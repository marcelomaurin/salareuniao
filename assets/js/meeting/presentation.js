/**
 * Maurinsoft Sala Reunião - Gestor de Modo de Apresentação e Full (MeetingPresentation)
 * - Orquestração central de Apresentação / Full / Spotlight
 * - Resolução unificada de Tiles (resolveParticipantTile): mapeia selfKey -> tile-local
 * - Otimização de mídia: apresentador em alta resolução, demais suspendem envio de vídeo
 * - Restauração fiel de estado prévio ao término da explanação
 */
(function(window) {
  'use strict';

  let presentationActive = false;
  let activePresenterKey = null;
  let activeMediaType = 'camera';
  let presentationStartedAt = null;

  function getCfg() {
    return window.MEETING_CONFIG || {};
  }

  // Resolução unificada de Tiles (Tarefa 22)
  function resolveParticipantTile(participantKey) {
    const cfg = getCfg();
    if (!participantKey || participantKey === cfg.selfKey || participantKey === 'local') {
      return document.getElementById('tile-local') || document.getElementById('local') || document.querySelector('.tile.local');
    }
    return document.getElementById('tile-' + participantKey) || document.querySelector(`[data-key="${participantKey}"]`);
  }

  function isActive() {
    return presentationActive;
  }

  function isLocalPresenter() {
    const cfg = getCfg();
    return presentationActive && (activePresenterKey === cfg.selfKey);
  }

  function getActivePresenterKey() {
    return activePresenterKey;
  }

  function updateStageUI() {
    const stageContainer = document.getElementById('videos') || document.getElementById('videoGrid');
    const allTiles = document.querySelectorAll('.tile');

    if (!stageContainer) return;

    if (presentationActive && activePresenterKey) {
      stageContainer.classList.add('presentation-mode');
      const presenterTile = resolveParticipantTile(activePresenterKey);

      allTiles.forEach(tile => {
        if (tile === presenterTile) {
          tile.classList.add('tile-presentation-full');
          tile.classList.remove('tile-presentation-mini');
          tile.style.display = 'flex';
        } else {
          tile.classList.remove('tile-presentation-full');
          tile.classList.add('tile-presentation-mini');
        }
      });
    } else {
      stageContainer.classList.remove('presentation-mode');
      allTiles.forEach(tile => {
        tile.classList.remove('tile-presentation-full', 'tile-presentation-mini');
      });
    }

    // Atualiza botão de Pedir Palavra para o apresentador (Tarefa 40)
    const btnHand = document.getElementById('btnHand');
    const handLabel = document.getElementById('handLabel');
    if (btnHand && handLabel) {
      if (isLocalPresenter()) {
        btnHand.classList.add('presenting-active');
        btnHand.disabled = true;
        handLabel.textContent = 'Apresentando';
      } else {
        btnHand.classList.remove('presenting-active');
        btnHand.disabled = false;
        if (window.MeetingParticipants && window.MeetingParticipants.isHandRaised()) {
          handLabel.textContent = 'Aguardando Aprovação';
        } else {
          handLabel.textContent = 'Pedir Palavra';
        }
      }
    }
  }

  // Início de Apresentação (Tarefas 14, 15, 16)
  async function start(presenterKey, mediaType, roomState) {
    const cfg = getCfg();
    presentationActive = true;
    activePresenterKey = presenterKey;
    activeMediaType = mediaType || 'camera';
    presentationStartedAt = (roomState && roomState.presentation_started_at) || new Date().toISOString();

    const isMe = (presenterKey === cfg.selfKey);

    if (isMe) {
      // ORADOR ATIVO (Único que transmite na sala):
      if (window.MeetingParticipants) {
        window.MeetingParticipants.clearLocalHand();
      }
      if (window.MeetingMedia) {
        window.MeetingMedia.setVideoRoomPermission(true);
        window.MeetingMedia.setVideoUserPreference(true);
        window.MeetingMedia.setAudioRoomPermission(true);
        window.MeetingMedia.setAudioUserPreference(true);
        if (typeof window.MeetingMedia.resumeOutgoingVideo === 'function') {
          await window.MeetingMedia.resumeOutgoingVideo();
        }
        if (typeof window.MeetingMedia.applyFullscreenVideoProfile === 'function') {
          await window.MeetingMedia.applyFullscreenVideoProfile(); // 1024x768 @ 30fps!
        }
      }
      if (window.showToast) {
        window.showToast('🎙️ Você está com a palavra e transmitindo áudio e vídeo.');
      }
    } else {
      // DEMAIS PARTICIPANTES (Ouvintes / Espectadores - Não transmitem):
      if (window.MeetingMedia) {
        if (typeof window.MeetingMedia.suspendOutgoingVideo === 'function') {
          await window.MeetingMedia.suspendOutgoingVideo();
        }
        window.MeetingMedia.setAudioRoomPermission(false);
      }
      if (window.showToast) {
        const pName = (roomState && roomState.presenter_name) || (window.MeetingParticipants ? window.MeetingParticipants.getParticipantName(presenterKey) : 'Participante');
        window.showToast(`Modo transmissão única: ${pName} está com a palavra.`);
      }
    }

    updateStageUI();
    updateConductionButtons();
    if (window.MeetingParticipants && window.MeetingParticipants.updateAdminControls) {
      window.MeetingParticipants.updateAdminControls();
    }
  }

  // Término de Apresentação (Tarefas 18, 21, 25, 56, 57)
  async function end(roomState) {
    const wasMe = isLocalPresenter();
    const oldPresenterKey = activePresenterKey;
    presentationActive = false;
    activePresenterKey = null;
    activeMediaType = 'camera';
    presentationStartedAt = null;

    if (wasMe) {
      // Apresentador restaura perfil normal (Tarefa 21)
      if (window.MeetingMedia) {
        await window.MeetingMedia.applyNormalVideoProfile();
      }
      if (window.MeetingBridge) {
        window.MeetingBridge.stopPublishing();
      }
    } else {
      // Demais participantes recalculam vídeo efetivo e restauram (Tarefa 18)
      if (window.MeetingMedia) {
        await window.MeetingMedia.resumeOutgoingVideo();
      }
      if (window.MeetingBridge && oldPresenterKey) {
        window.MeetingBridge.unsubscribe(oldPresenterKey);
      }
    }

    updateStageUI();
    if (window.showToast) {
      window.showToast('Apresentação encerrada. Grade normal restaurada.');
    }
    if (window.MeetingParticipants && window.MeetingParticipants.updateAdminControls) {
      window.MeetingParticipants.updateAdminControls();
    }
  }

  // Sincronização autoritativa (Tarefa 23, 24)
  function sync(state) {
    if (!state) return;
    const isPresentation = (state.room_mode === 'presentation' && state.active_presenter_key);

    if (isPresentation) {
      if (!presentationActive || activePresenterKey !== state.active_presenter_key) {
        start(state.active_presenter_key, state.presentation_media_type, state);
      }
    } else {
      if (presentationActive) {
        end(state);
      }
    }
  }

  // Inicialização com estado prévio do servidor (Tarefa 23)
  window.addEventListener('DOMContentLoaded', () => {
    const cfg = getCfg();
    if (cfg.initialRuntimeState) {
      sync(cfg.initialRuntimeState);
    }
  });


  function updateConductionButtons() {
    const cfg = getCfg();
    const btnDock = document.getElementById('btnTakeBackConduction');
    const btnBanner = document.getElementById('btnTakeBackBanner');

    // Botão de retomar condução visível apenas para o Administrador quando outro estiver falando
    const isOtherSpeaking = presentationActive && (activePresenterKey !== cfg.selfKey);
    const shouldShow = Boolean(cfg.CAN_ADMIT && isOtherSpeaking);

    if (btnDock) btnDock.style.display = shouldShow ? 'inline-flex' : 'none';
    if (btnBanner) btnBanner.style.display = shouldShow ? 'inline-flex' : 'none';
  }

  async function takeBackConduction() {
    const cfg = getCfg();
    if (!cfg.CAN_ADMIT) return;

    if (window.showToast) {
      window.showToast('🎙️ Retomando a condução da reunião...');
    }

    try {
      // 1. Envia comando pelo canal WebSocket de controle para toda a sala
      if (window.MeetingControl) {
        await window.MeetingControl.sendCommand('room.presentation.start', cfg.selfKey, {
          presenter_key: cfg.selfKey,
          media_type: 'camera'
        });
      }

      // 2. Persiste autoritativamente no backend
      const resp = await fetch('api/presentation.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
          token: cfg.TOKEN,
          action: 'approve',
          target_key: cfg.selfKey,
          media_type: 'camera'
        })
      });
      const data = await resp.json();
      if (data && data.ok) {
        await start(cfg.selfKey, 'camera', data.room);
      }
    } catch (e) {
      console.warn('Erro ao retomar condução:', e);
      await start(cfg.selfKey, 'camera');
    }
  }

  window.MeetingPresentation = {
    start,
    end,
    sync,
    isActive,
    isLocalPresenter,
    getActivePresenterKey,
    resolveParticipantTile,
    updateStageUI,
    updateConductionButtons,
    takeBackConduction
  };
})(window);
