<?php
require __DIR__.'/lib/bootstrap.php';
require __DIR__.'/lib/mailer.php';
$admin=require_admin();
$mailTest='';

$checks=[];
$checks[]=['PHP >= 8.1',version_compare(PHP_VERSION,'8.1.0','>='),PHP_VERSION];
$checks[]=['PDO MySQL',extension_loaded('pdo_mysql'),extension_loaded('pdo_mysql')?'carregado':'ausente'];
$checks[]=['OpenSSL',extension_loaded('openssl'),extension_loaded('openssl')?'carregado':'ausente'];
$isHttps=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https';
$checks[]=['HTTPS',$isHttps,$isHttps?'ativo':'não detectado'];

try{$pdo->query('SELECT 1');$db=true;}catch(Throwable $e){$db=false;}
$checks[]=['MySQL',$db,$db?'conectado':'falha'];

$turn=$config['webrtc']['turn']??[];
$turnOk=!empty($turn['enabled'])&&!empty($turn['secret'])&&!empty($turn['urls'])&&$turn['secret']!=='TROQUE_POR_UM_SEGREDO_FORTE';
$checks[]=['TURN configurado',$turnOk,$turnOk?'habilitado':'pendente'];
$base=(string)($config['app']['base_url']??'');
$checks[]=['Base URL HTTPS',str_starts_with($base,'https://'),$base?:'não configurada'];
$ws=$config['websocket']??[];
$wsUrl=(string)($ws['public_url']??'');
$wsOk=!empty($ws['enabled'])&&str_starts_with($wsUrl,'wss://')&&!empty($ws['http_host'])&&!empty($ws['listen_port']);
$checks[]=['WebSocket',$wsOk,$wsOk?$wsUrl:'pendente'];

require_once __DIR__.'/lib/broadcast.php';
$bcCfg=broadcast_config($config);
$bcActive=broadcast_active($config);
$bcHealth=$bcCfg['enabled']?broadcast_health($config):['ok'=>false,'metrics'=>[],'error'=>'desabilitado'];
$checks[]=['Transporte de mídia',true,$bcActive?'broadcast (bcastd)':'p2p (WebRTC mesh)'];
if($bcCfg['enabled']){
    $checks[]=['Serviço broadcast',$bcHealth['ok'],$bcHealth['ok']
        ?('online · salas '.($bcHealth['metrics']['bcast_rooms']??'0').' · clientes '.($bcHealth['metrics']['bcast_clients']??'0'))
        :('sem resposta em '.$bcCfg['internal_host'].':'.$bcCfg['internal_port'].' ('.$bcHealth['error'].')')];
    $checks[]=['Broadcast URL WSS',str_starts_with($bcCfg['public_url'],'wss://'),$bcCfg['public_url']?:'não configurada'];
}

$mailCfg=$config['mail']??[];
$vendorOk=is_file(__DIR__.'/vendor/autoload.php');
$checks[]=['PHPMailer',$vendorOk,$vendorOk?'instalado':'execute composer install em apps/web'];
$smtpOk=($mailCfg['driver']??'smtp')==='smtp'
    && !empty($mailCfg['host'])
    && !empty($mailCfg['from'])
    && (($mailCfg['auth']??false)===false || (!empty($mailCfg['username'])&&!empty($mailCfg['password'])&&$mailCfg['password']!=='ALTERE_AQUI'));
$checks[]=['SMTP configurado',$smtpOk,$smtpOk?(string)$mailCfg['host']:'pendente'];

$webrtcMsg = '';
$webrtcOk = true;

if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='save_webrtc_params'){
    verify_csrf();
    $maxMesh = (int)($_POST['max_mesh_participants'] ?? 4);
    if ($maxMesh < 2 || $maxMesh > 100) {
        $webrtcMsg = 'O limite de participantes em Mesh deve ser entre 2 e 100.';
        $webrtcOk = false;
    } else {
        set_system_parameter('webrtc.max_mesh_participants', $maxMesh, 'integer', 'Quantidade máxima recomendada de participantes para operação WebRTC Mesh.', (int)$admin['id']);
        audit_log('update_system_parameter', 'system_parameters', 'webrtc.max_mesh_participants', ['value' => $maxMesh]);
        $webrtcMsg = 'Parâmetros WebRTC atualizados com sucesso.';
        $webrtcOk = true;
    }
}

