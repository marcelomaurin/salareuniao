# Convites por E-mail, Tokens e Abertura Automática (Deep Linking) · Sala Reunião Desktop

Este documento especifica o fluxo completo de **convites por e-mail com token individual**, a estrutura dos **3 links de acesso no e-mail** e a **abertura automática da aplicação Desktop diretamente na sala**.

---

## 1. Visão Geral do Fluxo

```text
[ ADMINISTRADOR (APLICAÇÃO DESKTOP) ]
                   │
                   ▼ Dispara convite via interface de envio
[ SERVIDOR / BACKEND ]
                   │
                   ▼ Dispara e-mail em HTML corporativo
┌────────────────────────────────────────────────────────┐
│             CONVITE RECEBIDO POR E-MAIL                │
│                                                        │
│  [ OPÇÃO 1 - PRINCIPAL: ENTRAR NO APLICATIVO ]         │
│  • Link "Clique aqui para entrar na reunião"           │
│  • Dispara protocolo: salareuniao://join?token=<TOKEN> │
│  • Abre o Desktop e entra automaticamente na sala      │
│                                                        │
│  [ OPÇÃO 2 - DOWNLOAD: BAIXAR O PROGRAMA ]             │
│  • Link "Baixar Aplicativo Desktop"                    │
│  • Baixa o instalador caso o usuário ainda não possua  │
│                                                        │
│  [ OPÇÃO 3 - ACESSO WEB: NAVEGADOR ]                   │
│  • Link "Acessar versão Web pelo Navegador"            │
│  • Abre broadcast.php?token=<TOKEN> sem instalar nada  │
└────────────────────────────────────────────────────────┘
```

---

## 2. Estrutura do E-mail Enviado aos Convidados

O e-mail gerado pelo sistema é construído em HTML moderno e responsivo, contendo os três caminhos de entrada:

### 2.1. Template do E-mail (Os 3 Links Obrigatórios):

```html
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: auto; padding: 24px; border: 1px solid #e1e4e8; border-radius: 8px;">
  <div style="text-align: center; margin-bottom: 20px;">
    <h2 style="color: #2b579a; margin: 0;">Sala Reunião Corporativa</h2>
    <p style="color: #666; font-size: 14px;">Você foi convidado para uma videoconferência</p>
  </div>

  <div style="background: #f8f9fa; padding: 16px; border-radius: 6px; margin-bottom: 24px;">
    <h3 style="margin-top: 0; color: #333;">{NOME_DA_REUNIAO}</h3>
    <p style="margin: 4px 0; color: #555;"><strong>Organizador:</strong> {ORGANIZADOR}</p>
    <p style="margin: 4px 0; color: #555;"><strong>Data e Horário:</strong> {DATA_HORA}</p>
  </div>

  <!-- OPÇÃO 1: BOTÃO PRINCIPAL DE ABERTURA NO DESKTOP -->
  <div style="text-align: center; margin-bottom: 24px;">
    <a href="salareuniao://join?token={TOKEN}&server={SERVER_URL}" 
       style="background: #2b579a; color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px; display: inline-block;">
      Clique Aqui para Entrar na Reunião (Aplicativo)
    </a>
    <p style="font-size: 12px; color: #777; margin-top: 8px;">
      Ao clicar, o aplicativo Sala Reunião será aberto automaticamente já conectado à sala.
    </p>
  </div>

  <hr style="border: 0; border-top: 1px solid #eee; margin: 24px 0;" />

  <!-- OPÇÃO 2 & 3: DOWNLOAD OU NAVEGADOR WEB -->
  <div style="display: flex; justify-content: space-between; font-size: 13px; color: #444;">
    <div style="width: 48%;">
      <p style="margin: 0 0 6px 0;"><strong>Ainda não tem o aplicativo?</strong></p>
      <a href="{SERVER_URL}/downloads/SalaReuniaoSetup.exe" style="color: #2b579a; text-decoration: underline;">
        Baixar Aplicativo Desktop para Windows
      </a>
    </div>
    <div style="width: 48%; text-align: right;">
      <p style="margin: 0 0 6px 0;"><strong>Prefere usar pelo navegador?</strong></p>
      <a href="{SERVER_URL}/broadcast.php?token={TOKEN}" style="color: #2b579a; text-decoration: underline;">
        Acessar Versão Web no Navegador
      </a>
    </div>
  </div>
</div>
```

---

## 3. Comportamento ao Clicar em "Entrar na Reunião"

1. **Invocação do Protocolo `salareuniao://`:**
   - O navegador ou leitor de e-mail (Outlook, Thunderbird, Gmail, etc.) reconhece o protocolo customizado e solicita permissão para abrir `SalaReuniaoDesktop.exe`.
2. **Execução com Parâmetro:**
   - O Windows executa:
     ```bash
     "C:\...\SalaReuniaoDesktop.exe" "salareuniao://join?token=a1b2c3d4e5f6...&server=https://servidor/salareuniao"
     ```
3. **Reconhecimento do Token pelo Aplicativo Lazarus:**
   - Na inicialização (`.lpr`), a rotina `ProcessStartupParameters` isola o token:
     ```pascal
     Token := ExtractTokenFromParam(ParamStr(1));
     ```
   - O aplicativo se conecta ao servidor broadcast com o token de convite.
   - Se for um usuário comum, é posicionado na **Sala de Espera (Lobby)** com mensagem amigável, notificando o administrador para admissão.
4. **Instância Única (Single Instance via IPC):**
   - Caso o executável já esteja aberto no computador, o token é transferido via IPC (`TSimpleIPCClient -> TSimpleIPCServer`).
   - A janela existente é restaurada, ganha foco no topo da tela (`SetForegroundWindow`) e conecta imediatamente à sala.

---

## 4. Formulário de Envio no Desktop (`TInviteDialog`)

Na aplicação Lazarus, o Administrador conta com um diálogo nativo para chamar convidados:

```pascal
type
  TInviteDialog = class(TForm)
    EdGuestName: TEdit;
    EdGuestEmail: TEdit;
    ChkSendEmailNow: TCheckBox;
    BtnSendInvite: TButton;
    BtnCopyLink: TButton;
  end;
```

- **Ação do Botão `Enviar Convite`:**
  - Aciona `POST /api/v1/room_invites.php`.
  - O servidor gera o `token` criptográfico individual de 32 bytes (64 caracteres hexadecimais).
  - Monta o e-mail com as 3 opções (Link direto `salareuniao://`, Download do instalador e Link Web `broadcast.php`).
  - Dispara o e-mail via serviço de mensageria corporativo.
