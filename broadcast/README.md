# bcastd — servidor de broadcast da Sala Reunião

Substitui o WebRTC mesh (P2P) da sala por um servidor de distribuição: **um orador por vez** envia áudio e vídeo para o `bcastd`, que repassa o mesmo fluxo a todos os ouvintes. O serviço também é a autoridade da sala em tempo real: sala de espera, levantar a mão, escolha do orador, chat e todas as funções do organizador.

- C ANSI (C90, `-std=c90 -pedantic`) + POSIX threads, `./configure` e `Makefile`.
- Multithread: 1 thread de aceitação, N threads de E/S com `epoll` (ou `poll`), 1 thread de banco, thread principal de manutenção.
- Máquina de estados explícita para a conexão (`novo → espera → na sala → retirado | saiu | banido`), para a fala e para a sala (`src/bc_fsm.c`).
- Chave de acesso: o token da sala (`rooms.join_token`); convites individuais (`room_invites.token`) identificam organizadores e convidados.
- Sem dependências além de pthreads e do cliente MySQL/MariaDB (cJSON vem junto em `src/third_party`).

## Como funciona

```
Navegador do orador ── MediaRecorder (WebM VP8/Opus, pedaços de 250 ms) ──┐
                                                                         WSS
Navegadores dos ouvintes ◄── MediaSource ◄── WSS ◄── Apache :443 ◄── bcastd 127.0.0.1:8090 ── MySQL
```

O servidor não transcodifica. Ele lê só a estrutura do WebM para guardar o cabeçalho de inicialização e o último keyframe; assim quem entra no meio começa a ver em menos de 2 s. A latência típica fica entre 1 e 2 s.

## Compilar

```bash
sudo apt install build-essential libmariadb-dev      # ou libmysqlclient-dev
cd broadcast
./configure --with-mysql=yes
make
make check                                           # testes unitários
```

Opções do `configure`: `--prefix`, `--sysconfdir`, `--with-mysql=auto|yes|no`, `--enable-debug`, `--enable-asan`, `--enable-tsan`, `--cc=`. Com `--with-mysql=no` o binário usa um banco em memória (arquivo JSON em `db_fixture`) — só para testes.

## Instalar

1. Banco: aplique a migração `sql/014_broadcast.sql` (idempotente) e crie um usuário com privilégios mínimos:

```sql
CREATE USER 'salareuniao_bcast'@'127.0.0.1' IDENTIFIED BY 'senha-forte';
GRANT SELECT ON salareuniao.users TO 'salareuniao_bcast'@'127.0.0.1';
GRANT SELECT, UPDATE ON salareuniao.rooms TO 'salareuniao_bcast'@'127.0.0.1';
GRANT SELECT ON salareuniao.room_admins TO 'salareuniao_bcast'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON salareuniao.room_invites TO 'salareuniao_bcast'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON salareuniao.room_bans TO 'salareuniao_bcast'@'127.0.0.1';
GRANT SELECT, INSERT ON salareuniao.room_messages TO 'salareuniao_bcast'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON salareuniao.room_presence TO 'salareuniao_bcast'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON salareuniao.room_attendance TO 'salareuniao_bcast'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON salareuniao.room_runtime_state TO 'salareuniao_bcast'@'127.0.0.1';
GRANT INSERT ON salareuniao.room_control_audit TO 'salareuniao_bcast'@'127.0.0.1';
GRANT INSERT ON salareuniao.broadcast_outbox TO 'salareuniao_bcast'@'127.0.0.1';
```

2. Serviço:

```bash
sudo make install                     # /usr/local/sbin/bcastd, /etc/salareuniao/broadcast.conf, unit systemd
sudo useradd -r -s /usr/sbin/nologin salareuniao
sudo mkdir -p /var/log/salareuniao && sudo chown salareuniao /var/log/salareuniao
sudo nano /etc/salareuniao/broadcast.conf    # db_*, allowed_origin, base_url
bcastd -t -c /etc/salareuniao/broadcast.conf
sudo systemctl enable --now salareuniao-broadcast
```

