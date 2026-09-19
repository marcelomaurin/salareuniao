<?php
declare(strict_types=1);

function room_invite_link(array $config,string $token): string {
    return rtrim((string)$config['app']['base_url'],'/').'/join.php?token='.urlencode($token);
}

function send_room_invite_mail(array $config,array $room,string $email,string $token,string $kind='invite'): bool {
    $link=room_invite_link($config,$token);
    $name=(string)$room['name'];
    $starts=!empty($room['starts_at']) ? "\nData/hora: ".$room['starts_at'] : '';
    $subject=$kind==='resend' ? 'Reenvio de convite - '.$name : 'Convite para '.$name;
    $body="Você foi convidado para a reunião: {$name}{$starts}\n\nAcesse: {$link}\n";
    if(!empty($room['description'])) $body.="\nDescrição: ".$room['description']."\n";
    return @mail($email,$subject,$body,'From: '.$config['mail']['from']);
}
