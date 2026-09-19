# Sala Reunião Desktop — Lazarus / Free Pascal

Cliente Desktop da plataforma Sala Reunião.

## Tecnologia

- Lazarus / LCL
- Free Pascal
- `TFPHTTPClient`
- JSON nativo do FPC (`fpjson` / `jsonparser`)
- OpenSSL para HTTPS
- API REST v1 do Sala Reunião

A videoconferência Web/WebRTC já pronta pode ser aberta **dentro do próprio Desktop** quando o projeto é compilado com CEF4Delphi. Sem CEF, o cliente mantém fallback para o navegador externo usando o mesmo `host_join_token`.

## Recursos implementados

- login pela API REST;
- Bearer token em memória;
- configuração de URL da API;
- configuração da URL Web;
- e-mail do último usuário salvo localmente;
- lista de salas;
- agenda dos próximos 30 dias;
- criação de sala;
- abrir sala;
- encerrar sala;
- cancelar reunião;
- abrir videoconferência em janela interna;
- modo CEF4Delphi/Chromium opcional;
- fallback para navegador externo;
- lista de convidados;
- adicionar convidados;
- autorizar;
- recusar;
- reenviar convite;
- remover participante.

## Arquivos

```text
apps/desktop/
├── SalaReuniaoDesktop.lpi
├── SalaReuniaoDesktop.lpr
├── uMain.pas
├── uApiClient.pas
├── uAppConfig.pas
├── uInvites.pas
├── uMeetingView.pas
├── SalaReuniaoDesktopCEF.lpi
├── CEF_SETUP.md
└── README.md
```

## Abrindo no Lazarus

Abra:

```text
SalaReuniaoDesktop.lpi
```

O formulário é criado inteiramente em código; não há dependência de arquivos `.lfm`.

## Dependências FPC/Lazarus

São utilizados pacotes/unidades padrão:

- LCL
- FCL-Web / `fphttpclient`
- FCL-JSON
- `opensslsockets`
- `IniFiles`

Em Linux, instale também as bibliotecas OpenSSL do sistema.

## Compilação

Pelo Lazarus:

```text
Run > Build
```

Ou pela linha de comando:

```bash
lazbuild SalaReuniaoDesktop.lpi
```

O executável é configurado para:

```text
bin/SalaReuniaoDesktop
```

No Windows será gerado `.exe`.

## Configuração inicial

Na tela de login clique em **Servidor...**.

Exemplo se `apps/web` for a raiz do virtual host:

```text
API:
https://meet.exemplo.com/api/v1

Web:
https://meet.exemplo.com
```

Se a aplicação PHP estiver publicada dentro de uma subpasta:

```text
API:
https://meet.exemplo.com/salareuniao/apps/web/api/v1

Web:
https://meet.exemplo.com/salareuniao/apps/web
```

A configuração é gravada no diretório de configuração do usuário usando `GetAppConfigDir(False)`.

A senha **não é salva**.

## Login

O Desktop chama:

```text
POST /api/v1/auth/login.php
```

Depois usa:

```text
Authorization: Bearer USER_TOKEN
```

O token fica somente em memória durante a execução desta versão.

Ao sair, o Desktop chama `auth/logout.php`, revogando o token no servidor.

## Entrada na videoconferência

O Desktop consulta:

```text
GET /api/v1/room.php?id=<ID>
```

e recebe `host_join_token`.

Depois monta:

```text
<WEB_BASE_URL>/room.php?token=<HOST_JOIN_TOKEN>
```

No projeto `SalaReuniaoDesktopCEF.lpi`, essa URL é carregada em uma janela `TChromiumWindow` dentro da própria aplicação. No projeto padrão, a mesma janela oferece fallback para o navegador externo.

Isso permite aproveitar imediatamente:
- câmera;
- microfone;
- WebRTC;
- TURN;
- WebSocket;
- chat;
- compartilhamento de tela.

## CEF4Delphi

Para usar o navegador Chromium embutido, abra:

```text
SalaReuniaoDesktopCEF.lpi
```

Consulte `CEF_SETUP.md` para instalação do pacote Lazarus e dos binários CEF.

## Próxima evolução Desktop

A base agora permite evoluir gradualmente para:
- notificações nativas;
- seleção nativa de dispositivos de áudio/vídeo;
- WebSocket nativo;
- atualização automática;
- integração com o ESP32 da sala;
- abertura automática de reunião agendada.

## Validação

O código foi revisado estaticamente nesta implementação. O ambiente de desenvolvimento usado para esta alteração não possuía `fpc` ou `lazbuild`, portanto a compilação executável deve ser validada em uma instalação Lazarus/Free Pascal.
