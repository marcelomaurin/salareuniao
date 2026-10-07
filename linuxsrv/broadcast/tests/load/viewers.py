#!/usr/bin/env python3
"""
Teste de carga: 1 orador + N ouvintes numa sala (backend stub).

  python3 tests/load/viewers.py --bin ./bcastd --viewers 200 --seconds 20

Mede CPU e memoria (RSS) do bcastd, bytes entregues e ouvintes que ficaram
dessincronizados. Usa um WebM real gerado pelo ffmpeg, enviado em loop no
ritmo de tempo real (pedacos de 250 ms).
"""
import argparse
import asyncio
import json
import os
import shutil
import socket
import struct
import subprocess
import tempfile
import time

import multiprocessing as mp

import websockets

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
ROOM, OWNER = "a" * 64, "b" * 64


def free_port():
    s = socket.socket(); s.bind(("127.0.0.1", 0)); p = s.getsockname()[1]; s.close(); return p


def proc_stats(pid):
    with open("/proc/%d/stat" % pid) as f:
        parts = f.read().split(")")[-1].split()
    ticks = int(parts[11]) + int(parts[12])
    with open("/proc/%d/status" % pid) as f:
        rss = [l for l in f if l.startswith("VmRSS")][0].split()[1]
    return ticks / os.sysconf("SC_CLK_TCK"), int(rss)


