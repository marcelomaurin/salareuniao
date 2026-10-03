/**
 * Maurinsoft Sala Reunião - Gestão Resiliente de Dispositivos e Mídia (MeetingMedia)
 * - Modelo de permissões em 3 níveis: User Preference, Admin Permission, Room Policy
 * - Suspensão reversível de vídeo (replaceTrack(null)) sem perda da captura local
 * - Perfis de Mídia Dinâmicos: NORMAL (640x360@24fps) e PRESENTATION (1080p/720p@30fps)
 * - Mute administrativo preservando estritamente a escolha prévia do usuário
 */
(function(window) {
  'use strict';

  // Streams e Tracks nativos
  let localStream = null;
  let cameraTrack = null;
  let screenTrack = null;

  // Modelo de Permissão de 3 Níveis - CÂMERA (Tarefa 11)
  let camera_user_enabled = true;
  let camera_admin_allowed = true;
  let camera_room_allowed = true;

  // Modelo de Permissão de 3 Níveis - ÁUDIO (Tarefa 12)
  let microphone_user_enabled = true;
  let microphone_admin_allowed = true;
  let microphone_room_allowed = true;

  // Modelo de Permissão - TELA (Tarefa 27, 28)
  let screen_user_enabled = false;
  let screen_admin_allowed = true;

  // Perfis de Resolução e FPS Otimizados para Baixíssima Latência / Tempo Real
  const MEDIA_PROFILE_NORMAL = {
    width: { ideal: 640, max: 640 },
    height: { ideal: 360, max: 360 },
    frameRate: { ideal: 15, max: 15 }
  };

  const MEDIA_PROFILE_PRESENTATION = {
    width: { ideal: 1280, max: 1280 },
    height: { ideal: 720, max: 720 },
    frameRate: { ideal: 15, max: 15 }
  };

  // Perfil de Baixa Resolução para Múltiplos Participantes (160x120 @ 10-12fps)
  const MEDIA_PROFILE_MULTI_PEERS = {
    width: { ideal: 160, max: 160 },
    height: { ideal: 120, max: 120 },
    frameRate: { ideal: 10, max: 12 }
  };

  // Perfil de Tela Cheia / Condução (1024x768 @ 15fps)
  const MEDIA_PROFILE_FULLSCREEN = {
    width: { ideal: 1024, max: 1024 },
    height: { ideal: 768, max: 768 },
    frameRate: { ideal: 15, max: 15 }
  };

  function getEffectiveVideo() {
    return camera_user_enabled && camera_admin_allowed && camera_room_allowed;
  }

  function getEffectiveAudio() {
    return microphone_user_enabled && microphone_admin_allowed && microphone_room_allowed;
  }

  async function initLocalMedia() {
    let hasVideo = false;
    let hasAudio = false;

    try {
      localStream = await navigator.mediaDevices.getUserMedia({
        video: MEDIA_PROFILE_NORMAL,
        audio: {
          echoCancellation: true,
          noiseSuppression: true,
          autoGainControl: true,
          latency: 0
        }
      });
      hasVideo = true;
      hasAudio = true;
      window.rtcLog && window.rtcLog('LOCAL', 'media-tier1-success');
    } catch (err1) {
      console.warn('Tier 1 (Video+Audio) indisponível:', err1.name, err1.message);
      try {
        localStream = await navigator.mediaDevices.getUserMedia({ audio: true });
        hasAudio = true;
        if (window.showToast) window.showToast('Câmera ocupada ou indisponível. Entrando em modo áudio.');
        window.rtcLog && window.rtcLog('LOCAL', 'media-tier2-audio-only');
      } catch (err2) {
        console.warn('Tier 2 (Audio) falhou:', err2.name, err2.message);
        localStream = new MediaStream();
        if (window.showToast) window.showToast('Dispositivos bloqueados. Você entrou na sala como ouvinte.');
        window.rtcLog && window.rtcLog('LOCAL', 'media-tier3-listener-mode');
      }
    }

    cameraTrack = (localStream && localStream.getVideoTracks().length > 0) ? localStream.getVideoTracks()[0] : null;
    const audioTracks = localStream ? localStream.getAudioTracks() : [];

    const cfg = window.MEETING_CONFIG || {};
    // Ativa a câmera automaticamente ao entrar se o dispositivo estiver disponível
    camera_user_enabled = hasVideo && (cameraTrack !== null);
    camera_admin_allowed = true;
    camera_room_allowed = true;

    microphone_user_enabled = hasAudio && (audioTracks.length > 0);
    microphone_admin_allowed = true;
    microphone_room_allowed = true;

    applyEffectiveVideo();
    applyEffectiveAudio();

    const localVideo = document.getElementById('local');
    const localAvatar = document.getElementById('localAvatar');
    if (localVideo && localStream) {
      localVideo.srcObject = localStream;
      localVideo.style.display = getEffectiveVideo() ? 'block' : 'none';
    }
    if (localAvatar) {
      localAvatar.style.display = getEffectiveVideo() ? 'none' : 'flex';
    }

    updateControlsUI();
    return localStream;
  }

  // Aplicação de regras efetivas de Vídeo
  async function applyEffectiveVideo() {
    const effective = getEffectiveVideo();
    const localVideo = document.getElementById('local');
    const localAvatar = document.getElementById('localAvatar');

    if (cameraTrack) {
      cameraTrack.enabled = effective;
    }

    if (!effective) {
      // Se suspenso por política da sala ou admin, substitui envio por null para poupar banda (Tarefa 16, 17)
      if (!screen_user_enabled) {
        await replaceOutgoingTrack('video', null);
      }
      if (localVideo) localVideo.style.display = 'none';
      if (localAvatar) localAvatar.style.display = 'flex';
    } else {
      // Restaura envio se estava suspenso (Tarefa 18)
      if (!screen_user_enabled && cameraTrack) {
        await replaceOutgoingTrack('video', cameraTrack);
      }
      if (localVideo) localVideo.style.display = 'block';
      if (localAvatar) localAvatar.style.display = 'none';
    }

    updateControlsUI();
    if (window.MeetingSignaling) window.MeetingSignaling.broadcastPresence();
  }

  // Aplicação de regras efetivas de Áudio
  async function applyEffectiveAudio() {
    const effective = getEffectiveAudio();
    if (localStream) {
      localStream.getAudioTracks().forEach(t => {
        t.enabled = effective;
      });
    }
    updateControlsUI();
    if (window.MeetingSignaling) window.MeetingSignaling.broadcastPresence();
  }

  // Métodos Públicos de Permissão e Preferência (Tarefa 46)
  function setVideoUserPreference(enabled) {
    camera_user_enabled = !!enabled;
    applyEffectiveVideo();
  }

  function setVideoAdminPermission(allowed) {
    camera_admin_allowed = !!allowed;
    applyEffectiveVideo();
  }

  function setVideoRoomPermission(allowed) {
    camera_room_allowed = !!allowed;
    applyEffectiveVideo();
  }

  function setAudioUserPreference(enabled) {
    microphone_user_enabled = !!enabled;
    applyEffectiveAudio();
  }

  function setAudioAdminPermission(allowed) {
    microphone_admin_allowed = !!allowed;
    // IMPORTANTE: se microphone_user_enabled for falso, o microfone continuará desligado (Tarefas 13, 38)
    applyEffectiveAudio();
  }

  function setAudioRoomPermission(allowed) {
    microphone_room_allowed = !!allowed;
    applyEffectiveAudio();
  }

  function setScreenAdminPermission(allowed) {
    screen_admin_allowed = !!allowed;
    if (!screen_admin_allowed && screen_user_enabled) {
      stopScreen();
    }
  }

  // Suspensão e Restauração de Vídeo (Tarefas 17, 18)
  async function suspendOutgoingVideo() {
    camera_room_allowed = false;
    await replaceOutgoingTrack('video', null);
    const localVideo = document.getElementById('local');
    const localAvatar = document.getElementById('localAvatar');
    if (localVideo) localVideo.style.display = 'none';
    if (localAvatar) localAvatar.style.display = 'flex';
    updateControlsUI();
    window.rtcLog && window.rtcLog('LOCAL', 'video-suspended');
  }

  async function resumeOutgoingVideo() {
    camera_room_allowed = true;
    await applyEffectiveVideo();
    window.rtcLog && window.rtcLog('LOCAL', 'video-resumed');
  }

  // Perfis de Resolução (Tarefas 19, 20, 21)
  async function applyPresentationVideoProfile() {
    if (!cameraTrack || cameraTrack.readyState !== 'live') return;
    try {
      await cameraTrack.applyConstraints(MEDIA_PROFILE_PRESENTATION);
      window.rtcLog && window.rtcLog('LOCAL', 'profile-presentation-1080p');
    } catch (e1) {
      console.warn('Falha perfil 1080p, tentando 720p fallback:', e1);
      try {
        await cameraTrack.applyConstraints({
          width: { ideal: 1280, max: 1280 },
          height: { ideal: 720, max: 720 },
          frameRate: { ideal: 15, max: 15 }
        });
        window.rtcLog && window.rtcLog('LOCAL', 'profile-presentation-720p');
      } catch (e2) {
        console.warn('Fallback 720p falhou, mantendo resolução atual:', e2);
      }
    }
  }

  let currentProfileMode = 'normal';

  async function applyNormalVideoProfile() {
    if (!cameraTrack || cameraTrack.readyState !== 'live') return;
    try {
      await cameraTrack.applyConstraints(MEDIA_PROFILE_NORMAL);
      currentProfileMode = 'normal';
      window.rtcLog && window.rtcLog('LOCAL', 'profile-normal-360p');
    } catch (e) {
      console.warn('Falha ao restaurar perfil normal:', e);
    }
  }

  async function applyLowVideoProfile() {
    if (!cameraTrack || cameraTrack.readyState !== 'live') return;
    try {
      await cameraTrack.applyConstraints(MEDIA_PROFILE_MULTI_PEERS);
      currentProfileMode = 'low';
      window.rtcLog && window.rtcLog('LOCAL', 'profile-low-160x120');
    } catch (e1) {
      console.warn('applyConstraints para 160x120 direto falhou, tentando fallback aproximado:', e1);
      try {
        await cameraTrack.applyConstraints({
          width: { ideal: 160, max: 320 },
          height: { ideal: 120, max: 240 },
          frameRate: { ideal: 15, max: 15 }
        });
        currentProfileMode = 'low';
        window.rtcLog && window.rtcLog('LOCAL', 'profile-low-160x120-fallback');
      } catch (e2) {
        console.warn('Falha ao aplicar perfil de baixa resolução:', e2);
      }
    }
  }

  async function applyFullscreenVideoProfile() {
    if (!cameraTrack || cameraTrack.readyState !== 'live') return;
    try {
      await cameraTrack.applyConstraints(MEDIA_PROFILE_FULLSCREEN);
      currentProfileMode = 'fullscreen';
      window.rtcLog && window.rtcLog('LOCAL', 'profile-fullscreen-1024x768');
    } catch (e1) {
      console.warn('Falha ao aplicar 1024x768 exato, tentando fallback aproximado:', e1);
      try {
        await cameraTrack.applyConstraints({
          width: { ideal: 1024, max: 1280 },
          height: { ideal: 768, max: 720 },
          frameRate: { ideal: 15, max: 15 }
        });
        currentProfileMode = 'fullscreen';
        window.rtcLog && window.rtcLog('LOCAL', 'profile-fullscreen-fallback');
      } catch (e2) {
        console.warn('Falha ao aplicar perfil fullscreen:', e2);
      }
    }
  }

  async function adaptResolutionForParticipantCount(count) {
    // Não rebaixa a resolução se estiver em compartilhamento de tela ou modo apresentador
    if (screen_user_enabled || (window.MeetingPresentation && window.MeetingPresentation.isLocalPresenter())) {
      return;
    }
    // Se houver mais de 2 pessoas na sala (mais de 1 peer remoto enviando/recebendo), usa 160x120
    if (count > 2) {
      if (currentProfileMode !== 'low') {
        await applyLowVideoProfile();
      }
    } else {
      if (currentProfileMode !== 'normal') {
        await applyNormalVideoProfile();
      }
    }
  }

  // Substituição dinâmica no RTCRtpSender sem destruir conexão P2P (Tarefa 17)
  async function replaceOutgoingTrack(kind, newTrack) {
    if (!window.MeetingWebRTC || !window.MeetingWebRTC.pcs) return;
    const pcs = window.MeetingWebRTC.pcs;

    for (const [peerKey, pc] of pcs) {
      try {
        const transceivers = pc.getTransceivers();
        const transceiver = transceivers.find(t => 
          (t.receiver && t.receiver.track && t.receiver.track.kind === kind) ||
          (t.sender && t.sender.track && t.sender.track.kind === kind)
        );
        if (transceiver && transceiver.sender) {
          await transceiver.sender.replaceTrack(newTrack);
          window.rtcLog && window.rtcLog(peerKey, `replaceTrack-${kind}-${newTrack ? 'active' : 'null'}`);
        } else if (newTrack) {
          pc.addTrack(newTrack, localStream);
          window.rtcLog && window.rtcLog(peerKey, `addTrack-${kind}-fallback`);
        }
      } catch (err) {
        console.warn(`Erro ao substituir track ${kind} para peer ${peerKey}:`, err);
      }
    }
  }

  async function toggleCamera() {
    const cfg = window.MEETING_CONFIG || {};
    if (!camera_admin_allowed) {
      if (window.showToast) window.showToast('🚫 O uso de câmera está bloqueado pelo administrador.');
      return;
    }
    if (!camera_room_allowed) {
      if (window.showToast) window.showToast('ℹ️ A sala está em modo apresentação. Seu vídeo está suspenso.');
      return;
    }
    if (!cfg.CAN_ADMIT && window.MeetingParticipants && !window.MeetingParticipants.hasVideoGranted()) {
      if (window.showToast) window.showToast('✋ Para transmitir vídeo, clique no botão "Pedir Palavra" e aguarde a aprovação do anfitrião.');
      return;
    }

    if (cameraTrack && cameraTrack.readyState === 'live') {
      camera_user_enabled = !camera_user_enabled;
      await applyEffectiveVideo();
    } else {
      try {
        if (window.showToast) window.showToast('Solicitando acesso à câmera...');
        const stream = await navigator.mediaDevices.getUserMedia({
          video: MEDIA_PROFILE_NORMAL
        });
        const newTrack = stream.getVideoTracks()[0];
        if (newTrack) {
          cameraTrack = newTrack;
          if (!localStream) localStream = new MediaStream();
          localStream.getVideoTracks().forEach(t => { try { t.stop(); localStream.removeTrack(t); } catch(e){} });
          localStream.addTrack(cameraTrack);
          camera_user_enabled = true;
          await applyEffectiveVideo();
          if (window.showToast) window.showToast('Câmera ativada com sucesso!');
        }
      } catch (err) {
        console.warn('Falha ao reativar câmera:', err);
        if (window.showToast) window.showToast('Câmera ocupada ou permissão negada.');
      }
    }
  }

  async function toggleMicrophone() {
    if (!microphone_admin_allowed) {
      if (window.showToast) window.showToast('🔇 Seu microfone foi silenciado pelo administrador.');
      return;
    }

    const audioTracks = localStream ? localStream.getAudioTracks() : [];
    const audioTrack = audioTracks.length > 0 ? audioTracks[0] : null;

    if (audioTrack && audioTrack.readyState === 'live') {
      microphone_user_enabled = !microphone_user_enabled;
      await applyEffectiveAudio();
    } else {
      try {
        if (window.showToast) window.showToast('Solicitando acesso ao microfone...');
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        const newTrack = stream.getAudioTracks()[0];
        if (newTrack) {
          if (!localStream) localStream = new MediaStream();
          localStream.getAudioTracks().forEach(t => { try { t.stop(); localStream.removeTrack(t); } catch(e){} });
          localStream.addTrack(newTrack);
          microphone_user_enabled = true;
          await replaceOutgoingTrack('audio', newTrack);
          await applyEffectiveAudio();
          if (window.showToast) window.showToast('Microfone ativado com sucesso!');
        }
      } catch (err) {
        console.warn('Falha ao reativar microfone:', err);
        if (window.showToast) window.showToast('Microfone bloqueado.');
      }
    }
  }

  // Compartilhamento de tela com autorização (Tarefas 27, 28)
  async function toggleScreen() {
    if (screen_user_enabled) {
      await stopScreen();
      return;
    }

    if (!screen_admin_allowed) {
      if (window.showToast) window.showToast('Solicite autorização ao administrador para compartilhar sua tela.');
      return;
    }

    try {
      const display = await navigator.mediaDevices.getDisplayMedia({ 
        video: { 
          width: { ideal: 1920, max: 1920 },
          height: { ideal: 1080, max: 1080 },
          frameRate: { ideal: 30, max: 60 }
        }, 
        audio: false 
      });
      screenTrack = display.getVideoTracks()[0];
      screen_user_enabled = true;

      screenTrack.onended = () => stopScreen();
      await replaceOutgoingTrack('video', screenTrack);

      const localVideo = document.getElementById('local');
      if (localVideo) {
        localVideo.srcObject = new MediaStream([screenTrack, ...(localStream ? localStream.getAudioTracks() : [])]);
        localVideo.style.display = 'block';
      }
      const localAvatar = document.getElementById('localAvatar');
      if (localAvatar) localAvatar.style.display = 'none';

      updateControlsUI();
      window.rtcLog && window.rtcLog('LOCAL', 'screenshare-started');

      if (window.MeetingPresentation && window.MeetingPresentation.start) {
        const cfg = window.MEETING_CONFIG || {};
        window.MeetingPresentation.start(cfg.selfKey, 'screen');
      }

      if (window.MeetingSignaling) window.MeetingSignaling.broadcastPresence();
    } catch (e) {
      if (e.name !== 'NotAllowedError') {
        alert('Não foi possível compartilhar a tela: ' + e.message);
      }
    }
  }

  async function stopScreen() {
    if (!screen_user_enabled) return;
    screen_user_enabled = false;
    if (screenTrack) {
      screenTrack.onended = null;
      try { screenTrack.stop(); } catch(e){}
      screenTrack = null;
    }

    // Restaura câmera se o vídeo estiver efetivo
    if (getEffectiveVideo() && cameraTrack) {
      await replaceOutgoingTrack('video', cameraTrack);
    } else {
      await replaceOutgoingTrack('video', null);
    }

    if (window.MeetingPresentation && window.MeetingPresentation.isLocalPresenter()) {
      window.MeetingPresentation.end();
    }

    const localVideo = document.getElementById('local');
    const localAvatar = document.getElementById('localAvatar');
    if (localVideo && localStream) {
      localVideo.srcObject = localStream;
      localVideo.style.display = getEffectiveVideo() ? 'block' : 'none';
    }
    if (localAvatar) {
      localAvatar.style.display = getEffectiveVideo() ? 'none' : 'flex';
    }

    updateControlsUI();
    window.rtcLog && window.rtcLog('LOCAL', 'screenshare-stopped');
    if (window.MeetingSignaling) window.MeetingSignaling.broadcastPresence();
  }

  function stopAllMedia() {
    if (localStream) {
      localStream.getTracks().forEach(t => {
        try { t.stop(); } catch(e){}
      });
      localStream = null;
    }
    if (screenTrack) {
      try { screenTrack.stop(); } catch(e){}
      screenTrack = null;
    }
    cameraTrack = null;
    camera_user_enabled = false;
    microphone_user_enabled = false;
    screen_user_enabled = false;
    updateControlsUI();
  }

  function updateControlsUI() {
    const micEl = document.getElementById('mic');
    const camEl = document.getElementById('cam');
    const screenEl = document.getElementById('screen');
    const localState = document.getElementById('localState');

    const effectiveAudio = getEffectiveAudio();
    const effectiveVideo = getEffectiveVideo();

    if (micEl) {
      micEl.classList.toggle('active', effectiveAudio);
      micEl.innerHTML = (effectiveAudio ? '🎙️' : '🔇') + ' <span class="btn-label">' + (effectiveAudio ? 'Microfone' : 'Mudo') + '</span>';
      if (!microphone_admin_allowed) {
        micEl.title = 'Microfone bloqueado pelo administrador';
      } else {
        micEl.title = effectiveAudio ? 'Desativar microfone' : 'Ativar microfone';
      }
    }

    if (camEl) {
      camEl.classList.toggle('active', effectiveVideo);
      camEl.innerHTML = (effectiveVideo ? '📹' : '🚫') + ' <span class="btn-label">' + (effectiveVideo ? 'Câmera' : 'Câmera Desl.') + '</span>';
      if (!camera_admin_allowed) {
        camEl.title = 'Câmera bloqueada pelo administrador';
      } else if (!camera_room_allowed) {
        camEl.title = 'Vídeo suspenso durante apresentação';
      } else {
        camEl.title = effectiveVideo ? 'Desativar câmera' : 'Ativar câmera';
      }
    }

    // Sincroniza controles rápidos no celular
    const mMic = document.getElementById('mobileMic');
    const mCam = document.getElementById('mobileCam');
    if (mMic) {
      mMic.classList.toggle('active', effectiveAudio);
      mMic.innerHTML = effectiveAudio ? '🎙️' : '🔇';
    }
    if (mCam) {
      mCam.classList.toggle('active', effectiveVideo);
      mCam.innerHTML = effectiveVideo ? '📹' : '🚫';
    }

    if (screenEl) {
      screenEl.classList.toggle('active', screen_user_enabled);
      screenEl.innerHTML = '🖥️ <span class="btn-label">' + (screen_user_enabled ? 'Parar' : 'Compartilhar') + '</span>';
      if (!screen_admin_allowed) {
        screenEl.title = 'Compartilhamento não autorizado';
      }
    }

    if (localState) {
      localState.textContent = (effectiveAudio ? '🎙️' : '🔇') + ' ' + (effectiveVideo ? '📹' : '🚫') + (screen_user_enabled ? ' 🖥️' : '');
    }
  }

  window.MeetingMedia = {
    initLocalMedia,
    toggleCamera,
    toggleMicrophone,
    toggleScreen,
    stopScreen,
    stopAllMedia,
    suspendOutgoingVideo,
    resumeOutgoingVideo,
    applyPresentationVideoProfile,
    applyNormalVideoProfile,
    applyLowVideoProfile,
    applyFullscreenVideoProfile,
    adaptResolutionForParticipantCount,
    setVideoUserPreference,
    setVideoAdminPermission,
    setVideoRoomPermission,
    setAudioUserPreference,
    setAudioAdminPermission,
    setAudioRoomPermission,
    setScreenAdminPermission,
    replaceOutgoingTrack,
    updateControlsUI,
    getLocalStream: () => localStream,
    getCameraTrack: () => cameraTrack,
    getScreenTrack: () => screenTrack,
    isMicEnabled: () => getEffectiveAudio(),
    isCamEnabled: () => getEffectiveVideo(),
    isScreenSharing: () => screen_user_enabled,
    isCameraAdminAllowed: () => camera_admin_allowed,
    isAudioAdminAllowed: () => audio_admin_allowed,
    isScreenAdminAllowed: () => screen_admin_allowed
  };
})(window);
