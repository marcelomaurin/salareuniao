/**
 * Orquestrador Principal do Sala Reunião
 * Coordena inicialização, presença, heartbeat, eventos de interface e saída limpa
 */
(function(window) {
  'use strict';

  let leaving = false;
  let heartbeatTimer = null;

    async function jsonFetch(url, options = {}) {
    const r = await fetch(url, options);
    let data;
    try {
      data = await r.json();
    } catch(e) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      throw e;
    }
    if (!r.ok) {
      if (data && (data.error === 'kicked' || data.status === 'rejected' || data.kicked)) {
        return data;
      }
      const err = new Error(data && data.error ? data.error : 'HTTP ' + r.status);
      err.response = data;
      throw err;
    }
    return data;
  }

  async function heartbeat() {
    if (leaving) return;
    const cfg = window.MEETING_CONFIG;

    if (window.MeetingSignaling) {
      window.MeetingSignaling.broadcastPresence();
    }

    try {
      const media = window.MeetingMedia;
      const d = await jsonFetch('api/presence.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          token: cfg.TOKEN,
          mic: media ? media.isMicEnabled() : true,
          cam: media ? media.isCamEnabled() : true,
          screen: media ? media.isScreenSharing() : false,
          hand: (window.MeetingParticipants && window.MeetingParticipants.isHandRaised()) ? 1 : 0
        })
      });

      if (d.self) cfg.selfKey = d.self;
      if (d.kicked || d.error === 'kicked' || d.status === 'rejected') {
        await leaveRoom(false);
        window.location.href = 'kicked.php?room_name=' + encodeURIComponent(d.room_name || cfg.roomName || '');
        return;
      }
      if (d.room_status !== 'open') {
        alert('A reunião foi encerrada.');
        await leaveRoom(false);
        return;
      }

      if (window.MeetingParticipants) {
        window.MeetingParticipants.renderParticipants(d.participants || []);
        if (d.waiting) window.MeetingParticipants.renderWaitingList(d.waiting);
      }
    } catch (e) {
      // Erro temporário de heartbeat
    }

    heartbeatTimer = setTimeout(heartbeat, 4000);
  }

  async function leaveRoom(navigate = true) {
    if (leaving) return;
    leaving = true;

    if (heartbeatTimer) {
      clearTimeout(heartbeatTimer);
      heartbeatTimer = null;
    }

    if (window.MeetingDiagnostics) {
      window.MeetingDiagnostics.stopDiagnosticsCollector();
    }

    // 1. Libera todo o hardware de mídia
    if (window.MeetingMedia) {
      window.MeetingMedia.releaseAllMedia();
    }

    // 2. Notifica saída imediata via sendBeacon
    const cfg = window.MEETING_CONFIG;
    try {
      const blob = new Blob([JSON.stringify({ token: cfg.TOKEN })], { type: 'application/json' });
      navigator.sendBeacon('api/leave.php', blob);
    } catch (e) {}

    // 3. Notifica via WebSocket e fecha socket
    if (window.MeetingControl) {
      window.MeetingControl.disconnect();
    }

    if (window.MeetingSignaling) {
      window.MeetingSignaling.closeSocket();
    }

    // 4. Fecha todas as conexões WebRTC
    if (window.MeetingWebRTC) {
      window.MeetingWebRTC.closeAllPeers();
    }

    // 5. Redireciona para o painel inicial
    if (navigate) {
      window.location.href = 'index.php?left=1';
    }
  }

  async function initMeeting() {
    const cfg = window.MEETING_CONFIG;

    try {
      // 1. Inicializa mídia local primeiro
      await window.MeetingMedia.initLocalMedia();

      // Inicia o scanner de encaminhamento (Bridge) apenas se RELAY_MODE estiver ativo
      if (window.MeetingBridge && cfg.RELAY_MODE) {
        window.MeetingBridge.startScanner();
      }

      // 2. Ativa imediatamente o canal de sinalização (WebSocket e polling de sinais)
      // para que respostas e ofertas de outros peers possam ser processadas sem atraso
      if (window.MeetingSignaling) {
        window.MeetingSignaling.connectWebSocket();
        window.MeetingSignaling.pollSignals();
      }

      // 3. Registra presença inicial no servidor HTTP
      const first = await jsonFetch('api/presence.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          token: cfg.TOKEN,
          mic: window.MeetingMedia.isMicEnabled(),
          cam: window.MeetingMedia.isCamEnabled(),
          screen: false
        })
      });

      if (first.self) cfg.selfKey = first.self;

      // Sincroniza estado inicial de apresentação (Tarefas 23 e 24)
      if (cfg.initialRuntimeState && window.MeetingPresentation) {
        window.MeetingPresentation.sync(cfg.initialRuntimeState);
      }

      // 4. Descobre e inicia conexão com os participantes já presentes na sala
      if (window.MeetingParticipants) {
        window.MeetingParticipants.renderParticipants(first.participants || []);
        if (first.waiting) window.MeetingParticipants.renderWaitingList(first.waiting);
      }

      // 5. Notifica a sala que estamos prontos para receber conexões P2P
      if (window.MeetingSignaling) {
        window.MeetingSignaling.sendSignal('peer-ready', {}, null);
      }

      // 6. Carrega histórico e polling do chat
      if (window.MeetingChat) {
        await window.MeetingChat.loadChatHistory();
        window.MeetingChat.pollChat();
      }

      // 7. Inicia coletor de diagnósticos WebRTC
      if (window.MeetingDiagnostics) {
        window.MeetingDiagnostics.startDiagnosticsCollector();
      }

      // 8. Inicia heartbeat contínuo de presença
      setTimeout(heartbeat, 1200);
    } catch (e) {
      console.error('Falha na inicialização da reunião:', e);
      if (window.MeetingSignaling) {
        window.MeetingSignaling.pollSignals();
        window.MeetingSignaling.sendSignal('peer-ready', {}, null);
      }
      setTimeout(heartbeat, 1500);
    }
  }

  // Hook de saída em fechamento de página ou navegação
  window.addEventListener('beforeunload', () => {
    if (!leaving) {
      try {
        const cfg = window.MEETING_CONFIG;
        const blob = new Blob([JSON.stringify({ token: cfg.TOKEN })], { type: 'application/json' });
        navigator.sendBeacon('api/leave.php', blob);
      } catch (e) {}
    }
  });

  window.addEventListener('pagehide', () => {
    if (window.MeetingMedia) window.MeetingMedia.releaseAllMedia();
  });

  window.MeetingApp = {
    initMeeting,
    leaveRoom,
    isLeaving: () => leaving,
    triggerHeartbeat: heartbeat
  };
})(window);
