<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

$redirect = trim((string)($_GET['redirect'] ?? ($_POST['redirect'] ?? '')));
if ($redirect !== '' && preg_match('#^https?://#i', $redirect)) {
    $redirect = '';
}

if (empty($_GET['logged_out']) && current_user()) {
    if ($redirect !== '') {
        header('Location: ' . $redirect);
        exit;
    }
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $st = $pdo->prepare('SELECT * FROM users WHERE email=? AND active=1 LIMIT 1');
    $st->execute([$email]);
    $u = $st->fetch();
    if ($u && password_verify($password, $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $u['id'];
        audit_log('auth.login', 'user', $u['id'], ['email' => $u['email']]);
        
        $target = ($redirect !== '') ? $redirect : 'index.php';
        header('Location: ' . $target);
        exit;
    }
    $error = 'E-mail ou senha incorretos.';
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Acesso à Sala de Reunião - Maurinsoft</title>
  <link rel="stylesheet" href="assets/css/salareuniao.css">
  <style>
    body {
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      padding: 24px;
    }
    .sr-login-card {
      background: var(--bg-card);
      backdrop-filter: blur(16px);
      border: 1px solid var(--border-glass);
      border-radius: var(--radius-lg);
      padding: 36px 32px;
      max-width: 440px;
      width: 100%;
      box-shadow: var(--shadow-md);
    }
  </style>
</head>
<body>

  <div class="sr-login-card">
    <div style="text-align: center; margin-bottom: 28px;">
      <div class="sr-brand-logo" style="margin: 0 auto 12px; width: 44px; height: 44px; font-size: 20px;">M</div>
      <h1 style="font-size: 1.5rem; margin-bottom: 4px;">Maurinsoft Reunião</h1>
      <p style="color: var(--text-muted); font-size: 0.88rem;">Plataforma Corporativa de Videoconferência</p>
    </div>

    <?php if ($error): ?>
      <div class="sr-alert sr-alert-error"><?=e($error)?></div>
    <?php elseif (!empty($redirect) && str_contains($redirect, 'room.php')): ?>
      <div class="sr-alert sr-alert-info" style="margin-bottom: 20px; font-size: 0.86rem; background: rgba(0, 210, 255, 0.1); border: 1px solid rgba(0, 210, 255, 0.3); color: #00d2ff; padding: 10px 14px; border-radius: 8px;">
        🔒 Faça login para entrar na sala de reunião.
      </div>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <?php if (!empty($redirect)): ?>
        <input type="hidden" name="redirect" value="<?=e($redirect)?>">
      <?php endif; ?>

      <div class="sr-form-group">
        <label class="sr-label" for="email">E-mail Cadastrado</label>
        <input type="email" id="email" name="email" class="sr-input" placeholder="seu.email@empresa.com" required autofocus>
      </div>

      <div class="sr-form-group">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
          <label class="sr-label" for="password" style="margin: 0;">Sua Senha</label>
          <a href="forgot_password.php" style="font-size: 0.78rem;">Esqueceu a senha?</a>
        </div>
        <input type="password" id="password" name="password" class="sr-input" placeholder="••••••••" required>
      </div>

      <button type="submit" class="sr-btn sr-btn-primary sr-btn-lg" style="width: 100%; margin-top: 10px;">
        Entrar na Sala &rarr;
      </button>
    </form>

    <div style="margin-top: 24px; padding-top: 18px; border-top: 1px solid var(--border-glass); text-align: center; font-size: 0.82rem; color: var(--text-dim);">
      <p style="margin-bottom: 8px;">Possui conta na Área Restrita? <a href="../restrita/login.php">Entrar com SSO</a></p>
      <a href="../index.html" style="color: var(--text-muted);">&larr; Voltar para o Portal Maurinsoft</a>
    </div>
  </div>

</body>
</html>
