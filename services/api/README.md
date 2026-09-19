# API REST v1

A API REST serve clientes Desktop e dispositivos sem duplicar as regras da aplicação Web.

Base sugerida:

```text
https://meet.seu-dominio.example/api/v1
```

No layout atual do projeto, os scripts ficam em `apps/web/api/v1`.

## Autenticação de usuário

### Login

`POST /api/v1/auth/login.php`

```json
{
  "email": "usuario@example.com",
  "password": "senha",
  "client_name": "Desktop Lazarus"
}
```

Resposta:

```json
{
  "ok": true,
  "token": "TOKEN",
  "token_type": "Bearer",
  "expires_at": "2026-10-19 12:00:00",
  "user": {
    "id": 1,
    "name": "Usuário",
    "email": "usuario@example.com",
    "role": "user"
  }
}
```

Use nas demais chamadas:

```text
Authorization: Bearer TOKEN
```

Tokens de usuário ficam armazenados apenas como SHA-256 no banco e podem ser revogados.

### Perfil

`GET /api/v1/auth/me.php`

### Logout/revogação

`POST /api/v1/auth/logout.php`

## Salas

### Listar/criar

`GET /api/v1/rooms.php`

Usuário vê as próprias salas. Admin pode usar:

```text
GET /api/v1/rooms.php?scope=all
```

`POST /api/v1/rooms.php`

```json
{
  "name": "Reunião técnica",
  "description": "Acompanhamento do projeto",
  "starts_at": "2026-09-22 14:00:00"
}
```

A resposta inclui `host_join_token`, usado pelo cliente para entrar no WebSocket/WebRTC como anfitrião.

### Detalhe/gestão

`GET /api/v1/room.php?id=123`

`POST /api/v1/room.php?id=123`

Ações:

```json
{"action":"open"}
{"action":"close"}
{"action":"cancel"}
{"action":"update","name":"Novo nome","starts_at":"2026-09-22 15:00:00"}
```

## Convites

`GET /api/v1/room_invites.php?room_id=123`

Adicionar:

```json
{
  "room_id": 123,
  "action": "add",
  "emails": ["a@example.com", "b@example.com"]
}
```

Autorizar:

```json
{"room_id":123,"action":"approve","invite_id":55}
```

Outras ações:
- `reject`
- `resend`
- `remove`

## Agenda

`GET /api/v1/agenda.php?days=30`

Admin pode usar `scope=all`.

## Dispositivos

Somente admin cria e gerencia dispositivos.

### Cadastro/listagem

`GET /api/v1/devices.php`

`POST /api/v1/devices.php`

```json
{
  "action": "create",
  "name": "Painel Sala 1",
  "device_uid": "ESP32-001",
  "type": "esp32",
  "room_id": 123
}
```

A resposta contém o token do dispositivo **uma única vez**.

Outras ações:
- `rotate_token`
- `revoke`
- `update`

## API do ESP32

O ESP32 usa seu próprio Bearer token, não credenciais de usuário.

### Heartbeat

`POST /api/v1/device/heartbeat.php`

```json
{
  "status": "online",
  "firmware_version": "1.0.0",
  "free_heap": 183240,
  "rssi": -58,
  "uptime_seconds": 4200
}
```

### Estado/agenda

`GET /api/v1/device/state.php`

Retorna:
- identificação do dispositivo;
- sala vinculada;
- status da reunião;
- quantidade de participantes online;
- agenda próxima;
- horário do servidor.

### Telemetria/eventos

`POST /api/v1/device/events.php`

```json
{
  "type": "device.telemetry",
  "payload": {
    "temperature": 31.2,
    "free_heap": 182912
  }
}
```

## Segurança

- HTTPS obrigatório em produção.
- Tokens são enviados apenas em `Authorization: Bearer`.
- O banco armazena somente SHA-256 dos tokens.
- Tokens de dispositivo são independentes dos usuários.
- Tokens podem ser rotacionados/revogados.
- Perfil `user` não acessa salas de terceiros.
- Perfil `admin` pode usar visão global.


## Firmware OTA ESP32

Manifesto autenticado:

```text
GET /api/v1/device/firmware.php?current=1.0.0
Authorization: Bearer DEVICE_TOKEN
```

Manifesto de release específico:

```text
GET /api/v1/device/firmware.php?release_id=12&current=1.0.0
```

Resposta contém:
- ID do release;
- versão;
- tamanho;
- SHA-256;
- obrigatoriedade;
- notas;
- caminho autenticado de download.

Download:

```text
GET /api/v1/device/firmware_download.php?id=12
Authorization: Bearer DEVICE_TOKEN
```

O binário não é disponibilizado como arquivo público estático.
