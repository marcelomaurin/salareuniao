<?php
declare(strict_types=1);

/**
 * Envia os e-mails de convite criados de dentro da sala (admin.invite no
 * bcastd). O serviço em C apenas grava em broadcast_outbox; este script usa o
 * mesmo PHPMailer/SMTP do restante do site.
 *
 * Agende no cron (a cada minuto):
 *   * * * * * php /var/www/salareuniao/bin/broadcast_outbox.php >> /var/log/salareuniao-outbox.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("somente linha de comando\n");
}
define('IS_API', true);
require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/invitations.php';

$pdo = get_pdo();
$rows = $pdo->query("SELECT o.id, o.invite_id, o.attempts, i.email, i.token, i.status,
                            r.id AS room_id, r.name, r.description, r.starts_at
                     FROM broadcast_outbox o
                     JOIN room_invites i ON i.id = o.invite_id
                     JOIN rooms r ON r.id = i.room_id
                     WHERE o.sent_at IS NULL AND o.kind = 'invite' AND o.attempts < 5
                     ORDER BY o.id LIMIT 50")->fetchAll();

$sent = 0;
foreach ($rows as $row) {
    if ($row['status'] === 'revoked' || $row['email'] === '') {
        $pdo->prepare('UPDATE broadcast_outbox SET sent_at = NOW(), last_error = ? WHERE id = ?')
            ->execute(['convite cancelado ou sem e-mail', $row['id']]);
        continue;
    }
    $room = ['name' => $row['name'], 'description' => $row['description'], 'starts_at' => $row['starts_at']];
    $ok = false;
    $err = null;
    try {
        $ok = send_room_invite_mail($config, $room, (string)$row['email'], (string)$row['token']);
    } catch (Throwable $e) {
        $err = substr($e->getMessage(), 0, 250);
    }
    if ($ok) {
        $pdo->prepare('UPDATE broadcast_outbox SET sent_at = NOW(), attempts = attempts + 1, last_error = NULL WHERE id = ?')
            ->execute([$row['id']]);
        $sent++;
    } else {
        $pdo->prepare('UPDATE broadcast_outbox SET attempts = attempts + 1, last_error = ? WHERE id = ?')
            ->execute([$err ?? 'falha no envio SMTP', $row['id']]);
    }
}
echo date('c') . " outbox: " . count($rows) . " pendente(s), {$sent} enviado(s)\n";
