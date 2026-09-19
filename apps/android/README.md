# Sala Reunião Android

Aplicativo Android da plataforma Sala Reunião.

## Objetivo

O app usa a mesma API REST v1 do Desktop e a mesma sala WebRTC da versão Web.

Arquitetura:

```text
Android
  │
  ├── API REST v1
  │     ├── login
  │     ├── salas
  │     ├── agenda
  │     └── controle de reunião
  │
  └── WebView
        └── room.php / join.php
              ├── WebRTC
              ├── WebSocket
              ├── TURN
              ├── câmera
              ├── microfone
              └── chat
```

## Recursos implementados

- login com usuário/senha;
- Bearer token da API;
- token protegido por Android Keystore;
- configuração da URL da API;
- configuração da URL Web;
- lista de salas do usuário;
- agenda;
- criação de sala;
- abrir reunião;
- encerrar;
- cancelar;
- entrar como anfitrião;
- entrar por link/token de convite;
- videoconferência dentro do app;
- WebRTC via Android WebView;
- câmera;
- microfone;
- fullscreen da página;
- abertura externa de links que saem do domínio configurado;
- somente HTTPS;
- câmera/microfone liberados somente para o host configurado.

## Estrutura

```text
apps/android/
├── build.gradle.kts
├── settings.gradle.kts
├── gradle.properties
├── gradle/
│   └── wrapper/
│       └── gradle-wrapper.properties
├── app/
│   ├── build.gradle.kts
│   ├── proguard-rules.pro
│   └── src/main/
│       ├── AndroidManifest.xml
│       ├── java/br/com/maurinsoft/salareuniao/
│       │   ├── ApiClient.kt
│       │   ├── LoginActivity.kt
│       │   ├── MainActivity.kt
│       │   ├── MeetingActivity.kt
│       │   ├── Models.kt
│       │   ├── SecureTokenStore.kt
│       │   └── SessionStore.kt
│       └── res/
│           ├── layout/
│           └── values/
└── README.md
```

## Requisitos

Projeto configurado para:

- Android Gradle Plugin 9.4.0
- Gradle 9.6
- compileSdk 37
- targetSdk 37
- minSdk 26
- JDK 17
- Kotlin integrado do AGP 9

## Abrir no Android Studio

Abra a pasta:

```text
apps/android
```

O Android Studio fará o sync do Gradle.

Se o projeto não tiver o binário do Gradle Wrapper no clone, use o Gradle instalado pelo Android Studio ou execute uma vez:

```bash
gradle wrapper --gradle-version 9.6.0
```

## Configuração inicial

Na tela de login informe, por exemplo:

```text
API
https://meet.exemplo.com/api/v1

Web
https://meet.exemplo.com
```

Se a aplicação estiver em subpasta:

```text
API
https://meet.exemplo.com/salareuniao/apps/web/api/v1

Web
https://meet.exemplo.com/salareuniao/apps/web
```

O app recusa URL HTTP. Use certificado HTTPS válido.

## Login

O aplicativo chama:

```text
POST /api/v1/auth/login.php
```

com:

```json
{
  "email": "usuario@exemplo.com",
  "password": "senha",
  "client_name": "Android"
}
```

O token retornado é criptografado por uma chave AES/GCM criada no Android Keystore.

## Videoconferência

Ao entrar como anfitrião:

1. o app abre a sala pela API se ainda estiver agendada;
2. consulta `host_join_token`;
3. monta `room.php?token=...`;
4. carrega a sala em `MeetingActivity`;
5. a WebView executa o WebRTC já existente.

## Convites

Na tela inicial existe **Abrir convite**.

É possível colar:

- o URL completo recebido por e-mail; ou
- somente o token.

Nesse caso o app abre `join.php?token=...`, mantendo o fluxo de nome, sala de espera, autorização e entrada que já existe no Web.

## Câmera e microfone

O Manifest solicita apenas:

```text
INTERNET
CAMERA
RECORD_AUDIO
MODIFY_AUDIO_SETTINGS
```

Não há solicitação de armazenamento, contatos, localização ou outras permissões desnecessárias.

