<?php
require __DIR__.'/lib/bootstrap.php';$me=require_admin();$msg='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();$action=$_POST['action']??'';
 if($action==='create'){
  $name=trim($_POST['name']??'');$email=strtolower(trim($_POST['email']??''));$password=$_POST['password']??'';$role=($_POST['role']??'user')==='admin'?'admin':'user';
  if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<8)$error='Informe nome, e-mail válido e senha com pelo menos 8 caracteres.';
  else{
   try{$st=$pdo->prepare('INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,?)');$st->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),$role]);$newId=(int)$pdo->lastInsertId();audit_log('user.create','user',$newId,['email'=>$email,'role'=>$role]);$msg='Usuário criado.';}
   catch(PDOException $e){$error='Não foi possível criar o usuário. Verifique se o e-mail já existe.';}
  }
 }elseif($action==='toggle'){
  $id=(int)($_POST['user_id']??0);
  if($id!==$me['id']){$pdo->prepare('UPDATE users SET active=IF(active=1,0,1) WHERE id=?')->execute([$id]);audit_log('user.toggle_active','user',$id);$msg='Situação alterada.';}
 }elseif($action==='role'){
  $id=(int)($_POST['user_id']??0);$role=($_POST['role']??'user')==='admin'?'admin':'user';
  if($id!==$me['id']){$pdo->prepare('UPDATE users SET role=? WHERE id=?')->execute([$role,$id]);audit_log('user.change_role','user',$id,['role'=>$role]);$msg='Perfil alterado.';}
 }
}
$users=$pdo->query('SELECT id,name,email,role,active,created_at FROM users ORDER BY name')->fetchAll();
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Usuários - Administração</title></head><body>
<h1>Administração de usuários</h1><?php if($msg):?><p><?=e($msg)?></p><?php endif;?><?php if($error):?><p><?=e($error)?></p><?php endif;?>
<h2>Novo usuário</h2>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="create">
<input name="name" required placeholder="Nome"> <input type="email" name="email" required placeholder="E-mail"> <input type="password" name="password" required minlength="8" placeholder="Senha">
<select name="role"><option value="user">Usuário</option><option value="admin">Administrador</option></select><button>Criar</button></form>
<h2>Usuários</h2>
<?php foreach($users as $u):?><div style="margin:8px 0">
#<?=(int)$u['id']?> <strong><?=e($u['name'])?></strong> — <?=e($u['email'])?> — <?=e($u['role'])?> — <?=$u['active']?'ativo':'inativo'?>
<?php if((int)$u['id']!==(int)$me['id']):?>
<form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="user_id" value="<?=(int)$u['id']?>"><button><?=$u['active']?'Desativar':'Ativar'?></button></form>
<form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="role"><input type="hidden" name="user_id" value="<?=(int)$u['id']?>"><select name="role"><option value="user" <?=$u['role']==='user'?'selected':''?>>Usuário</option><option value="admin" <?=$u['role']==='admin'?'selected':''?>>Administrador</option></select><button>Alterar perfil</button></form>
<?php endif;?></div><?php endforeach;?>
<p><a href="admin.php">Voltar</a></p></body></html>
