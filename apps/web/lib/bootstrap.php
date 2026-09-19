<?php
declare(strict_types=1);

$configFile = __DIR__ . '/../config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Crie salareuniao/config.php a partir de config.example.php');
}
$config = require $configFile;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name($config['app']['session_name'] ?? 'MAURINSOFTSESSID');
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    @session_start();
}

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $config['db']['host'],
    $config['db']['port'] ?? 3306,
    $config['db']['name'],
    $config['db']['charset'] ?? 'utf8mb4'
);

try {
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    die('<div style="font-family: sans-serif; padding: 30px; background: #0b1120; color: #f1f5f9; min-height: 100vh;">'
        . '<h2 style="color: #ef4444;">Erro de Conexão com o Banco de Dados (Sala Reunião)</h2>'
        . '<p style="color: #94a3b8;">Não foi possível conectar ao banco de dados MySQL (' . htmlspecialchars($config['db']['name']) . ').</p>'
        . '<pre style="background: #1e293b; padding: 15px; border-radius: 8px; color: #fca5a5;">' . htmlspecialchars($e->getMessage()) . '</pre>'
        . '<p style="color: #64748b; font-size: 13px;">Verifique as credenciais no arquivo <code>salareuniao/config.php</code> ou <code>restrita/config.local.php</code>.</p>'
        . '</div>');
}

