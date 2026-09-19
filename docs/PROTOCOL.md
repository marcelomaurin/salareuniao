# Protocolo de Integração

## Camadas

A plataforma possui três famílias de comunicação:

1. **REST/HTTPS** — cadastro, autenticação, salas, agenda e operações persistentes.
2. **WebSocket** — presença, chat, eventos e sinalização WebRTC.
3. **Device Gateway** — ESP32/ESP8266 e equipamentos de sala.

## Compatibilidade com o protocolo atual

Durante a migração o gateway continua aceitando:

```text
GET SALA:=Sala 1
GET AGENDA:=Reunião de TI
GET STATUS:=Livre
GET ALL
```

Esse formato fica identificado como **Device Protocol v0 (legacy)**.

## Device Protocol v1

A evolução deve usar mensagens estruturadas e versionadas:

```json
{
  "v": 1,
  "type": "device.state",
  "deviceId": "room-panel-01",
  "roomId": "sala-01",
  "timestamp": "2026-09-19T13:00:00Z",
  "payload": {
    "status": "available"
  }
}
```

Tipos iniciais:

- `device.hello`
- `device.state`
- `device.telemetry`
- `device.command`
- `device.command.ack`
- `room.status`
- `meeting.started`
- `meeting.ended`
- `meeting.call`

## Segurança

O firmware novo não deve conter credenciais permanentes de usuário. Cada dispositivo deverá possuir identidade própria e token/chave revogável. Toda conexão externa deve preferencialmente usar TLS.

## WebRTC Signaling

Mensagens mínimas:

- `meeting.join`
- `meeting.leave`
- `webrtc.offer`
- `webrtc.answer`
- `webrtc.ice-candidate`
- `participant.joined`
- `participant.left`

A sinalização não transporta o vídeo; ela apenas coordena o estabelecimento da mídia WebRTC.


## WebSocket v1

A implementação atual usa uma conexão autenticada por token de convite aprovado:

```text
wss://meet.seu-dominio.example/ws?token=<TOKEN>
```

O token é validado no MySQL. A sessão somente permanece ativa enquanto:
- o convite estiver `approved`;
- a reunião estiver `open`.

Eventos:
- `signal` — offer/answer/ICE/peer-ready/leave;
- `chat` — mensagem persistente;
- `presence` — microfone/câmera/tela e heartbeat;
- `session-ended` — remoção, fechamento ou cancelamento;
- `ping/pong`.

O cliente mantém os endpoints HTTP existentes como fallback automático.


## REST API v1

Autenticação de usuário:

```text
POST /api/v1/auth/login.php
Authorization: Bearer <USER_TOKEN>
```

Autenticação de dispositivo:

```text
Authorization: Bearer <DEVICE_TOKEN>
```

Principais recursos:
- `/api/v1/rooms.php`
- `/api/v1/room.php`
- `/api/v1/room_invites.php`
- `/api/v1/agenda.php`
- `/api/v1/devices.php`
- `/api/v1/device/heartbeat.php`
- `/api/v1/device/state.php`
- `/api/v1/device/events.php`

O token de usuário não deve ser gravado no firmware. Dispositivos possuem credenciais próprias e revogáveis.


## Device Commands v1

O backend possui uma fila persistente de comandos por dispositivo.

ESP32 consulta:

```text
GET /api/v1/device/commands.php
Authorization: Bearer <DEVICE_TOKEN>
```

Resposta:

```json
{
  "ok": true,
  "commands": [
    {
      "id": 10,
      "command_type": "message",
      "payload": {
        "value": "Reunião iniciando"
      }
    }
  ]
}
```

Depois da execução, o dispositivo confirma:

```text
POST /api/v1/device/commands.php
```

```json
{
  "command_id": 10,
  "status": "acked",
  "ack_payload": {
    "message": "message displayed"
  }
}
```

Estados da fila:

- `pending`
- `delivered`
- `acked`
- `failed`

Um comando entregue sem ACK pode ser reenviado após timeout.

Comandos iniciais do firmware:

- `refresh`
- `led_on`
- `led_off`
- `message`
- `nextion_page`
- `reboot`
