# Sala Reunião Desktop — Lazarus / Free Pascal

Cliente Desktop da plataforma Sala Reunião.

## Tecnologia

- Lazarus / LCL
- Free Pascal
- `TFPHTTPClient`
- JSON nativo do FPC (`fpjson` / `jsonparser`)
- OpenSSL para HTTPS
- API REST v1 do Sala Reunião

A primeira versão reutiliza a videoconferência Web/WebRTC já pronta: o Desktop administra a reunião nativamente e, ao clicar em **Entrar**, abre a sala no navegador usando o `host_join_token`.

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
- abrir videoconferência;
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

Depois abre:

```text
<WEB_BASE_URL>/room.php?token=<HOST_JOIN_TOKEN>
```

Isso permite aproveitar imediatamente:
- câmera;
- microfone;
- WebRTC;
- TURN;
- WebSocket;
- chat;
- compartilhamento de tela.

## Próxima evolução Desktop

A base agora permite evoluir gradualmente para:
- janela embutida com WebView/CEF;
- notificações nativas;
- seleção nativa de dispositivos de áudio/vídeo;
- WebSocket nativo;
- atualização automática;
- integração com o ESP32 da sala;
- abertura automática de reunião agendada.

## Validação

O código foi revisado estaticamente nesta implementação. O ambiente de desenvolvimento usado para esta alteração não possuía `fpc` ou `lazbuild`, portanto a compilação executável deve ser validada em uma instalação Lazarus/Free Pascal.
