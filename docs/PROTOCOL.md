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
