<?php
declare(strict_types=1);

require_once __DIR__.'/mailer.php';

function room_invite_link(array $config,string $token): string {
    return rtrim((string)$config['app']['base_url'],'/').'/join.php?token='.urlencode($token);
}

function send_room_invite_mail(array $config,array $room,string $email,string $token,string $kind='invite'): bool {
    $link=room_invite_link($config,$token);
    $name=(string)$room['name'];
    $subject=$kind==='resend'?'Reenvio de convite - '.$name:'Convite para '.$name;
    $starts=!empty($room['starts_at'])?(string)$room['starts_at']:'A definir';
    $desc=trim((string)($room['description']??''));

    $content='<p>Você foi convidado para a reunião <strong>'.html_escape($name).'</strong>.</p>'
        .'<p><strong>Data/hora:</strong> '.html_escape($starts).'</p>'
        .($desc!==''?'<p><strong>Descrição:</strong><br>'.nl2br(html_escape($desc)).'</p>':'')
        .'<p style="margin:24px 0"><a href="'.html_escape($link).'" style="background:#2563eb;color:#fff;padding:12px 18px;text-decoration:none;border-radius:8px;display:inline-block">Abrir convite</a></p>'
        .'<p style="font-size:12px;color:#6b7280">Se o botão não funcionar, copie este endereço:<br>'.html_escape($link).'</p>';

    $text="Você foi convidado para a reunião: {$name}
Data/hora: {$starts}
";
    if($desc!=='')$text.="Descrição: {$desc}
";
    $text.="Acesse: {$link}
";
    return send_app_mail($config,$email,$subject,mail_layout($subject,$content),$text);
}

function send_room_cancelled_mail(array $config,array $room,string $email): bool {
    $name=(string)$room['name'];
    $subject='Reunião cancelada - '.$name;
    $content='<p>A reunião <strong>'.html_escape($name).'</strong> foi cancelada.</p>'
        .(!empty($room['starts_at'])?'<p><strong>Horário originalmente previsto:</strong> '.html_escape((string)$room['starts_at']).'</p>':'')
        .'<p>Não é necessário acessar o link do convite.</p>';
    $text="A reunião {$name} foi cancelada.
";
    return send_app_mail($config,$email,$subject,mail_layout($subject,$content),$text);
}

function send_password_reset_mail(array $config,array $user,string $token): bool {
    $url=rtrim((string)$config['app']['base_url'],'/').'/reset_password.php?token='.urlencode($token);
    $subject='Redefinição de senha - '.($config['app']['name']??'Sala Reunião');
    $content='<p>Olá, <strong>'.html_escape((string)$user['name']).'</strong>.</p>'
        .'<p>Recebemos uma solicitação para redefinir a senha da sua conta.</p>'
        .'<p style="margin:24px 0"><a href="'.html_escape($url).'" style="background:#2563eb;color:#fff;padding:12px 18px;text-decoration:none;border-radius:8px;display:inline-block">Redefinir senha</a></p>'
        .'<p>Este link expira em 60 minutos e só pode ser usado uma vez.</p>'
        .'<p style="font-size:12px;color:#6b7280">Se você não solicitou a alteração, ignore esta mensagem.</p>';
    $text="Olá, {$user['name']}.
Redefina sua senha em: {$url}
O link expira em 60 minutos.
";
    return send_app_mail($config,(string)$user['email'],$subject,mail_layout($subject,$content),$text);
}
