# Modo Apresentação / Full e Gestão de Palavra (Sala Reunião)

Este documento descreve a arquitetura, regras de negócio e fluxos de mídia do **Modo Apresentação / Full** e da fila de **Pedir Palavra**, implementados conforme as Tarefas 02 a 07, 11 a 28, e 37 a 47.

---

## 1. Princípios Arquiteturais e Premissas

1. **Separação Semântica Clara:**
   - **Pedir Palavra (`presentation_requested` / `hand_raised`):** Manifestação de interesse do participante para discursar ou apresentar.
   - **Apresentação Ativa (`presentation_active`):** Estado efetivo onde o participante tem sua transmissão elevada ao palco principal (Modo Full).
   - **Permissões de Mídia (`camera_admin_allowed`, etc.):** Autorização administrativa independente do modo de apresentação.

2. **O Administrador é o Controlador Exclusivo:**
   - Nenhum participante comum pode entrar sozinho em Modo Full.
   - Ao clicar em "Pedir Palavra", o participante apenas entra na fila aguardando deliberação do administrador.

3. **Fonte Autoritativa no Servidor:**
   - O estado da sala é persistido no banco de dados (`room_runtime_state`), contendo `room_mode`, `active_presenter_key`, `presentation_media_type`, `presentation_started_at` e `state_version`.
   - Um participante reconectando recebe imediatamente o estado oficial da reunião.

---

## 2. Fila Real de Pedidos de Palavra (FIFO)

### Registro com Timestamp
Quando um participante clica em **✋ Pedir Palavra**:
- O endpoint `POST api/presentation.php?action=request` é acionado.
- O banco registra `hand_raised = 1` e `hand_requested_at = NOW()`.
- O cliente exibe o estado *"✋ Mão levantada · Aguardando aprovação"*.

### Ordenação Estrita
- A fila administrativa é ordenada estritamente por:
  ```sql
  ORDER BY hand_requested_at ASC
  ```
- Garante que quem pediu primeiro seja listado primeiro, independentemente da ordem de entrada na reunião.

### Deliberação Administrativa: ACEITAR vs. RECUSAR
- **ACEITAR:**
  - Aciona `POST api/presentation.php?action=approve`.
  - Executa transação SQL atômica: remove da fila, define `room_mode = 'presentation'`, grava `active_presenter_key` e emite evento broadcast `room.presentation.start`.
- **RECUSAR:**
  - Aciona `POST api/presentation.php?action=reject`.
  - Limpa `hand_raised = 0`, define `hand_requested_at = NULL` e emite `presentation.request.rejected`.
  - O participante é notificado por toast na interface.

### Eliminação do Bug de Ressurreição pelo Heartbeat
- Se o cliente detectar `presentation_active = true` ou recusa administrativa, `isLocalHandRaised` é imediatamente resetado para `false`.
- O endpoint `api/presence.php` nunca restaura `hand_raised = 0` para `1` via heartbeat; apenas chamadas explícitas à API de apresentação podem gerar novos pedidos.

---

## 3. Comportamento no Modo Apresentação

### 3.1. Apresentador (Presenter)
Ao receber `room.presentation.start` com sua chave:
1. Marca `presentation_active = true` e `camera_room_allowed = true`.
2. Seu próprio tile (`tile-local`) é destacado no palco principal (`full-spotlight`).
3. O perfil de vídeo é elevado para alta resolução:
   - **Desejado:** 1920×1080 @ 30fps.
   - **Fallback:** 1280×720 @ 30fps ou 854×480 @ 24fps.
4. O bitrate de transmissão WebRTC é ampliado (1200–2000 kbps).
5. O botão "Pedir Palavra" é substituído pelo indicador "⛶ Apresentando".

### 3.2. Não Apresentadores (Audiência)
Ao receber `room.presentation.start` para outro participante:
1. Definem `camera_room_allowed = false`.
2. **Suspensão Real de Vídeo de Saída:**
   - Invocam `MeetingMedia.suspendOutgoingVideo()`.
   - Utilizam `RTCRtpSender.replaceTrack(null)` em todos os transceivers WebRTC de vídeo.
   - O hardware de câmera local permanece aberto em segundo plano sem perda de permissão, mas **zero bytes** de vídeo de saída trafegam pela rede de upstream.
3. **Áudio Preservado:**
   - O áudio dos participantes **NÃO** é silenciado por padrão durante a apresentação (`microphone_room_allowed = true`).
4. O palco principal dá foco total ao vídeo do apresentador.

---

## 4. Encerramento da Apresentação (`room.presentation.end`)

Ao encerrar a apresentação:
1. O administrador aciona `POST api/presentation.php?action=end` ou comando WS `room.presentation.end`.
2. O servidor define `room_mode = 'normal'`, `active_presenter_key = NULL` e transmite o evento.
3. **Restauração Inteligente de Mídia:**
   - Cada cliente recalcula `effective_video = camera_user_enabled && camera_admin_allowed && camera_room_allowed`.
   - Participantes que estavam com a câmera ligada retomam a transmissão via `MeetingMedia.resumeOutgoingVideo()`.
   - Participantes que haviam desligado sua câmera permanecem desligados.
   - O perfil normal de vídeo (640×360 @ 24fps, ~350 kbps) é restaurado.
4. A grade normal de vídeos é reexibida.

---

## 5. Modelo de Permissão de 3 Níveis

Tanto para vídeo quanto para áudio e tela, a transmissão efetiva depende de 3 camadas independentes:

```text
effective_video = camera_user_enabled AND camera_admin_allowed AND camera_room_allowed
effective_audio = microphone_user_enabled AND microphone_admin_allowed AND microphone_room_allowed
effective_screen = screen_user_enabled AND screen_admin_allowed
```

- **User Preference:** Controle do próprio usuário no botão do microfone ou câmera.
- **Admin Permission:** Bloqueio ou liberação pelo anfitrião através dos comandos `participant.*.allow` e `participant.*.inhibit`.
- **Room Policy:** Regra automática da sala (ex.: em apresentação, `camera_room_allowed = false` para não apresentadores).

Nenhum comando administrativo de permissão ativa o hardware forçadamente caso a preferência do usuário seja desligada.
