# Convites por E-mail, Tokens e Abertura Automática (Deep Linking) · Sala Reunião Desktop

Este documento especifica o fluxo completo de **convites por e-mail com token individual** e **abertura automática da aplicação Desktop diretamente na sala**.

---

## 1. Visão Geral do Fluxo

```text
[ ADMINISTRAÇÃO ]
       │
       ▼ Emite convite com token individual via e-mail
[ SERVIDOR / SMTP ]
       │
       ▼ Envia e-mail elegante com resumo da reunião e link
[ PARTICIPANTE ]
       │
       ▼ Clica no link do e-mail ("Entrar na Reunião")
       ├────────────────────────────────────────┐
       ▼                                        ▼
Opção A: Protocolo Direto                Opção B: Página Web (join.php)
salareuniao://join?token=<TOKEN>         • Verifica navegador
       │                                 • Oferece "Abrir no Desktop"
       ▼                                 • Redireciona para salareuniao://...
[ APLICAÇÃO LAZARUS DESKTOP ]
       │
       ├─ Processa ParamStr(1) contendo o token
       ├─ Conecta à sala correspondente sem exigir credenciais prévias
       └─ Abre imediatamente a janela de videoconferência no modo reunião
```

---

## 2. Registro do Protocolo Customizado no Windows (`salareuniao://`)

Para que o Windows saiba abrir o executável `SalaReuniaoDesktop.exe` ao clicar no link do e-mail ou do navegador, o aplicativo registra o manipulador de protocolo no Registro do Windows (`HKCU\Software\Classes\salareuniao`):

```ini
[HKEY_CURRENT_USER\Software\Classes\salareuniao]
@="URL:Sala Reuniao Protocol"
"URL Protocol"=""

[HKEY_CURRENT_USER\Software\Classes\salareuniao\DefaultIcon]
@="\"C:\\Program Files\\Maurinsoft\\SalaReuniao\\SalaReuniaoDesktop.exe\",0"

[HKEY_CURRENT_USER\Software\Classes\salareuniao\shell\open\command]
@="\"C:\\Program Files\\Maurinsoft\\SalaReuniao\\SalaReuniaoDesktop.exe\" \"%1\""
```

O aplicativo também implementa auto-registro no primeiro início ou através de rotina nativa no Lazarus:
```pascal
procedure RegisterUrlProtocol(const ExePath: string);
var
  Reg: TRegistry;
begin
  Reg := TRegistry.Create;
  try
    Reg.RootKey := HKEY_CURRENT_USER;
    if Reg.OpenKey('Software\Classes\salareuniao', True) then
    begin
      Reg.WriteString('', 'URL:Sala Reuniao Protocol');
      Reg.WriteString('URL Protocol', '');
      if Reg.OpenKey('shell\open\command', True) then
        Reg.WriteString('', '"' + ExePath + '" "%1"');
    end;
  finally
    Reg.Free;
  end;
end;
```

---

## 3. Tratamento de Linha de Comando e Inicialização Automática (`.lpr` e `uMain.pas`)

Ao ser disparado pelo e-mail ou protocolo, o aplicativo recebe o parâmetro no `ParamStr(1)`:

### Formatos Reconhecidos:
1. `salareuniao://join?token=a1b2c3d4e5f6...&server=https://...`
2. `salareuniao:a1b2c3d4e5f6...`
3. `--token=a1b2c3d4e5f6...`
4. `a1b2c3d4e5f6...` (token hexadecimal direto)

### Lógica de Entrada Automática:
```pascal
procedure TMainForm.ProcessStartupParameters;
var
  RawParam, Token, ServerUrl: string;
begin
  if ParamCount < 1 then Exit;
  RawParam := ParamStr(1);
  Token := ExtractTokenFromParam(RawParam, ServerUrl);

  if Token <> '' then
  begin
    // Se o link especificar servidor alternativo, atualiza em runtime
    if ServerUrl <> '' then
      FConfig.WebBaseUrl := ServerUrl;

    // Dispara a entrada direta na reunião com o token recebido
    JoinMeetingWithToken(Token);
  end;
end;
```

---

## 4. Instância Única e Mensagens entre Processos (Single Instance via IPC)

Se a aplicação Desktop já estiver em execução (por exemplo, minimizada na bandeja do sistema), o clique no link do e-mail não deve abrir um segundo processo concorrente, mas sim ativar a instância existente:

1. O executável secundário detecta que já há uma instância ativa (`UniqueInstance` ou `TSimpleIPCClient`).
2. Envia a mensagem contendo o token para a instância primária via `TSimpleIPC`.
3. A instância primária:
   - Restaura a janela principal caso esteja minimizada.
   - Traz a aplicação para o primeiro plano (`SetForegroundWindow`).
   - Carrega imediatamente a sala da reunião associada ao token.
4. O processo secundário é encerrado instantaneamente.

---

## 5. Estrutura do E-mail Enviado aos Convidados

O e-mail disparado pelo backend (baseado no card da imagem de referência) contém:
- **Cabeçalho:** Identificação corporativa e avatar do anfitrião.
- **Saudação e Descrição:** *"Olá [Nome], você foi convidado para participar da reunião [Título]."*
- **Data e Horário:** Data formatada com botão para inclusão no calendário (`.ics`).
- **Botão Principal de Ação:**
  - Link duplo: abre a página oficial da sala e dispara a chamada do protocolo desktop `salareuniao://join?token=<TOKEN>`.
