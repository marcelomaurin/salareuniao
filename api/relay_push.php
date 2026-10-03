<?php
declare(strict_types=1);

define('IS_API', true);
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

    require_once __DIR__ . '/../lib/relay_auth.php';
    $me = get_relay_auth_info($token);

    if (!$me) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden_or_inactive_room']);
        exit;
    }

    $roomId = $me['room_id'];
    $participantKey = $me['participant_key'];
    $seq = (int)($_GET['seq'] ?? $_SERVER['HTTP_X_CHUNK_SEQ'] ?? 0);
    $mime = trim((string)($_GET['mime'] ?? $_SERVER['HTTP_X_MIME_TYPE'] ?? 'video/webm;codecs=vp8,opus'));
    $isInit = !empty($_GET['init']) || (!empty($_SERVER['HTTP_X_IS_INIT']) && $_SERVER['HTTP_X_IS_INIT'] === '1') || ($seq === 0);

    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) === 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'empty_payload']);
        exit;
    }

    $relayDir = __DIR__ . '/../storage/' . $roomId . '/relay/' . $participantKey;
    if (!is_dir($relayDir)) {
        @mkdir($relayDir, 0775, true);
    }

    // Se for o chunk inicial com o cabeçalho EBML/Track do WebM, armazena como init.bin
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

    // Limpeza de buffer rotativo: mantém apenas os últimos 8 chunks
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
