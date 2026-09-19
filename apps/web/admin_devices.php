<?php
require __DIR__.'/lib/bootstrap.php';
$admin=require_admin();
$message='';$error='';$issuedToken=null;

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $action=$_POST['action']??'create';
    try{
        if($action==='create'){
            $name=trim($_POST['name']??'');
            $uid=trim($_POST['device_uid']??'');
            $type=$_POST['type']??'esp32';
            $roomId=($_POST['room_id']??'')!==''?(int)$_POST['room_id']:null;
            if($name===''||$uid==='')throw new RuntimeException('Nome e UID são obrigatórios.');
            if(!in_array($type,['esp32','esp8266','desktop','other'],true))throw new RuntimeException('Tipo inválido.');
            $pdo->beginTransaction();
            $q=$pdo->prepare("INSERT INTO devices(name,device_uid,room_id,type,active,status) VALUES(?,?,?,?,1,'offline')");
            $q->execute([$name,$uid,$roomId,$type]);
            $id=(int)$pdo->lastInsertId();
            $issuedToken=bin2hex(random_bytes(32));
            $pdo->prepare('INSERT INTO device_tokens(device_id,token_hash) VALUES(?,?)')->execute([$id,hash('sha256',$issuedToken)]);
            $pdo->commit();
            $message='Dispositivo criado. Copie o token agora; ele não será exibido novamente.';
        }elseif($action==='rotate'){
            $id=(int)($_POST['device_id']??0);
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE device_tokens SET revoked_at=NOW() WHERE device_id=? AND revoked_at IS NULL')->execute([$id]);
            $issuedToken=bin2hex(random_bytes(32));
            $pdo->prepare('INSERT INTO device_tokens(device_id,token_hash) VALUES(?,?)')->execute([$id,hash('sha256',$issuedToken)]);
            $pdo->commit();
            $message='Token rotacionado. Atualize o dispositivo.';
        }elseif($action==='toggle'){
            $id=(int)($_POST['device_id']??0);
            $active=(int)($_POST['active']??0);
            $pdo->prepare("UPDATE devices SET active=?,status=IF(?=1,status,'offline') WHERE id=?")->execute([$active,$active,$id]);
            $message=$active?'Dispositivo ativado.':'Dispositivo desativado.';
        }elseif($action==='bind'){
            $id=(int)($_POST['device_id']??0);
            $roomId=($_POST['room_id']??'')!==''?(int)$_POST['room_id']:null;
            $pdo->prepare('UPDATE devices SET room_id=? WHERE id=?')->execute([$roomId,$id]);
            $message='Vínculo atualizado.';
        }elseif($action==='command'){
            $id=(int)($_POST['device_id']??0);
            $type=trim($_POST['command_type']??'refresh');
            $value=trim($_POST['command_value']??'');
            $payload=json_encode(['value'=>$value],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $pdo->prepare('INSERT INTO device_commands(device_id,command_type,payload) VALUES(?,?,?)')->execute([$id,$type,$payload]);
            $message='Comando enfileirado.';
        }
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        $error=$e->getMessage();
    }
}
$devices=$pdo->query("SELECT d.*,r.name room_name,(d.last_seen_at IS NOT NULL AND d.last_seen_at>=DATE_SUB(NOW(),INTERVAL 90 SECOND)) online FROM devices d LEFT JOIN rooms r ON r.id=d.room_id ORDER BY d.name")->fetchAll();
$rooms=$pdo->query("SELECT id,name,status FROM rooms WHERE status IN('scheduled','open') ORDER BY starts_at IS NULL,starts_at,created_at DESC LIMIT 300")->fetchAll();
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dispositivos</title>
<style>body{font-family:Arial,sans-serif;margin:24px;background:#f6f7f9;color:#1f2937}.panel{background:#fff;border:1px solid #ddd;border-radius:10px;padding:16px;margin:14px 0;overflow:auto}table{border-collapse:collapse;width:100%;min-width:900px}th,td{padding:8px;border-bottom:1px solid #eee;text-align:left}input,select{padding:8px}.ok{background:#dcfce7;padding:10px;border-radius:8px}.err{background:#fee2e2;padding:10px;border-radius:8px}.token{word-break:break-all;background:#111827;color:#fff;padding:12px;border-radius:8px}.online{color:#15803d;font-weight:bold}</style></head><body>
<h1>Dispositivos</h1>
<p><a href="admin.php">Voltar à administração</a></p>
<?php if($message):?><p class="ok"><?=e($message)?></p><?php endif;?><?php if($error):?><p class="err"><?=e($error)?></p><?php endif;?>
<?php if($issuedToken):?><div class="panel"><h2>Token emitido</h2><div class="token"><?=e($issuedToken)?></div><p>Use como <code>Authorization: Bearer TOKEN</code>.</p></div><?php endif;?>

<div class="panel"><h2>Novo dispositivo</h2>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="create">
<input name="name" required placeholder="Nome"> <input name="device_uid" required placeholder="UID/MAC lógico">
<select name="type"><option>esp32</option><option>esp8266</option><option>desktop</option><option>other</option></select>
<select name="room_id"><option value="">Sem sala</option><?php foreach($rooms as $r):?><option value="<?=$r['id']?>"><?=e($r['name'])?></option><?php endforeach;?></select>
<button>Criar dispositivo</button></form></div>

<div class="panel"><h2>Cadastrados</h2>
<table><thead><tr><th>Nome</th><th>UID</th><th>Tipo</th><th>Status</th><th>Último contato</th><th>Sala</th><th>Ações</th></tr></thead><tbody>
<?php foreach($devices as $d):?><tr>
<td><?=e($d['name'])?></td><td><?=e($d['device_uid'])?></td><td><?=e($d['type'])?></td>
<td class="<?=$d['online']?'online':''?>"><?= $d['online']?'online':e($d['status']) ?></td><td><?=e((string)($d['last_seen_at']?:'-'))?></td><td><?=e((string)($d['room_name']?:'-'))?></td>
<td>
<form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="rotate"><input type="hidden" name="device_id" value="<?=$d['id']?>"><button>Rotacionar token</button></form>
<form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="device_id" value="<?=$d['id']?>"><input type="hidden" name="active" value="<?=$d['active']?0:1?>"><button><?=$d['active']?'Desativar':'Ativar'?></button></form>
<form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="bind"><input type="hidden" name="device_id" value="<?=$d['id']?>">
<select name="room_id"><option value="">Sem sala</option><?php foreach($rooms as $r):?><option value="<?=$r['id']?>" <?=$d['room_id']==$r['id']?'selected':''?>><?=e($r['name'])?></option><?php endforeach;?></select><button>Vincular</button></form>
<form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="command"><input type="hidden" name="device_id" value="<?=$d['id']?>">
<select name="command_type"><option value="refresh">Atualizar</option><option value="led_on">LED on</option><option value="led_off">LED off</option><option value="message">Mensagem</option><option value="nextion_page">Página Nextion</option><option value="reboot">Reiniciar</option></select>
<input name="command_value" placeholder="valor opcional" size="14"><button>Enviar comando</button></form>
</td></tr><?php endforeach;?>
</tbody></table></div>
</body></html>
