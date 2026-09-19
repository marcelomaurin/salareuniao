<?php
require __DIR__ . '/../lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') {
    echo json_encode([
        'ok' => true,
        'service' => 'Maurinsoft WebRTC Signal Poll API',
        'status' => 'online',
        'info' => 'Passe o parametro ?token=SEU_TOKEN&after=0'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$after = max(0, (int)($_GET['after'] ?? 0));
$st = $pdo->prepare("SELECT i.* FROM room_invites i JOIN rooms r ON r.id=i.room_id WHERE i.token=? AND i.status='approved' AND r.status='open' LIMIT 1");
$st->execute([$token]);
$me = $st->fetch();

if (!$me || empty($me['participant_key'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden_or_inactive_room']);
    exit;
}

$q = $pdo->prepare("SELECT id, sender_key, recipient_key, message_type, payload, created_at 
                    FROM signaling_messages 
                    WHERE room_id=? AND id>? AND sender_key<>? AND (recipient_key IS NULL OR recipient_key=?) 
                    ORDER BY id ASC LIMIT 200");
$q->execute([$me['room_id'], $after, $me['participant_key'], $me['participant_key']]);

$messages = [];
foreach ($q->fetchAll() as $m) {
    $m['payload'] = json_decode($m['payload'], true);
    $messages[] = $m;
}

echo json_encode([
    'ok' => true,
    'self' => $me['participant_key'],
    'messages' => $messages
], JSON_UNESCAPED_SLASHES);
