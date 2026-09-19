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

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$token = trim((string)($input['token'] ?? ''));

if ($token === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'token_required']);
    exit;
}

$st = $pdo->prepare("SELECT i.* FROM room_invites i JOIN rooms r ON r.id=i.room_id WHERE i.token=? AND i.status='approved' AND r.status='open' LIMIT 1");
$st->execute([$token]);
$me = $st->fetch();

if (!$me || empty($me['participant_key'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden_or_inactive_room']);
    exit;
}

$type = trim((string)($input['type'] ?? ''));
if (!in_array($type, ['offer', 'answer', 'ice', 'leave', 'peer-ready', 'mute-state'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_signal_type']);
    exit;
}

$recipient = !empty($input['recipient']) ? trim((string)$input['recipient']) : null;
$payload = json_encode($input['payload'] ?? new stdClass(), JSON_UNESCAPED_SLASHES);

$q = $pdo->prepare('INSERT INTO signaling_messages(room_id, sender_key, recipient_key, message_type, payload) VALUES (?, ?, ?, ?, ?)');
$q->execute([$me['room_id'], $me['participant_key'], $recipient, $type, $payload]);

echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
