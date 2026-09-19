<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
$user = require_login();
$id = (int)($_GET['id'] ?? 0);

if ($user['role'] === 'admin') {
    $st = $pdo->prepare('SELECT r.*, u.name owner_name FROM rooms r JOIN users u ON u.id=r.owner_user_id WHERE r.id=? LIMIT 1');
    $st->execute([$id]);
} else {
    $st = $pdo->prepare('SELECT r.*, u.name owner_name FROM rooms r JOIN users u ON u.id=r.owner_user_id WHERE r.id=? AND r.owner_user_id=? LIMIT 1');
    $st->execute([$id, $user['id']]);
}
$room = $st->fetch();
if (!$room) {
    http_response_code(404);
    exit('Sala não encontrada.');
}

$msg = $pdo->prepare('SELECT id, display_name, message, created_at FROM room_messages WHERE room_id=? ORDER BY id ASC');
$msg->execute([$id]);
$messages = $msg->fetchAll();

$participants = $pdo->prepare("
    SELECT i.id, i.display_name, i.email, i.status, i.requested_at, i.approved_at,
           COUNT(a.id) sessions,
           MIN(a.joined_at) first_join,
           MAX(COALESCE(a.left_at, NOW())) last_leave,
           COALESCE(SUM(COALESCE(a.duration_seconds, TIMESTAMPDIFF(SECOND, a.joined_at, NOW()))), 0) total_seconds
    FROM room_invites i
    LEFT JOIN room_attendance a ON a.room_id=i.room_id AND a.participant_key=i.participant_key
    WHERE i.room_id=?
    GROUP BY i.id, i.display_name, i.email, i.status, i.requested_at, i.approved_at
    ORDER BY i.created_at
");
$participants->execute([$id]);
$participants = $participants->fetchAll();

$sessions = $pdo->prepare("
    SELECT display_name, joined_at, left_at,
           COALESCE(duration_seconds, TIMESTAMPDIFF(SECOND, joined_at, NOW())) duration_seconds
    FROM room_attendance
    WHERE room_id=?
    ORDER BY joined_at DESC
");
$sessions->execute([$id]);
$sessions = $sessions->fetchAll();

function duration_text(int $seconds): string {
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return ($h > 0 ? $h . 'h ' : '') . $m . 'm ' . $s . 's';
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Histórico de Reunião - <?=e($room['name'])?></title>
  <link rel="stylesheet" href="assets/css/salareuniao.css">
  <style>
    .sr-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 12px;
      font-size: 0.9rem;
    }
    .sr-table th {
      background: rgba(255, 255, 255, 0.04);
      color: var(--text-muted);
      font-weight: 600;
      text-align: left;
      padding: 12px 14px;
      border-bottom: 1px solid var(--border-glass);
      font-size: 0.8rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }
    .sr-table td {
      padding: 12px 14px;
      border-bottom: 1px solid var(--border-glass);
      color: #e2e8f0;
    }
    .sr-table tr:hover td {
      background: rgba(255, 255, 255, 0.02);
    }
    .sr-msg-item {
      padding: 12px 14px;
      border-bottom: 1px solid var(--border-glass);
      border-radius: var(--radius-sm);
      margin-bottom: 8px;
      background: rgba(255, 255, 255, 0.03);
    }
  </style>
</head>
<body>

  <!-- Topbar -->
  <header class="sr-topbar">
    <div class="sr-topbar-inner">
      <div class="sr-brand">
        <div class="sr-brand-logo">M</div>
        <div class="sr-brand-title">Histórico de Presenças &bull; <span style="font-weight: 400; color: var(--primary);"><?=e($room['name'])?></span></div>
      </div>
      <div class="sr-nav-links">
        <a href="room_manage.php?id=<?=$id?>" class="sr-btn sr-btn-secondary sr-btn-sm">&larr; Gerenciar Sala</a>
        <a href="index.php" class="sr-btn sr-btn-secondary sr-btn-sm">Central de Salas</a>
      </div>
    </div>
  </header>

  <main class="sr-container">

    <!-- Header info card -->
    <div class="sr-card" style="margin-bottom: 24px;">
      <h1 style="font-size: 1.6rem; margin-bottom: 6px;"><?=e($room['name'])?></h1>
      <div style="font-size: 0.88rem; color: var(--text-muted); line-height: 1.8;">
        Responsável: <strong><?=e($room['owner_name'])?></strong> &bull;
        Status: <span class="sr-badge sr-badge-open" style="font-size: 10px;"><?=strtoupper(e($room['status']))?></span> &bull;
        Início: <?=!empty($room['starts_at']) ? date('d/m/Y H:i', strtotime($room['starts_at'])) : 'Sem data fixa'?> &bull;
        Término: <?=!empty($room['ends_at']) ? date('d/m/Y H:i', strtotime($room['ends_at'])) : 'Ativa/Indefinido'?>
      </div>
    </div>

    <!-- Participação Consolidada -->
    <div class="sr-card" style="margin-bottom: 24px; overflow-x: auto;">
      <h2 style="font-size: 1.25rem;">👥 Participação Consolidada</h2>
      <table class="sr-table">
        <thead>
          <tr>
            <th>Nome de Exibição</th>
            <th>E-mail</th>
            <th>Status</th>
            <th>Entradas</th>
            <th>Primeira Entrada</th>
            <th>Última Saída</th>
            <th>Tempo Total em Sala</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($participants)): ?>
            <tr><td colspan="7" style="text-align: center; color: var(--text-muted);">Nenhum participante registrado nesta sala.</td></tr>
          <?php else: ?>
            <?php foreach ($participants as $p): ?>
              <tr>
                <td><strong><?=e((string)($p['display_name'] ?: '-'))?></strong></td>
                <td><?=e($p['email'] ?: 'Link avulso')?></td>
                <td><span class="sr-badge sr-badge-open" style="font-size: 9px;"><?=e($p['status'])?></span></td>
                <td><?=(int)$p['sessions']?></td>
                <td><?=e((string)($p['first_join'] ? date('d/m/Y H:i', strtotime($p['first_join'])) : '-'))?></td>
                <td><?=e((string)($p['last_leave'] ? date('d/m/Y H:i', strtotime($p['last_leave'])) : '-'))?></td>
                <td><strong style="color: var(--primary);"><?=e(duration_text((int)$p['total_seconds']))?></strong></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Sessões de Conexão Detalhadas -->
    <div class="sr-card" style="margin-bottom: 24px; overflow-x: auto;">
      <h2 style="font-size: 1.25rem;">📡 Sessões e Conexões Individuais</h2>
      <table class="sr-table">
        <thead>
          <tr>
            <th>Participante</th>
            <th>Horário de Entrada</th>
            <th>Horário de Saída</th>
            <th>Duração da Sessão</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($sessions)): ?>
            <tr><td colspan="4" style="text-align: center; color: var(--text-muted);">Nenhuma conexão detalhada registrada até o momento.</td></tr>
          <?php else: ?>
            <?php foreach ($sessions as $s): ?>
              <tr>
                <td><?=e($s['display_name'])?></td>
                <td><?=e(date('d/m/Y H:i:s', strtotime($s['joined_at'])))?></td>
                <td><?=e((string)($s['left_at'] ? date('d/m/Y H:i:s', strtotime($s['left_at'])) : 'Online / Sessão ativa'))?></td>
                <td><?=e(duration_text((int)$s['duration_seconds']))?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Histórico de Mensagens de Chat -->
    <div class="sr-card">
      <h2 style="font-size: 1.25rem; margin-bottom: 12px;">💬 Histórico do Bate-Papo (<?=count($messages)?> mensagens)</h2>
      <?php if (empty($messages)): ?>
        <p style="color: var(--text-muted); font-size: 0.9rem;">Nenhuma mensagem de chat foi trocada durante esta sessão.</p>
      <?php else: ?>
        <div style="max-height: 400px; overflow-y: auto;">
          <?php foreach ($messages as $m): ?>
            <div class="sr-msg-item">
              <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 4px;">
                <strong style="color: var(--primary);"><?=e($m['display_name'])?></strong> &bull; <?=e(date('d/m/Y H:i:s', strtotime($m['created_at'])))?>
              </div>
              <div style="white-space: pre-wrap; word-break: break-word; color: #f1f5f9; font-size: 0.9rem;">
                <?=e($m['message'])?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

  </main>

</body>
</html>
