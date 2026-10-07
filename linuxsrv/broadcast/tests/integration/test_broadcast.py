#!/usr/bin/env python3
"""
Testes de integracao do bcastd (backend stub).

Sobe o servidor com uma configuracao temporaria e a fixture
tests/fixtures/rooms.json, e exercita o protocolo completo: sala de espera,
admissao, negacao, banimento, retirada, mao levantada, escolha de orador,
transmissao WebM (com verificacao de decodificacao via ffmpeg, se houver),
troca de resolucao, chat, sala trancada, idempotencia e encerramento.

Uso: python3 tests/integration/test_broadcast.py --bin ./bcastd
Requer: pip install websockets
"""
import argparse
import asyncio
import json
import os
import shutil
import socket
import struct
import subprocess
import sys
import tempfile
import time
import uuid

import websockets

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
ROOM = "a" * 64
CLOSED_ROOM = "f" * 64
OWNER_INV = "b" * 64
GUEST_INV = "d" * 64
COADMIN_INV = "9" * 64
ORIGIN = "https://maurinsoft.com.br"

PASSED = 0
FAILED = []


def check(cond, msg):
    global PASSED
    if cond:
        PASSED += 1
        print("  ok  ", msg)
    else:
        FAILED.append(msg)
        print("  FALHOU", msg)


def free_port():
    s = socket.socket()
    s.bind(("127.0.0.1", 0))
    p = s.getsockname()[1]
    s.close()
    return p


class Client:
    """Cliente de teste: guarda todas as mensagens recebidas."""

    def __init__(self, port, ip="10.0.0.1", origin=ORIGIN):
        self.port, self.ip, self.origin = port, ip, origin
        self.msgs = []
        self.bins = []
        self.closed = False
        self.ws = None
        self.task = None
        self.welcome = None

    async def connect(self):
        self.ws = await websockets.connect(
            "ws://127.0.0.1:%d/salareuniao/broadcast" % self.port,
            origin=self.origin, additional_headers={"X-Real-IP": self.ip},
            max_size=16 * 1024 * 1024, ping_interval=None)
        self.task = asyncio.create_task(self._reader())
        return self

    async def _reader(self):
        try:
            async for m in self.ws:
                if isinstance(m, bytes):
                    self.bins.append(m)
                    self.msgs.append({"t": "__bin__", "len": len(m)})
                else:
                    self.msgs.append(json.loads(m))
        except Exception:
            pass
        self.closed = True

    async def send(self, obj):
        obj.setdefault("v", 1)
        await self.ws.send(json.dumps(obj))

    async def cmd(self, t, **kw):
        cid = str(uuid.uuid4())
        await self.send(dict(t=t, id=cid, **kw))
        ack = await self.wait(lambda m: m.get("t") == "ack" and m.get("ref") == cid)
        return ack

    async def hello(self, name="Fulano", invite=None, room=ROOM):
        h = {"t": "hello", "room_token": room, "name": name, "client": "test"}
        if invite:
            h["invite_token"] = invite
        await self.send(h)
        self.welcome = await self.wait(lambda m: m.get("t") in ("welcome", "error"))
        return self.welcome

    async def wait(self, pred, timeout=5.0, start=0):
        end = time.time() + timeout
        while time.time() < end:
            for m in self.msgs[start:]:
                if pred(m):
                    return m
            await asyncio.sleep(0.02)
        return None

    def has(self, t):
        return any(m.get("t") == t for m in self.msgs)

    async def wait_closed(self, timeout=5.0):
        end = time.time() + timeout
        while time.time() < end and not self.closed:
            await asyncio.sleep(0.02)
        return self.closed

    async def close(self):
        if self.ws:
            await self.ws.close()


def make_webm(tmp):
    """Gera um WebM VP8+Opus no formato ao vivo (cluster a cada keyframe)."""
    if not shutil.which("ffmpeg"):
        return None
    out = os.path.join(tmp, "src.webm")
    cmd = ["ffmpeg", "-loglevel", "error", "-y",
           "-f", "lavfi", "-i", "testsrc=size=320x240:rate=15",
           "-f", "lavfi", "-i", "sine=frequency=440:sample_rate=48000",
           "-t", "6", "-c:v", "libvpx", "-b:v", "300k", "-g", "15", "-keyint_min", "15",
           "-c:a", "libopus", "-f", "webm", "-live", "1", out]
    if subprocess.call(cmd) != 0 or not os.path.exists(out):
        return None
    return open(out, "rb").read()


