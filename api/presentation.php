<?php
declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $token = trim((string)($_GET['token'] ?? ''));
    if ($token === '') {
        echo json_encode(['ok' => true, 'service' => 'Maurinsoft Room Presentation API']);
        exit;
    }
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$token = trim((string)($input['token'] ?? ($_GET['token'] ?? '')));
$action = trim((string)($input['action'] ?? ($_GET['action'] ?? 'sync')));
$targetKey = trim((string)($input['target_key'] ?? ''));
$mediaType = trim((string)($input['media_type'] ?? 'camera'));
if (!in_array($mediaType, ['camera', 'screen'], true)) {
    $mediaType = 'camera';
}

if ($token === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'token_required']);
    exit;
}

// 1. Validação do participante chamador e sala
$st = $pdo->prepare("SELECT i.*, r.owner_user_id, r.status as room_status, r.name as room_name 
                     FROM room_invites i 
                     JOIN rooms r ON r.id = i.room_id 
                     WHERE i.token = ? LIMIT 1");
$st->execute([$token]);
$me = $st->fetch();

if (!$me || $me['status'] === 'rejected') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'kicked' => true, 'error' => 'forbidden_or_kicked']);
    exit;
}

if ($me['status'] !== 'approved' || empty($me['participant_key']) || $me['room_status'] !== 'open') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'unauthorized_or_room_closed']);
    exit;
}

$roomId = (int)$me['room_id'];
$myKey = (string)$me['participant_key'];
$myName = trim((string)($me['display_name'] ?: $me['email']));
$isAdmin = is_token_room_admin($pdo, $token);

// 2. Despacho das ações autoritativas

