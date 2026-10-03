<?php
require __DIR__ . '/../lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$token = trim((string)($input['token'] ?? ($_GET['token'] ?? '')));

if ($token === '') {
    echo json_encode([
        'ok' => true,
        'service' => 'Maurinsoft Presence Heartbeat API',
        'status' => 'online'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$st = $pdo->prepare("SELECT i.*, r.status room_status, r.owner_user_id, r.name as room_name 
                     FROM room_invites i 
                     JOIN rooms r ON r.id=i.room_id 
                     WHERE i.token=? LIMIT 1");
$st->execute([$token]);
$me = $st->fetch();

if (!$me) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

// Expulsão autoritativa no servidor (Tarefa 30, 57)
if ($me['status'] === 'rejected' || $me['status'] === 'kicked') {
    echo json_encode([
        'ok' => false,
        'kicked' => true,
        'error' => 'kicked',
        'status' => 'rejected',
        'room_name' => $me['room_name'] ?? '',
        'message' => 'Você foi expulso da sala de reunião.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($me['status'] !== 'approved' || empty($me['participant_key'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$roomId = (int)$me['room_id'];
$key = (string)$me['participant_key'];
$name = trim((string)($me['display_name'] ?: $me['email']));

// 1. Limpeza de sessões abandonadas por perda de heartbeat (> 20 segundos)
try {
    $stale = $pdo->prepare("SELECT room_id, participant_key, last_seen_at FROM room_presence WHERE room_id=? AND last_seen_at<DATE_SUB(NOW(), INTERVAL 20 SECOND)");
    $stale->execute([$roomId]);
    $staleRows = $stale->fetchAll();
    foreach ($staleRows as $p) {
        $close = $pdo->prepare("UPDATE room_attendance SET left_at=?, duration_seconds=TIMESTAMPDIFF(SECOND, joined_at, ?) WHERE room_id=? AND participant_key=? AND left_at IS NULL ORDER BY id DESC LIMIT 1");
        $close->execute([$p['last_seen_at'], $p['last_seen_at'], $roomId, $p['participant_key']]);
    }
    if (!empty($staleRows)) {
        $pdo->prepare("DELETE FROM room_presence WHERE room_id=? AND last_seen_at<DATE_SUB(NOW(), INTERVAL 20 SECOND)")->execute([$roomId]);
    }
} catch (Throwable $e) {}

// 2. Trata queda do apresentador ativo por timeout (Tarefa 42)
$rState = get_room_runtime_state($roomId);
if ($rState['room_mode'] === 'presentation' && !empty($rState['active_presenter_key'])) {
    $presCheck = $pdo->prepare("SELECT 1 FROM room_presence WHERE room_id = ? AND participant_key = ? AND last_seen_at >= DATE_SUB(NOW(), INTERVAL 20 SECOND) LIMIT 1");
    $presCheck->execute([$roomId, $rState['active_presenter_key']]);
    if (!$presCheck->fetchColumn()) {
        // Apresentador desconectou: encerra apresentação automaticamente
        $rState = end_room_presentation($roomId);
        try {
            $sig = $pdo->prepare("INSERT INTO signaling_messages (room_id, sender_key, recipient_key, message_type, payload, created_at) 
                                  VALUES (?, 'system', NULL, 'peer-ready', ?, NOW())");
            $sig->execute([
                $roomId,
                json_encode([
                    'type' => 'room.presentation.end',
                    'action' => 'presentation_end',
                    'reason' => 'presenter_timeout',
                    'state_version' => $rState['state_version']
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ]);
        } catch (Throwable $e) {}
    }
}

$isAdmin = is_token_room_admin($pdo, $token);

// 3. Processamento do Heartbeat (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mic = !empty($input['mic']) ? 1 : 0;
    $cam = !empty($input['cam']) ? 1 : 0;
    $screen = !empty($input['screen']) ? 1 : 0;

    try {
        $existing = $pdo->prepare('SELECT video_granted, hand_raised, hand_requested_at, video_admin_allowed, audio_admin_allowed, screen_admin_allowed FROM room_presence WHERE room_id=? AND participant_key=? LIMIT 1');
        $existing->execute([$roomId, $key]);
        $rowExisting = $existing->fetch();

        if (!$rowExisting) {
            $att = $pdo->prepare('INSERT INTO room_attendance(room_id, participant_key, display_name, joined_at) VALUES (?, ?, ?, NOW())');
            $att->execute([$roomId, $key, $name]);
            
            $initVideoGranted = $isAdmin ? 1 : 0;
            $q = $pdo->prepare("INSERT INTO room_presence(room_id, participant_key, display_name, mic_enabled, cam_enabled, screen_sharing, video_granted, hand_raised, hand_requested_at, video_admin_allowed, audio_admin_allowed, screen_admin_allowed, joined_at, last_seen_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 0, NULL, 1, 1, 0, NOW(), NOW())");
            $q->execute([$roomId, $key, $name, $mic, $cam, $screen, $initVideoGranted]);
        } else {
            // O servidor é a autoridade sobre hand_raised e video_granted (Tarefa 04)
            // Heartbeat NUNCA ressuscita hand_raised se o servidor tiver 0!
            $handVal = (int)$rowExisting['hand_raised'];
            $handTime = $rowExisting['hand_requested_at'];
            
            // Se o participante for o apresentador ativo, hand_raised é estritamente 0
            if ($rState['room_mode'] === 'presentation' && $rState['active_presenter_key'] === $key) {
                $handVal = 0;
                $handTime = null;
            }

            $vidVal = (int)$rowExisting['video_granted'];
            if ($isAdmin) {
                $vidVal = 1;
            }

            $q = $pdo->prepare("UPDATE room_presence SET 
                display_name = ?, 
                mic_enabled = ?, 
                cam_enabled = ?, 
                screen_sharing = ?, 
                video_granted = ?,
                hand_raised = ?,
                hand_requested_at = ?,
                last_seen_at = NOW() 
                WHERE room_id = ? AND participant_key = ?");
            $q->execute([$name, $mic, $cam, $screen, $vidVal, $handVal, $handTime, $roomId, $key]);
        }
    } catch (Throwable $e) {
        $q = $pdo->prepare("INSERT INTO room_presence(room_id, participant_key, display_name, last_seen_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE display_name=VALUES(display_name), last_seen_at=NOW()");
        $q->execute([$roomId, $key, $name]);
    }
}

// 4. Lista de Participantes Online
$participants = [];
try {
    $q = $pdo->prepare("SELECT participant_key, display_name, mic_enabled, cam_enabled, screen_sharing, hand_raised, hand_requested_at, video_granted, video_admin_allowed, audio_admin_allowed, screen_admin_allowed, joined_at, last_seen_at
                        FROM room_presence WHERE room_id=? AND last_seen_at>=DATE_SUB(NOW(), INTERVAL 20 SECOND) ORDER BY joined_at");
    $q->execute([$roomId]);
    $participants = $q->fetchAll();
} catch (Throwable $e) {}

// 5. Fila Real de Pedidos de Palavra ordenada por hand_requested_at ASC (Tarefa 05)
$handQueue = [];
foreach ($participants as $p) {
    if (!empty($p['hand_raised'])) {
        $handQueue[] = [
            'participant_key' => $p['participant_key'],
            'display_name' => $p['display_name'],
            'hand_requested_at' => $p['hand_requested_at']
        ];
    }
}
usort($handQueue, function($a, $b) {
    $tA = strtotime((string)($a['hand_requested_at'] ?? '2099-01-01'));
    $tB = strtotime((string)($b['hand_requested_at'] ?? '2099-01-01'));
    return $tA <=> $tB;
});

// 6. Sala de espera
$waiting = [];
try {
    $wq = $pdo->prepare("SELECT id, display_name, email, requested_at, request_ip FROM room_invites WHERE room_id=? AND status='waiting' ORDER BY requested_at ASC");
    $wq->execute([$roomId]);
    $waiting = $wq->fetchAll();
    // Apenas administradores podem visualizar o endereço IP na sala de espera (Tarefa 07)
    if (!$isAdmin) {
        foreach ($waiting as &$wItem) {
            unset($wItem['request_ip']);
        }
        unset($wItem);
    }
} catch (Throwable $e) {}

// Permissões do próprio participante chamador
$myPresence = null;
foreach ($participants as $p) {
    if ($p['participant_key'] === $key) {
        $myPresence = $p;
        break;
    }
}

echo json_encode([
    'ok' => true,
    'room_status' => $me['room_status'],
    'self' => $key,
    'participants' => $participants,
    'waiting' => $waiting,
    'room_state' => $rState,
    'hand_queue' => $handQueue,
    'self_permissions' => [
        'video_allowed' => (bool)($myPresence['video_admin_allowed'] ?? 1),
        'audio_allowed' => (bool)($myPresence['audio_admin_allowed'] ?? 1),
        'screen_allowed' => (bool)($myPresence['screen_admin_allowed'] ?? 0),
        'video_granted' => (bool)($myPresence['video_granted'] ?? ($isAdmin ? 1 : 0)),
        'hand_raised' => (bool)($myPresence['hand_raised'] ?? 0),
        'is_presenter' => ($rState['room_mode'] === 'presentation' && $rState['active_presenter_key'] === $key),
    ]
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
