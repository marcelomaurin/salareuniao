<?php
declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

try {
    $token = trim((string)($_GET['token'] ?? $_SERVER['HTTP_X_TOKEN'] ?? ''));
    if ($token === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'token_required']);
        exit;
    }

    $st = $pdo->prepare("SELECT i.id, i.room_id, i.status, i.participant_key, r.status as room_status 
                         FROM room_invites i 
                         JOIN rooms r ON r.id = i.room_id 
                         WHERE i.token = ? LIMIT 1");
    $st->execute([$token]);
    $me = $st->fetch();

    if (!$me || empty($me['participant_key']) || $me['status'] !== 'approved' || $me['room_status'] !== 'open') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden_or_inactive_room']);
        exit;
    }

    $roomId = (int)$me['room_id'];
    $participantKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$me['participant_key']);
    $seq = (int)($_GET['seq'] ?? $_SERVER['HTTP_X_CHUNK_SEQ'] ?? 0);
    $mime = trim((string)($_GET['mime'] ?? $_SERVER['HTTP_X_MIME_TYPE'] ?? 'video/webm;codecs=vp8,opus'));
    $isInit = !empty($_GET['init']) || (!empty($_SERVER['HTTP_X_IS_INIT']) && $_SERVER['HTTP_X_IS_INIT'] === '1') || ($seq === 0);

    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) === 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'empty_payload']);
        exit;
    }

    $relayDir = get_room_storage_dir($roomId) . '/relay/' . $participantKey;
    if (!is_dir($relayDir)) {
        @mkdir($relayDir, 0775, true);
    }

    // Se for o chunk inicial com o cabecalho EBML/Track do WebM, armazena como init.bin
    if ($isInit || !is_file($relayDir . '/init.bin') || $seq === 0) {
        file_put_contents($relayDir . '/init.bin', $raw, LOCK_EX);
    }

    // Grava o chunk de dados sequencial
    $chunkFile = $relayDir . '/chunk_' . $seq . '.bin';
    file_put_contents($chunkFile, $raw, LOCK_EX);

    // Grava o arquivo de metadados
    $meta = [
        'participant_key' => $participantKey,
        'mime' => $mime,
        'last_seq' => $seq,
        'updated_at' => microtime(true)
    ];
    file_put_contents($relayDir . '/meta.json', json_encode($meta, JSON_UNESCAPED_SLASHES), LOCK_EX);

    // Limpeza de buffer rotativo: mantem apenas os ultimos 8 chunks para economizar disco
    if ($seq > 8) {
        $cleanupBefore = $seq - 8;
        for ($i = max(0, $cleanupBefore - 4); $i <= $cleanupBefore; $i++) {
            $oldChunk = $relayDir . '/chunk_' . $i . '.bin';
            if (is_file($oldChunk)) {
                @unlink($oldChunk);
            }
        }
    }

    echo json_encode([
        'ok' => true,
        'seq' => $seq,
        'bytes' => strlen($raw),
        'ts' => microtime(true)
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'relay_push_error',
        'message' => $e->getMessage()
    ]);
}
