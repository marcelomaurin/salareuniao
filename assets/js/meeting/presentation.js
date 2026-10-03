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
    if (activePresenterKey) return activePresenterKey;
    const cfg = getCfg();
    if (cfg.initialRuntimeState && cfg.initialRuntimeState.active_presenter_key) {
      return cfg.initialRuntimeState.active_presenter_key;
    }
    if (window.MeetingParticipants && typeof window.MeetingParticipants.getParticipants === 'function') {
      const pList = window.MeetingParticipants.getParticipants() || [];
      const granted = pList.find(p => Number(p.video_granted) === 1);
      if (granted) return granted.participant_key;
      const adminPart = pList.find(p => Number(p.is_admin) === 1 || (p.display_name && p.display_name.toLowerCase().includes('administrador')));
      if (adminPart) return adminPart.participant_key;
    }
    return cfg.CAN_ADMIT ? cfg.selfKey : null;
  }

  function updateStageUI() {
    const stageArea = document.getElementById('stageArea');
    const audienceStrip = document.getElementById('audienceStrip');
    const cfg = getCfg();

    if (!stageArea || !audienceStrip) return;

    // Determina quem é o apresentador que fica EM CIMA
    const presenterKey = getActivePresenterKey();

    // Atualiza o rótulo superior com o nome do usuário que está apresentando
    const titleEl = document.getElementById('stagePresenterName') || document.querySelector('.stage-header-title');
    if (titleEl) {
      let presName = '';
      if (presenterKey && presenterKey === cfg.selfKey) {
        presName = cfg.displayName || 'Você';
      } else if (presenterKey && window.MeetingParticipants && typeof window.MeetingParticipants.getParticipantName === 'function') {
        presName = window.MeetingParticipants.getParticipantName(presenterKey);
      }
      if (!presName || presName === 'Participante') {
        const presTile = document.getElementById('tile-' + presenterKey);
        if (presTile) {
          const nameSpan = presTile.querySelector('.name');
          if (nameSpan && nameSpan.textContent) {
            presName = nameSpan.textContent.replace(/^Você \(|\)$/g, '').trim();
          }
        }
      }
      if (!presName) {
        presName = cfg.CAN_ADMIT ? (cfg.displayName || 'Você') : 'Administrador';
      }
      titleEl.textContent = presName;
    }

    const allTiles = document.querySelectorAll('.tile');

    allTiles.forEach(tile => {
      const tileId = tile.id;
      const isMe = (tileId === 'tile-local');
      const tileKey = isMe ? cfg.selfKey : tileId.replace('tile-', '');
      const isPresenter = (tileKey === presenterKey) || (isMe && presenterKey === cfg.selfKey);

      if (isPresenter) {
        // O QUE ESTÁ TRANSMITINDO FICA EM CIMA (Palco Principal)
        if (tile.parentElement !== stageArea) {
          stageArea.appendChild(tile);
        }
        tile.classList.add('stage-speaker');
        tile.classList.remove('audience-listener');
        
        const v = tile.querySelector('video');
        if (v) {
          v.style.display = 'block';
          try { v.play().catch(() => {}); } catch(e) {}
        }
        const av = tile.querySelector('.peer-avatar, #localAvatar');
        if (av) av.style.display = 'none';

        const stateEl = tile.querySelector('.state');
        if (stateEl) {
          stateEl.textContent = 'AO VIVO';
          stateEl.style.background = '#10b981';
          stateEl.style.color = '#fff';
        }
      } else {
        // OS QUE ESTÃO PARTICIPANDO FICAM EM BAIXO (Ouvintes)
        if (tile.parentElement !== audienceStrip) {
          audienceStrip.appendChild(tile);
        }
        tile.classList.add('audience-listener');
        tile.classList.remove('stage-speaker');

        const v = tile.querySelector('video');
        if (v) v.style.display = 'none';
        
        const av = tile.querySelector('.peer-avatar, #localAvatar');
        if (av) av.style.display = 'flex';

        const stateEl = tile.querySelector('.state');
        if (stateEl) {
          const isHand = tile.classList.contains('hand-raised-glow');
          stateEl.textContent = isHand ? '✋ MÃO' : 'OUVINTE';
          stateEl.style.background = isHand ? 'rgba(234, 179, 8, 0.4)' : 'rgba(100, 116, 139, 0.3)';
          stateEl.style.color = isHand ? '#fef08a' : '#94a3b8';
        }
      }
    });

    if (window.MeetingParticipants && typeof window.MeetingParticipants.updateAudienceNavButtons === 'function') {
      window.MeetingParticipants.updateAudienceNavButtons();
    }

    // Atualiza botão de Pedir Palavra para o orador
    const btnHand = document.getElementById('btnHand');
    const handLabel = document.getElementById('handLabel');
    if (btnHand && handLabel) {
      if (isLocalPresenter()) {
        btnHand.classList.add('presenting-active');
        btnHand.disabled = true;
        handLabel.textContent = 'Transmitindo';
      } else {
        btnHand.classList.remove('presenting-active');
        btnHand.disabled = false;
        if (window.MeetingParticipants && window.MeetingParticipants.isHandRaised()) {
          handLabel.textContent = 'Mão Levantada';
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
      // ORADOR ATIVO (Único que transmite na sala e fica no topo):
      if (window.MeetingParticipants) {
        window.MeetingParticipants.clearLocalHand();
      }
      if (window.MeetingMedia) {
        window.MeetingMedia.setVideoAdminPermission(true);
        window.MeetingMedia.setVideoRoomPermission(true);
        window.MeetingMedia.setVideoUserPreference(true);
        window.MeetingMedia.setAudioAdminPermission(true);
        window.MeetingMedia.setAudioRoomPermission(true);
        window.MeetingMedia.setAudioUserPreference(true);

        if (typeof window.MeetingMedia.ensureCameraActive === 'function') {
          await window.MeetingMedia.ensureCameraActive();
        } else if (typeof window.MeetingMedia.resumeOutgoingVideo === 'function') {
          await window.MeetingMedia.resumeOutgoingVideo();
        }

        if (typeof window.MeetingMedia.ensureAudioActive === 'function') {
          await window.MeetingMedia.ensureAudioActive();
        }

        if (typeof window.MeetingMedia.applyFullscreenVideoProfile === 'function') {
          await window.MeetingMedia.applyFullscreenVideoProfile();
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
    updateStageUI();
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
    const mTakeBack = document.getElementById('mobileBtnTakeBack');
    if (mTakeBack) mTakeBack.style.display = shouldShow ? 'flex' : 'none';
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


  // =========================================================================
  // GESTÃO DE RESOLUÇÃO DE EXIBIÇÃO PELO ADMINISTRADOR (BOTÃO DIREITO NO VÍDEO)
  // =========================================================================
  const RESOLUTION_MAP = {
    '1080p': { label: '1080p (Full HD - 1920×1080)', width: 1920, height: 1080, fps: 15 },
    '720p':  { label: '720p (HD - 1280×720)',       width: 1280, height: 720,  fps: 15 },
    '480p':  { label: '480p (SD - 854×480)',        width: 854,  height: 480,  fps: 15 },
    '360p':  { label: '360p (Normal - 640×360)',    width: 640,  height: 360,  fps: 15 },
    '240p':  { label: '240p (Baixa - 320×240)',     width: 320,  height: 240,  fps: 12 },
    '120p':  { label: '120p (Econômica - 160×120)', width: 160,  height: 120,  fps: 10 },
    'auto':  { label: '⚡ Automática (Adaptativa)',   width: null, height: null, fps: 15 }
  };

  let contextTargetKey = null;

  function showContextMenu(e, targetKey, targetName) {
    const menu = document.getElementById('videoResolutionContextMenu');
    if (!menu) return;

    contextTargetKey = targetKey;
    const subTitleEl = document.getElementById('vcmTargetSubtitle');
    if (subTitleEl) {
      subTitleEl.textContent = targetName ? `Exibição: ${targetName}` : 'Palco Principal';
    }

    // Marca a opção ativa
    const currentKey = window.MeetingMedia ? window.MeetingMedia.getCurrentResolutionKey() : 'auto';
    menu.querySelectorAll('[data-res]').forEach(el => {
      el.classList.toggle('selected', el.getAttribute('data-res') === currentKey);
    });

    menu.style.display = 'block';

    const menuWidth = menu.offsetWidth || 250;
    const menuHeight = menu.offsetHeight || 290;
    let posX = e.clientX;
    let posY = e.clientY;

    if (posX + menuWidth > window.innerWidth - 10) {
      posX = window.innerWidth - menuWidth - 10;
    }
    if (posY + menuHeight > window.innerHeight - 10) {
      posY = window.innerHeight - menuHeight - 10;
    }
    if (posX < 10) posX = 10;
    if (posY < 10) posY = 10;

    menu.style.left = posX + 'px';
    menu.style.top = posY + 'px';
  }


  let stageFitMode = 'cover';

  function toggleStageFit(forcedMode) {
    const stage = document.getElementById('stageArea');
    if (!stage) return;

    if (forcedMode) {
      stageFitMode = forcedMode;
    } else {
      stageFitMode = (stageFitMode === 'cover') ? 'contain' : 'cover';
    }

    if (stageFitMode === 'contain') {
      stage.classList.add('fit-contain');
    } else {
      stage.classList.remove('fit-contain');
    }

    hideContextMenu();

    const fitLabel = document.getElementById('vcmFitLabel');
    if (fitLabel) {
      fitLabel.textContent = (stageFitMode === 'cover') 
        ? 'Ajustar Proporção (Sem Cortes)' 
        : 'Preencher Tela Toda (Sem Bordas)';
    }

    if (window.showToast) {
      window.showToast(stageFitMode === 'cover' 
        ? 'Exibição: Preenchendo a tela toda' 
        : 'Exibição: Ajustando à proporção original');
    }
  }

  function hideContextMenu() {
    const menu = document.getElementById('videoResolutionContextMenu');
    if (menu) menu.style.display = 'none';
  }

  async function changeDisplayResolution(resKey) {
    const cfg = getCfg();
    if (!cfg.CAN_ADMIT) return;

    const resDef = RESOLUTION_MAP[resKey];
    if (!resDef) return;

    hideContextMenu();

    const isLocal = (!contextTargetKey || contextTargetKey === cfg.selfKey || contextTargetKey === 'local');

    if (isLocal) {
      // Aplica na transmissão local
      if (window.MeetingMedia) {
        await window.MeetingMedia.setCustomResolution(resDef.width, resDef.height, resDef.fps, resDef.label, resKey);
      }
    } else {
      // Envia comando para o participante remoto que está transmitindo
      if (window.MeetingControl) {
        try {
          await window.MeetingControl.sendCommand('participant.resolution.change', contextTargetKey, {
            width: resDef.width,
            height: resDef.height,
            fps: resDef.fps,
            label: resDef.label,
            resKey: resKey
          });
          if (window.showToast) {
            window.showToast(`Solicitada resolução ${resDef.label} para o participante em exibição.`);
          }
        } catch (err) {
          console.warn('Erro ao enviar comando de resolução:', err);
        }
      }
    }
  }

  // Listener para clique com botão direito na imagem em exibição
  document.addEventListener('contextmenu', (e) => {
    const cfg = getCfg();
    if (!cfg.CAN_ADMIT) return;

    // Detecta se o clique foi na área de palco, no container de vídeo ou no próprio elemento de vídeo
    const stage = e.target.closest('#stageArea') || e.target.closest('.video-tile') || e.target.closest('video');
    if (stage) {
      e.preventDefault();
      e.stopPropagation();

      const tile = e.target.closest('.video-tile') || (document.getElementById('stageArea') ? document.getElementById('stageArea').querySelector('.video-tile') : null);
      let targetKey = cfg.selfKey;
      let targetName = 'Apresentador';

      if (tile) {
        if (tile.id === 'tile-local') {
          targetKey = cfg.selfKey;
          targetName = 'Você (Local)';
        } else {
          targetKey = tile.id.replace('tile-', '');
          const nameEl = tile.querySelector('.name');
          targetName = nameEl ? nameEl.textContent : targetKey;
        }
      } else {
        const pres = activePresenterKey;
        if (pres) targetKey = pres;
      }

      showContextMenu(e, targetKey, targetName);
    }
  });

  // Fecha o menu de contexto ao clicar em qualquer outro lugar
  document.addEventListener('click', (e) => {
    const menu = document.getElementById('videoResolutionContextMenu');
    if (menu && !menu.contains(e.target)) {
      hideContextMenu();
    }
  });

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
    takeBackConduction,
    changeDisplayResolution,
    showContextMenu,
    hideContextMenu,
    toggleStageFit
  };
})(window);
