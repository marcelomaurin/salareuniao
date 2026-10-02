<?php
require __DIR__.'/../_bootstrap.php';
api_require_method('GET');
$d=api_device($pdo);
$id=(int)($_GET['id']??0);
if($id<=0)api_json(['ok'=>false,'error'=>'release_id_required'],422);

$q=$pdo->prepare("SELECT * FROM firmware_releases WHERE id=? AND device_type='esp32' LIMIT 1");
$q->execute([$id]);$r=$q->fetch();
if(!$r)api_json(['ok'=>false,'error'=>'firmware_not_found'],404);

$path=dirname(__DIR__,5).'/storage/firmware/'.$r['file_name'];
if(!is_file($path))api_json(['ok'=>false,'error'=>'firmware_file_missing'],404);

header_remove('Content-Type');
header('Content-Type: application/octet-stream');
header('Content-Length: '.filesize($path));
header('X-Firmware-Version: '.$r['version']);
header('X-Firmware-SHA256: '.$r['sha256']);
header('Cache-Control: no-store');
readfile($path);
exit;
