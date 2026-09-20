/**
 * Camada de Sinalização Híbrida: WebSocket + Fallback HTTP Transparente
 * - Ratchet WebSocket (wss://...) como transporte primário
 * - Polling HTTP automático quando WebSocket cair ou for derrubado
 * - Fila de sinais sequencial por peer e deduplicação de mensagens
 */
(function(window) {
  'use strict';

  let ws = null;
  let wsReady = false;
  let wsReconnectTimer = null;
  let signalPollRunning = false;
  let lastSignalId = 0;
  const deliveredSignals = new Set();
  const signalQueues = new Map();

  async function jsonFetch(url, options = {}) {
    const r = await fetch(url, options);
    if (!r.ok) throw new Error('HTTP ' + r.status);
    return r.json();
  }

  function updateConnectionStatusUI(connectedWs) {
    const statusEl = document.getElementById('status');
    if (!statusEl) return;
    if (connectedWs) {
      statusEl.textContent = 'Conectado em tempo real';
      statusEl.style.color = '#10b981';
    } else {
      statusEl.textContent = 'WebSocket desconectado; usando fallback';
      statusEl.style.color = '#f59e0b';
    }
  }

  async function sendSignal(type, payload = {}, recipient = null) {
    const cfg = window.MEETING_CONFIG;
    if (wsReady && ws) {
      try {
        ws.send(JSON.stringify({
          type: 'signal',
          signalType: type,
          payload: payload,
          recipient: recipient
        }));
        return { ok: true, transport: 'websocket' };
      } catch (e) {
        wsReady = false;
      }
    }

    // Fallback HTTP
    return jsonFetch('api/signal_send.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        token: cfg.TOKEN,
        type: type,
        payload: payload,
        recipient: recipient
      })
    });
  }

  function queuePeerSignal(key, task) {
    const previous = signalQueues.get(key) || Promise.resolve();
    const next = previous.catch(() => {}).then(task);
    signalQueues.set(key, next);
    next.finally(() => {
      if (signalQueues.get(key) === next) signalQueues.delete(key);
    }).catch(() => {});
    return next;
  }

  async function handleIncomingSignal(m) {
    const id = m.id ? String(m.id) : null;
    if (id && deliveredSignals.has(id)) return;
    if (id) deliveredSignals.add(id);

    try {
      await queuePeerSignal(m.sender_key, () => {
        if (window.MeetingWebRTC && window.MeetingWebRTC.processSignal) {
          return window.MeetingWebRTC.processSignal(m);
        }
      });
    } catch (e) {
      if (id) deliveredSignals.delete(id);
      throw e;
    }

    if (deliveredSignals.size > 2000) {
      deliveredSignals.delete(deliveredSignals.values().next().value);
    }
  }

  async function pollSignals() {
    if (signalPollRunning) return;
    signalPollRunning = true;
    const cfg = window.MEETING_CONFIG;

    try {
      while (!window.MeetingApp?.isLeaving()) {
        try {
          const d = await jsonFetch(`api/signal_poll.php?token=${encodeURIComponent(cfg.TOKEN)}&after=${lastSignalId}`, {
            cache: 'no-store'
          });
          if (d.self) cfg.selfKey = d.self;

          for (const m of (d.messages || [])) {
            try {
              await handleIncomingSignal(m);
            } catch (e) {
              console.warn('Erro ao processar sinal de fallback:', e);
            }
            lastSignalId = Math.max(lastSignalId, Number(m.id) || 0);
          }
        } catch (e) {
          // Erro de rede temporário no polling
        }
        await new Promise(r => setTimeout(r, 750));
      }
    } finally {
      signalPollRunning = false;
    }
  }

  function connectWebSocket() {
    const cfg = window.MEETING_CONFIG;
    if (!cfg.WS_ENABLED || !cfg.WS_URL || window.MeetingApp?.isLeaving()) return;

    try {
      const url = cfg.WS_URL + (cfg.WS_URL.includes('?') ? '&' : '?') + 'token=' + encodeURIComponent(cfg.TOKEN);
      ws = new WebSocket(url);

      ws.onopen = () => {
        wsReady = true;
        updateConnectionStatusUI(true);
        if (wsReconnectTimer) {
          clearTimeout(wsReconnectTimer);
          wsReconnectTimer = null;
        }

        // Transmite presença inicial e notifica peer-ready para a sala
        broadcastPresence();
        sendSignal('peer-ready', {}, null);
        window.rtcLog && window.rtcLog('WS', 'connected-and-peer-ready-sent');
      };

      ws.onmessage = async (ev) => {
        try {
          const d = JSON.parse(ev.data);
          if (d.type === 'hello') {
            if (d.self) cfg.selfKey = d.self;
            return;
          }
          if (d.type === 'signal' && d.message) {
            await handleIncomingSignal(d.message);
            return;
          }
          if (d.type === 'chat' && d.message) {
            if (window.MeetingChat && window.MeetingChat.appendChatMessage) {
              window.MeetingChat.appendChatMessage(d.message);
            }
            return;
          }
          if (d.type === 'presence') {
            if (window.MeetingParticipants) {
              window.MeetingParticipants.renderParticipants(d.participants || []);
              if (d.waiting) window.MeetingParticipants.renderWaitingList(d.waiting);
            }
            return;
          }
          if (d.type === 'session-ended') {
            alert('Sua participação foi encerrada ou a reunião foi fechada.');
            if (window.MeetingApp) window.MeetingApp.leaveRoom(false);
            return;
          }
          if (d.type === 'error') {
            console.warn('Alerta WebSocket:', d.error);
          }
        } catch (e) {
          console.warn('Erro ao decodificar mensagem WS:', e);
        }
      };

      ws.onclose = () => {
        wsReady = false;
        updateConnectionStatusUI(false);
        // Ativa polling imediato de sinais e chat
        pollSignals();
        if (window.MeetingChat) window.MeetingChat.pollChat();
        if (!window.MeetingApp?.isLeaving()) {
          wsReconnectTimer = setTimeout(connectWebSocket, cfg.WS_RECONNECT_MS || 2000);
        }
      };

      ws.onerror = () => {
        wsReady = false;
      };
    } catch (e) {
      wsReady = false;
      if (!window.MeetingApp?.isLeaving()) {
        wsReconnectTimer = setTimeout(connectWebSocket, cfg.WS_RECONNECT_MS || 2000);
      }
    }
  }

  function broadcastPresence() {
    if (!wsReady || !ws) return;
    try {
      const media = window.MeetingMedia;
      ws.send(JSON.stringify({
        type: 'presence',
        mic: media ? media.isMicEnabled() : true,
        cam: media ? media.isCamEnabled() : true,
        screen: media ? media.isScreenSharing() : false
      }));
    } catch (e) {
      wsReady = false;
    }
  }

  function refreshLobby() {
    if (wsReady && ws) {
      try {
        ws.send(JSON.stringify({ type: 'lobby-refresh' }));
      } catch (e) {}
    }
  }

  function closeSocket() {
    if (ws) {
      try {
        if (wsReady) {
          ws.send(JSON.stringify({ type: 'signal', signalType: 'leave', payload: {}, recipient: null }));
        }
        ws.close();
      } catch (e) {}
      ws = null;
      wsReady = false;
    }
  }

  window.MeetingSignaling = {
    connectWebSocket,
    sendSignal,
    broadcastPresence,
    refreshLobby,
    pollSignals,
    closeSocket,
    isWsReady: () => wsReady,
    setSignalCursor: (id) => { lastSignalId = Math.max(lastSignalId, id || 0); }
  };
})(window);
