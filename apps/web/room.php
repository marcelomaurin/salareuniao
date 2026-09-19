<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
$token = $_GET['token'] ?? '';
$st = $pdo->prepare("SELECT i.*, r.name room_name, r.status room_status FROM room_invites i JOIN rooms r ON r.id=i.room_id WHERE i.token=? AND i.status='approved' LIMIT 1");
$st->execute([$token]);
$me = $st->fetch();

if (!$me) {
    http_response_code(403);
    exit('Entrada não autorizada.');
}
if ($me['room_status'] !== 'open') {
    exit('A sala ainda não foi aberta pelo administrador ou foi encerrada.');
}

$iceServers = $config['webrtc']['ice_servers'] ?? [];
$turn = $config['webrtc']['turn'] ?? [];
if (!empty($turn['enabled']) && !empty($turn['secret']) && !empty($turn['urls'])) {
    $ttl = max(300, (int)($turn['ttl'] ?? 3600));
    $turnUsername = (string)(time() + $ttl) . ':' . $me['participant_key'];
    $turnCredential = base64_encode(hash_hmac('sha1', $turnUsername, (string)$turn['secret'], true));
    $iceServers[] = [
        'urls' => $turn['urls'],
        'username' => $turnUsername,
        'credential' => $turnCredential,
    ];
}
$ice = json_encode($iceServers, JSON_UNESCAPED_SLASHES);
$wsEnabled = !empty($config['websocket']['enabled']) && !empty($config['websocket']['public_url']);
$wsUrl = $wsEnabled ? (string)$config['websocket']['public_url'] : '';
$wsReconnect = max(500, (int)($config['websocket']['reconnect_ms'] ?? 2000));
$inviteUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/join.php?token=' . urlencode($token);
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=e($me['room_name'])?> - Maurinsoft Sala Reunião</title>
  <link rel="stylesheet" href="assets/css/salareuniao.css">
  <style>
    body {
      background: #050811;
      overflow: hidden;
      margin: 0;
      padding: 0;
      height: 100vh;
      display: flex;
      flex-direction: column;
    }

    .room-header {
      background: rgba(11, 17, 32, 0.9);
      backdrop-filter: blur(12px);
      border-bottom: 1px solid var(--border-glass);
      padding: 10px 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 16px;
      flex-wrap: nowrap;
      z-index: 100;
    }

    .room-info {
      display: flex;
      align-items: center;
      gap: 14px;
    }

    .room-title {
      font-size: 1.1rem;
      font-weight: 700;
      color: #fff;
    }

    .room-timer {
      font-family: monospace;
      font-size: 0.9rem;
      color: var(--primary);
      background: rgba(0, 210, 255, 0.1);
      padding: 3px 10px;
      border-radius: var(--radius-full);
      border: 1px solid rgba(0, 210, 255, 0.25);
    }

    .room-main-layout {
      display: flex;
      flex: 1;
      height: calc(100vh - 60px);
      overflow: hidden;
      position: relative;
    }

    .room-stage {
      flex: 1;
      display: flex;
      flex-direction: column;
      padding: 16px;
      position: relative;
      overflow-y: auto;
    }

    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 14px;
      width: 100%;
      height: 100%;
      max-height: calc(100vh - 170px);
      align-items: center;
      justify-content: center;
    }

    .tile {
      position: relative;
      background: #0b1120;
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 14px;
      overflow: hidden;
      min-height: 200px;
      aspect-ratio: 16/9;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.5);
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.2s ease;
    }

    .tile video {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
      background: #0b1120;
    }

    .tile .name {
      position: absolute;
      left: 10px;
      bottom: 10px;
      background: rgba(0, 0, 0, 0.7);
      backdrop-filter: blur(8px);
      color: #fff;
      padding: 5px 10px;
      border-radius: 8px;
      font-size: 0.8rem;
      font-weight: 600;
      border: 1px solid rgba(255, 255, 255, 0.1);
      z-index: 2;
    }

    .tile .state {
      position: absolute;
      right: 10px;
      top: 10px;
      background: rgba(0, 210, 255, 0.2);
      color: var(--primary);
      padding: 4px 8px;
      border-radius: 6px;
      font-size: 0.72rem;
      font-weight: 700;
      letter-spacing: 0.05em;
      border: 1px solid rgba(0, 210, 255, 0.4);
      z-index: 2;
    }

    /* Floating Bottom Dock */
    .controls {
      position: absolute;
      bottom: 22px;
      left: 50%;
      transform: translateX(-50%);
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 10px 20px;
      background: rgba(15, 23, 42, 0.9);
      backdrop-filter: blur(20px);
      border: 1px solid rgba(255, 255, 255, 0.15);
      border-radius: 9999px;
      box-shadow: 0 10px 35px rgba(0, 0, 0, 0.7);
      z-index: 80;
    }

    .controls button {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 18px;
      border-radius: 9999px;
      border: 1px solid transparent;
      font-family: var(--font-body);
      font-size: 0.88rem;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
      background: rgba(255, 255, 255, 0.08);
      color: #fff;
    }

    .controls button:hover {
      background: rgba(255, 255, 255, 0.18);
      transform: translateY(-2px);
    }

    .controls button.active {
      background: rgba(0, 210, 255, 0.2);
      border-color: rgba(0, 210, 255, 0.4);
      color: var(--primary);
    }

    .controls button.muted, .controls button.danger {
      background: #dc2626;
      border-color: #ef4444;
      color: #fff;
    }

    .controls button.muted:hover, .controls button.danger:hover {
      background: #b91c1c;
    }

    /* Sidebar Drawer */
    .sidebar {
      width: 360px;
      background: rgba(11, 17, 32, 0.95);
      backdrop-filter: blur(16px);
      border-left: 1px solid var(--border-glass);
      display: flex;
      flex-direction: column;
      z-index: 70;
      transition: all 0.25s ease;
    }

    .sidebar.sr-hidden {
      display: none !important;
    }

    .tabs {
      display: flex;
      border-bottom: 1px solid var(--border-glass);
    }

    .tabs button {
      flex: 1;
      padding: 14px 10px;
      background: none;
      border: none;
      color: var(--text-muted);
      font-size: 0.88rem;
      font-weight: 600;
      cursor: pointer;
      border-bottom: 2px solid transparent;
      transition: all 0.2s;
    }

    .tabs button.active {
      color: var(--primary);
      border-bottom-color: var(--primary);
      background: rgba(0, 210, 255, 0.05);
    }

    .sidepane {
      display: none;
      padding: 16px;
      flex: 1;
      overflow-y: auto;
      min-height: 0;
    }

    .sidepane.active {
      display: flex;
      flex-direction: column;
    }

    .chatWrap {
      display: flex;
      flex-direction: column;
      height: 100%;
    }

    .chatMessages {
      flex: 1;
      overflow-y: auto;
      display: flex;
      flex-direction: column;
      gap: 10px;
      padding-bottom: 12px;
      max-height: calc(100vh - 220px);
    }

    .chatMsg {
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid var(--border-glass);
      border-radius: 10px;
      padding: 10px 12px;
      font-size: 0.88rem;
      max-width: 90%;
      align-self: flex-start;
    }

    .chatMsg.self {
      background: rgba(0, 210, 255, 0.12);
      border-color: rgba(0, 210, 255, 0.3);
      align-self: flex-end;
    }

    .chatMeta {
      font-size: 0.72rem;
      color: var(--text-muted);
      margin-bottom: 4px;
    }

    .chatText {
      white-space: pre-wrap;
      word-break: break-word;
      color: #f1f5f9;
    }

    .chatForm {
      display: flex;
      gap: 8px;
      margin-top: auto;
      padding-top: 10px;
      border-top: 1px solid var(--border-glass);
    }

    .chatForm textarea {
      flex: 1;
      resize: none;
      height: 50px;
      background: rgba(15, 23, 42, 0.6);
      border: 1px solid var(--border-glass);
      border-radius: 8px;
      padding: 10px;
      color: #fff;
      font-family: var(--font-body);
      font-size: 0.88rem;
    }

    .chatForm textarea:focus {
      outline: none;
      border-color: var(--primary);
    }

    .person {
      padding: 10px 12px;
      border-bottom: 1px solid var(--border-glass);
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-radius: 6px;
    }

    .person:hover {
      background: rgba(255, 255, 255, 0.03);
    }

    .person .badges {
      font-size: 0.75rem;
      color: var(--text-muted);
      margin-top: 2px;
    }

    .dot {
      display: inline-block;
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: var(--accent-green);
      margin-right: 8px;
      box-shadow: 0 0 8px rgba(16, 185, 129, 0.6);
    }

    @media (max-width: 900px) {
      .sidebar {
        position: absolute;
        right: 0;
        top: 0;
        bottom: 0;
        width: 100%;
        max-width: 320px;
        box-shadow: -5px 0 25px rgba(0, 0, 0, 0.6);
      }
      .controls {
        width: 95%;
        padding: 8px 12px;
        gap: 6px;
      }
      .controls button {
        padding: 8px 12px;
        font-size: 0.8rem;
      }
    }
  </style>
