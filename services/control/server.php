<?php
declare(strict_types=1);

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
} elseif (is_file(__DIR__ . '/../../vendor/autoload.php')) {
    require __DIR__ . '/../../vendor/autoload.php';
}

require __DIR__ . '/src/ControlSocket.php';
require __DIR__ . '/src/BridgeSocket.php';

use Ratchet\App;
use SalaReuniao\Control\ControlSocket;
use SalaReuniao\Control\BridgeSocket;

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

$controlSocket = new ControlSocket($config);
$bridgeSocket = new BridgeSocket($config);

$app = new App($httpHost, $port, $listenHost);
// Rota do canal de controle administrativo (Tarefa 08)
$app->route($path, $controlSocket, ['*']);
// Rota do media bridge experimental (Tarefas 40 a 42)
$bridgePath = (string)($config['bridge']['path'] ?? '/bridge');
$app->route($bridgePath, $bridgeSocket, ['*']);

fwrite(STDOUT, "Sala Reunião Control & Bridge Daemon em {$listenHost}:{$port}\n");
fwrite(STDOUT, "  - Controle: {$path}\n");
fwrite(STDOUT, "  - Bridge:   {$bridgePath}\n");
$app->run();
