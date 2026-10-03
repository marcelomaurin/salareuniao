/**
 * Maurinsoft Sala Reunião - Canal de Controle Administrativo (MeetingControl)
 * - Canal WebSocket independente da sinalização de mídia WebRTC
 * - Protocolo de Comandos Versionados (protocol: 1)
 * - Confirmação bidirecional (ACK) com idempotência
 * - Watchdog resiliente com sincronização obrigatória de estado (state.sync)
 * - Fallback transparente via API HTTP (/api/control.php)
 */
(function(window) {
  'use strict';

  let ws = null;
  let isConnecting = false;
  let reconnectTimer = null;
  let currentStateVersion = 1;
  const processedCommandIds = new Set();
  const pendingAcks = new Map(); // command_id -> { resolve, reject, timer }

  function getCfg() {
    return window.MEETING_CONFIG || {};
  }

  function getControlWsUrl() {
    const cfg = getCfg();
    if (cfg.WS_CONTROL_URL) {
      return cfg.WS_CONTROL_URL;
    }
    if (cfg.WS_URL) {
      // Deriva do WS_URL principal: substitui /ws por /control
      const base = cfg.WS_URL.replace(/\/ws\/?$/, '');
      return base + '/control';
    }
    return '';
  }

  function updateStatusUI(statusText, isConnected) {
    const el = document.getElementById('controlStatusIndicator');
    if (el) {
      el.textContent = statusText;
      el.className = 'control-status-badge ' + (isConnected ? 'connected' : 'disconnected');
    }
  }

  function isConnected() {
    return ws !== null && ws.readyState === WebSocket.OPEN;
  }

  function connect() {
    const cfg = getCfg();
    const token = cfg.token || (new URLSearchParams(window.location.search)).get('token') || '';
    if (!token) return;

    const baseWsUrl = getControlWsUrl();
    if (!baseWsUrl) {
      console.info('Canal de controle WebSocket não configurado; operando em modo fallback HTTP.');
      updateStatusUI('Controle: Fallback HTTP', false);
      return;
    }

    if (ws && (ws.readyState === WebSocket.OPEN || ws.readyState === WebSocket.CONNECTING)) {
      return;
    }

    clearTimeout(reconnectTimer);
    isConnecting = true;
    updateStatusUI('Controle: Conectando...', false);

    const fullUrl = baseWsUrl + (baseWsUrl.includes('?') ? '&' : '?') + 'token=' + encodeURIComponent(token);

    try {
      ws = new WebSocket(fullUrl);

      ws.onopen = function() {
        isConnecting = false;
        console.log('[Control] Canal WebSocket de controle conectado.');
        updateStatusUI('Controle: Conectado', true);
        // Sincronização obrigatória de estado após conectar/reconectar (Tarefa 24, 35)
        requestStateSync();
      };

      ws.onmessage = function(event) {
        try {
          const msg = JSON.parse(event.data);
          handleIncomingMessage(msg);
        } catch (err) {
          console.warn('[Control] Mensagem inválida recebida:', event.data, err);
        }
      };

      ws.onclose = function(e) {
        isConnecting = false;
        ws = null;
        updateStatusUI('Controle: Desconectado', false);
        console.warn('[Control] Canal de controle desconectado. Código:', e.code, 'Reconectando em breve...');
        reconnectTimer = setTimeout(connect, cfg.WS_RECONNECT_MS || 2500);
      };

      ws.onerror = function(err) {
        console.warn('[Control] Erro no canal de controle WebSocket:', err);
      };

    } catch (e) {
      isConnecting = false;
      ws = null;
      updateStatusUI('Controle: Erro', false);
      reconnectTimer = setTimeout(connect, cfg.WS_RECONNECT_MS || 3000);
    }
  }

  function disconnect() {
    clearTimeout(reconnectTimer);
    if (ws) {
      ws.close();
      ws = null;
    }
  }

  function generateUuid() {
    if (window.crypto && window.crypto.randomUUID) {
      return window.crypto.randomUUID();
    }
    return 'cmd-' + Math.random().toString(36).substring(2, 11) + '-' + Date.now();
  }

  function sendAck(commandId, status, error) {
    const ackPayload = {
      type: 'command.ack',
      command_id: commandId,
      status: status || 'applied',
      error: error || null
    };

    if (isConnected()) {
      ws.send(JSON.stringify(ackPayload));
    }
  }

  function requestStateSync() {
    const msg = { type: 'state.sync.request' };
    if (isConnected()) {
      ws.send(JSON.stringify(msg));
    } else {
      // Fallback via HTTP
      const cfg = getCfg();
      const token = cfg.token || (new URLSearchParams(window.location.search)).get('token') || '';
      fetch('api/control.php?action=sync&token=' + encodeURIComponent(token))
        .then(r => r.json())
        .then(data => {
          if (data && data.ok) {
            applyStateSync(data);
          }
        })
        .catch(() => {});
    }
  }

  function sendCommand(commandName, targetKey, payload) {
    const cfg = getCfg();
    const token = cfg.token || (new URLSearchParams(window.location.search)).get('token') || '';
    const cmdId = generateUuid();
    const roomId = cfg.roomId || 0;

    const cmdData = {
      protocol: 1,
      type: 'command',
      command_id: cmdId,
      command: commandName,
      room_id: roomId,
      target_key: targetKey || '',
      payload: payload || {}
    };

    return new Promise((resolve, reject) => {
      const timer = setTimeout(() => {
        pendingAcks.delete(cmdId);
        // Se WebSocket demorar para confirmar, tenta fallback HTTP
        console.warn('[Control] Timeout de ACK no WebSocket; executando fallback HTTP para comando:', commandName);
        executeHttpCommand(commandName, targetKey, payload, cmdId)
          .then(resolve)
          .catch(reject);
      }, 3500);

      pendingAcks.set(cmdId, { resolve, reject, timer });

      if (isConnected()) {
        ws.send(JSON.stringify(cmdData));
      } else {
        clearTimeout(timer);
        pendingAcks.delete(cmdId);
        executeHttpCommand(commandName, targetKey, payload, cmdId)
          .then(resolve)
          .catch(reject);
      }
    });
  }

  async function executeHttpCommand(commandName, targetKey, payload, commandId) {
    const cfg = getCfg();
    const token = cfg.token || (new URLSearchParams(window.location.search)).get('token') || '';
    const response = await fetch('api/control.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        token: token,
        action: 'command',
        command: commandName,
        target_key: targetKey,
        payload: payload,
        command_id: commandId || generateUuid()
      })
    });
    const res = await response.json();
    if (!res.ok) {
      throw new Error(res.error || 'Falha ao executar comando administrativo');
    }
    return res;
  }

  function handleIncomingMessage(msg) {
    if (!msg || typeof msg !== 'object') return;

    // Resposta de ACK para um comando nosso
    if (msg.type === 'command.ack') {
      const pending = pendingAcks.get(msg.command_id);
      if (pending) {
        clearTimeout(pending.timer);
        pendingAcks.delete(msg.command_id);
        if (msg.status === 'applied') {
          pending.resolve(msg);
        } else {
          pending.reject(new Error(msg.error || 'Comando rejeitado pelo servidor'));
        }
      }
      return;
    }

    // Sincronização de Estado Autoritativo (Tarefa 24)
    if (msg.type === 'state.sync') {
      applyStateSync(msg);
      return;
    }

    // Comandos Administrativos (Tarefa 09, 10, 33, 34)
    if (msg.type === 'command') {
      const cmdId = msg.command_id;
      const cmd = msg.command;
      const targetKey = msg.target_key;
      const payload = msg.payload || {};
      const newVersion = msg.state_version;
      const cfg = getCfg();
      const isForMe = (targetKey === cfg.selfKey);

      // Verificação de Idempotência (Tarefa 34)
      if (cmdId && processedCommandIds.has(cmdId)) {
        sendAck(cmdId, 'applied');
        return;
      }

      // Verificação de Versão de Estado (Tarefa 33)
      if (newVersion !== undefined && newVersion !== null) {
        if (newVersion < currentStateVersion) {
          console.warn('[Control] Comando descartado por ser de versão anterior:', newVersion, '<', currentStateVersion);
          sendAck(cmdId, 'ignored');
          return;
        }
        currentStateVersion = newVersion;
      }

      let applyStatus = 'applied';
      let applyError = null;

      try {
        executeCommandLocally(cmd, targetKey, isForMe, payload, msg);
      } catch (err) {
        console.error('[Control] Erro ao aplicar comando localmente:', err);
        applyStatus = 'failed';
        applyError = err.message || 'local_execution_error';
      }

      if (cmdId) {
        processedCommandIds.add(cmdId);
        // Limita o tamanho do cache de idempotência
        if (processedCommandIds.size > 200) {
          const first = processedCommandIds.values().next().value;
          processedCommandIds.delete(first);
        }
        sendAck(cmdId, applyStatus, applyError);
      }
    }
  }

  function applyStateSync(data) {
    if (!data) return;
    const room = data.room || {};
    const part = data.participant || {};
    const cfg = getCfg();

    if (room.state_version) {
      currentStateVersion = Math.max(currentStateVersion, room.state_version);
    }

    // Atualiza permissões do participante
    if (window.MeetingMedia) {
      if (typeof part.video_allowed === 'boolean') {
        window.MeetingMedia.setVideoAdminPermission(part.video_allowed);
      }
      if (typeof part.audio_allowed === 'boolean') {
        window.MeetingMedia.setAudioAdminPermission(part.audio_allowed);
      }
      if (typeof part.screen_allowed === 'boolean') {
        window.MeetingMedia.setScreenAdminPermission(part.screen_allowed);
      }
    }

    // Sincroniza estado de Apresentação / Full (Tarefas 23, 24)
    if (window.MeetingPresentation) {
      window.MeetingPresentation.sync(room);
    }
  }

  function executeCommandLocally(command, targetKey, isForMe, payload, rawMsg) {
    const cfg = getCfg();

    switch (command) {
      // Permissão de Vídeo (Tarefas 11, 26)
      case 'participant.video.allow':
        if (isForMe && window.MeetingMedia) {
          window.MeetingMedia.setVideoAdminPermission(true);
          if (window.showToast) window.showToast('O administrador permitiu o uso de sua câmera.');
        }
        break;

      case 'participant.video.inhibit':
        if (isForMe && window.MeetingMedia) {
          window.MeetingMedia.setVideoAdminPermission(false);
          if (window.showToast) window.showToast('O administrador suspendeu o uso de sua câmera.');
        }
        break;

      // Permissão de Áudio (Tarefas 12, 13, 37, 38)
      case 'participant.audio.allow':
        if (isForMe && window.MeetingMedia) {
          window.MeetingMedia.setAudioAdminPermission(true);
          if (window.showToast) window.showToast('O administrador liberou o seu microfone.');
        }
        break;

      case 'participant.audio.inhibit':
        if (isForMe && window.MeetingMedia) {
          window.MeetingMedia.setAudioAdminPermission(false);
          if (window.showToast) window.showToast('O administrador silenciou o seu microfone.');
        }
        break;

      // Permissão de Tela (Tarefas 27, 28)
      case 'participant.screen.allow':
        if (isForMe && window.MeetingMedia) {
          window.MeetingMedia.setScreenAdminPermission(true);
          if (window.showToast) window.showToast('O administrador autorizou o compartilhamento de tela.');
        }
        break;

      case 'participant.screen.inhibit':
        if (isForMe && window.MeetingMedia) {
          window.MeetingMedia.setScreenAdminPermission(false);
          if (window.showToast) window.showToast('Compartilhamento de tela suspenso pelo administrador.');
        }
        break;

      // Início de Apresentação / Full (Tarefas 14, 15, 16)
      case 'room.presentation.start':
        const presenterKey = payload.presenter_key || targetKey;
        const mediaType = payload.media_type || 'camera';
        if (window.MeetingPresentation) {
          window.MeetingPresentation.start(presenterKey, mediaType, rawMsg.room_state);
        }
        break;

      // Término de Apresentação / Full (Tarefas 18, 21, 25)
      case 'room.presentation.end':
        if (window.MeetingPresentation) {
          window.MeetingPresentation.end(rawMsg.room_state);
        }
        break;

      // Pedidos de Palavra em Tempo Real
      case 'room.hand.raise':
      case 'room.hand.cancel':
        if (window.MeetingApp && window.MeetingApp.triggerHeartbeat) {
          window.MeetingApp.triggerHeartbeat();
        }
        break;

      // Mudança de Resolução de Exibição pelo Administrador
      case 'participant.resolution.change':
        if (isForMe && window.MeetingMedia) {
          const p = payload || {};
          window.MeetingMedia.setCustomResolution(p.width, p.height, p.fps, p.label, p.resKey);
          if (window.showToast) {
            window.showToast('O administrador ajustou sua resolução para ' + (p.label || 'definida'));
          }
        }
        break;

      case 'room.resolution.change':
        if (window.MeetingPresentation && window.MeetingPresentation.isLocalPresenter() && window.MeetingMedia) {
          const p = payload || {};
          window.MeetingMedia.setCustomResolution(p.width, p.height, p.fps, p.label, p.resKey);
          if (window.showToast) {
            window.showToast('O administrador ajustou a resolução do palco para ' + (p.label || 'definida'));
          }
        }
        break;

      // Expulsão (Tarefas 29, 30)
      case 'participant.kick':
        if (isForMe) {
          disconnect();
          if (window.MeetingMedia && window.MeetingMedia.stopAllMedia) {
            window.MeetingMedia.stopAllMedia();
          }
          if (window.MeetingWebRTC && window.MeetingWebRTC.closeAllConnections) {
            window.MeetingWebRTC.closeAllConnections();
          }
          window.location.href = 'kicked.php?room_name=' + encodeURIComponent(cfg.roomName || 'Sala');
        }
        break;

      // Encerramento da Reunião
      case 'room.close':
        disconnect();
        alert('A reunião foi encerrada pelo organizador.');
        window.location.href = 'index.php';
        break;

      default:
        console.log('[Control] Comando não reconhecido:', command);
    }
  }

  // Inicialização automática ao carregar
  window.addEventListener('DOMContentLoaded', () => {
    connect();
  });

  return {
    connect,
    disconnect,
    isConnected,
    sendAck,
    requestStateSync,
    sendCommand,
    handleIncomingMessage
  };
})(window);
