<?php
declare(strict_types=1);

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
} elseif (is_file(__DIR__ . '/../../vendor/autoload.php')) {
    require __DIR__ . '/../../vendor/autoload.php';
}

require __DIR__ . '/src/ControlSocket.php';

use Ratchet\App;
use SalaReuniao\Control\ControlSocket;

$configFile = __DIR__ . '/../../config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "Configuração não encontrada: {$configFile}\n");
    exit(1);
}
$config = require $configFile;

$httpHost = (string)($config['websocket']['http_host'] ?? 'localhost');
$listenHost = (string)($config['websocket']['listen_host'] ?? '127.0.0.1');
$port = (int)($config['websocket']['control_port'] ?? 8089);
$path = (string)($config['websocket']['control_path'] ?? '/control');

$socket = new ControlSocket($config);
$app = new App($httpHost, $port, $listenHost);
$app->route($path, $socket, ['*']);

fwrite(STDOUT, "Sala Reunião Control WebSocket em {$listenHost}:{$port}{$path} (Host {$httpHost})\n");
$app->run();
