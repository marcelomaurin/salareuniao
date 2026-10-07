# Integração com o Servidor Broadcast (bcastd) · Sala Reunião Desktop

Este documento especifica a arquitetura de comunicação entre o aplicativo **Lazarus Desktop** (`desktop/`) e o servidor de broadcast **`bcastd`** (`linuxsrv/broadcast/`), além dos requisitos visuais baseados no layout do **Microsoft Teams**.

---

## 1. Visão Geral e Arquitetura

O servidor `bcastd` (desenvolvido em C90 com pthreads) centraliza a distribuição de mídia (WebM) e as mensagens de controle da sala via WebSocket.

```text
┌────────────────────────────────────────────────────────┐
│             APLICAÇÃO LAZARUS DESKTOP                  │
│                                                        │
│  ┌──────────────────────────────────────────────────┐  │
│  │   Interface Visual Nativa (Estilo Teams Dark)    │  │
│  │   • Modo Palco / Spotlight (Orador Principal)    │  │
│  │   • Coluna Lateral de Galeria de Participantes   │  │
│  │   • Barra Superior (Timer, Mic, Cam, Mão, Sair)  │  │
│  │   • Painel de Chat e Fila de Espera              │  │
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

## 2. Modos de Visualização da Interface (Estilo Microsoft Teams)

O cliente desktop implementa dois modos de visualização alternáveis:

### 2.1. Modo Galeria (Grid Completo — Imagem 1)
- Grade proporcional distribuindo todos os participantes na tela (ex.: 2x2, 3x3).
- Cartões com avatares/vídeos, tarja inferior com nome e ícone de microfone.
- Destaque com borda luminosa (roxo/azul) para quem estiver falando.

### 2.2. Modo Palco / Spotlight (Apresentação e Orador — Imagem 3)
- **Área Central (Stage):** Espaço amplo dedicado ao orador ativo (`speaker`) ou ao compartilhamento de tela.
- **Galeria Lateral Direita:** Coluna com cartões dos demais participantes em miniatura e trilha vertical de avatares com iniciais coloridas.
- **Painel de Chat Integrado:** Balões de conversa com identificação de remetente, horário e avatar.

### 2.3. Barra Superior de Controles (Dark Theme)
- Cronômetro no canto superior esquerdo (`22:06`).
- Botões de controle no topo à direita:
  - 👥 **Participantes & Espera:** Alterna o painel lateral com lista de presença e admissão do lobby.
  - 💬 **Chat:** Alterna o painel de mensagens instantâneas.
  - ✋ **Pedir a Palavra:** Aciona `hand.raise` / `hand.lower` com fila visual FIFO.
  - 📷 **Câmera:** Ativa/desativa transmissão de vídeo local.
  - 🎤 **Microfone:** Alterna mudo/ativo.
  - 🖥 **Compartilhar Tela:** Inicia transmissão da área de trabalho.
  - 🚪 **Botão Vermelho "Leave":** Encerra ou sai da conferência com envio de `bye`.

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
