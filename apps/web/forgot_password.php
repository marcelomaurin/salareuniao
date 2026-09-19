<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/invitations.php';

$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(trim($_POST['email'] ?? ''));
    if ($email === '') {
        $error = 'Informe o seu e-mail.';
    } else {
        $st = $pdo->prepare('SELECT * FROM users WHERE email=? AND active=1 LIMIT 1');
        $st->execute([$email]);
        $u = $st->fetch();
        if ($u) {
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('INSERT INTO password_resets(user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))')->execute([$u['id'], $token]);
            send_password_reset_mail($config, $u, $token);
            audit_log('auth.forgot_password', 'user', $u['id'], ['email' => $email]);
        }
        $msg = 'Se o e-mail estiver cadastrado em nosso sistema, as instruções para redefinição foram enviadas com sucesso.';
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Recuperar Senha - Maurinsoft Reunião</title>
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
    <div style="text-align: center; margin-bottom: 24px;">
      <div class="sr-brand-logo" style="margin: 0 auto 12px; width: 44px; height: 44px; font-size: 20px;">M</div>
      <h1 style="font-size: 1.5rem; margin-bottom: 4px;">Recuperar Acesso</h1>
      <p style="color: var(--text-muted); font-size: 0.88rem;">Informe seu e-mail para receber um link de redefinição de senha.</p>
    </div>

    <?php if ($msg): ?>
      <div class="sr-alert sr-alert-success"><?=e($msg)?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="sr-alert sr-alert-error"><?=e($error)?></div>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">

      <div class="sr-form-group">
        <label class="sr-label" for="email">E-mail Cadastrado</label>
        <input type="email" id="email" name="email" class="sr-input" placeholder="seu.email@empresa.com" required autofocus>
      </div>

      <button type="submit" class="sr-btn sr-btn-primary sr-btn-lg" style="width: 100%; margin-top: 10px;">
        Enviar Link de Redefinição &rarr;
      </button>
    </form>

    <div style="margin-top: 24px; padding-top: 18px; border-top: 1px solid var(--border-glass); text-align: center; font-size: 0.85rem;">
      <a href="login.php" style="color: var(--text-muted);">&larr; Voltar para o Login</a>
    </div>
  </div>

</body>
</html>