// Sincronização de estado da sala
if ($action === 'sync') {
    $rState = get_room_runtime_state($roomId);
    
    // Fila ordenada por hand_requested_at ASC (Tarefa 05)
    $queueQuery = $pdo->prepare("
        SELECT participant_key, display_name, hand_requested_at, video_granted, video_admin_allowed, audio_admin_allowed, screen_admin_allowed
        FROM room_presence 
        WHERE room_id = ? AND hand_raised = 1 AND last_seen_at >= DATE_SUB(NOW(), INTERVAL 20 SECOND)
        ORDER BY hand_requested_at ASC
    ");
    $queueQuery->execute([$roomId]);
    $handQueue = $queueQuery->fetchAll();

    // Permissões do próprio participante
    $pQuery = $pdo->prepare("SELECT video_admin_allowed, audio_admin_allowed, screen_admin_allowed, video_granted, hand_raised 
                             FROM room_presence WHERE room_id = ? AND participant_key = ? LIMIT 1");
    $pQuery->execute([$roomId, $myKey]);
    $myPresence = $pQuery->fetch() ?: [];

    echo json_encode([
        'ok' => true,
        'room' => $rState,
        'queue' => $handQueue,
        'participant' => [
            'video_allowed' => (bool)($myPresence['video_admin_allowed'] ?? 1),
            'audio_allowed' => (bool)($myPresence['audio_admin_allowed'] ?? 1),
            'screen_allowed' => (bool)($myPresence['screen_admin_allowed'] ?? 0),
            'video_granted' => (bool)($myPresence['video_granted'] ?? 1),
            'hand_raised' => (bool)($myPresence['hand_raised'] ?? 0),
            'is_presenter' => ($rState['room_mode'] === 'presentation' && $rState['active_presenter_key'] === $myKey),
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Participante pede a palavra (Tarefa 03)
if ($action === 'request') {
    $rState = get_room_runtime_state($roomId);
    if ($rState['room_mode'] === 'presentation' && $rState['active_presenter_key'] === $myKey) {
        // Já está apresentando (Tarefa 40)
        echo json_encode([
            'ok' => false,
            'error' => 'already_presenting',
            'message' => 'Você já está no modo de apresentação.'
        ]);
        exit;
    }

    $pdo->prepare("UPDATE room_presence SET hand_raised = 1, hand_requested_at = COALESCE(hand_requested_at, NOW()) WHERE room_id = ? AND participant_key = ?")
        ->execute([$roomId, $myKey]);

    // Emite sinal em tempo real
    try {
        $sig = $pdo->prepare("INSERT INTO signaling_messages (room_id, sender_key, recipient_key, message_type, payload, created_at) 
                              VALUES (?, ?, NULL, 'peer-ready', ?, NOW())");
        $sig->execute([
            $roomId,
            $myKey,
            json_encode([
                'action' => 'hand_raised',
                'participant_key' => $myKey,
                'display_name' => $myName
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);
    } catch (Throwable $e) {}

    log_control_command($roomId, null, $myKey, 'presentation.request', null, ['display_name' => $myName]);

    echo json_encode([
        'ok' => true,
        'hand_raised' => true,
        'participant_key' => $myKey,
        'message' => 'Pedido de palavra registrado. Aguardando aprovação do administrador.'
    ]);
    exit;
}

// Participante cancela pedido de palavra (Tarefa 03)
if ($action === 'cancel') {
    $pdo->prepare("UPDATE room_presence SET hand_raised = 0, hand_requested_at = NULL WHERE room_id = ? AND participant_key = ?")
        ->execute([$roomId, $myKey]);

    try {
        $sig = $pdo->prepare("INSERT INTO signaling_messages (room_id, sender_key, recipient_key, message_type, payload, created_at) 
                              VALUES (?, ?, NULL, 'peer-ready', ?, NOW())");
        $sig->execute([
            $roomId,
            $myKey,
            json_encode([
                'action' => 'hand_lowered',
                'participant_key' => $myKey,
                'display_name' => $myName
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);
    } catch (Throwable $e) {}

    log_control_command($roomId, null, $myKey, 'presentation.cancel', null, ['display_name' => $myName]);

    echo json_encode([
        'ok' => true,
        'hand_raised' => false,
        'participant_key' => $myKey
    ]);
    exit;
}

// As ações a seguir exigem administrador da sala (Tarefa 06, 07, 31)
if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'only_administrators_can_perform_this_action']);
    exit;
}

// Administrador recusa o pedido de palavra (Tarefa 06)
if ($action === 'reject') {
    if ($targetKey === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'target_key_required']);
        exit;
    }

    $pdo->prepare("UPDATE room_presence SET hand_raised = 0, hand_requested_at = NULL WHERE room_id = ? AND participant_key = ?")
        ->execute([$roomId, $targetKey]);

    // Emite sinal direcionado ao participante avisando da recusa
    try {
        $sig = $pdo->prepare("INSERT INTO signaling_messages (room_id, sender_key, recipient_key, message_type, payload, created_at) 
                              VALUES (?, ?, ?, 'peer-ready', ?, NOW())");
        $sig->execute([
            $roomId,
            $myKey,
            $targetKey,
            json_encode([
                'action' => 'presentation.request.rejected',
                'target_key' => $targetKey,
                'message' => 'Seu pedido de palavra foi recusado pelo administrador.'
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);
    } catch (Throwable $e) {}

    log_control_command($roomId, (int)$me['id'], $targetKey, 'presentation.reject', null, ['rejected_by' => $myName]);

    echo json_encode([
        'ok' => true,
        'rejected_key' => $targetKey,
        'message' => 'Pedido de palavra recusado com sucesso.'
    ]);
    exit;
}

// Administrador aprova a apresentação em transação única (Tarefa 06, 07)
if ($action === 'approve') {
    if ($targetKey === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'target_key_required']);
        exit;
    }

    $stTarget = $pdo->prepare("SELECT participant_key, display_name FROM room_presence WHERE room_id = ? AND participant_key = ? LIMIT 1");
    $stTarget->execute([$roomId, $targetKey]);
    $target = $stTarget->fetch();

    if (!$target) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'participant_not_found']);
        exit;
    }
    $targetName = $target['display_name'];

    // Transação Atômica SQL
    try {
        $pdo->beginTransaction();

        // 1. Atualiza permissões do participante alvo
        $pdo->prepare("
            UPDATE room_presence 
            SET video_granted = 1,
                video_admin_allowed = 1,
                hand_raised = 0,
                hand_requested_at = NULL,
                granted_at = NOW()
            WHERE room_id = ? AND participant_key = ?
        ")->execute([$roomId, $targetKey]);

        // 2. Atualiza estado em tempo de execução da sala (modo presentation)
        $pdo->prepare("
            INSERT INTO room_runtime_state (room_id, room_mode, active_presenter_key, presentation_media_type, presentation_started_at, state_version, updated_at)
            VALUES (?, 'presentation', ?, ?, NOW(), 1, NOW())
            ON DUPLICATE KEY UPDATE 
                room_mode = 'presentation',
                active_presenter_key = VALUES(active_presenter_key),
                presentation_media_type = VALUES(presentation_media_type),
                presentation_started_at = NOW(),
                state_version = state_version + 1,
                updated_at = NOW()
        ")->execute([$roomId, $targetKey, $mediaType]);

        // 3. Auditoria
        $commandId = bin2hex(random_bytes(16));
        $pdo->prepare("
            INSERT INTO room_control_audit (room_id, admin_user_id, participant_key, command_id, command, payload, status, created_at)
            VALUES (?, ?, ?, ?, 'room.presentation.start', ?, 'applied', NOW())
        ")->execute([
            $roomId,
            (int)$me['id'],
            $targetKey,
            $commandId,
            json_encode(['presenter_key' => $targetKey, 'media_type' => $mediaType, 'display_name' => $targetName], JSON_UNESCAPED_UNICODE)
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('presentation.approve transaction failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'transaction_failed', 'details' => $e->getMessage()]);
        exit;
    }

    $newState = get_room_runtime_state($roomId);

    // 4. Notifica broadcast para TODOS os participantes via signaling fallback
    try {
        $sig = $pdo->prepare("INSERT INTO signaling_messages (room_id, sender_key, recipient_key, message_type, payload, created_at) 
                              VALUES (?, ?, NULL, 'peer-ready', ?, NOW())");
        $sig->execute([
            $roomId,
            $myKey,
            json_encode([
                'type' => 'room.presentation.start',
                'action' => 'presentation_start',
                'presenter_key' => $targetKey,
                'presenter_name' => $targetName,
                'media_type' => $mediaType,
                'state_version' => $newState['state_version']
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);
    } catch (Throwable $e) {}

    echo json_encode([
        'ok' => true,
        'type' => 'room.presentation.start',
        'room' => $newState,
        'presenter_key' => $targetKey,
        'presenter_name' => $targetName,
        'media_type' => $mediaType
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Administrador encerra a apresentação (Tarefa 25)
if ($action === 'end') {
    try {
        $pdo->beginTransaction();

        $pdo->prepare("
            UPDATE room_runtime_state 
            SET room_mode = 'normal',
                active_presenter_key = NULL,
                presentation_media_type = NULL,
                presentation_started_at = NULL,
                state_version = state_version + 1,
                updated_at = NOW()
            WHERE room_id = ?
        ")->execute([$roomId]);

        $commandId = bin2hex(random_bytes(16));
        $pdo->prepare("
            INSERT INTO room_control_audit (room_id, admin_user_id, participant_key, command_id, command, payload, status, created_at)
            VALUES (?, ?, NULL, ?, 'room.presentation.end', ?, 'applied', NOW())
        ")->execute([
            $roomId,
            (int)$me['id'],
            $commandId,
            json_encode(['ended_by' => $myName], JSON_UNESCAPED_UNICODE)
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('presentation.end failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'transaction_failed']);
        exit;
    }

    $newState = get_room_runtime_state($roomId);

    // Broadcast para todos saírem do Full e restaurarem suas mídias anteriores
    try {
        $sig = $pdo->prepare("INSERT INTO signaling_messages (room_id, sender_key, recipient_key, message_type, payload, created_at) 
                              VALUES (?, ?, NULL, 'peer-ready', ?, NOW())");
        $sig->execute([
            $roomId,
            $myKey,
            json_encode([
                'type' => 'room.presentation.end',
                'action' => 'presentation_end',
                'state_version' => $newState['state_version']
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);
    } catch (Throwable $e) {}

    echo json_encode([
        'ok' => true,
        'type' => 'room.presentation.end',
        'room' => $newState
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'invalid_action']);