def decodes(tmp, name, data):
    path = os.path.join(tmp, name)
    with open(path, "wb") as f:
        f.write(data)
    p = subprocess.run(["ffmpeg", "-v", "error", "-i", path, "-f", "null", "-"],
                       capture_output=True, text=True)
    frames = subprocess.run(["ffprobe", "-v", "error", "-select_streams", "v:0", "-count_frames",
                             "-show_entries", "stream=nb_read_frames", "-of", "csv=p=0", path],
                            capture_output=True, text=True).stdout.strip()
    try:
        nframes = int(frames.split(",")[0])
    except ValueError:
        nframes = 0
    return p.returncode == 0, nframes, p.stderr.strip()[:300]


def media_payloads(c, since=0):
    """Concatena payloads dos quadros de midia depois do ultimo stream.reset."""
    return b"".join(b[16:] for b in c.bins[since:])


def frame(gen, seq, payload):
    return struct.pack(">BBBBIQ", 0xB5, 2, 0, gen & 0xFF, seq, int(time.time() * 1000)) + payload


async def run(binary, mysql_db=None):
    tmp = tempfile.mkdtemp(prefix="bcastd-test-")
    port = free_port()
    conf = os.path.join(tmp, "broadcast.conf")
    log = os.path.join(tmp, "bcastd.log")
    with open(conf, "w") as f:
        f.write("\n".join([
            "listen_addr = 127.0.0.1", "listen_port = %d" % port, "io_threads = 3",
            "hello_timeout_ms = 1500", "ping_interval_ms = 5000", "idle_timeout_ms = 30000",
            "waiting_timeout_ms = 60000", "speaker_pending_ms = 4000", "room_linger_ms = 1000",
            "allowed_origin = %s" % ORIGIN, "trust_proxy = 1",
            ("db_name = %s\ndb_user = %s\ndb_pass = %s\ndb_host = 127.0.0.1" % (
                mysql_db, os.environ.get("BC_DB_USER", "salareuniao_bcast"),
                os.environ.get("BC_DB_PASS", "teste")) if mysql_db else
             "db_fixture = %s" % os.path.join(ROOT, "tests", "fixtures", "rooms.json")),
            "log_level = debug", "log_file = %s" % log,
            "base_url = https://maurinsoft.com.br/salareuniao", ""]))
    proc = subprocess.Popen([binary, "-c", conf])
    try:
        for _ in range(100):
            try:
                socket.create_connection(("127.0.0.1", port), 0.2).close()
                break
            except OSError:
                await asyncio.sleep(0.05)
        await scenario(port, tmp)
    finally:
        proc.terminate()
        try:
            rc = proc.wait(10)
        except subprocess.TimeoutExpired:
            proc.kill()
            rc = -9
        check(rc == 0, "desligamento gracioso com SIGTERM (rc=%s)" % rc)
        if FAILED:
            print("\n--- log do servidor (fim) ---")
            print("".join(open(log).readlines()[-60:]))
        shutil.rmtree(tmp, ignore_errors=True)


