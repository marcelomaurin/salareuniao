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
    if (!r.ok) throw new Error('HTTP ' + r.status);
    return r.json();
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
          screen: media ? media.isScreenSharing() : false
        })
      });

      if (d.self) cfg.selfKey = d.self;
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
      // 1. Inicializa mídia local
      await window.MeetingMedia.initLocalMedia();

      // 2. Presença inicial HTTP
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
      if (window.MeetingParticipants) {
        window.MeetingParticipants.renderParticipants(first.participants || []);
        if (first.waiting) window.MeetingParticipants.renderWaitingList(first.waiting);
      }

      // 3. Carrega chat
      if (window.MeetingChat) {
        await window.MeetingChat.loadChatHistory();
      }

      // 4. Conecta sinalização primária WebSocket e inicia polling HTTP
      if (window.MeetingSignaling) {
        window.MeetingSignaling.connectWebSocket();
        window.MeetingSignaling.pollSignals();
        window.MeetingSignaling.sendSignal('peer-ready', {}, null);
      }

      if (window.MeetingChat) {
        window.MeetingChat.pollChat();
      }

      // 5. Inicia coletor de diagnósticos WebRTC
      if (window.MeetingDiagnostics) {
        window.MeetingDiagnostics.startDiagnosticsCollector();
      }

      // 6. Inicia heartbeat contínuo
      setTimeout(heartbeat, 1500);
    } catch (e) {
      console.error('Falha na inicialização da reunião:', e);
      if (window.MeetingSignaling) {
        window.MeetingSignaling.pollSignals();
        window.MeetingSignaling.sendSignal('peer-ready', {}, null);
      }
      setTimeout(heartbeat, 2000);
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
    isLeaving: () => leaving
  };
})(window);
