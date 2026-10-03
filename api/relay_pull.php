<?php
declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $token = trim((string)($_GET['token'] ?? ''));
    if ($token === '') {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
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
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'forbidden_or_inactive_room']);
        exit;
    }

    $roomId = (int)$me['room_id'];
    $roomRelayDir = get_room_storage_dir($roomId) . '/relay';

    // Lista participantes com transmissao ativa no relay
    if (!empty($_GET['list'])) {
        header('Content-Type: application/json; charset=utf-8');
        $publishers = [];
        if (is_dir($roomRelayDir)) {
            $dirs = scandir($roomRelayDir);
            $now = microtime(true);
            foreach ($dirs as $d) {
                if ($d === '.' || $d === '..') continue;
                $mFile = $roomRelayDir . '/' . $d . '/meta.json';
                if (is_file($mFile)) {
                    $mData = json_decode((string)file_get_contents($mFile), true);
                    if ($mData && ($now - (float)($mData['updated_at'] ?? 0) <= 6.0)) {
                        $publishers[] = $mData;
                    }
                }
            }
        }
        echo json_encode(['ok' => true, 'publishers' => $publishers]);
        exit;
    }

    $pubKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($_GET['publisher_key'] ?? ''));
    if ($pubKey === '') {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'publisher_key_required']);
        exit;
    }

    $pubDir = $roomRelayDir . '/' . $pubKey;
    $metaFile = $pubDir . '/meta.json';

    if (!is_dir($pubDir) || !is_file($metaFile)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'active' => false, 'has_new' => false]);
        exit;
    }

    $meta = json_decode((string)file_get_contents($metaFile), true);
    $lastSeq = (int)($meta['last_seq'] ?? 0);
    $mime = (string)($meta['mime'] ?? 'video/webm;codecs=vp8,opus');

    $after = (int)($_GET['after'] ?? -1);
    $needInit = !empty($_GET['init']) || ($after < 0);

    // Se o cliente precisa do cabecalho de inicializacao WebM
    if ($needInit) {
        $initFile = $pubDir . '/init.bin';
        if (is_file($initFile)) {
            header('Content-Type: application/octet-stream');
            header('X-Relay-Is-Init: 1');
            header('X-Relay-Seq: 0');
            header('X-Relay-Last-Seq: ' . $lastSeq);
            header('X-Relay-Mime: ' . $mime);
            readfile($initFile);
            exit;
        }
    }

    $targetSeq = $after + 1;
    $targetFile = $pubDir . '/chunk_' . $targetSeq . '.bin';

    // Long-polling: se o proximo chunk ainda nao esta em disco, aguarda ate 700ms verificando a cada 50ms
    if (!is_file($targetFile)) {
        $startTime = microtime(true);
        while (microtime(true) - $startTime < 0.70) {
            usleep(50000); // 50ms
            clearstatcache(true, $targetFile);
            if (is_file($targetFile)) {
                break;
            }
            // Se o transmissor avancou muito a frente do cliente (gap), pula para o chunk mais recente
            if (is_file($metaFile)) {
                $curMeta = json_decode((string)file_get_contents($metaFile), true);
                $curLast = (int)($curMeta['last_seq'] ?? 0);
                if ($curLast > $targetSeq + 4) {
                    $targetSeq = $curLast;
                    $targetFile = $pubDir . '/chunk_' . $targetSeq . '.bin';
                    if (is_file($targetFile)) break;
                }
            }
        }
    }

    if (is_file($targetFile)) {
        header('Content-Type: application/octet-stream');
        header('X-Relay-Is-Init: 0');
        header('X-Relay-Seq: ' . $targetSeq);
        header('X-Relay-Last-Seq: ' . $lastSeq);
        header('X-Relay-Mime: ' . $mime);
        readfile($targetFile);
        exit;
    }

    // Nenhum novo chunk disponivel neste ciclo
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'active' => (microtime(true) - (float)($meta['updated_at'] ?? 0) <= 6.0),
        'has_new' => false,
        'last_seq' => $lastSeq,
        'mime' => $mime
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'relay_pull_error',
        'message' => $e->getMessage()
    ]);
}
