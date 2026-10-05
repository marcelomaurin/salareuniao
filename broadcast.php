<?php
declare(strict_types=1);

/**
 * Sala de reunião no modo broadcast: um orador por vez, distribuído pelo
 * servidor bcastd (pasta broadcast/). A sala de espera, a palavra, o chat e
 * as funções do administrador trafegam pelo WebSocket do serviço.
 *
 * Entrada:
 *   broadcast.php?room_token=<rooms.join_token>   link público (vai para a espera)
 *   broadcast.php?token=<room_invites.token>      convite individual
 */

require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/broadcast.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$bc = broadcast_config($config);
if (!$bc['enabled'] || $bc['public_url'] === '') {
    http_response_code(503);
    exit('O serviço de broadcast não está habilitado (config.php: broadcast.enabled).');
}

$inviteToken = trim((string)($_GET['token'] ?? ''));
$roomToken = trim((string)($_GET['room_token'] ?? ''));
if ($inviteToken !== '' && !preg_match('/^[a-f0-9]{32,128}$/i', $inviteToken)) $inviteToken = '';
if ($roomToken !== '' && !preg_match('/^[a-f0-9]{32,128}$/i', $roomToken)) $roomToken = '';

$room = null;
$invite = null;
if ($inviteToken !== '') {
    $st = $pdo->prepare('SELECT i.id, i.status, i.display_name, i.email, r.id AS room_id, r.name, r.status AS room_status, r.join_token
                         FROM room_invites i JOIN rooms r ON r.id = i.room_id WHERE i.token = ? LIMIT 1');
    $st->execute([$inviteToken]);
    $invite = $st->fetch() ?: null;
    if ($invite) {
        $room = ['id' => $invite['room_id'], 'name' => $invite['name'], 'status' => $invite['room_status'], 'join_token' => $invite['join_token']];
    }
}
if (!$room && $roomToken !== '') {
    $st = $pdo->prepare('SELECT id, name, status, join_token FROM rooms WHERE join_token = ? LIMIT 1');
    $st->execute([$roomToken]);
    $room = $st->fetch() ?: null;
}
if (!$room || empty($room['join_token'])) {
    http_response_code(404);
    exit('Sala não encontrada. Confira o link recebido.');
}
if ($room['status'] !== 'open') {
    exit('A sala ainda não foi aberta pelo organizador ou já foi encerrada.');
}

// Usuário logado que é organizador/convidado desta sala entra com o próprio convite.
$user = current_user();
if (!$invite && $user) {
    $st = $pdo->prepare("SELECT token, display_name FROM room_invites
                         WHERE room_id = ? AND LOWER(email) = LOWER(?) AND status = 'approved' ORDER BY id LIMIT 1");
    $st->execute([(int)$room['id'], (string)$user['email']]);
    if ($row = $st->fetch()) {
        $inviteToken = (string)$row['token'];
        $invite = ['display_name' => $row['display_name']];
    }
}

$defaultName = (string)($invite['display_name'] ?? ($user['name'] ?? ''));
$boot = [
    'url' => $bc['public_url'],
    'roomToken' => (string)$room['join_token'],
    'inviteToken' => $inviteToken,
    'roomName' => (string)$room['name'],
    'defaultName' => $defaultName,
    'needsName' => $inviteToken === '',
    'chunkMs' => $bc['chunk_ms'],
];
?><!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e((string)$room['name']) ?> · Sala Reunião</title>
  <link rel="stylesheet" href="assets/css/salareuniao.css?v=20261005_bc1">
  <link rel="stylesheet" href="assets/css/broadcast.css?v=20261005_bc1">
</head>
<body class="bc-body">
  <header class="bc-top">
    <div class="bc-title">
      <span class="bc-dot" id="bcConnDot" title="Conexão"></span>
      <h1 id="bcRoomName"><?= e((string)$room['name']) ?></h1>
      <span class="bc-live" id="bcLive" hidden>AO VIVO</span>
    </div>
    <div class="bc-top-actions" id="bcAdminTop" hidden>
      <button type="button" class="bc-btn" id="bcInviteBtn">Convidar</button>
      <button type="button" class="bc-btn" id="bcLockBtn">Trancar sala</button>
      <button type="button" class="bc-btn bc-btn-danger" id="bcCloseBtn">Encerrar</button>
    </div>
  </header>

  <main class="bc-main">
    <section class="bc-stage-wrap">
      <div class="bc-stage">
        <video id="bcVideo" playsinline autoplay muted></video>
        <video id="bcPreview" playsinline autoplay muted hidden></video>
        <div class="bc-stage-msg" id="bcStageMsg">Aguardando o organizador escolher quem vai falar.</div>
        <button type="button" class="bc-unmute" id="bcUnmute" hidden>Ativar som</button>
        <div class="bc-speaker-tag" id="bcSpeakerTag" hidden></div>
      </div>
      <div class="bc-controls">
        <button type="button" class="bc-btn" id="bcHandBtn">✋ Pedir a palavra</button>
        <span id="bcSpeakerControls" hidden>
          <button type="button" class="bc-btn" id="bcSrcCamera">Câmera</button>
          <button type="button" class="bc-btn" id="bcSrcScreen">Tela</button>
          <button type="button" class="bc-btn" id="bcMicBtn">Microfone ligado</button>
          <button type="button" class="bc-btn bc-btn-danger" id="bcStopBtn">Encerrar minha fala</button>
        </span>
        <button type="button" class="bc-btn bc-btn-ghost" id="bcLeaveBtn">Sair</button>
        <span class="bc-stats" id="bcStats"></span>
      </div>
    </section>

    <aside class="bc-side">
      <nav class="bc-tabs" role="tablist">
        <button type="button" class="bc-tab is-active" data-tab="people">Participantes <span id="bcCountPeople">0</span></button>
        <button type="button" class="bc-tab" data-tab="chat">Chat <span id="bcCountChat"></span></button>
        <button type="button" class="bc-tab" data-tab="lobby" id="bcLobbyTab" hidden>Espera <span id="bcCountLobby">0</span></button>
        <button type="button" class="bc-tab" data-tab="bans" id="bcBansTab" hidden>Banidos</button>
      </nav>
      <div class="bc-panel is-active" data-panel="people">
        <h3 class="bc-h3" id="bcHandTitle" hidden>Pedidos de palavra</h3>
        <ol class="bc-list" id="bcHandList"></ol>
        <h3 class="bc-h3">Na sala</h3>
        <ul class="bc-list" id="bcPeople"></ul>
      </div>
      <div class="bc-panel" data-panel="chat">
        <ul class="bc-chat" id="bcChat"></ul>
        <form class="bc-chat-form" id="bcChatForm" autocomplete="off">
          <input type="text" id="bcChatInput" maxlength="2000" placeholder="Escreva uma mensagem" aria-label="Mensagem">
          <button type="submit" class="bc-btn">Enviar</button>
        </form>
      </div>
      <div class="bc-panel" data-panel="lobby">
        <button type="button" class="bc-btn bc-btn-sm" id="bcAdmitAll" hidden>Aceitar todos</button>
        <ul class="bc-list" id="bcLobby"></ul>
      </div>
      <div class="bc-panel" data-panel="bans">
        <ul class="bc-list" id="bcBans"></ul>
      </div>
    </aside>
  </main>

  <!-- entrada: nome -->
  <div class="bc-overlay" id="bcJoin" hidden>
    <form class="bc-card" id="bcJoinForm">
      <h2>Entrar em <?= e((string)$room['name']) ?></h2>
      <label for="bcJoinName">Seu nome</label>
      <input type="text" id="bcJoinName" maxlength="120" required value="<?= e($defaultName) ?>">
      <button type="submit" class="bc-btn bc-btn-primary">Entrar na sala de espera</button>
    </form>
  </div>

  <!-- sala de espera -->
  <div class="bc-overlay" id="bcWaiting" hidden>
    <div class="bc-card">
      <h2>Aguardando liberação</h2>
      <p>O organizador foi avisado. Assim que ele aceitar, você entra na sala.</p>
      <ul class="bc-chat bc-chat-small" id="bcWaitChat"></ul>
      <form class="bc-chat-form" id="bcWaitForm" autocomplete="off">
        <input type="text" id="bcWaitInput" maxlength="500" placeholder="Mensagem para o organizador" aria-label="Mensagem para o organizador">
        <button type="submit" class="bc-btn">Enviar</button>
      </form>
    </div>
  </div>

  <!-- fim -->
  <div class="bc-overlay" id="bcEnd" hidden>
    <div class="bc-card">
      <h2 id="bcEndTitle">Você saiu da sala</h2>
      <p id="bcEndText"></p>
      <a class="bc-btn bc-btn-primary" id="bcEndRetry" href="#" hidden>Tentar novamente</a>
    </div>
  </div>

  <!-- convite -->
  <div class="bc-overlay" id="bcInvite" hidden>
    <form class="bc-card" id="bcInviteForm">
      <h2>Convidar por e-mail</h2>
      <label for="bcInvEmail">E-mail</label>
      <input type="email" id="bcInvEmail" required maxlength="190">
      <label for="bcInvName">Nome (opcional)</label>
      <input type="text" id="bcInvName" maxlength="120">
      <p class="bc-muted" id="bcInvResult"></p>
      <div class="bc-row">
        <button type="submit" class="bc-btn bc-btn-primary">Criar convite</button>
        <button type="button" class="bc-btn bc-btn-ghost" data-close="bcInvite">Fechar</button>
      </div>
    </form>
  </div>

  <!-- banir: escolha -->
  <div class="bc-overlay" id="bcBan" hidden>
    <form class="bc-card" id="bcBanForm">
      <h2>Banir <span id="bcBanName"></span></h2>
      <label for="bcBanBy">Bloquear por</label>
      <select id="bcBanBy">
        <option value="both">IP e identidade</option>
        <option value="ip">Somente IP</option>
        <option value="identity">Somente identidade (convite)</option>
      </select>
      <label for="bcBanMinutes">Duração</label>
      <select id="bcBanMinutes">
        <option value="0">Permanente</option>
        <option value="60">1 hora</option>
        <option value="1440">1 dia</option>
      </select>
      <label for="bcBanReason">Motivo</label>
      <input type="text" id="bcBanReason" maxlength="200">
      <div class="bc-row">
        <button type="submit" class="bc-btn bc-btn-danger">Banir</button>
        <button type="button" class="bc-btn bc-btn-ghost" data-close="bcBan">Cancelar</button>
      </div>
    </form>
  </div>

  <div class="bc-toast" id="bcToast" role="status" hidden></div>

  <script>window.BC_BOOT = <?= json_encode($boot, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
  <script src="assets/js/broadcast/bc-client.js?v=20261005_bc1"></script>
  <script src="assets/js/broadcast/bc-media.js?v=20261005_bc1"></script>
  <script src="assets/js/broadcast/bc-app.js?v=20261005_bc1"></script>
</body>
</html>
