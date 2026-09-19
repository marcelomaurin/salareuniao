<?php
require __DIR__.'/lib/bootstrap.php';
$token=$_GET['token']??'';
$st=$pdo->prepare("SELECT i.*,r.name room_name,r.status room_status FROM room_invites i JOIN rooms r ON r.id=i.room_id WHERE i.token=? AND i.status='approved' LIMIT 1");
$st->execute([$token]);$me=$st->fetch();
if(!$me){http_response_code(403);exit('Entrada não autorizada.');}
if($me['room_status']!=='open')exit('A sala ainda não foi aberta pelo administrador.');
$ice=json_encode($config['webrtc']['ice_servers']??[],JSON_UNESCAPED_SLASHES);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($me['room_name'])?> - Sala Reunião</title>
<style>
:root{font-family:Arial,Helvetica,sans-serif;color:#1f2937;background:#f4f6f8}
*{box-sizing:border-box}body{margin:0}.top{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px 18px;background:#fff;border-bottom:1px solid #ddd;position:sticky;top:0;z-index:10}
.top h1{font-size:20px;margin:0}.top small{display:block;color:#6b7280;margin-top:3px}
.layout{display:grid;grid-template-columns:minmax(0,1fr) 280px;min-height:calc(100vh - 122px)}
.main{padding:14px}.sidebar{background:#fff;border-left:1px solid #ddd;padding:14px;overflow:auto}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;align-items:start}
.tile{position:relative;background:#111827;border-radius:12px;overflow:hidden;min-height:190px;box-shadow:0 2px 8px #0002}
.tile video{width:100%;height:100%;min-height:190px;max-height:52vh;object-fit:cover;display:block;background:#111827}
.tile .name{position:absolute;left:9px;bottom:9px;background:#0009;color:white;padding:5px 8px;border-radius:6px;font-size:13px}
.tile .state{position:absolute;right:9px;top:9px;background:#0009;color:white;padding:5px 7px;border-radius:6px;font-size:12px}
.controls{display:flex;justify-content:center;gap:9px;flex-wrap:wrap;padding:14px;background:#fff;border-top:1px solid #ddd;position:sticky;bottom:0}
button{border:0;border-radius:9px;padding:10px 15px;cursor:pointer;background:#e5e7eb}button.active{background:#dbeafe}button.danger{background:#dc2626;color:white}
.person{padding:9px 0;border-bottom:1px solid #eee}.person .badges{font-size:12px;color:#6b7280;margin-top:3px}
.dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#22c55e;margin-right:6px}.empty{color:#6b7280;font-size:14px}
#status{font-size:13px;color:#6b7280}
@media(max-width:800px){.layout{grid-template-columns:1fr}.sidebar{border-left:0;border-top:1px solid #ddd}.tile video{max-height:42vh}}
</style>
</head>
<body>
<div class="top">
  <div><h1><?=e($me['room_name'])?></h1><small><?=e($me['display_name']?:$me['email'])?></small></div>
  <div id="status">Conectando...</div>
</div>

<div class="layout">
  <main class="main">
    <div class="grid" id="videos">
      <div class="tile" id="tile-local">
        <video id="local" autoplay muted playsinline></video>
        <div class="name">Você</div>
        <div class="state" id="localState">🎙 📹</div>
      </div>
    </div>
  </main>
  <aside class="sidebar">
    <h3>Participantes (<span id="participantCount">1</span>)</h3>
    <div id="participants"><div class="empty">Carregando...</div></div>
  </aside>
</div>

<div class="controls">
  <button id="mic" class="active">🎙 Microfone</button>
  <button id="cam" class="active">📹 Câmera</button>
  <button id="screen">🖥 Compartilhar tela</button>
  <button id="leave" class="danger">Sair</button>
</div>

<script>
const TOKEN=<?=json_encode($token)?>;
const ICE_SERVERS=<?=$ice?>;
const pcs=new Map(), pendingIce=new Map(), participantNames=new Map();
let lastId=0,selfKey=null,localStream=null,cameraTrack=null,screenTrack=null;
let micEnabled=true,camEnabled=true,screenSharing=false,leaving=false;

async function jsonFetch(url,options={}){
  const r=await fetch(url,options);
  if(!r.ok) throw new Error('HTTP '+r.status);
  return r.json();
}
async function send(type,payload={},recipient=null){
  return jsonFetch('api/signal_send.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token:TOKEN,type,payload,recipient})});
}
function updateButtons(){
  document.getElementById('mic').classList.toggle('active',micEnabled);
  document.getElementById('cam').classList.toggle('active',camEnabled);
  document.getElementById('screen').classList.toggle('active',screenSharing);
  document.getElementById('mic').textContent=(micEnabled?'🎙':'🔇')+' Microfone';
  document.getElementById('cam').textContent=(camEnabled?'📹':'🚫')+' Câmera';
  document.getElementById('screen').textContent=screenSharing?'🖥 Parar compartilhamento':'🖥 Compartilhar tela';
  document.getElementById('localState').textContent=(micEnabled?'🎙':'🔇')+' '+(camEnabled?'📹':'🚫')+(screenSharing?' 🖥':'');
}
function ensureTile(key){
  let tile=document.getElementById('tile-'+key);
  if(tile) return tile;
  tile=document.createElement('div');tile.className='tile';tile.id='tile-'+key;
  const video=document.createElement('video');video.id='peer-'+key;video.autoplay=true;video.playsInline=true;
  const name=document.createElement('div');name.className='name';name.id='name-'+key;name.textContent=participantNames.get(key)||'Participante';
  const state=document.createElement('div');state.className='state';state.id='state-'+key;state.textContent='Conectando...';
  tile.append(video,name,state);document.getElementById('videos').appendChild(tile);return tile;
}
function removePeer(key){
  pcs.get(key)?.close();pcs.delete(key);pendingIce.delete(key);
  document.getElementById('tile-'+key)?.remove();
}
function peer(key){
  if(pcs.has(key)) return pcs.get(key);
  const pc=new RTCPeerConnection({iceServers:ICE_SERVERS});
  if(localStream) localStream.getAudioTracks().forEach(t=>pc.addTrack(t,localStream));
  const videoToSend=screenTrack||cameraTrack;
  if(videoToSend) pc.addTrack(videoToSend,localStream);
  pc.onicecandidate=e=>{if(e.candidate)send('ice',e.candidate,key).catch(console.warn);};
  pc.ontrack=e=>{
    ensureTile(key);
    const v=document.getElementById('peer-'+key);
    if(e.streams&&e.streams[0]) v.srcObject=e.streams[0];
  };
  pc.onconnectionstatechange=()=>{
    const state=document.getElementById('state-'+key);
    if(state) state.textContent=pc.connectionState;
    if(['failed','closed'].includes(pc.connectionState)) removePeer(key);
  };
  pcs.set(key,pc);return pc;
}
async function flushIce(key){
  const pc=pcs.get(key);if(!pc||!pc.remoteDescription)return;
  const queue=pendingIce.get(key)||[];
  while(queue.length){try{await pc.addIceCandidate(queue.shift());}catch(e){console.warn('ICE',e);}}
  pendingIce.delete(key);
}
async function makeOffer(key){
  if(key===selfKey)return;
  const pc=peer(key);
  const offer=await pc.createOffer();
  await pc.setLocalDescription(offer);
  await send('offer',offer,key);
}
async function handleSignal(m){
  const key=m.sender_key,p=m.payload||{};
  if(m.message_type==='peer-ready'){
    if(selfKey&&selfKey<key) await makeOffer(key);
    return;
  }
  if(m.message_type==='leave'){
    if(p.removed){alert('Você foi removido da reunião pelo administrador.');await leaveRoom(false);return;}
    removePeer(key);return;
  }
  if(m.message_type==='offer'){
    const pc=peer(key);await pc.setRemoteDescription(new RTCSessionDescription(p));await flushIce(key);
    const ans=await pc.createAnswer();await pc.setLocalDescription(ans);await send('answer',ans,key);return;
  }
  if(m.message_type==='answer'){
    const pc=peer(key);await pc.setRemoteDescription(new RTCSessionDescription(p));await flushIce(key);return;
  }
  if(m.message_type==='ice'){
    const candidate=new RTCIceCandidate(p),pc=peer(key);
    if(pc.remoteDescription){try{await pc.addIceCandidate(candidate);}catch(e){console.warn(e);}}
    else{const q=pendingIce.get(key)||[];q.push(candidate);pendingIce.set(key,q);}
  }
}
async function pollSignals(){
  if(leaving)return;
  try{
    const d=await jsonFetch('api/signal_poll.php?token='+encodeURIComponent(TOKEN)+'&after='+lastId,{cache:'no-store'});
    selfKey=d.self;
    for(const m of d.messages){lastId=Math.max(lastId,Number(m.id));await handleSignal(m);}
  }catch(e){document.getElementById('status').textContent='Reconectando sinalização...';}
  setTimeout(pollSignals,700);
}
async function heartbeat(){
  if(leaving)return;
  try{
    const d=await jsonFetch('api/presence.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token:TOKEN,mic:micEnabled,cam:camEnabled,screen:screenSharing})});
    selfKey=d.self;
    if(d.room_status!=='open'){alert('A reunião foi encerrada.');await leaveRoom(false);return;}
    renderParticipants(d.participants||[]);
    document.getElementById('status').textContent='Conectado';
  }catch(e){document.getElementById('status').textContent='Reconectando...';}
  setTimeout(heartbeat,4000);
}
function renderParticipants(list){
  const wrap=document.getElementById('participants');wrap.innerHTML='';
  document.getElementById('participantCount').textContent=list.length;
  const activeKeys=new Set();
  for(const p of list){
    activeKeys.add(p.participant_key);participantNames.set(p.participant_key,p.display_name);
    const div=document.createElement('div');div.className='person';
    div.innerHTML='<div><span class="dot"></span><strong></strong></div><div class="badges"></div>';
    div.querySelector('strong').textContent=p.display_name+(p.participant_key===selfKey?' (você)':'');
    div.querySelector('.badges').textContent=(Number(p.mic_enabled)?'🎙 mic':'🔇 sem mic')+' · '+(Number(p.cam_enabled)?'📹 câmera':'🚫 sem câmera')+(Number(p.screen_sharing)?' · 🖥 tela':'');
    wrap.appendChild(div);
    const n=document.getElementById('name-'+p.participant_key);if(n)n.textContent=p.display_name;
    const s=document.getElementById('state-'+p.participant_key);if(s)s.textContent=(Number(p.mic_enabled)?'🎙':'🔇')+' '+(Number(p.cam_enabled)?'📹':'🚫')+(Number(p.screen_sharing)?' 🖥':'');
  }
  if(!list.length)wrap.innerHTML='<div class="empty">Nenhum participante online.</div>';
  for(const key of pcs.keys()) if(!activeKeys.has(key)) removePeer(key);
}
async function toggleScreen(){
  if(screenSharing){await stopScreen();return;}
  try{
    const display=await navigator.mediaDevices.getDisplayMedia({video:true,audio:false});
    screenTrack=display.getVideoTracks()[0];screenSharing=true;
    screenTrack.onended=()=>stopScreen();
    for(const pc of pcs.values()){
      const sender=pc.getSenders().find(s=>s.track&&s.track.kind==='video');
      if(sender) await sender.replaceTrack(screenTrack);
    }
    document.getElementById('local').srcObject=new MediaStream([screenTrack,...localStream.getAudioTracks()]);
    updateButtons();
  }catch(e){if(e.name!=='NotAllowedError')alert('Não foi possível compartilhar a tela: '+e.message);}
}
async function stopScreen(){
  if(!screenSharing)return;
  screenSharing=false;
  if(screenTrack){screenTrack.onended=null;screenTrack.stop();screenTrack=null;}
  for(const pc of pcs.values()){
    const sender=pc.getSenders().find(s=>s.track&&s.track.kind==='video');
    if(sender) await sender.replaceTrack(cameraTrack);
  }
  document.getElementById('local').srcObject=localStream;updateButtons();
}
async function leaveRoom(navigate=true){
  if(leaving)return;leaving=true;
  try{await jsonFetch('api/leave.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token:TOKEN})});}catch(e){}
  for(const pc of pcs.values())pc.close();pcs.clear();
  localStream?.getTracks().forEach(t=>t.stop());screenTrack?.stop();
  if(navigate)location.href='join.php?token='+encodeURIComponent(TOKEN);
  else location.href='join.php?token='+encodeURIComponent(TOKEN);
}
document.getElementById('mic').onclick=()=>{micEnabled=!micEnabled;localStream?.getAudioTracks().forEach(t=>t.enabled=micEnabled);updateButtons();};
document.getElementById('cam').onclick=()=>{camEnabled=!camEnabled;if(cameraTrack)cameraTrack.enabled=camEnabled;updateButtons();};
document.getElementById('screen').onclick=toggleScreen;
document.getElementById('leave').onclick=()=>leaveRoom(true);

window.addEventListener('beforeunload',()=>{
  if(leaving)return;
  const blob=new Blob([JSON.stringify({token:TOKEN})],{type:'application/json'});
  navigator.sendBeacon('api/leave.php',blob);
});

(async()=>{
  try{
    localStream=await navigator.mediaDevices.getUserMedia({video:true,audio:true});
    cameraTrack=localStream.getVideoTracks()[0]||null;
    document.getElementById('local').srcObject=localStream;updateButtons();
    const first=await jsonFetch('api/presence.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token:TOKEN,mic:true,cam:true,screen:false})});
    selfKey=first.self;renderParticipants(first.participants||[]);
    await send('peer-ready',{});
    document.getElementById('status').textContent='Conectado';
    pollSignals();setTimeout(heartbeat,1200);
  }catch(e){
    document.getElementById('status').textContent='Erro de mídia';
    alert('Não foi possível acessar câmera/microfone: '+e.message+'\nVerifique permissões e HTTPS.');
  }
})();
</script>
</body>
</html>
