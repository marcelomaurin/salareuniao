<?php
require __DIR__ . '/../lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['ok' => true, 'service' => 'Maurinsoft Room File Upload API', 'status' => 'online']);
    exit;
}

$token = trim((string)($_POST['token'] ?? $_GET['token'] ?? ''));
if ($token === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_token']);
    exit;
}

$st = $pdo->prepare("SELECT i.*, r.status room_status FROM room_invites i JOIN rooms r ON r.id=i.room_id WHERE i.token=? AND i.status='approved' LIMIT 1");
$st->execute([$token]);
$me = $st->fetch();

if (!$me || empty($me['participant_key'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

if ($me['room_status'] !== 'open') {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'room_closed']);
    exit;
}

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'no_file_uploaded']);
    exit;
}

$fileError = $_FILES['file']['error'];
if ($fileError !== UPLOAD_ERR_OK) {
    http_response_code(400);
    $errMap = [
        UPLOAD_ERR_INI_SIZE => 'file_exceeds_upload_max_filesize',
        UPLOAD_ERR_FORM_SIZE => 'file_exceeds_max_file_size',
        UPLOAD_ERR_PARTIAL => 'file_upload_partial',
        UPLOAD_ERR_NO_FILE => 'no_file_uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'missing_tmp_dir',
        UPLOAD_ERR_CANT_WRITE => 'failed_to_write_file',
        UPLOAD_ERR_EXTENSION => 'file_upload_stopped_by_extension',
    ];
    echo json_encode(['ok' => false, 'error' => $errMap[$fileError] ?? 'upload_error']);
    exit;
}

$fileSize = (int)$_FILES['file']['size'];
$maxSize = 100 * 1024 * 1024; // 100 MB
if ($fileSize > $maxSize) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'file_too_large']);
    exit;
}

$originalName = basename($_FILES['file']['name']);
if ($originalName === '') {
    $originalName = 'arquivo_' . date('Ymd_His');
}

// Cria dinamicamente o subdiretório storage/<room_id>/ para esta sala
$roomId = (int)$me['room_id'];
$roomStorageDir = get_room_storage_dir($roomId);

// Sanitiza o nome do arquivo para armazenamento seguro
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$cleanBase = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
if ($cleanBase === '') $cleanBase = 'arquivo';

// Impede execução de arquivos de script
$dangerExts = ['php', 'phtml', 'php3', 'php4', 'php5', 'phps', 'pl', 'py', 'cgi', 'sh', 'exe', 'bat', 'cmd', 'js', 'html', 'htm'];
$safeExt = in_array($ext, $dangerExts, true) ? $ext . '.bin' : ($ext ?: 'dat');

$storedName = time() . '_' . bin2hex(random_bytes(8)) . '_' . substr($cleanBase, 0, 60) . '.' . $safeExt;
$destPath = $roomStorageDir . '/' . $storedName;

if (!move_uploaded_file($_FILES['file']['tmp_name'], $destPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'save_failed']);
    exit;
}

$realSize = filesize($destPath);
$mimeType = mime_content_type($destPath) ?: 'application/octet-stream';
$displayName = trim((string)($me['display_name'] ?: $me['email']));

// Registra arquivo no banco de dados
$ins = $pdo->prepare("
    INSERT INTO room_files (room_id, participant_key, display_name, original_name, stored_name, file_size, mime_type, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
");
$ins->execute([$roomId, $me['participant_key'], $displayName, $originalName, $storedName, $realSize, $mimeType]);
$fileId = (int)$pdo->lastInsertId();

// Registra mensagem no chat para que todos os participantes vejam o arquivo compartilhado em tempo real
$fileMetaJson = json_encode([
    'id' => $fileId,
    'name' => $originalName,
    'size' => $realSize,
    'mime' => $mimeType,
    'display_name' => $displayName
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$chatMsg = '[FILE:' . $fileMetaJson . ']';
$qMsg = $pdo->prepare('INSERT INTO room_messages(room_id, participant_key, display_name, message, created_at) VALUES (?, ?, ?, ?, NOW())');
$qMsg->execute([$roomId, $me['participant_key'], $displayName, $chatMsg]);
$chatMsgId = (int)$pdo->lastInsertId();

audit_log('room.file_upload', 'room', $roomId, [
    'file_id' => $fileId,
    'filename' => $originalName,
    'size' => $realSize
]);

echo json_encode([
    'ok' => true,
    'file' => [
        'id' => $fileId,
        'original_name' => $originalName,
        'file_size' => $realSize,
        'mime_type' => $mimeType,
        'display_name' => $displayName,
        'created_at' => date('Y-m-d H:i:s'),
        'download_url' => 'api/file_download.php?token=' . urlencode($token) . '&id=' . $fileId
    ],
    'chat_message_id' => $chatMsgId
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
