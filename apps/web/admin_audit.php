<?php
require __DIR__.'/lib/bootstrap.php';
$admin=require_admin();

$action=trim($_GET['action']??'');
$userId=(int)($_GET['user_id']??0);
$targetType=trim($_GET['target_type']??'');
$targetId=trim($_GET['target_id']??'');
$from=trim($_GET['from']??'');
$to=trim($_GET['to']??'');

$where=[];$params=[];
if($action!==''){$where[]='a.action LIKE ?';$params[]='%'.$action.'%';}
if($userId>0){$where[]='a.user_id=?';$params[]=$userId;}
if($targetType!==''){$where[]='a.target_type=?';$params[]=$targetType;}
if($targetId!==''){$where[]='a.target_id=?';$params[]=$targetId;}
if($from!==''){$where[]='a.created_at>=?';$params[]=$from.' 00:00:00';}
if($to!==''){$where[]='a.created_at<=?';$params[]=$to.' 23:59:59';}

$sql="SELECT a.*,u.name user_name,u.email user_email
      FROM audit_log a
      LEFT JOIN users u ON u.id=a.user_id";
if($where)$sql.=' WHERE '.implode(' AND ',$where);
$sql.=' ORDER BY a.id DESC LIMIT 500';

$q=$pdo->prepare($sql);$q->execute($params);$rows=$q->fetchAll();
$users=$pdo->query('SELECT id,name,email FROM users ORDER BY name')->fetchAll();
$actions=$pdo->query('SELECT DISTINCT action FROM audit_log ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);

function pretty_audit_json($value): string {
    if($value===null||$value==='')return '-';
    $d=json_decode((string)$value,true);
    if(json_last_error()===JSON_ERROR_NONE){
        return json_encode($d,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'-';
    }
    return (string)$value;
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Auditoria - Sala Reunião</title>
<style>
body{font-family:Arial,sans-serif;margin:24px;background:#f5f7fa;color:#1f2937}
.panel{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin:14px 0;overflow:auto}
.filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;align-items:end}
label{font-size:13px;color:#4b5563}input,select,button{padding:8px;width:100%;box-sizing:border-box}
table{border-collapse:collapse;width:100%;min-width:1100px}th,td{padding:8px;border-bottom:1px solid #eee;text-align:left;vertical-align:top}th{background:#f9fafb}
pre{margin:0;white-space:pre-wrap;word-break:break-word;font-size:12px}.muted{color:#6b7280;font-size:12px}
</style></head><body>
<h1>Auditoria</h1>
<p><a href="admin.php">Voltar à administração</a></p>

<div class="panel">
<form method="get" class="filters">
<label>Ação<input name="action" list="actions" value="<?=e($action)?>"><datalist id="actions"><?php foreach($actions as $a):?><option value="<?=e($a)?>"><?php endforeach;?></datalist></label>
<label>Usuário<select name="user_id"><option value="">Todos</option><?php foreach($users as $u):?><option value="<?=$u['id']?>" <?=$userId===(int)$u['id']?'selected':''?>><?=e($u['name'].' · '.$u['email'])?></option><?php endforeach;?></select></label>
<label>Tipo do alvo<input name="target_type" value="<?=e($targetType)?>" placeholder="room/device/user"></label>
<label>ID do alvo<input name="target_id" value="<?=e($targetId)?>"></label>
<label>De<input type="date" name="from" value="<?=e($from)?>"></label>
<label>Até<input type="date" name="to" value="<?=e($to)?>"></label>
<div><button>Filtrar</button></div>
<div><a href="admin_audit.php">Limpar filtros</a></div>
</form>
</div>

<div class="panel">
<p class="muted">Exibindo até 500 registros mais recentes.</p>
<table><thead><tr><th>Data</th><th>Usuário</th><th>Ação</th><th>Alvo</th><th>IP</th><th>Detalhes</th><th>User-Agent</th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr>
<td><?=e($r['created_at'])?></td>
<td><?=e($r['user_name']?:'sistema')?><div class="muted"><?=e((string)($r['user_email']?:''))?></div></td>
<td><strong><?=e($r['action'])?></strong></td>
<td><?=e((string)($r['target_type']?:'-'))?><?= $r['target_id']!==null?' #'.e((string)$r['target_id']):'' ?></td>
<td><?=e((string)($r['ip_address']?:'-'))?></td>
<td><pre><?=e(pretty_audit_json($r['details']))?></pre></td>
<td class="muted"><?=e((string)($r['user_agent']?:'-'))?></td>
</tr><?php endforeach;?>
<?php if(!$rows):?><tr><td colspan="7">Nenhum registro encontrado.</td></tr><?php endif;?>
</tbody></table>
</div>
</body></html>
