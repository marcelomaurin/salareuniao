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
    'api' => [
        // Tokens de usuário (Desktop): 30 dias.
        'user_token_ttl' => 2592000,
        // 0 = token de dispositivo sem expiração até ser revogado/rotacionado.
        'device_token_ttl' => 0,
    ],
    'firmware' => [
        'esp32_target_version' => '1.0.0',
    ],
    'android_update' => [
        'enabled' => true,
        'version_code' => 1,
        'version' => '1.0.0',
        'required' => false,
        'notes' => 'Primeira versão Android distribuída pelo servidor.',
        'apk_url' => 'https://meet.seu-dominio.example/downloads/SalaReuniaoAndroid-1.0.0.apk',
    ],
    'desktop_update' => [
        'enabled' => true,
        'version' => '1.0.0',
        'required' => false,
        'notes' => 'Primeira versão distribuída pelo servidor.',
        'windows_url' => 'https://meet.seu-dominio.example/downloads/SalaReuniaoDesktop-1.0.0-setup.exe',
        'linux_url' => 'https://meet.seu-dominio.example/downloads/SalaReuniaoDesktop-1.0.0.tar.gz',
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
        'max_mesh_participants' => 4,
        // Servidores STUN públicos (Google e STUN padrão)
        'ice_servers' => [
            ['urls' => [
                'stun:stun.l.google.com:19302',
                'stun:stun1.l.google.com:19302',
                'stun:stun2.l.google.com:19302',
            ]],
        ],
        // Servidor TURN (Essencial para Hospedagem Compartilhada como Hostinger, cPanel, hPanel)
        // Para hospedar sem VPS, use um provedor TURN gratuito (ex: Metered.ca com 50GB/mês grátis)
        // ou um servidor Coturn próprio com static-auth-secret.
        'turn' => [
            'enabled' => false,
            // Exemplo com Metered.ca (Hospedagem Compartilhada Hostinger):
            // 'urls' => [
            //     'turn:standard.relay.metered.ca:80',
            //     'turn:standard.relay.metered.ca:443',
            //     'turn:standard.relay.metered.ca:443?transport=tcp'
            // ],
            // 'username' => 'SEU_USERNAME_METERED',
            // 'credential' => 'SEU_PASSWORD_METERED',

            // Exemplo com Coturn próprio (VPS):
            // 'urls' => [
            //     'turn:turn.seu-dominio.example:3478?transport=udp',
            //     'turn:turn.seu-dominio.example:3478?transport=tcp',
            //     'turns:turn.seu-dominio.example:5349?transport=tcp',
            // ],
            // 'secret' => 'TROQUE_POR_UM_SEGREDO_FORTE',
            // 'ttl' => 3600,
        ],
    ],
    // Transporte de mídia da sala:
    //   'p2p'       = WebRTC mesh legado (room.php)
    //   'broadcast' = servidor de broadcast em C (broadcast/, bcastd): um orador por vez,
    //                 sala de espera, palavra, chat e funções do organizador pelo serviço.
    'media' => [
        'transport' => 'p2p',
    ],
    // Serviço broadcast (bcastd). Requer VPS: o binário roda atrás do Apache em 127.0.0.1:8090.
    // Veja broadcast/README.md e broadcast/etc/apache/broadcast.conf.
    'broadcast' => [
        'enabled' => false,
        'public_url' => 'wss://seu-dominio.example/salareuniao/broadcast',
        'internal_host' => '127.0.0.1',   // usado por admin_system.php (/healthz e /metrics)
        'internal_port' => 8090,
        'chunk_ms' => 250,                // tamanho dos pedaços do MediaRecorder (latência x overhead)
    ],
    // PHP Media Bridge: Desative em hospedagem compartilhada (Hostinger hPanel).
    // Requer VPS com serviço daemon Ratchet rodando em segundo plano.
    'bridge' => [
        'enabled' => false,
        'public_url' => 'wss://maurinsoft.com.br/salareuniao/bridge',
        'path' => '/bridge',
        'max_clients_per_room' => 20,
        'max_message_bytes' => 524288,
        'max_buffer_bytes' => 4194304,
        'video_bitrate' => 350000,
        'audio_bitrate' => 48000,
        'chunk_ms' => 250,
        'fallback_timeout_ms' => 8000,
    ],
];
