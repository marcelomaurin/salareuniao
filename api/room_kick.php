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

// 2. Busca dados da sala e do anfitrião/admin
$stAdmin = $pdo->prepare("SELECT i.*, r.owner_user_id, r.status as room_status, r.name as room_name 
                          FROM room_invites i 
                          JOIN rooms r ON r.id = i.room_id 
                          WHERE i.token = ? LIMIT 1");
$stAdmin->execute([$adminToken]);
$admin = $stAdmin->fetch();

if (!$admin || $admin['room_status'] !== 'open') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'unauthorized_or_room_closed']);
    exit;
}

$roomId = (int)$admin['room_id'];

// 3. Localiza o participante alvo
// Tenta primeiro em room_invites
$stTarget = $pdo->prepare("SELECT i.*, u.id as user_id 
                           FROM room_invites i 
                           LEFT JOIN users u ON LOWER(u.email) = LOWER(i.email)
                           WHERE i.room_id = ? AND i.participant_key = ? LIMIT 1");
$stTarget->execute([$roomId, $targetKey]);
$target = $stTarget->fetch();

$targetName = $target['display_name'] ?? '';

// Se não achou em room_invites, procura na presença ativa
if (!$target) {
    $stP = $pdo->prepare("SELECT participant_key, display_name FROM room_presence WHERE room_id = ? AND participant_key = ? LIMIT 1");
    $stP->execute([$roomId, $targetKey]);
    $pRow = $stP->fetch();
    if ($pRow) {
        $targetName = $pRow['display_name'];
    }
} else {
    // Não permite expulsar o proprietário da sala se este for conhecido
    if (!empty($target['user_id']) && (int)$target['user_id'] === (int)$admin['owner_user_id'] && (int)$admin['owner_user_id'] > 0) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'cannot_kick_room_owner']);
        exit;
    }
}

if (!$target && empty($targetName)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'participant_not_found']);
    exit;
}

// 4. Marca o convite do participante como rejeitado / expulso
if ($target && !empty($target['id'])) {
    $pdo->prepare("UPDATE room_invites SET status = 'rejected' WHERE id = ?")->execute([$target['id']]);
}
$pdo->prepare("UPDATE room_invites SET status = 'rejected' WHERE room_id = ? AND participant_key = ?")->execute([$roomId, $targetKey]);

// 5. Remove presença imediata e finaliza registro de frequência
$pdo->prepare("DELETE FROM room_presence WHERE room_id = ? AND participant_key = ?")->execute([$roomId, $targetKey]);
try {
    $pdo->prepare("UPDATE room_attendance SET left_at = NOW(), duration_seconds = TIMESTAMPDIFF(SECOND, joined_at, NOW()) 
                   WHERE room_id = ? AND participant_key = ? AND left_at IS NULL ORDER BY id DESC LIMIT 1")->execute([$roomId, $targetKey]);
} catch (Throwable $e) {}

// 6. Envia sinal de expulsão via sinalização para desconexão imediata
// ATENÇÃO: signaling_messages.message_type é ENUM('offer','answer','ice','leave','peer-ready')
// Usamos 'leave' para total compatibilidade com o ENUM do MySQL, com payload kicked=true
try {
    $sig = $pdo->prepare("INSERT INTO signaling_messages (room_id, sender_key, recipient_key, message_type, payload, created_at) 
                          VALUES (?, ?, ?, 'leave', ?, NOW())");
    $sig->execute([
        $roomId,
        $admin['participant_key'],
        $targetKey,
        json_encode([
            'kicked' => true,
            'action' => 'kicked',
            'reason' => 'expelled_by_admin',
            'target_key' => $targetKey,
            'target_name' => $targetName,
            'room_name' => $admin['room_name']
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    ]);
} catch (Throwable $e) {
    error_log('signaling kick error: ' . $e->getMessage());
}

audit_log('room.kick_participant', 'room', $roomId, [
    'target_key' => $targetKey,
    'target_name' => $targetName,
    'admin_key' => $admin['participant_key']
]);

echo json_encode([
    'ok' => true,
    'kicked' => true,
    'target_key' => $targetKey,
    'display_name' => $targetName
], JSON_UNESCAPED_UNICODE);
