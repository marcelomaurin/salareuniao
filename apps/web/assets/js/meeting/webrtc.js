/**
 * Núcleo WebRTC Mesh da Sala Reunião
 * - Centralização em getOrCreatePeer(participantKey)
 * - Implementação estrita de Perfect Negotiation (polite = selfKey > remoteKey)
 * - Fila de ICE Candidates por peer (pendingIce)
 * - Estratégia de reconexão resiliente (restartIce e recriação sem recarregar a página)
 * - Descarte e encerramento limpo de conexões órfãs
 */
(function(window) {
  'use strict';

  const pcs = new Map();
  const peerState = new Map();
  const pendingIce = new Map();

  function getOrCreatePeer(remoteKey) {
    const cfg = window.MEETING_CONFIG;
    if (!remoteKey || remoteKey === cfg.selfKey || window.MeetingApp?.isLeaving()) {
      return null;
    }

    // 1. Verifica se a conexão já existe
    if (pcs.has(remoteKey)) {
      return pcs.get(remoteKey);
    }

    window.rtcLog(remoteKey, 'peer-created');

    // 2. Cria RTCPeerConnection com servidores configurados
    const iceServers = cfg.ICE_SERVERS || [];
    const pc = new RTCPeerConnection({
      iceServers: iceServers,
      iceTransportPolicy: 'all',
      bundlePolicy: 'max-bundle',
      rtcpMuxPolicy: 'require'
    });

    // Estado da Negociação Perfeita
    // Escolha determinística de cortesia (polite peer): selfKey > remoteKey
    const isPolite = String(cfg.selfKey || '') > String(remoteKey);
    const state = {
      makingOffer: false,
      ignoreOffer: false,
      polite: isPolite,
      isSettingRemoteAnswerPending: false,
      remoteStream: new MediaStream(),
      reconnectTimer: null,
      iceRestartAttempts: 0
    };

    pcs.set(remoteKey, pc);
    peerState.set(remoteKey, state);
    pendingIce.set(remoteKey, []);

    // Garante criação do elemento de vídeo/avatar na interface
    if (window.MeetingParticipants) {
      window.MeetingParticipants.ensureTile(remoteKey);
      window.MeetingParticipants.updateCounters();
    }

    // 3. Adiciona transceivers de áudio e vídeo
    const localStream = window.MeetingMedia?.getLocalStream();
    const cameraTrack = window.MeetingMedia?.getCameraTrack();
    const screenTrack = window.MeetingMedia?.getScreenTrack();
    const audioTrack = localStream?.getAudioTracks()[0] || null;
    const videoTrack = screenTrack || cameraTrack || null;

    const transceiverOptions = {
      direction: 'sendrecv',
      streams: localStream ? [localStream] : []
    };

    pc.addTransceiver(audioTrack || 'audio', transceiverOptions);
    pc.addTransceiver(videoTrack || 'video', transceiverOptions);

    // 4. Configuração de onicecandidate
    pc.onicecandidate = (event) => {
      if (event.candidate) {
        window.rtcLog(remoteKey, 'ice-local', event.candidate);
        window.MeetingSignaling.sendSignal('ice', event.candidate.toJSON ? event.candidate.toJSON() : event.candidate, remoteKey)
          .catch(err => console.warn('Erro ao enviar ICE:', err));
      }
    };

    // 5. Configuração de ontrack
    pc.ontrack = (event) => {
      if (pcs.get(remoteKey) !== pc) return;
      window.rtcLog(remoteKey, `track-received-${event.track.kind}`);

      if (!state.remoteStream.getTracks().some(t => t.id === event.track.id)) {
        state.remoteStream.addTrack(event.track);
      }

      const video = document.getElementById('peer-' + remoteKey);
      if (video) {
        if (video.srcObject !== state.remoteStream) {
          video.srcObject = state.remoteStream;
        }
        event.track.onunmute = () => playRemoteVideo(video, remoteKey);
        playRemoteVideo(video, remoteKey);
      }
    };

    // 6. Perfect Negotiation: onnegotiationneeded
    pc.onnegotiationneeded = async () => {
      try {
        state.makingOffer = true;
        window.rtcLog(remoteKey, 'negotiation-needed-offer-start');
        
        // Em navegadores modernos, setLocalDescription() sem args gera a oferta correta
        await pc.setLocalDescription();
        window.rtcLog(remoteKey, 'offer-created', pc.localDescription);

        await window.MeetingSignaling.sendSignal('offer', {
          type: pc.localDescription.type,
          sdp: pc.localDescription.sdp
        }, remoteKey);
        window.rtcLog(remoteKey, 'offer-sent');
      } catch (err) {
        console.warn(`Erro em onnegotiationneeded com peer ${remoteKey}:`, err);
        window.rtcLog(remoteKey, 'negotiation-error', err);
      } finally {
        state.makingOffer = false;
      }
    };

    // 7. Configuração de onconnectionstatechange e reconexão resiliente
    pc.onconnectionstatechange = () => {
      if (pcs.get(remoteKey) !== pc) return;
      const cState = pc.connectionState;
      window.rtcLog(remoteKey, `connection-state: ${cState}`);
      updatePeerStateLabel(remoteKey, cState);

      if (window.MeetingParticipants) {
        window.MeetingParticipants.updateCounters();
      }

      if (cState === 'connected') {
        if (state.reconnectTimer) {
          clearTimeout(state.reconnectTimer);
          state.reconnectTimer = null;
        }
        state.iceRestartAttempts = 0;
        window.rtcLog(remoteKey, 'connected');
        if (window.MeetingDiagnostics) {
          window.MeetingDiagnostics.updatePeerRoute(remoteKey, pc);
        }
      } else if (cState === 'disconnected') {
        window.rtcLog(remoteKey, 'disconnected');
        // Carência de 4 segundos antes de reiniciar o ICE
        if (!state.reconnectTimer) {
          state.reconnectTimer = setTimeout(async () => {
            state.reconnectTimer = null;
            if (pcs.get(remoteKey) === pc && (pc.connectionState === 'disconnected' || pc.iceConnectionState === 'disconnected')) {
              await attemptIceRestart(remoteKey, pc, state);
            }
          }, 4000);
        }
      } else if (cState === 'failed') {
        window.rtcLog(remoteKey, 'failed');
        handlePeerFailure(remoteKey);
      } else if (cState === 'closed') {
        window.rtcLog(remoteKey, 'closed');
      }
    };

    // 8. Configuração de oniceconnectionstatechange
    pc.oniceconnectionstatechange = () => {
      if (pcs.get(remoteKey) !== pc) return;
      const iceState = pc.iceConnectionState;
      window.rtcLog(remoteKey, `ice-connection-state: ${iceState}`);

      if (iceState === 'failed') {
        handlePeerFailure(remoteKey);
      }
    };

    return pc;
  }

  async function attemptIceRestart(remoteKey, pc, state) {
    if (state.iceRestartAttempts >= 3) {
      window.rtcLog(remoteKey, 'ice-restart-limit-reached-recreating-peer');
      handlePeerFailure(remoteKey);
      return;
    }
    state.iceRestartAttempts++;
    window.rtcLog(remoteKey, `restart-ice-attempt-${state.iceRestartAttempts}`);

    try {
      if (typeof pc.restartIce === 'function') {
        pc.restartIce();
      }
      state.makingOffer = true;
      const offer = await pc.createOffer({ iceRestart: true });
      await pc.setLocalDescription(offer);
      await window.MeetingSignaling.sendSignal('offer', {
        type: pc.localDescription.type,
        sdp: pc.localDescription.sdp
      }, remoteKey);
      window.rtcLog(remoteKey, 'restart-ice-offer-sent');
    } catch (e) {
      console.warn(`Falha no restartIce para ${remoteKey}:`, e);
      handlePeerFailure(remoteKey);
    } finally {
      state.makingOffer = false;
    }
  }

  function handlePeerFailure(remoteKey) {
    window.rtcLog(remoteKey, 'recreating-peer-after-failure');
    removePeer(remoteKey);
    setTimeout(() => {
      if (!window.MeetingApp?.isLeaving()) {
        getOrCreatePeer(remoteKey);
      }
    }, 1500);
  }

  async function flushPendingIce(remoteKey) {
    const pc = pcs.get(remoteKey);
    if (!pc || !pc.remoteDescription) return;

    const queue = pendingIce.get(remoteKey) || [];
    while (queue.length > 0) {
      const candidateInit = queue.shift();
      try {
        await pc.addIceCandidate(new RTCIceCandidate(candidateInit));
        window.rtcLog(remoteKey, 'ice-remote-flushed');
      } catch (err) {
        console.warn(`Erro ao aplicar candidato ICE para ${remoteKey}:`, err);
      }
    }
  }

  async function processSignal(m) {
    const key = m.sender_key;
    const p = m.payload || {};
    const cfg = window.MEETING_CONFIG;

    if (!key || key === cfg.selfKey || window.MeetingApp?.isLeaving()) return;

    // Descoberta de participantes em tempo real
    if (m.message_type === 'peer-ready') {
      window.rtcLog(key, 'peer-ready-received');
      getOrCreatePeer(key);
      return;
    }

    if (m.message_type === 'leave') {
      window.rtcLog(key, 'peer-leave-received');
      if (p.removed) {
        alert('Você foi removido da reunião pelo administrador.');
        if (window.MeetingApp) window.MeetingApp.leaveRoom(false);
        return;
      }
      removePeer(key);
      return;
    }

    // Sinal de OFERTA ou RESPOSTA com Perfect Negotiation
    if (m.message_type === 'offer' || m.message_type === 'answer') {
      const pc = getOrCreatePeer(key);
      const state = peerState.get(key);
      if (!pc || !state) return;

      const isOffer = (m.message_type === 'offer');
      const offerCollision = isOffer && (state.makingOffer || pc.signalingState !== 'stable');

      // Se houver colisão de oferta e este nó NÃO for o educado (polite), ignora a oferta recebida
      state.ignoreOffer = !state.polite && offerCollision;
      if (state.ignoreOffer) {
        window.rtcLog(key, 'offer-collision-ignored-impolite');
        return;
      }

      if (offerCollision && state.polite) {
        window.rtcLog(key, 'offer-collision-polite-rollback');
      }

      if (!isOffer && pc.signalingState !== 'have-local-offer') {
        window.rtcLog(key, `ignoring-spurious-answer-state-${pc.signalingState}`);
        return;
      }

      try {
        state.isSettingRemoteAnswerPending = !isOffer;
        await pc.setRemoteDescription(new RTCSessionDescription(p));
        state.isSettingRemoteAnswerPending = false;
        window.rtcLog(key, isOffer ? 'offer-received-setRemote' : 'answer-received-setRemote');

        await flushPendingIce(key);

        if (isOffer) {
          const answer = await pc.createAnswer();
          await pc.setLocalDescription(answer);
          window.rtcLog(key, 'answer-created', pc.localDescription);
          await window.MeetingSignaling.sendSignal('answer', {
            type: pc.localDescription.type,
            sdp: pc.localDescription.sdp
          }, key);
          window.rtcLog(key, 'answer-sent');
        }
      } catch (err) {
        console.warn(`Erro no setRemoteDescription com ${key}:`, err);
        window.rtcLog(key, 'setRemoteDescription-error', err);
      }
      return;
    }

    // Sinal de Candidato ICE
    if (m.message_type === 'ice') {
      const pc = getOrCreatePeer(key);
      const state = peerState.get(key);
      if (!pc || !state || state.ignoreOffer) return;

      if (pc.remoteDescription && pc.remoteDescription.type) {
        try {
          await pc.addIceCandidate(new RTCIceCandidate(p));
          window.rtcLog(key, 'ice-remote-added');
        } catch (err) {
          if (!state.ignoreOffer) {
            console.warn(`Erro ao adicionar ICE candidate de ${key}:`, err);
          }
        }
      } else {
        // Enfileira candidato que chegou antes do remoteDescription
        const q = pendingIce.get(key) || [];
        q.push(p);
        pendingIce.set(key, q);
        window.rtcLog(key, 'ice-remote-queued');
      }
    }
  }

  function removePeer(key) {
    window.rtcLog(key, 'peer-removed');
    const pc = pcs.get(key);
    const state = peerState.get(key);

    if (state && state.reconnectTimer) {
      clearTimeout(state.reconnectTimer);
      state.reconnectTimer = null;
    }

    pcs.delete(key);
    peerState.delete(key);
    pendingIce.delete(key);

    if (pc) {
      pc.onconnectionstatechange = null;
      pc.oniceconnectionstatechange = null;
      pc.onnegotiationneeded = null;
      pc.ontrack = null;
      pc.onicecandidate = null;
      try {
        pc.close();
      } catch(e) {}
    }

    // Remove tile visual e limpa stream
    const tile = document.getElementById('tile-' + key);
    if (tile) {
      const video = tile.querySelector('video');
      if (video) video.srcObject = null;
      tile.remove();
    }

    if (window.MeetingParticipants) {
      window.MeetingParticipants.updateCounters();
      window.MeetingParticipants.updateVideoGridCount();
    }
  }

  function updatePeerStateLabel(key, state) {
    const label = document.getElementById('state-' + key);
    if (!label) return;
    if (state === 'connected') {
      label.textContent = 'Conectado';
      label.style.borderColor = 'rgba(16, 185, 129, 0.4)';
      label.style.color = '#10b981';
    } else if (state === 'failed') {
      label.textContent = 'Tentando reconectar…';
      label.style.borderColor = 'rgba(239, 68, 68, 0.4)';
      label.style.color = '#ef4444';
    } else {
      label.textContent = 'Conectando…';
      label.style.borderColor = 'rgba(0, 210, 255, 0.4)';
      label.style.color = 'var(--primary, #00d2ff)';
    }
  }

  async function playRemoteVideo(video, remoteKey) {
    try {
      await video.play();
    } catch (e) {
      video.muted = true;
      try {
        await video.play();
      } catch (err) {
        console.warn('Vídeo remoto bloqueado:', err);
      }
      const tile = video.parentElement;
      if (!tile || tile.querySelector('.enable-audio')) return;
      const button = document.createElement('button');
      button.className = 'sr-btn sr-btn-primary enable-audio';
      button.textContent = 'Ativar áudio';
      button.style.cssText = 'position:absolute;top:12px;left:12px;z-index:10;font-size:0.75rem;padding:4px 10px;border-radius:6px;';
      button.onclick = async () => {
        video.muted = false;
        try {
          await video.play();
          button.remove();
        } catch (err) {
          video.muted = true;
        }
      };
      tile.appendChild(button);
    }
  }

  function closeAllPeers() {
    for (const key of Array.from(pcs.keys())) {
      removePeer(key);
    }
    pcs.clear();
    peerState.clear();
    pendingIce.clear();
  }

  window.MeetingWebRTC = {
    pcs,
    peerState,
    getOrCreatePeer,
    removePeer,
    processSignal,
    flushPendingIce,
    closeAllPeers,
    getPeerCount: () => pcs.size,
    getConnectedPeerCount: () => {
      let count = 0;
      for (const pc of pcs.values()) {
        if (pc.connectionState === 'connected') count++;
      }
      return count;
    },
    hasFailedPeer: () => {
      for (const pc of pcs.values()) {
        if (pc.connectionState === 'failed') return true;
      }
      return false;
    }
  };
})(window);
