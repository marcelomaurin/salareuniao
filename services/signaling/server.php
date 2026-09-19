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

$host=(string)($config['websocket']['listen_host']??'0.0.0.0');
$port=(int)($config['websocket']['listen_port']??8088);
$path=(string)($config['websocket']['path']??'/ws');

$socket=new MeetingSocket($config);
$app=new App($host,$port,'0.0.0.0');
$app->route($path,$socket,['*']);

fwrite(STDOUT,"Sala Reunião WebSocket em {$host}:{$port}{$path}\n");
$app->run();
