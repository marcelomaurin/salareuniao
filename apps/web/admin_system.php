<?php
require __DIR__.'/lib/bootstrap.php';
require_admin();

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

$allOk=true;foreach($checks as $c)$allOk=$allOk&&$c[1];
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Diagnóstico - Sala Reunião</title>
<style>body{font-family:Arial,sans-serif;margin:24px;background:#f6f7f9;color:#1f2937}.box{max-width:850px;background:#fff;border:1px solid #ddd;border-radius:12px;padding:20px}.row{display:grid;grid-template-columns:1fr 110px 1fr;gap:12px;padding:10px;border-bottom:1px solid #eee}.ok{color:#15803d;font-weight:bold}.bad{color:#b91c1c;font-weight:bold}.summary{padding:12px;border-radius:8px;background:#f3f4f6;margin-bottom:15px}</style></head><body>
<div class="box"><h1>Diagnóstico de produção</h1>
<div class="summary <?=$allOk?'ok':'bad'?>"><?=$allOk?'Configuração básica pronta para teste.':'Existem itens pendentes antes do teste externo.'?></div>
<?php foreach($checks as $c):?><div class="row"><strong><?=e($c[0])?></strong><span class="<?=$c[1]?'ok':'bad'?>"><?=$c[1]?'OK':'PENDENTE'?></span><span><?=e((string)$c[2])?></span></div><?php endforeach;?>
<h2>Teste WebRTC</h2>
<p>Depois que todos os itens acima estiverem OK, abra uma sala em dois computadores conectados a redes diferentes. Na tela da reunião aparecerá:</p>
<ul><li><strong>ICE: P2P direto</strong> quando a mídia estiver direta entre os navegadores.</li><li><strong>ICE: TURN relay</strong> quando o Coturn estiver encaminhando a mídia.</li></ul>
<p><a href="admin.php">Voltar à administração</a></p></div>
</body></html>