// Auto-provisionamento de todas as tabelas essenciais do Sala Reunião
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('admin','user') NOT NULL DEFAULT 'user',
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS rooms (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            owner_user_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            description TEXT NULL,
            starts_at DATETIME NULL,
            ends_at DATETIME NULL,
            status ENUM('scheduled','open','closed','cancelled') NOT NULL DEFAULT 'scheduled',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_rooms_owner (owner_user_id),
            INDEX idx_rooms_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS room_invites (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            room_id BIGINT UNSIGNED NOT NULL,
            email VARCHAR(190) NOT NULL,
            token CHAR(64) NOT NULL UNIQUE,
            status ENUM('invited','waiting','approved','rejected','left') NOT NULL DEFAULT 'invited',
            display_name VARCHAR(120) NULL,
            participant_key CHAR(64) NULL,
            requested_at DATETIME NULL,
            approved_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_invite_room_status (room_id, status),
            INDEX idx_invite_participant (room_id, participant_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS room_presence (
            room_id BIGINT UNSIGNED NOT NULL,
            participant_key CHAR(64) NOT NULL,
            display_name VARCHAR(120) NOT NULL,
            ip_address VARCHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            last_seen_at DATETIME NOT NULL,
            PRIMARY KEY (room_id, participant_key),
            INDEX idx_presence_room (room_id, last_seen_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS room_attendance (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            room_id BIGINT UNSIGNED NOT NULL,
            participant_key CHAR(64) NOT NULL,
            display_name VARCHAR(120) NOT NULL,
            joined_at DATETIME NOT NULL,
            left_at DATETIME NULL,
            duration_seconds INT UNSIGNED NULL,
            INDEX idx_attendance_room (room_id, joined_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS room_messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            room_id BIGINT UNSIGNED NOT NULL,
            participant_key CHAR(64) NOT NULL,
            display_name VARCHAR(120) NOT NULL,
            message TEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_messages_room (room_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS signaling_messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            room_id BIGINT UNSIGNED NOT NULL,
            from_key CHAR(64) NOT NULL,
            to_key CHAR(64) NOT NULL,
            type VARCHAR(32) NOT NULL,
            payload MEDIUMTEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_signaling_to (room_id, to_key, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS audit_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NULL,
            action VARCHAR(120) NOT NULL,
            target_type VARCHAR(80) NULL,
            target_id VARCHAR(120) NULL,
            details JSON NULL,
            ip_address VARCHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_audit_user (user_id),
            INDEX idx_audit_action (action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Throwable $e) {
    error_log('SalaReuniao auto-provisioning notice: ' . $e->getMessage());
}

function current_user(): ?array {
    global $pdo;
    
    // 1. Tenta sessao do Sala Reuniao
    if (!empty($_SESSION['user_id'])) {
        try {
            $st = $pdo->prepare('SELECT id,name,email,role,active FROM users WHERE id=? LIMIT 1');
            $st->execute([$_SESSION['user_id']]);
            $u = $st->fetch();
            if ($u && $u['active']) return $u;
        } catch (Throwable $e) {}
    }

    // 2. Ponte de SSO: Se logado na Area Restrita da Maurinsoft ($_SESSION['usuario_id'])
    if (!empty($_SESSION['usuario_id'])) {
        try {
            $uRestrita = null;
            // Tenta buscar no schema usuarios
            $st = $pdo->prepare('SELECT * FROM usuarios WHERE id=? LIMIT 1');
            $st->execute([$_SESSION['usuario_id']]);
            $uRestrita = $st->fetch();
            
            if ($uRestrita && (!isset($uRestrita['ativo']) || (int)$uRestrita['ativo'] === 1)) {
                $email = strtolower(trim((string)($uRestrita['email'] ?? '')));
                $nome = trim((string)($uRestrita['nome'] ?? 'Usuário Maurinsoft'));
                if ($email === '') {
                    $email = strtolower((string)($uRestrita['login'] ?? 'user')) . '@maurinsoft.com.br';
                }

                $papel = strtolower((string)($uRestrita['papel'] ?? $uRestrita['perfil'] ?? 'usuario'));
                $isAdmin = ($papel === 'admin' || $email === 'marcelomaurinmartins@gmail.com' || ($uRestrita['login'] ?? '') === 'admin');
                $role = $isAdmin ? 'admin' : 'user';

                // Localiza ou cria em users
                $st = $pdo->prepare('SELECT id,name,email,role,active FROM users WHERE LOWER(email)=? LIMIT 1');
                $st->execute([$email]);
                $uExistente = $st->fetch();

                if (!$uExistente) {
                    $st = $pdo->prepare('INSERT INTO users(name,email,password_hash,role,active) VALUES(?,?,?,?,1)');
                    $st->execute([$nome, $email, (string)($uRestrita['senha_hash'] ?? $uRestrita['senha'] ?? 'sso_auth'), $role]);
                    $novoId = (int)$pdo->lastInsertId();
                    $uExistente = ['id' => $novoId, 'name' => $nome, 'email' => $email, 'role' => $role, 'active' => 1];
                } else if ($isAdmin && $uExistente['role'] !== 'admin') {
                    $pdo->prepare('UPDATE users SET role="admin" WHERE id=?')->execute([$uExistente['id']]);
                    $uExistente['role'] = 'admin';
                }

                $_SESSION['user_id'] = (int)$uExistente['id'];
                $_SESSION['user_name'] = $uExistente['name'];
                $_SESSION['role'] = $uExistente['role'];
                return $uExistente;
            }
        } catch (Throwable $e) {}
    }

    return null;
}

function require_login(): array {
    $u = current_user();
    if (!$u) {
        header('Location: login.php');
        exit;
    }
    return $u;
}

function require_admin(): array {
    $u = require_login();
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        exit('Acesso restrito ao administrador.');
    }
    return $u;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function verify_csrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419);
        exit('CSRF inválido.');
    }
}

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function audit_log(string $action, ?string $targetType=null, $targetId=null, array $details=[]): void {
    global $pdo;
    try {
        $u=current_user();
        $userId=$u['id']??null;
        $ip=substr((string)($_SERVER['REMOTE_ADDR']??''),0,64);
        $ua=substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,255);
        $json=$details ? json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null;
        $st=$pdo->prepare('INSERT INTO audit_log(user_id,action,target_type,target_id,details,ip_address,user_agent) VALUES(?,?,?,?,?,?,?)');
        $st->execute([
            $userId,
            substr($action,0,120),
            $targetType!==null?substr($targetType,0,80):null,
            $targetId!==null?substr((string)$targetId,0,120):null,
            $json,
            $ip?:null,
            $ua?:null,
        ]);
    } catch (Throwable $e) {
        error_log('SalaReuniao audit error: '.$e->getMessage());
    }
}
