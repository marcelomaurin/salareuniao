<?php
require __DIR__ . '/../lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') {
    echo json_encode(['ok' => true, 'service' => 'Maurinsoft Chat Poll API', 'status' => 'online']);
    exit;
}

$after = max(0, (int)($_GET['after'] ?? 0));
$st = $pdo->prepare("SELECT i.*, r.status room_status FROM room_invites i JOIN rooms r ON r.id=i.room_id WHERE i.token=? AND i.status='approved' LIMIT 1");
$st->execute([$token]);
$me = $st->fetch();

if (!$me || empty($me['participant_key'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$q = $pdo->prepare("SELECT id, participant_key, display_name, message, created_at FROM room_messages WHERE room_id=? AND id>? ORDER BY id ASC LIMIT 200");
$q->execute([$me['room_id'], $after]);

echo json_encode([
    'ok' => true,
    'room_status' => $me['room_status'],
    'self' => $me['participant_key'],
    'messages' => $q->fetchAll()
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
