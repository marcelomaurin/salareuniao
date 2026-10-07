# Arquitetura de Software · Sala Reunião Desktop

Este documento detalha o desenho arquitetural, separação de responsabilidades e padrões de projeto adotados no cliente desktop em **Lazarus / Free Pascal**.

---

## 1. Visão Geral em Camadas

O sistema foi concebido seguindo o princípio de separação de responsabilidades (SoC), garantindo que as regras de comunicação com a API REST fiquem desacopladas dos formulários da interface visual (LCL).

```text
┌────────────────────────────────────────────────────────────────┐
│                   CAMADA DE APRESENTAÇÃO (LCL)                 │
│  ┌──────────────┐ ┌──────────────┐ ┌─────────────────────────┐  │
│  │   uMain.pas  │ │  uLogin.pas  │ │       uInvites.pas      │  │
│  └──────┬───────┘ └──────┬───────┘ └────────────┬────────────┘  │
│         │                │                      │               │
│         ▼                ▼                      ▼               │
│  ┌───────────────────────────────────────────────────────────┐  │
│  │              uMeetingView.pas (Videoconferência)          │  │
│  │     Modo CEF (TChromiumWindow)  OU  Fallback (Navegador)  │  │
│  └───────────────────────────────────────────────────────────┘  │
└────────────────────────────────┬───────────────────────────────┘
                                 │
┌────────────────────────────────▼───────────────────────────────┐
│                   CAMADA DE SERVIÇOS & NEGÓCIO                 │
│  ┌──────────────────┐ ┌──────────────────┐ ┌─────────────────┐ │
│  │  uAppConfig.pas  │ │ uDesktopNotify   │ │  uUpdater.pas   │ │
│  │  (Persistência)  │ │ (Bandeja/Alertas)│ │  (Auto-Update)  │ │
│  └──────────────────┘ └──────────────────┘ └─────────────────┘ │
└────────────────────────────────┬───────────────────────────────┘
                                 │
┌────────────────────────────────▼───────────────────────────────┐
│                   CAMADA DE COMUNICAÇÃO HTTP/REST              │
│  ┌───────────────────────────────────────────────────────────┐  │
│  │                      uApiClient.pas                       │  │
│  │       • fphttpclient / OpenSSL HTTPS                      │  │
│  │       • Bearer Token Authentication                       │  │
│  │       • Serialização e Deserialização JSON (fpjson)       │  │
│  └───────────────────────────────────────────────────────────┘  │
└────────────────────────────────┬───────────────────────────────┘
                                 │ HTTPS (Porta 443)
                                 ▼
                    Servidor Sala Reunião (Web / API)
```

---

## 2. Descrição das Unidades (`src/`)

### 2.1. `uApiClient.pas` (Cliente de API REST)
- Responsável por todas as chamadas HTTP/HTTPS direcionadas à API do Sala Reunião (`/api/v1/`).
- Mantém em memória o token de autenticação ativo (`Bearer <token>`).
- Implementa métodos de alto nível para cada operação do sistema:
  - `Login(Email, Password, ClientName)`: Autentica o usuário e armazena o token.
  - `Me()`: Retorna o perfil do usuário logado.
  - `Rooms(Scope)`: Lista salas (`all`, `active`, `mine`).
  - `RoomDetail(RoomId)`: Retorna detalhes completos da sala e o `host_join_token`.
  - `CreateRoom(Name, Description, StartsAt)`: Registra uma nova sala.
  - `RoomAction(RoomId, Action)`: Executa ações na sala (`open`, `close`, `cancel`).
  - `Invites(RoomId)`: Lista convites e status de participantes.
  - `CreateInvite(RoomId, Name, Email, AutoApprove, Role)`: Cria convite com link único.
  - `Agenda(Days, Scope)`: Obtém reuniões agendadas para os próximos dias.
  - `CheckUpdate(CurrentVersion, OS, Arch)`: Consulta nova versão do software desktop.

### 2.2. `uAppConfig.pas` (Configurações e Persistência)
- Armazena em arquivo `.ini` (localizado em diretório seguro do usuário ou junto à aplicação):
  - `WebBaseUrl`: URL base do servidor (ex.: `https://maurinsoft.com.br/salareuniao` ou `https://172.17.241.200/salareuniao`).
  - `SavedEmail`: E-mail lembrado para facilidade de login.
  - `SavedToken`: Token Bearer persistido para reconexão automática.
  - `NotificationsEnabled`: Habilitação de alertas na bandeja do sistema.
  - `AutoJoinEnabled`: Abertura automática da videoconferência ao atingir o horário da reunião.
  - `AutoJoinMinutes`: Intervalo de antecedência para disparo do auto-join.
  - `AutoUpdateEnabled`: Checagem periódica de novas versões disponíveis.

### 2.3. `uMain.pas` (Formulário Principal)
- Apresenta a interface em abas utilizando `TPageControl`:
  - **Aba 1 — Salas:** TStringGrid com salas disponíveis, status (`open`, `scheduled`, `closed`), contadores de convidados e botões de ação (Criar, Abrir, Encerrar, Convites e Entrar).
  - **Aba 2 — Agenda:** Grid das próximas reuniões agendadas por data/hora.
  - **Aba 3 — Configurações:** Parâmetros de conexão ao servidor, preferências de notificação e informações da versão.
- Gerencia os timers de segundo plano:
  - `TimerAgenda`: Executa verificação a cada 60 segundos para emitir alertas e auto-join.
  - `TimerUpdate`: Checa novas versões a cada 6 horas.

### 2.4. `uMeetingView.pas` (Janela de Videoconferência)
- Exibe a sala de reunião no cliente desktop.
- Quando compilado com `USE_CEF4DELPHI`:
  - Cria um `TChromiumWindow` que renderiza a página da reunião (`/room.php?token=...`).
  - Permite interação total com áudio, vídeo, WebRTC, chat e compartilhamento de tela.
  - Solicita fechamento controlado da instância Chromium antes de descarregar a janela.
- Modo Fallback:
  - Se a compilação for padrão ou o runtime CEF não estiver disponível, fornece botão para abrir a sala no navegador padrão do sistema operacional (`OpenURL`).

### 2.5. `uInvites.pas` (Gestão de Convites)
- Formulário modal aberto para uma sala específica.
- Exibe tabela de convidados, status (`waiting`, `approved`, `rejected`), chave do participante e tokens.
- Permite cadastrar novos participantes e enviar e-mails de convite automatizados via backend.

### 2.6. `uDesktopNotify.pas` (Notificações do Sistema)
- Utiliza `TTrayIcon` da LCL com balões de notificação nativos do Windows.
- Permite que o aplicativo continue monitorando a agenda minimizado na bandeja.

### 2.7. `uUpdater.pas` (Atualização Automática)
- Compara a versão atual da aplicação contra os dados retornados por `/api/v1/desktop_update.php`.
- Caso haja versão mais recente, efetua download do instalador e o executa com encerramento gracioso do aplicativo principal.

---

## 3. Gestão de Memória e Boas Práticas

- **Tratamento de Objetos JSON:** Todo objeto `TJSONObject` ou `TJSONArray` instanciado pelo parser é liberado em blocos `try ... finally`.
- **Conexões HTTP:** `TFPHTTPClient` é criado e destruído dinamicamente a cada requisição ou reutilizado com cabeçalhos limpos, evitando vazamento de descritores de sockets.
- **Isolamento de Threads:** Chamadas de longa duração (download de atualizações) utilizam threads auxiliares (`TThread`) para manter a interface LCL responsiva.
