<?php
declare(strict_types=1);

namespace SalaReuniao\Signaling;

use PDO;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use SplObjectStorage;
use Throwable;

final class MeetingSocket implements MessageComponentInterface
{
    private PDO $pdo;
    private SplObjectStorage $clients;
    /** @var array<int,array{room_id:int,participant_key:string,display_name:string,token:string}> */
    private array $meta=[];
    /** @var array<int,array<string,ConnectionInterface>> */
    private array $rooms=[];

    public function __construct(private array $config)
    {
        $db=$config['db'];
        $dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'],$db['port']??3306,$db['name'],$db['charset']??'utf8mb4');
        $this->pdo=new PDO($dsn,$db['user'],$db['pass'],[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
        $this->clients=new SplObjectStorage();
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        try{
            $query=[];
            parse_str($conn->httpRequest->getUri()->getQuery(),$query);
            $token=(string)($query['token']??'');
            if($token===''){ $this->reject($conn,'missing_token'); return; }

            $st=$this->pdo->prepare("SELECT i.*,r.status room_status
                FROM room_invites i JOIN rooms r ON r.id=i.room_id
                WHERE i.token=? AND i.status='approved' LIMIT 1");
            $st->execute([$token]);
            $me=$st->fetch();

            if(!$me||empty($me['participant_key'])){ $this->reject($conn,'forbidden'); return; }
            if($me['room_status']!=='open'){ $this->reject($conn,'room_not_open'); return; }

            $roomId=(int)$me['room_id'];
            $key=(string)$me['participant_key'];
            $name=trim((string)($me['display_name']?:$me['email']));

            $this->clients->attach($conn);
            $this->meta[$conn->resourceId]=[
                'room_id'=>$roomId,
                'participant_key'=>$key,
                'display_name'=>$name,
                'token'=>$token,
            ];
            $this->rooms[$roomId][$key]=$conn;

            $this->touchPresence($roomId,$key,$name,true,true,false);
            $conn->send(json_encode([
                'type'=>'hello','self'=>$key,'roomId'=>$roomId,'displayName'=>$name
            ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

            $this->broadcastPresence($roomId);
        }catch(Throwable $e){
            $this->reject($conn,'server_error');
        }
    }

    public function onMessage(ConnectionInterface $from,$msg): void
    {
        $meta=$this->meta[$from->resourceId]??null;
        if(!$meta){$from->close();return;}

        try{
            if(!$this->sessionStillAllowed($meta)){
                $from->send(json_encode(['type'=>'session-ended','reason'=>'authorization_or_room_closed']));
                $from->close();
                return;
            }

            $data=json_decode((string)$msg,true,512,JSON_THROW_ON_ERROR);
            $type=(string)($data['type']??'');

            if($type==='signal'){
                $this->handleSignal($from,$meta,$data);
            }elseif($type==='chat'){
                $this->handleChat($meta,$data);
            }elseif($type==='presence'){
                $this->touchPresence(
                    $meta['room_id'],$meta['participant_key'],$meta['display_name'],
                    !empty($data['mic']),!empty($data['cam']),!empty($data['screen'])
                );
                $this->broadcastPresence($meta['room_id']);
            }elseif($type==='lobby-refresh'){
                $this->broadcastPresence($meta['room_id']);
            }elseif($type==='ping'){
                $from->send('{"type":"pong"}');
            }
        }catch(Throwable $e){
            $from->send(json_encode(['type'=>'error','error'=>'invalid_message']));
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $meta=$this->meta[$conn->resourceId]??null;
        if($meta){
            $this->closeAttendance($meta['room_id'],$meta['participant_key']);
            $this->pdo->prepare('DELETE FROM room_presence WHERE room_id=? AND participant_key=?')
                ->execute([$meta['room_id'],$meta['participant_key']]);

            unset($this->rooms[$meta['room_id']][$meta['participant_key']],$this->meta[$conn->resourceId]);
            if(empty($this->rooms[$meta['room_id']]))unset($this->rooms[$meta['room_id']]);

            $this->broadcast($meta['room_id'],[
                'type'=>'signal',
                'message'=>[
                    'sender_key'=>$meta['participant_key'],
                    'message_type'=>'leave',
                    'payload'=>new \stdClass(),
                ],
            ],$meta['participant_key']);
            $this->broadcastPresence($meta['room_id']);
        }
        if($this->clients->contains($conn))$this->clients->detach($conn);
    }

    public function onError(ConnectionInterface $conn,\Exception $e): void
    {
        $conn->close();
    }

    private function handleSignal(ConnectionInterface $from,array $meta,array $data): void
    {
        $signalType=(string)($data['signalType']??'');
        if(!in_array($signalType,['offer','answer','ice','leave','peer-ready'],true))return;

        $recipient=!empty($data['recipient'])?(string)$data['recipient']:null;
        $payload=$data['payload']??new \stdClass();

        $q=$this->pdo->prepare('INSERT INTO signaling_messages(room_id,sender_key,recipient_key,message_type,payload) VALUES(?,?,?,?,?)');
        $q->execute([$meta['room_id'],$meta['participant_key'],$recipient,$signalType,
            json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);

        $message=[
            'id'=>(int)$this->pdo->lastInsertId(),
            'sender_key'=>$meta['participant_key'],
            'recipient_key'=>$recipient,
            'message_type'=>$signalType,
            'payload'=>$payload,
            'created_at'=>date('Y-m-d H:i:s'),
        ];

        if($recipient){
            $target=$this->rooms[$meta['room_id']][$recipient]??null;
            if($target)$target->send(json_encode(['type'=>'signal','message'=>$message],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        }else{
            $this->broadcast($meta['room_id'],['type'=>'signal','message'=>$message],$meta['participant_key']);
        }
    }

    private function handleChat(array $meta,array $data): void
    {
        $message=trim((string)($data['message']??''));
        if($message===''||mb_strlen($message)>2000)return;

        $q=$this->pdo->prepare('INSERT INTO room_messages(room_id,participant_key,display_name,message) VALUES(?,?,?,?)');
        $q->execute([$meta['room_id'],$meta['participant_key'],$meta['display_name'],$message]);

        $saved=[
            'id'=>(int)$this->pdo->lastInsertId(),
            'participant_key'=>$meta['participant_key'],
            'display_name'=>$meta['display_name'],
            'message'=>$message,
            'created_at'=>date('Y-m-d H:i:s'),
        ];
        $this->broadcast($meta['room_id'],['type'=>'chat','message'=>$saved]);
    }

    private function touchPresence(int $roomId,string $key,string $name,bool $mic,bool $cam,bool $screen): void
    {
        $existing=$this->pdo->prepare('SELECT 1 FROM room_presence WHERE room_id=? AND participant_key=? LIMIT 1');
        $existing->execute([$roomId,$key]);
        if(!$existing->fetchColumn()){
            $att=$this->pdo->prepare('INSERT INTO room_attendance(room_id,participant_key,display_name,joined_at) VALUES(?,?,?,NOW())');
            $att->execute([$roomId,$key,$name]);
        }

        $q=$this->pdo->prepare("INSERT INTO room_presence(room_id,participant_key,display_name,mic_enabled,cam_enabled,screen_sharing,joined_at,last_seen_at)
            VALUES(?,?,?,?,?,?,NOW(),NOW())
            ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),mic_enabled=VALUES(mic_enabled),
            cam_enabled=VALUES(cam_enabled),screen_sharing=VALUES(screen_sharing),last_seen_at=NOW()");
        $q->execute([$roomId,$key,$name,$mic?1:0,$cam?1:0,$screen?1:0]);
    }

    private function sessionStillAllowed(array $meta): bool
    {
        $q=$this->pdo->prepare("SELECT 1
            FROM room_invites i JOIN rooms r ON r.id=i.room_id
            WHERE i.room_id=? AND i.participant_key=? AND i.status='approved' AND r.status='open'
            LIMIT 1");
        $q->execute([$meta['room_id'],$meta['participant_key']]);
        return (bool)$q->fetchColumn();
    }

    private function closeAttendance(int $roomId,string $key): void
    {
        $left=date('Y-m-d H:i:s');
        $q=$this->pdo->prepare("UPDATE room_attendance
            SET left_at=?,duration_seconds=TIMESTAMPDIFF(SECOND,joined_at,?)
            WHERE room_id=? AND participant_key=? AND left_at IS NULL
            ORDER BY id DESC LIMIT 1");
        $q->execute([$left,$left,$roomId,$key]);
    }

    private function broadcastPresence(int $roomId): void
    {
        $q=$this->pdo->prepare("SELECT participant_key,display_name,mic_enabled,cam_enabled,screen_sharing,joined_at,last_seen_at
            FROM room_presence WHERE room_id=? AND last_seen_at>=DATE_SUB(NOW(),INTERVAL 20 SECOND) ORDER BY joined_at");
        $q->execute([$roomId]);
        $participants=$q->fetchAll();

        $wq=$this->pdo->prepare("SELECT id, display_name, email, requested_at
            FROM room_invites WHERE room_id=? AND status='waiting' ORDER BY requested_at ASC");
        $wq->execute([$roomId]);
        $waiting=$wq->fetchAll();

        $this->broadcast($roomId,[
            'type'=>'presence',
            'participants'=>$participants,
            'waiting'=>$waiting,
        ]);
    }

    private function broadcast(int $roomId,array $data,?string $exceptKey=null): void
    {
        $json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        foreach($this->rooms[$roomId]??[] as $key=>$conn){
            if($exceptKey!==null&&$key===$exceptKey)continue;
            $conn->send($json);
        }
    }

    private function reject(ConnectionInterface $conn,string $reason): void
    {
        $conn->send(json_encode(['type'=>'error','error'=>$reason]));
        $conn->close();
    }
}
