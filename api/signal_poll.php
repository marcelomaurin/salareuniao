<?php
require __DIR__ . '/../lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $token = trim((string)($_GET['token'] ?? ''));
    if ($token === '') {
        echo json_encode([
            'ok' => true,
            'service' => 'Maurinsoft WebRTC Signal Poll API',
            'status' => 'online',
            'info' => 'Passe o parametro ?token=SEU_TOKEN&after=0'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $after = max(0, (int)($_GET['after'] ?? 0));
    $st = $pdo->prepare("SELECT i.*, r.status as room_status FROM room_invites i JOIN rooms r ON r.id=i.room_id WHERE i.token=? LIMIT 1");
    $st->execute([$token]);
    $me = $st->fetch();

    if (!$me || empty($me['participant_key'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden_or_inactive_room']);
        exit;
    }

    if ($me['status'] !== 'approved' || $me['room_status'] !== 'open') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'room_not_open_or_unapproved']);
        exit;
    }

    // Limpeza oportunista de mensagens de sinalização antigas (> 10 minutos)
    if (mt_rand(1, 100) === 1) {
        try {
            $pdo->exec("DELETE FROM signaling_messages WHERE created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
        } catch (Throwable $cleanErr) {}
    }

    $q = $pdo->prepare("SELECT id, sender_key, recipient_key, message_type, payload, created_at 
                        FROM signaling_messages 
                        WHERE room_id=? AND id>? AND sender_key<>? AND (recipient_key IS NULL OR recipient_key=?) 
                        ORDER BY id ASC LIMIT 200");
    $q->execute([$me['room_id'], $after, $me['participant_key'], $me['participant_key']]);

    $messages = [];
    foreach ($q->fetchAll() as $m) {
        $m['payload'] = json_decode($m['payload'], true);
        $messages[] = $m;
    }

    echo json_encode([
        'ok' => true,
        'self' => $me['participant_key'],
        'messages' => $messages
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'poll_error',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
