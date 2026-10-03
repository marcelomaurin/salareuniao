<?php
require __DIR__ . '/../lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

// Se acessado diretamente via GET (pelo navegador ou health check)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'ok' => true,
        'service' => 'Maurinsoft WebRTC Signaling API',
        'status' => 'online',
        'usage' => 'Envie requisicoes POST com {token, type, recipient, payload}'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        $input = [];
    }

    $token = trim((string)($input['token'] ?? ''));

    if ($token === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'token_required']);
        exit;
    }

    $st = $pdo->prepare("SELECT i.*, r.status as room_status FROM room_invites i JOIN rooms r ON r.id=i.room_id WHERE i.token=? LIMIT 1");
    $st->execute([$token]);
    $me = $st->fetch();

    if (!$me || empty($me['participant_key'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden_or_inactive_room']);
        exit;
    }

    if ($me['status'] !== 'approved' || $me['room_status'] !== 'open') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'room_not_open_or_unapproved']);
        exit;
    }

    $type = trim((string)($input['type'] ?? ''));
    if (!in_array($type, ['offer', 'answer', 'ice', 'leave', 'peer-ready', 'mute-state', 'mute-user'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_signal_type']);
        exit;
    }

    $recipient = !empty($input['recipient']) ? trim((string)$input['recipient']) : null;
    $payloadData = $input['payload'] ?? [];
    if ($type === 'mute-user') {
        if (is_array($payloadData)) $payloadData['action'] = 'mute-user';
    }
    $dbType = in_array($type, ['offer', 'answer', 'ice', 'leave', 'peer-ready'], true) ? $type : 'peer-ready';
    $payload = json_encode($payloadData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        $payload = '{}';
    }

    try {
        $q = $pdo->prepare('INSERT INTO signaling_messages(room_id, sender_key, recipient_key, message_type, payload) VALUES (?, ?, ?, ?, ?)');
        $q->execute([$me['room_id'], $me['participant_key'], $recipient, $dbType, $payload]);
        echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
        exit;
    } catch (Throwable $dbErr) {
        // Auto-reparo imediato da tabela de sinalização
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS signaling_messages (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    room_id BIGINT UNSIGNED NOT NULL,
                    sender_key VARCHAR(120) NOT NULL,
                    recipient_key VARCHAR(120) NULL,
                    message_type VARCHAR(64) NOT NULL,
                    payload LONGTEXT NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_signaling_to (room_id, recipient_key, id),
                    INDEX idx_signaling_sender (room_id, sender_key)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            try { $pdo->exec("ALTER TABLE signaling_messages MODIFY COLUMN payload LONGTEXT NOT NULL"); } catch (Throwable $e2) {}
            try { $pdo->exec("ALTER TABLE signaling_messages MODIFY COLUMN message_type VARCHAR(64) NOT NULL"); } catch (Throwable $e2) {}
            try { $pdo->exec("ALTER TABLE signaling_messages MODIFY COLUMN sender_key VARCHAR(120) NOT NULL"); } catch (Throwable $e2) {}
            try { $pdo->exec("ALTER TABLE signaling_messages MODIFY COLUMN recipient_key VARCHAR(120) NULL"); } catch (Throwable $e2) {}

            $q = $pdo->prepare('INSERT INTO signaling_messages(room_id, sender_key, recipient_key, message_type, payload) VALUES (?, ?, ?, ?, ?)');
            $q->execute([$me['room_id'], $me['participant_key'], $recipient, $dbType, $payload]);
            echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
            exit;
        } catch (Throwable $retryErr) {
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'error' => 'db_error',
                'message' => $retryErr->getMessage()
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
} catch (Throwable $fatal) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'internal_error',
        'message' => $fatal->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
