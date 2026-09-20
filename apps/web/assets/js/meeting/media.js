/**
 * Gestão Resiliente de Dispositivos de Mídia (Câmera, Microfone e Tela)
 * - Mute/unmute via track.enabled
 * - Substituição dinâmica de tracks sem destruir a conexão WebRTC
 * - Fallback gracioso (Vídeo+Áudio -> Apenas Áudio -> Ouvinte)
 */
(function(window) {
  'use strict';

  let localStream = null;
  let cameraTrack = null;
  let screenTrack = null;
  let micEnabled = true;
  let camEnabled = true;
  let screenSharing = false;

  async function initLocalMedia() {
    let hasVideo = false;
    let hasAudio = false;

    // Tier 1: Tenta Câmera + Microfone
    try {
      localStream = await navigator.mediaDevices.getUserMedia({
        video: { width: { ideal: 1280 }, height: { ideal: 720 } },
        audio: true
      });
      hasVideo = true;
      hasAudio = true;
      window.rtcLog && window.rtcLog('LOCAL', 'media-tier1-success');
    } catch (err1) {
      console.warn('Tier 1 (Video+Audio) indisponível:', err1.name, err1.message);

      // Tier 2: Tenta apenas Áudio (câmera ocupada ou negada)
      try {
        localStream = await navigator.mediaDevices.getUserMedia({ audio: true });
        hasAudio = true;
        if (window.showToast) window.showToast('Câmera ocupada ou indisponível. Entrando em modo áudio.');
        window.rtcLog && window.rtcLog('LOCAL', 'media-tier2-audio-only');
      } catch (err2) {
        console.warn('Tier 2 (Audio) falhou:', err2.name, err2.message);
        // Tier 3: Modo ouvinte (stream local vazio)
        localStream = new MediaStream();
        if (window.showToast) window.showToast('Dispositivos bloqueados. Você entrou na sala como ouvinte.');
        window.rtcLog && window.rtcLog('LOCAL', 'media-tier3-listener-mode');
      }
    }

    cameraTrack = (localStream && localStream.getVideoTracks().length > 0) ? localStream.getVideoTracks()[0] : null;
    const audioTracks = localStream ? localStream.getAudioTracks() : [];
    
    camEnabled = hasVideo && (cameraTrack !== null);
    micEnabled = hasAudio && (audioTracks.length > 0);

    const localVideo = document.getElementById('local');
    const localAvatar = document.getElementById('localAvatar');
    if (localVideo && localStream) {
      localVideo.srcObject = localStream;
      localVideo.style.display = camEnabled ? 'block' : 'none';
    }
    if (localAvatar) {
      localAvatar.style.display = camEnabled ? 'none' : 'flex';
    }

    updateControlsUI();
    return localStream;
  }

  function updateControlsUI() {
    const micEl = document.getElementById('mic');
    const camEl = document.getElementById('cam');
    const screenEl = document.getElementById('screen');
    const localState = document.getElementById('localState');

    if (micEl) {
      micEl.classList.toggle('active', micEnabled);
      micEl.innerHTML = (micEnabled ? '🎙️' : '🔇') + ' <span class="btn-label">' + (micEnabled ? 'Microfone' : 'Mudo') + '</span>';
    }
    if (camEl) {
      camEl.classList.toggle('active', camEnabled);
      camEl.innerHTML = (camEnabled ? '📹' : '🚫') + ' <span class="btn-label">' + (camEnabled ? 'Câmera' : 'Câmera Desl.') + '</span>';
    }
    if (screenEl) {
      screenEl.classList.toggle('active', screenSharing);
      screenEl.innerHTML = '🖥️ <span class="btn-label">' + (screenSharing ? 'Parar' : 'Compartilhar') + '</span>';
    }
    if (localState) {
      localState.textContent = (micEnabled ? '🎙️' : '🔇') + ' ' + (camEnabled ? '📹' : '🚫') + (screenSharing ? ' 🖥️' : '');
    }
  }

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
          window.rtcLog && window.rtcLog(peerKey, `replaceTrack-${kind}-success`);
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
    const localVideo = document.getElementById('local');
    const localAvatar = document.getElementById('localAvatar');

    if (cameraTrack && cameraTrack.readyState === 'live') {
      camEnabled = !camEnabled;
      cameraTrack.enabled = camEnabled;
      updateControlsUI();
      if (localVideo) localVideo.style.display = camEnabled ? 'block' : 'none';
      if (localAvatar) localAvatar.style.display = camEnabled ? 'none' : 'flex';
      window.rtcLog && window.rtcLog('LOCAL', camEnabled ? 'camera-enabled' : 'camera-disabled');
      if (window.MeetingSignaling) window.MeetingSignaling.broadcastPresence();
    } else {
      try {
        if (window.showToast) window.showToast('Solicitando acesso à câmera...');
        const stream = await navigator.mediaDevices.getUserMedia({
          video: { width: { ideal: 1280 }, height: { ideal: 720 } }
        });
        const newTrack = stream.getVideoTracks()[0];
        if (newTrack) {
          cameraTrack = newTrack;
          if (!localStream) localStream = new MediaStream();
          // Remove tracks antigos se houver
          localStream.getVideoTracks().forEach(t => { try { t.stop(); localStream.removeTrack(t); } catch(e){} });
          localStream.addTrack(cameraTrack);
          camEnabled = true;

          if (localVideo) {
            localVideo.srcObject = localStream;
            localVideo.style.display = 'block';
          }
          if (localAvatar) localAvatar.style.display = 'none';

          if (!screenSharing) {
            await replaceOutgoingTrack('video', cameraTrack);
          }
          updateControlsUI();
          if (window.showToast) window.showToast('Câmera ativada com sucesso!');
          window.rtcLog && window.rtcLog('LOCAL', 'camera-restarted');
          if (window.MeetingSignaling) window.MeetingSignaling.broadcastPresence();
        }
      } catch (err) {
        console.warn('Falha ao reativar câmera:', err);
        if (window.showToast) window.showToast('Câmera ocupada ou permissão negada: ' + (err.message || err.name));
      }
    }
  }

  async function toggleMicrophone() {
    const audioTracks = localStream ? localStream.getAudioTracks() : [];
    const audioTrack = audioTracks.length > 0 ? audioTracks[0] : null;

    if (audioTrack && audioTrack.readyState === 'live') {
      micEnabled = !micEnabled;
      audioTrack.enabled = micEnabled;
      updateControlsUI();
      window.rtcLog && window.rtcLog('LOCAL', micEnabled ? 'mic-unmuted' : 'mic-muted');
      if (window.MeetingSignaling) window.MeetingSignaling.broadcastPresence();
    } else {
      try {
        if (window.showToast) window.showToast('Solicitando acesso ao microfone...');
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        const newTrack = stream.getAudioTracks()[0];
        if (newTrack) {
          if (!localStream) localStream = new MediaStream();
          localStream.getAudioTracks().forEach(t => { try { t.stop(); localStream.removeTrack(t); } catch(e){} });
          localStream.addTrack(newTrack);
          micEnabled = true;

          await replaceOutgoingTrack('audio', newTrack);
          updateControlsUI();
          if (window.showToast) window.showToast('Microfone ativado com sucesso!');
          window.rtcLog && window.rtcLog('LOCAL', 'mic-restarted');
          if (window.MeetingSignaling) window.MeetingSignaling.broadcastPresence();
        }
      } catch (err) {
        console.warn('Falha ao reativar microfone:', err);
        if (window.showToast) window.showToast('Microfone bloqueado: ' + (err.message || err.name));
      }
    }
  }

  async function toggleScreen() {
    if (screenSharing) {
      await stopScreen();
      return;
    }
    try {
      const display = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: false });
      screenTrack = display.getVideoTracks()[0];
      screenSharing = true;

      screenTrack.onended = () => stopScreen();
      await replaceOutgoingTrack('video', screenTrack);

      const localVideo = document.getElementById('local');
      if (localVideo) {
        localVideo.srcObject = new MediaStream([screenTrack, ...localStream.getAudioTracks()]);
        localVideo.style.display = 'block';
      }
      const localAvatar = document.getElementById('localAvatar');
      if (localAvatar) localAvatar.style.display = 'none';

      updateControlsUI();
      window.rtcLog && window.rtcLog('LOCAL', 'screenshare-started');
      if (window.MeetingSignaling) window.MeetingSignaling.broadcastPresence();
    } catch (e) {
      if (e.name !== 'NotAllowedError') {
        alert('Não foi possível compartilhar a tela: ' + e.message);
      }
    }
  }

  async function stopScreen() {
    if (!screenSharing) return;
    screenSharing = false;
    if (screenTrack) {
      screenTrack.onended = null;
      try { screenTrack.stop(); } catch(e){}
      screenTrack = null;
    }
    await replaceOutgoingTrack('video', cameraTrack);

    const localVideo = document.getElementById('local');
    const localAvatar = document.getElementById('localAvatar');
    if (localVideo && localStream) {
      localVideo.srcObject = localStream;
      localVideo.style.display = camEnabled ? 'block' : 'none';
    }
    if (localAvatar) {
      localAvatar.style.display = camEnabled ? 'none' : 'flex';
    }

    updateControlsUI();
    window.rtcLog && window.rtcLog('LOCAL', 'screenshare-stopped');
    if (window.MeetingSignaling) window.MeetingSignaling.broadcastPresence();
  }

  function releaseAllMedia() {
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
  }

  window.MeetingMedia = {
    initLocalMedia,
    toggleCamera,
    toggleMicrophone,
    toggleScreen,
    stopScreen,
    replaceOutgoingTrack,
    releaseAllMedia,
    updateControlsUI,
    getLocalStream: () => localStream,
    getCameraTrack: () => cameraTrack,
    getScreenTrack: () => screenTrack,
    isMicEnabled: () => micEnabled,
    isCamEnabled: () => camEnabled,
    isScreenSharing: () => screenSharing
  };
})(window);
