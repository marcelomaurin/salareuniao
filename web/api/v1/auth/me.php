<?php
require __DIR__.'/../_bootstrap.php';
api_require_method('GET');
$u=api_user($pdo);
api_json(['ok'=>true,'user'=>[
    'id'=>(int)$u['id'],
    'name'=>$u['name'],
    'email'=>$u['email'],
    'role'=>$u['role'],
]]);
