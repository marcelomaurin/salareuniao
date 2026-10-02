<?php
require __DIR__ . '/../lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['ok' => true, 'service' => 'Maurinsoft Room Admission API']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$hostToken = trim((string)($input['host_token'] ?? ($input['token'] ?? '')));
$inviteId = (int)($input['invite_id'] ?? 0);
$action = trim((string)($input['action'] ?? 'approve')); // 'approve' ou 'reject'

if ($hostToken === '' || $inviteId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_parameters']);
    exit;
}

// Qualquer participante aprovado dentro da sala aberta pode aprovar/recusar participantes da sala de espera
$st = $pdo->prepare("SELECT i.*, r.owner_user_id, r.status as room_status 
                     FROM room_invites i 
                     JOIN rooms r ON r.id = i.room_id 
                     WHERE i.token = ? AND i.status = 'approved' LIMIT 1");
$st->execute([$hostToken]);
$member = $st->fetch();

if (!$member || $member['room_status'] !== 'open') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'unauthorized_or_room_closed']);
    exit;
}

$roomId = (int)$member['room_id'];

// Localiza o convite do participante na sala de espera
$q = $pdo->prepare("SELECT id, room_id, display_name, participant_key, status FROM room_invites WHERE id=? AND room_id=? AND status='waiting' LIMIT 1");
$q->execute([$inviteId, $roomId]);
$guest = $q->fetch();

if (!$guest) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'guest_not_found']);
    exit;
}

if ($action === 'approve') {
    $up = $pdo->prepare("UPDATE room_invites SET status='approved', approved_at=NOW() WHERE id=? AND status='waiting'");
    $up->execute([$inviteId]);
    if ($up->rowCount() !== 1) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'guest_already_processed']);
        exit;
    }

    try {
        $sig = $pdo->prepare("INSERT INTO signaling_messages(room_id, sender_key, recipient_key, message_type, payload) 
                              VALUES (?, ?, NULL, 'guest-admitted', ?)");
        $sig->execute([$roomId, $member['participant_key'], json_encode(['guest_name' => $guest['display_name'], 'invite_id' => $inviteId])]);
    } catch (Throwable $e) {}

    echo json_encode(['ok' => true, 'action' => 'approved', 'display_name' => $guest['display_name']]);
} elseif ($action === 'reject') {
    $up = $pdo->prepare("UPDATE room_invites SET status='rejected' WHERE id=? AND status='waiting'");
    $up->execute([$inviteId]);
    if ($up->rowCount() !== 1) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'guest_already_processed']);
        exit;
    }

    echo json_encode(['ok' => true, 'action' => 'rejected', 'display_name' => $guest['display_name']]);
} else {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'unknown_action']);
}
