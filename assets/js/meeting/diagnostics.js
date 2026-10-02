/**
 * Módulo de Diagnóstico WebRTC por Peer
 * Coleta e exibe estatísticas em tempo real via pc.getStats():
 * - connectionState, iceConnectionState, signalingState
 * - Candidatos Local e Remoto (host, srflx, relay)
 * - Transporte (UDP, TCP) e Rota (P2P direto vs TURN relay)
 * - RTT, jitter, perda de pacotes, bytes/frames enviados e recebidos
 */
(function(window) {
  'use strict';

  const peerStatsCache = new Map();
  let diagnosticsInterval = null;

  async function collectStatsForPeer(key, pc) {
    if (!pc || pc.connectionState === 'closed') return null;

    try {
      const stats = await pc.getStats();
      let transport = null;
      let selectedPair = null;
      let localCandidate = null;
      let remoteCandidate = null;

      // Métricas de áudio/vídeo
      let rtt = null;
      let jitter = null;
      let packetsLost = 0;
      let bytesReceived = 0;
      let bytesSent = 0;
      let framesReceived = 0;
      let framesSent = 0;

      // 1. Identifica o par de candidatos selecionado
      stats.forEach(report => {
        if (report.type === 'transport' && report.selectedCandidatePairId) {
          transport = report;
        }
        if (report.type === 'inbound-rtp' && (report.kind === 'video' || report.kind === 'audio')) {
          bytesReceived += (report.bytesReceived || 0);
          packetsLost += (report.packetsLost || 0);
          if (report.jitter !== undefined) jitter = (report.jitter * 1000).toFixed(1);
          if (report.framesReceived !== undefined) framesReceived += report.framesReceived;
        }
        if (report.type === 'outbound-rtp') {
          bytesSent += (report.bytesSent || 0);
          if (report.framesSent !== undefined) framesSent += report.framesSent;
        }
      });

      if (transport && transport.selectedCandidatePairId) {
        selectedPair = stats.get(transport.selectedCandidatePairId);
      }
      if (!selectedPair) {
        stats.forEach(report => {
          if (report.type === 'candidate-pair' && report.state === 'succeeded' && report.nominated) {
            selectedPair = report;
          }
        });
      }

      if (selectedPair) {
        if (selectedPair.currentRoundTripTime !== undefined) {
          rtt = (selectedPair.currentRoundTripTime * 1000).toFixed(0);
        }
        localCandidate = stats.get(selectedPair.localCandidateId);
        remoteCandidate = stats.get(selectedPair.remoteCandidateId);
      }

      const localType = localCandidate?.candidateType || '?';
      const remoteType = remoteCandidate?.candidateType || '?';
      const localProto = localCandidate?.protocol?.toUpperCase() || (localCandidate?.transport?.toUpperCase() || 'UDP');
      const isRelay = (localType === 'relay' || remoteType === 'relay');
      const route = isRelay ? 'TURN relay' : 'P2P direto';

      const data = {
        key: key,
        name: window.MeetingParticipants ? window.MeetingParticipants.getParticipantName(key) : key,
        connectionState: pc.connectionState,
        iceConnectionState: pc.iceConnectionState,
        signalingState: pc.signalingState,
        localCandidateType: localType,
        remoteCandidateType: remoteType,
        transport: localProto,
        route: route,
        rtt: rtt !== null ? `${rtt} ms` : '--',
        jitter: jitter !== null ? `${jitter} ms` : '--',
        packetsLost: packetsLost,
        bytesReceived: formatBytes(bytesReceived),
        bytesSent: formatBytes(bytesSent),
        framesReceived: framesReceived,
        framesSent: framesSent,
        timestamp: Date.now()
      };

      peerStatsCache.set(key, data);
      return data;
    } catch (e) {
      console.warn(`Erro ao coletar stats para ${key}:`, e);
      return null;
    }
  }

  function formatBytes(bytes) {
    if (!bytes || bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
  }

  async function updatePeerRoute(key, pc) {
    const data = await collectStatsForPeer(key, pc);
    if (!data) return;

    // Atualiza etiqueta no header
    const iceRouteEl = document.getElementById('iceRoute');
    if (iceRouteEl) {
      iceRouteEl.textContent = `ICE: ${data.route} (${data.localCandidateType} ↔ ${data.remoteCandidateType})`;
    }

    const stateEl = document.getElementById('state-' + key);
    if (stateEl) {
      stateEl.dataset.route = data.route;
    }
  }

  async function refreshAllStats() {
    if (!window.MeetingWebRTC || !window.MeetingWebRTC.pcs) return;
    for (const [key, pc] of window.MeetingWebRTC.pcs) {
      if (pc.connectionState === 'connected') {
        await collectStatsForPeer(key, pc);
      }
    }
    renderDiagnosticsModalIfOpen();
  }

  function startDiagnosticsCollector() {
    if (diagnosticsInterval) clearInterval(diagnosticsInterval);
    // Intervalo de 3 a 5 segundos (Requisito 7)
    diagnosticsInterval = setInterval(refreshAllStats, 4000);
  }

  function stopDiagnosticsCollector() {
    if (diagnosticsInterval) {
      clearInterval(diagnosticsInterval);
      diagnosticsInterval = null;
    }
  }

  function openDiagnosticsModal() {
    let modal = document.getElementById('diagnosticsModal');
    if (!modal) {
      modal = document.createElement('div');
      modal.id = 'diagnosticsModal';
      modal.style.cssText = 'position:fixed;inset:0;background:rgba(5,8,17,0.85);backdrop-filter:blur(16px);z-index:300;display:flex;align-items:center;justify-content:center;padding:16px;';
      modal.innerHTML = `
        <div style="background:#0b1120;border:1px solid rgba(255,255,255,0.15);border-radius:16px;max-width:760px;width:100%;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 20px 50px rgba(0,0,0,0.8);">
          <div style="padding:16px 20px;border-bottom:1px solid rgba(255,255,255,0.1);display:flex;justify-content:space-between;align-items:center;">
            <div style="display:flex;align-items:center;gap:10px;">
              <span style="font-size:1.3rem;">📊</span>
              <h3 style="margin:0;font-size:1.1rem;color:#fff;">Diagnóstico WebRTC em Tempo Real</h3>
            </div>
            <button type="button" class="sr-btn" onclick="MeetingDiagnostics.closeDiagnosticsModal()" style="background:rgba(255,255,255,0.1);border:none;color:#fff;border-radius:8px;padding:6px 12px;cursor:pointer;">✕ Fechar</button>
          </div>
          <div id="diagnosticsContent" style="padding:20px;overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:16px;">
            Carregando métricas dos peers...
          </div>
        </div>
      `;
      document.body.appendChild(modal);
    }
    modal.style.display = 'flex';
    refreshAllStats();
  }

  function closeDiagnosticsModal() {
    const modal = document.getElementById('diagnosticsModal');
    if (modal) modal.style.display = 'none';
  }

  function renderDiagnosticsModalIfOpen() {
    const modal = document.getElementById('diagnosticsModal');
    if (!modal || modal.style.display === 'none') return;
    const content = document.getElementById('diagnosticsContent');
    if (!content) return;

    if (peerStatsCache.size === 0) {
      content.innerHTML = '<div style="color:var(--text-muted);text-align:center;padding:30px;">Nenhum peer conectado no momento.</div>';
      return;
    }

    let html = '';
    peerStatsCache.forEach((d, key) => {
      const isRelay = (d.route === 'TURN relay');
      html += `
        <div style="background:rgba(15,23,42,0.6);border:1px solid rgba(255,255,255,0.1);border-radius:12px;padding:16px;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
            <div>
              <strong style="font-size:0.95rem;color:#fff;">${d.name}</strong>
              <div style="font-size:0.75rem;color:var(--text-muted);font-family:monospace;">key: ${d.key}</div>
            </div>
            <span style="background:${isRelay ? 'rgba(245,158,11,0.2)' : 'rgba(16,185,129,0.2)'};color:${isRelay ? '#f59e0b' : '#10b981'};border:1px solid ${isRelay ? 'rgba(245,158,11,0.4)' : 'rgba(16,185,129,0.4)'};padding:3px 10px;border-radius:20px;font-size:0.75rem;font-weight:700;">
              ${d.route}
            </span>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;font-size:0.8rem;">
            <div><span style="color:var(--text-muted);">Connection:</span> <strong style="color:#fff;">${d.connectionState}</strong></div>
            <div><span style="color:var(--text-muted);">ICE State:</span> <strong style="color:#fff;">${d.iceConnectionState}</strong></div>
            <div><span style="color:var(--text-muted);">Signaling:</span> <strong style="color:#fff;">${d.signalingState}</strong></div>
            <div><span style="color:var(--text-muted);">Candidato Local:</span> <strong style="color:#fff;">${d.localCandidateType} (${d.transport})</strong></div>
            <div><span style="color:var(--text-muted);">Candidato Remoto:</span> <strong style="color:#fff;">${d.remoteCandidateType}</strong></div>
            <div><span style="color:var(--text-muted);">RTT:</span> <strong style="color:#38bdf8;">${d.rtt}</strong></div>
            <div><span style="color:var(--text-muted);">Jitter:</span> <strong style="color:#38bdf8;">${d.jitter}</strong></div>
            <div><span style="color:var(--text-muted);">Pacotes Perdidos:</span> <strong style="color:${d.packetsLost > 0 ? '#ef4444' : '#fff'};">${d.packetsLost}</strong></div>
            <div><span style="color:var(--text-muted);">Dados Recebidos:</span> <strong style="color:#fff;">${d.bytesReceived}</strong></div>
            <div><span style="color:var(--text-muted);">Dados Enviados:</span> <strong style="color:#fff;">${d.bytesSent}</strong></div>
          </div>
        </div>
      `;
    });

    content.innerHTML = html;
  }

  window.MeetingDiagnostics = {
    collectStatsForPeer,
    updatePeerRoute,
    startDiagnosticsCollector,
    stopDiagnosticsCollector,
    openDiagnosticsModal,
    closeDiagnosticsModal,
    getPeerStats: (key) => peerStatsCache.get(key)
  };
})(window);
