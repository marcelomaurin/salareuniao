/**
 * Módulo de Chat em Tempo Real
 * Suporta WebSocket e Fallback HTTP transparente
 */
(function(window) {
  'use strict';

  let lastChatId = 0;
  let chatOpen = false;
  let chatUnread = 0;

  function appendChatMessage(m) {
    const wrap = document.getElementById('chatMessages');
    if (!wrap) return;
    if (wrap.querySelector('.empty')) wrap.innerHTML = '';
    if (document.getElementById('chat-' + m.id)) return;

    const cfg = window.MEETING_CONFIG;
    const isSelf = (m.participant_key === cfg.selfKey);

    const div = document.createElement('div');
    div.className = 'chatMsg' + (isSelf ? ' self' : '');
    div.id = 'chat-' + m.id;

    const meta = document.createElement('div');
    meta.className = 'chatMeta';
    meta.textContent = m.display_name + ' · ' + String(m.created_at || '').slice(11, 16);

    const text = document.createElement('div');
    text.className = 'chatText';
    text.textContent = m.message;

    div.append(meta, text);
    wrap.appendChild(div);
    lastChatId = Math.max(lastChatId, Number(m.id) || 0);

    if (chatOpen) {
      wrap.scrollTop = wrap.scrollHeight;
    } else if (!isSelf) {
      chatUnread++;
      updateUnread();
    }
  }

  function updateUnread() {
    const badge = document.getElementById('chatUnread');
    const topBadge = document.getElementById('chatBadgeCount');
    if (badge) {
      badge.style.display = chatUnread > 0 ? 'inline-block' : 'none';
      badge.textContent = chatUnread > 0 ? '(' + chatUnread + ')' : '';
    }
    if (topBadge) {
      topBadge.style.display = chatUnread > 0 ? 'inline-block' : 'none';
      topBadge.textContent = String(chatUnread);
    }
  }

  async function loadChatHistory() {
    const cfg = window.MEETING_CONFIG;
    try {
      const resp = await fetch(`api/chat_history.php?token=${encodeURIComponent(cfg.TOKEN)}&limit=100`, { cache: 'no-store' });
      const d = await resp.json();
      if (d.self) cfg.selfKey = d.self;
      for (const m of (d.messages || [])) {
        appendChatMessage(m);
      }
      chatUnread = 0;
      updateUnread();
    } catch (e) {
      console.warn('Erro ao carregar histórico do chat:', e);
    }
  }

  async function pollChat() {
    if (window.MeetingApp?.isLeaving() || window.MeetingSignaling?.isWsReady()) return;
    const cfg = window.MEETING_CONFIG;

    try {
      const resp = await fetch(`api/chat_poll.php?token=${encodeURIComponent(cfg.TOKEN)}&after=${lastChatId}`, { cache: 'no-store' });
      const d = await resp.json();
      for (const m of (d.messages || [])) {
        appendChatMessage(m);
      }
    } catch (e) {
      // Erro transitório de poll
    }
    setTimeout(pollChat, 1500);
  }

  async function sendChatMessage(text) {
    const msg = text.trim();
    if (!msg) return;
    const cfg = window.MEETING_CONFIG;

    if (window.MeetingSignaling?.isWsReady()) {
      // Envia via WebSocket
      await window.MeetingSignaling.sendSignal('chat-ws-noop', {}); // ou envia direto
      // MeetingSocket aceita {type: 'chat', message: text}
      // O Signaling tem o ws
    }

    // Por segurança e compatibilidade com persistência
    const d = await fetch('api/chat_send.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: cfg.TOKEN, message: msg })
    }).then(r => r.json());

    if (d.message) {
      appendChatMessage(d.message);
    }
  }

  function setChatOpen(isOpen) {
    chatOpen = isOpen;
    if (chatOpen) {
      chatUnread = 0;
      updateUnread();
      const wrap = document.getElementById('chatMessages');
      if (wrap) setTimeout(() => { wrap.scrollTop = wrap.scrollHeight; }, 0);
    }
  }

  window.MeetingChat = {
    appendChatMessage,
    loadChatHistory,
    pollChat,
    sendChatMessage,
    setChatOpen
  };
})(window);
