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

    tile.append(video, avatar, nameDiv, stateDiv);
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
        const div = document.createElement('div');
        div.className = 'person';
        div.innerHTML = '<div><span class="dot"></span><strong></strong></div><div class="badges"></div>';
        div.querySelector('strong').textContent = p.display_name + (p.participant_key === cfg.selfKey ? ' (você)' : '');
        div.querySelector('.badges').textContent = 
          (Number(p.mic_enabled) ? '🎙 mic' : '🔇 sem mic') + ' · ' +
          (Number(p.cam_enabled) ? '📹 câmera' : '🚫 sem câmera') +
          (Number(p.screen_sharing) ? ' · 🖥 tela' : '');
        wrap.appendChild(div);
      }

      // Atualiza tile remoto (nome, avatar e estado de câmera)
      const nameEl = document.getElementById('name-' + p.participant_key);
      if (nameEl) nameEl.textContent = p.display_name;

      const avatarEl = document.getElementById('avatar-' + p.participant_key);
      const peerVideo = document.getElementById('peer-' + p.participant_key);
      const camOn = Number(p.cam_enabled) === 1;

      if (avatarEl && peerVideo && p.participant_key !== cfg.selfKey) {
        avatarEl.textContent = (p.display_name.trim().charAt(0) || 'U').toUpperCase();
        avatarEl.style.display = camOn ? 'none' : 'flex';
        peerVideo.style.display = camOn ? 'block' : 'none';
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

    updateCounters();
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
          item.style.cssText = 'background: rgba(15, 23, 42, 0.7); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 8px 10px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center;';
          item.innerHTML = `
            <div>
              <div style="font-size: 0.84rem; font-weight: 600; color: #fff;">${escapeHtml(w.display_name)}</div>
              <div style="font-size: 0.72rem; color: var(--text-muted, #94a3b8);">Aguardando aprovação</div>
            </div>
            <div style="display: flex; gap: 6px;">
              <button type="button" class="sr-btn sr-btn-success" style="padding: 3px 8px; font-size: 0.74rem; border-radius: 4px; background: #10b981; border: none; color: #fff; cursor: pointer;" onclick="MeetingParticipants.admitGuest(${w.id}, 'approve')">Permitir</button>
              <button type="button" class="sr-btn sr-btn-danger" style="padding: 3px 8px; font-size: 0.74rem; border-radius: 4px; background: #ef4444; border: none; color: #fff; cursor: pointer;" onclick="MeetingParticipants.admitGuest(${w.id}, 'reject')">Recusar</button>
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
        toastBtns.innerHTML = `
          <button type="button" class="sr-btn sr-btn-success" style="padding: 4px 12px; font-size: 0.8rem; border-radius: 20px; background: #10b981; border: none; color: #fff; cursor: pointer; font-weight: 600;" onclick="MeetingParticipants.admitGuest(${g.id}, 'approve')">Permitir</button>
          <button type="button" class="sr-btn sr-btn-danger" style="padding: 4px 12px; font-size: 0.8rem; border-radius: 20px; background: rgba(239, 68, 68, 0.85); border: none; color: #fff; cursor: pointer; font-weight: 600;" onclick="MeetingParticipants.admitGuest(${g.id}, 'reject')">Recusar</button>
        `;
        toast.style.display = 'flex';
      } else {
        toast.style.display = 'none';
      }
    }
  }

  async function admitGuest(inviteId, action) {
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
          window.showToast(action === 'approve' ? `${res.display_name} foi admitido(a)!` : `${res.display_name} foi recusado(a).`);
        }
        currentWaiting = currentWaiting.filter(w => w.id !== inviteId);
        renderWaitingList(currentWaiting);
        if (window.MeetingSignaling) window.MeetingSignaling.refreshLobby();
      } else {
        if (window.showToast) window.showToast('Não foi possível autorizar o convidado.');
      }
    } catch(e) {
      if (window.showToast) window.showToast('Erro ao processar admissão.');
    }
  }

  window.MeetingParticipants = {
    renderParticipants,
    renderWaitingList,
    admitGuest,
    ensureTile,
    updateCounters,
    updateVideoGridCount,
    getParticipantName: (key) => participantNames.get(key) || 'Participante',
    getParticipants: () => participantList
  };
})(window);
