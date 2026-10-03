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

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        try {
            $q = $pdo->prepare('INSERT INTO signaling_messages(room_id, sender_key, recipient_key, message_type, payload) VALUES (?, ?, ?, ?, ?)');
            $q->execute([$me['room_id'], $me['participant_key'], $recipient, $dbType, $payload]);
            echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
            exit;
        } catch (Throwable $dbErr) {
            if ($attempt === 1) {
                usleep(50000); // 50ms retry para contornar lock temporário
                continue;
            }
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'error' => 'db_error',
                'message' => $dbErr->getMessage()
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