$currentMaxMesh = (int)get_system_parameter('webrtc.max_mesh_participants', $config['webrtc']['max_mesh_participants'] ?? 4);

if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='test_mail'){
    verify_csrf();
    $subject='Teste de e-mail - '.($config['app']['name']??'Sala Reunião');
    $html=mail_layout($subject,'<p>O envio SMTP da aplicação está funcionando.</p><p>Este teste foi solicitado pelo painel administrativo.</p>');
    $mailTest=send_app_mail($config,$admin['email'],$subject,$html,'Teste SMTP do Sala Reunião.');
    $mailTest=$mailTest?'E-mail de teste enviado para '.$admin['email'].'.':'Falha ao enviar. Consulte o log do PHP e a configuração SMTP.';
}

$allOk=true;foreach($checks as $c)$allOk=$allOk&&$c[1];
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Diagnóstico - Sala Reunião</title>
<style>body{font-family:Arial,sans-serif;margin:24px;background:#f6f7f9;color:#1f2937}.box{max-width:850px;background:#fff;border:1px solid #ddd;border-radius:12px;padding:20px}.row{display:grid;grid-template-columns:1fr 110px 1fr;gap:12px;padding:10px;border-bottom:1px solid #eee}.ok{color:#15803d;font-weight:bold}.bad{color:#b91c1c;font-weight:bold}.summary{padding:12px;border-radius:8px;background:#f3f4f6;margin-bottom:15px}</style></head><body>
<div class="box"><h1>Diagnóstico de produção</h1>
<div class="summary <?=$allOk?'ok':'bad'?>"><?=$allOk?'Configuração básica pronta para teste.':'Existem itens pendentes antes do teste externo.'?></div>
<?php foreach($checks as $c):?><div class="row"><strong><?=e($c[0])?></strong><span class="<?=$c[1]?'ok':'bad'?>"><?=$c[1]?'OK':'PENDENTE'?></span><span><?=e((string)$c[2])?></span></div><?php endforeach;?>
<h2>Teste de e-mail</h2>
<?php if($mailTest):?><p class="summary"><?=e($mailTest)?></p><?php endif;?>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="test_mail">
<button type="submit">Enviar e-mail de teste para <?=e($admin['email'])?></button></form>

<h2>WebRTC / Mídia</h2>
<?php if($webrtcMsg):?><p class="summary <?=$webrtcOk?'ok':'bad'?>"><?=e($webrtcMsg)?></p><?php endif;?>
<form method="post" style="margin-bottom: 25px;">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<input type="hidden" name="action" value="save_webrtc_params">
<div style="margin-bottom: 10px;">
<label for="max_mesh_participants" style="display:block; font-weight:bold; margin-bottom: 6px;">Máximo de participantes em Mesh:</label>
<input type="number" id="max_mesh_participants" name="max_mesh_participants" min="2" max="100" value="<?=e((string)$currentMaxMesh)?>" style="padding: 8px 12px; border: 1px solid #ccc; border-radius: 6px; width: 100px; font-size: 1rem;">
<p style="color: #64748b; font-size: 0.88rem; margin-top: 6px; margin-bottom: 0;">
Acima deste valor a interface deverá alertar que a sala ultrapassou o limite recomendado da arquitetura WebRTC Mesh.
</p>
</div>
<button type="submit" style="padding: 8px 16px; background: #0284c7; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-weight: bold;">Salvar Parâmetros WebRTC</button>
</form>

<h2>Teste WebRTC</h2>
<p>Depois que todos os itens acima estiverem OK, abra uma sala em dois computadores conectados a redes diferentes. Na tela da reunião aparecerá:</p>
<ul><li><strong>ICE: P2P direto</strong> quando a mídia estiver direta entre os navegadores.</li><li><strong>ICE: TURN relay</strong> quando o Coturn estiver encaminhando a mídia.</li></ul>
<p><a href="admin.php">Voltar à administração</a></p></div>
</body></html>
