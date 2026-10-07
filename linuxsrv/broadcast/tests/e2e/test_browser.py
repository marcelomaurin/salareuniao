#!/usr/bin/env python3
"""
Teste ponta a ponta no navegador (Chromium headless, camera/microfone falsos).

Sobe o PHP embutido servindo o site e o bcastd (backend MySQL), abre duas
paginas (organizador e convidado) e exercita: sala de espera, aceitar,
levantar a mao, dar a palavra, transmissao real do MediaRecorder ate o <video>
do organizador, chat, troca de resolucao e retirada.

Pre-requisitos: pip install playwright websockets; MariaDB com o banco de
teste preparado (tests/integration/seed_mysql.sql) e config.php do site
apontando para ele. Uso:
  python3 tests/e2e/test_browser.py --bin ./bcastd --db salareuniao_test
"""
import argparse
import os
import socket
import subprocess
import sys
import tempfile
import time

from playwright.sync_api import sync_playwright

HERE = os.path.dirname(os.path.abspath(__file__))
BROADCAST = os.path.abspath(os.path.join(HERE, "..", ".."))
SITE = os.path.abspath(os.path.join(BROADCAST, ".."))
OK, BAD = [], []


def check(cond, msg):
    (OK if cond else BAD).append(msg)
    print(("  ok    " if cond else "  FALHOU ") + msg)


def free_port():
    s = socket.socket()
    s.bind(("127.0.0.1", 0))
    p = s.getsockname()[1]
    s.close()
    return p


def wait_port(port, t=10):
    end = time.time() + t
    while time.time() < end:
        try:
            socket.create_connection(("127.0.0.1", port), 0.3).close()
            return True
        except OSError:
            time.sleep(0.1)
    return False


