<?php
require __DIR__.'/lib/bootstrap.php';
require __DIR__.'/lib/invitations.php';

if(current_user()){header('Location: index.php');exit;}

$message='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $email=strtolower(trim($_POST['email']??''));

    if(filter_var($email,FILTER_VALIDATE_EMAIL)){
        $st=$pdo->prepare('SELECT id,name,email FROM users WHERE email=? AND active=1 LIMIT 1');
        $st->execute([$email]);$user=$st->fetch();

        if($user){
            $rawToken=bin2hex(random_bytes(32));
            $hash=hash('sha256',$rawToken);

            $pdo->beginTransaction();
            try{
                $pdo->prepare('UPDATE password_reset_tokens SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')
                    ->execute([$user['id']]);
                $q=$pdo->prepare('INSERT INTO password_reset_tokens(user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 60 MINUTE))');
                $q->execute([$user['id'],$hash]);
                $pdo->commit();

                send_password_reset_mail($config,$user,$rawToken);
            }catch(Throwable $e){
                if($pdo->inTransaction())$pdo->rollBack();
                error_log('SalaReuniao forgot password error: '.$e->getMessage());
            }
        }
    }

    $message='Se houver uma conta ativa com esse e-mail, enviaremos instruções para redefinir a senha.';
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Recuperar senha - Sala Reunião</title>
<style>body{font-family:Arial,sans-serif;background:#f6f7f9;margin:0}.box{max-width:480px;margin:60px auto;background:#fff;border:1px solid #ddd;border-radius:12px;padding:24px}input{width:100%;box-sizing:border-box;padding:10px;margin:8px 0 14px;border:1px solid #ccc;border-radius:8px}button{padding:10px 16px}.ok{background:#ecfdf5;padding:10px;border-radius:8px}</style></head><body>
<div class="box"><h1>Recuperar senha</h1>
<?php if($message):?><p class="ok"><?=e($message)?></p><?php endif;?>
<form method="post">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<label>E-mail<input type="email" name="email" required autocomplete="email"></label>
<button type="submit">Enviar instruções</button>
</form>
<p><a href="login.php">Voltar ao login</a></p>
</div></body></html>
