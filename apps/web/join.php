<?php
require __DIR__ . '/lib/bootstrap.php';
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$token = trim((string)($_GET['token'] ?? ''));
$roomId = (int)($_GET['room_id'] ?? ($_GET['id'] ?? 0));

$invite = null;
$room = null;

// 1. Se veio com token individual
if ($token !== '') {
    $st = $pdo->prepare("SELECT i.*, r.name room_name, r.status room_status, r.owner_user_id 
                         FROM room_invites i 
                         JOIN rooms r ON r.id=i.room_id 
                         WHERE i.token=? LIMIT 1");
    $st->execute([$token]);
    $invite = $st->fetch();
    if ($invite) {
        $roomId = (int)$invite['room_id'];
    }
}

// 2. Se veio com room_id (link público de compartilhamento da sala)
if ($roomId > 0) {
    $stRoom = $pdo->prepare("SELECT id, name, status, owner_user_id FROM rooms WHERE id=? LIMIT 1");
    $stRoom->execute([$roomId]);
    $room = $stRoom->fetch();
}

if (!$invite && !$room) {
    http_response_code(404);
    die('<div style="font-family: sans-serif; padding: 40px; text-align: center; background: #0b1120; color: #f87171; min-height: 100vh;">'
        . '<h1>Convite ou Sala não encontrada</h1>'
        . '<p style="color: #94a3b8;">Verifique o link recebido ou solicite um novo convite ao organizador.</p>'
        . '<a href="index.php" style="color: #60a5fa;">Ir para a página inicial</a>'
        . '</div>');
}

$roomName = $invite ? $invite['room_name'] : $room['name'];
$roomStatus = $invite ? $invite['room_status'] : $room['status'];

// Se a sala estiver fechada ou cancelada
if ($roomStatus !== 'open' && $roomStatus !== 'scheduled') {
    die('<div style="font-family: sans-serif; padding: 40px; text-align: center; background: #0b1120; color: #f87171; min-height: 100vh;">'
        . '<h1>Reunião Encerrada</h1>'
        . '<p style="color: #94a3b8;">Esta sala de reunião está no momento: <strong>' . htmlspecialchars($roomStatus) . '</strong>.</p>'
        . '<a href="index.php" style="color: #60a5fa;">Voltar ao início</a>'
        . '</div>');
}

// Se o usuário já tiver um token aprovado e a sala aberta, redireciona direto
if ($invite && $invite['status'] === 'approved' && $roomStatus === 'open' && empty($_GET['new']) && empty($_GET['left']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: room.php?token=" . urlencode($token));
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf($_POST['csrf'] ?? null)) {
        $error = 'Sessão expirada. Tente novamente.';
    } else {
        $displayName = trim((string)($_POST['display_name'] ?? ''));
        if ($displayName === '' || mb_strlen($displayName) > 120) {
            $error = 'Informe seu nome com até 120 caracteres.';
        }

        if ($error === null) {

        // Convidado por link público de sala -> CRIA NA SALA DE ESPERA (waiting)
        if ($roomId > 0 && (!$invite || !empty($_GET['new']) || !empty($_POST['create_new']))) {
            $guestToken = bin2hex(random_bytes(24));
            $guestKey = bin2hex(random_bytes(32));
            $guestEmail = 'guest_' . substr($guestToken, 0, 8) . '@sala.local';

            $ins = $pdo->prepare("INSERT INTO room_invites 
                (room_id, email, token, status, display_name, participant_key, requested_at, approved_at) 
                VALUES (?, ?, ?, 'waiting', ?, ?, NOW(), NULL)");
            $ins->execute([$roomId, $guestEmail, $guestToken, $displayName, $guestKey]);

            header("Location: join.php?token=" . urlencode($guestToken) . "&waiting=1");
            exit;
        }

        // Se veio por convite existente, coloca em espera caso ainda não esteja aprovado
        if ($invite) {
            $pKey = !empty($invite['participant_key']) ? $invite['participant_key'] : bin2hex(random_bytes(32));
            $signedUser = current_user();
            $isHost = $signedUser && !empty($signedUser['active'])
                && (int)$signedUser['id'] === (int)$room['owner_user_id']
                && strcasecmp($signedUser['email'], $invite['email']) === 0;

            // Se for o próprio anfitrião, aprova direto; senão, entra na sala de espera
            $newStatus = $isHost ? 'approved' : 'waiting';

            $up = $pdo->prepare("UPDATE room_invites SET 
                display_name = ?,
                participant_key = ?,
                requested_at = NOW(),
                status = ?,
                approved_at = " . ($newStatus === 'approved' ? "NOW()" : "NULL") . "
                WHERE token = ?");
            $up->execute([$displayName, $pKey, $newStatus, $token]);

            if ($newStatus === 'approved') {
                header("Location: room.php?token=" . urlencode($token));
                exit;
            } else {
                header("Location: join.php?token=" . urlencode($token) . "&waiting=1");
                exit;
            }
        }
    }
}

}

$isWaiting = ($invite && in_array($invite['status'], ['waiting', 'approved'], true));
$isRejected = ($invite && $invite['status'] === 'rejected');
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= $isWaiting ? 'Sala de Espera' : 'Entrar na Sala' ?>: <?= e($roomName) ?> - Maurinsoft</title>
  <link rel="stylesheet" href="assets/css/salareuniao.css?v=20260919_3">
  <style>
    body {
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      padding: 24px;
      background: #050811;
      color: #fff;
    }
    .sr-join-card {
      min-width: 0;
      overflow-wrap: anywhere;
      background: var(--bg-card, rgba(15, 23, 42, 0.8));
      backdrop-filter: blur(16px);
      border: 1px solid var(--border-glass, rgba(255, 255, 255, 0.1));
      border-radius: var(--radius-lg, 16px);
      padding: 36px 32px;
      max-width: 520px;
      width: 100%;
      box-shadow: 0 10px 40px rgba(0,0,0,0.6);
      text-align: center;
    }
    .sr-preview-container {
      position: relative;
      width: 100%;
      aspect-ratio: 16/9;
      background: #090d16;
      border-radius: 12px;
      overflow: hidden;
      margin: 20px 0;
      border: 1px solid rgba(255, 255, 255, 0.1);
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .sr-preview-container video {
      width: 100%;
      height: 100%;
      object-fit: cover;
      transform: scaleX(-1);
    }
    .sr-preview-controls {
      position: absolute;
      bottom: 12px;
      left: 50%;
      transform: translateX(-50%);
      display: flex;
      gap: 10px;
      background: rgba(11, 17, 32, 0.8);
      backdrop-filter: blur(8px);
      padding: 6px 14px;
      border-radius: 30px;
      border: 1px solid rgba(255, 255, 255, 0.15);
    }
    .sr-preview-btn {
      background: none;
      border: none;
      color: #fff;
      font-size: 0.85rem;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 6px;
      padding: 4px 8px;
      border-radius: 6px;
    }
    .sr-pulse-circle {
      width: 64px;
      height: 64px;
      border-radius: 50%;
      background: rgba(0, 210, 255, 0.15);
      border: 2px solid var(--primary, #00d2ff);
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 16px;
      font-size: 1.8rem;
      animation: pulseAnim 2s infinite ease-in-out;
    }
    @keyframes pulseAnim {
      0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(0, 210, 255, 0.4); }
      70% { transform: scale(1.05); box-shadow: 0 0 0 15px rgba(0, 210, 255, 0); }
      100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(0, 210, 255, 0); }
    }

    @media (max-width: 480px) {
      body { padding: 12px; }
      .sr-join-card {
      min-width: 0;
      overflow-wrap: anywhere; padding: 22px 16px; border-radius: 12px; }
    }
  </style>
</head>
<body>
  <div class="sr-join-card">
    
    <!-- TELA 1: SAIU DA REUNIÃO (CONFIRMAÇÃO) -->
    <?php if (!empty($_GET['left'])): ?>
      <div style="padding: 20px 0;">
        <div style="font-size: 3.2rem; margin-bottom: 14px;">👋</div>
        <h2 style="font-size: 1.4rem; font-weight: 700; margin-bottom: 8px;">Você saiu da reunião</h2>
        <p style="color: var(--text-muted, #94a3b8); font-size: 0.9rem; margin-bottom: 24px;">
          Sua conexão e mídia com a sala <strong><?= e($roomName) ?></strong> foram encerradas com sucesso.
        </p>
        <div style="display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
          <a href="join.php?room_id=<?= urlencode((string)$roomId) ?>" class="sr-btn sr-btn-primary">
            🔄 Entrar Novamente
          </a>
          <a href="index.php" class="sr-btn sr-btn-outline">
            🏠 Voltar ao Painel
          </a>
        </div>
      </div>

    <!-- TELA 2: SALA DE ESPERA (LOBBY COM APROVAÇÃO DO ANFITRIÃO) -->
    <?php elseif ($isWaiting): ?>
      <div id="lobbyWaitingArea" style="padding: 16px 0;">
        <div class="sr-pulse-circle">⏳</div>
        <h2 style="font-size: 1.35rem; font-weight: 700; margin-bottom: 6px;">Sala de Espera</h2>
        <h3 style="font-size: 1rem; color: var(--primary, #00d2ff); margin-bottom: 10px;"><?= e($roomName) ?></h3>
        <p style="color: var(--text-muted, #94a3b8); font-size: 0.92rem; max-width: 440px; margin: 0 auto 16px;">
          Olá, <strong><?= e($invite ? $invite['display_name'] : 'Visitante') ?></strong>! Sua solicitação foi enviada. O moderador da reunião foi avisado e autorizará sua entrada em instantes.
        </p>
        <div style="font-size: 0.8rem; color: var(--text-dim, #64748b);">
          Verificando permissão de entrada em tempo real...
        </div>
      </div>

      <div id="lobbyRejectedArea" style="display: none; padding: 20px 0;">
        <div style="font-size: 3.2rem; margin-bottom: 12px;">🚫</div>
        <h2 style="font-size: 1.35rem; font-weight: 700; margin-bottom: 8px; color: #ef4444;">Entrada Não Autorizada</h2>
        <p style="color: var(--text-muted, #94a3b8); font-size: 0.9rem; margin-bottom: 24px;">
          O moderador da reunião não autorizou sua entrada neste momento.
        </p>
        <a href="index.php" class="sr-btn sr-btn-outline">Voltar ao Início</a>
      </div>

      <script>
        // Polling contínuo da Sala de Espera
        const guestToken = <?= json_encode($token) ?>;
        const lobbyPoll = setInterval(async () => {
          try {
            const resp = await fetch('api/join_status.php?token=' + encodeURIComponent(guestToken), {cache: 'no-store'});
            const data = await resp.json();
            if (data.ok) {
              if (['closed', 'cancelled'].includes(data.room_status)) {
                clearInterval(lobbyPoll);
                window.location.reload();
              } else if (data.status === 'approved' && data.room_status === 'open') {
                clearInterval(lobbyPoll);
                stopPreviewStream();
                window.location.href = 'room.php?token=' + encodeURIComponent(guestToken);
              } else if (data.status === 'rejected') {
                clearInterval(lobbyPoll);
                document.getElementById('lobbyWaitingArea').style.display = 'none';
                document.getElementById('lobbyRejectedArea').style.display = 'block';
              }
            }
          } catch(e) {}
        }, 2000);
      </script>

    <?php elseif ($isRejected): ?>
      <h1>Entrada não autorizada</h1>
      <p>O anfitrião não autorizou sua entrada nesta reunião.</p>
      <p>Entre em contato com quem enviou o convite.</p>

    <!-- TELA 3: ENTRADA COM NOME E PRÉVIA (PEDIR PARA ENTRAR) -->
    <?php else: ?>
      <div class="sr-brand-logo" style="margin: 0 auto 12px; width: 44px; height: 44px; font-size: 20px;">M</div>
      <h1 style="font-size: 1.4rem; font-weight: 700; margin-bottom: 4px;"><?= e($roomName) ?></h1>
      <p style="color: var(--text-muted, #94a3b8); font-size: 0.88rem;">
        <?= $invite ? 'Convite para: ' . e($invite['email']) : 'Acesso de Participante Convidado' ?>
      </p>

      <?php if ($error): ?>
        <div class="sr-alert sr-alert-danger" style="margin: 16px 0;"><?= e($error) ?></div>
      <?php endif; ?>

      <!-- Preview de Câmera e Áudio -->
      <div class="sr-preview-container" id="previewBox">
        <video id="previewVideo" autoplay playsinline muted webkit-playsinline></video>
        <div id="previewPlaceholder" style="display: none; color: var(--text-muted, #94a3b8); padding: 20px; font-size: 0.9rem;">
          Câmera desativada ou ocupada.<br>
          <span style="font-size: 0.8rem; color: var(--text-dim, #64748b);">Você poderá habilitar dentro da sala.</span>
        </div>
        <div class="sr-preview-controls">
          <button type="button" class="sr-preview-btn" id="btnToggleCam" onclick="togglePreviewCam()">
            📹 <span>Câmera On</span>
          </button>
          <button type="button" class="sr-preview-btn" id="btnToggleMic" onclick="togglePreviewMic()">
            🎤 <span>Mic On</span>
          </button>
        </div>
      </div>

      <form method="post" id="joinForm">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <?php if ($roomId > 0 && (!$invite || !empty($_GET['new']))): ?>
          <input type="hidden" name="create_new" value="1">
        <?php endif; ?>
        
        <div class="sr-form-group" style="text-align: left; margin-bottom: 20px;">
          <label class="sr-label" for="displayName">Qual o seu nome?</label>
          <input type="text" id="displayName" name="display_name" class="sr-input" 
                 value="<?= e($invite ? ($invite['display_name'] ?: explode('@', $invite['email'])[0]) : '') ?>" 
                 placeholder="Digite seu nome para ser identificado na chamada" maxlength="120" autocomplete="name" required autofocus>
        </div>

        <button type="submit" class="sr-btn sr-btn-primary sr-btn-lg" style="width: 100%; justify-content: center;">
          Pedir para Entrar na Reunião &rarr;
        </button>
      </form>
    <?php endif; ?>

  </div>

  <script>
    let localStream = null;
    let camOn = true;
    let micOn = true;

    async function initPreview() {
      try {
        localStream = await navigator.mediaDevices.getUserMedia({
          video: { width: { ideal: 640 }, height: { ideal: 360 } },
          audio: true
        });
        const video = document.getElementById('previewVideo');
        if (video) video.srcObject = localStream;
      } catch (err) {
        console.warn('Prévia de mídia indisponível:', err);
        const p = document.getElementById('previewPlaceholder');
        if (p) p.style.display = 'block';
        const v = document.getElementById('previewVideo');
        if (v) v.style.display = 'none';
        camOn = false;
        const btn = document.getElementById('btnToggleCam');
        if (btn) btn.innerHTML = '📹 <span>Câmera Off</span>';
      }
    }

    function togglePreviewCam() {
      if (!localStream) return;
      camOn = !camOn;
      localStream.getVideoTracks().forEach(t => t.enabled = camOn);
      const v = document.getElementById('previewVideo');
      const p = document.getElementById('previewPlaceholder');
      if (v) v.style.display = camOn ? 'block' : 'none';
      if (p) p.style.display = camOn ? 'none' : 'block';
      const btn = document.getElementById('btnToggleCam');
      if (btn) btn.innerHTML = camOn ? '📹 <span>Câmera On</span>' : '📹 <span>Câmera Off</span>';
    }

    function togglePreviewMic() {
      if (!localStream) return;
      micOn = !micOn;
      localStream.getAudioTracks().forEach(t => t.enabled = micOn);
      const btn = document.getElementById('btnToggleMic');
      if (btn) btn.innerHTML = micOn ? '🎤 <span>Mic On</span>' : '🎤 <span>Mic Mudo</span>';
    }

    function stopPreviewStream() {
      if (localStream) {
        localStream.getTracks().forEach(t => {
          try { t.stop(); } catch(e) {}
        });
        localStream = null;
      }
      const video = document.getElementById('previewVideo');
      if (video) video.srcObject = null;
    }

    window.addEventListener('beforeunload', stopPreviewStream);
    window.addEventListener('pagehide', stopPreviewStream);
    const form = document.getElementById('joinForm');
    if (form) {
      form.addEventListener('submit', stopPreviewStream);
    }

    if (document.getElementById('previewVideo')) {
      window.addEventListener('DOMContentLoaded', initPreview);
    }
  </script>
</body>
</html>
