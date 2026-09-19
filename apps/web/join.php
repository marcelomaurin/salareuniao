<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$st = $pdo->prepare('SELECT i.*, r.name room_name, r.description room_description, r.status room_status, r.starts_at FROM room_invites i JOIN rooms r ON r.id=i.room_id WHERE i.token=? LIMIT 1');
$st->execute([$token]);
$invite = $st->fetch();

if (!$invite) {
    http_response_code(404);
    exit('Convite não encontrado ou inválido.');
}

if ($invite['status'] === 'approved' && $invite['room_status'] === 'open') {
    header('Location: room.php?token=' . urlencode($token));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $invite['status'] === 'invited' && $invite['room_status'] !== 'cancelled') {
    verify_csrf();
    $name = trim($_POST['display_name'] ?? '');
    if ($name !== '') {
        $participantKey = bin2hex(random_bytes(32));
        $up = $pdo->prepare("UPDATE room_invites SET display_name=?, participant_key=?, status='waiting', requested_at=NOW() WHERE id=?");
        $up->execute([$name, $participantKey, $invite['id']]);
        header('Location: join.php?token=' . urlencode($token));
        exit;
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Entrar na Reunião - <?=e($invite['room_name'])?></title>
  <?php if ($invite['status'] === 'waiting' && $invite['room_status'] !== 'cancelled'): ?>
    <meta http-equiv="refresh" content="3">
  <?php endif; ?>
  <link rel="stylesheet" href="assets/css/salareuniao.css">
  <style>
    body {
      display: flex;
      flex-direction: column;
      min-height: 100vh;
      align-items: center;
      justify-content: center;
      padding: 24px;
    }

    .sr-lobby-card {
      background: var(--bg-card);
      backdrop-filter: blur(16px);
      border: 1px solid var(--border-glass);
      border-radius: var(--radius-lg);
      padding: 36px 32px;
      max-width: 580px;
      width: 100%;
      box-shadow: var(--shadow-md);
      text-align: center;
      position: relative;
    }

    .sr-preview-box {
      width: 100%;
      aspect-ratio: 16/9;
      background: #000;
      border-radius: var(--radius-md);
      overflow: hidden;
      margin: 20px 0;
      position: relative;
      border: 1px solid rgba(255, 255, 255, 0.1);
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .sr-preview-box video {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .sr-preview-badge {
      position: absolute;
      bottom: 12px;
      left: 12px;
      background: rgba(0, 0, 0, 0.65);
      color: #fff;
      font-size: 0.75rem;
      padding: 4px 10px;
      border-radius: 6px;
      backdrop-filter: blur(8px);
    }

    .sr-preview-controls {
      position: absolute;
      bottom: 12px;
      right: 12px;
      display: flex;
      gap: 8px;
    }

    .sr-waiting-pulse {
      display: inline-block;
      width: 60px;
      height: 60px;
      border-radius: 50%;
      background: rgba(0, 210, 255, 0.15);
      border: 2px solid var(--primary);
      animation: srRing 1.8s infinite;
      margin-bottom: 20px;
    }

    @keyframes srRing {
      0% { transform: scale(0.9); box-shadow: 0 0 0 0 rgba(0, 210, 255, 0.5); }
      70% { transform: scale(1.05); box-shadow: 0 0 0 15px rgba(0, 210, 255, 0); }
      100% { transform: scale(0.9); box-shadow: 0 0 0 0 rgba(0, 210, 255, 0); }
    }
  </style>
</head>
<body>

  <div class="sr-lobby-card">
    <div style="display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 16px;">
      <div class="sr-brand-logo">M</div>
      <span class="sr-brand-title">Maurinsoft Sala Reunião</span>
    </div>

    <h1 style="font-size: 1.6rem; margin-bottom: 6px;"><?=e($invite['room_name'])?></h1>
    <?php if (!empty($invite['room_description'])): ?>
      <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 12px;"><?=e($invite['room_description'])?></p>
    <?php endif; ?>

    <div style="margin-bottom: 16px;">
      <?php if ($invite['room_status'] === 'open'): ?>
        <span class="sr-badge sr-badge-open"><span class="sr-pulse-dot"></span> Reunião em Andamento</span>
      <?php elseif ($invite['room_status'] === 'scheduled'): ?>
        <span class="sr-badge sr-badge-scheduled">Agendada para: <?=!empty($invite['starts_at']) ? date('d/m/Y H:i', strtotime($invite['starts_at'])) : 'Em breve'?></span>
      <?php else: ?>
        <span class="sr-badge sr-badge-closed">Reunião Encerrada</span>
      <?php endif; ?>
    </div>

    <!-- Live Media Preview -->
    <div class="sr-preview-box">
      <video id="previewVideo" autoplay muted playsinline></video>
      <div class="sr-preview-badge">Prévia da Câmera</div>
      <div class="sr-preview-controls">
        <button type="button" class="sr-btn sr-btn-secondary sr-btn-sm" onclick="togglePreviewCam()" id="btnToggleCam">Desligar Cam</button>
        <button type="button" class="sr-btn sr-btn-secondary sr-btn-sm" onclick="togglePreviewMic()" id="btnToggleMic">Mutar Mic</button>
      </div>
    </div>

    <!-- Status: Invited (Needs to enter name) -->
    <?php if ($invite['status'] === 'invited' && $invite['room_status'] !== 'cancelled'): ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <div class="sr-form-group" style="text-align: left;">
          <label class="sr-label" for="displayName">Como gostaria de ser chamado(a)?</label>
          <input type="text" id="displayName" name="display_name" class="sr-input" value="<?=e($invite['display_name'] ?? '')?>" placeholder="Seu nome ou apelido" required autofocus>
        </div>

        <button type="submit" class="sr-btn sr-btn-primary sr-btn-lg" style="width: 100%;">
          Pedir para Entrar na Sala &rarr;
        </button>
      </form>

    <!-- Status: Waiting Approval -->
    <?php elseif ($invite['status'] === 'waiting' && $invite['room_status'] !== 'cancelled'): ?>
      <div style="padding: 20px 0;">
        <div class="sr-waiting-pulse"></div>
        <h3 style="margin-bottom: 8px;">Aguardando o anfitrião...</h3>
        <p style="color: var(--text-muted); font-size: 0.92rem; max-width: 420px; margin: 0 auto;">
          Sua solicitação foi enviada. Assim que o moderador autorizar sua entrada, você será conectado(a) automaticamente à chamada.
        </p>
        <div style="margin-top: 18px; font-size: 0.8rem; color: var(--text-dim);">
          Verificando permissão a cada 3 segundos...
        </div>
      </div>

    <!-- Status: Approved -->
    <?php elseif ($invite['status'] === 'approved'): ?>
      <?php if ($invite['room_status'] === 'open'): ?>
        <p style="color: #6ee7b7; margin-bottom: 20px;">Sua entrada foi autorizada!</p>
        <a href="room.php?token=<?=urlencode($token)?>" class="sr-btn sr-btn-primary sr-btn-lg" style="width: 100%;">
          Conectar Agora &rarr;
        </a>
      <?php else: ?>
        <p style="color: #fbbf24; margin-bottom: 12px;">Sua entrada foi autorizada, mas a sala ainda não foi iniciada pelo anfitrião.</p>
        <p style="color: var(--text-muted); font-size: 0.85rem;">Esta página atualizará automaticamente quando a sala abrir.</p>
      <?php endif; ?>

    <!-- Status: Rejected or Cancelled -->
    <?php else: ?>
      <div class="sr-alert sr-alert-error" style="text-align: left;">
        <span>Esta reunião foi cancelada ou seu acesso não foi autorizado pelo anfitrião.</span>
      </div>
      <a href="../index.html" class="sr-btn sr-btn-secondary" style="width: 100%;">
        Voltar para a Página Inicial
      </a>
    <?php endif; ?>

    <div style="margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--border-glass); font-size: 0.8rem; color: var(--text-dim);">
      Videoconferência segura fornecida por Maurinsoft WebRTC &bull; <a href="../sobre.html">Saiba Mais</a>
    </div>
  </div>

  <script>
    let localStream = null;
    let camOn = true;
    let micOn = true;

    async function initPreview() {
      try {
        localStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
        const video = document.getElementById('previewVideo');
        if (video) video.srcObject = localStream;
      } catch (e) {
        console.warn('Câmera ou microfone indisponível na prévia:', e);
        const box = document.querySelector('.sr-preview-box');
        if (box) {
          box.innerHTML = '<div style="color: var(--text-muted); font-size: 0.9rem; padding: 20px;">Câmera/microfone desativados ou permissão negada. Você ainda pode ingressar e habilitar dentro da sala.</div>';
        }
      }
    }

    function togglePreviewCam() {
      if (!localStream) return;
      camOn = !camOn;
      localStream.getVideoTracks().forEach(t => t.enabled = camOn);
      document.getElementById('btnToggleCam').textContent = camOn ? 'Desligar Cam' : 'Ligar Cam';
    }

    function togglePreviewMic() {
      if (!localStream) return;
      micOn = !micOn;
      localStream.getAudioTracks().forEach(t => t.enabled = micOn);
      document.getElementById('btnToggleMic').textContent = micOn ? 'Mutar Mic' : 'Desmutar Mic';
    }

    window.addEventListener('DOMContentLoaded', initPreview);
  </script>
</body>
</html>
