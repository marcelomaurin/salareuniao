# Sala de Espera (Lobby) e Fluxo de Admissão · Sala Reunião Desktop

Este documento especifica o comportamento da **Sala de Espera (Lobby)** para usuários normais e os controles de moderação do administrador.

---

## 1. Regra Central de Entrada

> **Ao entrar na reunião, qualquer usuário comum é colocado automaticamente na Sala de Espera (Lobby). O acesso à videoconferência só é liberado após admissão explícita pelo administrador.**

Apenas o anfitrião, proprietário da sala ou usuários com papel de `admin` entram diretamente na videoconferência ativa.

---

## 2. Diagrama de Transição de Estados (Lobby)

```text
[ USUÁRIO COMUM / CONVIDADO ]
              │
              ▼ Acessa via token / e-mail
      Envia 'hello' ao bcastd
              │
              ▼
   ┌────────────────────────────────────────────────────────┐
   │       ESTADO: WAITING (SALA DE ESPERA / LOBBY)         │
   │                                                        │
   │  • Tela de Espera Amigável:                            │
   │    "Aguarde, o organizador permitirá sua entrada..."   │
   │  • Exibição do título da reunião                       │
   │  • Teste/ajuste prévio de microfone e câmera           │
   │  • Conexão ativa no WebSocket aguardando deliberação   │
   └────────────────────────┬───────────────────────────────┘
                            │
               bcastd emite 'lobby.join'
                            │
                            ▼
   ┌────────────────────────────────────────────────────────┐
   │       TELA DO ADMINISTRADOR (ORGANIZADOR)              │
   │                                                        │
   │  • Notificação visual no topo:                         │
   │    "[Nome] está aguardando no lobby"                   │
   │  • Painel Lateral "Sala de Espera":                    │
   │    - Lista de quem está esperando                      │
   │    - Botão [ ADMITIR ]      → admin.admit              │
   │    - Botão [ RECUSAR ]      → admin.deny               │
   │    - Botão [ ADMITIR TODOS ]→ admin.admit_all          │
   └────────────────────────┬───────────────────────────────┘
                            │
              Admin clica em [ ADMITIR ]
                            │
                            ▼
                  bcastd emite 'admitted'
                            │
                            ▼
   ┌────────────────────────────────────────────────────────┐
   │         ESTADO: IN_ROOM (VIDEOCONFERÊNCIA ATIVA)       │
   │                                                        │
   │  • Transição imediata da tela de espera para o palco   │
   │  • Exibição do Grid de Participantes e áudio/vídeo     │
   │  • Entrada anunciada para os demais participantes      │
   └────────────────────────────────────────────────────────┘
```

---

## 3. Interface Visual da Sala de Espera no Usuário (`TWaitingRoomPanel`)

Quando a resposta do servidor `bcastd` for `welcome` com `state = "waiting"`:

1. O cliente desktop esconde os controles da conferência e exibe o painel de espera centralizado:
   - **Ícone:** Ampulheta ou relógio animado moderno.
   - **Título:** *"Você está na sala de espera"*.
   - **Mensagem:** *"Assim que o organizador da reunião autorizar, sua entrada será liberada automaticamente."*.
   - **Nome da Reunião:** Destaque para o nome da sala (ex.: *"Planejamento Estratégico"*).
   - **Botão Sair:** Permite ao convidado desistir e fechar a aplicação antes de ser admitido (`bye`).

---

## 4. Controles do Administrador no Painel Lateral (`TLobbyPanel`)

Na interface do administrador:
- Ao receber o evento WebSocket `lobby.join`, a aba **People** na barra superior exibe um badge numérico destacado (ex.: `👥 People (9) · 1 no lobby`).
- O painel lateral lista os participantes pendentes com:
  - Avatar e Nome do convidado.
  - Horário em que entrou na espera.
  - Ações rápidas:
    - ✔ **Admitir (`admin.admit`):**
      ```json
      {"v": 1, "t": "admin.admit", "id": "<uuid>", "pkey": "<part_key>"}
      ```
    - ✖ **Recusar (`admin.deny`):**
      ```json
      {"v": 1, "t": "admin.deny", "id": "<uuid>", "pkey": "<part_key>", "reason": "Acesso não autorizado"}
      ```
    - 🌐 **Admitir Todos (`admin.admit_all`):**
      ```json
      {"v": 1, "t": "admin.admit_all", "id": "<uuid>"}
      ```

---

## 5. Transição Automática após Admissão

1. O servidor `bcastd` processa `admin.admit`, atualiza o participante para `IN_ROOM` e envia a mensagem `admitted` e `state.sync` para o cliente.
2. O aplicativo do convidado intercepta a mensagem `admitted` via `TBcastClient`:
   - Oculta o painel de espera `TWaitingRoomPanel`.
   - Inicializa a renderização da videoconferência (Grid de Participantes ou Palco).
   - Emite um feedback sonoro sutil de conexão bem-sucedida.
