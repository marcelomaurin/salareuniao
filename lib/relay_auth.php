<?php
declare(strict_types=1);

if (!defined('IS_API')) {
    define('IS_API', true);
}

/**
 * Autentica o participante do Relay HTTP com cache em disco para evitar
 * esgotamento de conexões MySQL na Hospedagem Compartilhada (Hostinger).
 */
function get_relay_auth_info(string $token): ?array {
    $token = trim($token);
    if ($token === '' || !preg_match('/^[a-f0-9]{32,64}$/i', $token)) {
        return null;
    }

    $cacheDir = __DIR__ . '/../storage/tokens';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }
    $cacheFile = $cacheDir . '/' . md5($token) . '.json';

    // 1. Tenta carregar do cache em disco (evita tocar no MySQL em cada chunk de 250ms)
    if (is_file($cacheFile)) {
        $raw = @file_get_contents($cacheFile);
        if ($raw) {
            $cached = json_decode($raw, true);
            if ($cached && isset($cached['exp']) && $cached['exp'] > time() && !empty($cached['data'])) {
                return $cached['data'];
            }
        }
    }

    // 2. Se não estiver no cache ou expirou, consulta o banco
    require_once __DIR__ . '/bootstrap.php';
    global $pdo;

    if (!isset($pdo) || !$pdo) {
        return null;
    }

    try {
        $st = $pdo->prepare("SELECT i.id, i.room_id, i.status, i.participant_key, r.status as room_status 
                             FROM room_invites i 
                             JOIN rooms r ON r.id = i.room_id 
                             WHERE i.token = ? LIMIT 1");
        $st->execute([$token]);
        $me = $st->fetch();

        // Fecha conexão imediatamente para liberar o pool do MySQL
        $pdo = null;

        if (!$me || empty($me['participant_key']) || $me['status'] !== 'approved' || $me['room_status'] !== 'open') {
            return null;
        }

        $data = [
            'room_id' => (int)$me['room_id'],
            'participant_key' => preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$me['participant_key'])
        ];

        // Salva no cache com TTL de 45 segundos
        @file_put_contents($cacheFile, json_encode([
            'exp' => time() + 45,
            'data' => $data
        ], JSON_UNESCAPED_SLASHES), LOCK_EX);

        return $data;
    } catch (Throwable $e) {
        $pdo = null;
        return null;
    }
}
