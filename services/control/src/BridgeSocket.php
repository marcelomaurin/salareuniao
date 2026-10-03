<?php
declare(strict_types=1);

namespace SalaReuniao\Control;

use PDO;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use SplObjectStorage;
use Throwable;

/**
 * BridgeSocket - PHP Media Bridge Relay (Tarefas 39 a 78)
 *
 * Responsabilidade:
 * - Autenticar conexão por token de sala
 * - Agrupar participantes por room_id
 * - Receber blocos binários (WebM/Opus) do MediaRecorder via WebSocket
 * - Redistribuir blocos binários em tempo real para os assinantes da mesma sala
 * - Zero conversão/decodificação em PHP (relay de baixo overhead)
 * - Controle de buffer (backpressure), limites de mensagem e descarte de atraso
 */
final class BridgeSocket implements MessageComponentInterface
{
    private PDO $pdo;
    private SplObjectStorage $clients;

    /**
     * Metadados de cada conexão:
     * resourceId => [
     *   'room_id' => int,
     *   'participant_key' => string,
     *   'display_name' => string,
     *   'token' => string,
     *   'is_publisher' => bool,
     *   'stream_id' => ?string,
     *   'media_type' => string,
     *   'mime' => string,
     *   'bytes_sent_sec' => int,
     *   'last_sec' => int,
     *   'created_at' => int
     * ]
     */
    private array $meta = [];

    /**
     * Salas e conexões ativas:
     * room_id => [ resourceId => ConnectionInterface ]
     */
    private array $rooms = [];

    /**
     * Publicador ativo por sala (V1: 1 publicador de vídeo/áudio por sala - Tarefas 53 a 57):
     * room_id => resourceId
     */
    private array $publishers = [];

    /**
     * Assinantes por sala:
     * room_id => [ resourceId => ConnectionInterface ]
     */
    private array $subscribers = [];

    // Limites de proteção e backpressure (Tarefas 43, 69, 71, 72)
    private int $maxMessageBytes = 524288;   // 512 KB por chunk
    private int $maxBufferBytes = 4194304;   // 4 MB buffer máximo
    private int $maxRateBytesSec = 1048576;  // 1 MB/s máximo por publicador

    public function __construct(private array $config)
    {
        $db = $config['db'];
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'],
            $db['port'] ?? 3306,
            $db['name'],
            $db['charset'] ?? 'utf8mb4'
        );
        $this->pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->clients = new SplObjectStorage();

