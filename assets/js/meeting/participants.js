/**
 * Gestão de Participantes, Grid de Vídeos, Sala de Espera e Contadores de Conexão
 */
(function(window) {
  'use strict';

  const participantNames = new Map();
  const lastPresence = new Map();
  let currentWaiting = [];
  const knownWaitingIds = new Set();
  let participantList = [];
  let isLocalHandRaised = false;
  let isFullModeActive = false;
  let fullTargetKey = null;
  let isScreenShareFull = false;
  let localVideoGranted = false;
  const knownHandKeys = new Set();

  function playHandChime() {
    try {
      const ctx = new (window.AudioContext || window.webkitAudioContext)();
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = 'triangle';
      osc.frequency.setValueAtTime(659.25, ctx.currentTime); // E5
      osc.frequency.setValueAtTime(987.77, ctx.currentTime + 0.15); // B5
      gain.gain.setValueAtTime(0.2, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.5);
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.start();
      osc.stop(ctx.currentTime + 0.5);
    } catch(e) {}
  }


  function escapeHtml(str) {
    return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function playLobbyChime() {
    try {
      const ctx = new (window.AudioContext || window.webkitAudioContext)();
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = 'sine';
      osc.frequency.setValueAtTime(587.33, ctx.currentTime); // D5
      osc.frequency.setValueAtTime(880, ctx.currentTime + 0.12); // A5
      gain.gain.setValueAtTime(0.15, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.4);
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.start();
      osc.stop(ctx.currentTime + 0.4);
    } catch(e) {}
  }

    function updateVideoGridCount() {
    const wrap = document.getElementById('videos');
    if (!wrap) return;
    const count = wrap.querySelectorAll('.tile').length;
    wrap.dataset.count = String(count);
  }

  function removeTile(key) {
    const tile = document.getElementById('tile-' + key);
    if (tile) {
      tile.remove();
    }
    const peerVideo = document.getElementById('peer-' + key);
    if (peerVideo) {
      try {
        if (peerVideo.srcObject) {
          peerVideo.srcObject.getTracks().forEach(t => t.stop());
          peerVideo.srcObject = null;
        }
      } catch(e) {}
      peerVideo.remove();
    }
    


            // Detecção de compartilhamento de tela com controle exclusivo de aprovação pelo Administrador
    const screenSharer = participantList.find(p => Number(p.screen_sharing) === 1 && p.participant_key !== cfg.selfKey);
    const scrToast = document.getElementById('screenShareToast');
    const scrToastText = document.getElementById('screenShareToastText');
    const scrToastBtns = document.getElementById('screenShareToastButtons');

    if (screenSharer) {
      if (cfg.CAN_ADMIT && scrToast && scrToastText && scrToastBtns) {
        if (!isFullModeActive || fullTargetKey !== screenSharer.participant_key) {
          scrToastText.textContent = `🖥️ ${screenSharer.display_name} iniciou compartilhamento de tela`;
          scrToastBtns.innerHTML = `
            <button type="button" class="sr-btn sr-btn-success" style="padding: 4px 12px; font-size: 0.8rem; border-radius: 20px; background: #10b981; border: none; color: #fff; cursor: pointer; font-weight: 600;" onclick="MeetingParticipants.approveFullMode('${screenSharer.participant_key}', true)">Aprovar Exibição Full</button>
            <button type="button" class="sr-btn sr-btn-secondary" style="padding: 4px 12px; font-size: 0.8rem; border-radius: 20px; background: rgba(255,255,255,0.12); border: none; color: #fff; cursor: pointer;" onclick="MeetingParticipants.dismissScreenToast()">Manter Grade Normal</button>
          `;
          scrToast.style.display = 'flex';
        } else {
          scrToast.style.display = 'none';
        }
      }
    } else {
      if (scrToast) scrToast.style.display = 'none';
      if (isFullModeActive && isScreenShareFull && fullTargetKey !== 'local') {
        exitFullMode();
      }
    }

    // Sincroniza estado próprio com o servidor para evitar ressurreição (Tarefa 04)
    const me = participantList.find(p => p.participant_key === cfg.selfKey);
    if (me) {
      localVideoGranted = Boolean(Number(me.video_granted));
      const serverHand = Number(me.hand_raised) === 1;
      const isPresenter = window.MeetingPresentation && window.MeetingPresentation.isLocalPresenter();
      if (!serverHand || localVideoGranted || isPresenter) {
        isLocalHandRaised = false;
      }
      updateHandBtnUI(isLocalHandRaised, localVideoGranted);
    }

    // Fila real de Pedidos de Palavra ordenada por hand_requested_at ASC (Tarefas 05, 06)
    const handRequesters = participantList
      .filter(p => Number(p.hand_raised) === 1 && p.participant_key !== cfg.selfKey)
      .sort((a, b) => {
        const tA = new Date(a.hand_requested_at || '2099-01-01').getTime();
        const tB = new Date(b.hand_requested_at || '2099-01-01').getTime();
        return tA - tB;
      });

    // Tarefa 30: Sincroniza knownHandKeys removendo quem cancelou, saiu ou foi atendido
    const activeHandKeys = new Set(handRequesters.map(r => r.participant_key));
    for (const k of knownHandKeys) {
      if (!activeHandKeys.has(k)) {
        knownHandKeys.delete(k);
      }
    }

    let hasNewHand = false;
    handRequesters.forEach(hr => {
      if (!knownHandKeys.has(hr.participant_key)) {
        knownHandKeys.add(hr.participant_key);
        hasNewHand = true;
      }
    });

    // Tarefas 24 a 31: Atualiza Indicador Compacto no Canto Superior Esquerdo
    updateRaisedHandIndicator(handRequesters, hasNewHand);

    // Desativa dependência do grande toast central (Tarefa 31)
    const handToast = document.getElementById('handToast');
    if (handToast) handToast.style.display = 'none';
    updateCounters();
    updateVideoGridCount();
  }

  function ensureTile(key) {
    let tile = document.getElementById('tile-' + key);
    if (tile) return tile;

    tile = document.createElement('div');
    tile.className = 'tile';
    tile.id = 'tile-' + key;

    const video = document.createElement('video');
    video.id = 'peer-' + key;
    video.autoplay = true;
    video.playsInline = true;
    video.setAttribute('playsinline', '');
    video.setAttribute('webkit-playsinline', '');

    const avatar = document.createElement('div');
    avatar.className = 'peer-avatar';
    avatar.id = 'avatar-' + key;
    avatar.style.cssText = 'display: none; width: 88px; height: 88px; border-radius: 50%; background: linear-gradient(135deg, #3b82f6, #00d2ff); color: #fff; font-size: 2.2rem; font-weight: 700; align-items: center; justify-content: center; position: absolute; z-index: 2; box-shadow: 0 4px 25px rgba(0,0,0,0.6);';
    const name = participantNames.get(key) || 'Participante';
    avatar.textContent = (name.trim().charAt(0) || 'U').toUpperCase();

    const nameDiv = document.createElement('div');
    nameDiv.className = 'name';
    nameDiv.id = 'name-' + key;
    nameDiv.textContent = name;

    const stateDiv = document.createElement('div');
    stateDiv.className = 'state';
    stateDiv.id = 'state-' + key;
    stateDiv.textContent = 'Conectando…';

    const muteBtn = document.createElement('button');
    muteBtn.type = 'button';
    muteBtn.className = 'tile-mute-btn';
    muteBtn.id = 'tile-mute-' + key;
    muteBtn.style.cssText = 'position: absolute; right: 12px; bottom: 12px; background: rgba(15, 23, 42, 0.85); backdrop-filter: blur(8px); border: 1px solid rgba(255, 255, 255, 0.15); color: #fff; padding: 4px 8px; border-radius: 6px; font-size: 0.78rem; cursor: pointer; z-index: 6; display: flex; align-items: center; gap: 4px; transition: all 0.2s;';
    muteBtn.innerHTML = '🔇';
    muteBtn.title = 'Desativar microfone';
    muteBtn.onclick = (e) => {
      e.stopPropagation();
      muteParticipant(key, participantNames.get(key) || 'Participante');
    };

    tile.append(video, avatar, nameDiv, stateDiv, muteBtn);
    document.getElementById('videos').appendChild(tile);
    updateVideoGridCount();
    return tile;
  }

  function updateCounters() {
    const cfg = window.MEETING_CONFIG;
    const participantsCount = participantList.length;
    const expectedPeers = Math.max(0, participantsCount - 1);
    const connectedPeers = window.MeetingWebRTC ? window.MeetingWebRTC.getConnectedPeerCount() : 0;
    const hasFailed = window.MeetingWebRTC ? window.MeetingWebRTC.hasFailedPeer() : false;

    // Atualiza badges do header
    const pCountNum = document.querySelector('.participant-count-num');
    if (pCountNum) pCountNum.textContent = participantsCount;
    const sbCount = document.getElementById('sidebarParticipantCount');
    if (sbCount) sbCount.textContent = participantsCount;

    // Atualiza contador de conexões detalhado (Requisito 17)
    const connCounter = document.getElementById('peerConnectionCounter');
    if (connCounter) {
      let statusHtml = '';
      if (hasFailed) {
        statusHtml = '<span style="color: #ef4444; font-weight: 600;">Problema de conexão</span>';
      } else if (connectedPeers === expectedPeers && expectedPeers > 0) {
        statusHtml = '<span style="color: #10b981; font-weight: 600;">Todos conectados</span>';
      } else if (expectedPeers > 0) {
        statusHtml = '<span style="color: var(--primary, #00d2ff);">Conectando...</span>';
      } else {
        statusHtml = '<span style="color: var(--text-dim, #64748b);">Aguardando outros</span>';
      }

      connCounter.innerHTML = `Participantes: <strong>${participantsCount}</strong> · Peers esperados: <strong>${expectedPeers}</strong> · Conectados: <strong>${connectedPeers}</strong> · ${statusHtml}`;
    }

    // Alerta de Limite para Mesh (Requisito 18)
    const maxMesh = cfg.MAX_MESH_PARTICIPANTS || 4;
    const meshWarning = document.getElementById('meshLimitWarning');
    if (meshWarning) {
      if (participantsCount > maxMesh) {
        meshWarning.style.display = 'flex';
      } else {
        meshWarning.style.display = 'none';
      }
    }
  }

  function renderParticipants(list) {
    participantList = list || [];
    const cfg = window.MEETING_CONFIG;
    const otherCount = participantList.filter(p => p.participant_key !== cfg.selfKey).length;
    const wn = document.getElementById('waitingNotice');
    if (wn) wn.style.display = (otherCount === 0) ? 'flex' : 'none';

    const wrap = document.getElementById('participants');
    if (wrap) wrap.innerHTML = '';

    const activeKeys = new Set();
    for (const p of participantList) {
      activeKeys.add(p.participant_key);
      participantNames.set(p.participant_key, p.display_name);
      lastPresence.set(p.participant_key, Date.now());

      // Descoberta automática de peer em qualquer ordem
      if (cfg.selfKey && p.participant_key !== cfg.selfKey) {
        if (window.MeetingWebRTC) {
          window.MeetingWebRTC.getOrCreatePeer(p.participant_key);
        }
      }

      // Atualiza lista na barra lateral
      if (wrap) {
        const isSelf = (p.participant_key === cfg.selfKey);
        const micActive = Number(p.mic_enabled) === 1;

        const div = document.createElement('div');
        div.className = 'person';
        
        div.innerHTML = `
          <div class="person-header">
            <div style="min-width: 0; flex: 1;">
              <div style="display: flex; align-items: center; gap: 6px;">
                <span class="dot" style="${micActive ? 'background: #10b981;' : 'background: #64748b;'}"></span>
                <strong style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 0.88rem; color: #fff;"></strong>
              </div>
              <div class="badges" style="font-size: 0.73rem; color: var(--text-muted, #94a3b8); margin-top: 2px;"></div>
            </div>
          </div>
          <div class="person-actions"></div>
        `;

        div.querySelector('strong').textContent = p.display_name + (isSelf ? ' (você)' : '');
        const camActive = Number(p.cam_enabled) === 1;
        const screenActive = Number(p.screen_sharing) === 1;
        const handRaised = Number(p.hand_raised) === 1;
        const isPresenter = (window.MeetingPresentation && window.MeetingPresentation.getActivePresenterKey() === p.participant_key);
        const isVideoAllowed = (p.video_admin_allowed === undefined || Number(p.video_admin_allowed) === 1);
        const isAudioAllowed = (p.audio_admin_allowed === undefined || Number(p.audio_admin_allowed) === 1);

        let micDesc = isAudioAllowed ? (micActive ? '🎙 Ativo' : '🔇 Desligado') : '🚫 Áudio bloqueado';
        let camDesc = isVideoAllowed ? (camActive ? '📹 Ativa' : '📷 Desligada') : '🚫 Vídeo bloqueado';
        let badgesTxt = micDesc + ' · ' + camDesc;
        if (screenActive) badgesTxt += ' · 🖥 Compartilhando';
        if (handRaised) badgesTxt += ' · ✋ Aguardando palavra';
        if (isPresenter) badgesTxt += ' · ⛶ Apresentando';

        div.querySelector('.badges').textContent = badgesTxt;

        const actions = div.querySelector('.person-actions');
        if (!isSelf && cfg.CAN_ADMIT) {
          // 1. Botão Microfone: Toggle Administrativo Bidirecional (Tarefas 18 a 21)
          const micBtn = document.createElement('button');
          micBtn.type = 'button';
          micBtn.className = 'participant-action-btn ' + (isAudioAllowed ? 'participant-action-warning' : 'participant-action-active');
          micBtn.innerHTML = isAudioAllowed ? '🔇 Inibir Áudio' : '🎙 Liberar Áudio';
          micBtn.title = isAudioAllowed ? `Bloquear microfone de ${p.display_name}` : `Liberar microfone de ${p.display_name}`;
          micBtn.onclick = (e) => {
            e.stopPropagation();
            toggleAudioPermission(p.participant_key, !isAudioAllowed);
          };
          actions.appendChild(micBtn);

          // 2. Botão Conceder / Retirar Palavra (Vídeo)
          const isGranted = Number(p.video_granted) === 1;
          const wordBtn = document.createElement('button');
          wordBtn.type = 'button';
          wordBtn.className = 'participant-action-btn ' + (isGranted ? 'participant-action-warning' : 'participant-action-primary');
          wordBtn.innerHTML = isGranted ? '🚫 Retirar Vídeo' : (Number(p.hand_raised) === 1 ? '✋ Aceitar Palavra' : '📹 Conceder Vídeo');
          wordBtn.title = isGranted ? `Recolher vídeo de ${p.display_name}` : `Conceder espaço de vídeo a ${p.display_name}`;
          wordBtn.onclick = (e) => {
            e.stopPropagation();
            if (isGranted) {
              revokeWord(p.participant_key, p.display_name);
            } else if (Number(p.hand_raised) === 1) {
              approveHandWithFullMode(p.participant_key, p.display_name);
            } else {
              grantWord(p.participant_key, p.display_name);
            }
          };
          actions.appendChild(wordBtn);

          // 3. Botão Exibição Full / Exibição Normal
          const isTargetFull = isFullModeActive && fullTargetKey === p.participant_key;
          const fullActionBtn = document.createElement('button');
          fullActionBtn.type = 'button';
          fullActionBtn.className = 'participant-action-btn ' + (isTargetFull ? 'participant-action-primary' : 'participant-action-secondary');
          fullActionBtn.innerHTML = isTargetFull ? '▦ Exibição Normal' : '⛶ Exibição Full';
          fullActionBtn.title = isTargetFull ? 'Voltar para a exibição normal' : `Aprovar exibição full para ${p.display_name}`;
          fullActionBtn.onclick = (e) => {
            e.stopPropagation();
            if (isTargetFull) {
              revokeFullMode();
            } else {
              approveFullMode(p.participant_key, Number(p.screen_sharing) === 1);
            }
          };
          actions.appendChild(fullActionBtn);

          // 4. Botão Expulsar (Tarefa 22)
          const kickBtn = document.createElement('button');
          kickBtn.type = 'button';
          kickBtn.className = 'participant-action-btn participant-action-danger';
          kickBtn.innerHTML = '🚫 Expulsar';
          kickBtn.title = `Expulsar ${p.display_name} da reunião (poderá tentar entrar novamente)`;
          kickBtn.onclick = (e) => {
            e.stopPropagation();
            kickParticipant(p.participant_key, p.display_name);
          };
          actions.appendChild(kickBtn);

          // 5. Botão Banir IP (Tarefa 22)
          const banBtn = document.createElement('button');
          banBtn.type = 'button';
          banBtn.className = 'participant-action-btn participant-action-danger';
          banBtn.style.gridColumn = '1 / -1';
          banBtn.innerHTML = '⛔ Banir IP';
          banBtn.title = `Banir endereço IP de ${p.display_name} desta sala permanentemente`;
          banBtn.onclick = (e) => {
            e.stopPropagation();
            banParticipant(p.participant_key, p.display_name);
          };
          actions.appendChild(banBtn);
        }

        wrap.appendChild(div);
      }

      // Atualiza tile remoto (nome, avatar e estado de câmera)
      const nameEl = document.getElementById('name-' + p.participant_key);
      if (nameEl) nameEl.textContent = p.display_name;

      const avatarEl = document.getElementById('avatar-' + p.participant_key);
      const peerVideo = document.getElementById('peer-' + p.participant_key);
      const camOn = Number(p.cam_enabled) === 1;
      const videoGranted = Number(p.video_granted) === 1;
      const handOn = Number(p.hand_raised) === 1;

      const tileEl = document.getElementById('tile-' + p.participant_key);

      // Imagem / Botão de Mãozinha na Janela de quem pediu a palavra (Apenas o Administrador pode aprovar ao clicar nela)
      if (tileEl) {
        let handAction = document.getElementById('hand-action-' + p.participant_key);
        if (handOn) {
          if (!handAction) {
            handAction = document.createElement('div');
            handAction.id = 'hand-action-' + p.participant_key;
            handAction.className = 'tile-hand-action' + (cfg.CAN_ADMIT ? ' admin-clickable' : '');
            handAction.innerHTML = '✋';
            if (cfg.CAN_ADMIT) {
              handAction.title = `✋ Clique na mãozinha para aprovar a palavra e exibir ${p.display_name} em Exibição Full (Resolução Máxima)`;
              handAction.onclick = (e) => {
                e.stopPropagation();
                approveHandWithFullMode(p.participant_key, p.display_name);
              };
            } else {
              handAction.title = `✋ ${p.display_name} pediu a palavra`;
            }
            tileEl.appendChild(handAction);
          }
          tileEl.classList.add('hand-raised-glow');
        } else {
          if (handAction) handAction.remove();
          tileEl.classList.remove('hand-raised-glow');
        }
      }

      // Exibe a mãozinha na janela do próprio usuário quando ele pede a palavra
      const localTile = document.getElementById('tile-local');
      if (localTile) {
        let localHandIcon = document.getElementById('hand-action-local');
        if (isLocalHandRaised) {
          if (!localHandIcon) {
            localHandIcon = document.createElement('div');
            localHandIcon.id = 'hand-action-local';
            localHandIcon.className = 'tile-hand-action';
            localHandIcon.innerHTML = '✋';
            localHandIcon.title = '✋ Você pediu a palavra';
            localTile.appendChild(localHandIcon);
          }
          localTile.classList.add('hand-raised-glow');
        } else {
          if (localHandIcon) localHandIcon.remove();
          localTile.classList.remove('hand-raised-glow');
        }
      }

      // Se for o próprio usuário, sincroniza se o moderador concedeu/revogou o vídeo
      if (p.participant_key === cfg.selfKey) {
        if (videoGranted !== localVideoGranted) {
          localVideoGranted = videoGranted;
          if (localVideoGranted) {
            if (window.showToast) window.showToast('🎉 O anfitrião concedeu a palavra a você! Seu vídeo agora está ativo.');
            if (window.MeetingMedia && window.MeetingMedia.setCameraEnabled) {
              window.MeetingMedia.setCameraEnabled(true);
            }
            updateHandBtnUI(false, true);
          } else if (!cfg.CAN_ADMIT) {
            if (window.showToast) window.showToast('Seu tempo de vídeo foi encerrado para dar a palavra a outro participante.');
            if (window.MeetingMedia && window.MeetingMedia.setCameraEnabled) {
              window.MeetingMedia.setCameraEnabled(false);
            }
            updateHandBtnUI(false, false);
          }
        }
      }

      // Para participantes remotos: apenas exibe o elemento <video> se video_granted === 1 e cam_enabled === 1
      // Do contrário, exibe o avatar estático reduzindo o consumo de banda e CPU
      if (avatarEl && peerVideo && p.participant_key !== cfg.selfKey) {
        avatarEl.textContent = (p.display_name.trim().charAt(0) || 'U').toUpperCase();
        const showVideo = videoGranted && camOn;
        avatarEl.style.display = showVideo ? 'none' : 'flex';
        peerVideo.style.display = showVideo ? 'block' : 'none';
      }
    }

    if (wrap && !participantList.length) {
      wrap.innerHTML = '<div class="empty">Nenhum participante online.</div>';
    }

    // Limpeza de conexões que perderam presença por mais de 25s
    if (window.MeetingWebRTC) {
      for (const key of window.MeetingWebRTC.pcs.keys()) {
        if (!activeKeys.has(key) && Date.now() - (lastPresence.get(key) || Date.now()) > 25000) {
          window.MeetingWebRTC.removePeer(key);
        }
      }
    }

    


            // Detecção de compartilhamento de tela com controle exclusivo de aprovação pelo Administrador
    const screenSharer = participantList.find(p => Number(p.screen_sharing) === 1 && p.participant_key !== cfg.selfKey);
    const scrToast = document.getElementById('screenShareToast');
    const scrToastText = document.getElementById('screenShareToastText');
    const scrToastBtns = document.getElementById('screenShareToastButtons');

    if (screenSharer) {
      if (cfg.CAN_ADMIT && scrToast && scrToastText && scrToastBtns) {
        if (!isFullModeActive || fullTargetKey !== screenSharer.participant_key) {
          scrToastText.textContent = `🖥️ ${screenSharer.display_name} iniciou compartilhamento de tela`;
          scrToastBtns.innerHTML = `
            <button type="button" class="sr-btn sr-btn-success" style="padding: 4px 12px; font-size: 0.8rem; border-radius: 20px; background: #10b981; border: none; color: #fff; cursor: pointer; font-weight: 600;" onclick="MeetingParticipants.approveFullMode('${screenSharer.participant_key}', true)">Aprovar Exibição Full</button>
            <button type="button" class="sr-btn sr-btn-secondary" style="padding: 4px 12px; font-size: 0.8rem; border-radius: 20px; background: rgba(255,255,255,0.12); border: none; color: #fff; cursor: pointer;" onclick="MeetingParticipants.dismissScreenToast()">Manter Grade Normal</button>
          `;
          scrToast.style.display = 'flex';
        } else {
          scrToast.style.display = 'none';
        }
      }
    } else {
      if (scrToast) scrToast.style.display = 'none';
      if (isFullModeActive && isScreenShareFull && fullTargetKey !== 'local') {
        exitFullMode();
      }
    }

    // Renderiza Fila de Pedidos de Palavra (Mão Levantada)
    const handRequesters = participantList.filter(p => Number(p.hand_raised) === 1 && p.participant_key !== cfg.selfKey);
    let hasNewHand = false;
    handRequesters.forEach(hr => {
      if (!knownHandKeys.has(hr.participant_key)) {
        knownHandKeys.add(hr.participant_key);
        hasNewHand = true;
      }
    });
    if (hasNewHand && handRequesters.length > 0) {
      playHandChime();
    }

    // Toast de Mão Levantada no Palco para Administrador
    const handToast = document.getElementById('handToast');
    const handToastText = document.getElementById('handToastText');
    const handToastBtns = document.getElementById('handToastButtons');

    if (cfg.CAN_ADMIT && handToast && handToastText && handToastBtns) {
      if (handRequesters.length > 0) {
        const topH = handRequesters[0];
        const extraH = handRequesters.length > 1 ? ` (+${handRequesters.length - 1} outro${handRequesters.length > 2 ? 's' : ''})` : '';
        handToastText.textContent = `✋ ${topH.display_name}${extraH} pediu a palavra`;
        handToastBtns.innerHTML = `
          <button type="button" class="sr-btn sr-btn-success" style="padding: 4px 12px; font-size: 0.8rem; border-radius: 20px; background: #10b981; border: none; color: #fff; cursor: pointer; font-weight: 600;" onclick="MeetingParticipants.approveHandWithFullMode('${topH.participant_key}', '${escapeHtml(topH.display_name)}')">✋ Aprovar e Exibir Full</button>
          <button type="button" class="sr-btn sr-btn-secondary" style="padding: 4px 12px; font-size: 0.8rem; border-radius: 20px; background: rgba(255,255,255,0.12); border: none; color: #fff; cursor: pointer;" onclick="MeetingParticipants.dismissHandToast()">Dispensar</button>
        `;
        handToast.style.display = 'flex';
      } else {
        handToast.style.display = 'none';
      }
    }
    updateCounters();
    updateVideoGridCount();
  }

  
  let prevHandCount = 0;

  function updateRaisedHandIndicator(handRequesters, hasNewHand) {
    const cfg = window.MEETING_CONFIG || {};
    const indicator = document.getElementById('raisedHandIndicator');
    const countEl = document.getElementById('raisedHandCount');
    if (!indicator) return;

    // Tarefa 25: Exclusivo do administrador (CAN_ADMIT)
    if (!cfg.CAN_ADMIT || !handRequesters || handRequesters.length === 0) {
      indicator.style.display = 'none';
      prevHandCount = 0;
      return;
    }

    const count = handRequesters.length;
    indicator.style.display = 'flex';

    // Tarefa 24: Formato visual (1 -> ✋, >1 -> ✋ count)
    if (countEl) {
      countEl.textContent = count > 1 ? String(count) : '';
    }

    // Tarefa 28: Tooltip descritivo
    const topH = handRequesters[0];
    const topName = topH.display_name || 'Participante';
    if (count === 1) {
      indicator.title = `${topName} pediu a palavra (clique para ver na aba Participantes)`;
    } else {
      indicator.title = `${topName} e mais ${count - 1} participante(s) pediram a palavra (clique para ver)`;
    }

    // Tarefa 29: Pulsar somente quando chegar um novo pedido
    if (hasNewHand || count > prevHandCount) {
      indicator.classList.remove('pulse');
      void indicator.offsetWidth; // Força reflow para reiniciar animação
      indicator.classList.add('pulse');
      playHandChime();
      setTimeout(() => {
        indicator.classList.remove('pulse');
      }, 1500);
    }

    prevHandCount = count;
  }

  function onRaisedHandIndicatorClick() {
    if (typeof openSidebarTab === 'function') {
      openSidebarTab('participants');
    }
    const handSec = document.getElementById('sidebarHandSection');
    if (handSec) {
      handSec.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      handSec.style.transition = 'box-shadow 0.3s ease';
      handSec.style.boxShadow = '0 0 14px rgba(234, 179, 8, 0.6)';
      setTimeout(() => {
        handSec.style.boxShadow = '';
      }, 1500);
    }
  }

  function renderWaitingList(waitingList) {
    const cfg = window.MEETING_CONFIG;
    if (!cfg.CAN_ADMIT) return;
    currentWaiting = waitingList || [];

    // Detecta se há novos convidados para tocar o sinal sonoro
    let hasNew = false;
    currentWaiting.forEach(w => {
      if (!knownWaitingIds.has(w.id)) {
        knownWaitingIds.add(w.id);
        hasNew = true;
      }
    });
    if (hasNew && currentWaiting.length > 0) {
      playLobbyChime();
    }

    // 1. Atualiza Seção na Barra Lateral
    const waitSec = document.getElementById('sidebarWaitingSection');
    const waitCount = document.getElementById('sidebarWaitingCount');
    const waitList = document.getElementById('sidebarWaitingList');

    if (waitSec && waitList) {
      if (currentWaiting.length > 0) {
        waitSec.style.display = 'block';
        if (waitCount) waitCount.textContent = currentWaiting.length;
        waitList.innerHTML = '';
        currentWaiting.forEach(w => {
          const item = document.createElement('div');
          item.style.cssText = 'background: rgba(15, 23, 42, 0.7); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 10px; margin-bottom: 8px; display: flex; flex-direction: column; gap: 8px;';
          
          let ipDisplay = w.request_ip ? `<div style="font-size: 0.72rem; color: #38bdf8; font-family: monospace;">IP: ${escapeHtml(w.request_ip)}</div>` : '';

          item.innerHTML = `
            <div>
              <div style="font-size: 0.86rem; font-weight: 600; color: #fff;">${escapeHtml(w.display_name)}</div>
              ${ipDisplay}
              <div style="font-size: 0.70rem; color: var(--text-muted, #94a3b8); margin-top: 2px;">Aguardando aprovação</div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 6px; width: 100%;">
              <button type="button" class="participant-action-btn participant-action-active" onclick="MeetingParticipants.admitGuest(${w.id}, 'approve')">✓ Permitir</button>
              <button type="button" class="participant-action-btn participant-action-warning" onclick="MeetingParticipants.admitGuest(${w.id}, 'reject')">✕ Recusar</button>
              <button type="button" class="participant-action-btn participant-action-danger" style="grid-column: 1 / -1;" onclick="MeetingParticipants.admitGuest(${w.id}, 'ban', '${escapeHtml(w.request_ip || '')}')">⛔ Banir IP</button>
            </div>
          `;
          waitList.appendChild(item);
        });
      } else {
        waitSec.style.display = 'none';
      }
    }

    // 2. Notificação Flutuante no Topo do Palco (Teams Style Toast)
    const toast = document.getElementById('lobbyToast');
    const toastGuest = document.getElementById('lobbyGuestName');
    const toastBtns = document.getElementById('lobbyToastButtons');

    if (toast && toastGuest && toastBtns) {
      if (currentWaiting.length > 0) {
        const g = currentWaiting[0];
        const extra = currentWaiting.length > 1 ? ` (+${currentWaiting.length - 1} outro${currentWaiting.length > 2 ? 's' : ''})` : '';
        toastGuest.textContent = `${g.display_name}${extra} está na sala de espera`;
        let ipSubtitle = g.request_ip ? `<div style="font-size: 0.72rem; color: #38bdf8; font-family: monospace; margin-top: 2px;">IP: ${escapeHtml(g.request_ip)}</div>` : '';
        toastGuest.innerHTML = `<div>${escapeHtml(g.display_name)}${extra} está na sala de espera</div>${ipSubtitle}`;
        toastBtns.innerHTML = `
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 6px; width: 100%;">
            <button type="button" class="participant-action-btn participant-action-active" onclick="MeetingParticipants.admitGuest(${g.id}, 'approve')">✓ Permitir</button>
            <button type="button" class="participant-action-btn participant-action-warning" onclick="MeetingParticipants.admitGuest(${g.id}, 'reject')">✕ Recusar</button>
            <button type="button" class="participant-action-btn participant-action-danger" style="grid-column: 1 / -1;" onclick="MeetingParticipants.admitGuest(${g.id}, 'ban', '${escapeHtml(g.request_ip || '')}')">⛔ Banir IP</button>
          </div>
        `;
        toast.style.display = 'flex';
      } else {
        toast.style.display = 'none';
      }
    }
  }

  async function admitGuest(inviteId, action, ipAddress) {
    if (action === 'ban') {
      const targetDesc = ipAddress ? `o IP ${ipAddress}` : 'este participante';
      if (!confirm(`Banir ${targetDesc} desta sala?\n\nNovas tentativas provenientes deste IP serão bloqueadas permanentemente nesta reunião.`)) {
        return;
      }
    }
    const cfg = window.MEETING_CONFIG;
    try {
      const res = await fetch('api/room_admit.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          host_token: cfg.TOKEN,
          csrf: cfg.CSRF,
          invite_id: inviteId,
          action: action
        })
      }).then(r => r.json());

      if (res.ok) {
        if (window.showToast) {
          if (action === 'approve') {
            window.showToast(`${res.display_name} foi admitido(a)!`);
          } else if (action === 'ban') {
            window.showToast(`IP de ${res.display_name} foi banido desta reunião.`);
          } else {
            window.showToast(`${res.display_name} foi recusado(a).`);
          }
        }
        currentWaiting = currentWaiting.filter(w => w.id !== inviteId);
        renderWaitingList(currentWaiting);
        if (window.MeetingSignaling) window.MeetingSignaling.refreshLobby();
      } else {
        if (window.showToast) window.showToast('Não foi possível processar a ação: ' + (res.error || ''));
      }
    } catch(e) {
      if (window.showToast) window.showToast('Erro ao processar admissão.');
    }
  }

  
  async function toggleAudioPermission(key, allow) {
    const cmd = allow ? 'participant.audio.allow' : 'participant.audio.inhibit';
    const name = participantNames.get(key) || 'Participante';
    try {
      if (window.MeetingControl && window.MeetingControl.isConnected()) {
        try {
          await window.MeetingControl.sendCommand(cmd, key, {});
        } catch (wsErr) {
          console.warn('Falha no comando WS de áudio, tentando fallback HTTP:', wsErr);
        }
      }

      // Fallback HTTP autoritativo (Tarefa 17)
      try {
        await fetch('api/control.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            token: window.MEETING_CONFIG.TOKEN,
            action: 'command',
            command: cmd,
            target_key: key,
            payload: {}
          })
        });
      } catch (httpErr) {}

      // Compatibilidade legada via sinalização WebRTC se for inibição
      if (!allow && window.MeetingSignaling) {
        await window.MeetingSignaling.sendSignal('mute-user', { action: 'mute-user' }, key);
      }

      if (window.showToast) {
        window.showToast(allow ? `Áudio de ${name} foi liberado.` : `Áudio de ${name} foi inibido pelo administrador.`);
      }

      if (window.MeetingApp && window.MeetingApp.triggerHeartbeat) {
        window.MeetingApp.triggerHeartbeat();
      }
    } catch (err) {
      console.warn('Erro ao alterar permissão de áudio:', err);
      if (window.showToast) window.showToast('Não foi possível alterar a permissão de áudio.');
    }
  }

  async function banParticipant(targetKey, displayName) {
    if (!confirm(`Banir "${displayName}" desta sala de reunião?\n\nO participante será desconectado imediatamente e qualquer nova tentativa do mesmo endereço IP será bloqueada.`)) {
      return;
    }
    const cfg = window.MEETING_CONFIG;
    try {
      if (window.MeetingControl && window.MeetingControl.isConnected()) {
        try {
          await window.MeetingControl.sendCommand('participant.kick', targetKey, { ban: true });
        } catch (ctrlErr) {
          console.warn('Falha no comando WS kick, chamando API HTTP:', ctrlErr);
        }
      }

      const resp = await fetch('api/room_kick.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
          token: cfg.TOKEN,
          target_key: targetKey,
          action: 'ban'
        })
      });
      const data = await resp.json();
      if (!resp.ok || !data.ok) {
        throw new Error(data.error || 'Falha ao banir participante');
      }

      if (window.showToast) {
        window.showToast(`🚫 ${displayName} foi expulso e seu IP foi banido da reunião.`);
      }
      removeTile(targetKey);
      if (window.MeetingApp && window.MeetingApp.triggerHeartbeat) {
        window.MeetingApp.triggerHeartbeat();
      }
    } catch (err) {
      console.error('Erro ao banir participante:', err);
      if (window.showToast) {
        window.showToast('Erro ao banir participante.');
      }
    }
  }

  async function muteParticipant(key, name) {
    return toggleAudioPermission(key, false);
  }
  async function _legacyMuteParticipant(key, name) {
    try {
      // 1. Envia comando pelo canal de controle administrativo (Tarefa 37)
      if (window.MeetingControl && window.MeetingControl.isConnected()) {
        try {
          await window.MeetingControl.sendCommand('participant.audio.inhibit', key, {});
        } catch (ctrlErr) {
          console.warn('Falha no comando WS de mute, tentando fallback:', ctrlErr);
        }
      }

      // Fallback via API HTTP autoritativa
      try {
        await fetch('api/control.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            token: window.MEETING_CONFIG.TOKEN,
            command: 'participant.audio.inhibit',
            target_key: key
          })
        });
      } catch (httpErr) {}

      // 2. Envia sinal de compatibilidade legado via WebRTC
      if (window.MeetingSignaling) {
        await window.MeetingSignaling.sendSignal('mute-user', { action: 'mute-user' }, key);
      }

      // 2. Muta imediatamente o track de áudio recebido deste peer para silêncio instantâneo
      if (window.MeetingWebRTC && window.MeetingWebRTC.pcs) {
        const pc = window.MeetingWebRTC.pcs.get(key);
        if (pc) {
          pc.getReceivers().forEach(r => {
            if (r.track && r.track.kind === 'audio') {
              r.track.enabled = false;
            }
          });
        }
      }

      if (window.showToast) {
        window.showToast(`Microfone de ${name} foi desativado.`);
      }
      window.rtcLog && window.rtcLog(key, 'mute-user-sent');
    } catch (err) {
      console.warn('Erro ao desativar microfone de participante:', err);
      if (window.showToast) {
        window.showToast('Não foi possível desativar o microfone.');
      }
    }
  }

    async function kickParticipant(targetKey, displayName) {
    if (!confirm(`Tem certeza que deseja expulsar "${displayName}" desta sala de reunião?
O participante será desconectado imediatamente.`)) {
      return;
    }
    const cfg = window.MEETING_CONFIG;
    try {
      // Notifica canal de controle em tempo real (Tarefa 29)
      if (window.MeetingControl && window.MeetingControl.isConnected()) {
        try {
          await window.MeetingControl.sendCommand('participant.kick', targetKey, {});
        } catch (ctrlErr) {
          console.warn('Falha no comando WS kick, chamando API HTTP:', ctrlErr);
        }
      }

      const resp = await fetch('api/room_kick.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
          token: cfg.TOKEN,
          target_key: targetKey
        })
      });
      let data;
      try {
        data = await resp.json();
      } catch (jsonErr) {
        throw new Error('Falha na resposta do servidor (HTTP ' + resp.status + ')');
      }

      if (!resp.ok || !data.ok) {
        const errMsg = (data && data.error) ? data.error : ('HTTP ' + resp.status);
        let userMsg = 'Não foi possível expulsar o participante: ' + errMsg;
        if (errMsg === 'only_administrators_can_kick') {
          userMsg = 'Apenas administradores da sala possuem permissão para expulsar participantes.';
        } else if (errMsg === 'cannot_kick_room_owner') {
          userMsg = 'Não é permitido expulsar o proprietário da sala.';
        } else if (errMsg === 'participant_not_found') {
          userMsg = 'O participante já não está mais na sala ou foi desconectado.';
        }
        alert(userMsg);
        return;
      }

      if (window.showToast) {
        window.showToast(`🚫 ${displayName} foi expulso da reunião.`);
      }
      if (window.MeetingWebRTC && window.MeetingWebRTC.removePeer) {
        window.MeetingWebRTC.removePeer(targetKey);
      }
      removeTile(targetKey);
      if (window.MeetingApp && window.MeetingApp.triggerHeartbeat) {
        window.MeetingApp.triggerHeartbeat();
      }
    } catch (e) {
      console.error('Falha ao expulsar participante:', e);
      alert('Não foi possível expulsar o participante: ' + (e.message || 'Verifique a conexão.'));
    }
  }

    function updateHandBtnUI(raised, granted) {
    const btn = document.getElementById('btnHand');
    const lbl = document.getElementById('handLabel');
    if (!btn) return;
    if (granted) {
      btn.classList.add('hand-active');
      if (lbl) lbl.textContent = 'Palavra Concedida';
      btn.title = 'Você está com o vídeo ativo. Clique para devolver a palavra.';
    } else if (raised) {
      btn.classList.add('hand-active');
      if (lbl) lbl.textContent = 'Mão Levantada';
      btn.title = 'Mão levantada aguardando o anfitrião. Clique para cancelar o pedido.';
    } else {
      btn.classList.remove('hand-active');
      if (lbl) lbl.textContent = 'Pedir Palavra';
      btn.title = 'Pedir a palavra para ativar seu vídeo';
    }
  }

  async function toggleRaiseHand() {
    const cfg = window.MEETING_CONFIG;
    if (window.MeetingPresentation && window.MeetingPresentation.isLocalPresenter()) {
      return;
    }
    const newAction = isLocalHandRaised ? 'cancel' : 'request';
    try {
      const resp = await fetch('api/presentation.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
          token: cfg.TOKEN,
          action: newAction
        })
      });
      const data = await resp.json();
      if (data.ok) {
        isLocalHandRaised = Boolean(data.hand_raised);
        updateHandBtnUI(isLocalHandRaised, localVideoGranted);
        if (isLocalHandRaised) {
          if (window.showToast) window.showToast('✋ Mão levantada. Aguardando aprovação do administrador.');
        } else {
          if (window.showToast) window.showToast('Você cancelou o pedido de palavra.');
        }
        if (window.MeetingApp && window.MeetingApp.triggerHeartbeat) {
          window.MeetingApp.triggerHeartbeat();
        }
      }
    } catch(e) {
      console.warn('Erro ao alternar pedido de palavra:', e);
    }
  }

  async function approveHandWithFullMode(targetKey, targetName) {
    const cfg = window.MEETING_CONFIG;
    if (!cfg.CAN_ADMIT) return;

    if (window.showToast) {
      window.showToast(`Aprovando apresentação para ${targetName}...`);
    }

    try {
      // Transação atômica única no servidor (Tarefas 06, 07)
      const resp = await fetch('api/presentation.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
          token: cfg.TOKEN,
          action: 'approve',
          target_key: targetKey,
          media_type: 'camera'
        })
      });
      const data = await resp.json();

      if (data.ok) {
        if (window.MeetingControl && window.MeetingControl.isConnected()) {
          window.MeetingControl.sendCommand('room.presentation.start', targetKey, {
            presenter_key: targetKey,
            media_type: 'camera'
          }).catch(() => {});
        }

        if (window.MeetingPresentation) {
          window.MeetingPresentation.start(targetKey, 'camera', data.room);
        }

        dismissHandToast();
        knownHandKeys.delete(targetKey);
        if (window.showToast) window.showToast(`🎉 Apresentação de ${targetName} aprovada em modo Full!`);

        if (window.MeetingApp && window.MeetingApp.triggerHeartbeat) {
          window.MeetingApp.triggerHeartbeat();
        }
      } else {
        alert('Não foi possível aprovar apresentação: ' + (data.error || 'Erro no servidor'));
      }
    } catch (e) {
      console.error('Erro ao aprovar apresentação:', e);
    }
  }

  async function rejectHandRequest(targetKey, targetName) {
    const cfg = window.MEETING_CONFIG;
    if (!cfg.CAN_ADMIT) return;

    try {
      const resp = await fetch('api/presentation.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
          token: cfg.TOKEN,
          action: 'reject',
          target_key: targetKey
        })
      });
      const data = await resp.json();
      if (data.ok) {
        dismissHandToast();
        knownHandKeys.delete(targetKey);
        if (window.showToast) window.showToast(`Pedido de palavra de ${targetName} foi recusado.`);
        if (window.MeetingApp && window.MeetingApp.triggerHeartbeat) {
          window.MeetingApp.triggerHeartbeat();
        }
      }
    } catch (e) {
      console.warn('Erro ao recusar pedido de palavra:', e);
    }
  }

  async function grantWord(targetKey, targetName) {
    const cfg = window.MEETING_CONFIG;
    try {
      const resp = await fetch('api/room_hand.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
          token: cfg.TOKEN,
          action: 'grant',
          target_key: targetKey
        })
      });
      const data = await resp.json();
      if (data.ok) {
        let msg = `📹 Vídeo concedido a ${data.granted_name || targetName}!`;
        if (data.revoked_name) {
          msg += ` (${data.revoked_name} foi recolhido para manter o limite de 5 vídeos).`;
        }
        if (window.showToast) window.showToast(msg);
        if (window.MeetingApp && window.MeetingApp.triggerHeartbeat) {
          window.MeetingApp.triggerHeartbeat();
        }
      } else {
        alert('Não foi possível conceder o vídeo: ' + (data.error || 'Falha na operação'));
      }
    } catch(e) {
      alert('Erro na comunicação com o servidor.');
    }
  }

  async function revokeWord(targetKey, targetName) {
    const cfg = window.MEETING_CONFIG;
    try {
      const resp = await fetch('api/room_hand.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
          token: cfg.TOKEN,
          action: 'revoke',
          target_key: targetKey
        })
      });
      const data = await resp.json();
      if (data.ok) {
        if (window.showToast) window.showToast(`Vídeo de ${targetName} foi recolhido.`);
        if (window.MeetingApp && window.MeetingApp.triggerHeartbeat) {
          window.MeetingApp.triggerHeartbeat();
        }
      }
    } catch(e) {}
  }

      async function approveFullMode(targetKey, isScreen = false) {
    const cfg = window.MEETING_CONFIG;
    if (!cfg.CAN_ADMIT) {
      if (window.showToast) window.showToast('Apenas o administrador pode aprovar a exibição full.');
      return;
    }

    try {
      const resp = await fetch('api/room_hand.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
          token: cfg.TOKEN,
          action: 'set_full',
          target_key: targetKey,
          is_screen: isScreen ? 1 : 0
        })
      });
      const data = await resp.json();
      if (data.ok) {
        enterFullMode(targetKey, isScreen);
        dismissScreenToast();
        if (window.showToast) window.showToast('⛶ Exibição Full ativada em resolução máxima para toda a sala.');
      } else {
        alert('Não foi possível aprovar exibição full: ' + (data.error || 'Falha na operação'));
      }
    } catch (e) {
      // Fallback local caso rede falhe
      enterFullMode(targetKey, isScreen);
    }
  }

  async function revokeFullMode() {
    const cfg = window.MEETING_CONFIG;
    if (cfg.CAN_ADMIT) {
      try {
        await fetch('api/room_hand.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({
            token: cfg.TOKEN,
            action: 'exit_full'
          })
        });
      } catch(e) {}
    }
    exitFullMode();
    dismissScreenToast();
    if (window.showToast) window.showToast('▦ Retornado para a Exibição Normal (Grade).');
  }

  function dismissScreenToast() {
    const scrToast = document.getElementById('screenShareToast');
    if (scrToast) scrToast.style.display = 'none';
  }

    function resolveParticipantTile(participantKey) {
    if (window.MeetingPresentation && typeof window.MeetingPresentation.resolveParticipantTile === 'function') {
      return window.MeetingPresentation.resolveParticipantTile(participantKey);
    }
    const cfg = window.MEETING_CONFIG || {};
    if (!participantKey || participantKey === 'local' || participantKey === cfg.selfKey) {
      return document.getElementById('tile-local');
    }
    return document.getElementById('tile-' + participantKey);
  }

  function enterFullMode(targetKey, isScreenShare = false) {
    const cfg = window.MEETING_CONFIG;
    const grid = document.getElementById('videos');
    if (!grid) return;

    const targetTile = resolveParticipantTile(targetKey);
    if (!targetTile) return;

    isFullModeActive = true;
    fullTargetKey = targetKey;
    isScreenShareFull = isScreenShare;

    grid.classList.add('full-mode');
    document.querySelectorAll('#videos .tile').forEach(t => t.classList.remove('full-spotlight'));
    targetTile.classList.add('full-spotlight');

    // 1. Pausa as demais exibições de vídeo para economia total de banda e CPU
    document.querySelectorAll('#videos .tile:not(.full-spotlight) video').forEach(v => {
      try { v.pause(); } catch(e) {}
    });

    // Desativa tracks de vídeo dos outros participantes no WebRTC para não puxar dados desnecessários
    if (window.MeetingWebRTC && window.MeetingWebRTC.pcs) {
      window.MeetingWebRTC.pcs.forEach((pc, pKey) => {
        pc.getReceivers().forEach(r => {
          if (r.track && r.track.kind === 'video') {
            r.track.enabled = (pKey === targetKey);
          }
        });
      });
    }

    // 2. Garante reprodução ativa e resolução máxima para a exibição full
    const spotlightVideo = targetTile.querySelector('video');
    if (spotlightVideo) {
      try {
        spotlightVideo.play().catch(() => {});
        spotlightVideo.style.display = 'block';
      } catch(e) {}
    }
    const avatar = targetTile.querySelector('.peer-avatar, #localAvatar');
    if (avatar) avatar.style.display = 'none';

    // Eleva bitrate para máxima qualidade se o stream for transmitido localmente (câmera ou tela)
    if (targetKey === 'local' && window.MeetingWebRTC && window.MeetingWebRTC.setSpotlightBitrate) {
      window.MeetingWebRTC.setSpotlightBitrate(true);
    }

    // 3. Exibe o banner de controle de modo full
    const banner = document.getElementById('fullModeBanner');
    const titleEl = document.getElementById('fullModeTitle');
    const targetName = (targetKey === 'local') ? `Você (${cfg.displayName || ''})` : (participantNames.get(targetKey) || 'Participante');
    if (banner && titleEl) {
      titleEl.textContent = isScreenShare ? `🖥️ Compartilhamento de Tela - ${targetName}` : `⛶ Exibição Full - ${targetName}`;
      banner.style.display = 'flex';
    }
    const dockBtn = document.getElementById('btnNormalViewDock');
    if (dockBtn) dockBtn.style.display = 'inline-flex';
  }

  function exitFullMode() {
    if (!isFullModeActive) return;
    isFullModeActive = false;
    fullTargetKey = null;
    isScreenShareFull = false;

    const grid = document.getElementById('videos');
    if (grid) grid.classList.remove('full-mode');
    document.querySelectorAll('#videos .tile').forEach(t => t.classList.remove('full-spotlight'));

    const banner = document.getElementById('fullModeBanner');
    if (banner) banner.style.display = 'none';
    const dockBtn = document.getElementById('btnNormalViewDock');
    if (dockBtn) dockBtn.style.display = 'none';

    // 1. Despausa os vídeos dos participantes
    document.querySelectorAll('#videos .tile video').forEach(v => {
      try { v.play().catch(() => {}); } catch(e) {}
    });

    // 2. Restaura estado dos tracks de vídeo recebidos no WebRTC
    if (window.MeetingWebRTC && window.MeetingWebRTC.pcs) {
      window.MeetingWebRTC.pcs.forEach((pc, pKey) => {
        const pData = participantList.find(p => p.participant_key === pKey);
        const shouldShow = pData && Number(pData.video_granted) === 1 && Number(pData.cam_enabled) === 1;
        pc.getReceivers().forEach(r => {
          if (r.track && r.track.kind === 'video') {
            r.track.enabled = Boolean(shouldShow);
          }
        });
      });
    }

    // Retorna bitrate de envio para o padrão otimizado (350 kbps)
    if (window.MeetingWebRTC && window.MeetingWebRTC.setSpotlightBitrate) {
      window.MeetingWebRTC.setSpotlightBitrate(false);
    }

    // Restaura perfis de mídia e saída local (Tarefas 18 e 21)
    if (window.MeetingMedia) {
      if (typeof window.MeetingMedia.resumeOutgoingVideo === 'function') {
        window.MeetingMedia.resumeOutgoingVideo();
      }
      if (typeof window.MeetingMedia.applyNormalVideoProfile === 'function') {
        window.MeetingMedia.applyNormalVideoProfile();
      }
    }

    // Re-renderiza o estado da grade normal
    renderParticipants(participantList);
  }

  function toggleFullMode(targetKey) {
    if (isFullModeActive && fullTargetKey === targetKey) {
      exitFullMode();
    } else {
      enterFullMode(targetKey, false);
    }
  }

  function dismissHandToast() {
    const handToast = document.getElementById('handToast');
    if (handToast) handToast.style.display = 'none';
  }

  window.MeetingParticipants = {
    renderParticipants,
    renderWaitingList,
    kickParticipant,
    toggleRaiseHand,
    approveHandWithFullMode,
    rejectHandRequest,
    clearLocalHand: () => { isLocalHandRaised = false; updateHandBtnUI(false, localVideoGranted); },
    approveFullMode,
    revokeFullMode,
    dismissScreenToast,
    enterFullMode,
    exitFullMode,
    toggleFullMode,
    isFullMode: () => isFullModeActive,
    playHandChime,
    grantWord,
    revokeWord,
    dismissHandToast,
    hasVideoGranted: () => localVideoGranted || Boolean(window.MEETING_CONFIG && window.MEETING_CONFIG.CAN_ADMIT),
    isHandRaised: () => isLocalHandRaised,
    admitGuest,
    muteParticipant,
    toggleAudioPermission,
    banParticipant,
    onRaisedHandIndicatorClick,
    updateRaisedHandIndicator,
    removeTile,
    ensureTile,
    updateCounters,
    updateVideoGridCount,
    getParticipantName: (key) => participantNames.get(key) || 'Participante',
    resolveParticipantTile,
    getParticipants: () => participantList
  };
})(window);


// Inicializa a contagem inicial da grade de videos na carga da pagina
window.addEventListener('DOMContentLoaded', () => {
  if (window.MeetingParticipants && typeof window.MeetingParticipants.updateVideoGridCount === 'function') {
    window.MeetingParticipants.updateVideoGridCount();
  }
});
