# Protocolo do bcastd (versão 1)

Uma conexão WebSocket por cliente leva **controle** (quadros de texto JSON) e **mídia** (quadros binários).

- Endpoint público: `wss://<host>/salareuniao/broadcast` (o Apache termina o TLS e repassa para `127.0.0.1:8090`).
- Todo JSON tem `"v": 1` e `"t": "<tipo>"`. Comandos que pedem confirmação levam `"id"` (UUID); o servidor responde `ack` com `"ref"` igual a esse `id`.
- Comandos `admin.*` são idempotentes por `id`: repetir o mesmo `id` devolve o `ack` original sem executar de novo.
- O `Origin` do navegador precisa estar em `allowed_origin`.

## 1. Estados da conexão

```
NEW → HANDSHAKE → AUTH ─┬─ hello sem convite ───────────→ WAITING ─┬─ admin.admit ─→ IN_ROOM
                        ├─ hello de admin/convite aprovado ───────────────────────→ IN_ROOM
                        └─ IP/identidade banidos ───────→ BANNED   ├─ admin.deny ──→ DENIED
                                                                   ├─ admin.ban_waiting → BANNED
                                                                   └─ bye/timeout → LEFT
IN_ROOM ─┬─ admin.kick → KICKED   ─┬─ admin.ban → BANNED   ─┬─ bye/queda/inatividade → LEFT
         └─ reconexão com a mesma identidade: a conexão antiga recebe goodbye "replaced"
```

Dentro de `IN_ROOM` existe o subestado de fala: `viewer → hand → pending → speaking → viewer`. Só há um `pending`/`speaking` por sala. `pending` expira em `speaker_pending_ms` sem `media.init`.

## 2. Entrada

Primeira mensagem do cliente (até `hello_timeout_ms`):

```json
{"v":1,"t":"hello","room_token":"<rooms.join_token>","invite_token":"<room_invites.token opcional>","name":"Ana","client":"web"}
```

Regras, nesta ordem:

1. `room_token` inexistente → `error room_not_found`; sala com status diferente de `open` → `error room_not_open`.
2. Convite revogado → `error invite_revoked`.
3. IP, `participant_key` ou e-mail com banimento ativo → `error banned` (o dono da sala nunca é bloqueado).
4. Sala trancada (`admin.lock`) e sem convite → `error room_locked`.
5. Convite do dono da sala, de `room_admins` ou de usuário com `role = admin` → papel **admin**, entra direto.
6. Convite com status `approved` e `invite_auto_admit = 1` → entra direto como ouvinte.
7. Qualquer outro caso → **sala de espera**; os admins recebem `lobby.join`.

Resposta: `welcome` e, para quem entra na sala, `state.sync`.

```json
{"v":1,"t":"welcome","session":17,"pkey":"<64 hex>","name":"Ana","role":"viewer","state":"waiting","room_id":1,"room_name":"Sala","state_version":12,"server":"bcastd/0.1.0"}
```

Quem entra pelo link público e é aceito recebe `invite.token` com um convite próprio; o cliente guarda e usa em `invite_token` ao reconectar (assim volta direto para a sala, inclusive com a palavra, se era o orador).

## 3. Mensagens do cliente

| `t` | Quem | Campos | Efeito |
| --- | --- | --- | --- |
| `hello` | qualquer | ver acima | autenticação |
| `ping` | qualquer | — | `pong` |
| `bye` | qualquer | — | sai (`LEFT`) |
| `chat.send` | na sala | `text` (até 2.000 caracteres) | `chat.msg` para todos na sala; grava em `room_messages` |
| `chat.private` | espera ou sala | `text`; admin informa `pkey` | admin → participante; quem espera → admins |
| `hand.raise` / `hand.lower` | ouvinte | — | entra/sai da fila FIFO; todos recebem `hand.queue` |
| `media.init` | orador | `mime`, `gen`, `w`, `h` | abre a geração `gen` (a atual ou a seguinte) |
| `media.stop` | orador | — | encerra a fala |
| `state.sync.request` | na sala | — | reenvia `state.sync` |
| binário | orador | ver seção 6 | segmento WebM |

## 4. Comandos do administrador

Todos com `id` e resposta `ack {"status":"applied"|"failed","error":...}`.

