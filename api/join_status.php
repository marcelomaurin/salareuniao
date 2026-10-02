<?php
require __DIR__ . '/../lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') {
    echo json_encode(['ok' => false, 'error' => 'token_required']);
    exit;
}

$st = $pdo->prepare("SELECT i.id, i.token, i.status, i.display_name, r.status as room_status, r.name as room_name 
                     FROM room_invites i 
                     JOIN rooms r ON r.id = i.room_id 
                     WHERE i.token = ? LIMIT 1");
$st->execute([$token]);
$invite = $st->fetch();

if (!$invite) {
    echo json_encode(['ok' => false, 'error' => 'invite_not_found']);
    exit;
}

echo json_encode([
    'ok' => true,
    'status' => $invite['status'], // 'waiting', 'approved', 'rejected', 'invited'
    'display_name' => $invite['display_name'],
    'room_status' => $invite['room_status'],
    'room_name' => $invite['room_name']
], JSON_UNESCAPED_UNICODE);
