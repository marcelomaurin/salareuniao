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
      iceRestartAttempts: 0,
      transportMode: 'webrtc',
      failureTimer: null,
      restoreTimer: null
    };

    pcs.set(remoteKey, pc);
    peerState.set(remoteKey, state);
    pendingIce.set(remoteKey, []);

    // Garante criação do elemento de vídeo/avatar na interface
    if (window.MeetingParticipants) {
      window.MeetingParticipants.ensureTile(remoteKey);
      window.MeetingParticipants.updateCounters();
    }

    // 3. Handlers de eventos configurados antes de adicionar transceivers

    // Configuração de onicecandidate
    pc.onicecandidate = (event) => {
      if (event.candidate) {
        window.rtcLog(remoteKey, 'ice-local', event.candidate);
        window.MeetingSignaling.sendSignal('ice', event.candidate.toJSON ? event.candidate.toJSON() : event.candidate, remoteKey)
          .catch(err => console.warn('Erro ao enviar ICE:', err));
      }
    };

    // Configuração de ontrack com exibição imediata do vídeo
    pc.ontrack = (event) => {
      if (pcs.get(remoteKey) !== pc) return;
      window.rtcLog(remoteKey, `track-received-${event.track.kind}`);

      if (!state.remoteStream.getTracks().some(t => t.id === event.track.id)) {
        state.remoteStream.addTrack(event.track);
      }

      const video = document.getElementById('peer-' + remoteKey);
      const avatar = document.getElementById('avatar-' + remoteKey);
      if (video) {
        if (video.srcObject !== state.remoteStream) {
          video.srcObject = state.remoteStream;
        }
        if (event.track.kind === 'video') {
          video.style.display = 'block';
          if (avatar) avatar.style.display = 'none';
        }
        event.track.onunmute = () => {
          if (event.track.kind === 'video') {
            video.style.display = 'block';
            if (avatar) avatar.style.display = 'none';
          }
          playRemoteVideo(video, remoteKey);
        };
        playRemoteVideo(video, remoteKey);
      }
    };

    // Perfect Negotiation: onnegotiationneeded
    pc.onnegotiationneeded = async () => {
      try {
        state.makingOffer = true;
        window.rtcLog(remoteKey, 'negotiation-needed-offer-start');
        
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

    // 4. Adiciona transceivers de áudio e vídeo
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
    const videoTransceiver = pc.addTransceiver(videoTrack || 'video', transceiverOptions);

    // Otimização de largura de banda: limita o bitrate máximo de vídeo da câmera a 400 kbps
    if (videoTransceiver && videoTransceiver.sender && !screenTrack) {
      try {
        const pms = videoTransceiver.sender.getParameters();
        if (!pms.encodings || pms.encodings.length === 0) pms.encodings = [{}];
        pms.encodings[0].maxBitrate = 400000; // 400 kbps
        pms.encodings[0].maxFramerate = 24;
        videoTransceiver.sender.setParameters(pms);
      } catch (e) {}
    }

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
        if (state.failureTimer) {
          clearTimeout(state.failureTimer);
          state.failureTimer = null;
        }
        state.iceRestartAttempts = 0;
        window.rtcLog(remoteKey, 'WEBRTC_CONNECTED');

        // Tarefa 65: Se estava em fallback Bridge, aguarda estabilidade de 3 segundos
        if (state.transportMode === 'bridge') {
          if (state.restoreTimer) clearTimeout(state.restoreTimer);
          state.restoreTimer = setTimeout(() => {
            state.restoreTimer = null;
            if (pcs.get(remoteKey) === pc && pc.connectionState === 'connected') {
              state.transportMode = 'webrtc';
              window.rtcLog(remoteKey, 'WEBRTC_RESTORED');
              if (window.MeetingBridge) {
                window.MeetingBridge.deactivateFallback(remoteKey);
              }
            }
          }, 3000);
        } else {
          state.transportMode = 'webrtc';
        }

        if (window.MeetingDiagnostics) {
          window.MeetingDiagnostics.updatePeerRoute(remoteKey, pc);
        }
      } else if (cState === 'disconnected') {
        window.rtcLog(remoteKey, 'disconnected');
        if (state.restoreTimer) {
          clearTimeout(state.restoreTimer);
          state.restoreTimer = null;
        }
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
        window.rtcLog(remoteKey, 'WEBRTC_FAILED');
        handlePeerFailure(remoteKey, pc, state);
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
        window.rtcLog(remoteKey, 'WEBRTC_FAILED');
        handlePeerFailure(remoteKey, pc, state);
      }
    };

    return pc;
  }

  async function attemptIceRestart(remoteKey, pc, state) {
    const cfg = window.MEETING_CONFIG || {};
    if (state.iceRestartAttempts >= 3) {
      window.rtcLog(remoteKey, 'ice-restart-limit-reached-recreating-peer');
      if (cfg.BRIDGE_ENABLED && state.transportMode !== 'bridge') {
        state.transportMode = 'bridge';
        if (window.MeetingBridge) {
          window.MeetingBridge.activateFallback(remoteKey);
        }
      }
      handlePeerFailure(remoteKey, pc, state);
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

  function handlePeerFailure(remoteKey, pc, state) {
    if (!state) state = peerState.get(remoteKey);
    const cfg = window.MEETING_CONFIG || {};
    const fallbackTimeout = cfg.BRIDGE_FALLBACK_TIMEOUT_MS || 8000;

    // Tarefas 62 a 64: Ativação de fallback Bridge se persistir em falha
    if (state && cfg.BRIDGE_ENABLED && state.transportMode !== 'bridge') {
      if (!state.failureTimer) {
        state.failureTimer = setTimeout(() => {
          state.failureTimer = null;
          const curPc = pcs.get(remoteKey);
          if (curPc && (curPc.connectionState === 'failed' || curPc.iceConnectionState === 'failed')) {
            state.transportMode = 'bridge';
            window.rtcLog(remoteKey, 'BRIDGE_CONNECTING');
            if (window.MeetingBridge) {
              window.MeetingBridge.activateFallback(remoteKey);
            }
          }
        }, fallbackTimeout);
      }
    }

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

    // Descoberta de participantes e recuperacao de fluxo de video sob demanda
    if (m.message_type === 'peer-ready') {
      window.rtcLog(key, 'peer-ready-received');
      const action = p && (p.action || p.request);
      if (action === 'request-video-stream' || action === 'renegotiate') {
        window.rtcLog(key, 'peer-requested-video-retransmission');
        handleStreamRecoveryRequest(key);
        return;
      }
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

    // Sinal para desativar microfone recebido
    const isMuteSignal = (m.message_type === 'mute-user') || 
                         (m.payload && (m.payload.action === 'mute-user' || m.payload.mute === true));

    if (isMuteSignal) {
      window.rtcLog(key, 'mute-user-received-from-moderator');
      if (window.MeetingMedia) {
        window.MeetingMedia.setMicrophoneEnabled(false);
      }
      if (window.showToast) {
        window.showToast('Seu microfone foi desativado na reunião.');
      }
      return;
    }

    // Sinal de OFERTA ou RESPOSTA com Perfect Negotiation Estrito (W3C/MDN)
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

      try {
        // Se for o nó educado e houver colisão de oferta, executa ROLLBACK para voltar a 'stable'
        if (offerCollision && state.polite) {
          window.rtcLog(key, 'offer-collision-polite-rollback-start');
          await pc.setLocalDescription({ type: 'rollback' });
          window.rtcLog(key, 'offer-collision-polite-rollback-success');
        }

        if (!isOffer && pc.signalingState !== 'have-local-offer') {
          window.rtcLog(key, `ignoring-spurious-answer-state-${pc.signalingState}`);
          return;
        }

        state.isSettingRemoteAnswerPending = !isOffer;
        await pc.setRemoteDescription(new RTCSessionDescription(p));
        state.isSettingRemoteAnswerPending = false;
        // Reseta ignoreOffer após aceitar descrição remota válida
        state.ignoreOffer = false;
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

    // Sinal de Candidato ICE com controle resiliente de fila
    if (m.message_type === 'ice') {
      const pc = getOrCreatePeer(key);
      const state = peerState.get(key);
      if (!pc || !state) return;

      if (state.ignoreOffer) {
        window.rtcLog(key, 'ice-candidate-ignored-due-to-ignoreOffer');
        return;
      }

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

  // Atende a solicitacao de outro peer para retransmitir/forcar envio do fluxo de video
  async function handleStreamRecoveryRequest(remoteKey) {
    const pc = getOrCreatePeer(remoteKey);
    const state = peerState.get(remoteKey);
    if (!pc || !state) return;

    try {
      const cameraTrack = window.MeetingMedia?.getCameraTrack();
      const localStream = window.MeetingMedia?.getLocalStream();

      // 1. Reanexa ou atualiza o track de video no transceiver correspondente
      if (cameraTrack && cameraTrack.readyState === 'live') {
        cameraTrack.enabled = true;
        const transceivers = pc.getTransceivers();
        const vt = transceivers.find(t => 
          (t.sender && t.sender.track && t.sender.track.kind === 'video') ||
          (t.receiver && t.receiver.track && t.receiver.track.kind === 'video')
        );
        if (vt && vt.sender) {
          await vt.sender.replaceTrack(cameraTrack);
          window.rtcLog(remoteKey, 'camera-track-reattached-to-transceiver');
        } else {
          pc.addTrack(cameraTrack, localStream || new MediaStream([cameraTrack]));
        }
      }

      // 2. Dispara renegociacao com reinicio de ICE para forcar o envio dos pacotes
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
      window.rtcLog(remoteKey, 'recovery-offer-sent-with-restart-ice');
    } catch (err) {
      console.warn(`Erro ao retransmitir video para ${remoteKey}:`, err);
      window.rtcLog(remoteKey, 'recovery-error', err);
    } finally {
      state.makingOffer = false;
    }
  }

  // Watchdog de Saude de Midia: monitora se algum peer conectado com cam_enabled nao esta entregando frames
  const lastRecoveryTime = new Map();

  function checkMediaHealthAndRecover() {
    if (window.MeetingApp?.isLeaving()) return;
    if (!window.MeetingParticipants) return;

    const list = window.MeetingParticipants.getParticipants() || [];
    const cfg = window.MEETING_CONFIG;
    const now = Date.now();

    for (const p of list) {
      const remoteKey = p.participant_key;
      if (!remoteKey || remoteKey === cfg.selfKey) continue;

      const camActive = Number(p.cam_enabled) === 1;
      const pc = pcs.get(remoteKey);
      const video = document.getElementById('peer-' + remoteKey);

      // Se o JSON de presenca indica que o usuario esta com a camera ligada
      if (camActive) {
        // Se a conexao peer nem sequer foi iniciada, cria imediatamente
        if (!pc) {
          getOrCreatePeer(remoteKey);
          continue;
        }

        // Avalia se o video esta efetivamente recebendo e renderizando frames
        const hasFrames = video && (video.videoWidth > 0 && video.videoHeight > 0 && !video.paused);
        const hasLiveTrack = Boolean(video && video.srcObject && 
          video.srcObject.getVideoTracks && 
          video.srcObject.getVideoTracks().some(t => t.readyState === 'live' && !t.muted));

        // Se nao esta renderizando frames ou o track esta ausente/mutado
        if (!hasFrames || !hasLiveTrack) {
          const lastAttempt = lastRecoveryTime.get(remoteKey) || 0;
          // Cooldown de 5 segundos entre tentativas por peer
          if (now - lastAttempt > 5000) {
            lastRecoveryTime.set(remoteKey, now);
            window.rtcLog(remoteKey, 'watchdog-missing-video-detected-triggering-recovery');

            // 1. Tenta desmutar e forcar execucao do video no navegador local
            if (video) {
              video.style.display = 'block';
              playRemoteVideo(video, remoteKey);
            }

            // 2. Se a conexao WebRTC travou em failed ou disconnected, tenta reiniciar ICE
            const state = peerState.get(remoteKey);
            if (state && (pc.connectionState === 'failed' || pc.connectionState === 'disconnected')) {
              attemptIceRestart(remoteKey, pc, state);
            }

            // 3. Solicita formalmente ao peer remoto que reenvie sua transmissao de video
            window.MeetingSignaling.sendSignal('peer-ready', {
              action: 'request-video-stream',
              reason: 'missing-video-rendering'
            }, remoteKey).catch(() => {});
          }
        }
      }
    }
  }

  // Inicia o Watchdog a cada 3.5 segundos
  setInterval(checkMediaHealthAndRecover, 3500);

  function closeAllPeers() {
    for (const key of Array.from(pcs.keys())) {
      removePeer(key);
    }
    pcs.clear();
    peerState.clear();
    pendingIce.clear();
  }

    async function setSpotlightBitrate(isHighQuality) {
    for (const pc of pcs.values()) {
      for (const sender of pc.getSenders()) {
        if (sender.track && sender.track.kind === 'video') {
          try {
            const params = sender.getParameters();
            if (params.encodings && params.encodings.length > 0) {
              params.encodings[0].maxBitrate = isHighQuality ? 2500000 : 350000;
              params.encodings[0].maxFramerate = isHighQuality ? 30 : 24;
              await sender.setParameters(params);
            }
          } catch(e) {}
        }
      }
    }
  }

  window.MeetingWebRTC = {
    pcs,
    peerState,
    getOrCreatePeer,
    removePeer,
    closePeer: removePeer,
    setSpotlightBitrate,
    processSignal,
    flushPendingIce,
    closeAllPeers,
    getPeerTransportMode: (remoteKey) => {
      const s = peerState.get(remoteKey);
      return s ? s.transportMode : 'webrtc';
    },
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
