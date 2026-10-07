<?php
declare(strict_types=1);

if (!defined('IS_API')) {
    define('IS_API', true);
}

/**
 * Autentica o participante do Relay HTTP com cache multi-nível (memória + /tmp + disco)
 * para evitar qualquer consulta ou sobrecarga no MySQL na Hospedagem Compartilhada (Hostinger).
 */
function get_relay_auth_info(string $token): ?array {
    $token = trim($token);
    if ($token === '' || !preg_match('/^[a-f0-9]{32,64}$/i', $token)) {
        return null;
    }

    static $memoryCache = [];
    if (isset($memoryCache[$token])) {
        return $memoryCache[$token];
    }

    $hash = md5($token);
    $files = [
        sys_get_temp_dir() . '/tok_' . $hash . '.json',
        __DIR__ . '/../storage/tokens/' . $hash . '.json'
    ];

    // 1. Tenta carregar do cache em /tmp ou em storage/tokens (12 horas)
    foreach ($files as $f) {
        if (is_file($f)) {
            $raw = @file_get_contents($f);
            if ($raw) {
                $cached = json_decode($raw, true);
                if ($cached && isset($cached['exp']) && $cached['exp'] > time() && !empty($cached['data'])) {
                    $memoryCache[$token] = $cached['data'];
                    return $cached['data'];
                }
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

        $payload = json_encode([
            'exp' => time() + 43200,
            'data' => $data
        ], JSON_UNESCAPED_SLASHES);

        foreach ($files as $f) {
            $dir = dirname($f);
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            @file_put_contents($f, $payload, LOCK_EX);
        }

        $memoryCache[$token] = $data;
        return $data;
    } catch (Throwable $e) {
        return null;
    }
}
