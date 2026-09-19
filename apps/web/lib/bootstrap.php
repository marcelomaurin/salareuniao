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
        . '<h2 style="color: #ef4444;">Erro de Conexão com o Banco de Dados</h2>'
        . '<p>Não foi possível conectar ao MySQL para a Sala de Reunião: ' . htmlspecialchars($e->getMessage()) . '</p>'
        . '<p style="color: #94a3b8;">Verifique as credenciais em <code>salareuniao/config.php</code> ou <code>restrita/config.local.php</code>.</p>'
        . '</div>');
}

// Auto-provisioning de tabelas essenciais
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
            mic_enabled TINYINT(1) NOT NULL DEFAULT 1,
            cam_enabled TINYINT(1) NOT NULL DEFAULT 1,
            screen_sharing TINYINT(1) NOT NULL DEFAULT 0,
            joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
            sender_key CHAR(64) NOT NULL,
            recipient_key CHAR(64) NULL,
            message_type VARCHAR(32) NOT NULL,
            payload MEDIUMTEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_signaling_to (room_id, recipient_key, id),
            INDEX idx_signaling_sender (room_id, sender_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS devices (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            device_uid VARCHAR(120) NOT NULL UNIQUE,
            name VARCHAR(120) NOT NULL,
            type VARCHAR(60) NOT NULL DEFAULT 'webcam',
            api_key VARCHAR(120) NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            last_seen_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
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

        CREATE TABLE IF NOT EXISTS password_resets (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            token CHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            used TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_reset_token (token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Migrações seguras de colunas em signaling_messages
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM signaling_messages")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('from_key', $cols, true) && !in_array('sender_key', $cols, true)) {
            $pdo->exec("ALTER TABLE signaling_messages CHANGE COLUMN from_key sender_key CHAR(64) NOT NULL");
        }
        if (in_array('to_key', $cols, true) && !in_array('recipient_key', $cols, true)) {
            $pdo->exec("ALTER TABLE signaling_messages CHANGE COLUMN to_key recipient_key CHAR(64) NULL");
        }
        if (in_array('type', $cols, true) && !in_array('message_type', $cols, true)) {
            $pdo->exec("ALTER TABLE signaling_messages CHANGE COLUMN type message_type VARCHAR(32) NOT NULL");
        }
    } catch (Throwable $e) {}

    // Migrações seguras de colunas em room_presence
    try {
        $colsPresence = $pdo->query("SHOW COLUMNS FROM room_presence")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('mic_enabled', $colsPresence, true)) {
            $pdo->exec("ALTER TABLE room_presence ADD COLUMN mic_enabled TINYINT(1) NOT NULL DEFAULT 1");
        }
        if (!in_array('cam_enabled', $colsPresence, true)) {
            $pdo->exec("ALTER TABLE room_presence ADD COLUMN cam_enabled TINYINT(1) NOT NULL DEFAULT 1");
        }
        if (!in_array('screen_sharing', $colsPresence, true)) {
            $pdo->exec("ALTER TABLE room_presence ADD COLUMN screen_sharing TINYINT(1) NOT NULL DEFAULT 0");
        }
        if (!in_array('joined_at', $colsPresence, true)) {
            $pdo->exec("ALTER TABLE room_presence ADD COLUMN joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        }
    } catch (Throwable $e) {}

    // Cria usuário admin padrão se não houver usuários cadastrados
    try {
        $userCount = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($userCount === 0) {
            $defaultPass = password_hash('Maurinsoft@2026', PASSWORD_DEFAULT);
            $st = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, active) VALUES (?, ?, ?, 'admin', 1)");
            $st->execute(['Marcelo Maurin', 'marcelomaurinmartins@maurinsoft.com.br', $defaultPass]);
        }
    } catch (Throwable $e) {}

} catch (Throwable $e) {
    error_log('SalaReuniao auto-provisioning notice: ' . $e->getMessage());
}

function current_user(): ?array {
    global $pdo;
    
    // 1. Tenta sessão nativa do Sala Reunião
    if (!empty($_SESSION['user_id'])) {
        try {
            $st = $pdo->prepare('SELECT id,name,email,role,active FROM users WHERE id=? LIMIT 1');
            $st->execute([$_SESSION['user_id']]);
            $u = $st->fetch();
            if ($u && $u['active']) return $u;
        } catch (Throwable $e) {}
    }

    // 2. Ponte de SSO: Se logado na Área Restrita da Maurinsoft ($_SESSION['usuario_id'])
    if (!empty($_SESSION['usuario_id'])) {
        try {
            $st = $pdo->prepare('SELECT * FROM usuarios WHERE id=? LIMIT 1');
            $st->execute([$_SESSION['usuario_id']]);
            $uRestrita = $st->fetch();
            
            if ($uRestrita && !empty($uRestrita['email'])) {
                $st2 = $pdo->prepare('SELECT id,name,email,role,active FROM users WHERE email=? LIMIT 1');
                $st2->execute([$uRestrita['email']]);
                $uSala = $st2->fetch();
                if (!$uSala) {
                    $defPass = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                    $ins = $pdo->prepare("INSERT INTO users(name,email,password_hash,role,active) VALUES(?,?,?,'admin',1)");
                    $ins->execute([$uRestrita['nome'] ?? $uRestrita['email'], $uRestrita['email'], $defPass]);
                    $newId = (int)$pdo->lastInsertId();
                    $_SESSION['user_id'] = $newId;
                    return ['id' => $newId, 'name' => $uRestrita['nome'] ?? $uRestrita['email'], 'email' => $uRestrita['email'], 'role' => 'admin', 'active' => 1];
                } else {
                    $_SESSION['user_id'] = (int)$uSala['id'];
                    return $uSala;
                }
            }
        } catch (Throwable $e) {}
    }

    return null;
}

function require_login(): array {
    $u = current_user();
    if (!$u) {
        $ret = urlencode($_SERVER['REQUEST_URI'] ?? 'index.php');
        header("Location: index.php?redirect={$ret}");
        exit;
    }
    return $u;
}

function require_admin(): array {
    $u = require_login();
    if (($u['role'] ?? '') !== 'admin') {
        http_response_code(403);
        die('<div style="font-family: sans-serif; padding: 40px; text-align: center; background: #0b1120; color: #f87171; min-height: 100vh;">'
            . '<h1>403 - Acesso Restrito</h1>'
            . '<p>Esta área requer privilégios de administrador do sistema.</p>'
            . '<a href="index.php" style="color: #60a5fa; text-decoration: underline;">Voltar ao Início</a>'
            . '</div>');
    }
    return $u;
}

function e(?string $v): string {
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function check_csrf(?string $token): bool {
    return !empty($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

function verify_csrf(): void {
    $token = $_POST['csrf'] ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!check_csrf($token)) {
        http_response_code(403);
        die('<div style="font-family: sans-serif; padding: 30px; background: #0b1120; color: #f87171; min-height: 100vh;">'
            . '<h2>Ação não autorizada (CSRF)</h2>'
            . '<p style="color: #cbd5e1;">A sessão expirou ou o token de segurança é inválido. Por favor, volte e tente novamente.</p>'
            . '<a href="javascript:history.back()" style="color: #60a5fa; text-decoration: underline;">Voltar</a>'
            . '</div>');
    }
}

function audit_log(string $action, ?string $targetType = null, $targetId = null, array $details = []): void {
    global $pdo;
    if (!$pdo) return;
    try {
        $u = current_user();
        $userId = $u['id'] ?? null;
        $json = !empty($details) ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $st = $pdo->prepare('INSERT INTO audit_log(user_id, action, target_type, target_id, details, ip_address, user_agent) VALUES(?,?,?,?,?,?,?)');
        $st->execute([
            $userId,
            substr($action, 0, 120),
            $targetType !== null ? substr($targetType, 0, 80) : null,
            $targetId !== null ? substr((string)$targetId, 0, 120) : null,
            $json,
            $ip ?: null,
            $ua ?: null,
        ]);
    } catch (Throwable $e) {
        error_log('audit_log error: ' . $e->getMessage());
    }
}

