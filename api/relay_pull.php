<?php
declare(strict_types=1);

define('IS_API', true);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

try {
    $token = trim((string)($_GET['token'] ?? ''));
    if ($token === '') {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'token_required']);
        exit;
    }

    require_once __DIR__ . '/../lib/relay_auth.php';
    $me = get_relay_auth_info($token);

    if (!$me) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'forbidden_or_inactive_room']);
        exit;
    }

    $roomId = $me['room_id'];
    $roomRelayDir = __DIR__ . '/../storage/' . $roomId . '/relay';

    // Lista participantes com transmissão ativa no relay
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

    // Se o cliente precisa do cabeçalho de inicialização WebM
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

    // Long-polling curto: aguarda até 400ms verificando a cada 40ms sem prender conexões MySQL
    if (!is_file($targetFile)) {
        $startTime = microtime(true);
        while (microtime(true) - $startTime < 0.40) {
            usleep(40000); // 40ms
            clearstatcache(true, $targetFile);
            if (is_file($targetFile)) {
                break;
            }
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

    // Nenhum novo chunk disponível neste ciclo
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
