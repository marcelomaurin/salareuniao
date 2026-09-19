<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

function app_mail_bootstrap(): void {
    static $loaded=false;
    if($loaded)return;
    $autoload=__DIR__.'/../vendor/autoload.php';
    if(is_file($autoload)) require_once $autoload;
    $loaded=true;
}

function html_escape(string $value): string {
    return htmlspecialchars($value,ENT_QUOTES,'UTF-8');
}

function mail_layout(string $title,string $contentHtml,string $footer='Sala Reunião'): string {
    $t=html_escape($title);
    $f=html_escape($footer);
    return '<!doctype html><html><body style="margin:0;background:#f3f4f6;font-family:Arial,sans-serif;color:#1f2937">'
        .'<div style="max-width:640px;margin:32px auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden">'
        .'<div style="background:#111827;color:#fff;padding:20px 24px"><h1 style="margin:0;font-size:22px">'.$t.'</h1></div>'
        .'<div style="padding:24px;line-height:1.55">'.$contentHtml.'</div>'
        .'<div style="padding:14px 24px;background:#f9fafb;color:#6b7280;font-size:12px">'.$f.'</div>'
        .'</div></body></html>';
}

function send_app_mail(array $config,string $to,string $subject,string $html,string $text=''): bool {
    $mailCfg=$config['mail']??[];
    $driver=(string)($mailCfg['driver']??'smtp');

    if($driver==='smtp'){
        app_mail_bootstrap();
        if(class_exists(PHPMailer::class)){
            try{
                $m=new PHPMailer(true);
                $m->isSMTP();
                $m->Host=(string)($mailCfg['host']??'');
                $m->Port=(int)($mailCfg['port']??587);
                $m->SMTPAuth=!empty($mailCfg['auth']);
                if($m->SMTPAuth){
                    $m->Username=(string)($mailCfg['username']??'');
                    $m->Password=(string)($mailCfg['password']??'');
                }
                $secure=(string)($mailCfg['encryption']??'tls');
                if($secure!=='') $m->SMTPSecure=$secure;
                $m->CharSet='UTF-8';
                $m->setFrom((string)$mailCfg['from'],(string)($mailCfg['from_name']??($config['app']['name']??'Sala Reunião')));
                if(!empty($mailCfg['reply_to'])) $m->addReplyTo((string)$mailCfg['reply_to']);
                $m->addAddress($to);
                $m->Subject=$subject;
                $m->isHTML(true);
                $m->Body=$html;
                $m->AltBody=$text!==''?$text:strip_tags(str_replace(['<br>','<br/>','<br />'],"
",$html));
                return $m->send();
            }catch(Throwable $e){
                error_log('SalaReuniao mail SMTP error: '.$e->getMessage());
                if(empty($mailCfg['fallback_mail'])) return false;
            }
        }elseif(empty($mailCfg['fallback_mail'])){
            error_log('SalaReuniao: PHPMailer não instalado.');
            return false;
        }
    }

    $headers=[
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: '.($mailCfg['from_name']??'Sala Reunião').' <'.($mailCfg['from']??'noreply@example.com').'>',
    ];
    return @mail($to,$subject,$html,implode("
",$headers));
}
