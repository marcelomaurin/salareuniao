<?php
declare(strict_types=1);

namespace SalaReuniao\Control;

use PDO;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use SplObjectStorage;
use Throwable;

final class ControlSocket implements MessageComponentInterface
{
    private PDO $pdo;
    private SplObjectStorage $clients;
    /** @var array<int,array{room_id:int,participant_key:string,display_name:string,token:string,is_admin:bool,user_id:?int}> */
    private array $meta = [];
    /** @var array<int,array<string,ConnectionInterface>> */
    private array $rooms = [];

    public function __construct(private array $config)
    {
        $db = $config['db'];
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'], $db['port'] ?? 3306, $db['name'], $db['charset'] ?? 'utf8mb4');
        $this->pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->clients = new SplObjectStorage();
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        try {
            $query = [];
            parse_str($conn->httpRequest->getUri()->getQuery(), $query);
            $token = (string)($query['token'] ?? '');
            if ($token === '') {
                $this->reject($conn, 'missing_token');
                return;
            }

            $st = $this->pdo->prepare("
                SELECT i.*, r.status room_status, r.owner_user_id, r.name as room_name, u.id as user_id, u.role as user_role, u.active as user_active
                FROM room_invites i 
                JOIN rooms r ON r.id = i.room_id
                LEFT JOIN users u ON LOWER(u.email) = LOWER(i.email)
                WHERE i.token = ? LIMIT 1
            ");
            $st->execute([$token]);
            $me = $st->fetch();

            if (!$me) {
                $this->reject($conn, 'forbidden');
                return;
            }

            if ($me['status'] === 'rejected' || $me['status'] === 'kicked') {
                $this->reject($conn, 'kicked');
                return;
            }

            if ($me['status'] !== 'approved' || empty($me['participant_key'])) {
                $this->reject($conn, 'forbidden');
                return;
            }

            if ($me['room_status'] !== 'open') {
                $this->reject($conn, 'room_not_open');
                return;
            }

            $roomId = (int)$me['room_id'];
            $key = (string)$me['participant_key'];
            $name = trim((string)($me['display_name'] ?: $me['email']));
            $userId = !empty($me['user_id']) ? (int)$me['user_id'] : null;

            // Determina autoridade administrativa no servidor (Tarefa 31)
            $isAdmin = false;
            if (!empty($me['user_role']) && $me['user_role'] === 'admin' && !empty($me['user_active'])) {
                $isAdmin = true;
            } elseif ($userId !== null && $userId === (int)$me['owner_user_id']) {
                $isAdmin = true;
            } else {
                $stAdm = $this->pdo->prepare("SELECT 1 FROM room_admins WHERE room_id = ? AND user_id = ? LIMIT 1");
                $stAdm->execute([$roomId, $userId]);
                if ($stAdm->fetchColumn()) {
                    $isAdmin = true;
                }
            }

            $this->clients->attach($conn);
            $this->meta[$conn->resourceId] = [
                'room_id' => $roomId,
                'participant_key' => $key,
                'display_name' => $name,
                'token' => $token,
                'is_admin' => $isAdmin,
                'user_id' => $userId,
            ];

            $this->rooms[$roomId][$key] = $conn;

            // Envia estado inicial da sala e permissões ao conectar (Tarefa 24)
            $rState = $this->getRoomState($roomId);
            $pState = $this->getParticipantPermissions($roomId, $key);

            $conn->send(json_encode([
                'type' => 'state.sync',
                'room' => $rState,
                'participant' => $pState,
                'is_admin' => $isAdmin,
                'server_time' => date('Y-m-d H:i:s')
            ], JSON_UNESCAPED_UNICODE));

        } catch (Throwable $e) {
            $this->reject($conn, 'server_error: ' . $e->getMessage());
        }
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $rid = $from->resourceId;
        if (!isset($this->meta[$rid])) {
            return;
        }

        $sender = $this->meta[$rid];
        $roomId = $sender['room_id'];
        $senderKey = $sender['participant_key'];
        $isAdmin = $sender['is_admin'];

        $data = json_decode((string)$msg, true);
        if (!is_array($data) || empty($data['type'])) {
            return;
        }

        $type = (string)$data['type'];

        // 1. Sincronização de estado solicitada pelo cliente (Tarefa 24)
        if ($type === 'state.sync.request') {
            $rState = $this->getRoomState($roomId);
            $pState = $this->getParticipantPermissions($roomId, $senderKey);
            $from->send(json_encode([
                'type' => 'state.sync',
                'room' => $rState,
                'participant' => $pState,
                'is_admin' => $isAdmin
            ], JSON_UNESCAPED_UNICODE));
            return;
        }

        // 2. ACK de comando enviado pelo cliente (Tarefa 10)
        if ($type === 'command.ack') {
            $cmdId = (string)($data['command_id'] ?? '');
            $status = (string)($data['status'] ?? 'applied');
            if ($cmdId !== '') {
                try {
                    $st = $this->pdo->prepare("UPDATE room_control_audit SET status = ?, result_ack = ? WHERE command_id = ? LIMIT 1");
                    $st->execute([
                        substr($status, 0, 30),
                        json_encode($data, JSON_UNESCAPED_UNICODE),
                        $cmdId
                    ]);
                } catch (Throwable $e) {}
            }
            return;
        }

        // 3. Comandos Administrativos (Tarefas 09, 31, 32, 34)
        if ($type === 'command') {
            $cmdId = (string)($data['command_id'] ?? bin2hex(random_bytes(16)));
            $command = (string)($data['command'] ?? '');
            $targetKey = (string)($data['target_key'] ?? '');
            $payload = (array)($data['payload'] ?? []);

            // Autorização estrita server-side (Tarefa 31)
            if (!$isAdmin) {
                $from->send(json_encode([
                    'type' => 'command.ack',
                    'command_id' => $cmdId,
                    'status' => 'failed',
                    'error' => 'forbidden_admin_required'
                ]));
                return;
            }

            // Validação de sala alvo (Tarefa 32)
            if (!empty($data['room_id']) && (int)$data['room_id'] !== $roomId) {
                $from->send(json_encode([
                    'type' => 'command.ack',
                    'command_id' => $cmdId,
                    'status' => 'failed',
                    'error' => 'cross_room_forbidden'
                ]));
                return;
            }

            // Idempotência (Tarefa 34): se o comando já foi aplicado, responde ACK sem reprocessar
            try {
                $checkCmd = $this->pdo->prepare("SELECT id, status FROM room_control_audit WHERE command_id = ? LIMIT 1");
                $checkCmd->execute([$cmdId]);
                if ($existingCmd = $checkCmd->fetch()) {
                    $from->send(json_encode([
                        'type' => 'command.ack',
                        'command_id' => $cmdId,
                        'status' => $existingCmd['status'],
                        'idempotent' => true
                    ]));
                    return;
                }
            } catch (Throwable $e) {}

            // Execução autoritativa do comando
            $applied = $this->applyCommand($roomId, $sender, $command, $targetKey, $payload, $cmdId);

            if ($applied['ok']) {
                // Registra em auditoria
                $this->logCommand($roomId, $sender['user_id'], $targetKey ?: null, $command, $cmdId, $payload, 'applied');

                // Envia ACK de sucesso para o chamador
                $from->send(json_encode([
                    'type' => 'command.ack',
                    'command_id' => $cmdId,
                    'status' => 'applied',
                    'state_version' => $applied['state_version'] ?? null
                ]));

                // Broadcast do evento/comando para os clientes relevantes da sala
                $broadcastMsg = json_encode([
                    'type' => 'command',
                    'protocol' => 1,
                    'command_id' => $cmdId,
                    'command' => $command,
                    'room_id' => $roomId,
                    'target_key' => $targetKey,
                    'payload' => $payload,
                    'state_version' => $applied['state_version'] ?? null,
                    'room_state' => $applied['room_state'] ?? null
                ], JSON_UNESCAPED_UNICODE);

                $this->broadcastRoom($roomId, $broadcastMsg);

            } else {
                $this->logCommand($roomId, $sender['user_id'], $targetKey ?: null, $command, $cmdId, $payload, 'failed');
                $from->send(json_encode([
                    'type' => 'command.ack',
                    'command_id' => $cmdId,
                    'status' => 'failed',
                    'error' => $applied['error'] ?? 'unknown_error'
                ]));
            }
        }
    }

    private function applyCommand(int $roomId, array $sender, string $command, string $targetKey, array $payload, string $cmdId): array
    {
        try {
            switch ($command) {
                // Controle de Vídeo (Tarefas 11, 26)
                case 'participant.video.allow':
                    $this->pdo->prepare("UPDATE room_presence SET video_admin_allowed = 1 WHERE room_id = ? AND participant_key = ?")
                        ->execute([$roomId, $targetKey]);
                    return ['ok' => true];

                case 'participant.video.inhibit':
                    $this->pdo->prepare("UPDATE room_presence SET video_admin_allowed = 0 WHERE room_id = ? AND participant_key = ?")
                        ->execute([$roomId, $targetKey]);
                    return ['ok' => true];

                // Controle de Áudio (Tarefas 12, 13, 37, 38)
                case 'participant.audio.allow':
                    $this->pdo->prepare("UPDATE room_presence SET audio_admin_allowed = 1 WHERE room_id = ? AND participant_key = ?")
                        ->execute([$roomId, $targetKey]);
                    return ['ok' => true];

                case 'participant.audio.inhibit':
                    $this->pdo->prepare("UPDATE room_presence SET audio_admin_allowed = 0 WHERE room_id = ? AND participant_key = ?")
                        ->execute([$roomId, $targetKey]);
                    return ['ok' => true];

                // Controle de Tela (Tarefas 27, 28)
                case 'participant.screen.allow':
                    $this->pdo->prepare("UPDATE room_presence SET screen_admin_allowed = 1 WHERE room_id = ? AND participant_key = ?")
                        ->execute([$roomId, $targetKey]);
                    return ['ok' => true];

                case 'participant.screen.inhibit':
                    $this->pdo->prepare("UPDATE room_presence SET screen_admin_allowed = 0 WHERE room_id = ? AND participant_key = ?")
                        ->execute([$roomId, $targetKey]);
                    return ['ok' => true];

                // Expulsão Autoritativa (Tarefas 29, 30)
                case 'participant.kick':
                    $this->pdo->prepare("UPDATE room_invites SET status = 'rejected' WHERE room_id = ? AND participant_key = ?")
                        ->execute([$roomId, $targetKey]);
                    $this->pdo->prepare("DELETE FROM room_presence WHERE room_id = ? AND participant_key = ?")
                        ->execute([$roomId, $targetKey]);
                    return ['ok' => true];

                // Modo Apresentação / Full (Tarefas 07, 14, 15, 23, 25)
                case 'room.presentation.start':
                    $mediaType = in_array(($payload['media_type'] ?? ''), ['camera', 'screen'], true) ? $payload['media_type'] : 'camera';
                    $this->pdo->prepare("
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

                    $this->pdo->prepare("UPDATE room_presence SET video_granted = 1, video_admin_allowed = 1, hand_raised = 0, hand_requested_at = NULL WHERE room_id = ? AND participant_key = ?")
                        ->execute([$roomId, $targetKey]);

                    $rState = $this->getRoomState($roomId);
                    return ['ok' => true, 'state_version' => $rState['state_version'], 'room_state' => $rState];

                case 'room.presentation.end':
                    $this->pdo->prepare("
                        UPDATE room_runtime_state 
                        SET room_mode = 'normal',
                            active_presenter_key = NULL,
                            presentation_media_type = NULL,
                            presentation_started_at = NULL,
                            state_version = state_version + 1,
                            updated_at = NOW()
                        WHERE room_id = ?
                    ")->execute([$roomId]);

                    $rState = $this->getRoomState($roomId);
                    return ['ok' => true, 'state_version' => $rState['state_version'], 'room_state' => $rState];

                case 'room.close':
                    $this->pdo->prepare("UPDATE rooms SET status = 'closed', updated_at = NOW() WHERE id = ?")->execute([$roomId]);
                    return ['ok' => true];

                default:
                    return ['ok' => false, 'error' => 'unsupported_command'];
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function getRoomState(int $roomId): array
    {
        try {
            $st = $this->pdo->prepare("SELECT room_id, room_mode, active_presenter_key, presentation_media_type, presentation_started_at, state_version FROM room_runtime_state WHERE room_id = ? LIMIT 1");
            $st->execute([$roomId]);
            $row = $st->fetch();
            if ($row) {
                return [
                    'room_id' => (int)$row['room_id'],
                    'room_mode' => (string)($row['room_mode'] ?: 'normal'),
                    'active_presenter_key' => $row['active_presenter_key'] ?: null,
                    'presentation_media_type' => $row['presentation_media_type'] ?: null,
                    'presentation_started_at' => $row['presentation_started_at'] ?: null,
                    'state_version' => (int)($row['state_version'] ?? 1),
                ];
            }
        } catch (Throwable $e) {}

        return [
            'room_id' => $roomId,
            'room_mode' => 'normal',
            'active_presenter_key' => null,
            'presentation_media_type' => null,
            'presentation_started_at' => null,
            'state_version' => 1
        ];
    }

    private function getParticipantPermissions(int $roomId, string $participantKey): array
    {
        try {
            $st = $this->pdo->prepare("SELECT video_admin_allowed, audio_admin_allowed, screen_admin_allowed, video_granted, hand_raised FROM room_presence WHERE room_id = ? AND participant_key = ? LIMIT 1");
            $st->execute([$roomId, $participantKey]);
            if ($row = $st->fetch()) {
                return [
                    'video_allowed' => (bool)$row['video_admin_allowed'],
                    'audio_allowed' => (bool)$row['audio_admin_allowed'],
                    'screen_allowed' => (bool)$row['screen_admin_allowed'],
                    'video_granted' => (bool)$row['video_granted'],
                    'hand_raised' => (bool)$row['hand_raised'],
                ];
            }
        } catch (Throwable $e) {}

        return [
            'video_allowed' => true,
            'audio_allowed' => true,
            'screen_allowed' => false,
            'video_granted' => false,
            'hand_raised' => false,
        ];
    }

    private function logCommand(int $roomId, ?int $userId, ?string $participantKey, string $command, string $commandId, array $payload, string $status): void
    {
        try {
            $st = $this->pdo->prepare("
                INSERT INTO room_control_audit (room_id, admin_user_id, participant_key, command_id, command, payload, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $st->execute([
                $roomId,
                $userId,
                $participantKey,
                $commandId,
                substr($command, 0, 80),
                !empty($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
                substr($status, 0, 30)
            ]);
        } catch (Throwable $e) {}
    }

    private function broadcastRoom(int $roomId, string $msg): void
    {
        if (empty($this->rooms[$roomId])) {
            return;
        }
        foreach ($this->rooms[$roomId] as $conn) {
            try {
                $conn->send($msg);
            } catch (Throwable $e) {}
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $rid = $conn->resourceId;
        if (isset($this->meta[$rid])) {
            $roomId = $this->meta[$rid]['room_id'];
            $key = $this->meta[$rid]['participant_key'];
            unset($this->rooms[$roomId][$key]);
            if (empty($this->rooms[$roomId])) {
                unset($this->rooms[$roomId]);
            }
            unset($this->meta[$rid]);
        }
        $this->clients->detach($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        $conn->close();
    }

    private function reject(ConnectionInterface $conn, string $reason): void
    {
        try {
            $conn->send(json_encode(['type' => 'error', 'error' => $reason]));
        } catch (Throwable $e) {}
        $conn->close();
    }
}
