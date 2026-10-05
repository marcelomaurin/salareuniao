<?php
declare(strict_types=1);

/**
 * Integração do site PHP com o serviço broadcast (bcastd, em broadcast/).
 *
 * O bcastd é a autoridade em tempo real da sala (sala de espera, orador,
 * chat, banimentos). O PHP continua com login, cadastro de salas, convites
 * por e-mail e histórico. A troca do transporte é feita por configuração:
 *
 *   'media' => ['transport' => 'broadcast'],     // ou 'p2p' (legado)
 *   'broadcast' => ['enabled' => true, 'public_url' => 'wss://.../salareuniao/broadcast', ...]
 */

function broadcast_config(array $config): array {
    $b = $config['broadcast'] ?? [];
    return [
        'enabled' => !empty($b['enabled']),
        'public_url' => (string)($b['public_url'] ?? ''),
        'internal_host' => (string)($b['internal_host'] ?? '127.0.0.1'),
        'internal_port' => (int)($b['internal_port'] ?? 8090),
        'chunk_ms' => max(100, min(1000, (int)($b['chunk_ms'] ?? 250))),
    ];
}

/** true quando a mídia deve usar o servidor de broadcast em vez do P2P. */
function broadcast_active(array $config): bool {
    $transport = (string)($config['media']['transport'] ?? 'p2p');
    $b = broadcast_config($config);
    return $transport === 'broadcast' && $b['enabled'] && $b['public_url'] !== '';
}

/**
 * Redireciona room.php / join.php para a página do broadcast quando ativo.
 * Mantém o token individual (convite) ou o token público da sala.
 */
function broadcast_redirect_if_active(array $config, string $inviteToken, string $roomToken): void {
    if (!broadcast_active($config)) {
        return;
    }
    $q = [];
    if ($inviteToken !== '' && preg_match('/^[a-f0-9]{32,128}$/i', $inviteToken)) {
        $q['token'] = $inviteToken;
    }
    if ($roomToken !== '' && preg_match('/^[a-f0-9]{32,128}$/i', $roomToken)) {
        $q['room_token'] = $roomToken;
    }
    if (!$q) {
        return;
    }
    header('Location: broadcast.php?' . http_build_query($q));
    exit;
}

/** Consulta /healthz e /metrics do bcastd (somente acessível em 127.0.0.1). */
function broadcast_health(array $config): array {
    $b = broadcast_config($config);
    $out = ['ok' => false, 'metrics' => [], 'error' => ''];
    $fetch = static function (string $path) use ($b, &$out): ?string {
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($b['internal_host'], $b['internal_port'], $errno, $errstr, 2.0);
        if (!$fp) {
            $out['error'] = $errstr !== '' ? $errstr : 'sem conexão';
            return null;
        }
        stream_set_timeout($fp, 2);
        fwrite($fp, "GET {$path} HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
        $resp = stream_get_contents($fp);
        fclose($fp);
        if (!is_string($resp) || !str_contains($resp, "\r\n\r\n")) {
            return null;
        }
        [$head, $body] = explode("\r\n\r\n", $resp, 2);
        return str_contains($head, ' 200 ') ? $body : null;
    };
    $health = $fetch('/healthz');
    $out['ok'] = $health !== null && trim($health) === 'ok';
    if ($out['ok']) {
        $metrics = $fetch('/metrics') ?? '';
        foreach (preg_split('/\r?\n/', $metrics) as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) === 2) {
                $out['metrics'][$parts[0]] = $parts[1];
            }
        }
    }
    return $out;
}
