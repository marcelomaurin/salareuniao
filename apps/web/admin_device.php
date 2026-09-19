<?php
require __DIR__.'/lib/bootstrap.php';
$admin=require_admin();

$id=(int)($_GET['id']??$_POST['id']??0);
if($id<=0){http_response_code(400);exit('ID inválido.');}

$message='';$error='';

function load_device(PDO $pdo,int $id): ?array {
    $q=$pdo->prepare("SELECT d.*,r.name room_name,r.status room_status,
      (d.last_seen_at IS NOT NULL AND d.last_seen_at>=DATE_SUB(NOW(),INTERVAL 90 SECOND)) online
      FROM devices d
      LEFT JOIN rooms r ON r.id=d.room_id
      WHERE d.id=? LIMIT 1");
    $q->execute([$id]);
    return $q->fetch()?:null;
}

$device=load_device($pdo,$id);
if(!$device){http_response_code(404);exit('Dispositivo não encontrado.');}
$esp32Target=(string)($config['firmware']['esp32_target_version']??'');
$fwCurrent=(string)($device['firmware_version']??'');
$firmwareOutdated=$device['type']==='esp32'&&$esp32Target!==''&&$fwCurrent!==''&&version_compare($fwCurrent,$esp32Target,'<');

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $action=$_POST['action']??'';
    try{
        if($action==='command'){
            $type=trim($_POST['command_type']??'refresh');
            $value=trim($_POST['command_value']??'');
            $allowed=['refresh','led_on','led_off','message','nextion_page','reboot'];
            if(!in_array($type,$allowed,true)) throw new RuntimeException('Comando inválido.');
            $payload=json_encode(['value'=>$value],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $pdo->prepare('INSERT INTO device_commands(device_id,command_type,payload) VALUES(?,?,?)')
                ->execute([$id,$type,$payload]);
            $message='Comando enfileirado.';
        }elseif($action==='rotate'){
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE device_tokens SET revoked_at=NOW() WHERE device_id=? AND revoked_at IS NULL')->execute([$id]);
            $issuedToken=bin2hex(random_bytes(32));
            $pdo->prepare('INSERT INTO device_tokens(device_id,token_hash) VALUES(?,?)')
                ->execute([$id,hash('sha256',$issuedToken)]);
            $pdo->commit();
            $message='Token rotacionado. Novo token: '.$issuedToken;
        }elseif($action==='toggle'){
            $active=!empty($_POST['active'])?1:0;
            $pdo->prepare("UPDATE devices SET active=?,status=IF(?=1,status,'offline') WHERE id=?")
                ->execute([$active,$active,$id]);
            $message=$active?'Dispositivo ativado.':'Dispositivo desativado.';
        }
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        $error=$e->getMessage();
    }
    $device=load_device($pdo,$id);
}

$eventsStmt=$pdo->prepare("SELECT id,event_type,payload,created_at
  FROM device_events WHERE device_id=? ORDER BY id DESC LIMIT 100");
$eventsStmt->execute([$id]);
$events=$eventsStmt->fetchAll();

$commandsStmt=$pdo->prepare("SELECT id,command_type,payload,status,created_at,delivered_at,acked_at,ack_payload
  FROM device_commands WHERE device_id=? ORDER BY id DESC LIMIT 100");
$commandsStmt->execute([$id]);
$commands=$commandsStmt->fetchAll();

$latestTelemetry=null;
foreach($events as $ev){
    if($ev['event_type']==='device.telemetry' || $ev['event_type']==='device.heartbeat'){
        $latestTelemetry=json_decode((string)$ev['payload'],true);
        if(is_array($latestTelemetry))break;
    }
}

function pretty_json($value): string {
    if($value===null||$value==='')return '-';
    if(is_string($value)){
        $decoded=json_decode($value,true);
        if(json_last_error()===JSON_ERROR_NONE)$value=$decoded;
    }
    return json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'-';
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($device['name'])?> - Dispositivo</title>
<style>
body{font-family:Arial,sans-serif;margin:24px;background:#f5f7fa;color:#1f2937}
.top{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:18px 0}
.card,.panel{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px}
.card strong{display:block;font-size:22px;margin-top:5px}
.panel{margin:16px 0;overflow:auto}
.ok{background:#dcfce7;padding:10px;border-radius:8px}.err{background:#fee2e2;padding:10px;border-radius:8px}
.online{color:#15803d;font-weight:bold}.offline{color:#b91c1c;font-weight:bold}.muted{color:#6b7280;font-size:13px}
table{border-collapse:collapse;width:100%;min-width:900px}th,td{padding:9px;border-bottom:1px solid #eee;text-align:left;vertical-align:top}th{background:#f9fafb}
pre{white-space:pre-wrap;word-break:break-word;font-size:12px;margin:0}
form.inline{display:inline-flex;gap:6px;align-items:center;flex-wrap:wrap}
input,select,button{padding:8px}.status-pending{color:#92400e}.status-delivered{color:#1d4ed8}.status-acked{color:#15803d}.status-failed{color:#b91c1c}
</style>
</head>
<body>
<div class="top">
  <div>
    <h1><?=e($device['name'])?></h1>
    <div class="muted">UID <?=e($device['device_uid'])?> · <?=e($device['type'])?></div>
  </div>
  <div><a href="admin_devices.php">Voltar aos dispositivos</a> · <a href="admin.php">Administração</a></div>
</div>

<?php if($message):?><p class="ok"><?=e($message)?></p><?php endif;?>
<?php if($error):?><p class="err"><?=e($error)?></p><?php endif;?>

<div class="cards">
  <div class="card">Conectividade<strong class="<?=$device['online']?'online':'offline'?>"><?=$device['online']?'ONLINE':'OFFLINE'?></strong><span class="muted"><?=e((string)($device['last_seen_at']?:'sem heartbeat'))?></span></div>
  <div class="card">Firmware<strong class="<?=$firmwareOutdated?'offline':'online'?>"><?=e($fwCurrent?:'-')?></strong><span class="muted"><?=$firmwareOutdated?'desatualizado · alvo '.e($esp32Target):($esp32Target!==''?'alvo '.e($esp32Target):'versão informada pelo heartbeat')?></span></div>
  <div class="card">IP<strong><?=e((string)($device['ip_address']?:'-'))?></strong><span class="muted">último endereço visto</span></div>
  <div class="card">Sala<strong><?=e((string)($device['room_name']?:'não vinculada'))?></strong><span class="muted"><?=e((string)($device['room_status']?:'-'))?></span></div>
  <div class="card">Ativo<strong><?=$device['active']?'SIM':'NÃO'?></strong><span class="muted">cadastro do dispositivo</span></div>
</div>

<div class="panel">
<h2>Telemetria atual</h2>
<?php if($latestTelemetry):?>
<div class="cards">
  <div class="card">RSSI<strong><?=e((string)($latestTelemetry['rssi']??'-'))?></strong><span class="muted">dBm</span></div>
  <div class="card">Heap livre<strong><?=e((string)($latestTelemetry['free_heap']??'-'))?></strong><span class="muted">bytes</span></div>
  <div class="card">Heap mínimo<strong><?=e((string)($latestTelemetry['min_free_heap']??'-'))?></strong><span class="muted">bytes</span></div>
  <div class="card">Uptime<strong><?=e((string)($latestTelemetry['uptime_seconds']??'-'))?></strong><span class="muted">segundos</span></div>
</div>
<?php else:?><p class="muted">Ainda não há telemetria registrada.</p><?php endif;?>
</div>

<div class="panel">
<h2>Comandos</h2>
<form method="post" class="inline">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<input type="hidden" name="id" value="<?=$id?>">
<input type="hidden" name="action" value="command">
<select name="command_type">
<option value="refresh">Atualizar estado</option>
<option value="led_on">LED on</option>
<option value="led_off">LED off</option>
<option value="message">Mensagem</option>
<option value="nextion_page">Página Nextion</option>
<option value="reboot">Reiniciar</option>
</select>
<input name="command_value" placeholder="valor opcional" size="24">
<button>Enviar comando</button>
</form>

<form method="post" class="inline" style="margin-left:14px">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<input type="hidden" name="id" value="<?=$id?>">
<input type="hidden" name="action" value="toggle">
<input type="hidden" name="active" value="<?=$device['active']?0:1?>">
<button><?=$device['active']?'Desativar dispositivo':'Ativar dispositivo'?></button>
</form>
</div>

<div class="panel">
<h2>Histórico de comandos</h2>
<table>
<thead><tr><th>ID</th><th>Comando</th><th>Status</th><th>Criado</th><th>Entregue</th><th>ACK</th><th>Payload</th><th>Resposta</th></tr></thead>
<tbody>
<?php foreach($commands as $c):?>
<tr>
<td>#<?=(int)$c['id']?></td>
<td><?=e($c['command_type'])?></td>
<td class="status-<?=e($c['status'])?>"><?=e($c['status'])?></td>
<td><?=e((string)$c['created_at'])?></td>
<td><?=e((string)($c['delivered_at']?:'-'))?></td>
<td><?=e((string)($c['acked_at']?:'-'))?></td>
<td><pre><?=e(pretty_json($c['payload']))?></pre></td>
<td><pre><?=e(pretty_json($c['ack_payload']))?></pre></td>
</tr>
<?php endforeach;?>
<?php if(!$commands):?><tr><td colspan="8">Nenhum comando registrado.</td></tr><?php endif;?>
</tbody>
</table>
</div>

<div class="panel">
<h2>Eventos e telemetria</h2>
<table>
<thead><tr><th>ID</th><th>Tipo</th><th>Data</th><th>Payload</th></tr></thead>
<tbody>
<?php foreach($events as $ev):?>
<tr>
<td>#<?=(int)$ev['id']?></td>
<td><?=e($ev['event_type'])?></td>
<td><?=e((string)$ev['created_at'])?></td>
<td><pre><?=e(pretty_json($ev['payload']))?></pre></td>
</tr>
<?php endforeach;?>
<?php if(!$events):?><tr><td colspan="4">Nenhum evento registrado.</td></tr><?php endif;?>
</tbody>
</table>
</div>
</body>
</html>
