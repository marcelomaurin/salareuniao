<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

$u = current_user();
if ($u) {
    audit_log('auth.logout', 'user', (string)$u['id'], [
        'email' => $u['email'] ?? null,
        'name' => $u['name'] ?? null
    ]);
}

// Limpa todas as variáveis da sessão
$_SESSION = [];

// Invalida o cookie de sessão no cliente
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

@session_destroy();
@session_write_close();

header('Location: login.php?logged_out=1');
exit;