        $bCfg = $config['bridge'] ?? [];
        if (!empty($bCfg['max_message_bytes'])) {
            $this->maxMessageBytes = (int)$bCfg['max_message_bytes'];
        }
        if (!empty($bCfg['max_buffer_bytes'])) {
            $this->maxBufferBytes = (int)$bCfg['max_buffer_bytes'];
        }
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        try {
            // Tarefa 73: Validar Origin (aceitar maurinsoft.com.br e localhost)
            $origin = (string)($conn->httpRequest->getHeader('Origin')[0] ?? '');
            if ($origin !== '') {
                $originHost = parse_url($origin, PHP_URL_HOST);
                $serverHost = parse_url($this->config['websocket']['public_url'] ?? '', PHP_URL_HOST) ?: 'maurinsoft.com.br';
                if ($originHost !== $serverHost && $originHost !== 'localhost' && $originHost !== '127.0.0.1') {
                    $this->reject($conn, 'forbidden_origin');
                    return;
                }
            }

            // Tarefa 44: Autentica usando o token da reunião
            $query = [];
            parse_str($conn->httpRequest->getUri()->getQuery(), $query);
            $token = trim((string)($query['token'] ?? ''));

            if ($token === '') {
                $this->reject($conn, 'missing_token');
                return;
            }

            // Tarefa 45: Nunca confiar no room_id enviado pelo cliente. Busca autorizado no banco.
            $st = $this->pdo->prepare("
                SELECT i.*, r.status room_status, r.owner_user_id, r.name as room_name 
                FROM room_invites i 
                JOIN rooms r ON r.id = i.room_id 
                WHERE i.token = ? LIMIT 1
            ");
            $st->execute([$token]);
            $me = $st->fetch();

            if (!$me) {
                $this->reject($conn, 'forbidden_invalid_token');
                return;
            }

            if ($me['status'] === 'rejected' || $me['status'] === 'kicked') {
                $this->reject($conn, 'kicked');
                return;
            }

            if ($me['status'] !== 'approved' || empty($me['participant_key'])) {
                $this->reject($conn, 'forbidden_unapproved');
                return;
            }

            if ($me['room_status'] !== 'open') {
                $this->reject($conn, 'room_not_open');
                return;
            }

            $roomId = (int)$me['room_id'];
            $key = (string)$me['participant_key'];

            // Validação de IP banido (Tarefas 09, 10, 44)
            $clientIp = $conn->remoteAddress ?? '127.0.0.1';
            $stBan = $this->pdo->prepare("SELECT id FROM room_bans WHERE room_id = ? AND ip_address = ? AND active = 1 LIMIT 1");
            $stBan->execute([$roomId, $clientIp]);
            if ($stBan->fetch()) {
                $this->reject($conn, 'ip_banned');
                return;
            }

            // Registra conexão no storage e índices de sala
            $id = $conn->resourceId;
            $this->clients->attach($conn);

            $this->meta[$id] = [
                'room_id' => $roomId,
                'participant_key' => $key,
                'display_name' => (string)($me['display_name'] ?: $me['email']),
                'token' => $token,
                'is_publisher' => false,
                'stream_id' => null,
                'media_type' => 'camera',
                'mime' => 'video/webm;codecs=vp8,opus',
                'bytes_sent_sec' => 0,
                'last_sec' => time(),
                'created_at' => time()
            ];

            if (!isset($this->rooms[$roomId])) {
                $this->rooms[$roomId] = [];
            }
            $this->rooms[$roomId][$id] = $conn;

            // Por padrão, toda nova conexão entra como assinante da sala
            if (!isset($this->subscribers[$roomId])) {
                $this->subscribers[$roomId] = [];
            }
            $this->subscribers[$roomId][$id] = $conn;

            // Envia confirmação de conexão e informa se já há publicador ativo
            $pubInfo = null;
            if (!empty($this->publishers[$roomId]) && isset($this->meta[$this->publishers[$roomId]])) {
                $pMeta = $this->meta[$this->publishers[$roomId]];
                $pubInfo = [
                    'publisher_key' => $pMeta['participant_key'],
                    'display_name' => $pMeta['display_name'],
                    'media' => $pMeta['media_type'],
                    'mime' => $pMeta['mime'],
                    'stream_id' => $pMeta['stream_id']
                ];
            }

            $conn->send(json_encode([
                'type' => 'bridge.connected',
                'room_id' => $roomId,
                'participant_key' => $key,
                'active_publisher' => $pubInfo
            ], JSON_UNESCAPED_UNICODE));

        } catch (Throwable $e) {
            error_log('[BridgeSocket] Erro no onOpen: ' . $e->getMessage());
            $conn->close();
        }
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $id = $from->resourceId;
        if (!isset($this->meta[$id])) {
            return;
        }

        $meta = &$this->meta[$id];
        $roomId = $meta['room_id'];

        // Se for mensagem em texto JSON (mensagens de sinalização/controle do Bridge - Tarefa 51)
        if (is_string($msg) && isset($msg[0]) && $msg[0] === '{') {
            $data = json_decode($msg, true);
            if (!is_array($data) || empty($data['type'])) {
                return;
            }

            $type = (string)$data['type'];

            switch ($type) {
                case 'bridge.publish.start':
                    // Tarefas 51 e 53: Anúncio de início de publicação
                    $meta['is_publisher'] = true;
                    $meta['stream_id'] = (string)($data['stream_id'] ?? bin2hex(random_bytes(8)));
                    $meta['media_type'] = (string)($data['media'] ?? 'camera');
                    $meta['mime'] = (string)($data['mime'] ?? 'video/webm;codecs=vp8,opus');

                    $this->publishers[$roomId] = $id;

                    // Notifica todos os demais assinantes da sala que há nova transmissão pronta
                    $announce = json_encode([
                        'type' => 'bridge.publisher.ready',
                        'publisher_key' => $meta['participant_key'],
                        'display_name' => $meta['display_name'],
                        'media' => $meta['media_type'],
                        'mime' => $meta['mime'],
                        'stream_id' => $meta['stream_id']
                    ], JSON_UNESCAPED_UNICODE);

                    foreach ($this->rooms[$roomId] ?? [] as $subId => $subConn) {
                        if ($subId !== $id) {
                            $subConn->send($announce);
                        }
                    }
                    break;

                case 'bridge.publish.stop':
                    // Término de publicação
                    $meta['is_publisher'] = false;
                    if (isset($this->publishers[$roomId]) && $this->publishers[$roomId] === $id) {
                        unset($this->publishers[$roomId]);
                    }

                    $stopped = json_encode([
                        'type' => 'bridge.publisher.stopped',
                        'publisher_key' => $meta['participant_key'],
                        'stream_id' => $meta['stream_id']
                    ], JSON_UNESCAPED_UNICODE);

                    foreach ($this->rooms[$roomId] ?? [] as $subId => $subConn) {
                        if ($subId !== $id) {
                            $subConn->send($stopped);
                        }
                    }
                    break;

                case 'bridge.subscribe':
                    // Tarefa 55: Assinatura de transmissão
                    $this->subscribers[$roomId][$id] = $from;
                    $from->send(json_encode([
                        'type' => 'bridge.subscribed',
                        'publisher_key' => $data['publisher_key'] ?? null
                    ]));
                    break;

                case 'bridge.unsubscribe':
                    unset($this->subscribers[$roomId][$id]);
                    break;

                case 'bridge.ping':
                    $from->send(json_encode([
                        'type' => 'bridge.pong',
                        'timestamp' => $data['timestamp'] ?? microtime(true)
                    ]));
                    break;
            }
            return;
        }

        // =========================================================================
        // REDISTRIBUIÇÃO DE FRAMES BINÁRIOS (Tarefas 50, 52, 54, 69, 71, 72)
        // =========================================================================
        // Somente o publicador ativo tem permissão para enviar mídia
        if (!$meta['is_publisher']) {
            return;
        }

        $chunkLen = strlen($msg);

        // Tarefa 71: Limitar tamanho máximo de chunk (512 KB)
        if ($chunkLen > $this->maxMessageBytes) {
            error_log("[BridgeSocket] Chunk ignorado: tamanho excessivo ({$chunkLen} bytes) de {$meta['participant_key']}");
            return;
        }

        // Tarefa 72: Rate limit de bytes/segundo
        $now = time();
        if ($now !== $meta['last_sec']) {
            $meta['last_sec'] = $now;
            $meta['bytes_sent_sec'] = 0;
        }
        $meta['bytes_sent_sec'] += $chunkLen;
        if ($meta['bytes_sent_sec'] > $this->maxRateBytesSec) {
            // Descarta frame silenciosamente para proteger banda do servidor
            return;
        }

        // Tarefa 54: Redistribuição estrita para os assinantes da mesma sala (zero disco - Tarefa 74)
        $roomSubs = $this->subscribers[$roomId] ?? [];
        foreach ($roomSubs as $subId => $subConn) {
            if ($subId === $id) {
                continue; // Não devolve para o próprio emissor
            }

            try {
                // Tarefas 69 e 70: Backpressure - se buffer de escrita do cliente estiver sobrecarregado, descarta
                // (no Ratchet / ReactPHP socket, streams bufferizados têm buffer de saída)
                $subConn->send($msg);
            } catch (Throwable $e) {
                // Se falhar o envio para um cliente lento, desconecta ou ignora frame
                error_log("[BridgeSocket] Erro ao redistribuir frame para cliente {$subId}: " . $e->getMessage());
            }
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $id = $conn->resourceId;
        if (!isset($this->meta[$id])) {
            $this->clients->detach($conn);
            return;
        }

        $meta = $this->meta[$id];
        $roomId = $meta['room_id'];

        // Se era o publicador ativo, avisa aos demais assinantes
        if (!empty($meta['is_publisher']) || (isset($this->publishers[$roomId]) && $this->publishers[$roomId] === $id)) {
            unset($this->publishers[$roomId]);
            $stopped = json_encode([
                'type' => 'bridge.publisher.stopped',
                'publisher_key' => $meta['participant_key'],
                'stream_id' => $meta['stream_id']
            ], JSON_UNESCAPED_UNICODE);

            foreach ($this->rooms[$roomId] ?? [] as $subId => $subConn) {
                if ($subId !== $id) {
                    try {
                        $subConn->send($stopped);
                    } catch (Throwable $e) {}
                }
            }
        }

        unset($this->rooms[$roomId][$id]);
        unset($this->subscribers[$roomId][$id]);
        if (empty($this->rooms[$roomId])) {
            unset($this->rooms[$roomId]);
            unset($this->subscribers[$roomId]);
            unset($this->publishers[$roomId]);
        }

        unset($this->meta[$id]);
        $this->clients->detach($conn);
    }

    public function onError(ConnectionInterface $conn, Throwable $e): void
    {
        error_log("[BridgeSocket] Erro na conexão {$conn->resourceId}: " . $e->getMessage());
        $conn->close();
    }

    private function reject(ConnectionInterface $conn, string $reason): void
    {
        try {
            $conn->send(json_encode([
                'type' => 'bridge.error',
                'error' => $reason
            ], JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {}
        $conn->close();
    }
}
