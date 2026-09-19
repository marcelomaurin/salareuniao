<?php
require __DIR__.'/lib/bootstrap.php';

if(current_user()){header('Location: index.php');exit;}

$token=$_GET['token']??$_POST['token']??'';
$error='';$success='';
$record=null;

if($token!==''){
    $hash=hash('sha256',$token);
    $st=$pdo->prepare("SELECT pr.id,pr.user_id,pr.expires_at,u.email
        FROM password_reset_tokens pr
        JOIN users u ON u.id=pr.user_id
        WHERE pr.token_hash=? AND pr.used_at IS NULL AND pr.expires_at>NOW() AND u.active=1
        LIMIT 1");
    $st->execute([$hash]);$record=$st->fetch()?:null;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $password=(string)($_POST['password']??'');
    $confirm=(string)($_POST['password_confirm']??'');

    if(!$record){
        $error='Este link é inválido ou expirou.';
    }elseif(strlen($password)<8){
        $error='A senha deve ter pelo menos 8 caracteres.';
    }elseif($password!==$confirm){
        $error='As senhas não conferem.';
    }else{
        $pdo->beginTransaction();
        try{
            $newHash=password_hash($password,PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$newHash,$record['user_id']]);
            $pdo->prepare('UPDATE password_reset_tokens SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')->execute([$record['user_id']]);
            $pdo->commit();
            $success='Senha alterada com sucesso. Você já pode entrar.';
            $record=null;
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            $error='Não foi possível alterar a senha.';
        }
    }
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Redefinir senha - Sala Reunião</title>
<style>body{font-family:Arial,sans-serif;background:#f6f7f9;margin:0}.box{max-width:480px;margin:60px auto;background:#fff;border:1px solid #ddd;border-radius:12px;padding:24px}input{width:100%;box-sizing:border-box;padding:10px;margin:8px 0 14px;border:1px solid #ccc;border-radius:8px}button{padding:10px 16px}.ok{background:#dcfce7;padding:10px;border-radius:8px}.err{background:#fee2e2;padding:10px;border-radius:8px}</style></head><body>
<div class="box"><h1>Redefinir senha</h1>
<?php if($success):?><p class="ok"><?=e($success)?></p><p><a href="login.php">Ir para o login</a></p>
<?php elseif(!$record):?><p class="err"><?=e($error?:'Este link é inválido ou expirou.')?></p><p><a href="forgot_password.php">Solicitar novo link</a></p>
<?php else:?>
<?php if($error):?><p class="err"><?=e($error)?></p><?php endif;?>
<form method="post">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<input type="hidden" name="token" value="<?=e($token)?>">
<label>Nova senha<input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
<label>Confirmar nova senha<input type="password" name="password_confirm" required minlength="8" autocomplete="new-password"></label>
<button type="submit">Alterar senha</button>
</form>
<?php endif;?>
</div></body></html>
