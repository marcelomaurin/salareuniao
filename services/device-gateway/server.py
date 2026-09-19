#!/usr/bin/env python3
import asyncio
import json
import os
import signal
import argparse
from typing import Dict

STATE_FILE = "state.json"

DEFAULT_STATE = {
    "ssid": "maurinsrv_1",
    "pass": "1425361425",
    "host": "maurinsoft.com.br",
    "port": 8090,               # porta de destino (vista pelo ESP)
    "sala": "Sala 1",
    "agenda": "-",
    "data": "",                 # ESP costuma preencher via NTP
    "status": "Livre",
}

# ---------- Persistência ----------
def load_state() -> Dict[str, str]:
    if os.path.exists(STATE_FILE):
        try:
            with open(STATE_FILE, "r", encoding="utf-8") as f:
                data = json.load(f)
                state = DEFAULT_STATE.copy()
                state.update({k: data.get(k, v) for k, v in state.items()})
                # tipagem segura da porta
                try:
                    state["port"] = int(state["port"])
                except Exception:
                    state["port"] = DEFAULT_STATE["port"]
                return state
        except Exception:
            pass
    return DEFAULT_STATE.copy()

def save_state(state: Dict[str, str]) -> None:
    tmp = state.copy()
    # garante que a porta é serializável como int
    tmp["port"] = int(tmp.get("port", DEFAULT_STATE["port"]))
    with open(STATE_FILE, "w", encoding="utf-8") as f:
        json.dump(tmp, f, ensure_ascii=False, indent=2)

# ---------- Parser de linha ----------
def handle_get_command(line: str, state: Dict[str, str]) -> str:
    """
    Entende linhas como:
      GET VAR:=valor
      VAR:=valor
      GET VAR:=       (só consulta)
    Vars suportadas: sala, agenda, data, status, ssid, pass, host, port, all
    Retorna string de resposta (sem \n no final).
    """
    s = line.strip()
    if not s:
        return ""

    # normaliza: se não começa com GET mas possui ':=', assume GET implícito
    if not s.upper().startswith("GET ") and ":=" in s:
        s = "GET " + s

    if not s.upper().startswith("GET "):
        # não é comando local -> ecoa como recebido
        return f"IGNORED:{s}"

    body = s[4:].strip()  # remove "GET "
    if not body:
        return "ERR NoVar"

    # suporta 'GET ALL' para dump completo
    if body.upper() == "ALL" or body.lower() == "all":
        return ("ssid={ssid}\npass={pass}\nhost={host}\nport={port}\n"
                "sala={sala}\nagenda={agenda}\ndata={data}\nstatus={status}").format(**state)

    # separa var e valor
    if ":=" in body:
        var, val = body.split(":=", 1)
        var = var.strip().lower()
        val = val.strip()
    else:
        var = body.strip().lower()
        val = ""  # consulta

    # mapa de variáveis válidas
    valid_vars = {"ssid", "pass", "host", "port", "sala", "agenda", "data", "status"}

    if var not in valid_vars:
        return f"ERR VarNotFound:{var}"

    # consulta (sem valor)
    if val == "":
        return f"{var}={state[var]}"

    # atualização + persistência
    if var == "port":
        try:
            p = int(val)
            if not (1 <= p <= 65535):
                return "ERR PortRange"
            state["port"] = p
        except ValueError:
            return "ERR PortNaN"
    else:
        state[var] = val

    save_state(state)
    return f"OK {var}={state[var]}"

# ---------- Handler TCP ----------
class ESPServerProtocol(asyncio.Protocol):
    def __init__(self, state: Dict[str, str], on_connection=None):
        self.state = state
        self.transport = None
        self.buffer = ""
        self.on_connection = on_connection  # callback opcional

    def connection_made(self, transport: asyncio.BaseTransport) -> None:
        self.transport = transport
        peer = transport.get_extra_info("peername")
        print(f"[+] Conexão de {peer}")
        if self.on_connection:
            try:
                self.on_connection(peer)
            except Exception:
                pass

    def data_received(self, data: bytes) -> None:
        try:
            chunk = data.decode("utf-8", errors="ignore")
        except Exception:
            chunk = ""
        self.buffer += chunk

        # Processa por linhas (LF). ESP manda \n ao final.
        while "\n" in self.buffer:
            line, self.buffer = self.buffer.split("\n", 1)
            line = line.rstrip("\r")
            if not line.strip():
                continue

            # aceita múltiplos comandos em uma mesma linha separados por ';'
            parts = [p for p in line.split(";") if p.strip()]
            for part in parts:
                resp = handle_get_command(part, self.state)
                if resp:
                    # sempre responde com \n ao final para facilitar debug
                    out = (resp + "\n").encode("utf-8", errors="ignore")
                    try:
                        self.transport.write(out)
                    except Exception:
                        pass
                # log simples
                print(f"[rx] {part}  ->  [tx] {resp}")

    def connection_lost(self, exc: Exception | None) -> None:
        peer = None
        if self.transport:
            peer = self.transport.get_extra_info("peername")
        print(f"[-] Conexão encerrada de {peer}")

# ---------- Main ----------
async def main():
    parser = argparse.ArgumentParser(description="Servidor TCP para ESP8266 (protocolo GET VAR:=VAL).")
    parser.add_argument("--host", default="0.0.0.0", help="Endereço de bind (default: 0.0.0.0)")
    parser.add_argument("--port", type=int, default=8090, help="Porta de escuta do servidor (default: 8090)")
    args = parser.parse_args()

    state = load_state()
    print(f"Estado inicial: {json.dumps(state, ensure_ascii=False)}")

    loop = asyncio.get_running_loop()
    stop_event = asyncio.Event()

    for sig in (signal.SIGINT, signal.SIGTERM):
        loop.add_signal_handler(sig, stop_event.set)

    server = await loop.create_server(
        lambda: ESPServerProtocol(state),
        host=args.host, port=args.port
    )

    addrs = ", ".join(str(sock.getsockname()) for sock in server.sockets)
    print(f"Servidor ouvindo em {addrs}")
    print("Ctrl+C para sair.")

    try:
        await stop_event.wait()
    finally:
        server.close()
        await server.wait_closed()
        print("Servidor finalizado.")

if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        pass
