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
