# Integração com o Servidor Broadcast (bcastd) · Sala Reunião Desktop

Este documento especifica a arquitetura de comunicação entre o aplicativo **Lazarus Desktop** (`desktop/`) e o servidor de broadcast **`bcastd`** (`linuxsrv/broadcast/`), além dos requisitos visuais inspirados no layout do Microsoft Teams.

---

## 1. Visão Geral e Arquitetura

O servidor `bcastd` (desenvolvido em C90 com pthreads) centraliza a distribuição de mídia (WebM) e as mensagens de controle da sala via WebSocket.

```text
┌────────────────────────────────────────────────────────┐
│             APLICAÇÃO LAZARUS DESKTOP                  │
│                                                        │
│  ┌──────────────────────────────────────────────────┐  │
│  │   Interface Visual Nativa (Estilo Teams Grid)    │  │
│  │   • Grid de Câmeras/Avatares dos Participantes   │  │
│  │   • Barra Superior (Timer, Mic, Cam, Mão, Sair)  │  │
│  │   • Painel Lateral (Lobby, Fila de Mão, Chat)    │  │
│  └────────────────────────┬─────────────────────────┘  │
│                           │                            │
│  ┌────────────────────────┴─────────────────────────┐  │
│  │     Cliente de Protocolo Broadcast (TBcastClient)│  │
│  │     • Conexão WebSocket / TLS                    │  │
│  │     • Envio e recepção de JSON (v: 1)            │  │
│  └────────────────────────┬─────────────────────────┘  │
└───────────────────────────┼────────────────────────────┘
                            │ WebSocket (wss://.../broadcast)
                            ▼
┌────────────────────────────────────────────────────────┐
│           SERVIDOR LINUX (linuxsrv/broadcast)          │
│                       bcastd                           │
│  • Gestão de Sessões, FSM e Permissões                 │
│  • Sala de Espera (Lobby) e Admissão Administrativa    │
│  • Fila FIFO de Oradores (Pedir a Palavra)             │
│  • Distribuição de Vídeo e Áudio WebM                  │
└────────────────────────────────────────────────────────┘
```

---

## 2. Especificação da Interface Visual (Design Inspirado no Microsoft Teams)

Com base no layout corporativo de referência:

### 2.1. Área Central — Grid de Participantes
- **Grade Dinâmica de Vídeos/Avatares:** Exibição dos participantes em blocos proporcionais (1x1, 2x2, 3x3, etc.).
- **Destaque do Orador Ativo (`speaker`):** O participante com a palavra recebe borda destacada e indicador de áudio ativo.
- **Rótulo de Identificação:** Tarja semitransparente no canto inferior esquerdo de cada card com o nome do participante e status de microfone.

### 2.2. Barra Superior de Controles
- **Cronômetro da Reunião:** Relógio em tempo real com o tempo decorrido (`HH:MM:SS`).
- **Botões de Ação Rápida:**
  - 👥 **Participantes & Espera:** Exibe/oculta a barra lateral com a lista e contagem de participantes.
  - 💬 **Chat:** Alterna o painel de mensagens em tempo real.
  - ✋ **Pedir a Palavra:** Aciona `hand.raise` / `hand.lower` (fila FIFO).
  - 📷 **Câmera:** Alterna o envio de vídeo do usuário.
  - 🎤 **Microfone:** Alterna o mudo local.
  - 🖥 **Compartilhar Tela:** Aciona o compartilhamento de desktop.
  - 🚪 **Sair / Encerrar (Botão Vermelho "Leave"):** Desconecta com envio de `bye` e confirmação.

### 2.3. Painel Lateral Direito (Retrátil)
- **Sala de Espera (Lobby):**
  - Lista participantes que aguardam autorização (`state = "waiting"`).
  - Botões para o Administrador: **Admitir** (`admin.admit`), **Admitir Todos** (`admin.admit_all`) e **Recusar** (`admin.deny`).
- **Fila de Mãos Levantadas:**
  - Ordenação estrita por tempo de solicitação.
  - Botões administrativos para **Conceder a Palavra** (`admin.hand.accept`) ou **Dispensar** (`admin.hand.reject`).
- **Chat da Reunião:**
  - Lista de mensagens com remetente, horário e balão de texto.
  - Campo de entrada com envio por tecla Enter ou botão Enviar (`chat.send`).
- **Gestão de Convites (Card de E-mail):**
  - Módulo para emissão de novos convites (`admin.invite`) com geração de links únicos e envio automático de e-mail recapitulando a reunião.

---

## 3. Protocolo WebSocket do bcastd (Versão 1)

O aplicativo Lazarus implementa a classe `TBcastClient` que gerencia a máquina de estados do protocolo:

### 3.1. Handshake e Entrada (`hello`)
Ao conectar, o cliente envia:
```json
{
  "v": 1,
  "t": "hello",
  "room_token": "<token_da_sala>",
  "invite_token": "<token_do_convite>",
  "name": "Nome do Usuário",
  "client": "desktop"
}
```

O servidor responde com `welcome`:
```json
{
  "v": 1,
  "t": "welcome",
  "session": 17,
  "pkey": "<chave_hex_64>",
  "name": "Nome do Usuário",
  "role": "admin",
  "state": "in_room",
  "room_id": 1,
  "room_name": "Sala do Conselho",
  "state_version": 12,
  "server": "bcastd/0.1.0"
}
```

### 3.2. Sincronização de Estado (`state.sync`)
O servidor envia o panorama completo da sala:
- Lista de participantes online e seus papéis (`admin`, `speaker`, `viewer`).
- Orador atual (`speaker`).
- Fila de espera do lobby (para administradores).
- Fila FIFO de mãos levantadas (`hand_queue`).
- Histórico recente do chat.

### 3.3. Comandos Administrativos (com ID e Idempotência)
- `admin.admit`: `{"v":1,"t":"admin.admit","id":"<uuid>","pkey":"<part_key>"}`
- `admin.deny`: `{"v":1,"t":"admin.deny","id":"<uuid>","pkey":"<part_key>"}`
- `admin.hand.accept`: `{"v":1,"t":"admin.hand.accept","id":"<uuid>","pkey":"<part_key>"}`
- `admin.speaker.set`: `{"v":1,"t":"admin.speaker.set","id":"<uuid>","pkey":"<part_key>"}`
- `admin.mute`: `{"v":1,"t":"admin.mute","id":"<uuid>","pkey":"<part_key>"}`
- `admin.kick`: `{"v":1,"t":"admin.kick","id":"<uuid>","pkey":"<part_key>"}`
- `admin.close`: `{"v":1,"t":"admin.close","id":"<uuid>"}`

### 3.4. Chat em Tempo Real
- Envio: `{"v":1,"t":"chat.send","text":"Olá a todos!"}`
- Broadcast recebido: `{"v":1,"t":"chat.msg","from":"Nome","pkey":"...","text":"Olá a todos!","time":"14:32"}`

---

## 4. Renderização Audiovisual no Desktop
- O cliente pode operar com o visualizador embutido **CEF4Delphi** apontado para `broadcast.php?token=...`, garantindo decodificação acelerada por hardware de WebM (VP8/Opus) via `MediaSource Extensions`.
- A interface nativa Lazarus se encarrega de sincronizar toda a lista de participantes, controle de fila e ações administrativas em paralelo.