def video_state(page, sel):
    return page.evaluate("""(sel) => { const v = document.querySelector(sel);
        return {w: v.videoWidth, h: v.videoHeight, t: v.currentTime, rs: v.readyState, paused: v.paused}; }""", sel)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--bin", default=os.path.join(BROADCAST, "bcastd"))
    ap.add_argument("--db", default="salareuniao_test")
    a = ap.parse_args()

    php_port, bc_port = free_port(), free_port()
    origin = "http://127.0.0.1:%d" % php_port
    tmp = tempfile.mkdtemp(prefix="bc-e2e-")
    conf = os.path.join(tmp, "bc.conf")
    with open(conf, "w") as f:
        f.write("\n".join([
            "listen_addr = 127.0.0.1", "listen_port = %d" % bc_port, "io_threads = 2",
            "allowed_origin = %s" % origin, "trust_proxy = 0",
            "db_name = %s" % a.db, "db_user = salareuniao_bcast", "db_pass = teste", "db_host = 127.0.0.1",
            "log_level = info", "log_file = %s" % os.path.join(tmp, "bc.log"), ""]))
    env = dict(os.environ, BC_E2E_WS="ws://127.0.0.1:%d/salareuniao/broadcast" % bc_port)
    bc = subprocess.Popen([a.bin, "-c", conf])
    php = subprocess.Popen(["php", "-S", "127.0.0.1:%d" % php_port, "-t", SITE], env=env,
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        assert wait_port(bc_port) and wait_port(php_port), "servicos nao subiram"
        run(origin)
    finally:
        php.terminate()
        bc.terminate()
        bc.wait(10)
        if BAD:
            print(open(os.path.join(tmp, "bc.log")).read()[-4000:])
    print("\n%d ok, %d falhas" % (len(OK), len(BAD)))
    sys.exit(1 if BAD else 0)


def run(origin):
    with sync_playwright() as p:
        browser = p.chromium.launch(args=[
            "--use-fake-device-for-media-stream", "--use-fake-ui-for-media-stream",
            "--autoplay-policy=no-user-gesture-required"])
        ctx_a = browser.new_context()
        ctx_g = browser.new_context()
        ctx_a.grant_permissions(["camera", "microphone"])
        ctx_g.grant_permissions(["camera", "microphone"])
        admin = ctx_a.new_page()
        guest = ctx_g.new_page()
        errors = []
        for pg, who in ((admin, "admin"), (guest, "guest")):
            pg.on("pageerror", lambda e, w=who: errors.append("%s: %s" % (w, e)))

        print("[entrada]")
        admin.goto(origin + "/broadcast.php?token=" + "b" * 64)
        admin.wait_for_selector("#bcAdminTop:not([hidden])", timeout=8000)
        check(admin.is_visible("#bcInviteBtn"), "organizador ve as ferramentas de admin")

        guest.goto(origin + "/broadcast.php?room_token=" + "a" * 64)
        guest.wait_for_selector("#bcJoin:not([hidden])")
        guest.fill("#bcJoinName", "Visitante <script>")
        guest.click("#bcJoinForm button[type=submit]")
        guest.wait_for_selector("#bcWaiting:not([hidden])")
        check(True, "convidado ve a tela de espera")

        admin.click(".bc-tab[data-tab=lobby]")
        admin.wait_for_selector("#bcLobby .bc-item")
        name = admin.inner_text("#bcLobby .bc-item strong")
        check(name == "Visitante <script>", "nome aparece como texto (sem HTML) na espera")
        admin.click("#bcLobby .bc-item .bc-btn-primary")
        guest.wait_for_selector("#bcWaiting", state="hidden", timeout=8000)
        check(True, "organizador aceita e o convidado entra")

        print("[palavra e transmissao]")
        guest.click("#bcHandBtn")
        admin.click(".bc-tab[data-tab=people]")
        admin.wait_for_selector("#bcHandList .bc-item")
        admin.click("#bcHandList .bc-item .bc-btn-primary")
        guest.wait_for_selector("#bcSpeakerControls:not([hidden])", timeout=8000)
        check(True, "convidado recebe a palavra")
        admin.wait_for_selector("#bcLive:not([hidden])", timeout=10000)
        check(True, "organizador ve AO VIVO")
        ok = False
        st = {}
        for _ in range(60):
            st = video_state(admin, "#bcVideo")
            if st["w"] > 0 and st["rs"] >= 2 and st["t"] > 1.0:
                ok = True
                break
            time.sleep(0.25)
        check(ok, "video do orador toca no organizador (%sx%s, t=%.1fs)" % (st.get("w"), st.get("h"), st.get("t", 0)))
        t1 = video_state(admin, "#bcVideo")["t"]
        time.sleep(2)
        t2 = video_state(admin, "#bcVideo")["t"]
        check(t2 - t1 > 1.0, "reproducao avanca (%.1fs em 2s)" % (t2 - t1))

        print("[chat]")
        guest.click(".bc-tab[data-tab=chat]")
        guest.fill("#bcChatInput", "<img src=x onerror=alert(1)> oi")
        guest.click("#bcChatForm button")
        admin.click(".bc-tab[data-tab=chat]")
        admin.wait_for_selector("#bcChat .bc-msg p:has-text('oi')")
        check(admin.locator("#bcChat img").count() == 0, "chat nao interpreta HTML")

        print("[resolucao]")
        admin.click(".bc-tab[data-tab=people]")
        row = admin.locator("#bcPeople .bc-item", has_text="Visitante")
        row.locator(".bc-menu > .bc-btn").click()
        row.locator(".bc-menu-box .bc-btn", has_text="Resolução 240p").click()
        time.sleep(4)
        st = video_state(admin, "#bcVideo")
        check(st["w"] > 0 and not st["paused"], "video continua apos troca de resolucao (%sx%s)" % (st["w"], st["h"]))

        print("[retirar]")
        admin.on("dialog", lambda d: d.accept())
        row = admin.locator("#bcPeople .bc-item", has_text="Visitante")
        row.locator(".bc-menu > .bc-btn").click()
        row.locator(".bc-menu-box .bc-btn", has_text="Retirar da sala").click()
        guest.wait_for_selector("#bcEnd:not([hidden])", timeout=8000)
        check("retirado" in guest.inner_text("#bcEndTitle"), "convidado ve que foi retirado")
        admin.wait_for_selector("#bcStageMsg:not([hidden])", timeout=5000)
        check(True, "palco do organizador volta a aguardar orador")
        check(not errors, "sem erros de JavaScript nas paginas %s" % errors[:3])
        browser.close()


if __name__ == "__main__":
    main()
