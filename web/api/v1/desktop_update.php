<?php
require __DIR__.'/_bootstrap.php';
api_require_method('GET');
$u=api_user($pdo);

$platform=strtolower(trim((string)($_GET['platform']??'')));
$cfg=$config['desktop_update']??[];
$enabled=!empty($cfg['enabled']);
$url='';

if($platform==='windows')$url=(string)($cfg['windows_url']??'');
elseif($platform==='linux')$url=(string)($cfg['linux_url']??'');

api_json([
  'ok'=>true,
  'enabled'=>$enabled,
  'version'=>(string)($cfg['version']??'0.0.0'),
  'required'=>!empty($cfg['required']),
  'notes'=>(string)($cfg['notes']??''),
  'url'=>$url,
  'platform'=>$platform,
]);
