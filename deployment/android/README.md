# Android — App Links e distribuição

## Host do aplicativo

Antes do build de produção, ajuste em:

```text
apps/android/app/build.gradle.kts
```

os dois valores:

```kotlin
manifestPlaceholders["appHost"] = "meet.seu-dominio.example"
buildConfigField("String", "APP_HOST", "\"meet.seu-dominio.example\"")
```

Use somente o hostname, sem `https://` e sem caminho.

## Android App Links

O Manifest declara links HTTPS verificáveis para convites `join.php`.

Para a verificação funcionar, publique no domínio:

```text
https://meet.seu-dominio.example/.well-known/assetlinks.json
```

Use `deployment/android/assetlinks.json.example` como base.

Obtenha o SHA-256 do certificado usado para assinar o APK/AAB de produção. Exemplo:

```bash
keytool -list -v -keystore release.keystore -alias salareuniao
```

Copie o fingerprint SHA-256 para `sha256_cert_fingerprints`.

O arquivo precisa ser servido:
- por HTTPS válido;
- sem redirecionar para login;
- como JSON;
- no caminho exato `/.well-known/assetlinks.json`.

Depois disso, links como:

```text
https://meet.seu-dominio.example/join.php?token=...
```

podem abrir diretamente no app.

Se a aplicação Web estiver em subpasta, o filtro usa `pathPattern` e aceita caminhos que terminam em `join.php`.

## Deep link alternativo

Também existe:

```text
salareuniao://join?token=TOKEN
```

Esse formato não depende de verificação de domínio, mas o link HTTPS verificado é preferível.

## Atualização de APK

O app consulta:

```text
GET /api/v1/android_update.php
```

Configure no servidor:

```php
'android_update' => [
    'enabled' => true,
    'version_code' => 2,
    'version' => '1.1.0',
    'required' => false,
    'notes' => 'Correções e melhorias.',
    'apk_url' => 'https://meet.exemplo.com/downloads/SalaReuniaoAndroid-1.1.0.apk',
],
```

O `version_code` precisa ser maior que o `versionCode` instalado.

### Distribuição privada / sideload

O app pode:
1. detectar a nova versão;
2. baixar o APK;
3. abrir o instalador do Android.

O Android exige autorização do usuário para a instalação. O app direciona para **Instalar apps desconhecidos** quando necessário.

### Google Play

Se o aplicativo for distribuído pela Play Store, remova o fluxo de sideload/`REQUEST_INSTALL_PACKAGES` e deixe a Play Store gerenciar atualizações.

## Lembretes

O app usa WorkManager para programar lembretes aproximadamente 10 minutos antes das reuniões carregadas pela agenda.

No Android 13+, o usuário precisa permitir notificações.

## Verificação por ADB

Depois de instalar a versão assinada:

```bash
adb shell pm verify-app-links --re-verify br.com.maurinsoft.salareuniao
adb shell pm get-app-links br.com.maurinsoft.salareuniao
```

Teste um convite:

```bash
adb shell am start -W -a android.intent.action.VIEW -d "https://meet.seu-dominio.example/join.php?token=TESTE"
```
