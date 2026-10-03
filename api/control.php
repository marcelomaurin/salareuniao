<?php
declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$token = trim((string)($input['token'] ?? ($_GET['token'] ?? '')));

if ($token === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'token_required']);
    exit;
}

$st = $pdo->prepare("SELECT i.*, r.owner_user_id, r.status as room_status, r.name as room_name 
                     FROM room_invites i 
                     JOIN rooms r ON r.id = i.room_id 
                     WHERE i.token = ? LIMIT 1");
$st->execute([$token]);
$me = $st->fetch();

if (!$me || $me['status'] === 'rejected' || $me['status'] === 'kicked') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'kicked' => true, 'error' => 'kicked']);
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

$action = trim((string)($input['action'] ?? ($_GET['action'] ?? 'sync')));

// 1. Sincronização de Estado (Tarefa 24)
if ($action === 'sync') {
    $rState = get_room_runtime_state($roomId);
    
    $pQuery = $pdo->prepare("SELECT video_admin_allowed, audio_admin_allowed, screen_admin_allowed, video_granted, hand_raised 
                             FROM room_presence WHERE room_id = ? AND participant_key = ? LIMIT 1");
    $pQuery->execute([$roomId, $myKey]);
    $myPresence = $pQuery->fetch() ?: [];

    echo json_encode([
        'ok' => true,
        'room' => $rState,
        'participant' => [
            'video_allowed' => (bool)($myPresence['video_admin_allowed'] ?? 1),
            'audio_allowed' => (bool)($myPresence['audio_admin_allowed'] ?? 1),
            'screen_allowed' => (bool)($myPresence['screen_admin_allowed'] ?? 0),
            'video_granted' => (bool)($myPresence['video_granted'] ?? 1),
            'hand_raised' => (bool)($myPresence['hand_raised'] ?? 0),
            'is_presenter' => ($rState['room_mode'] === 'presentation' && $rState['active_presenter_key'] === $myKey),
        ],
        'is_admin' => $isAdmin
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. Comandos Administrativos (Tarefas 09, 31, 32, 34)
if ($action === 'command') {
    if (!$isAdmin) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'status' => 'failed', 'error' => 'forbidden_admin_required']);
        exit;
    }

    $commandId = trim((string)($input['command_id'] ?? bin2hex(random_bytes(16))));
    $command = trim((string)($input['command'] ?? ''));
    $targetKey = trim((string)($input['target_key'] ?? ''));
    $payload = (array)($input['payload'] ?? []);

    // Idempotência
    $checkCmd = $pdo->prepare("SELECT status FROM room_control_audit WHERE command_id = ? LIMIT 1");
    $checkCmd->execute([$commandId]);
    if ($existing = $checkCmd->fetch()) {
        echo json_encode([
            'ok' => true,
            'type' => 'command.ack',
            'command_id' => $commandId,
            'status' => $existing['status'],
            'idempotent' => true
        ]);
        exit;
    }

    $applied = false;
    $stateVersion = null;
    $extraState = null;

    try {
        switch ($command) {
            case 'participant.video.allow':
                $pdo->prepare("UPDATE room_presence SET video_admin_allowed = 1 WHERE room_id = ? AND participant_key = ?")
                    ->execute([$roomId, $targetKey]);
                $applied = true;
                break;

            case 'participant.video.inhibit':
                $pdo->prepare("UPDATE room_presence SET video_admin_allowed = 0 WHERE room_id = ? AND participant_key = ?")
                    ->execute([$roomId, $targetKey]);
                $applied = true;
                break;

            case 'participant.audio.allow':
                $pdo->prepare("UPDATE room_presence SET audio_admin_allowed = 1 WHERE room_id = ? AND participant_key = ?")
                    ->execute([$roomId, $targetKey]);
                $applied = true;
                break;

            case 'participant.audio.inhibit':
                $pdo->prepare("UPDATE room_presence SET audio_admin_allowed = 0 WHERE room_id = ? AND participant_key = ?")
                    ->execute([$roomId, $targetKey]);
                $applied = true;
                break;

            case 'participant.screen.allow':
                $pdo->prepare("UPDATE room_presence SET screen_admin_allowed = 1 WHERE room_id = ? AND participant_key = ?")
                    ->execute([$roomId, $targetKey]);
                $applied = true;
                break;

            case 'participant.screen.inhibit':
                $pdo->prepare("UPDATE room_presence SET screen_admin_allowed = 0 WHERE room_id = ? AND participant_key = ?")
                    ->execute([$roomId, $targetKey]);
                $applied = true;
                break;

            case 'participant.kick':
                $pdo->prepare("UPDATE room_invites SET status = 'rejected' WHERE room_id = ? AND participant_key = ?")
                    ->execute([$roomId, $targetKey]);
                $pdo->prepare("DELETE FROM room_presence WHERE room_id = ? AND participant_key = ?")
                    ->execute([$roomId, $targetKey]);
                $applied = true;
                break;

            case 'room.presentation.start':
                $mediaType = in_array(($payload['media_type'] ?? ''), ['camera', 'screen'], true) ? $payload['media_type'] : 'camera';
                $extraState = set_room_presentation($roomId, $targetKey, $mediaType);
                $stateVersion = $extraState['state_version'];
                $pdo->prepare("UPDATE room_presence SET video_granted = 1, video_admin_allowed = 1, hand_raised = 0, hand_requested_at = NULL WHERE room_id = ? AND participant_key = ?")
                    ->execute([$roomId, $targetKey]);
                $applied = true;
                break;

            case 'room.presentation.end':
                $extraState = end_room_presentation($roomId);
                $stateVersion = $extraState['state_version'];
                $applied = true;
                break;

            case 'room.close':
                $pdo->prepare("UPDATE rooms SET status = 'closed', updated_at = NOW() WHERE id = ?")->execute([$roomId]);
                $applied = true;
                break;

            default:
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'unsupported_command']);
                exit;
        }
    } catch (Throwable $e) {
        log_control_command($roomId, (int)$me['id'], $targetKey ?: null, $command, $commandId, $payload, 'failed');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }

    if ($applied) {
        log_control_command($roomId, (int)$me['id'], $targetKey ?: null, $command, $commandId, $payload, 'applied');

        // Envia broadcast de sinalização HTTP fallback para os demais clientes
        try {
            $sig = $pdo->prepare("INSERT INTO signaling_messages (room_id, sender_key, recipient_key, message_type, payload, created_at) 
                                  VALUES (?, ?, NULL, 'peer-ready', ?, NOW())");
            $sig->execute([
                $roomId,
                $myKey,
                json_encode([
                    'type' => 'command',
                    'protocol' => 1,
                    'command_id' => $commandId,
                    'command' => $command,
                    'target_key' => $targetKey,
                    'payload' => $payload,
                    'state_version' => $stateVersion,
                    'room_state' => $extraState
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ]);
        } catch (Throwable $e) {}

        echo json_encode([
            'ok' => true,
            'type' => 'command.ack',
            'command_id' => $commandId,
            'status' => 'applied',
            'state_version' => $stateVersion,
            'room_state' => $extraState
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'invalid_action']);
