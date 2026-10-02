<?php
require __DIR__ . '/../lib/bootstrap.php';

$token = trim((string)($_GET['token'] ?? ''));
$fileId = (int)($_GET['id'] ?? 0);

if ($token === '' || $fileId <= 0) {
    http_response_code(400);
    exit('Requisição inválida.');
}

$st = $pdo->prepare("SELECT i.*, r.status room_status FROM room_invites i JOIN rooms r ON r.id=i.room_id WHERE i.token=? AND i.status='approved' LIMIT 1");
$st->execute([$token]);
$me = $st->fetch();

if (!$me) {
    http_response_code(403);
    exit('Acesso não autorizado.');
}

$fSt = $pdo->prepare("SELECT * FROM room_files WHERE id=? AND room_id=? LIMIT 1");
$fSt->execute([$fileId, $me['room_id']]);
$file = $fSt->fetch();

if (!$file) {
    http_response_code(404);
    exit('Arquivo não encontrado.');
}

$roomStorageDir = get_room_storage_dir((int)$file['room_id']);
$filePath = $roomStorageDir . '/' . $file['stored_name'];

if (!file_exists($filePath)) {
    http_response_code(404);
    exit('O arquivo físico não foi encontrado no storage da sala.');
}

$fileSize = filesize($filePath);
$mimeType = $file['mime_type'] ?: 'application/octet-stream';
$downloadName = $file['original_name'];

// Limpa qualquer saída anterior
if (ob_get_level()) {
    ob_end_clean();
}

header('Content-Description: File Transfer');
header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . addslashes($downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');
header('Content-Length: ' . $fileSize);

readfile($filePath);
exit;