Quando a página WebRTC solicita mídia, o app:

1. verifica se a origem é exatamente o host HTTPS configurado;
2. solicita a permissão Android correspondente;
3. libera somente `VIDEO_CAPTURE` e/ou `AUDIO_CAPTURE`;
4. rejeita outras permissões Web.

## Segurança

- cleartext HTTP desativado;
- token API protegido pelo Android Keystore;
- domínio da WebView restrito ao host configurado;
- links externos abrem fora da WebView;
- acesso a arquivos locais da WebView desativado;
- conteúdo misto HTTP/HTTPS bloqueado;
- senha do usuário não é persistida.

## Build

No Android Studio:

```text
Build > Make Project
```

APK debug:

```bash
gradle :app:assembleDebug
```

Saída típica:

```text
app/build/outputs/apk/debug/app-debug.apk
```

## Observações

Câmera e microfone são suportados pela integração WebRTC/WebView.

Compartilhamento de tela depende das capacidades do Android System WebView e do fluxo WebRTC da página. Para uma implementação Android totalmente nativa de compartilhamento de tela seria necessário integrar MediaProjection ao pipeline de mídia do cliente.

## Próximas evoluções

- foreground service durante chamada;
- seleção de câmera frontal/traseira pelo app;
- integração com Android Telecom;
- chat nativo opcional.


## Recursos adicionados na versão atual

### Lembretes

A agenda obtida da API gera trabalhos únicos no WorkManager, normalmente 10 minutos antes do início da reunião.

No Android 13+ o app solicita `POST_NOTIFICATIONS` em tempo de execução.

### Gestão de convidados

Selecione uma sala e toque em **Convidados**.

O app permite:
- listar convidados;
- adicionar e-mails;
- autorizar;
- recusar;
- reenviar convite;
- remover participante.

### Atualização do APK

O app verifica `/api/v1/android_update.php` ao atualizar a tela e também em segundo plano aproximadamente a cada 12 horas.

Quando uma versão nova existe:
- mostra notificação;
- apresenta notas da versão;
- pode baixar o APK;
- abre o instalador Android.

Instalação silenciosa não é usada: o Android mantém a confirmação do usuário.

### Deep links e App Links

São suportados:
- `salareuniao://join?token=...`;
- links HTTPS verificados do domínio configurado.

Consulte `deployment/android/README.md`.


## CI GitHub Actions

Foi criado:

```text
.github/workflows/android-build.yml
```

O workflow compila:

```text
:app:assembleDebug
:app:assembleRelease
```

e publica os artefatos:

```text
SalaReuniaoAndroid-debug
SalaReuniaoAndroid-release
```

### Host do App Link

Crie a variável do repositório:

```text
ANDROID_APP_HOST=meet.seu-dominio.example
```

Em:

```text
Settings > Secrets and variables > Actions > Variables
```

### Assinatura do release

Para gerar release assinado, cadastre estes secrets em:

```text
Settings > Secrets and variables > Actions > Secrets
```

```text
ANDROID_KEYSTORE_BASE64
ANDROID_KEYSTORE_PASSWORD
ANDROID_KEY_ALIAS
ANDROID_KEY_PASSWORD
```

Converta o keystore para Base64 antes de cadastrar.

Linux:

```bash
base64 -w 0 salareuniao-release.keystore
```

PowerShell:

```powershell
[Convert]::ToBase64String(
  [IO.File]::ReadAllBytes("salareuniao-release.keystore")
)
```

Sem esses secrets, o workflow continua compilando o APK debug e o release não assinado.

### Executar

O workflow pode ser executado manualmente em:

```text
GitHub > Actions > Android Build > Run workflow
```

ou por um push que altere `apps/android/**`.

Commits feitos por automações/integradores podem não iniciar outro workflow automaticamente, para evitar loops. Nesse caso use **Run workflow** ou faça um push normal.

### APKs

Após sucesso:

```text
Actions > Android Build > execução > Artifacts
```

Baixe:

```text
SalaReuniaoAndroid-debug
SalaReuniaoAndroid-release
```
