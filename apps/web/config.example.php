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
        'from' => 'salareuniao@seu-dominio.example',
    ],
    'webrtc' => [
        'ice_servers' => [
            ['urls' => ['stun:stun.l.google.com:19302']]
        ],
    ],
];
