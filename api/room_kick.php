<?php
require __DIR__ . '/../lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['ok' => true, 'service' => 'Maurinsoft Room Kick API']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$adminToken = trim((string)($input['token'] ?? ''));
$targetKey = trim((string)($input['target_key'] ?? ''));

if ($adminToken === '' || $targetKey === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_parameters']);
    exit;
}

// 1. Valida se o solicitante é administrador da sala
if (!is_token_room_admin($pdo, $adminToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'only_administrators_can_kick']);
    exit;
}

// 2. Busca dados do anfitrião/admin
$stAdmin = $pdo->prepare("SELECT i.*, r.owner_user_id, r.status as room_status, r.name as room_name 
                          FROM room_invites i 
                          JOIN rooms r ON r.id = i.room_id 
                          WHERE i.token = ? AND i.status = 'approved' LIMIT 1");
$stAdmin->execute([$adminToken]);
$admin = $stAdmin->fetch();

if (!$admin || $admin['room_status'] !== 'open') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'unauthorized_or_room_closed']);
    exit;
}

$roomId = (int)$admin['room_id'];

// 3. Localiza o participante alvo
$stTarget = $pdo->prepare("SELECT i.*, u.id as user_id 
                           FROM room_invites i 
                           LEFT JOIN users u ON LOWER(u.email) = LOWER(i.email)
                           WHERE i.room_id = ? AND i.participant_key = ? LIMIT 1");
$stTarget->execute([$roomId, $targetKey]);
$target = $stTarget->fetch();

if (!$target) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'participant_not_found']);
    exit;
}

// Não permite expulsar o proprietário da sala
if (!empty($target['user_id']) && (int)$target['user_id'] === (int)$admin['owner_user_id']) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'cannot_kick_room_owner']);
    exit;
}

// 4. Marca o convite do participante como rejeitado / expulso
$pdo->prepare("UPDATE room_invites SET status = 'rejected' WHERE id = ?")->execute([$target['id']]);

// 5. Remove presença imediata e finaliza registro de frequência
$pdo->prepare("DELETE FROM room_presence WHERE room_id = ? AND participant_key = ?")->execute([$roomId, $targetKey]);
$pdo->prepare("UPDATE room_attendance SET left_at = NOW(), duration_seconds = TIMESTAMPDIFF(SECOND, joined_at, NOW()) 
               WHERE room_id = ? AND participant_key = ? AND left_at IS NULL ORDER BY id DESC LIMIT 1")->execute([$roomId, $targetKey]);

// 6. Envia sinal de expulsão via sinalização para desconexão imediata
try {
    $sig = $pdo->prepare("INSERT INTO signaling_messages (room_id, sender_key, recipient_key, message_type, payload, created_at) 
                          VALUES (?, ?, ?, 'kicked', ?, NOW())");
    $sig->execute([
        $roomId,
        $admin['participant_key'],
        $targetKey,
        json_encode([
            'kicked' => true,
            'reason' => 'expelled_by_admin',
            'target_key' => $targetKey,
            'target_name' => $target['display_name']
        ])
    ]);
} catch (Throwable $e) {}

audit_log('room.kick_participant', 'room', $roomId, [
    'target_key' => $targetKey,
    'target_name' => $target['display_name'],
    'admin_key' => $admin['participant_key']
]);

echo json_encode([
    'ok' => true,
    'kicked' => true,
    'target_key' => $targetKey,
    'display_name' => $target['display_name']
]);
