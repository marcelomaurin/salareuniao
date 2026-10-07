# Sala Reunião · Cliente Desktop (Lazarus / Free Pascal)

Aplicativo desktop nativo desenvolvido em **Lazarus / Free Pascal (FPC 3.2.2+)** para o ecossistema **Sala Reunião**, integrando-se diretamente com o backend Web e com o servidor de distribuição **`bcastd`** (`linuxsrv/broadcast`).

O aplicativo oferece uma experiência completa no padrão corporativo (estilo **Microsoft Teams**):
- **Sala de Espera (Lobby Automático):** Usuários normais entram automaticamente na sala de espera aguardando autorização; o administrador recebe alertas e admite ou recusa com um clique.
- **Abertura Automática por E-mail (Deep Linking):** Os convidados recebem e-mails com tokens únicos de acesso; ao clicar no link, o aplicativo desktop abre automaticamente já conectado à sala.
- **Autoridade Administrativa Estrita:** Apenas administradores e anfitriões podem gerar tokens, convidar pessoas e moderar a sala.
- **Flexibilidade total de Layout:** Clique em qualquer participante para destacá-lo no palco principal (Spotlight/Pin), redimensionando os demais automaticamente para miniaturas na galeria lateral.
- **Menu Views Completo:** Modos Grade (*Gallery*), Orador (*Speaker*), Destaque (*Spotlight*) e Galeria no Topo/Lateral.
- Barra superior de controles rápidos (Timer, Câmera, Microfone, Tela, Levantar a Mão, Chat e Sair).
- Painel lateral retrátil com Sala de Espera (Lobby), Fila de Fala (FIFO), Chat e Envio de Convites por e-mail.
- Comunicação WebSocket com o servidor **`bcastd`** (`linuxsrv/broadcast`).
- Videoconferência integrada via **Chromium (CEF4Delphi)** ou através do navegador padrão.

---

## Estrutura do Diretório `desktop/`

```text
desktop/
├── bin/          # Executáveis finais (.exe), bibliotecas auxiliares e runtime Chromium
├── docs/         # Documentação arquitetural, guias e especificação de APIs
│   ├── README.md                      # Visão geral e guia de início rápido
│   ├── ARCHITECTURE.md                # Arquitetura interna, camadas e ciclo de vida
│   ├── API_INTEGRATION.md             # Especificação técnica das APIs REST (/api/v1/)
│   ├── BROADCAST_INTEGRATION.md       # Protocolo do bcastd e interface estilo Teams
│   ├── LAYOUT_AND_INTERACTION.md      # Redimensionamento dinâmico e menu Views
│   ├── INVITATION_AND_DEEP_LINKING.md # Convites por e-mail, tokens e protocolo salareuniao://
│   ├── LOBBY_AND_WAITING_ROOM.md      # Sala de espera (lobby) e fluxo de admissão
│   └── CEF_INTEGRATION.md             # Guia de configuração e uso do CEF4Delphi
├── libs/         # Bibliotecas, pacotes locais ou dependências de terceiros
└── src/          # Código-fonte da aplicação (.lpr, .lpi, .pas, .lfm, .res)
```

---

## Recursos Implementados no Cliente Desktop

1. **Sala de Espera Obrigatória para Usuários Normais (Lobby):**
   - Usuários normais permanecem em tela de espera (*"Aguarde a liberação do organizador"*).
   - O administrador recebe notificações em tempo real (`lobby.join`) com botões para Admitir, Recusar ou Admitir Todos.
   - Transição automática e suave para a videoconferência assim que admitido.

2. **Abertura Automática por Link de E-mail (Deep Linking):**
   - Suporte ao protocolo nativo `salareuniao://join?token=<TOKEN>` e parâmetros de linha de comando.
   - Detecção de instância única (Single Instance via IPC): se a aplicação já estiver aberta, ativa e carrega a sala imediatamente.

3. **Controle Estrito de Permissões (Exclusividade do Administrador):**
   - Somente administradores podem criar tokens, emitir links e chamar novas pessoas.

4. **Seleção e Redimensionamento Dinâmico de Janelas:**
   - Liberdade para escolher quem fica em destaque amplo na tela através de clique na pessoa.
   - Reorganização automática dos demais participantes em miniaturas pequenas na galeria lateral (2 colunas + coluna de avatares circulares).
   - Menu **Views** com alternância rápida entre Grade, Orador, Destaque e Galeria Lateral/Topo.

5. **Autenticação Segura de Anfitriões:**
   - Login corporativo via `/api/v1/auth/login.php` com Bearer Token persistido.

6. **Gestão de Salas e Videoconferências:**
   - Listagem em tempo real de salas ativas, agendadas e encerradas.
   - Criação de novas salas com agendamento prévio ou início imediato.
   - Abertura, encerramento e cancelamento de salas pelo anfitrião.

7. **Integração com o Servidor Broadcast (`bcastd`):**
   - Conexão direta via WebSocket (`wss://.../broadcast`).
   - Sincronização em tempo real de participantes, orador e mensagens.
   - Gestão de fila de mãos levantadas (FIFO).

8. **Interface Gráfica Estilo Microsoft Teams:**
   - Palco amplo de orador ou tela compartilhada.
   - Galeria lateral com miniaturas e avatares com iniciais coloridas.
   - Painel lateral retrátil com Lista de Participantes, Fila de Fala e Chat.
   - Barra de controle superior com temporizador e botões de ação rápida.

9. **Agenda e Notificações:**
   - Acompanhamento das próximas reuniões agendadas com alertas nativos do Windows.

10. **Videoconferência Integrada:**
   - Suporte a Chromium Embedded Framework (**CEF4Delphi**) e fallback para navegador padrão.

---

## Requisitos de Desenvolvimento

- **Lazarus:** 3.0 ou superior (32-bit ou 64-bit).
- **Free Pascal Compiler (FPC):** 3.2.2 ou superior.
- **Bibliotecas LCL e FCL:**
  - `fphttpclient` (com suporte a OpenSSL para conexões HTTPS).
  - `fpjson` e `jsonparser` para manipulação de payloads JSON.
- **Bibliotecas Opcionais:**
  - `CEF4Delphi` (necessário caso deseje compilar com Chromium embutido `USE_CEF4DELPHI`).

---

## Compilação Rápida

Para compilar via linha de comando (`lazbuild`):

```bash
# Modo padrão (sem dependência do CEF):
lazbuild src/SalaReuniaoDesktop.lpi

# Modo com Chromium embutido (CEF4Delphi):
lazbuild src/SalaReuniaoDesktopCEF.lpi
```

O executável compilado será gerado automaticamente em `desktop/bin/`.