</head>
<body>

  <!-- Top Navigation Bar -->
  <header class="room-header">
    <div class="room-info">
      <div class="sr-brand-logo" style="width: 30px; height: 30px; font-size: 14px;">M</div>
      <div>
        <div class="room-title"><?=e($me['room_name'])?></div>
        <small id="status" style="color: var(--text-muted); font-size: 0.78rem;">Iniciando conexão WebRTC...</small>
      </div>
    </div>

    <div style="display: flex; align-items: center; gap: 12px;">
      <span class="room-timer" id="meetingTimer">00:00:00</span>
      <span id="iceRoute" style="font-size: 0.78rem; color: var(--text-dim);"></span>
      <span class="sr-badge sr-badge-open" id="participantBadge">
        <span class="sr-pulse-dot"></span>
        <span id="participantCount">1</span> online
      </span>
      <button type="button" class="sr-btn sr-btn-secondary sr-btn-sm" onclick="copyInvite()" title="Copiar link de convite">
        📋 Convidar
      </button>
      <button type="button" class="sr-btn sr-btn-secondary sr-btn-sm" onclick="toggleSidebar()" title="Bate-papo e Participantes">
        💬 Painel
      </button>
    </div>
  </header>

  <!-- Main Viewport -->
  <div class="room-main-layout">
    
    <!-- Stage Area -->
    <main class="room-stage">
      <div class="grid" id="videos">
        <div class="tile" id="tile-local">
          <video id="local" autoplay muted playsinline></video>
          <div class="name">Você (<?=e($me['display_name'])?>)</div>
          <div class="state" id="localState">AO VIVO</div>
        </div>
      </div>
    </main>

    <!-- Sidebar Drawer (Chat & Participants) -->
    <aside class="sidebar" id="roomSidebar">
      <div class="tabs">
        <button id="tabChat" class="active">Chat <span id="chatUnread" class="sr-badge sr-badge-scheduled" style="display:none; padding: 1px 6px; font-size: 10px;">0</span></button>
        <button id="tabParticipants">Participantes</button>
      </div>

      <!-- Chat Pane -->
      <div id="paneChat" class="sidepane active">
        <div class="chatWrap">
          <div class="chatMessages" id="chatMessages">
            <div class="chatMsg" style="background: rgba(0, 210, 255, 0.08); border: 1px dashed rgba(0, 210, 255, 0.2);">
              <div class="chatMeta">Sistema Maurinsoft</div>
              <div class="chatText">Bem-vindo(a) à sala de reunião criptografada ponta-a-ponta. As mensagens enviadas aqui são visíveis para todos os participantes da sessão.</div>
            </div>
          </div>
          <form id="chatForm" class="chatForm">
            <textarea id="chatInput" placeholder="Digite uma mensagem e pressione Enter..." rows="2"></textarea>
            <button type="submit" class="sr-btn sr-btn-primary sr-btn-sm" style="align-self: flex-end; height: 50px;">
              Enviar
            </button>
          </form>
        </div>
      </div>

      <!-- Participants Pane -->
      <div id="paneParticipants" class="sidepane">
        <div style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 12px;">
          Participantes conectados nesta chamada:
        </div>
        <div id="participants"></div>
      </div>
    </aside>

  </div>

  <!-- Floating Control Dock -->
  <div class="controls">
    <button id="mic" class="active" title="Ativar/Desativar Microfone">
      <span id="micIcon">🎙️</span> <span id="micText">Mic Ligado</span>
    </button>
    <button id="cam" class="active" title="Ativar/Desativar Câmera">
      <span id="camIcon">📹</span> <span id="camText">Câmera Ligada</span>
    </button>
    <button id="screen" title="Compartilhar Tela do Computador">
      🖥️ Compartilhar Tela
    </button>
    <button id="leave" class="danger" title="Encerrar ou Sair da Reunião">
      🚪 Sair da Sala
    </button>
  </div>

  <!-- Toast Notification -->
  <div id="sr-toast">Link de convite copiado para a área de transferência!</div>

  <script>
    // Meeting Timer
    let meetingSeconds = 0;
    setInterval(() => {
      meetingSeconds++;
      const hrs = String(Math.floor(meetingSeconds / 3600)).padStart(2, '0');
      const mins = String(Math.floor((meetingSeconds % 3600) / 60)).padStart(2, '0');
      const secs = String(meetingSeconds % 60).padStart(2, '0');
      const el = document.getElementById('meetingTimer');
      if (el) el.textContent = hrs + ':' + mins + ':' + secs;
    }, 1000);

    function copyInvite() {
      const url = <?=json_encode($inviteUrl)?>;
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(url).then(showToast);
      } else {
        const input = document.createElement('input');
        input.value = url;
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        document.body.removeChild(input);
        showToast();
      }
    }

    function showToast() {
      const toast = document.getElementById('sr-toast');
      if (toast) {
        toast.classList.add('show');
        setTimeout(() => toast.classList.remove('show'), 3000);
      }
    }

    function toggleSidebar() {
      const sb = document.getElementById('roomSidebar');
      if (sb) sb.classList.toggle('sr-hidden');
    }
  </script>

  <script>

