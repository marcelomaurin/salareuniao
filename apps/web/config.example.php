<?php
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'salareuniao',
        'user' => 'salareuniao',
        'pass' => 'troque-a-senha',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'base_url' => 'https://seu-dominio.example/salareuniao/apps/web',
        'name' => 'Sala Reunião',
        'session_name' => 'salareuniao_session',
    ],
    'mail' => [
        'driver' => 'smtp',
        'host' => 'smtp.seu-dominio.example',
        'port' => 587,
        'auth' => true,
        'username' => 'salareuniao@seu-dominio.example',
        'password' => 'ALTERE_AQUI',
        'encryption' => 'tls',
        'from' => 'salareuniao@seu-dominio.example',
        'from_name' => 'Sala Reunião',
        'reply_to' => 'salareuniao@seu-dominio.example',
        'fallback_mail' => false,
    ],
    'websocket' => [
        'enabled' => true,
        // URL pública usada pelo navegador. Em produção, use wss://.
        'public_url' => 'wss://meet.seu-dominio.example/ws',
        // Interface/porta internas do serviço PHP Ratchet.
        'http_host' => 'meet.seu-dominio.example',
        'listen_host' => '127.0.0.1',
        'listen_port' => 8088,
        'path' => '/ws',
        'reconnect_ms' => 2000,
    ],
    'webrtc' => [
        'ice_servers' => [
            ['urls' => ['stun:turn.seu-dominio.example:3478']],
        ],
        'turn' => [
            'enabled' => true,
            'urls' => [
                'turn:turn.seu-dominio.example:3478?transport=udp',
                'turn:turn.seu-dominio.example:3478?transport=tcp',
                'turns:turn.seu-dominio.example:5349?transport=tcp',
            ],
            // Deve ser o MESMO valor de static-auth-secret do Coturn.
            // Nunca publique este segredo no Git.
            'secret' => 'TROQUE_POR_UM_SEGREDO_FORTE',
            'ttl' => 3600,
        ],
    ],
];
