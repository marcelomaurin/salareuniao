/**
 * Logger centralizado do WebRTC - Sala Reunião
 * Formato padrão: [RTC][PEER_KEY] EVENTO
 */
(function(window) {
  'use strict';

  function rtcLog(peerKey, event, data) {
    const key = peerKey ? String(peerKey).substring(0, 8) : 'GLOBAL';
    const tag = `[RTC][${key}] ${event}`;
    
    // Evita registrar SDP completo em produção
    let sanitizedData = data;
    if (data && typeof data === 'object') {
      if (data.sdp) {
        sanitizedData = {
          type: data.type,
          sdpLines: String(data.sdp).split('\n').length,
          preview: String(data.sdp).substring(0, 60) + '...'
        };
      } else if (data.candidate) {
        sanitizedData = {
          candidate: data.candidate.split(' ').slice(0, 8).join(' ') + '...',
          sdpMid: data.sdpMid,
          sdpMLineIndex: data.sdpMLineIndex
        };
      }
    }

    if (data !== undefined) {
      console.log(tag, sanitizedData);
    } else {
      console.log(tag);
    }
  }

  window.MeetingLogger = {
    rtcLog: rtcLog
  };
  window.rtcLog = rtcLog;
})(window);
