<?php
require __DIR__.'/lib/bootstrap.php';
$token=$_GET['token']??'';
$st=$pdo->prepare("SELECT i.*,r.name room_name,r.status room_status FROM room_invites i JOIN rooms r ON r.id=i.room_id WHERE i.token=? AND i.status='approved' LIMIT 1");
$st->execute([$token]);$me=$st->fetch();
if(!$me){http_response_code(403);exit('Entrada não autorizada.');}
if($me['room_status']!=='open')exit('A sala ainda não foi aberta pelo administrador.');
$ice=json_encode($config['webrtc']['ice_servers']??[],JSON_UNESCAPED_SLASHES);
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title><?=e($me['room_name'])?></title>
<style>body{font-family:Arial,sans-serif;margin:20px}.grid{display:flex;flex-wrap:wrap;gap:10px}video{width:320px;max-width:100%;background:#111;border-radius:8px}.bar{margin:12px 0}</style></head><body>
<h1><?=e($me['room_name'])?></h1><p><?=e($me['display_name']?:$me['email'])?></p>
<div class="bar"><button id="mic">Microfone</button> <button id="cam">Câmera</button> <button id="leave">Sair</button></div>
<div class="grid" id="videos"><video id="local" autoplay muted playsinline></video></div>
<script>
const TOKEN=<?=json_encode($token)?>,ICE_SERVERS=<?=$ice?>;
const pcs=new Map();let lastId=0,selfKey=null,localStream=null;
async function send(type,payload={},recipient=null){await fetch('api/signal_send.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token:TOKEN,type,payload,recipient})});}
function peer(key){
 if(pcs.has(key))return pcs.get(key);
 const pc=new RTCPeerConnection({iceServers:ICE_SERVERS});
 localStream.getTracks().forEach(t=>pc.addTrack(t,localStream));
 pc.onicecandidate=e=>{if(e.candidate)send('ice',e.candidate,key);};
 pc.ontrack=e=>{let v=document.getElementById('peer-'+key);if(!v){v=document.createElement('video');v.id='peer-'+key;v.autoplay=true;v.playsInline=true;document.getElementById('videos').appendChild(v);}v.srcObject=e.streams[0];};
 pc.onconnectionstatechange=()=>{if(['failed','closed'].includes(pc.connectionState)){document.getElementById('peer-'+key)?.remove();pcs.delete(key);}};
 pcs.set(key,pc);return pc;
}
async function makeOffer(key){const pc=peer(key),offer=await pc.createOffer();await pc.setLocalDescription(offer);await send('offer',offer,key);}
async function handle(m){
 const key=m.sender_key,p=m.payload||{};
 if(m.message_type==='peer-ready'){if(selfKey&&selfKey<key)await makeOffer(key);return;}
 if(m.message_type==='offer'){const pc=peer(key);await pc.setRemoteDescription(new RTCSessionDescription(p));const ans=await pc.createAnswer();await pc.setLocalDescription(ans);await send('answer',ans,key);}
 else if(m.message_type==='answer'){await peer(key).setRemoteDescription(new RTCSessionDescription(p));}
 else if(m.message_type==='ice'){try{await peer(key).addIceCandidate(new RTCIceCandidate(p));}catch(e){console.warn(e);}}
 else if(m.message_type==='leave'){pcs.get(key)?.close();pcs.delete(key);document.getElementById('peer-'+key)?.remove();}
}
async function poll(){try{const r=await fetch('api/signal_poll.php?token='+encodeURIComponent(TOKEN)+'&after='+lastId,{cache:'no-store'});if(r.ok){const d=await r.json();selfKey=d.self;for(const m of d.messages){lastId=Math.max(lastId,Number(m.id));await handle(m);}}}catch(e){console.warn(e);}setTimeout(poll,900);}
(async()=>{try{localStream=await navigator.mediaDevices.getUserMedia({video:true,audio:true});document.getElementById('local').srcObject=localStream;await send('peer-ready',{});poll();}catch(e){alert('Não foi possível acessar câmera/microfone: '+e.message);}})();
document.getElementById('mic').onclick=()=>localStream?.getAudioTracks().forEach(t=>t.enabled=!t.enabled);
document.getElementById('cam').onclick=()=>localStream?.getVideoTracks().forEach(t=>t.enabled=!t.enabled);
document.getElementById('leave').onclick=async()=>{await send('leave',{});location.href='join.php?token='+encodeURIComponent(TOKEN);};
</script></body></html>
