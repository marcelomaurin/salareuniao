<?php
require __DIR__.'/../_bootstrap.php';
api_require_method('GET');
$d=api_device($pdo);

$releaseId=(int)($_GET['release_id']??0);
if($releaseId>0){
    $q=$pdo->prepare("SELECT id,version,file_size,sha256,notes,required FROM firmware_releases WHERE id=? AND device_type='esp32' LIMIT 1");
    $q->execute([$releaseId]);
}else{
    $q=$pdo->prepare("SELECT id,version,file_size,sha256,notes,required FROM firmware_releases WHERE device_type='esp32' AND active=1 ORDER BY created_at DESC LIMIT 1");
    $q->execute();
}
$r=$q->fetch();
if(!$r)api_json(['ok'=>true,'update_available'=>false]);

$current=(string)($_GET['current']??$d['firmware_version']??'0.0.0');
$available=version_compare($current,$r['version'],'<');

api_json([
  'ok'=>true,
  'update_available'=>$available,
  'current_version'=>$current,
  'release'=>[
    'id'=>(int)$r['id'],
    'version'=>$r['version'],
    'size'=>(int)$r['file_size'],
    'sha256'=>$r['sha256'],
    'required'=>(bool)$r['required'],
    'notes'=>$r['notes'],
    'download_path'=>'/device/firmware_download.php?id='.(int)$r['id'],
  ],
]);
