<?php
declare(strict_types=1);

$configFile=__DIR__.'/../../config.php';
if(!is_file($configFile)){
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'server_not_configured']);
    exit;
}
$config=require $configFile;

$dsn=sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $config['db']['host'],
    $config['db']['port']??3306,
    $config['db']['name'],
    $config['db']['charset']??'utf8mb4'
);
$pdo=new PDO($dsn,$config['db']['user'],$config['db']['pass'],[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function api_json(array $data,int $status=200): never {
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

function api_input(): array {
    $raw=file_get_contents('php://input');
    if($raw===false||trim($raw)==='')return [];
    $data=json_decode($raw,true);
    if(!is_array($data))api_json(['ok'=>false,'error'=>'invalid_json'],400);
    return $data;
}

function api_require_method(string ...$allowed): void {
    if(!in_array($_SERVER['REQUEST_METHOD']??'GET',$allowed,true)){
        header('Allow: '.implode(', ',$allowed));
        api_json(['ok'=>false,'error'=>'method_not_allowed'],405);
    }
}

function api_bearer(): string {
    $header=$_SERVER['HTTP_AUTHORIZATION']??'';
    if($header===''&&function_exists('getallheaders')){
        $headers=getallheaders();
        $header=$headers['Authorization']??$headers['authorization']??'';
    }
    if(!preg_match('/^Bearer\s+(.+)$/i',$header,$m))api_json(['ok'=>false,'error'=>'missing_bearer_token'],401);
    return trim($m[1]);
}

function api_user(PDO $pdo): array {
    $raw=api_bearer();
    $hash=hash('sha256',$raw);
    $q=$pdo->prepare("SELECT u.id,u.name,u.email,u.role,u.active,t.id token_id
        FROM api_tokens t JOIN users u ON u.id=t.user_id
        WHERE t.token_hash=? AND t.revoked_at IS NULL
          AND (t.expires_at IS NULL OR t.expires_at>NOW())
          AND u.active=1
        LIMIT 1");
    $q->execute([$hash]);$user=$q->fetch();
    if(!$user)api_json(['ok'=>false,'error'=>'invalid_or_expired_token'],401);
    $pdo->prepare('UPDATE api_tokens SET last_used_at=NOW() WHERE id=?')->execute([$user['token_id']]);
    return $user;
}

function api_admin(PDO $pdo): array {
    $u=api_user($pdo);
    if($u['role']!=='admin')api_json(['ok'=>false,'error'=>'admin_required'],403);
    return $u;
}

function api_device(PDO $pdo): array {
    $raw=api_bearer();
    $hash=hash('sha256',$raw);
    $q=$pdo->prepare("SELECT d.*,t.id token_id
        FROM device_tokens t JOIN devices d ON d.id=t.device_id
        WHERE t.token_hash=? AND t.revoked_at IS NULL
          AND (t.expires_at IS NULL OR t.expires_at>NOW())
          AND d.active=1
        LIMIT 1");
    $q->execute([$hash]);$device=$q->fetch();
    if(!$device)api_json(['ok'=>false,'error'=>'invalid_or_expired_device_token'],401);
    return $device;
}

function api_client_ip(): string {
    return substr((string)($_SERVER['REMOTE_ADDR']??''),0,64);
}
