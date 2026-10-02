<?php
require __DIR__ . '/lib/bootstrap.php';
$user = require_admin();

// Estatísticas seguras contra ausência de tabelas opcionais
$summary = [
    'active_users' => 0,
    'admins' => 0,
    'regular_users' => 0,
    'total_rooms' => 0,
    'open_rooms' => 0,
    'scheduled_rooms' => 0,
    'online_participants' => 0,
    'signaling_5m' => 0,
    'active_devices' => 0,
    'online_devices' => 0,
    'audit_24h' => 0
];

try {
    $res = $pdo->query("
        SELECT
            (SELECT COUNT(*) FROM users WHERE active=1) active_users,
            (SELECT COUNT(*) FROM users WHERE role='admin' AND active=1) admins,
            (SELECT COUNT(*) FROM users WHERE role='user' AND active=1) regular_users,
            (SELECT COUNT(*) FROM rooms) total_rooms,
            (SELECT COUNT(*) FROM rooms WHERE status='open') open_rooms,
            (SELECT COUNT(*) FROM rooms WHERE status='scheduled') scheduled_rooms,
            (SELECT COUNT(*) FROM room_presence WHERE last_seen_at>=DATE_SUB(NOW(), INTERVAL 20 SECOND)) online_participants,
            (SELECT COUNT(*) FROM signaling_messages WHERE created_at>=DATE_SUB(NOW(), INTERVAL 5 MINUTE)) signaling_5m
    ")->fetch();
    if ($res) {
        $summary = array_merge($summary, $res);
    }
} catch (Throwable $e) {}

try {
    $devs = $pdo->query("
        SELECT
            (SELECT COUNT(*) FROM devices WHERE active=1) active_devices,
            (SELECT COUNT(*) FROM devices WHERE active=1 AND last_seen_at>=DATE_SUB(NOW(), INTERVAL 90 SECOND)) online_devices
    ")->fetch();
    if ($devs) {
        $summary = array_merge($summary, $devs);
    }
} catch (Throwable $e) {}

try {
    $aud = $pdo->query("SELECT COUNT(*) FROM audit_log WHERE created_at>=DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
    $summary['audit_24h'] = (int)$aud;
} catch (Throwable $e) {}

$rooms = [];
try {
    $rooms = $pdo->query("
        SELECT r.*, u.name as owner_name,
            (SELECT COUNT(*) FROM room_invites i WHERE i.room_id=r.id) invited,
            (SELECT COUNT(*) FROM room_presence p WHERE p.room_id=r.id AND p.last_seen_at>=DATE_SUB(NOW(), INTERVAL 20 SECOND)) online,
            (SELECT COUNT(*) FROM signaling_messages s WHERE s.room_id=r.id AND s.created_at>=DATE_SUB(NOW(), INTERVAL 5 MINUTE)) signaling_5m
        FROM rooms r
        JOIN users u ON u.id=r.owner_user_id
        ORDER BY (r.status='open') DESC, COALESCE(r.starts_at, r.created_at) DESC
        LIMIT 100
    ")->fetchAll();
} catch (Throwable $e) {}

$recentAudit = [];
try {
    $recentAudit = $pdo->query("SELECT a.*, u.name as user_name FROM audit_log a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 10")->fetchAll();
} catch (Throwable $e) {}

$usage = [];
try {
    $usage = $pdo->query("
        SELECT u.id, u.name, u.email, u.role,
            COUNT(DISTINCT r.id) rooms_created,
            SUM(CASE WHEN r.status='open' THEN 1 ELSE 0 END) open_rooms,
            COALESCE(SUM((SELECT COUNT(*) FROM room_invites i WHERE i.room_id=r.id)), 0) invitations
        FROM users u
        LEFT JOIN rooms r ON r.owner_user_id=u.id
        GROUP BY u.id, u.name, u.email, u.role
        ORDER BY rooms_created DESC, u.name
        LIMIT 50
    ")->fetchAll();
} catch (Throwable $e) {}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Administração - Sala Reunião | Maurinsoft</title>
  <link rel="stylesheet" href="assets/css/salareuniao.css">
  <style>
    .sr-admin-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 16px;
      margin-bottom: 24px;
    }
    .sr-stat-card {
      background: var(--bg-card);
      border: 1px solid var(--border-glass);
      border-radius: var(--radius-md);
      padding: 18px;
      backdrop-filter: blur(12px);
    }
    .sr-stat-val {
      font-size: 2rem;
      font-weight: 700;
      color: var(--brand-primary);
      margin: 4px 0;
    }
    .sr-stat-desc {
      font-size: 0.8rem;
      color: var(--text-muted);
    }
    .sr-table-card {
      background: var(--bg-card);
      border: 1px solid var(--border-glass);
      border-radius: var(--radius-md);
      padding: 24px;
      margin-bottom: 24px;
      backdrop-filter: blur(12px);
      overflow-x: auto;
    }
  </style>
</head>
<body class="sr-body">
  <header class="sr-header">
    <div class="sr-header-inner">
      <div class="sr-brand">
        <div class="sr-brand-logo">M</div>
        <div class="sr-brand-text">
          <span>Sala Reunião</span>
          <span class="sr-brand-sub">Painel Administrativo</span>
        </div>
      </div>
      <nav class="sr-nav">
        <a href="index.php" class="sr-nav-link">Minhas Salas</a>
        <a href="room_create.php" class="sr-nav-link">Nova Sala</a>
        <a href="admin.php" class="sr-nav-link active">Admin Global</a>
        <div class="sr-user-pill">
          <div class="sr-user-avatar"><?= strtoupper(substr($user['name'] ?? 'A', 0, 1)) ?></div>
          <span class="sr-user-name"><?= e($user['name']) ?> (Admin)</span>
        </div>
        <a href="logout.php" class="sr-btn sr-btn-outline" style="padding: 6px 14px; font-size: 0.82rem;">Sair</a>
      </nav>
    </div>
  </header>

  <main class="sr-container" style="padding-top: 24px; padding-bottom: 60px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
      <div>
        <h1 style="font-size: 1.6rem; font-weight: 700; margin-bottom: 4px;">Visão Geral do Sistema</h1>
        <p style="color: var(--text-muted); font-size: 0.9rem;">Telemetria em tempo real, monitoramento de salas e auditoria WebRTC.</p>
      </div>
      <div style="display: flex; gap: 10px;">
        <a href="room_create.php" class="sr-btn sr-btn-primary">+ Criar Reunião</a>
        <a href="/restrita/admin/index.php" class="sr-btn sr-btn-outline">Painel Geral Maurinsoft</a>
      </div>
    </div>

    <!-- CARDS DE MÉTRICAS -->
    <div class="sr-admin-grid">
      <div class="sr-stat-card">
        <div class="sr-stat-desc">Participantes Online Agora</div>
        <div class="sr-stat-val"><?= (int)$summary['online_participants'] ?></div>
        <div class="sr-stat-desc">Heartbeat ativo (&lt; 20s)</div>
      </div>
      <div class="sr-stat-card">
        <div class="sr-stat-desc">Salas Abertas</div>
        <div class="sr-stat-val" style="color: var(--status-open);"><?= (int)$summary['open_rooms'] ?></div>
        <div class="sr-stat-desc">de <?= (int)$summary['total_rooms'] ?> salas totais</div>
      </div>
      <div class="sr-stat-card">
        <div class="sr-stat-desc">Sinalizações WebRTC (5 min)</div>
        <div class="sr-stat-val"><?= (int)$summary['signaling_5m'] ?></div>
        <div class="sr-stat-desc">Offers, answers e ICE candidates</div>
      </div>
      <div class="sr-stat-card">
        <div class="sr-stat-desc">Usuários Cadastrados</div>
        <div class="sr-stat-val"><?= (int)$summary['active_users'] ?></div>
        <div class="sr-stat-desc"><?= (int)$summary['admins'] ?> admins / <?= (int)$summary['regular_users'] ?> membros</div>
      </div>
    </div>

    <!-- TABELA DE SALAS ATIVAS -->
    <div class="sr-table-card">
      <h2 style="font-size: 1.15rem; font-weight: 600; margin-bottom: 16px;">Salas e Utilização Recente</h2>
      <table class="sr-table">
        <thead>
          <tr>
            <th>Nome da Sala</th>
            <th>Proprietário</th>
            <th>Status</th>
            <th>Online</th>
            <th>Convidados</th>
            <th>Sinalização (5m)</th>
            <th style="text-align: right;">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rooms)): ?>
            <tr><td colspan="7" style="text-align: center; color: var(--text-muted); padding: 24px;">Nenhuma sala cadastrada no momento.</td></tr>
          <?php else: ?>
            <?php foreach ($rooms as $r): ?>
              <tr>
                <td><strong><?= e($r['name']) ?></strong></td>
                <td><?= e($r['owner_name']) ?></td>
                <td>
                  <span class="sr-badge <?= $r['status'] === 'open' ? 'sr-badge-open' : 'sr-badge-closed' ?>">
                    <?= e($r['status']) ?>
                  </span>
                </td>
                <td><strong style="color: var(--brand-primary);"><?= (int)$r['online'] ?></strong></td>
                <td><?= (int)$r['invited'] ?></td>
                <td><?= (int)$r['signaling_5m'] ?></td>
                <td style="text-align: right;">
                  <a href="room_manage.php?id=<?= (int)$r['id'] ?>" class="sr-btn sr-btn-outline" style="padding: 4px 10px; font-size: 0.8rem;">Gerenciar</a>
                  <?php if ($r['status'] === 'open'): ?>
                    <a href="room.php?id=<?= (int)$r['id'] ?>" class="sr-btn sr-btn-primary" style="padding: 4px 10px; font-size: 0.8rem; margin-left: 6px;">Entrar</a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- USO POR USUÁRIO -->
    <div class="sr-table-card">
      <h2 style="font-size: 1.15rem; font-weight: 600; margin-bottom: 16px;">Uso por Usuário</h2>
      <table class="sr-table">
        <thead>
          <tr>
            <th>Usuário</th>
            <th>Email</th>
            <th>Perfil</th>
            <th>Salas Criadas</th>
            <th>Salas Abertas</th>
            <th>Convites Enviados</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($usage)): ?>
            <tr><td colspan="6" style="text-align: center; color: var(--text-muted); padding: 20px;">Sem dados de uso.</td></tr>
          <?php else: ?>
            <?php foreach ($usage as $u): ?>
              <tr>
                <td><strong><?= e($u['name']) ?></strong></td>
                <td style="color: var(--text-muted);"><?= e($u['email']) ?></td>
                <td><span class="sr-badge"><?= e($u['role']) ?></span></td>
                <td><?= (int)$u['rooms_created'] ?></td>
                <td><?= (int)$u['open_rooms'] ?></td>
                <td><?= (int)$u['invitations'] ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- AUDITORIA -->
    <div class="sr-table-card">
      <h2 style="font-size: 1.15rem; font-weight: 600; margin-bottom: 16px;">Registro de Atividades (Auditoria)</h2>
      <table class="sr-table">
        <thead>
          <tr>
            <th>Data/Hora</th>
            <th>Usuário</th>
            <th>Ação</th>
            <th>Alvo</th>
            <th>IP</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($recentAudit)): ?>
            <tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 20px;">Nenhum registro recente de auditoria.</td></tr>
          <?php else: ?>
            <?php foreach ($recentAudit as $a): ?>
              <tr>
                <td style="color: var(--text-muted); font-size: 0.85rem;"><?= e($a['created_at']) ?></td>
                <td><?= e($a['user_name'] ?: 'Sistema') ?></td>
                <td><code><?= e($a['action']) ?></code></td>
                <td><?= e((string)($a['target_type'] ?: '-')) ?><?= $a['target_id'] !== null ? ' #' . e((string)$a['target_id']) : '' ?></td>
                <td style="color: var(--text-muted); font-size: 0.85rem;"><?= e($a['ip_address'] ?? '-') ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </main>
</body>
</html>
