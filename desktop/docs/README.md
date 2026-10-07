# Sala Reunião · Cliente Desktop (Lazarus / Free Pascal)

Aplicativo desktop nativo desenvolvido em **Lazarus / Free Pascal (FPC 3.2.2+)** para o ecossistema **Sala Reunião**, integrando-se diretamente com o backend Web e com o servidor de distribuição **`bcastd`** (`linuxsrv/broadcast`).

O aplicativo oferece uma experiência completa no padrão corporativo (estilo **Microsoft Teams**):
- Grid de participantes com destaque automático do orador ativo.
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
│   ├── README.md                 # Visão geral e guia de início rápido
│   ├── ARCHITECTURE.md           # Arquitetura interna, camadas e ciclo de vida
│   ├── API_INTEGRATION.md        # Especificação técnica das APIs REST (/api/v1/)
│   ├── BROADCAST_INTEGRATION.md  # Protocolo do bcastd e interface estilo Teams
│   └── CEF_INTEGRATION.md        # Guia de configuração e uso do CEF4Delphi
├── libs/         # Bibliotecas, pacotes locais ou dependências de terceiros
└── src/          # Código-fonte da aplicação (.lpr, .lpi, .pas, .lfm, .res)
```

---

## Recursos Implementados no Cliente Desktop

1. **Autenticação Segura:**
   - Login por e-mail e senha corporativos via `/api/v1/auth/login.php`.
   - Emissão de Token Bearer com persistência local segura de sessão.
   - Suporte a perfil de administrador e usuário padrão.

2. **Gestão de Salas e Videoconferências:**
   - Listagem em tempo real de salas ativas, agendadas e encerradas.
   - Criação de novas salas com agendamento prévio ou início imediato.
   - Abertura, encerramento e cancelamento de salas pelo anfitrião.
   - Geração automática da identidade e token individual de host.

3. **Integração com o Servidor Broadcast (`bcastd`):**
   - Conexão direta via WebSocket (`wss://.../broadcast`).
   - Sincronização em tempo real de participantes, orador e mensagens.
   - Gestão de fila de mãos levantadas (FIFO).
   - Sala de espera (lobby) com admissão e recusa em tempo real.

4. **Interface Gráfica Estilo Microsoft Teams:**
   - Grade dinâmica de vídeos/avatares dos participantes.
   - Painel lateral retrátil com Lista de Participantes, Fila de Fala e Chat.
   - Barra de controle superior com temporizador e botões de ação rápida.

5. **Gestão de Convites por E-mail:**
   - Geração de tokens únicos e links de acesso direto.
   - Layout de convite personalizado com resumo da reunião e inclusão na agenda.

6. **Agenda e Notificações:**
   - Acompanhamento das próximas reuniões agendadas.
   - Alertas nativos do Windows (bandeja / toast) quando uma reunião estiver próxima do início.
   - Opção de auto-entrada configurável.

7. **Videoconferência Integrada:**
   - Modo embutido via Chromium Embedded Framework (**CEF4Delphi**), mantendo todo o fluxo de áudio, vídeo, WebRTC, chat e compartilhamento de tela na própria janela nativa.
   - Fallback automático para o navegador padrão caso os binários do CEF não estejam presentes.

8. **Atualizador Automático (Auto-Update):**
   - Verificação automática de novas versões pelo endpoint `/api/v1/desktop_update.php`.
   - Download assistido e aplicação do pacote de instalação.

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
