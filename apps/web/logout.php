<?php
require __DIR__ . '/lib/bootstrap.php';
$u=current_user();
if($u) audit_log('auth.logout','user',$u['id']);
$_SESSION = [];
session_destroy();
header('Location: login.php');