3. Apache: inclua `etc/apache/broadcast.conf` no VirtualHost 443 (`a2enmod proxy proxy_wstunnel headers`). Para Nginx, `etc/nginx/broadcast.conf`.

4. Site (`config.php`):

```php
'media' => ['transport' => 'broadcast'],
'broadcast' => [
    'enabled' => true,
    'public_url' => 'wss://maurinsoft.com.br/salareuniao/broadcast',
    'internal_host' => '127.0.0.1',
    'internal_port' => 8090,
],
```

Com isso `room.php` e `join.php` passam a redirecionar para `broadcast.php`. Para voltar ao P2P, troque `transport` para `p2p`.

5. Convites criados de dentro da sala: agende `php bin/broadcast_outbox.php` no cron (a cada minuto).

`SIGHUP` reabre o arquivo de log; `SIGTERM` avisa os clientes (`goodbye server_shutdown`, que reconectam sozinhos) e encerra. `GET /healthz` e `GET /metrics` respondem somente para 127.0.0.1 (o `admin_system.php` usa os dois).

## Testes

| Comando | O que cobre |
| --- | --- |
| `make check` | SHA-1/SHA-256/HMAC/Base64 (vetores das RFCs), handshake e quadros WebSocket, parser WebM cortado em 1 a 64 bytes, tabela inteira da máquina de estados, UTF-8, JSON |
| `./configure --with-mysql=no && make && make itest` | 76 verificações do protocolo: espera, aceitar, negar, banir (IP e identidade), desbanir, retirar, mão levantada, orador, WebM real do ffmpeg entregue byte a byte e decodificável por quem entrou no meio, resolução, chat, idempotência, sala trancada, reconexão do orador, encerramento |
| `python3 tests/integration/test_broadcast.py --bin ./bcastd --mysql BANCO` | a mesma suíte contra MySQL/MariaDB real (prepare com `tests/integration/seed_mysql.sql`) |
| `python3 tests/e2e/test_browser.py --bin ./bcastd --db BANCO` | Chromium com câmera falsa: MediaRecorder → bcastd → `<video>` do organizador, pelas páginas PHP |
| `python3 tests/load/viewers.py --viewers 300` | carga: 1 orador e N ouvintes |

## Estrutura

```
configure, Makefile.in      build
src/main.c                  ciclo de vida e manutenção
src/bc_net.c                aceitação, threads de E/S, handshake, quadros, filas de saída, timeouts
src/bc_ws.c                 HTTP Upgrade e codificação RFC 6455
src/bc_fsm.c                tabelas das máquinas de estado
src/bc_room.c               registro de salas, ações das transições, orador, fan-out de mídia
src/bc_proto.c              despacho das mensagens JSON e comandos do administrador
src/bc_ebml.c               parser incremental mínimo de WebM
src/bc_db.c                 fila de jobs da thread de banco
src/bc_db_mysql.c           backend MySQL/MariaDB (prepared statements)
src/bc_db_stub.c            backend em memória para testes
src/bc_crypto.c             SHA-1, SHA-256, HMAC, Base64
docs/PROTOCOL.md            protocolo completo
```

Ordem de travas (nunca inverter): registro de salas → `room->mu` → `conn->mu` → `worker->mu` → `buf->mu`. Com a trava da sala presa só se enfileiram envios e jobs; nenhuma E/S de socket nem consulta ao banco.

## Navegadores

- Para **transmitir** (orador): Chrome, Edge ou Firefox (precisam gerar WebM no `MediaRecorder`).
- Para **assistir**: navegadores com Media Source Extensions e WebM VP8 (Chrome, Edge, Firefox; Safari 17+ via `ManagedMediaSource`, dependendo do suporte a WebM do aparelho).
