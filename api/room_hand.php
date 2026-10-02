<?php
require __DIR__ . '/../lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['ok' => true, 'service' => 'Maurinsoft Room Hand & Video Allocation API']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$token = trim((string)($input['token'] ?? ''));
$action = trim((string)($input['action'] ?? 'raise')); // 'raise', 'lower', 'grant', 'revoke'
$targetKey = trim((string)($input['target_key'] ?? ''));

if ($token === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'token_required']);
    exit;
}

// 1. Busca dados do participante e da sala
$st = $pdo->prepare("SELECT i.*, r.owner_user_id, r.status as room_status, r.name as room_name 
                     FROM room_invites i 
                     JOIN rooms r ON r.id = i.room_id 
                     WHERE i.token = ? AND i.status = 'approved' LIMIT 1");
$st->execute([$token]);
$me = $st->fetch();

if (!$me || $me['room_status'] !== 'open') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'unauthorized_or_room_closed']);
    exit;
}

$roomId = (int)$me['room_id'];
$myKey = $me['participant_key'];
$myName = $me['display_name'];
$isAdmin = is_token_room_admin($pdo, $token);

if ($action === 'raise') {
    // Participante levanta a mão para pedir a palavra
    $pdo->prepare("UPDATE room_presence SET hand_raised = 1, hand_requested_at = NOW() WHERE room_id = ? AND participant_key = ?")
        ->execute([$roomId, $myKey]);

    // Envia sinal em tempo real para os demais participantes
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

    echo json_encode(['ok' => true, 'hand_raised' => true, 'participant_key' => $myKey]);
    exit;
}

if ($action === 'lower') {
    // Participante baixa a mão
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

    echo json_encode(['ok' => true, 'hand_raised' => false, 'participant_key' => $myKey]);
    exit;
}

// Ações restritas ao administrador da sala
if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'only_administrators_can_manage_speakers']);
    exit;
}

if ($targetKey === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'target_key_required']);
    exit;
}

// Localiza o participante alvo
$stTarget = $pdo->prepare("SELECT participant_key, display_name FROM room_presence WHERE room_id = ? AND participant_key = ? LIMIT 1");
$stTarget->execute([$roomId, $targetKey]);
$target = $stTarget->fetch();
$targetName = $target['display_name'] ?? 'Participante';

if ($action === 'grant') {
    // Regra: Máximo de 5 vídeos abertos simultâneos na sala!
    // Busca quem atualmente possui vídeo concedido ativo na sala
    $activeQuery = $pdo->prepare("SELECT participant_key, display_name, granted_at 
                                  FROM room_presence 
                                  WHERE room_id = ? AND video_granted = 1 AND participant_key <> ?
                                  ORDER BY granted_at ASC");
    $activeQuery->execute([$roomId, $targetKey]);
    $activeSpeakers = $activeQuery->fetchAll();

    $revokedKey = null;
    $revokedName = null;

    // Se já existem 5 ou mais pessoas no vídeo, remove a mais antiga (que não seja o anfitrião se possível)
    if (count($activeSpeakers) >= 4) { // 4 outros + 1 alvo = total 5
        // Tenta remover o speaker mais antigo que não seja o próprio admin chamador
        $toRevoke = null;
        foreach ($activeSpeakers as $sp) {
            if ($sp['participant_key'] !== $myKey) {
                $toRevoke = $sp;
                break;
            }
        }
        if (!$toRevoke && !empty($activeSpeakers)) {
            $toRevoke = $activeSpeakers[0];
        }

        if ($toRevoke) {
            $revokedKey = $toRevoke['participant_key'];
            $revokedName = $toRevoke['display_name'];
            $pdo->prepare("UPDATE room_presence SET video_granted = 0 WHERE room_id = ? AND participant_key = ?")
                ->execute([$roomId, $revokedKey]);
        }
    }

    // Concede o vídeo para o participante alvo e zera o pedido de mão
    $pdo->prepare("UPDATE room_presence SET video_granted = 1, hand_raised = 0, granted_at = NOW() WHERE room_id = ? AND participant_key = ?")
        ->execute([$roomId, $targetKey]);

    // Notifica via sinalização para atualização instantânea dos vídeos
    try {
        $sig = $pdo->prepare("INSERT INTO signaling_messages (room_id, sender_key, recipient_key, message_type, payload, created_at) 
                              VALUES (?, ?, NULL, 'peer-ready', ?, NOW())");
        $sig->execute([
            $roomId,
            $myKey,
            json_encode([
                'action' => 'speaker_granted',
                'granted_key' => $targetKey,
                'granted_name' => $targetName,
                'revoked_key' => $revokedKey,
                'revoked_name' => $revokedName
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);
    } catch (Throwable $e) {}

    echo json_encode([
        'ok' => true,
        'granted_key' => $targetKey,
        'granted_name' => $targetName,
        'revoked_key' => $revokedKey,
        'revoked_name' => $revokedName
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'revoke') {
    // Administrador retira o vídeo do participante
    $pdo->prepare("UPDATE room_presence SET video_granted = 0 WHERE room_id = ? AND participant_key = ?")
        ->execute([$roomId, $targetKey]);

    try {
        $sig = $pdo->prepare("INSERT INTO signaling_messages (room_id, sender_key, recipient_key, message_type, payload, created_at) 
                              VALUES (?, ?, NULL, 'peer-ready', ?, NOW())");
        $sig->execute([
            $roomId,
            $myKey,
            json_encode([
                'action' => 'speaker_revoked',
                'revoked_key' => $targetKey,
                'revoked_name' => $targetName
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);
    } catch (Throwable $e) {}

    echo json_encode(['ok' => true, 'revoked_key' => $targetKey]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'invalid_action']);
