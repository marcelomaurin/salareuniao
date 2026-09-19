<?php
declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Ratchet\App;
use SalaReuniao\Signaling\MeetingSocket;

$configFile=__DIR__.'/../../apps/web/config.php';
if(!is_file($configFile)){
    fwrite(STDERR,"Configuração não encontrada: {$configFile}\n");
    exit(1);
}
$config=require $configFile;

$httpHost=(string)($config['websocket']['http_host']??'localhost');
$listenHost=(string)($config['websocket']['listen_host']??'127.0.0.1');
$port=(int)($config['websocket']['listen_port']??8088);
$path=(string)($config['websocket']['path']??'/ws');

$socket=new MeetingSocket($config);
$app=new App($httpHost,$port,$listenHost);
$app->route($path,$socket,['*']);

fwrite(STDOUT,"Sala Reunião WebSocket em {$listenHost}:{$port}{$path} (Host {$httpHost})\n");
$app->run();
