<?php
require __DIR__.'/_bootstrap.php';
api_require_method('GET');
$u=api_user($pdo);

$cfg=$config['android_update']??[];
api_json([
  'ok'=>true,
  'enabled'=>!empty($cfg['enabled']),
  'version_code'=>(int)($cfg['version_code']??0),
  'version'=>(string)($cfg['version']??'0.0.0'),
  'required'=>!empty($cfg['required']),
  'notes'=>(string)($cfg['notes']??''),
  'apk_url'=>(string)($cfg['apk_url']??''),
]);
