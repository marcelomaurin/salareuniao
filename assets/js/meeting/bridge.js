/**
 * assets/js/meeting/bridge.js
 * 
 * PHP Media Bridge Client (Tarefas 39 a 78)
 * - Relay experimental de mídia para contornar falhas de rede WebRTC Mesh
 * - Publicação de vídeo/áudio codificado nativamente pelo navegador via MediaRecorder
 * - Transmissão binária direta via WebSocket (zero base64) para o PHP Ratchet
 * - Cabeçalho binário mínimo de 8 bytes (timestamp Float64) para medição de latência fim-a-fim
 * - Reprodução nos espectadores via MediaSource Extensions (MSE) com fila sequencial e descarte de atraso
 * - Fallback automático acionado após falhas persistentes de WebRTC
 */
(function(window) {
  'use strict';

  let ws = null;
  let isConnecting = false;
  let isPublishing = false;
  let recorder = null;
  let currentStreamId = null;
  let activeMediaType = 'camera';
  let currentChunkSeq = 0;
  let isSendingChunk = false;
  const chunkQueue = [];
  const activePullLoops = new Map(); // remoteKey => { running: true, lastSeq: -1 }

  // Subscrições e reprodutores MSE remotos:
  // publisherKey => { mediaSource, sourceBuffer, queue: [], videoEl, mime, isReady }
  const remotePlayers = new Map();

  // Chaves de participantes usando transporte bridge como fallback
  const activeFallbacks = new Set();

  // Estatísticas de diagnósticos (Tarefas 61, 77, 78)
  const stats = {
    bytesSent: 0,
    bytesReceived: 0,
    chunksSent: 0,
    chunksReceived: 0,
    droppedChunks: 0,
    latencyMs: 0,
    bitrateKbps: 0,
    activePublisherKey: null,
    transportMode: 'webrtc' // 'webrtc' | 'bridge'
  };

  // Cálculo contínuo de bitrate a cada 2 segundos
  let lastByteCount = 0;
  let lastBitrateCalc = Date.now();
  setInterval(() => {
    const now = Date.now();
    const elapsed = (now - lastBitrateCalc) / 1000;
    if (elapsed >= 1) {
      const bytes = (stats.bytesReceived + stats.bytesSent) - lastByteCount;
      stats.bitrateKbps = Math.max(0, Math.round((bytes * 8) / (elapsed * 1000)));
      lastByteCount = stats.bytesReceived + stats.bytesSent;
      lastBitrateCalc = now;
    }
  }, 2000);

  function getCfg() {
    return window.MEETING_CONFIG || {};
  }

  // Tarefa 48: Testa codecs suportados pelo navegador
  function getSupportedMimeType() {
    const types = [
      'video/webm;codecs=vp8,opus',
      'video/webm;codecs=vp9,opus',
      'video/webm',
      'video/mp4;codecs=avc1,mp4a.40.2'
    ];
    for (const t of types) {
      if (window.MediaRecorder && typeof MediaRecorder.isTypeSupported === 'function' && MediaRecorder.isTypeSupported(t)) {
        return t;
      }
    }
    return 'video/webm';
  }

  // Tarefas 42 e 44: Conexão segura ao endpoint do Bridge com token
  function getBridgeUrl() {
    const cfg = getCfg();
    if (cfg.BRIDGE_URL) {
      const url = new URL(cfg.BRIDGE_URL, window.location.href);
      if (cfg.TOKEN) {
        url.searchParams.set('token', cfg.TOKEN);
      }
      return url.toString();
    }
    const loc = window.location;
    const proto = (loc.protocol === 'https:') ? 'wss:' : 'ws:';
    const base = cfg.baseUrl || (loc.pathname.substring(0, loc.pathname.lastIndexOf('/')));
    const cleanBase = base.replace(/\/+$/, '');
    return `${proto}//${loc.host}${cleanBase}/bridge?token=${encodeURIComponent(cfg.TOKEN || '')}`;
  }

  function connect() {
    if (ws && (ws.readyState === WebSocket.OPEN || ws.readyState === WebSocket.CONNECTING)) {
      return Promise.resolve();
    }
    if (isConnecting) {
      return new Promise((resolve) => setTimeout(resolve, 500));
    }

    isConnecting = true;
    const url = getBridgeUrl();

    return new Promise((resolve, reject) => {
      try {
        ws = new WebSocket(url);
        ws.binaryType = 'arraybuffer'; // Tarefa 50: Frames binários diretos (ArrayBuffer)

        ws.onopen = () => {
          isConnecting = false;
          console.log('[MeetingBridge] Conectado ao canal WebSocket do Bridge.');
          if (window.rtcLog) window.rtcLog('bridge', 'BRIDGE_CONNECTED');
          resolve();
        };

        ws.onmessage = (event) => {
          handleIncomingData(event.data);
        };

        ws.onerror = (err) => {
          console.warn('[MeetingBridge] Erro de conexão:', err);
          if (window.rtcLog) window.rtcLog('bridge', 'BRIDGE_FAILED');
        };

        ws.onclose = () => {
          isConnecting = false;
          ws = null;
          console.log('[MeetingBridge] Desconectado do Bridge.');
          stopPublishing();
        };
      } catch (err) {
        isConnecting = false;
        reject(err);
      }
    });
  }

  function disconnect() {
    stopPublishing();
    if (ws) {
      try { ws.close(); } catch(e) {}
      ws = null;
    }
    remotePlayers.clear();
  }

  function isConnected() {
    return ws !== null && ws.readyState === WebSocket.OPEN;
  }

  // Envio de chunks de audio/video para o Webservice de Encaminhamento HTTP (Hostinger)
  async function sendHttpRelayChunk(buffer, seq, mime, isInit) {
    const cfg = getCfg();
    const token = cfg.TOKEN;
    if (!token) return;

    chunkQueue.push({ buffer, seq, mime, isInit });
    if (chunkQueue.length > 6) {
      chunkQueue.shift();
    }
    if (isSendingChunk) return;
    isSendingChunk = true;

    while (chunkQueue.length > 0) {
      const item = chunkQueue.shift();
      try {
        const url = `api/relay_push.php?token=${encodeURIComponent(token)}&seq=${item.seq}&mime=${encodeURIComponent(item.mime)}${item.isInit ? '&init=1' : ''}`;
        await fetch(url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/octet-stream' },
          body: item.buffer
        });
      } catch (e) {}
    }
    isSendingChunk = false;
  }

  // =========================================================================
  // PUBLICAÇÃO COM MediaRecorder (Tarefas 48, 49, 50, 51, 52, 67 + HTTP Forwarding)
  // =========================================================================
  async function startPublishing(customStream = null, mediaType = 'camera') {
    if (isPublishing) return;
    const cfg = getCfg();
    const chunkMs = cfg.BRIDGE_CHUNK_MS || 250;

    let stream = customStream;
    if (!stream && window.MeetingMedia && typeof window.MeetingMedia.getLocalStream === 'function') {
      stream = window.MeetingMedia.getLocalStream();
    }
    if (!stream) {
      console.warn('[MeetingBridge] Impossivel publicar: nenhum stream local disponivel.');
      return;
    }

    if (cfg.BRIDGE_ENABLED && cfg.BRIDGE_URL) {
      connect().catch(() => {});
    }

    const mime = getSupportedMimeType();
    currentStreamId = 'stream_' + Math.random().toString(36).substring(2, 9);
    activeMediaType = mediaType;
    currentChunkSeq = 0;

    try {
      recorder = new MediaRecorder(stream, {
        mimeType: mime,
        videoBitsPerSecond: 350000,
        audioBitsPerSecond: 48000
      });

      // Anuncia inicio de publicacao no WebSocket se disponivel
      if (isConnected()) {
        try {
          ws.send(JSON.stringify({
            type: 'bridge.publish.start',
            stream_id: currentStreamId,
            media: activeMediaType,
            mime: mime
          }));
        } catch(e) {}
      }

      // Envio binario continuo: WebSocket (se disponivel) + Webservice HTTP Relay
      recorder.ondataavailable = async (e) => {
        if (!e.data || e.data.size === 0) return;
        try {
          const now = Date.now();
          const chunkBuffer = await e.data.arrayBuffer();

          // Cabecalho de 8 bytes com timestamp para medicao de latencia
          const framed = new Uint8Array(8 + chunkBuffer.byteLength);
          const dv = new DataView(framed.buffer);
          dv.setFloat64(0, now, false); // Float64 big-endian
          framed.set(new Uint8Array(chunkBuffer), 8);

          // 1. Envia via WebSocket se conectado
          if (isConnected()) {
            try { ws.send(framed.buffer); } catch(e) {}
          }

          // 2. Envia via Webservice de Encaminhamento HTTP (Hostinger)
          const isInit = (currentChunkSeq === 0);
          sendHttpRelayChunk(framed.buffer, currentChunkSeq++, mime, isInit);

          stats.bytesSent += framed.byteLength;
          stats.chunksSent++;
        } catch (bufErr) {
          console.warn('[MeetingBridge] Erro ao processar chunk binario:', bufErr);
        }
      };

      recorder.onerror = (recErr) => {
        console.error('[MeetingBridge] Erro no MediaRecorder:', recErr);
        stopPublishing();
      };

      recorder.start(chunkMs); // Chunks a cada 250ms (Tarefa 49)
      isPublishing = true;
      stats.transportMode = 'bridge';
      if (window.rtcLog) window.rtcLog('bridge', 'BRIDGE_PUBLISHING');
      console.log(`[MeetingBridge] Publicação iniciada (${mime}, chunks de ${chunkMs}ms).`);
    } catch (err) {
      console.error('[MeetingBridge] Falha ao iniciar MediaRecorder:', err);
      isPublishing = false;
    }
  }

  function stopPublishing() {
    if (!isPublishing && !recorder) return;
    isPublishing = false;
    if (recorder) {
      try {
        if (recorder.state !== 'inactive') recorder.stop();
      } catch(e) {}
      recorder = null;
    }
    if (isConnected() && currentStreamId) {
      try {
        ws.send(JSON.stringify({
          type: 'bridge.publish.stop',
          stream_id: currentStreamId
        }));
      } catch(e) {}
    }
    currentStreamId = null;
  }

  // =========================================================================
  // RECEPÇÃO E REPRODUÇÃO VIA MSE (Tarefas 58, 59, 60, 61)
  // =========================================================================
  function handleIncomingData(data) {
    // 1. Mensagens de Controle JSON
    if (typeof data === 'string') {
      try {
        const msg = JSON.parse(data);
        handleControlMessage(msg);
      } catch(e) {}
      return;
    }

    // 2. Chunks Binários de Mídia (ArrayBuffer) com cabeçalho de 8 bytes de timestamp
    if (data instanceof ArrayBuffer) {
      let mediaPayload = data;

      // Tarefa 61: Mede latência calculando receiving_time - capturing_time
      if (data.byteLength >= 8) {
        const dv = new DataView(data);
        const captureTime = dv.getFloat64(0, false);
        const now = Date.now();
        if (captureTime > 0 && captureTime <= now) {
          stats.latencyMs = Math.max(0, Math.round(now - captureTime));
        }
        mediaPayload = data.slice(8);
      }

      stats.bytesReceived += mediaPayload.byteLength;
      stats.chunksReceived++;

      // Encaminha chunk puro de mídia para o reprodutor ativo
      const pubKey = stats.activePublisherKey;
      if (pubKey && remotePlayers.has(pubKey)) {
        appendMediaChunk(pubKey, mediaPayload);
      }
    }
  }

  function handleControlMessage(msg) {
    if (!msg || !msg.type) return;

    switch (msg.type) {
      case 'bridge.connected':
        if (msg.active_publisher) {
          stats.activePublisherKey = msg.active_publisher.publisher_key;
          setupRemotePlayer(msg.active_publisher.publisher_key, msg.active_publisher.mime);
        }
        break;

      case 'bridge.publisher.ready':
        stats.activePublisherKey = msg.publisher_key;
        setupRemotePlayer(msg.publisher_key, msg.mime);
        if (window.rtcLog) window.rtcLog(msg.publisher_key, 'BRIDGE_RECEIVING');
        break;

      case 'bridge.publisher.stopped':
        if (stats.activePublisherKey === msg.publisher_key) {
          stats.activePublisherKey = null;
        }
        cleanupRemotePlayer(msg.publisher_key);
        break;
    }
  }

  function setupRemotePlayer(publisherKey, mimeType) {
    if (remotePlayers.has(publisherKey)) {
      cleanupRemotePlayer(publisherKey);
    }

    let tile = null;
    if (window.MeetingParticipants && typeof window.MeetingParticipants.resolveParticipantTile === 'function') {
      tile = window.MeetingParticipants.resolveParticipantTile(publisherKey);
    }
    if (!tile) tile = document.getElementById('tile-' + publisherKey);
    if (!tile) return;

    const videoEl = tile.querySelector('video');
    const avatarEl = tile.querySelector('.avatar');
    if (!videoEl) return;

    if (avatarEl) avatarEl.style.display = 'none';
    videoEl.style.display = 'block';

    const mime = mimeType || getSupportedMimeType();
    if (!window.MediaSource || !MediaSource.isTypeSupported(mime)) {
      console.warn(`[MeetingBridge] MSE não suporta mimeType: ${mime}`);
      return;
    }

    const mediaSource = new MediaSource();
    const playerObj = {
      mediaSource,
      sourceBuffer: null,
      queue: [],
      videoEl,
      mime,
      isReady: false
    };

    remotePlayers.set(publisherKey, playerObj);

    mediaSource.addEventListener('sourceopen', () => {
      try {
        const sb = mediaSource.addSourceBuffer(mime);
        sb.mode = 'sequence';
        playerObj.sourceBuffer = sb;
        playerObj.isReady = true;

        // Tarefa 59: Fila sequencial - alimenta o SourceBuffer no término de updateend
        sb.addEventListener('updateend', () => {
          feedSourceBuffer(playerObj);
        });

        sb.addEventListener('error', (e) => {
          console.warn('[MeetingBridge] Erro no SourceBuffer:', e);
        });
      } catch (sbErr) {
        console.warn('[MeetingBridge] Falha ao criar SourceBuffer:', sbErr);
      }
    });

    videoEl.srcObject = null;
    videoEl.src = URL.createObjectURL(mediaSource);
    videoEl.play().catch(() => {});
  }

  function appendMediaChunk(publisherKey, arrayBuffer) {
    const player = remotePlayers.get(publisherKey);
    if (!player) return;

    // Tarefa 60: Política de descarte de chunks para baixa latência
    // Se a fila passar de 8 chunks (~2 segundos de atraso), descarta os mais antigos
    if (player.queue.length > 8) {
      stats.droppedChunks += (player.queue.length - 4);
      player.queue = player.queue.slice(-4);
    }

    player.queue.push(arrayBuffer);
    if (player.isReady) {
      feedSourceBuffer(player);
    }
  }

  function feedSourceBuffer(player) {
    if (!player.sourceBuffer || player.sourceBuffer.updating || player.queue.length === 0) {
      return;
    }
    try {
      const chunk = player.queue.shift();
      player.sourceBuffer.appendBuffer(chunk);
    } catch (err) {
      // Se estourar buffer do navegador, remove faixa de tempo anterior
      if (err.name === 'QuotaExceededError' && player.sourceBuffer.buffered.length > 0) {
        try {
          player.sourceBuffer.remove(0, player.videoEl.currentTime - 1);
        } catch(rmErr) {}
      }
    }
  }

  function cleanupRemotePlayer(publisherKey) {
    const player = remotePlayers.get(publisherKey);
    if (!player) return;
    try {
      if (player.mediaSource && player.mediaSource.readyState === 'open') {
        player.mediaSource.endOfStream();
      }
    } catch(e) {}
    remotePlayers.delete(publisherKey);
  }

  // Loop de Recepcao via HTTP Long-Polling (Hostinger Relay)
  async function startHttpPullLoop(remoteKey) {
    if (activePullLoops.has(remoteKey)) return;
    const state = { running: true, lastSeq: -1 };
    activePullLoops.set(remoteKey, state);

    const cfg = getCfg();
    const token = cfg.TOKEN;
    if (!token) return;

    console.log(`[MeetingBridge] Iniciando recepcao por encaminhamento HTTP para ${remoteKey}`);

    let errCount = 0;
    while (state.running && !window.MeetingApp?.isLeaving()) {
      try {
        const url = `api/relay_pull.php?token=${encodeURIComponent(token)}&publisher_key=${encodeURIComponent(remoteKey)}&after=${state.lastSeq}`;
        const resp = await fetch(url, { cache: 'no-store' });

        if (!resp.ok) {
          errCount++;
          await new Promise(r => setTimeout(r, Math.min(2000, 300 * errCount)));
          continue;
        }

        errCount = 0;
        const contentType = resp.headers.get('Content-Type') || '';

        if (contentType.includes('octet-stream') || contentType.includes('video/webm')) {
          const isInit = resp.headers.get('X-Relay-Is-Init') === '1';
          const seq = parseInt(resp.headers.get('X-Relay-Seq') || '0', 10);
          const mime = resp.headers.get('X-Relay-Mime') || 'video/webm;codecs=vp8,opus';

          if (!remotePlayers.has(remoteKey)) {
            stats.activePublisherKey = remoteKey;
            setupRemotePlayer(remoteKey, mime);
          }

          const buffer = await resp.arrayBuffer();
          if (buffer.byteLength > 0) {
            handleIncomingData(buffer);
            state.lastSeq = seq;
          }
        } else {
          const json = await resp.json().catch(() => ({}));
          if (json && json.last_seq !== undefined && json.last_seq > state.lastSeq) {
            state.lastSeq = json.last_seq - 1;
          }
          await new Promise(r => setTimeout(r, 100));
        }
      } catch (err) {
        errCount++;
        await new Promise(r => setTimeout(r, Math.min(2000, 300 * errCount)));
      }
    }
  }

  function stopHttpPullLoop(remoteKey) {
    if (activePullLoops.has(remoteKey)) {
      activePullLoops.get(remoteKey).running = false;
      activePullLoops.delete(remoteKey);
    }
  }

  // =========================================================================
  // FALLBACK E INTEGRACAO WEBRTC COM SERVICO DE ENCAMINHAMENTO
  // =========================================================================
  async function activateFallback(remoteKey) {
    activeFallbacks.add(remoteKey);
    stats.transportMode = 'bridge';

    console.log(`[MeetingBridge] Ativando encaminhamento de midia para peer: ${remoteKey}`);
    if (window.rtcLog) window.rtcLog(remoteKey, 'BRIDGE_CONNECTING');

    // 1. Inicia gravacao e envio dos nossos frames para quem pedir nosso ID
    if (!isPublishing) {
      startPublishing();
    }

    // 2. Inicia recepcao dos frames do peer remoto (via WebSocket e/ou HTTP Relay)
    if (isConnected()) {
      subscribe(remoteKey);
    }
    startHttpPullLoop(remoteKey);

    if (window.showToast) {
      window.showToast('Encaminhamento de audio e video ativo.');
    }
  }

  function deactivateFallback(remoteKey) {
    activeFallbacks.delete(remoteKey);
    stopHttpPullLoop(remoteKey);
    if (activeFallbacks.size === 0) {
      stats.transportMode = 'webrtc';
      stopPublishing();
      if (window.rtcLog) window.rtcLog(remoteKey, 'WEBRTC_RESTORED');
      console.log(`[MeetingBridge] Conexao WebRTC restaurada. Desativando fallback.`);
    }
  }

  function subscribe(publisherKey) {
    if (isConnected()) {
      ws.send(JSON.stringify({ type: 'bridge.subscribe', publisher_key: publisherKey }));
    }
  }

  function unsubscribe(publisherKey) {
    if (isConnected()) {
      ws.send(JSON.stringify({ type: 'bridge.unsubscribe', publisher_key: publisherKey }));
    }
  }

  window.MeetingBridge = {
    connect,
    disconnect,
    startPublishing,
    stopPublishing,
    subscribe,
    unsubscribe,
    isConnected,
    isPublishing: () => isPublishing,
    activateFallback,
    deactivateFallback,
    getStats: () => {
      let totalQueueBytes = 0;
      remotePlayers.forEach(p => {
        if (p.queue) {
          totalQueueBytes += p.queue.reduce((acc, c) => acc + (c.byteLength || 0), 0);
        }
      });
      return {
        ...stats,
        queueBytes: totalQueueBytes,
        queueKb: (totalQueueBytes / 1024).toFixed(1)
      };
    },
    getTransportMode: () => stats.transportMode
  };
})(window);
