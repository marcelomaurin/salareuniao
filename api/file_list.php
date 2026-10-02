<?php
require __DIR__ . '/../lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_token']);
    exit;
}

$st = $pdo->prepare("SELECT i.*, r.status room_status FROM room_invites i JOIN rooms r ON r.id=i.room_id WHERE i.token=? AND i.status='approved' LIMIT 1");
$st->execute([$token]);
$me = $st->fetch();

if (!$me) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$roomId = (int)$me['room_id'];
$roomStorageDir = get_room_storage_dir($roomId);

$q = $pdo->prepare("
    SELECT id, participant_key, display_name, original_name, stored_name, file_size, mime_type, created_at
    FROM room_files
    WHERE room_id = ?
    ORDER BY id DESC
    LIMIT 200
");
$q->execute([$roomId]);
$files = $q->fetchAll();

$list = [];
foreach ($files as $f) {
    $exists = file_exists($roomStorageDir . '/' . $f['stored_name']);
    $list[] = [
        'id' => (int)$f['id'],
        'original_name' => $f['original_name'],
        'file_size' => (int)$f['file_size'],
        'mime_type' => $f['mime_type'],
        'display_name' => $f['display_name'],
        'created_at' => $f['created_at'],
        'is_self' => ($f['participant_key'] === $me['participant_key']),
        'available_on_disk' => $exists,
        'download_url' => 'api/file_download.php?token=' . urlencode($token) . '&id=' . $f['id']
    ];
}

echo json_encode([
    'ok' => true,
    'room_id' => $roomId,
    'count' => count($list),
    'files' => $list
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
