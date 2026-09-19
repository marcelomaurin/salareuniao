<?php
require __DIR__.'/../_bootstrap.php';
api_require_method('POST');
$u=api_user($pdo);
$pdo->prepare('UPDATE api_tokens SET revoked_at=NOW() WHERE id=?')->execute([$u['token_id']]);
api_audit($pdo,$u,'auth.api_logout','user',$u['id']);
api_json(['ok'=>true]);