async def viewer(port, i, counters, stop):
    try:
        async with websockets.connect("ws://127.0.0.1:%d/" % port, origin="https://maurinsoft.com.br",
                                      additional_headers={"X-Real-IP": "10.1.%d.%d" % (i // 250, i % 250 + 1)},
                                      max_size=None, ping_interval=None) as ws:
            # link publico: cada ouvinte e uma identidade nova na sala de espera (o admin aceita)
            await ws.send(json.dumps({"v": 1, "t": "hello", "room_token": ROOM, "name": "ouvinte %d" % i}))
            async for m in ws:
                if stop.is_set():
                    break
                if isinstance(m, bytes):
                    counters["bytes"] += len(m)
                    counters["frames"] += 1
                elif '"stream.reset"' in m:
                    counters["resets"] += 1
    except Exception as e:
        counters["errors"] += 1


def worker_proc(port, ids, seconds, q):
    """Processo separado com um grupo de ouvintes (o cliente Python e o gargalo, nao o servidor)."""
    async def go():
        counters = {"bytes": 0, "frames": 0, "resets": 0, "errors": 0}
        stop = asyncio.Event()
        tasks = [asyncio.create_task(viewer(port, i, counters, stop)) for i in ids]
        await asyncio.sleep(seconds)
        stop.set()
        for t in tasks:
            t.cancel()
        return counters
    q.put(asyncio.run(go()))


async def main_async(a):
    tmp = tempfile.mkdtemp(prefix="bc-load-")
    src = os.path.join(tmp, "src.webm")
    subprocess.check_call(["ffmpeg", "-loglevel", "error", "-y", "-f", "lavfi", "-i", "testsrc=size=640x360:rate=24",
                           "-f", "lavfi", "-i", "sine=frequency=440:sample_rate=48000", "-t", "30",
                           "-vf", "noise=alls=25:allf=t", "-c:v", "libvpx", "-b:v", "%dk" % a.kbps, "-minrate", "%dk" % a.kbps, "-maxrate", "%dk" % a.kbps, "-g", "48", "-c:a", "libopus",
                           "-f", "webm", "-live", "1", src])
    data = open(src, "rb").read()
    port = free_port()
    conf = os.path.join(tmp, "c.conf")
    open(conf, "w").write("listen_port = %d\nio_threads = %d\nmax_clients_per_room = %d\nmax_clients = %d\n"
                          "allowed_origin = https://maurinsoft.com.br\ntrust_proxy = 1\nlog_level = warn\n"
                          "db_fixture = %s\n" % (port, a.threads, a.viewers + 10, a.viewers + 10,
                                                 os.path.join(ROOT, "tests", "fixtures", "rooms.json")))
    proc = subprocess.Popen([a.bin, "-c", conf])
    await asyncio.sleep(0.5)

    spk = await websockets.connect("ws://127.0.0.1:%d/" % port, origin="https://maurinsoft.com.br",
                                   additional_headers={"X-Real-IP": "10.0.0.1"}, max_size=None, ping_interval=None)
    await spk.send(json.dumps({"v": 1, "t": "hello", "room_token": ROOM, "invite_token": OWNER, "name": ""}))
    counters = {"bytes": 0, "frames": 0, "resets": 0, "errors": 0}
    stop = asyncio.Event()
    me = None
    gen = 0
    admitted = 0

    async def read_spk():
        nonlocal me, gen, admitted
        async for m in spk:
            if isinstance(m, str):
                j = json.loads(m)
                if j["t"] == "welcome":
                    me = j["pkey"]
                elif j["t"] == "speaker.you":
                    gen = j["gen"]
                elif j["t"] == "stats":
                    print("stats do servidor:", {k: j[k] for k in ("in_room", "synced", "drops", "bytes_out")})
                elif j["t"] == "lobby.join":
                    admitted += 1
    rt = asyncio.create_task(read_spk())
    while me is None:
        await asyncio.sleep(0.05)
    q = mp.Queue()
    procs = []
    per = (a.viewers + a.workers - 1) // a.workers
    for w in range(a.workers):
        ids = list(range(w * per, min(a.viewers, (w + 1) * per)))
        pr = mp.Process(target=worker_proc, args=(port, ids, a.seconds + 30, q))
        pr.start()
        procs.append(pr)
    tasks = []
    t_wait = time.time()
    while admitted < a.viewers and time.time() - t_wait < 20:
        await asyncio.sleep(0.1)
    await spk.send(json.dumps({"v": 1, "t": "admin.admit_all", "id": "all"}))
    await asyncio.sleep(1)
    await spk.send(json.dumps({"v": 1, "t": "admin.speaker.set", "id": "s1", "pkey": me}))
    await asyncio.sleep(0.3)
    await spk.send(json.dumps({"v": 1, "t": "media.init", "id": "m1", "mime": "video/webm;codecs=vp8,opus", "gen": gen}))
    await asyncio.sleep(0.3)

    chunk = int(len(data) / 30 / 4)          # ~250 ms de midia por pedaco
    c0, _ = proc_stats(proc.pid)
    t0 = time.time()
    seq = 0
    pos = 0
    while time.time() - t0 < a.seconds and pos < len(data):
        part = data[pos:pos + chunk]
        pos += chunk
        await spk.send(struct.pack(">BBBBIQ", 0xB5, 2, 0, gen, seq, int(time.time() * 1000)) + part)
        seq += 1
        await asyncio.sleep(0.25)
    elapsed = time.time() - t0
    c1, rss = proc_stats(proc.pid)
    await spk.send(json.dumps({"v": 1, "t": "admin.stats", "id": "st"}))
    await asyncio.sleep(0.5)
    stop.set()
    rt.cancel()
    proc.terminate()
    proc.wait(10)
    for _ in procs:
        c = q.get(timeout=60)
        for k in counters:
            counters[k] += c[k]
    for pr in procs:
        pr.join(5)
    sent = pos
    print("ouvintes=%d  duracao=%.1fs  enviado pelo orador=%.0f kB (%.0f kbit/s)" % (
        a.viewers, elapsed, sent / 1024, sent * 8 / elapsed / 1000))
    print("admitidos=%d" % admitted)
    print("esperado por ouvinte=%.1f MB" % (sent / 1e6))
    print("entregue=%.1f MB  quadros=%d  resets(sincronizacoes)=%d  erros=%d" % (
        counters["bytes"] / 1e6, counters["frames"], counters["resets"], counters["errors"]))
    print("CPU bcastd=%.1f%% de um nucleo  RSS=%d kB" % ((c1 - c0) / elapsed * 100, rss))
    shutil.rmtree(tmp, ignore_errors=True)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--bin", default=os.path.join(ROOT, "bcastd"))
    ap.add_argument("--viewers", type=int, default=200)
    ap.add_argument("--seconds", type=int, default=20)
    ap.add_argument("--kbps", type=int, default=800)
    ap.add_argument("--threads", type=int, default=4)
    ap.add_argument("--workers", type=int, default=max(1, (os.cpu_count() or 2) - 1))
    asyncio.run(main_async(ap.parse_args()))


if __name__ == "__main__":
    main()
