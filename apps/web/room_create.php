<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/invitations.php';
$user = require_login();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $starts = trim($_POST['starts_at'] ?? '') ?: null;
    $status = (!empty($_POST['start_now'])) ? 'open' : 'scheduled';
    $emails = preg_split('/[\s,;]+/', trim($_POST['emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);

    if ($name === '') {
        $error = 'Por favor, informe o nome da sala de videoconferência.';
    } else {
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('INSERT INTO rooms (owner_user_id, name, description, starts_at, status) VALUES (?, ?, ?, ?, ?)');
            $st->execute([$user['id'], $name, $description, $starts, $status]);
            $roomId = (int)$pdo->lastInsertId();

            // O criador da sala recebe automaticamente uma identidade de anfitrião (Host) aprovada
            $hostToken = bin2hex(random_bytes(32));
            $hostKey = bin2hex(random_bytes(32));
            $host = $pdo->prepare("INSERT INTO room_invites (room_id, email, token, status, display_name, participant_key, requested_at, approved_at)
                                  VALUES (?, ?, ?, 'approved', ?, ?, NOW(), NOW())");
            $host->execute([$roomId, strtolower($user['email']), $hostToken, $user['name'], $hostKey]);

            $inviteStmt = $pdo->prepare('INSERT INTO room_invites (room_id, email, token, status) VALUES (?, ?, ?, "invited")');
            foreach (array_unique($emails) as $email) {
                $email = strtolower(trim($email));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $email === strtolower($user['email'])) continue;
                $token = bin2hex(random_bytes(32));
                $inviteStmt->execute([$roomId, $email, $token]);
                send_room_invite_mail($config, [
                    'name' => $name,
                    'description' => $description,
                    'starts_at' => $starts,
                ], $email, $token);
            }
            $pdo->commit();
            audit_log('room.create', 'room', $roomId, ['name' => $name, 'starts_at' => $starts]);

            if ($status === 'open') {
                header('Location: room.php?token=' . urlencode($hostToken));
            } else {
                header('Location: room_manage.php?id=' . $roomId);
            }
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Não foi possível criar a sala de reunião. Erro: ' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Criar Nova Sala - Maurinsoft Reunião</title>
  <link rel="stylesheet" href="assets/css/salareuniao.css">
  <style>
    .sr-form-card {
      max-width: 680px;
      margin: 30px auto;
      background: var(--bg-card);
      border: 1px solid var(--border-glass);
      border-radius: var(--radius-lg);
      padding: 32px;
      box-shadow: var(--shadow-md);
    }
  </style>
</head>
<body>

  <!-- Topbar -->
  <header class="sr-topbar">
    <div class="sr-topbar-inner">
      <div class="sr-brand">
        <div class="sr-brand-logo">M</div>
        <div class="sr-brand-title">Maurinsoft <span style="font-weight: 400; color: var(--primary);">Sala Reunião</span></div>
      </div>
      <div class="sr-nav-links">
        <a href="index.php" class="sr-btn sr-btn-secondary sr-btn-sm">&larr; Voltar para o Painel</a>
      </div>
    </div>
  </header>

  <main class="sr-container">
    <div class="sr-form-card">
      <div style="margin-bottom: 24px;">
        <h1 style="font-size: 1.7rem; margin-bottom: 6px;">Agendar ou Iniciar Reunião</h1>
        <p style="color: var(--text-muted); font-size: 0.9rem;">
          Configure os dados da sala, data/hora prevista e convide os participantes por e-mail ou link direto.
        </p>
      </div>

      <?php if ($error): ?>
        <div class="sr-alert sr-alert-error"><?=e($error)?></div>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">

        <div class="sr-form-group">
          <label class="sr-label" for="name">Nome da Reunião *</label>
          <input type="text" id="name" name="name" class="sr-input" placeholder="Ex: Alinhamento de Engenharia / Demonstração de Produto" required autofocus>
        </div>

        <div class="sr-form-group">
          <label class="sr-label" for="description">Pauta ou Descrição</label>
          <textarea id="description" name="description" class="sr-textarea" rows="3" placeholder="Tópicos da reunião, objetivos e orientações aos participantes"></textarea>
        </div>

        <div class="sr-form-group">
          <label class="sr-label" for="starts_at">Data e Hora de Início (opcional)</label>
          <input type="datetime-local" id="starts_at" name="starts_at" class="sr-input">
          <small style="color: var(--text-dim); display: block; margin-top: 4px;">Deixe em branco se a sala for para uso contínuo ou imediato.</small>
        </div>

        <div class="sr-form-group">
          <label class="sr-label" for="emails">E-mails dos Convidados</label>
          <textarea id="emails" name="emails" class="sr-textarea" rows="4" placeholder="Insira os e-mails separados por vírgula, espaço ou ponto-e-vírgula. Ex: cliente@empresa.com, engenharia@maurinsoft.com.br"></textarea>
          <small style="color: var(--text-dim); display: block; margin-top: 4px;">Um convite formal com link de acesso exclusivo será enviado a cada destinatário.</small>
        </div>

        <div class="sr-form-group" style="display: flex; align-items: center; gap: 10px; background: rgba(0, 210, 255, 0.05); padding: 12px; border-radius: var(--radius-sm); border: 1px solid rgba(0, 210, 255, 0.2);">
          <input type="checkbox" id="start_now" name="start_now" value="1" style="width: 18px; height: 18px; cursor: pointer;">
          <label for="start_now" style="cursor: pointer; font-size: 0.9rem; color: #fff; margin: 0;">
            <strong>Abrir sala imediatamente</strong> (entrar diretamente após criar)
          </label>
        </div>

        <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 28px;">
          <a href="index.php" class="sr-btn sr-btn-secondary">Cancelar</a>
          <button type="submit" class="sr-btn sr-btn-primary sr-btn-lg">
            Criar Sala e Enviar Convites &rarr;
          </button>
        </div>
      </form>
    </div>
  </main>

</body>
</html>
