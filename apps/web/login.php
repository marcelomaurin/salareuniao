<?php
require __DIR__ . '/lib/bootstrap.php';
if (current_user()) { header('Location: index.php'); exit; }

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
        header('Location: index.php');
        exit;
    }
    $error = 'E-mail ou senha inválidos.';
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Entrar - Sala Reunião</title></head>
<body>
<h1>Sala Reunião</h1>
<?php if ($error): ?><p><?=e($error)?></p><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<label>E-mail <input type="email" name="email" required></label><br>
<label>Senha <input type="password" name="password" required></label><br>
<button type="submit">Entrar</button>
</form>
</body></html>
