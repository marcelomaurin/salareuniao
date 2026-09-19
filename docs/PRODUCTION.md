# Produção: HTTPS + Coturn

## Objetivo

Permitir que reuniões funcionem entre redes diferentes, inclusive quando o P2P direto falhar por NAT, CGNAT ou firewall.

## DNS

Crie dois nomes, por exemplo:

- `meet.seu-dominio.example` -> servidor PHP/HTTPS
- `turn.seu-dominio.example` -> servidor Coturn

Podem apontar para a mesma máquina, desde que as portas estejam disponíveis.

## Portas

No firewall do servidor TURN, libere:

- TCP/UDP 3478
- TCP 5349
- UDP 49160-49260

Se alterar a faixa `min-port/max-port`, ajuste o firewall.

## Coturn

1. Instale Coturn.
2. Copie `deployment/coturn/turnserver.conf.example` para `/etc/turnserver.conf`.
3. Gere um segredo forte:

```bash
openssl rand -hex 32
```

4. Coloque o mesmo segredo em:
   - `static-auth-secret` do Coturn;
   - `webrtc.turn.secret` do `apps/web/config.php`.
5. Configure certificado TLS para `turn.seu-dominio.example`.
6. Reinicie o Coturn.

O projeto usa credenciais temporárias no formato de autenticação REST do Coturn. O segredo nunca é enviado ao navegador.

## HTTPS

O cliente Web deve ser servido em HTTPS. Configure Apache/Nginx com certificado válido.

## Teste mínimo

Teste com:

1. computador A em uma rede;
2. computador B em outra rede, de preferência 4G/5G ou outro provedor;
3. abrir a mesma sala;
4. confirmar áudio e vídeo;
5. abrir o painel de diagnóstico na reunião;
6. verificar o tipo do candidato selecionado.

Interpretação:

- `host` / `srflx`: conexão direta P2P;
- `relay`: mídia passando pelo TURN.

## Produção

Depois do teste funcional:

- monitore uso de CPU, RAM e banda do Coturn;
- monitore quantidade de allocations;
- dimensione a faixa de portas;
- use limites de banda/quota adequados;
- automatize renovação do certificado;
- mantenha `config.php` fora do Git.


## WebSocket

### Dependências

```bash
cd services/signaling
composer install --no-dev --optimize-autoloader
```

O pacote Ratchet recomendado pelo próprio projeto é instalado por Composer.

### Nginx

Use `deployment/nginx/websocket.conf.example` dentro do virtual host HTTPS.

### Apache

Habilite os módulos necessários:

```bash
sudo a2enmod proxy proxy_http proxy_wstunnel rewrite
```

Use então `deployment/apache/websocket.conf.example`.

### systemd

Ajuste os caminhos do arquivo:

`deployment/systemd/salareuniao-signaling.service.example`

Depois:

```bash
sudo cp deployment/systemd/salareuniao-signaling.service.example /etc/systemd/system/salareuniao-signaling.service
sudo systemctl daemon-reload
sudo systemctl enable --now salareuniao-signaling
sudo systemctl status salareuniao-signaling
```

### Validação

No painel administrativo, abra **Diagnóstico**. O item WebSocket deve aparecer como OK.

Na reunião, o status muda para:

```text
Conectado em tempo real
```

Se o serviço cair:

```text
WebSocket desconectado; usando fallback
```

e o sistema continua operando via polling.


## SMTP / PHPMailer

Instale as dependências do cliente Web:

```bash
cd apps/web
composer install --no-dev --optimize-autoloader
```

Configure em `apps/web/config.php`:

```php
'mail' => [
    'driver' => 'smtp',
    'host' => 'smtp.seu-dominio.example',
    'port' => 587,
    'auth' => true,
    'username' => 'salareuniao@seu-dominio.example',
    'password' => 'SENHA_SMTP',
    'encryption' => 'tls',
    'from' => 'salareuniao@seu-dominio.example',
    'from_name' => 'Sala Reunião',
    'reply_to' => 'salareuniao@seu-dominio.example',
    'fallback_mail' => false,
],
```

Não versione `config.php`. Após configurar, acesse **Administração > Diagnóstico** e use o botão de e-mail de teste.


## API REST

Configure os TTLs em `apps/web/config.php`:

```php
'api' => [
    'user_token_ttl' => 2592000,
    'device_token_ttl' => 0,
],
```

- `user_token_ttl`: validade dos tokens de Desktop em segundos.
- `device_token_ttl = 0`: token do dispositivo permanece válido até revogação/rotação.

Todos os endpoints externos devem ser publicados somente em HTTPS.

O administrador pode cadastrar e rotacionar tokens de dispositivos em:

```text
Administração > Dispositivos
```

O token bruto é exibido somente no momento da criação/rotação. Armazene-o no dispositivo de forma segura.


## Distribuição e atualização do Desktop

O Desktop consulta o manifesto autenticado:

```text
/api/v1/desktop_update.php
```

Configure em `apps/web/config.php`:

```php
'desktop_update' => [
    'enabled' => true,
    'version' => '1.1.0',
    'required' => false,
    'notes' => 'Correções e melhorias.',
    'windows_url' => 'https://meet.exemplo.com/downloads/SalaReuniaoDesktop-1.1.0-setup.exe',
    'linux_url' => 'https://meet.exemplo.com/downloads/SalaReuniaoDesktop-1.1.0.AppImage',
],
```

Fluxo recomendado de publicação:

1. atualize `APP_VERSION` em `apps/desktop/uVersion.pas`;
2. compile o Desktop;
3. gere instalador Windows e pacote/AppImage Linux;
4. publique os arquivos somente por HTTPS;
5. atualize `desktop_update.version` e as URLs;
6. mantenha `required=false` normalmente;
7. use `required=true` apenas quando uma versão antiga não puder continuar operando.

O cliente baixa o pacote e chama o mecanismo padrão do sistema operacional para iniciá-lo. O instalador deve cuidar da substituição do executável.


## OTA ESP32

Crie um diretório privado, fora da raiz pública do virtual host:

```bash
mkdir -p storage/firmware
chown -R www-data:www-data storage/firmware
chmod 770 storage/firmware
```

O PHP grava os binários em:

```text
<raiz-do-projeto>/storage/firmware
```

O arquivo não deve ser servido diretamente por Apache/Nginx. O download ocorre exclusivamente por:

```text
/api/v1/device/firmware_download.php
```

e exige o Bearer token do dispositivo.

### PHP upload

Garanta no `php.ini` limites compatíveis com o firmware, por exemplo:

```ini
upload_max_filesize = 16M
post_max_size = 18M
```

Depois reinicie PHP-FPM/Apache conforme seu ambiente.

### Publicação

1. atualize `FW_VERSION`;
2. compile o ESP32;
3. localize `firmware.bin`;
4. abra **Administração > Firmware**;
5. informe a versão e notas;
6. faça upload do `.bin`;
7. o site calcula SHA-256;
8. o release publicado torna-se o ativo;
9. dispositivos abaixo dessa versão passam a aparecer como desatualizados.

### Atualização por dispositivo

Abra:

```text
Administração > Dispositivos > Detalhes
```

Quando houver versão ativa superior, aparece o botão **Atualizar para X.Y.Z**.

O ESP32 baixa o binário por HTTPS, valida SHA-256 e só então grava a nova imagem.
