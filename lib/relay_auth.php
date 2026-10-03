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

    // 1. Tenta carregar do cache em disco (TTL de 12 horas - sessão da reunião)
    if (is_file($cacheFile)) {
        $raw = @file_get_contents($cacheFile);
        if ($raw) {
            $cached = json_decode($raw, true);
            if ($cached && isset($cached['exp']) && $cached['exp'] > time() && !empty($cached['data'])) {
                return $cached['data'];
            }
        }
    }

    // 2. Se não estiver no cache, obtém e valida no banco de forma resiliente
    require_once __DIR__ . '/bootstrap.php';

    try {
        $db = get_pdo();
        $st = $db->prepare("SELECT i.id, i.room_id, i.status, i.participant_key, r.status as room_status 
                             FROM room_invites i 
                             JOIN rooms r ON r.id = i.room_id 
                             WHERE i.token = ? LIMIT 1");
        $st->execute([$token]);
        $me = $st->fetch();

        if (!$me || empty($me['participant_key']) || $me['status'] !== 'approved' || $me['room_status'] !== 'open') {
            return null;
        }

        $data = [
            'room_id' => (int)$me['room_id'],
            'participant_key' => preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$me['participant_key'])
        ];

        // Salva no cache com TTL de 12 horas
        @file_put_contents($cacheFile, json_encode([
            'exp' => time() + 43200,
            'data' => $data
        ], JSON_UNESCAPED_SLASHES), LOCK_EX);

        return $data;
    } catch (Throwable $e) {
        return null;
    }
}
