<?php
require __DIR__.'/../_bootstrap.php';
api_require_method('POST');
$in=api_input();

$email=strtolower(trim((string)($in['email']??'')));
$password=(string)($in['password']??'');
$name=trim((string)($in['client_name']??'Desktop'));
if($name==='')$name='Desktop';

$q=$pdo->prepare('SELECT id,name,email,password_hash,role,active FROM users WHERE email=? AND active=1 LIMIT 1');
$q->execute([$email]);$u=$q->fetch();
if(!$u||!password_verify($password,$u['password_hash'])){
    usleep(250000);
    api_json(['ok'=>false,'error'=>'invalid_credentials'],401);
}

$token=bin2hex(random_bytes(32));
$hash=hash('sha256',$token);
$ttl=max(3600,(int)($config['api']['user_token_ttl']??2592000));
$expires=(new DateTimeImmutable())->modify('+'.$ttl.' seconds')->format('Y-m-d H:i:s');

$ins=$pdo->prepare('INSERT INTO api_tokens(user_id,token_hash,name,expires_at) VALUES(?,?,?,?)');
$ins->execute([$u['id'],$hash,mb_substr($name,0,120),$expires]);

api_json([
    'ok'=>true,
    'token'=>$token,
    'token_type'=>'Bearer',
    'expires_at'=>$expires,
    'user'=>[
        'id'=>(int)$u['id'],
        'name'=>$u['name'],
        'email'=>$u['email'],
        'role'=>$u['role'],
    ],
]);