async def scenario(port, tmp):
    print("[handshake e autenticacao]")
    # origem proibida -> 403
    try:
        await websockets.connect("ws://127.0.0.1:%d/" % port, origin="https://malicioso.example")
        check(False, "origem proibida recusada")
    except Exception as e:
        check("403" in str(e), "origem proibida recusada com 403")

    # /healthz local
    r, w = await asyncio.open_connection("127.0.0.1", port)
    w.write(b"GET /healthz HTTP/1.1\r\nHost: x\r\n\r\n")
    data = await r.read()
    check(b"200 OK" in data and data.endswith(b"ok\n"), "/healthz responde ok")
    w.close()

    c = await Client(port).connect()
    await c.send({"t": "hello", "room_token": "xyz"})
    err = await c.wait(lambda m: m.get("t") == "error")
    check(err and err["code"] == "bad_hello", "room_token invalido -> bad_hello")
    check(await c.wait_closed(), "conexao fechada apos bad_hello")

    c = await Client(port).connect()
    e = await c.hello(name="X", room=CLOSED_ROOM)
    check(e and e.get("code") == "room_not_open", "sala fechada -> room_not_open")

    c = await Client(port).connect()
    check(await c.wait_closed(3.0), "sem hello -> fechada por hello_timeout")
    check(c.has("error"), "erro hello_timeout enviado")

    print("[organizador e sala de espera]")
    admin = await Client(port, ip="10.0.0.1").connect()
    w = await admin.hello(name="", invite=OWNER_INV)
    check(w and w["t"] == "welcome" and w["role"] == "admin" and w["state"] == "in_room",
          "organizador entra direto como admin")
    sync = await admin.wait(lambda m: m.get("t") == "state.sync")
    check(sync and sync["room"]["name"] == "Sala de Teste", "state.sync com dados da sala")
    check(sync and len(sync["chat"]) == 1 and sync["chat"][0]["text"] == "Bem-vindos", "historico de chat carregado")
    check(sync and "waiting" in sync, "admin recebe lista de espera")

    g1 = await Client(port, ip="10.0.0.11").connect()
    w = await g1.hello(name="Ana")
    check(w and w["state"] == "waiting" and w["role"] == "viewer", "convidado do link publico vai para a espera")
    lj = await admin.wait(lambda m: m.get("t") == "lobby.join" and m["participant"]["name"] == "Ana")
    check(lj is not None, "admin avisado de quem entrou na espera")
    check(lj and lj["participant"]["ip"] == "10.0.0.11", "admin ve o IP (X-Real-IP do proxy)")
    g1_pkey = w["pkey"]

    await g1.send({"t": "chat.send", "text": "posso entrar?"})
    er = await g1.wait(lambda m: m.get("t") == "error")
    check(er and er["code"] == "waiting", "quem esta na espera nao usa o chat da sala")
    await g1.send({"t": "chat.private", "text": "oi, sou a Ana"})
    pv = await admin.wait(lambda m: m.get("t") == "chat.private" and m["from_name"] == "Ana")
    check(pv is not None, "espera -> admin: mensagem privada")

    ack = await admin.cmd("admin.admit", pkey=g1_pkey)
    check(ack and ack["status"] == "applied", "admin.admit aplicado")
    adm = await g1.wait(lambda m: m.get("t") == "admitted")
    check(adm is not None, "convidado recebe admitted")
    s1 = await g1.wait(lambda m: m.get("t") == "state.sync")
    check(s1 and "waiting" not in s1 and all("ip" not in p for p in s1["participants"]),
          "participante comum nao recebe IPs nem a fila de espera")
    tok = await g1.wait(lambda m: m.get("t") == "invite.token")
    check(tok and len(tok["invite_token"]) == 64, "convite criado para reconexao do convidado")

    print("[negar e banir na espera]")
    g2 = await Client(port, ip="10.0.0.12").connect()
    w = await g2.hello(name="Beto")
    await admin.wait(lambda m: m.get("t") == "lobby.join" and m["participant"]["name"] == "Beto")
    ack = await admin.cmd("admin.deny", pkey=w["pkey"], reason="reuniao interna")
    check(ack and ack["status"] == "applied", "admin.deny aplicado")
    gb = await g2.wait(lambda m: m.get("t") == "goodbye")
    check(gb and gb["state"] == "denied" and gb["reason"] == "reuniao interna", "negado recebe goodbye denied")
    check(await g2.wait_closed(), "negado e desconectado")
    g2b = await Client(port, ip="10.0.0.12").connect()
    w = await g2b.hello(name="Beto")
    check(w and w["state"] == "waiting", "negado pode tentar de novo")
    await g2b.close()

    g3 = await Client(port, ip="10.0.0.13").connect()
    w = await g3.hello(name="Spammer")
    await admin.wait(lambda m: m.get("t") == "lobby.join" and m["participant"]["name"] == "Spammer")
    ack = await admin.cmd("admin.ban_waiting", pkey=w["pkey"], by="ip", reason="spam")
    check(ack and ack["status"] == "applied", "admin.ban_waiting aplicado")
    gb = await g3.wait(lambda m: m.get("t") == "goodbye")
    check(gb and gb["state"] == "banned", "banido na espera recebe goodbye banned")
    g3b = await Client(port, ip="10.0.0.13").connect()
    e = await g3b.hello(name="Outro nome")
    check(e and e.get("code") == "banned", "IP banido nao volta nem trocando o nome")

    await admin.send({"t": "admin.bans.list"})
    bl = await admin.wait(lambda m: m.get("t") == "bans")
    check(bl and len(bl["list"]) == 1 and bl["list"][0]["ip"] == "10.0.0.13", "lista de banimentos")
    ack = await admin.cmd("admin.unban", ban_id=bl["list"][0]["id"])
    check(ack and ack["status"] == "applied", "admin.unban aplicado")
    await asyncio.sleep(0.2)
    g3c = await Client(port, ip="10.0.0.13").connect()
    w = await g3c.hello(name="Spammer arrependido")
    check(w and w.get("t") == "welcome", "desbanido consegue voltar para a espera")
    await g3c.close()

    print("[permissoes]")
    ack = await g1.cmd("admin.kick", pkey=admin.welcome["pkey"])
    check(ack is None, "comando admin de nao-admin nao gera ack de sucesso")
    er = await g1.wait(lambda m: m.get("t") == "error" and m.get("code") == "not_admin")
    check(er is not None, "nao-admin recebe not_admin")

    print("[convidado aprovado e co-organizador]")
    g4 = await Client(port, ip="10.0.0.14").connect()
    w = await g4.hello(name="", invite=GUEST_INV)
    check(w and w["state"] == "in_room" and w["name"] == "Convidado Aprovado", "convite aprovado entra direto")
    co = await Client(port, ip="10.0.0.15").connect()
    w = await co.hello(name="", invite=COADMIN_INV)
    check(w and w["role"] == "admin", "room_admins vira admin")

    print("[mao levantada e orador]")
    await g4.send({"t": "hand.raise", "id": "h1"})
    await asyncio.sleep(0.05)
    await g1.send({"t": "hand.raise", "id": "h2"})
    hq = await admin.wait(lambda m: m.get("t") == "hand.queue" and len(m["list"]) == 2)
    check(hq and hq["list"][0]["name"] == "Convidado Aprovado" and hq["list"][1]["name"] == "Ana",
          "fila de mao em ordem FIFO")
    ack = await admin.cmd("admin.hand.reject", pkey=g4.welcome["pkey"])
    check(ack and ack["status"] == "applied", "admin.hand.reject aplicado")
    check(await g4.wait(lambda m: m.get("t") == "hand.rejected") is not None, "participante avisado da recusa")
    mark = len(admin.msgs)
    ack = await admin.cmd("admin.hand.accept", pkey=g1_pkey)
    check(ack and ack["status"] == "applied", "admin.hand.accept aplicado")
    sy = await g1.wait(lambda m: m.get("t") == "speaker.you")
    check(sy is not None and "profile" in sy, "orador escolhido recebe speaker.you com perfil")
    sc = await admin.wait(lambda m: m.get("t") == "speaker.changed" and m["speaker"] and m["speaker"]["pkey"] == g1_pkey, start=mark)
    check(sc is not None, "todos recebem speaker.changed")

    # quem nao e orador nao consegue publicar
    await g4.send({"t": "media.init", "mime": "video/webm;codecs=vp8,opus", "gen": 0})
    er = await g4.wait(lambda m: m.get("t") == "ack" and m.get("status") == "failed")
    check(er and er["error"] == "not_speaker", "nao-orador nao inicia midia")

    print("[transmissao de midia]")
    webm = make_webm(tmp)
    if webm is None:
        print("  (ffmpeg/libvpx ausente: teste de midia com WebM sintetico)")
    gen = sy["gen"]
    ack = await g1.cmd("media.init", mime="video/webm;codecs=vp8,opus", gen=gen, w=320, h=240)
    check(ack and ack["status"] == "applied", "media.init aplicado")
    live = await admin.wait(lambda m: m.get("t") == "speaker.changed" and m["speaker"] and m["speaker"].get("live"))
    check(live is not None, "ouvintes veem o orador ao vivo")

    if webm:
        chunks = [webm[i:i + 4096] for i in range(0, len(webm), 4096)]
        half = len(chunks) // 2
        bins_admin = len(admin.bins)
        for i, ch in enumerate(chunks[:half]):
            await g1.ws.send(frame(gen, i, ch))
            await asyncio.sleep(0.003)
        late = await Client(port, ip="10.0.0.16").connect()
        w = await late.hello(name="Atrasado")
        await admin.wait(lambda m: m.get("t") == "lobby.join" and m["participant"]["name"] == "Atrasado")
        await admin.cmd("admin.admit", pkey=w["pkey"])
        await late.wait(lambda m: m.get("t") == "admitted")
        for i, ch in enumerate(chunks[half:]):
            await g1.ws.send(frame(gen, half + i, ch))
            await asyncio.sleep(0.003)
        await asyncio.sleep(0.8)
        got = media_payloads(admin, bins_admin)
        check(got == webm, "ouvinte presente desde o inicio recebe o WebM identico (%d bytes)" % len(webm))
        okd, nfr, err = decodes(tmp, "admin.webm", got)
        check(okd and nfr > 0, "stream do ouvinte decodifica (%d quadros)" % nfr)
        check(late.has("stream.reset"), "atrasado recebe stream.reset")
        lp = media_payloads(late)
        okd, nfr, err = decodes(tmp, "late.webm", lp)
        check(okd and 0 < nfr < 90, "atrasado entra no ultimo keyframe e decodifica (%d quadros) %s" % (nfr, err))
        check(not g1.bins, "orador nao recebe a propria midia")
        await admin.send({"t": "admin.stats"})
        st = await admin.wait(lambda m: m.get("t") == "stats")
        check(st and st["have_key"] and st["drops"] == 0, "estatisticas: keyframe e zero descartes")
    else:
        late = None

    print("[resolucao]")
    ack = await admin.cmd("admin.resolution.set", pkey=g1_pkey, w=1280, h=720)
    check(ack and ack["status"] == "applied", "admin.resolution.set aplicado")
    mp = await g1.wait(lambda m: m.get("t") == "media.profile")
    check(mp and mp["profile"]["w"] == 1280 and mp["gen"] == (gen + 1) % 256, "orador recebe media.profile com nova geracao")
    ack = await admin.cmd("admin.resolution.set", pkey=g1_pkey, w=1000, h=700)
    check(ack and ack["status"] == "failed" and ack["error"] == "bad_profile", "resolucao fora da lista e recusada")
    ack = await g1.cmd("media.init", mime="video/webm;codecs=vp8,opus", gen=mp["gen"])
    check(ack and ack["status"] == "applied", "orador reinicia com a nova geracao")

    print("[chat]")
    mark = len(g4.msgs)
    await g4.send({"t": "chat.send", "text": "<b>ola</b> a todos"})
    cm = await admin.wait(lambda m: m.get("t") == "chat.msg" and m["name"] == "Convidado Aprovado")
    check(cm and cm["text"] == "<b>ola</b> a todos", "chat difundido (texto cru; o cliente escapa)")
    check(await g4.wait(lambda m: m.get("t") == "chat.msg", start=mark) is not None, "remetente tambem recebe")
    ack = await g4.cmd("chat.send", text="   ")
    check(ack and ack["error"] == "empty_message", "mensagem vazia recusada")

    print("[idempotencia]")
    cid = str(uuid.uuid4())
    await admin.send({"t": "admin.lock", "id": cid})
    a1 = await admin.wait(lambda m: m.get("t") == "ack" and m.get("ref") == cid)
    n_before = sum(1 for m in admin.msgs if m.get("t") == "room.state")
    await admin.send({"t": "admin.lock", "id": cid})
    await asyncio.sleep(0.3)
    a2 = [m for m in admin.msgs if m.get("t") == "ack" and m.get("ref") == cid]
    n_after = sum(1 for m in admin.msgs if m.get("t") == "room.state")
    check(a1 and len(a2) == 2 and a2[0] == a2[1] and n_before == n_after, "command_id repetido devolve o mesmo ack sem reexecutar")

    print("[sala trancada]")
    x = await Client(port, ip="10.0.0.20").connect()
    e = await x.hello(name="Sem convite")
    check(e and e.get("code") == "room_locked", "sala trancada recusa link publico")
    await admin.cmd("admin.unlock")

    print("[reconexao do orador]")
    old_pkey = g1_pkey
    g1b = await Client(port, ip="10.0.0.11").connect()
    w = await g1b.hello(name="Ana", invite=tok["invite_token"])
    check(w and w["state"] == "in_room" and w["pkey"] == old_pkey, "reconexao com o convite recebido mantem identidade")
    gb = await g1.wait(lambda m: m.get("t") == "goodbye")
    check(gb and gb["reason"] == "replaced", "conexao antiga substituida")
    sy2 = await g1b.wait(lambda m: m.get("t") == "speaker.you")
    check(sy2 is not None, "orador reconectado retoma a palavra")

    print("[retirar e banir na sala]")
    mark = len(admin.msgs)
    ack = await admin.cmd("admin.kick", pkey=old_pkey, reason="tempo esgotado")
    check(ack and ack["status"] == "applied", "admin.kick aplicado")
    gb = await g1b.wait(lambda m: m.get("t") == "goodbye")
    check(gb and gb["state"] == "kicked", "retirado recebe goodbye kicked")
    sc = await admin.wait(lambda m: m.get("t") == "speaker.changed" and m["speaker"] is None, start=mark)
    check(sc is not None, "palco fica vazio quando o orador e retirado")
    g1c = await Client(port, ip="10.0.0.11").connect()
    w = await g1c.hello(invite=tok["invite_token"])
    check(w and w["state"] == "waiting", "retirado volta pela sala de espera")
    await g1c.close()

    ack = await admin.cmd("admin.ban", pkey=g4.welcome["pkey"], by="identity", reason="conduta")
    check(ack and ack["status"] == "applied", "admin.ban na sala aplicado")
    gb = await g4.wait(lambda m: m.get("t") == "goodbye")
    check(gb and gb["state"] == "banned", "banido na sala recebe goodbye banned")
    g4b = await Client(port, ip="10.0.0.99").connect()
    e = await g4b.hello(name="", invite=GUEST_INV)
    check(e and e.get("code") == "banned", "banimento por identidade vale de outro IP")
    ack = await admin.cmd("admin.kick", pkey=admin.welcome["pkey"])
    check(ack and ack["error"] == "not_allowed", "admin nao retira a si mesmo")

    print("[convite]")
    await admin.send({"t": "admin.invite", "id": "inv1", "email": "Novo@Example.com", "name": "Novo"})
    ic = await admin.wait(lambda m: m.get("t") == "invite.created")
    check(ic and ic["email"] == "novo@example.com" and "/join.php?token=" in ic["link"], "convite criado com link")
    ack = await admin.cmd("admin.invite", email="invalido")
    check(ack and ack["error"] == "bad_email", "e-mail invalido recusado")

    print("[encerramento]")
    ack = await co.cmd("admin.close")
    check(ack and ack["status"] == "applied", "co-organizador encerra a sala")
    gb = await admin.wait(lambda m: m.get("t") == "goodbye")
    check(gb and gb["reason"] == "room_closed", "todos recebem goodbye room_closed")
    if late:
        check(await late.wait_closed(), "conexoes fechadas ao encerrar")
    x = await Client(port, ip="10.0.0.30").connect()
    e = await x.hello(name="Tarde demais")
    check(e and e.get("code") in ("room_not_open",), "sala encerrada recusa novas entradas")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--bin", default=os.path.join(ROOT, "bcastd"))
    ap.add_argument("--mysql", metavar="BANCO", help="usa MySQL/MariaDB (rode seed_mysql.sql antes)")
    a = ap.parse_args()
    asyncio.run(run(os.path.abspath(a.bin), a.mysql))
    print("\n%d verificacoes ok, %d falhas" % (PASSED, len(FAILED)))
    for f in FAILED:
        print("  -", f)
    sys.exit(1 if FAILED else 0)


if __name__ == "__main__":
    main()
