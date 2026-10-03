# Protocolo do Canal de Controle Administrativo (Sala Reunião)

Este documento especifica a arquitetura e o protocolo do canal de controle WebSocket (`/control` ou `/ws/control`) do **Sala Reunião**, implementado conforme as Tarefas 08 a 36 e 48 a 50.

---

## 1. Visão Geral e Princípios Arquiteturais

1. **Separação de Planos (Control Plane vs. Media Plane):**
   - O plano de controle trafega por um WebSocket independente da sinalização e do tráfego WebRTC Mesh.
   - Mesmo que conexões P2P, ICE ou TURN falhem, o canal de controle permanece operacional para permitir ações administrativas (expulsão, silenciamento, suspensão de vídeo, encerramento de apresentação).

2. **Autoridade Server-Side:**
   - O servidor WebSocket de controle valida os dados de sessão (`token`, `room_id`, `is_admin`) diretamente no banco de dados.
   - Mensagens com flag `CAN_ADMIT` ou papéis enviados pelo navegador não são confiadas sem validação no banco.
   - Comandos administrativos só afetam participantes pertencentes à mesma `room_id`.

3. **Arquitetura Cliente:**
   - O navegador nunca é transformado em servidor WebSocket; cada participante conecta-se como **cliente** ao endpoint central.

---

## 2. Conexão e Autenticação

### Endpoint WebSocket
```text
wss://<servidor>/control
ou
wss://<servidor>/ws/control
```

### Inicialização (Handshake)
Ao estabelecer a conexão, o cliente deve enviar imediatamente a mensagem de inicialização com seu `token` de sessão:

```json
{
  "type": "init",
  "token": "a1b2c3d4e5f6..."
}
```

O servidor localiza o convite em `room_invites`, identifica a sala (`room_id`), a chave do participante (`participant_key`) e se possui privilégios de anfitrião/administrador (`is_admin`).

### Resposta de Inicialização
```json
{
  "type": "init.ok",
  "room_id": 42,
  "participant_key": "part_abc123",
  "is_admin": true,
  "state_version": 105
}
```

---

## 3. Protocolo de Comandos Administrativos

Todos os comandos possuem versão de protocolo e `command_id` único (UUID v4) para garantir idempotência.

### Formato do Pacote de Comando
```json
{
  "protocol": 1,
  "type": "command",
  "command_id": "9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d",
  "command": "participant.audio.inhibit",
  "room_id": 42,
  "target_key": "part_xyz789",
  "payload": {}
}
```

### Catálogo de Comandos Implementados

| Comando | Escopo | Descrição |
| :--- | :--- | :--- |
| `participant.video.allow` | Participante | Concede permissão administrativa para envio de vídeo. |
| `participant.video.inhibit` | Participante | Suspende permissão administrativa de envio de vídeo. |
| `participant.audio.allow` | Participante | Concede permissão administrativa para microfone. |
| `participant.audio.inhibit` | Participante | Bloqueia microfone do participante (envio desativado). |
| `participant.screen.allow` | Participante | Autoriza transmissão de tela. |
| `participant.screen.inhibit` | Participante | Bloqueia transmissão de tela. |
| `participant.kick` | Participante | Expulsa o participante (revoga convite e desconecta). |
| `room.presentation.start` | Sala | Inicia Modo Apresentação / Full com o participante informado. |
| `room.presentation.end` | Sala | Encerra Modo Apresentação e restaura grade e mídias normais. |
| `room.close` | Sala | Encerra a reunião para todos os participantes. |

---

## 4. Confirmação de Aplicação (ACK)

O cliente alvo deve obrigatoriamente responder com ACK ao aplicar ou rejeitar o comando:

```json
{
  "type": "command.ack",
  "command_id": "9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d",
  "status": "applied",
  "room_id": 42,
  "target_key": "part_xyz789"
}
```

### Status de ACK:
- `received`: Comando recebido pelo cliente.
- `applied`: Comando executado e efeito aplicado com sucesso.
- `failed`: Falha ao aplicar comando (ex.: câmera indisponível ou hardware travado).
- `ignored`: Comando ignorado por idempotência (`command_id` já executado) ou versão desatualizada.

Em caso de falha:
```json
{
  "type": "command.ack",
  "command_id": "...",
  "status": "failed",
  "error": "camera_device_error"
}
```

---

## 5. Sincronização de Estado (`state.sync`)

### Solicitação de Sincronização
Após conexão ou reconexão do WebSocket:
```json
{
  "type": "state.sync.request"
}
```

### Resposta do Servidor
O servidor consulta a tabela `room_runtime_state` e os dados do participante, respondendo com o estado autoritativo consolidado:
```json
{
  "type": "state.sync",
  "room": {
    "mode": "presentation",
    "presenter_key": "part_abc123",
    "media_type": "camera",
    "state_version": 105
  },
  "participant": {
    "video_allowed": true,
    "audio_allowed": true,
    "screen_allowed": false,
    "kicked": false
  }
}
```

---

## 6. Versionamento de Estado e Idempotência

1. **State Version (`state_version`):**
   - Cada alteração administrativa no estado da sala incrementa monotonicamente `state_version` no banco (`room_runtime_state`).
   - Clientes ignoram comandos ou eventos com `state_version < versao_atual` para evitar que mensagens defasadas revertam o estado atual.

2. **Idempotência (`command_id`):**
   - O servidor e os clientes mantêm um cache dos `command_id` executados recentemente.
   - Comandos com `command_id` duplicado respondem imediatamente o ACK anterior sem reexecutar ações destrutivas ou de mídia.

---

## 7. Watchdog e Reconexão Automática

O módulo `MeetingControl` implementa watchdog com estratégia de reconexão exponencial:
- Conexão monitorada via `controlStatusIndicator` no cabeçalho da sala.
- Em caso de desconexão, reconexões periódicas (1s, 2s, 4s até 10s).
- Após cada reconexão bem-sucedida, um `state.sync.request` é executado obrigatoriamente antes de retomar operações.
