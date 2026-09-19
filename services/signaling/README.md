# Signaling WebSocket

Serviço PHP CLI responsável por eventos em tempo real, presença, chat e sinalização WebRTC.

## Tecnologia

- PHP 8.1+
- Ratchet `cboden/ratchet:^0.4.4`
- PDO MySQL
- WebSocket RFC 6455

A mídia de áudio/vídeo **não passa por este serviço**. Ele transporta apenas controle e eventos; a mídia continua no WebRTC P2P/TURN.

## Instalação

```bash
cd services/signaling
composer install --no-dev --optimize-autoloader
php server.php
```

O serviço lê a mesma configuração de `apps/web/config.php`.

## Configuração

Exemplo:

```php
'websocket' => [
    'enabled' => true,
    'public_url' => 'wss://meet.seu-dominio.example/ws',
    'http_host' => 'meet.seu-dominio.example',
    'listen_host' => '127.0.0.1',
    'listen_port' => 8088,
    'path' => '/ws',
    'reconnect_ms' => 2000,
],
```

- `public_url`: URL usada pelo navegador.
- `http_host`: Host HTTP esperado pelo Ratchet.
- `listen_host`: interface local de bind.
- `listen_port`: porta interna.
- `path`: rota WebSocket.

Em produção, o navegador deve acessar `wss://` através do proxy HTTPS.

## Protocolo

Cliente -> servidor:

```json
{"type":"signal","signalType":"offer","recipient":"PARTICIPANT_KEY","payload":{}}
{"type":"chat","message":"Olá"}
{"type":"presence","mic":true,"cam":true,"screen":false}
{"type":"ping"}
```

Servidor -> cliente:

```json
{"type":"hello","self":"PARTICIPANT_KEY","roomId":1}
{"type":"signal","message":{}}
{"type":"chat","message":{}}
{"type":"presence","participants":[]}
{"type":"session-ended","reason":"authorization_or_room_closed"}
{"type":"pong"}
```

## Persistência

O WebSocket grava:
- `signaling_messages`;
- `room_messages`;
- `room_presence`;
- `room_attendance`.

## Fallback

Se o WebSocket cair, `room.php` retoma automaticamente:
- `signal_poll.php`;
- `chat_poll.php`;
- `presence.php`.

Quando o WebSocket reconecta, o polling para novamente.

## Serviço

Há um exemplo de systemd em:

`deployment/systemd/salareuniao-signaling.service.example`

E exemplos de proxy em:
- `deployment/nginx/websocket.conf.example`;
- `deployment/apache/websocket.conf.example`.