const TOKEN=<?=json_encode($token)?>;
const ICE_SERVERS=<?=$ice?>;
const WS_URL=<?=json_encode($wsUrl)?>;
const WS_ENABLED=<?=json_encode($wsEnabled)?>;
const WS_RECONNECT_MS=<?=$wsReconnect?>;
const pcs=new Map(), pendingIce=new Map(), participantNames=new Map();
let lastId=0,selfKey=null,localStream=null,cameraTrack=null,screenTrack=null;
let micEnabled=true,camEnabled=true,screenSharing=false,leaving=false,lastChatId=0,chatOpen=false,chatUnread=0,ws=null,wsReady=false,wsReconnectTimer=null;

async function jsonFetch(url,options={}){
  const r=await fetch(url,options);
  if(!r.ok) throw new Error('HTTP '+r.status);
  return r.json();
}
async function send(type,payload={},recipient=null){
  if(wsReady&&ws){
    ws.send(JSON.stringify({type:'signal',signalType:type,payload,recipient}));
    return {ok:true,transport:'websocket'};
  }
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
async function updateIceRoute(key,pc){
  try{
    const stats=await pc.getStats();
    let transport=null,pair=null,local=null,remote=null;
    stats.forEach(r=>{if(r.type==='transport'&&r.selectedCandidatePairId)transport=r;});
    if(transport)pair=stats.get(transport.selectedCandidatePairId);
    if(!pair){
      stats.forEach(r=>{if(r.type==='candidate-pair'&&r.state==='succeeded'&&r.nominated)pair=r;});
    }
    if(pair){
      local=stats.get(pair.localCandidateId);remote=stats.get(pair.remoteCandidateId);
      const lt=local?.candidateType||'?';
      const rt=remote?.candidateType||'?';
      const via=(lt==='relay'||rt==='relay')?'TURN relay':'P2P direto';
      document.getElementById('iceRoute').textContent='ICE: '+via+' ('+lt+' ↔ '+rt+')';
      const state=document.getElementById('state-'+key);
      if(state)state.dataset.route=via;
    }
  }catch(e){console.warn('ICE stats',e);}
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
    if(pc.connectionState==='connected')updateIceRoute(key,pc);
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
  if(leaving||wsReady)return;
  try{
    const d=await jsonFetch('api/signal_poll.php?token='+encodeURIComponent(TOKEN)+'&after='+lastId,{cache:'no-store'});
    selfKey=d.self;
    for(const m of d.messages){lastId=Math.max(lastId,Number(m.id));await handleSignal(m);}
  }catch(e){document.getElementById('status').textContent='Reconectando sinalização...';}
  setTimeout(pollSignals,700);
}
function setSideTab(tab){
  chatOpen=tab==='chat';
  document.getElementById('paneParticipants').classList.toggle('active',!chatOpen);
  document.getElementById('paneChat').classList.toggle('active',chatOpen);
  document.getElementById('tabParticipants').classList.toggle('active',!chatOpen);
  document.getElementById('tabChat').classList.toggle('active',chatOpen);
  if(chatOpen){chatUnread=0;updateUnread();setTimeout(()=>{const w=document.getElementById('chatMessages');w.scrollTop=w.scrollHeight;},0);}
}
function updateUnread(){document.getElementById('chatUnread').textContent=chatUnread?('('+chatUnread+')'):'';}
function appendChatMessage(m){
  const wrap=document.getElementById('chatMessages');
  if(wrap.querySelector('.empty'))wrap.innerHTML='';
  if(document.getElementById('chat-'+m.id))return;
  const div=document.createElement('div');div.className='chatMsg'+(m.participant_key===selfKey?' self':'');div.id='chat-'+m.id;
  const meta=document.createElement('div');meta.className='chatMeta';meta.textContent=m.display_name+' · '+String(m.created_at||'').slice(11,16);
  const text=document.createElement('div');text.className='chatText';text.textContent=m.message;
  div.append(meta,text);wrap.appendChild(div);lastChatId=Math.max(lastChatId,Number(m.id)||0);
  if(chatOpen)wrap.scrollTop=wrap.scrollHeight;else if(m.participant_key!==selfKey){chatUnread++;updateUnread();}
}
async function loadChatHistory(){
  try{const d=await jsonFetch('api/chat_history.php?token='+encodeURIComponent(TOKEN)+'&limit=100',{cache:'no-store'});selfKey=d.self;for(const m of d.messages||[])appendChatMessage(m);chatUnread=0;updateUnread();}catch(e){console.warn('chat history',e);}
}
async function pollChat(){
  if(leaving||wsReady)return;
  try{const d=await jsonFetch('api/chat_poll.php?token='+encodeURIComponent(TOKEN)+'&after='+lastChatId,{cache:'no-store'});for(const m of d.messages||[])appendChatMessage(m);}catch(e){console.warn('chat poll',e);}
  setTimeout(pollChat,1200);
}
async function sendChatMessage(text){
  const msg=text.trim();if(!msg)return;
  if(wsReady&&ws){
    ws.send(JSON.stringify({type:'chat',message:msg}));
    return;
  }
  const d=await jsonFetch('api/chat_send.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token:TOKEN,message:msg})});
  if(d.message)appendChatMessage(d.message);
}
async function heartbeat(){
  if(leaving)return;
  if(wsReady&&ws){
    try{
      ws.send(JSON.stringify({type:'presence',mic:micEnabled,cam:camEnabled,screen:screenSharing}));
      document.getElementById('status').textContent='Conectado em tempo real';
    }catch(e){wsReady=false;}
  }else{
    try{
      const d=await jsonFetch('api/presence.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token:TOKEN,mic:micEnabled,cam:camEnabled,screen:screenSharing})});
      selfKey=d.self;
      if(d.room_status!=='open'){alert('A reunião foi encerrada.');await leaveRoom(false);return;}
      renderParticipants(d.participants||[]);
      document.getElementById('status').textContent='Conectado (fallback)';
    }catch(e){document.getElementById('status').textContent='Reconectando...';}
  }
  for(const [key,pc] of pcs) if(pc.connectionState==='connected') updateIceRoute(key,pc);
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
function connectWebSocket(){
  if(!WS_ENABLED||!WS_URL||leaving)return;
  try{
    ws=new WebSocket(WS_URL+(WS_URL.includes('?')?'&':'?')+'token='+encodeURIComponent(TOKEN));
    ws.onopen=()=>{
      wsReady=true;
      document.getElementById('status').textContent='Conectado em tempo real';
      if(wsReconnectTimer){clearTimeout(wsReconnectTimer);wsReconnectTimer=null;}
      ws.send(JSON.stringify({type:'presence',mic:micEnabled,cam:camEnabled,screen:screenSharing}));
      ws.send(JSON.stringify({type:'signal',signalType:'peer-ready',payload:{},recipient:null}));
    };
    ws.onmessage=async ev=>{
      try{
        const d=JSON.parse(ev.data);
        if(d.type==='hello'){selfKey=d.self||selfKey;return;}
        if(d.type==='signal'&&d.message){
          if(d.message.id)lastId=Math.max(lastId,Number(d.message.id));
          await handleSignal(d.message);
          return;
        }
        if(d.type==='chat'&&d.message){appendChatMessage(d.message);return;}
        if(d.type==='presence'){renderParticipants(d.participants||[]);return;}
        if(d.type==='session-ended'){
          alert('Sua participação foi encerrada ou a reunião foi fechada.');
          await leaveRoom(false);
          return;
        }
        if(d.type==='error'){console.warn('WebSocket',d.error);}
      }catch(e){console.warn('WS message',e);}
    };
    ws.onclose=()=>{
      wsReady=false;
      document.getElementById('status').textContent='WebSocket desconectado; usando fallback';
      pollSignals();pollChat();
      if(!leaving)wsReconnectTimer=setTimeout(connectWebSocket,WS_RECONNECT_MS);
    };
    ws.onerror=()=>{wsReady=false;};
  }catch(e){
    wsReady=false;
    if(!leaving)wsReconnectTimer=setTimeout(connectWebSocket,WS_RECONNECT_MS);
  }
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
  try{
    if(wsReady&&ws)ws.send(JSON.stringify({type:'signal',signalType:'leave',payload:{},recipient:null}));
    await jsonFetch('api/leave.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token:TOKEN})});
  }catch(e){}
  try{ws?.close();}catch(e){}
  for(const pc of pcs.values())pc.close();pcs.clear();
  localStream?.getTracks().forEach(t=>t.stop());screenTrack?.stop();
  if(navigate)location.href='join.php?token='+encodeURIComponent(TOKEN);
  else location.href='join.php?token='+encodeURIComponent(TOKEN);
}
document.getElementById('mic').onclick=()=>{micEnabled=!micEnabled;localStream?.getAudioTracks().forEach(t=>t.enabled=micEnabled);updateButtons();};
document.getElementById('cam').onclick=()=>{camEnabled=!camEnabled;if(cameraTrack)cameraTrack.enabled=camEnabled;updateButtons();};
document.getElementById('screen').onclick=toggleScreen;
document.getElementById('leave').onclick=()=>leaveRoom(true);
document.getElementById('tabParticipants').onclick=()=>setSideTab('participants');
document.getElementById('tabChat').onclick=()=>setSideTab('chat');
document.getElementById('chatForm').addEventListener('submit',async e=>{
  e.preventDefault();const input=document.getElementById('chatInput');const value=input.value;if(!value.trim())return;
  input.disabled=true;
  try{await sendChatMessage(value);input.value='';}catch(err){alert('Não foi possível enviar a mensagem.');}
  finally{input.disabled=false;input.focus();}
});
document.getElementById('chatInput').addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();document.getElementById('chatForm').requestSubmit();}});

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
    await loadChatHistory();
    connectWebSocket();
    if(!WS_ENABLED){await send('peer-ready',{});pollSignals();pollChat();}
    document.getElementById('status').textContent=WS_ENABLED?'Conectando WebSocket...':'Conectado (fallback)';
    setTimeout(heartbeat,1200);
  }catch(e){
    document.getElementById('status').textContent='Erro de mídia';
    alert('Não foi possível acessar câmera/microfone: '+e.message+'\nVerifique permissões e HTTPS.');
  }
})();

  </script>
</body>
</html>
