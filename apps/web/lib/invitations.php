<?php
declare(strict_types=1);

require_once __DIR__ . '/mailer.php';

function room_invite_link(array $config, string $token): string {
    $baseUrl = (string)($config['app']['base_url'] ?? '/salareuniao');
    if (str_starts_with($baseUrl, 'http://') || str_starts_with($baseUrl, 'https://')) {
        $base = rtrim($baseUrl, '/');
    } else {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'https';
        $host = !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'maurinsoft.com.br';
        $base = $scheme . '://' . $host . '/' . ltrim($baseUrl, '/');
    }
    return rtrim($base, '/') . '/room.php?token=' . urlencode($token);
}

function send_room_invite_mail(array $config, array $room, string $email, string $token, string $kind = 'invite'): bool {
    $link = room_invite_link($config, $token);
    $name = (string)$room['name'];
    $subject = $kind === 'resend' ? 'Reenvio de convite - ' . $name : 'Convite para ' . $name;
    $starts = !empty($room['starts_at']) ? (string)$room['starts_at'] : 'A definir / Imediato';
    $desc = trim((string)($room['description'] ?? ''));

    $content = '<p>Você foi convidado(a) para a reunião <strong>' . html_escape($name) . '</strong>.</p>'
        . '<p><strong>Data/Hora Prevista:</strong> ' . html_escape($starts) . '</p>'
        . ($desc !== '' ? '<p><strong>Pauta / Descrição:</strong><br>' . nl2br(html_escape($desc)) . '</p>' : '')
        . '<p style="margin:24px 0"><a href="' . html_escape($link) . '" style="background:#00d2ff;color:#070b14;padding:12px 22px;text-decoration:none;border-radius:8px;font-weight:bold;display:inline-block">Entrar na Sala de Reunião</a></p>'
        . '<p style="font-size:12px;color:#6b7280">Se o botão acima não funcionar, copie este endereço no seu navegador:<br>' . html_escape($link) . '</p>';

    $text = "Você foi convidado(a) para a reunião: {$name}\nData/hora: {$starts}\n";
    if ($desc !== '') $text .= "Descrição: {$desc}\n";
    $text .= "Acesse o link para entrar: {$link}\n";

    return send_app_mail($config, $email, $subject, mail_layout($subject, $content), $text);
}

function send_room_cancelled_mail(array $config, array $room, string $email): bool {
    $name = (string)$room['name'];
    $subject = 'Reunião cancelada - ' . $name;
    $content = '<p>A reunião <strong>' . html_escape($name) . '</strong> foi cancelada pelo anfitrião.</p>'
        . (!empty($room['starts_at']) ? '<p><strong>Horário previsto:</strong> ' . html_escape((string)$room['starts_at']) . '</p>' : '')
        . '<p>Não é mais necessário acessar o link do convite.</p>';
    $text = "A reunião {$name} foi cancelada.\n";
    return send_app_mail($config, $email, $subject, mail_layout($subject, $content), $text);
}

function send_password_reset_mail(array $config, array $user, string $token): bool {
    $url = rtrim((string)$config['app']['base_url'], '/') . '/reset_password.php?token=' . urlencode($token);
    $subject = 'Redefinição de senha - ' . ($config['app']['name'] ?? 'Sala Reunião Maurinsoft');
    $content = '<p>Olá, <strong>' . html_escape((string)$user['name']) . '</strong>.</p>'
        . '<p>Recebemos uma solicitação para redefinir a senha de acesso da sua conta.</p>'
        . '<p style="margin:24px 0"><a href="' . html_escape($url) . '" style="background:#00d2ff;color:#070b14;padding:12px 22px;text-decoration:none;border-radius:8px;font-weight:bold;display:inline-block">Redefinir Minha Senha</a></p>'
        . '<p>Este link expira em 60 minutos e só pode ser usado uma única vez.</p>'
        . '<p style="font-size:12px;color:#6b7280">Se você não solicitou esta alteração, desconsidere esta mensagem com segurança.</p>';
    $text = "Olá, {$user['name']}.\nRedefina sua senha em: {$url}\nO link expira em 60 minutos.\n";
    return send_app_mail($config, (string)$user['email'], $subject, mail_layout($subject, $content), $text);
}
