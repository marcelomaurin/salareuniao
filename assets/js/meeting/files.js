/**
 * Módulo de Gestão e Transferência de Arquivos na Sala
 * Maurinsoft Sala Reunião
 */
(function(window) {
  'use strict';

  function formatBytes(bytes) {
    if (!bytes || bytes <= 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return (bytes / Math.pow(k, i)).toFixed(i === 0 ? 0 : 1) + ' ' + sizes[i];
  }

  function getFileIcon(name, mime) {
    const ext = String(name || '').split('.').pop().toLowerCase();
    if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'].includes(ext)) return '🖼️';
    if (['pdf'].includes(ext)) return '📕';
    if (['doc', 'docx', 'odt', 'txt', 'rtf'].includes(ext)) return '📄';
    if (['xls', 'xlsx', 'csv'].includes(ext)) return '📊';
    if (['ppt', 'pptx'].includes(ext)) return '📑';
    if (['zip', 'rar', '7z', 'tar', 'gz'].includes(ext)) return '📦';
    if (['mp3', 'wav', 'ogg', 'm4a'].includes(ext)) return '🎵';
    if (['mp4', 'mkv', 'avi', 'mov', 'webm'].includes(ext)) return '🎥';
    if (['php', 'js', 'py', 'c', 'cpp', 'java', 'html', 'css', 'json', 'sql'].includes(ext)) return '💻';
    return '📁';
  }

  async function loadFiles() {
    const cfg = window.MEETING_CONFIG;
    if (!cfg || !cfg.TOKEN) return;

    const listEl = document.getElementById('roomFilesList');
    const countBadge = document.getElementById('sidebarFilesCount');
    const topBadge = document.getElementById('filesBadgeCount');

    try {
      const resp = await fetch(`api/file_list.php?token=${encodeURIComponent(cfg.TOKEN)}`, { cache: 'no-store' });
      const data = await resp.json();

      if (!data.ok) {
        if (listEl) listEl.innerHTML = '<div style="color:#ef4444;font-size:0.84rem;text-align:center;padding:16px;">Erro ao carregar arquivos da sala.</div>';
        return;
      }

      const files = data.files || [];
      if (countBadge) countBadge.textContent = String(files.length);
      if (topBadge) {
        topBadge.style.display = files.length > 0 ? 'inline-block' : 'none';
        topBadge.textContent = String(files.length);
      }

      if (!listEl) return;

      if (files.length === 0) {
        listEl.innerHTML = `
          <div style="color: var(--text-dim); font-size: 0.84rem; text-align: center; padding: 24px 10px;">
            Nenhum arquivo compartilhado nesta sala ainda.<br>
            <span style="font-size: 0.76rem; color: var(--text-muted); margin-top: 4px; display: inline-block;">Use o botão acima ou anexe no chat para transferir.</span>
          </div>
        `;
        return;
      }

      let html = '';
      for (const f of files) {
        const icon = getFileIcon(f.original_name, f.mime_type);
        const timeStr = String(f.created_at || '').slice(11, 16);
        html += `
          <div class="room-file-item" id="file-item-${f.id}">
            <div style="font-size: 1.4rem; flex-shrink: 0;">${icon}</div>
            <div style="flex: 1; min-width: 0;">
              <div style="font-weight: 600; font-size: 0.84rem; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${encodeURIComponent(f.original_name)}">
                ${escapeHtml(f.original_name)}
              </div>
              <div style="font-size: 0.74rem; color: var(--text-muted); margin-top: 2px;">
                ${formatBytes(f.file_size)} &bull; ${escapeHtml(f.display_name)} &bull; ${timeStr}
              </div>
            </div>
            <a href="${f.download_url}" class="sr-btn sr-btn-secondary sr-btn-sm" style="padding: 4px 10px; font-size: 0.78rem; text-decoration: none; flex-shrink: 0;" download="${encodeURIComponent(f.original_name)}" title="Baixar Arquivo">
              ⬇️ Baixar
            </a>
          </div>
        `;
      }
      listEl.innerHTML = html;
    } catch (e) {
      console.warn('Erro ao carregar lista de arquivos:', e);
      if (listEl) listEl.innerHTML = '<div style="color:var(--text-dim);font-size:0.84rem;text-align:center;padding:16px;">Falha na conexão com a central de arquivos.</div>';
    }
  }

  async function uploadFile(file) {
    if (!file) return;
    const cfg = window.MEETING_CONFIG;
    if (!cfg || !cfg.TOKEN) return;

    const progressEl = document.getElementById('fileUploadProgress');
    if (progressEl) progressEl.style.display = 'block';

    const formData = new FormData();
    formData.append('token', cfg.TOKEN);
    formData.append('file', file);

    try {
      const resp = await fetch('api/file_upload.php', {
        method: 'POST',
        body: formData
      });
      const data = await resp.json();

      if (!data.ok) {
        alert('Erro ao enviar arquivo: ' + (data.error || 'Falha no upload'));
        return;
      }

      if (window.showToast) {
        window.showToast('📁 Arquivo enviado para o storage da sala!');
      }

      // Recarrega arquivos e histórico do chat
      loadFiles();
      if (window.MeetingChat) {
        window.MeetingChat.loadChatHistory();
      }
    } catch (e) {
      console.error('Falha no upload:', e);
      alert('Não foi possível enviar o arquivo. Verifique sua conexão e tente novamente.');
    } finally {
      if (progressEl) progressEl.style.display = 'none';
    }
  }

  function uploadFileFromInput(input) {
    if (!input || !input.files || input.files.length === 0) return;
    const file = input.files[0];
    uploadFile(file);
    input.value = '';
  }

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
  }

  function initDropZone() {
    const dropZone = document.getElementById('fileDropZone');
    if (!dropZone) return;

    ['dragenter', 'dragover'].forEach(eventName => {
      dropZone.addEventListener(eventName, e => {
        e.preventDefault();
        e.stopPropagation();
        dropZone.style.borderColor = 'var(--primary, #00d2ff)';
        dropZone.style.background = 'rgba(0, 210, 255, 0.08)';
      }, false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
      dropZone.addEventListener(eventName, e => {
        e.preventDefault();
        e.stopPropagation();
        dropZone.style.borderColor = 'var(--border-glass)';
        dropZone.style.background = 'rgba(255, 255, 255, 0.02)';
      }, false);
    });

    dropZone.addEventListener('drop', e => {
      const dt = e.dataTransfer;
      if (dt && dt.files && dt.files.length > 0) {
        uploadFile(dt.files[0]);
      }
    }, false);
  }

  window.MeetingFiles = {
    loadFiles,
    uploadFile,
    uploadFileFromInput,
    formatBytes,
    getFileIcon,
    initDropZone
  };
})(window);