| `t` | Campos | Efeito |
| --- | --- | --- |
| `admin.admit` | `pkey` | espera → sala |
| `admin.admit_all` | — | aceita todos que estão na espera |
| `admin.deny` | `pkey`, `reason?` | espera → `DENIED` (pode tentar de novo) |
| `admin.ban_waiting` | `pkey`, `by` (`ip`/`identity`/`both`), `minutes?`, `reason?` | espera → `BANNED`; grava `room_bans` |
| `admin.kick` | `pkey`, `reason?` | sala → `KICKED` (volta pela espera) |
| `admin.ban` | `pkey`, `by`, `minutes?`, `reason?` | sala → `BANNED`; banir por IP derruba outras conexões do mesmo IP |
| `admin.unban` | `ban_id` | desativa o banimento e reenvia `bans` |
| `admin.bans.list` | — | resposta `bans {list:[...]}` |
| `admin.invite` | `email`, `name?` | cria convite; resposta `invite.created {link}`; e-mail vai para `broadcast_outbox` |
| `admin.invite.revoke` | `invite_id` | revoga e derruba quem estiver usando o convite |
| `admin.speaker.set` | `pkey` | escolhe o orador (o anterior para) |
| `admin.speaker.clear` | — | ninguém transmite |
| `admin.hand.accept` | `pkey` | dá a palavra a quem levantou a mão |
| `admin.hand.reject` | `pkey` | tira da fila; o alvo recebe `hand.rejected` |
| `admin.resolution.set` | `pkey`, `w`, `h`, `fps?`, `kbps?` | perfis: 426×240, 640×360, 854×480, 1280×720, 1920×1080; no orador gera `media.profile` com nova geração |
| `admin.mute` / `admin.unmute` | `pkey` | silencia o microfone do orador (`media.mute`) |
| `admin.lock` / `admin.unlock` | — | sala trancada só aceita convites individuais |
| `admin.close` | — | encerra a reunião: todos recebem `goodbye room_closed`; `rooms.status = 'closed'` |
| `admin.stats` | — | resposta `stats` com contadores da sala |

Comando de quem não é admin → `error not_admin`. Todos os comandos `admin.*` são gravados em `room_control_audit`.

## 5. Mensagens do servidor

| `t` | Para | Conteúdo |
| --- | --- | --- |
| `welcome` | quem entrou | identidade, papel e estado |
| `state.sync` | quem está na sala | sala (estado, trancada, orador, perfil), participantes, fila de mão, chat recente; admins recebem também `waiting` (com IP) |
| `admitted` | quem foi aceito | — |
| `invite.token` | quem foi aceito pelo link público | convite para reconexão |
| `lobby.join` / `lobby.leave` | admins | entrada/saída da espera |
| `participant.joined` / `participant.left` / `participant.state` | sala | mudanças de participantes (IP só para admins) |
| `hand.queue` | sala | fila de mão em ordem FIFO |
| `hand.rejected` | alvo | pedido recusado |
| `speaker.changed` | sala | `speaker` (ou `null`), `live`, `gen`, `mime` |
| `speaker.you` | orador escolhido | `gen` e `profile` para iniciar |
| `media.profile` | orador | nova resolução e geração |
| `media.restart` | orador | WebM inválido: reiniciar com `gen + 1` |
| `media.stop.request` | orador | parar de transmitir |
| `media.mute` | orador | `muted` |
| `stream.reset` | ouvinte | recriar o `SourceBuffer`; seguem o quadro de inicialização e os dados desde o último keyframe |
| `room.state` | sala | `locked`, `state` |
| `chat.msg` / `chat.private` | sala / destinatário | mensagem (texto cru; o cliente escapa) |
| `invite.created`, `bans`, `stats` | admin | respostas |
| `ack` | remetente | confirmação de comando |
| `error` | remetente | `code`, `msg` |
| `goodbye` | quem sai | `state` final e `reason`; a conexão fecha em seguida |

Códigos de erro: `bad_json`, `bad_request`, `bad_hello`, `hello_timeout`, `not_authenticated`, `protocol_version`, `room_not_found`, `room_not_open`, `room_locked`, `invite_revoked`, `banned`, `server_full`, `service_unavailable`, `waiting`, `not_allowed`, `not_admin`, `not_speaker`, `bad_target`, `bad_profile`, `bad_email`, `stale_gen`, `unsupported_media`, `empty_message`, `message_too_long`, `rate_limited`, `nobody_waiting`, `speaker_timeout`, `unknown_type`.

## 6. Quadro binário de mídia

Cabeçalho fixo de 16 bytes (big-endian) seguido do pedaço WebM produzido pelo `MediaRecorder`:

| Bytes | Campo | Valor |
| --- | --- | --- |
| 0 | magic | `0xB5` |
| 1 | tipo | 1 = primeiro pedaço / inicialização, 2 = dados |
| 2 | flags | bit 0 = contém início de keyframe (preenchido pelo servidor) |
| 3 | geração | muda a cada `media.init` |
| 4–7 | seq | sequência |
| 8–15 | timestamp | relógio do emissor em ms (o cliente mostra a latência) |

O servidor não decodifica mídia. Ele lê a estrutura EBML para separar o cabeçalho de inicialização (EBML + Segment + Info + Tracks), achar o início de cada Cluster e saber se o primeiro bloco de vídeo do Cluster é keyframe. Quem entra no meio recebe `stream.reset`, o cabeçalho e os dados a partir do último Cluster com keyframe. Quadros de outra geração são descartados.

Ouvinte cuja fila de saída passa de `max_queue_bytes` deixa de receber e é ressincronizado no próximo keyframe (as mensagens de controle continuam chegando).

## 7. Limites

- Quadro WebSocket: `max_frame_bytes` (1 MB). JSON: 64 KB, profundidade 8.
- 40 mensagens de texto/s por conexão; 5 mensagens de chat/s; ~12 Mbit/s de mídia por orador.
- `max_clients`, `max_rooms`, `max_clients_per_room` no `broadcast.conf`.
