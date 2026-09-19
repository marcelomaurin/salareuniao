<?php
require __DIR__ . '/../lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') {
    echo json_encode(['ok' => true, 'service' => 'Maurinsoft Chat History API', 'status' => 'online']);
    exit;
}

$limit = min(200, max(1, (int)($_GET['limit'] ?? 100)));
$st = $pdo->prepare("SELECT i.* FROM room_invites i WHERE i.token=? AND i.status='approved' LIMIT 1");
$st->execute([$token]);
$me = $st->fetch();

if (!$me || empty($me['participant_key'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$q = $pdo->prepare("SELECT id, participant_key, display_name, message, created_at FROM room_messages WHERE room_id=? ORDER BY id DESC LIMIT {$limit}");
$q->execute([$me['room_id']]);
$rows = array_reverse($q->fetchAll());

echo json_encode([
    'ok' => true,
    'self' => $me['participant_key'],
    'messages' => $rows
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
