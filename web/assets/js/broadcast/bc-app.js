/*
 * bc-app.js - interface da sala no modo broadcast
 *
 * Todo texto vindo da rede entra no DOM por textContent (nunca innerHTML).
 */
(function () {
  'use strict';

  var BOOT = window.BC_BOOT;
  var $ = function (id) { return document.getElementById(id); };

  var state = {
    me: null,               /* {pkey, name, role, state} */
    participants: {},       /* pkey -> participante */
    lobby: {},              /* pkey -> participante em espera */
    hands: [],
    speaker: null,
    locked: false,
    joined: false
  };

  var ERRORS = {
    banned: 'Seu acesso a esta sala foi bloqueado.',
    room_not_open: 'A sala não está aberta.',
    room_not_found: 'Sala não encontrada.',
    room_locked: 'A sala foi trancada pelo organizador.',
    bad_hello: 'Não foi possível entrar. Confira o link.',
    invite_revoked: 'Seu convite foi cancelado.',
    server_full: 'A sala está cheia.',
    service_unavailable: 'Serviço temporariamente indisponível. Tente em instantes.',
    not_admin: 'Somente o organizador pode fazer isso.',
    not_speaker: 'Você não está com a palavra.',
    rate_limited: 'Calma! Muitas mensagens seguidas.',
    message_too_long: 'Mensagem longa demais.',
    bad_target: 'Participante não encontrado.',
    bad_email: 'E-mail inválido.',
    bad_profile: 'Resolução não permitida.',
    not_allowed: 'Ação não permitida.',
    speaker_timeout: 'O orador escolhido não começou a transmitir.'
  };

  var END_TEXT = {
    kicked: ['Você foi retirado da sala', 'O organizador retirou você da reunião. Você pode pedir para entrar de novo.'],
    banned: ['Acesso bloqueado', 'O organizador bloqueou seu acesso a esta sala.'],
    denied: ['Entrada não autorizada', 'O organizador não liberou sua entrada desta vez.'],
    left: ['Você saiu da sala', ''],
    room_closed: ['Reunião encerrada', 'O organizador encerrou a reunião.'],
    room_cancelled: ['Reunião cancelada', 'O organizador cancelou a reunião.'],
    replaced: ['Sessão aberta em outra aba', 'Você entrou nesta sala em outra janela ou aparelho.'],
    waiting_timeout: ['Tempo de espera esgotado', 'Ninguém liberou sua entrada. Tente novamente.']
  };

  var PROFILES = [
    { label: '240p', w: 426, h: 240 }, { label: '360p', w: 640, h: 360 },
    { label: '480p', w: 854, h: 480 }, { label: '720p', w: 1280, h: 720 },
    { label: '1080p', w: 1920, h: 1080 }
  ];

  var client, publisher, player;
  var video = $('bcVideo'), preview = $('bcPreview');

  /* ------------------------------------------------------------ util */

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = text;
    return n;
  }

  function btn(label, cls, fn) {
    var b = el('button', 'bc-btn bc-btn-sm ' + (cls || ''), label);
    b.type = 'button';
    b.addEventListener('click', fn);
    return b;
  }

  var toastTimer;
  function toast(msg) {
    var t = $('bcToast');
    t.textContent = msg;
    t.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.hidden = true; }, 4000);
  }

  function isAdmin() { return state.me && state.me.role === 'admin'; }

  function show(id, on) { $(id).hidden = !on; }

  function cmd(type, fields, okMsg) {
    return client.cmd(type, fields).then(function (ack) {
      if (ack.status !== 'applied') toast(ERRORS[ack.error] || ('Erro: ' + ack.error));
      else if (okMsg) toast(okMsg);
      return ack;
    }).catch(function () { toast('Sem resposta do servidor.'); });
  }

  /* ------------------------------------------------------------ render */

  function renderPeople() {
    var ul = $('bcPeople');
    ul.textContent = '';
    var list = Object.keys(state.participants).map(function (k) { return state.participants[k]; });
    list.sort(function (a, b) { return (b.role === 'admin') - (a.role === 'admin') || a.name.localeCompare(b.name); });
    list.forEach(function (p) {
      var li = el('li', 'bc-item');
      var who = el('div', 'bc-who');
      who.appendChild(el('strong', null, p.name));
      var tags = [];
      if (p.role === 'admin') tags.push('organizador');
      if (state.speaker && state.speaker.pkey === p.pkey) tags.push(state.speaker.live ? 'falando' : 'iniciando');
      if (p.sub === 'hand') tags.push('✋');
      if (p.muted) tags.push('silenciado');
      if (state.me && p.pkey === state.me.pkey) tags.push('você');
      if (tags.length) who.appendChild(el('span', 'bc-tag', tags.join(' · ')));
      if (isAdmin() && p.ip) who.appendChild(el('span', 'bc-muted', p.ip));
      li.appendChild(who);
      if (isAdmin()) {
        var acts = el('div', 'bc-acts');
        var isSpeaker = state.speaker && state.speaker.pkey === p.pkey;
        acts.appendChild(isSpeaker
          ? btn('Tirar a palavra', '', function () { cmd('admin.speaker.clear'); })
          : btn('Dar a palavra', 'bc-btn-primary', function () { cmd('admin.speaker.set', { pkey: p.pkey }); }));
        acts.appendChild(menu(p));
        li.appendChild(acts);
      }
      ul.appendChild(li);
    });
    $('bcCountPeople').textContent = String(list.length);
  }

  /* Menu "..." com as demais acoes do admin (no maximo 2 botoes por linha). */
  function menu(p) {
    var wrap = el('div', 'bc-menu');
    var toggle = btn('⋯', 'bc-btn-ghost', function (ev) {
      ev.stopPropagation();
      document.querySelectorAll('.bc-menu.is-open').forEach(function (m) { if (m !== wrap) m.classList.remove('is-open'); });
      wrap.classList.toggle('is-open');
    });
    toggle.setAttribute('aria-label', 'Mais ações para ' + p.name);
    var box = el('div', 'bc-menu-box');
    PROFILES.forEach(function (pr) {
      box.appendChild(btn('Resolução ' + pr.label, '', function () {
        cmd('admin.resolution.set', { pkey: p.pkey, w: pr.w, h: pr.h }, 'Resolução ' + pr.label + ' enviada');
      }));
    });
    box.appendChild(btn(p.muted ? 'Liberar áudio' : 'Silenciar', '', function () {
      cmd(p.muted ? 'admin.unmute' : 'admin.mute', { pkey: p.pkey });
    }));
    if (state.me && p.pkey !== state.me.pkey && p.role !== 'admin') {
      box.appendChild(btn('Retirar da sala', 'bc-btn-warn', function () {
        if (confirm('Retirar ' + p.name + ' da sala?')) cmd('admin.kick', { pkey: p.pkey });
      }));
      box.appendChild(btn('Banir', 'bc-btn-danger', function () { openBan(p, 'admin.ban'); }));
    }
    wrap.appendChild(toggle);
    wrap.appendChild(box);
    return wrap;
  }

  function renderHands() {
    var ol = $('bcHandList');
    ol.textContent = '';
    state.hands.forEach(function (h) {
      var li = el('li', 'bc-item');
      var who = el('div', 'bc-who');
      who.appendChild(el('strong', null, h.position + '. ' + h.name));
      li.appendChild(who);
      if (isAdmin()) {
        var acts = el('div', 'bc-acts');
        acts.appendChild(btn('Aceitar', 'bc-btn-primary', function () { cmd('admin.hand.accept', { pkey: h.pkey }); }));
        acts.appendChild(btn('Recusar', '', function () { cmd('admin.hand.reject', { pkey: h.pkey }); }));
        li.appendChild(acts);
      }
      ol.appendChild(li);
    });
    $('bcHandTitle').hidden = state.hands.length === 0;
    var mine = state.me && state.participants[state.me.pkey];
    var hb = $('bcHandBtn');
    var speaking = state.speaker && state.me && state.speaker.pkey === state.me.pkey;
    hb.hidden = !!speaking;
    hb.textContent = mine && mine.sub === 'hand' ? 'Baixar a mão' : '✋ Pedir a palavra';
  }

  function renderLobby() {
    var ul = $('bcLobby');
    ul.textContent = '';
    var keys = Object.keys(state.lobby);
    keys.forEach(function (k) {
      var p = state.lobby[k];
      var li = el('li', 'bc-item');
      var who = el('div', 'bc-who');
      who.appendChild(el('strong', null, p.name));
      who.appendChild(el('span', 'bc-muted', (p.ip || '') + (p.invited ? ' · convidado' : ' · link público')));
      li.appendChild(who);
      var acts = el('div', 'bc-acts');
      acts.appendChild(btn('Aceitar', 'bc-btn-primary', function () { cmd('admin.admit', { pkey: p.pkey }); }));
      var more = el('div', 'bc-menu');
      var box = el('div', 'bc-menu-box');
      box.appendChild(btn('Negar acesso', 'bc-btn-warn', function () { cmd('admin.deny', { pkey: p.pkey }); }));
      box.appendChild(btn('Banir', 'bc-btn-danger', function () { openBan(p, 'admin.ban_waiting'); }));
      box.appendChild(btn('Mensagem', '', function () {
        var t = prompt('Mensagem para ' + p.name);
        if (t) client.cmd('chat.private', { pkey: p.pkey, text: t });
      }));
      more.appendChild(btn('⋯', 'bc-btn-ghost', function (ev) { ev.stopPropagation(); more.classList.toggle('is-open'); }));
      more.appendChild(box);
      acts.appendChild(more);
      li.appendChild(acts);
      ul.appendChild(li);
    });
    $('bcCountLobby').textContent = String(keys.length);
    $('bcAdmitAll').hidden = keys.length < 2;
    $('bcLobbyTab').classList.toggle('bc-attn', keys.length > 0);
  }

  function renderBans(list) {
    var ul = $('bcBans');
    ul.textContent = '';
    if (!list.length) ul.appendChild(el('li', 'bc-muted', 'Nenhum banimento ativo.'));
    list.forEach(function (b) {
      var li = el('li', 'bc-item');
      var who = el('div', 'bc-who');
      who.appendChild(el('strong', null, b.name || b.ip || b.email || ('#' + b.id)));
      who.appendChild(el('span', 'bc-muted', [b.type, b.ip, b.email, b.reason].filter(Boolean).join(' · ')));
      li.appendChild(who);
      var acts = el('div', 'bc-acts');
      acts.appendChild(btn('Desbanir', '', function () { cmd('admin.unban', { ban_id: b.id }, 'Banimento removido'); }));
      li.appendChild(acts);
      ul.appendChild(li);
    });
  }

  function addChat(target, m, priv) {
    var li = el('li', 'bc-msg' + (priv ? ' bc-msg-priv' : '') + (m.admin ? ' bc-msg-admin' : ''));
    var head = el('div', 'bc-msg-head');
    head.appendChild(el('strong', null, priv ? (m.from_name || '') + (m.to && m.to !== 'admins' ? ' (privado)' : ' → organizador') : m.name));
    head.appendChild(el('time', null, new Date(m.ts).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })));
    li.appendChild(head);
    li.appendChild(el('p', null, m.text));
    target.appendChild(li);
    target.scrollTop = target.scrollHeight;
    while (target.children.length > 500) target.removeChild(target.firstChild);
  }

  function renderStage() {
    var sp = state.speaker;
    var mine = sp && state.me && sp.pkey === state.me.pkey;
    show('bcLive', !!(sp && sp.live));
    show('bcSpeakerControls', !!mine);
    preview.hidden = !mine;
    video.hidden = !!mine;
    var tag = $('bcSpeakerTag');
    tag.hidden = !sp;
    if (sp) tag.textContent = (mine ? 'Você' : sp.name) + (sp.live ? '' : ' · conectando…');
    var msg = $('bcStageMsg');
    if (!sp) { msg.hidden = false; msg.textContent = 'Aguardando o organizador escolher quem vai falar.'; }
    else if (!sp.live && !mine) { msg.hidden = false; msg.textContent = sp.name + ' vai começar a falar…'; }
    else msg.hidden = true;
    if (!sp && player) player.stop();
  }

  function renderAll() {
    $('bcAdminTop').hidden = !isAdmin();
    $('bcLobbyTab').hidden = !isAdmin();
    $('bcBansTab').hidden = !isAdmin();
    $('bcLockBtn').textContent = state.locked ? 'Destrancar sala' : 'Trancar sala';
    renderPeople();
    renderHands();
    if (isAdmin()) renderLobby();
    renderStage();
  }

  /* ------------------------------------------------------------ eventos */

  function onMessage(m) {
    switch (m.t) {
      case 'welcome':
        state.me = { pkey: m.pkey, name: m.name, role: m.role, state: m.state };
        show('bcJoin', false);
        show('bcWaiting', m.state === 'waiting');
        renderAll();
        break;
      case 'admitted':
        if (state.me) state.me.state = 'in_room';
        show('bcWaiting', false);
        toast('Você entrou na sala.');
        break;
      case 'state.sync':
        state.participants = {};
        m.participants.forEach(function (p) { state.participants[p.pkey] = p; });
        state.lobby = {};
        (m.waiting || []).forEach(function (p) { state.lobby[p.pkey] = p; });
        state.hands = m.hand_queue || [];
        state.speaker = m.room.speaker;
        state.locked = m.room.locked;
        if (m.you) { state.me.role = m.you.role; state.me.state = 'in_room'; }
        $('bcChat').textContent = '';
        (m.chat || []).forEach(function (c) { addChat($('bcChat'), c, false); });
        show('bcWaiting', false);
        renderAll();
        break;
      case 'lobby.join':
        state.lobby[m.participant.pkey] = m.participant;
        renderLobby();
        toast(m.participant.name + ' está na sala de espera');
        break;
      case 'lobby.leave':
        delete state.lobby[m.pkey];
        renderLobby();
        break;
      case 'participant.joined':
      case 'participant.state':
        state.participants[m.participant.pkey] = Object.assign(state.participants[m.participant.pkey] || {}, m.participant);
        renderPeople();
        renderHands();
        break;
      case 'participant.left':
        delete state.participants[m.pkey];
        renderPeople();
        break;
      case 'hand.queue':
        state.hands = m.list;
        renderHands();
        break;
      case 'hand.rejected':
        toast('O organizador não aceitou seu pedido agora.');
        break;
      case 'speaker.changed':
        state.speaker = m.speaker;
        if (!m.speaker || (state.me && m.speaker.pkey !== state.me.pkey)) {
          if (publisher && publisher.active()) publisher.stop();
        }
        renderAll();
        break;
      case 'speaker.you':
        startSpeaking(m.gen, m.profile);
        break;
      case 'media.stop.request':
        if (publisher) publisher.stop();
        break;
      case 'media.profile':
        if (publisher) publisher.applyProfile(m.gen, m.profile);
        toast('Resolução alterada para ' + m.profile.w + 'x' + m.profile.h);
        break;
      case 'media.restart':
        if (publisher) publisher.restart((m.gen + 1) & 0xFF);
        break;
      case 'media.mute':
        if (publisher) publisher.setMuted(m.muted);
        $('bcMicBtn').textContent = m.muted ? 'Microfone bloqueado' : 'Microfone ligado';
        $('bcMicBtn').disabled = !!m.muted;
        toast(m.muted ? 'O organizador silenciou seu microfone.' : 'Seu microfone foi liberado.');
        break;
      case 'stream.reset':
        if (!state.me || !state.speaker || state.speaker.pkey !== state.me.pkey) {
          player.reset(m.gen, m.mime);
          $('bcUnmute').hidden = !video.muted;
        }
        break;
      case 'chat.msg':
        addChat($('bcChat'), m, false);
        if (!document.querySelector('.bc-tab[data-tab="chat"]').classList.contains('is-active'))
          $('bcCountChat').textContent = '•';
        break;
      case 'chat.private':
        addChat(state.me && state.me.state === 'waiting' && !isAdmin() ? $('bcWaitChat') : $('bcChat'), m, true);
        break;
      case 'room.state':
        state.locked = m.locked;
        renderAll();
        break;
      case 'invite.created':
        $('bcInvResult').textContent = 'Convite criado. O e-mail será enviado; link: ' + m.link;
        break;
      case 'bans':
        renderBans(m.list);
        break;
      case 'error':
        if (state.me) toast(ERRORS[m.code] || m.msg || m.code);
        break;
    }
  }

  function onTerminal(t) {
    if (publisher) publisher.stop();
    if (player) player.stop();
    var key = END_TEXT[t.reason] ? t.reason : t.state;
    var txt = END_TEXT[key] || [t.state === 'error' ? 'Não foi possível entrar' : 'Conexão encerrada', ERRORS[t.reason] || t.msg || ''];
    $('bcEndTitle').textContent = txt[0];
    $('bcEndText').textContent = txt[1];
    var retry = $('bcEndRetry');
    retry.hidden = !(t.state === 'kicked' || t.state === 'denied' || t.reason === 'waiting_timeout' || t.state === 'left');
    retry.href = 'broadcast.php?room_token=' + encodeURIComponent(BOOT.roomToken);
    show('bcWaiting', false);
    show('bcEnd', true);
  }

  async function startSpeaking(gen, profile) {
    if (!window.BcPublisher || !BcPublisher.supported()) {
      toast('Este navegador não consegue transmitir. Use Chrome, Edge ou Firefox.');
      client.cmd('media.stop');
      return;
    }
    toast('Você está com a palavra.');
    try {
      await publisher.start(gen, profile);
    } catch (e) {
      toast('Não foi possível acessar câmera/microfone: ' + e.message);
      client.cmd('media.stop');
      publisher.stop();
    }
  }

  /* ------------------------------------------------------------ banir */

  var banTarget = null;
  function openBan(p, type) {
    banTarget = { p: p, type: type };
    $('bcBanName').textContent = p.name;
    $('bcBanReason').value = '';
    show('bcBan', true);
  }

  /* ------------------------------------------------------------ inicio */

  function wire() {
    document.querySelectorAll('.bc-tab').forEach(function (t) {
      t.addEventListener('click', function () {
        document.querySelectorAll('.bc-tab').forEach(function (x) { x.classList.toggle('is-active', x === t); });
        document.querySelectorAll('.bc-panel').forEach(function (p) { p.classList.toggle('is-active', p.dataset.panel === t.dataset.tab); });
        if (t.dataset.tab === 'chat') $('bcCountChat').textContent = '';
        if (t.dataset.tab === 'bans') client.send({ t: 'admin.bans.list' });
      });
    });
    document.addEventListener('click', function () {
      document.querySelectorAll('.bc-menu.is-open').forEach(function (m) { m.classList.remove('is-open'); });
    });
    document.querySelectorAll('[data-close]').forEach(function (b) {
      b.addEventListener('click', function () { show(b.dataset.close, false); });
    });

    $('bcHandBtn').addEventListener('click', function () {
      var mine = state.me && state.participants[state.me.pkey];
      cmd(mine && mine.sub === 'hand' ? 'hand.lower' : 'hand.raise');
    });
    $('bcSrcCamera').addEventListener('click', function () { publisher.switchSource('camera'); });
    $('bcSrcScreen').addEventListener('click', function () { publisher.switchSource('screen'); });
    $('bcMicBtn').addEventListener('click', function () {
      publisher.setMuted(!publisher.muted);
      $('bcMicBtn').textContent = publisher.muted ? 'Microfone desligado' : 'Microfone ligado';
    });
    $('bcStopBtn').addEventListener('click', function () { publisher.stop(); cmd('media.stop'); });
    $('bcLeaveBtn').addEventListener('click', function () {
      if (publisher) publisher.stop();
      client.close();
      onTerminal({ state: 'left', reason: 'left' });
    });
    $('bcUnmute').addEventListener('click', function () {
      video.muted = false;
      video.play();
      $('bcUnmute').hidden = true;
    });

    $('bcChatForm').addEventListener('submit', function (ev) {
      ev.preventDefault();
      var i = $('bcChatInput');
      if (!i.value.trim()) return;
      cmd('chat.send', { text: i.value });
      i.value = '';
    });
    $('bcWaitForm').addEventListener('submit', function (ev) {
      ev.preventDefault();
      var i = $('bcWaitInput');
      if (!i.value.trim()) return;
      client.cmd('chat.private', { text: i.value });
      i.value = '';
    });

    $('bcAdmitAll').addEventListener('click', function () { cmd('admin.admit_all'); });
    $('bcInviteBtn').addEventListener('click', function () { $('bcInvResult').textContent = ''; show('bcInvite', true); });
    $('bcInviteForm').addEventListener('submit', function (ev) {
      ev.preventDefault();
      cmd('admin.invite', { email: $('bcInvEmail').value.trim(), name: $('bcInvName').value.trim() });
    });
    $('bcLockBtn').addEventListener('click', function () { cmd(state.locked ? 'admin.unlock' : 'admin.lock'); });
    $('bcCloseBtn').addEventListener('click', function () {
      if (confirm('Encerrar a reunião para todos?')) cmd('admin.close');
    });
    $('bcBanForm').addEventListener('submit', function (ev) {
      ev.preventDefault();
      if (!banTarget) return;
      cmd(banTarget.type, {
        pkey: banTarget.p.pkey, by: $('bcBanBy').value,
        minutes: parseInt($('bcBanMinutes').value, 10) || 0, reason: $('bcBanReason').value.trim()
      }, banTarget.p.name + ' foi banido');
      show('bcBan', false);
    });

    setInterval(function () {
      var dot = $('bcConnDot');
      dot.classList.toggle('is-on', !!(client && client.connected));
      if (player && player.sb) {
        $('bcStats').textContent = 'atraso ~' + (player.latencyMs() / 1000).toFixed(1) + ' s';
      } else {
        $('bcStats').textContent = '';
      }
    }, 1000);
  }

  function start(name) {
    client = new BcClient({ url: BOOT.url, roomToken: BOOT.roomToken, inviteToken: BOOT.inviteToken, name: name });
    publisher = new BcPublisher(client, {
      chunkMs: BOOT.chunkMs,
      onPreview: function (stream) { preview.srcObject = stream; },
      onError: function (msg) { toast(msg); }
    });
    player = new BcPlayer(video, {
      onState: function (s) {
        if (s === 'unsupported') toast('Este navegador não reproduz o vídeo da sala. Use Chrome, Edge ou Firefox.');
      }
    });
    client.on('message', onMessage);
    client.on('media', function (ab) { player.push(ab); });
    client.on('terminal', onTerminal);
    client.on('close', function () { if (publisher && publisher.active()) publisher.stop(); });
    client.connect();
    state.joined = true;
  }

  wire();
  var saved = '';
  try { saved = sessionStorage.getItem('bc_invite_' + BOOT.roomToken) || ''; } catch (e) { saved = ''; }
  if (BOOT.needsName && !saved) {
    show('bcJoin', true);
    $('bcJoinForm').addEventListener('submit', function (ev) {
      ev.preventDefault();
      var n = $('bcJoinName').value.trim();
      if (!n) return;
      show('bcJoin', false);
      show('bcWaiting', true);
      start(n);
    });
  } else {
    start(BOOT.defaultName);
  }
})();
