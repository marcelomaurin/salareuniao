<?php
require __DIR__.'/lib/bootstrap.php';
$admin=require_admin();

$message='';$error='';
$storage=__DIR__.'/storage/firmware';
if(!is_dir($storage))@mkdir($storage,0770,true);

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $action=$_POST['action']??'upload';
    try{
        if($action==='upload'){
            $version=trim($_POST['version']??'');
            $notes=trim($_POST['notes']??'');
            $required=!empty($_POST['required'])?1:0;
            if($version==='')throw new RuntimeException('Informe a versão.');
            if(!preg_match('/^[0-9A-Za-z._-]{1,80}$/',$version))throw new RuntimeException('Versão inválida.');
            if(empty($_FILES['firmware'])||$_FILES['firmware']['error']!==UPLOAD_ERR_OK)throw new RuntimeException('Envie o arquivo .bin.');
            $original=(string)$_FILES['firmware']['name'];
            if(strtolower(pathinfo($original,PATHINFO_EXTENSION))!=='bin')throw new RuntimeException('O firmware deve ser um arquivo .bin.');
            $size=(int)$_FILES['firmware']['size'];
            if($size<=0||$size>16*1024*1024)throw new RuntimeException('Tamanho de firmware inválido.');
            $tmp=(string)$_FILES['firmware']['tmp_name'];
            $sha=hash_file('sha256',$tmp);
            if(!$sha)throw new RuntimeException('Não foi possível calcular SHA-256.');

            $stored='esp32-'.$version.'-'.substr($sha,0,12).'.bin';
            $dest=$storage.'/'.$stored;
            if(!move_uploaded_file($tmp,$dest))throw new RuntimeException('Não foi possível armazenar o firmware.');

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE firmware_releases SET active=0 WHERE device_type='esp32'")->execute();
            $q=$pdo->prepare("INSERT INTO firmware_releases(device_type,version,file_name,original_name,file_size,sha256,notes,active,required,created_by)
              VALUES('esp32',?,?,?,?,?,?,1,?,?)");
            $q->execute([$version,$stored,$original,$size,$sha,$notes,$required,$admin['id']]);
            $pdo->commit();
            $message='Firmware '.$version.' publicado e ativado.';
        }elseif($action==='activate'){
            $id=(int)($_POST['release_id']??0);
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE firmware_releases SET active=0 WHERE device_type='esp32'")->execute();
            $pdo->prepare("UPDATE firmware_releases SET active=1 WHERE id=? AND device_type='esp32'")->execute([$id]);
            $pdo->commit();
            $message='Release ativado.';
        }elseif($action==='delete'){
            $id=(int)($_POST['release_id']??0);
            $q=$pdo->prepare("SELECT * FROM firmware_releases WHERE id=? LIMIT 1");$q->execute([$id]);$r=$q->fetch();
            if(!$r)throw new RuntimeException('Release não encontrado.');
            if($r['active'])throw new RuntimeException('Não é possível excluir o release ativo.');
            $pdo->prepare('DELETE FROM firmware_releases WHERE id=?')->execute([$id]);
            @unlink($storage.'/'.$r['file_name']);
            $message='Release excluído.';
        }
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        $error=$e->getMessage();
    }
}

$releases=$pdo->query("SELECT f.*,u.name created_by_name FROM firmware_releases f JOIN users u ON u.id=f.created_by ORDER BY f.created_at DESC")->fetchAll();
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Firmware ESP32</title>
<style>body{font-family:Arial,sans-serif;margin:24px;background:#f5f7fa;color:#1f2937}.panel{background:#fff;border:1px solid #ddd;border-radius:12px;padding:16px;margin:14px 0}.ok{background:#dcfce7;padding:10px;border-radius:8px}.err{background:#fee2e2;padding:10px;border-radius:8px}table{border-collapse:collapse;width:100%;min-width:900px}th,td{padding:9px;border-bottom:1px solid #eee;text-align:left}input,textarea,button{padding:8px}.active{color:#15803d;font-weight:bold}.muted{color:#6b7280;font-size:13px}</style></head><body>
<h1>Firmware ESP32</h1><p><a href="admin_devices.php">Dispositivos</a> · <a href="admin.php">Administração</a></p>
<?php if($message):?><p class="ok"><?=e($message)?></p><?php endif;?><?php if($error):?><p class="err"><?=e($error)?></p><?php endif;?>

<div class="panel"><h2>Publicar nova versão</h2>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="upload">
<p><label>Versão<br><input name="version" required placeholder="1.1.0"></label></p>
<p><label>Arquivo .bin<br><input type="file" name="firmware" accept=".bin,application/octet-stream" required></label></p>
<p><label><input type="checkbox" name="required" value="1"> Atualização obrigatória</label></p>
<p><label>Notas<br><textarea name="notes" rows="4" cols="70"></textarea></label></p>
<button>Publicar e ativar</button>
</form></div>

<div class="panel"><h2>Releases</h2><table><thead><tr><th>Versão</th><th>Status</th><th>Tamanho</th><th>SHA-256</th><th>Obrigatória</th><th>Publicada</th><th>Por</th><th>Ações</th></tr></thead><tbody>
<?php foreach($releases as $r):?><tr>
<td><strong><?=e($r['version'])?></strong><div class="muted"><?=e($r['original_name'])?></div></td>
<td class="<?=$r['active']?'active':''?>"><?=$r['active']?'ATIVO':'inativo'?></td>
<td><?=number_format((int)$r['file_size']/1024,1,',','.')?> KB</td>
<td><code><?=e($r['sha256'])?></code></td>
<td><?=$r['required']?'SIM':'não'?></td>
<td><?=e($r['created_at'])?></td><td><?=e($r['created_by_name'])?></td>
<td>
<?php if(!$r['active']):?>
<form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="activate"><input type="hidden" name="release_id" value="<?=$r['id']?>"><button>Ativar</button></form>
<form method="post" style="display:inline" onsubmit="return confirm('Excluir este release?')"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="release_id" value="<?=$r['id']?>"><button>Excluir</button></form>
<?php endif;?>
</td></tr><?php endforeach;?>
<?php if(!$releases):?><tr><td colspan="8">Nenhum firmware publicado.</td></tr><?php endif;?>
</tbody></table></div>
</body></html>
