<?php
require __DIR__ . '/lib/bootstrap.php';
$user = require_login();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $starts = trim($_POST['starts_at'] ?? '') ?: null;
    $emails = preg_split('/[\s,;]+/', trim($_POST['emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);

    if ($name === '') {
        $error = 'Informe o nome da sala.';
    } else {
        $pdo->beginTransaction();
        $st = $pdo->prepare('INSERT INTO rooms(owner_user_id,name,description,starts_at,status) VALUES(?,?,?,?,?)');
        $st->execute([$user['id'],$name,$description,$starts,'scheduled']);
        $roomId = (int)$pdo->lastInsertId();

        $inviteStmt = $pdo->prepare('INSERT INTO room_invites(room_id,email,token) VALUES(?,?,?)');
        foreach (array_unique($emails) as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            $token = bin2hex(random_bytes(32));
            $inviteStmt->execute([$roomId,strtolower($email),$token]);
            $link = rtrim($config['app']['base_url'],'/') . '/join.php?token=' . urlencode($token);
            $subject = 'Convite para ' . $name;
            $body = "Você foi convidado para a sala: {$name}\n\nAcesse: {$link}\n";
            @mail($email, $subject, $body, 'From: '.$config['mail']['from']);
        }
        $pdo->commit();
        header('Location: room_manage.php?id='.$roomId);
        exit;
    }
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Criar sala</title></head><body>
<h1>Criar sala</h1>
<?php if ($error): ?><p><?=e($error)?></p><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<label>Nome <input name="name" required></label><br>
<label>Descrição <textarea name="description"></textarea></label><br>
<label>Início <input type="datetime-local" name="starts_at"></label><br>
<label>Convidados (e-mails separados por vírgula, espaço ou ;)<br><textarea name="emails" rows="8" cols="60"></textarea></label><br>
<button>Criar e enviar convites</button>
</form>
</body></html>
