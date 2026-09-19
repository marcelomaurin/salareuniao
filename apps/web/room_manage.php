<?php
require __DIR__.'/lib/bootstrap.php';
require __DIR__.'/lib/invitations.php';

$user=require_login();
$id=(int)($_GET['id']??$_POST['id']??0);
$msg='';$error='';

function load_room_for_manager(PDO $pdo,array $user,int $id): ?array {
    if($user['role']==='admin'){
        $st=$pdo->prepare('SELECT r.*,u.name owner_name,u.email owner_email FROM rooms r JOIN users u ON u.id=r.owner_user_id WHERE r.id=? LIMIT 1');
        $st->execute([$id]);
    }else{
        $st=$pdo->prepare('SELECT r.*,u.name owner_name,u.email owner_email FROM rooms r JOIN users u ON u.id=r.owner_user_id WHERE r.id=? AND r.owner_user_id=? LIMIT 1');
        $st->execute([$id,$user['id']]);
    }
    return $st->fetch()?:null;
}

$room=load_room_for_manager($pdo,$user,$id);
if(!$room){http_response_code(404);exit('Sala não encontrada.');}

$hst=$pdo->prepare("SELECT * FROM room_invites WHERE room_id=? AND email=? AND status='approved' ORDER BY id LIMIT 1");
$hst->execute([$id,strtolower($room['owner_email'])]);
$hostInvite=$hst->fetch();
if(!$hostInvite){
    $hostToken=bin2hex(random_bytes(32));$hostKey=bin2hex(random_bytes(32));
    $ins=$pdo->prepare("INSERT INTO room_invites(room_id,email,token,status,display_name,participant_key,requested_at,approved_at)
                        VALUES(?,?,?,'approved',?,?,NOW(),NOW())");
    $ins->execute([$id,strtolower($room['owner_email']),$hostToken,$room['owner_name'],$hostKey]);
    $hst->execute([$id,strtolower($room['owner_email'])]);$hostInvite=$hst->fetch();
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $inviteId=(int)($_POST['invite_id']??0);
    $action=$_POST['action']??'';

    try{
        if($action==='edit'){
            $name=trim($_POST['name']??'');
            $description=trim($_POST['description']??'');
            $starts=trim($_POST['starts_at']??'')?:null;
            $ends=trim($_POST['ends_at']??'')?:null;
            if($name==='') throw new RuntimeException('Informe o nome da reunião.');
            $q=$pdo->prepare('UPDATE rooms SET name=?,description=?,starts_at=?,ends_at=? WHERE id=?');
            $q->execute([$name,$description,$starts,$ends,$id]);
            audit_log('room.edit','room',$id,['name'=>$name,'starts_at'=>$starts,'ends_at'=>$ends]);
            $msg='Dados da reunião atualizados.';
        }elseif($action==='add_invites'){
            if($room['status']==='cancelled') throw new RuntimeException('Não é possível convidar pessoas para uma reunião cancelada.');
            $emails=preg_split('/[\s,;]+/',trim($_POST['emails']??''),-1,PREG_SPLIT_NO_EMPTY);
            $added=0;
            foreach(array_unique($emails) as $email){
                $email=strtolower(trim($email));
                if(!filter_var($email,FILTER_VALIDATE_EMAIL)||$email===strtolower($room['owner_email'])) continue;
                $exists=$pdo->prepare("SELECT id FROM room_invites WHERE room_id=? AND email=? AND status<>'rejected' LIMIT 1");
                $exists->execute([$id,$email]);
                if($exists->fetch()) continue;
                $token=bin2hex(random_bytes(32));
                $ins=$pdo->prepare("INSERT INTO room_invites(room_id,email,token,status) VALUES(?,?,?,'invited')");
                $ins->execute([$id,$email,$token]);
                send_room_invite_mail($config,$room,$email,$token);
                $added++;
            }
            audit_log('room.invites_add','room',$id,['count'=>$added]);
            $msg=$added.' novo(s) convite(s) enviado(s).';
        }elseif($action==='resend'){
            $q=$pdo->prepare('SELECT * FROM room_invites WHERE id=? AND room_id=? LIMIT 1');
            $q->execute([$inviteId,$id]);$invite=$q->fetch();
            if(!$invite) throw new RuntimeException('Convite não encontrado.');
            send_room_invite_mail($config,$room,$invite['email'],$invite['token'],'resend');
            audit_log('room.invite_resend','invite',$inviteId,['room_id'=>$id,'email'=>$invite['email']]);
            $msg='Convite reenviado para '.$invite['email'].'.';
        }elseif(in_array($action,['approve','reject'],true)){
            if($action==='approve'){
                $pk=bin2hex(random_bytes(32));
                $up=$pdo->prepare("UPDATE room_invites SET status='approved',participant_key=COALESCE(participant_key,?),approved_at=NOW() WHERE id=? AND room_id=?");
                $up->execute([$pk,$inviteId,$id]);
                audit_log('room.participant_approve','invite',$inviteId,['room_id'=>$id]);
                $msg='Participante autorizado.';
            }else{
                $pdo->prepare("UPDATE room_invites SET status='rejected' WHERE id=? AND room_id=?")->execute([$inviteId,$id]);
                audit_log('room.participant_reject','invite',$inviteId,['room_id'=>$id]);
                $msg='Participante recusado.';
            }
        }elseif($action==='open'){
            if($room['status']==='cancelled') throw new RuntimeException('Uma reunião cancelada não pode ser aberta.');
            $pdo->prepare("UPDATE rooms SET status='open' WHERE id=?")->execute([$id]);
            audit_log('room.open','room',$id);
            $msg='Sala aberta.';
        }elseif(in_array($action,['close','cancel'],true)){
            $newStatus=$action==='cancel'?'cancelled':'closed';
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE room_attendance a JOIN room_presence p ON p.room_id=a.room_id AND p.participant_key=a.participant_key SET a.left_at=p.last_seen_at,a.duration_seconds=TIMESTAMPDIFF(SECOND,a.joined_at,p.last_seen_at) WHERE a.room_id=? AND a.left_at IS NULL')->execute([$id]);
            $pdo->prepare("UPDATE rooms SET status=?,ends_at=NOW() WHERE id=?")->execute([$newStatus,$id]);
            $pdo->prepare('DELETE FROM room_presence WHERE room_id=?')->execute([$id]);
            $sig=$pdo->prepare("INSERT INTO signaling_messages(room_id,sender_key,recipient_key,message_type,payload)
                                SELECT ?,?,participant_key,'leave',JSON_OBJECT('roomEnded',true,'cancelled',?) FROM room_invites WHERE room_id=? AND participant_key IS NOT NULL AND participant_key<>?");
            $sig->execute([$id,$hostInvite['participant_key'],$action==='cancel'?1:0,$id,$hostInvite['participant_key']]);
            $pdo->commit();
            if($action==='cancel'){
                $emails=$pdo->prepare("SELECT DISTINCT email FROM room_invites WHERE room_id=? AND email<>?");
                $emails->execute([$id,strtolower($room['owner_email'])]);
                foreach($emails->fetchAll() as $row){
                    if(!empty($row['email'])) send_room_cancelled_mail($config,$room,$row['email']);
                }
            }
            audit_log($action==='cancel'?'room.cancel':'room.close','room',$id);
            $msg=$action==='cancel'?'Reunião cancelada e convidados notificados.':'Reunião encerrada.';
        }elseif($action==='remove'){
            $target=$pdo->prepare('SELECT participant_key FROM room_invites WHERE id=? AND room_id=?');
            $target->execute([$inviteId,$id]);$t=$target->fetch();
            if($t&&$t['participant_key']&&(int)$inviteId!==(int)$hostInvite['id']){
                $left=date('Y-m-d H:i:s');
                $pdo->prepare("UPDATE room_attendance SET left_at=?,duration_seconds=TIMESTAMPDIFF(SECOND,joined_at,?) WHERE room_id=? AND participant_key=? AND left_at IS NULL ORDER BY id DESC LIMIT 1")
                    ->execute([$left,$left,$id,$t['participant_key']]);
                $pdo->prepare("UPDATE room_invites SET status='rejected' WHERE id=? AND room_id=?")->execute([$inviteId,$id]);
                $pdo->prepare('DELETE FROM room_presence WHERE room_id=? AND participant_key=?')->execute([$id,$t['participant_key']]);
                $sig=$pdo->prepare("INSERT INTO signaling_messages(room_id,sender_key,recipient_key,message_type,payload) VALUES(?,?,?,'leave',JSON_OBJECT('removed',true))");
                $sig->execute([$id,$hostInvite['participant_key'],$t['participant_key']]);
                audit_log('room.participant_remove','invite',$inviteId,['room_id'=>$id]);
                $msg='Participante removido.';
            }
        }
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        $error=$e->getMessage();
    }
    $room=load_room_for_manager($pdo,$user,$id);
}

$inv=$pdo->prepare("SELECT i.*,p.last_seen_at,p.mic_enabled,p.cam_enabled,p.screen_sharing,
                    (p.last_seen_at>=DATE_SUB(NOW(),INTERVAL 20 SECOND)) online
                    FROM room_invites i
                    LEFT JOIN room_presence p ON p.room_id=i.room_id AND p.participant_key=i.participant_key
                    WHERE i.room_id=? ORDER BY i.created_at");
$inv->execute([$id]);$invites=$inv->fetchAll();
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Gerenciar Sala - <?=e($room['name'])?></title>
  <link rel="stylesheet" href="assets/css/salareuniao.css">
  <style>
    .sr-manage-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 20px;
      margin-top: 20px;
    }
    @media (max-width: 850px) {
      .sr-manage-grid {
        grid-template-columns: 1fr;
      }
    }
    .sr-invite-item {
      background: rgba(255, 255, 255, 0.03);
      border: 1px solid var(--border-glass);
      border-radius: var(--radius-sm);
      padding: 14px;
      margin-bottom: 10px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }
  </style>
</head>
<body>

  <!-- Topbar -->
  <header class="sr-topbar">
    <div class="sr-topbar-inner">
      <div class="sr-brand">
        <div class="sr-brand-logo">M</div>
        <div class="sr-brand-title"><?=e($room['name'])?> <span style="font-weight: 400; font-size: 0.9rem; color: var(--text-muted);">&bull; Gestão do Anfitrião</span></div>
      </div>
      <div class="sr-nav-links">
        <a href="index.php" class="sr-btn sr-btn-secondary sr-btn-sm">&larr; Central de Salas</a>
        <?php if (!empty($hostInvite['token']) && $room['status'] === 'open'): ?>
          <a href="room.php?token=<?=urlencode($hostInvite['token'])?>" class="sr-btn sr-btn-primary sr-btn-sm">
            🚀 Acessar Sala ao Vivo
          </a>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <main class="sr-container">

    <!-- Status & Alerts -->
    <?php if ($msg): ?>
      <div class="sr-alert sr-alert-success"><?=e($msg)?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="sr-alert sr-alert-error"><?=e($error)?></div>
    <?php endif; ?>

    <!-- Master Control Bar -->
    <div class="sr-card" style="margin-bottom: 20px;">
      <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
        <div>
          <div style="display: flex; align-items: center; gap: 10px;">
            <h1 style="font-size: 1.5rem;"><?=e($room['name'])?></h1>
            <?php
              $isOpen = ($room['status'] === 'open');
              $badgeClass = $isOpen ? 'sr-badge-open' : ($room['status'] === 'scheduled' ? 'sr-badge-scheduled' : 'sr-badge-closed');
            ?>
            <span class="sr-badge <?=$badgeClass?>">
              <?php if ($isOpen): ?><span class="sr-pulse-dot"></span><?php endif; ?>
              Status: <?=strtoupper(e($room['status']))?>
            </span>
          </div>
          <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 4px;">
            Anfitrião: <strong><?=e($room['owner_name'])?></strong> (<?=e($room['owner_email'])?>) &bull;
            Início previsto: <?=!empty($room['starts_at']) ? date('d/m/Y H:i', strtotime($room['starts_at'])) : 'Livre'?>
          </div>
        </div>

        <!-- Room Action Controls -->
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
          <form method="post" style="display: inline;">
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
            <input type="hidden" name="id" value="<?=$id?>">
            <?php if ($room['status'] !== 'open'): ?>
              <input type="hidden" name="status" value="open">
              <button type="submit" name="action" value="set_status" class="sr-btn sr-btn-success">
                🟢 Abrir Sala Agora
              </button>
            <?php else: ?>
              <input type="hidden" name="status" value="closed">
              <button type="submit" name="action" value="set_status" class="sr-btn sr-btn-danger">
                🔴 Encerrar Sala
              </button>
            <?php endif; ?>
          </form>

          <?php if (!empty($hostInvite['token'])): ?>
            <?php
              $publicJoinLink = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/join.php?token=' . urlencode($hostInvite['token']);
            ?>
            <button type="button" class="sr-btn sr-btn-secondary" onclick="copyLink('<?=e($publicJoinLink)?>')">
              📋 Copiar Link Público
            </button>
            <a href="https://api.whatsapp.com/send?text=<?=urlencode('Participe da reunião (' . $room['name'] . '): ' . $publicJoinLink)?>" target="_blank" class="sr-btn sr-btn-secondary">
              💬 Enviar via WhatsApp
            </a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Main Grid: Attendees / Waiting & Invites -->
    <div class="sr-manage-grid">

      <!-- Left Column: Convites e Entrada Pendente -->
      <div class="sr-card">
        <h2 style="font-size: 1.25rem; margin-bottom: 16px;">👥 Participantes & Convites</h2>

        <?php if (empty($invites)): ?>
          <p style="color: var(--text-muted); font-size: 0.9rem;">Nenhum convite emitido até o momento.</p>
        <?php else: ?>
          <div style="max-height: 480px; overflow-y: auto;">
            <?php foreach ($invites as $inv): ?>
              <?php
                $isWaiting = ($inv['status'] === 'waiting');
                $isApproved = ($inv['status'] === 'approved');
                $isHost = (!empty($inv['role']) && $inv['role'] === 'host') || strtolower($inv['email'] ?? '') === strtolower($room['owner_email']);
              ?>
              <div class="sr-invite-item" style="<?=$isWaiting ? 'border-color: var(--accent-amber); background: rgba(245, 158, 11, 0.08);' : ''?>">
                <div>
                  <div style="font-weight: 600; color: #fff;">
                    <?=e($inv['display_name'] ?: ($inv['email'] ?: 'Convidado Sem Nome'))?>
                    <?php if ($isHost): ?>
                      <span class="sr-badge sr-badge-open" style="font-size: 9px; padding: 1px 6px;">Anfitrião</span>
                    <?php endif; ?>
                  </div>
                  <div style="font-size: 0.78rem; color: var(--text-muted);">
                    <?=e($inv['email'] ?: 'Link de acesso avulso')?> &bull;
                    <span style="color: <?=$isApproved ? '#34d399' : ($isWaiting ? '#fbbf24' : '#94a3b8')?>;">
                      <?=strtoupper(e($inv['status']))?>
                    </span>
                  </div>
                </div>

                <div style="display: flex; gap: 6px;">
                  <?php if ($isWaiting): ?>
                    <form method="post" style="display: inline;">
                      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
                      <input type="hidden" name="id" value="<?=$id?>">
                      <input type="hidden" name="invite_id" value="<?=(int)$inv['id']?>">
                      <input type="hidden" name="action" value="approve_invite">
                      <button type="submit" class="sr-btn sr-btn-success sr-btn-sm">Aprovar</button>
                    </form>
                    <form method="post" style="display: inline;">
                      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
                      <input type="hidden" name="id" value="<?=$id?>">
                      <input type="hidden" name="invite_id" value="<?=(int)$inv['id']?>">
                      <input type="hidden" name="action" value="reject_invite">
                      <button type="submit" class="sr-btn sr-btn-danger sr-btn-sm">Recusar</button>
                    </form>
                  <?php endif; ?>

                  <?php if (!empty($inv['token'])): ?>
                    <?php
                      $invUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/join.php?token=' . urlencode($inv['token']);
                    ?>
                    <button type="button" class="sr-btn sr-btn-secondary sr-btn-sm" onclick="copyLink('<?=e($invUrl)?>')" title="Copiar Link deste convidado">
                      Link
                    </button>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <!-- Form: Convidar mais pessoas -->
        <div style="margin-top: 24px; padding-top: 18px; border-top: 1px solid var(--border-glass);">
          <h3 style="font-size: 1rem; margin-bottom: 12px;">+ Adicionar Convidados</h3>
          <form method="post">
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
            <input type="hidden" name="id" value="<?=$id?>">
            <input type="hidden" name="action" value="add_invites">
            <div class="sr-form-group">
              <textarea name="emails" class="sr-textarea" rows="2" placeholder="E-mails separados por vírgula para envio de convite imediato"></textarea>
            </div>
            <button type="submit" class="sr-btn sr-btn-primary sr-btn-sm">Enviar Novos Convites</button>
          </form>
        </div>
      </div>

      <!-- Right Column: Presença em Tempo Real & Detalhes -->
      <div class="sr-card">
        <h2 style="font-size: 1.25rem; margin-bottom: 16px;">📡 Presença ao Vivo & Histórico</h2>

        <div style="margin-bottom: 20px;">
          <h3 style="font-size: 0.95rem; color: var(--text-muted); margin-bottom: 8px;">Conectados Agora</h3>
          <?php if (empty($activePresence)): ?>
            <p style="color: var(--text-dim); font-size: 0.88rem;">Nenhum participante com vídeo/áudio ativo neste instante.</p>
          <?php else: ?>
            <?php foreach ($activePresence as $p): ?>
              <div class="sr-invite-item" style="border-color: rgba(16, 185, 129, 0.3);">
                <div>
                  <span class="sr-pulse-dot"></span>
                  <strong><?=e($p['display_name'])?></strong>
                  <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;">
                    IP: <?=e($p['ip_address'] ?? 'N/A')?> &bull; Visto: <?=e($p['last_seen_at'])?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <div>
          <h3 style="font-size: 0.95rem; color: var(--text-muted); margin-bottom: 8px;">Detalhes Técnicos da Sala</h3>
          <div style="font-size: 0.82rem; color: var(--text-dim); line-height: 1.8;">
            <div>ID da Sala: <code><?=$id?></code></div>
            <div>Criado em: <?=date('d/m/Y H:i:s', strtotime($room['created_at']))?></div>
            <div>WebRTC Signaling: <code>HTTP Long-Polling + WebSockets</code></div>
            <div>STUN/TURN: <code>Habilitado com credenciais temporais</code></div>
          </div>
        </div>

        <div style="margin-top: 28px;">
          <a href="room_history.php?id=<?=$id?>" class="sr-btn sr-btn-secondary sr-btn-sm">
            📊 Ver Relatório Completo de Presenças
          </a>
        </div>
      </div>

    </div>
  </main>

  <!-- Toast Notification -->
  <div id="sr-toast">Link copiado para a área de transferência!</div>

  <script>
    function copyLink(url) {
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(url).then(showToast);
      } else {
        const input = document.createElement('input');
        input.value = url;
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        document.body.removeChild(input);
        showToast();
      }
    }

    function showToast() {
      const toast = document.getElementById('sr-toast');
      if (toast) {
        toast.classList.add('show');
        setTimeout(() => toast.classList.remove('show'), 3000);
      }
    }
  </script>
</body>
</html>
