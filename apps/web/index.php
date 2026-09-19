<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}

// Ação de Criar Reunião Instantânea
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'quick_meeting') {
    verify_csrf();
    $name = 'Reunião Instantânea - ' . date('d/m H:i');
    $st = $pdo->prepare("INSERT INTO rooms (owner_user_id, name, description, status, starts_at) VALUES (?, ?, 'Reunião rápida criada diretamente pelo painel', 'open', NOW())");
    $st->execute([$user['id'], $name]);
    $roomId = (int)$pdo->lastInsertId();

    $token = bin2hex(random_bytes(24));
    $participantKey = bin2hex(random_bytes(32));
    $st = $pdo->prepare("INSERT INTO room_invites (room_id, email, display_name, token, participant_key, status, approved_at) VALUES (?, ?, ?, ?, ?, 'approved', NOW())");
    $st->execute([$roomId, $user['email'], $user['name'], $token, $participantKey]);

    audit_log('room.quick_create', 'room', $roomId, ['name' => $name]);
    header('Location: room.php?token=' . urlencode($token));
    exit;
}

// Busca salas do usuário (compatível com schema sem coluna role em room_invites)
try {
    $st = $pdo->prepare("
        SELECT r.*,
         (SELECT COUNT(*) FROM room_invites i WHERE i.room_id=r.id) invited,
         (SELECT COUNT(*) FROM room_presence p WHERE p.room_id=r.id AND p.last_seen_at>=DATE_SUB(NOW(), INTERVAL 20 SECOND)) online,
         (SELECT token FROM room_invites i WHERE i.room_id=r.id AND i.status='approved' AND i.email=? ORDER BY i.id ASC LIMIT 1) host_token
        FROM rooms r
        WHERE r.owner_user_id=?
        ORDER BY CASE WHEN r.status='open' THEN 1 WHEN r.status='scheduled' THEN 2 ELSE 3 END, COALESCE(r.starts_at, r.created_at) DESC
    ");
    $st->execute([$user['email'], $user['id']]);
    $rooms = $st->fetchAll();

    $stats = $pdo->prepare("
        SELECT
         COUNT(*) total_rooms,
         SUM(status='open') open_rooms,
         SUM(status='scheduled') scheduled_rooms
        FROM rooms WHERE owner_user_id=?
    ");
    $stats->execute([$user['id']]);
    $my = $stats->fetch();
} catch (Throwable $e) {
    http_response_code(500);
    die('<div style="font-family: sans-serif; padding: 30px; background: #0b1120; color: #f1f5f9; min-height: 100vh;">'
        . '<h2 style="color: #ef4444;">Erro ao carregar salas de reunião</h2>'
        . '<p style="color: #94a3b8;">Ocorreu uma falha na consulta ao banco de dados:</p>'
        . '<pre style="background: #1e293b; padding: 15px; border-radius: 8px; color: #fca5a5;">' . htmlspecialchars($e->getMessage()) . '</pre>'
        . '<p><a href="../index.html" style="color: #38bdf8;">&larr; Voltar ao Portal</a></p>'
        . '</div>');
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Central de Videoconferências - Maurinsoft</title>
  <link rel="stylesheet" href="assets/css/salareuniao.css?v=20260919_3">
  <style>
    .sr-rooms-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(min(100%, 360px), 1fr));
      gap: 20px;
      margin-top: 20px;
    }
    .sr-room-card {
      background: var(--bg-card);
      border: 1px solid var(--border-glass);
      border-radius: var(--radius-md);
      padding: 22px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      gap: 16px;
      transition: all 0.25s ease;
      position: relative;
    }
    .sr-room-card:hover {
      border-color: rgba(0, 210, 255, 0.4);
      transform: translateY(-2px);
      box-shadow: 0 12px 30px rgba(0, 0, 0, 0.4);
    }
    .sr-room-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 12px;
    }
    .sr-room-title {
      font-size: 1.15rem;
      font-weight: 700;
      color: #fff;
    }
    .sr-room-meta {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      font-size: 0.82rem;
      color: var(--text-muted);
      margin-top: 8px;
    }
    .sr-room-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      padding-top: 14px;
      border-top: 1px solid var(--border-glass);
    }
    .sr-hero {
      background: linear-gradient(135deg, rgba(13, 20, 36, 0.95), rgba(7, 11, 20, 0.95));
      border: 1px solid var(--border-glass);
      border-radius: var(--radius-lg);
      padding: 32px 28px;
      margin-bottom: 28px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 24px;
      flex-wrap: wrap;
    }
    .sr-hero-actions {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
    }

    .sr-room-card, .sr-room-header > *, .sr-hero > *, .sr-brand > *, .sr-user-pill { min-width: 0; }
    .sr-room-card, .sr-brand-title, .sr-user-pill { overflow-wrap: anywhere; }
    .sr-room-header { flex-wrap: wrap; }
    .sr-nav-links { flex-wrap: wrap; max-width: 100%; }
    .sr-brand { max-width: 100%; flex-wrap: wrap; }
    .sr-brand-logo, .sr-user-avatar { flex-shrink: 0; }
    .sr-section-heading { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
    .sr-section-heading h2 { flex: 1 1 240px; }
    .sr-btn { white-space: normal; text-align: center; max-width: 100%; }
    @media (max-width: 640px) {
      .sr-topbar { position: static; padding: 14px 12px; }
      .sr-container { padding: 20px 12px; }
      .sr-nav-links { width: 100%; gap: 8px; }
      .sr-nav-links .sr-btn { flex: 1 1 120px; min-height: 44px; }
      .sr-user-pill { width: 100%; order: -1; }
      .sr-brand-badge { display: none; }
      .sr-brand-title { font-size: 1.1rem; }
      .sr-hero { padding: 22px 16px; gap: 20px; }
      .sr-hero h1 { font-size: 1.5rem !important; }
      .sr-hero-actions, .sr-hero-actions form, .sr-hero-actions .sr-btn { width: 100%; }
      .sr-hero-actions .sr-btn { min-height: 48px; }
      .sr-stats-grid { grid-template-columns: 1fr; gap: 10px; }
      .sr-stat-card { padding: 14px 18px; }
      .sr-room-card { padding: 18px 14px; }
      .sr-room-actions .sr-btn { flex: 1 1 120px !important; min-height: 44px; }
      .sr-section-heading h2 { font-size: 1.25rem; }
      #sr-toast { max-width: calc(100% - 24px); white-space: normal; text-align: center; }
    }
  </style>
</head>
<body>

  <!-- Topbar -->
  <header class="sr-topbar">
    <div class="sr-topbar-inner">
      <div class="sr-brand">
        <div class="sr-brand-logo">M</div>
        <div>
          <div class="sr-brand-title">Maurinsoft <span style="font-weight: 400; color: var(--primary);">Sala Reunião</span></div>
        </div>
        <span class="sr-brand-badge">WebRTC Pro</span>
      </div>

      <div class="sr-nav-links">
        <a href="../index.html" class="sr-btn sr-btn-secondary sr-btn-sm">&larr; Portal Principal</a>
        <?php if (!empty($user['role']) && $user['role'] === 'admin'): ?>
          <a href="../restrita/admin/index.php" class="sr-btn sr-btn-secondary sr-btn-sm">Painel Restrito</a>
          <a href="admin.php" class="sr-btn sr-btn-secondary sr-btn-sm">Admin WebRTC</a>
        <?php endif; ?>
        <div class="sr-user-pill">
          <div class="sr-user-avatar"><?=strtoupper(substr($user['name'], 0, 1))?></div>
          <span><?=e($user['name'])?></span>
        </div>
        <a href="logout.php" class="sr-btn sr-btn-danger sr-btn-sm">Sair</a>
      </div>
    </div>
  </header>

  <!-- Container -->
  <main class="sr-container">

    <!-- Hero Banner -->
    <section class="sr-hero">
      <div>
        <h1 style="font-size: 1.85rem; margin-bottom: 8px;">Videoconferências Criptografadas em Tempo Real</h1>
        <p style="color: var(--text-muted); max-width: 650px;">
          Crie uma reunião e compartilhe o convite. O convidado não precisa de conta: informa o nome e aguarda sua autorização na sala de espera.
        </p>
      </div>
      <div class="sr-hero-actions">
        <form method="post" style="display: inline;">
          <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
          <input type="hidden" name="action" value="quick_meeting">
          <button type="submit" class="sr-btn sr-btn-primary sr-btn-lg">
            <span style="font-size: 1.2rem;">⚡</span> Iniciar Reunião Imediata
          </button>
        </form>
        <a href="room_create.php" class="sr-btn sr-btn-secondary sr-btn-lg">
          <span>📅</span> Agendar Reunião
        </a>
      </div>
    </section>

    <!-- KPI Stats Cards -->
    <section class="sr-stats-grid">
      <div class="sr-stat-card">
        <div class="sr-stat-label">Minhas Salas Criadas</div>
        <div class="sr-stat-value"><?=(int)($my['total_rooms'] ?? 0)?></div>
      </div>
      <div class="sr-stat-card">
        <div class="sr-stat-label">Abertas Agora</div>
        <div class="sr-stat-value" style="color: var(--accent-green);">
          <?=(int)($my['open_rooms'] ?? 0)?>
        </div>
      </div>
      <div class="sr-stat-card">
        <div class="sr-stat-label">Reuniões Agendadas</div>
        <div class="sr-stat-value" style="color: var(--accent-amber);">
          <?=(int)($my['scheduled_rooms'] ?? 0)?>
        </div>
      </div>
    </section>

    <!-- Rooms List Section -->
    <section>
      <div class="sr-section-heading">
        <h2>Suas Salas de Videoconferência</h2>
        <a href="room_create.php" class="sr-btn sr-btn-primary sr-btn-sm">+ Nova Sala</a>
      </div>

      <?php if (empty($rooms)): ?>
        <div class="sr-card" style="text-align: center; padding: 48px 24px;">
          <div style="font-size: 3rem; margin-bottom: 16px;">🎙️</div>
          <h3 style="margin-bottom: 8px;">Você ainda não possui salas criadas</h3>
          <p style="color: var(--text-muted); margin-bottom: 24px;">
            Inicie uma reunião agora mesmo ou agende uma videoconferência com seus clientes e parceiros.
          </p>
          <form method="post" style="display: inline;">
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
            <input type="hidden" name="action" value="quick_meeting">
            <button type="submit" class="sr-btn sr-btn-primary">Iniciar Primeira Reunião</button>
          </form>
        </div>
      <?php else: ?>
        <div class="sr-rooms-grid">
          <?php foreach ($rooms as $r): ?>
            <?php
              $isOpen = ($r['status'] === 'open');
              $isScheduled = ($r['status'] === 'scheduled');
              $badgeClass = $isOpen ? 'sr-badge-open' : ($isScheduled ? 'sr-badge-scheduled' : 'sr-badge-closed');
              $statusLabel = $isOpen ? 'Aberta Agora' : ($isScheduled ? 'Agendada' : 'Encerrada');
              $hostToken = $r['host_token'] ?? '';
              $enterUrl = $hostToken ? 'room.php?token=' . urlencode($hostToken) : 'room_manage.php?id=' . (int)$r['id'];
              $inviteLink = rtrim((string)$config['app']['base_url'], '/') . '/join.php?room_id=' . (int)$r['id'];
            ?>
            <div class="sr-room-card">
              <div>
                <div class="sr-room-header">
                  <div class="sr-room-title"><?=e($r['name'])?></div>
                  <span class="sr-badge <?=$badgeClass?>">
                    <?php if ($isOpen): ?><span class="sr-pulse-dot"></span><?php endif; ?>
                    <?=$statusLabel?>
                  </span>
                </div>

                <?php if (!empty($r['description'])): ?>
                  <p style="font-size: 0.88rem; color: var(--text-muted); margin-top: 8px;">
                    <?=e($r['description'])?>
                  </p>
                <?php endif; ?>

                <div class="sr-room-meta">
                  <div>
                    📅 <?=!empty($r['starts_at']) ? date('d/m/Y H:i', strtotime($r['starts_at'])) : 'Sem data fixa'?>
                  </div>
                  <div>
                    👥 <strong><?=(int)$r['online']?></strong> online
                  </div>
                  <div>
                    ✉️ <strong><?=(int)$r['invited']?></strong> convidados
                  </div>
                </div>
              </div>

              <div class="sr-room-actions">
                <?php if ($isOpen && $hostToken): ?>
                  <a href="<?=e($enterUrl)?>" class="sr-btn sr-btn-primary sr-btn-sm" style="flex: 1;">
                    🚀 Entrar na Sala
                  </a>
                <?php else: ?>
                  <a href="room_manage.php?id=<?=(int)$r['id']?>" class="sr-btn sr-btn-success sr-btn-sm" style="flex: 1;">
                    ⚙️ Gerenciar / Abrir
                  </a>
                <?php endif; ?>

                <a href="room_manage.php?id=<?=(int)$r['id']?>" class="sr-btn sr-btn-secondary sr-btn-sm" title="Gerenciar convites e configurações">
                  Gerenciar
                </a>

                <?php if ($isOpen || $isScheduled): ?>
                  <button type="button" class="sr-btn sr-btn-secondary sr-btn-sm" data-invite-url="<?=e($inviteLink)?>" onclick="copyInvite(this.dataset.inviteUrl)" title="Copiar Link de Convite">
                    📋 Copiar convite
                  </button>
                  <a href="https://api.whatsapp.com/send?text=<?=urlencode('Convite para videoconferência Maurinsoft (' . $r['name'] . '): ' . $inviteLink)?>" target="_blank" class="sr-btn sr-btn-secondary sr-btn-sm" title="Enviar no WhatsApp">
                    💬 WhatsApp
                  </a>
                <?php endif; ?>

                <a href="room_history.php?id=<?=(int)$r['id']?>" class="sr-btn sr-btn-secondary sr-btn-sm" title="Histórico de presenças">
                  Histórico
                </a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

  </main>

  <!-- Toast Notification -->
  <div id="sr-toast">📋 Link de convite copiado com sucesso!</div>

  <script>
    function copyInvite(url) {
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(url).then(showToast);
      } else {
        const input = document.createElement('input');
        input.value = url;
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        document.body.removeChild(input);
        showToast();
      }
    }

    function showToast() {
      const toast = document.getElementById('sr-toast');
      toast.classList.add('show');
      setTimeout(() => toast.classList.remove('show'), 3000);
    }
  </script>
</body>
</html>
