<?php
require __DIR__.'/lib/bootstrap.php';
require __DIR__.'/lib/invitations.php';

$user=require_login();
$id=(int)($_GET['id']??$_POST['id']??0);
$msg='';$error='';

function load_room_for_manager(PDO $pdo,array $user,int $id): ?array {
    if(!empty($user['role']) && $user['role']==='admin'){
        $st=$pdo->prepare('SELECT r.*,u.name owner_name,u.email owner_email FROM rooms r JOIN users u ON u.id=r.owner_user_id WHERE r.id=? LIMIT 1');
        $st->execute([$id]);
    }else{
        $st=$pdo->prepare('SELECT r.*,u.name owner_name,u.email owner_email FROM rooms r JOIN users u ON u.id=r.owner_user_id WHERE r.id=? AND (r.owner_user_id=? OR EXISTS(SELECT 1 FROM room_admins ra WHERE ra.room_id=r.id AND ra.user_id=?)) LIMIT 1');
        $st->execute([$id,$user['id'],$user['id']]);
    }
    return $st->fetch()?:null;
}

$room=load_room_for_manager($pdo,$user,$id);
if(!$room){http_response_code(404);exit('Sala não encontrada.');}

$loginEmail = strtolower(trim($user['email']));
$hst=$pdo->prepare("SELECT * FROM room_invites WHERE room_id=? AND LOWER(email)=? AND status='approved' ORDER BY id LIMIT 1");
$hst->execute([$id,$loginEmail]);
$hostInvite=$hst->fetch();
if(!$hostInvite){
    $hostToken=bin2hex(random_bytes(32));$hostKey=bin2hex(random_bytes(32));
    $ins=$pdo->prepare("INSERT INTO room_invites(room_id,email,token,status,display_name,participant_key,requested_at,approved_at)
                        VALUES(?,?,?,'approved',?,?,NOW(),NOW())");
    $ins->execute([$id,$loginEmail,$hostToken,$user['name'],$hostKey]);
    $hst->execute([$id,$loginEmail]);$hostInvite=$hst->fetch();
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
            if($starts) $starts = str_replace('T', ' ', $starts);
            if($ends) $ends = str_replace('T', ' ', $ends);
            if($name==='') throw new RuntimeException('Informe o nome da reunião.');
            $q=$pdo->prepare('UPDATE rooms SET name=?,description=?,starts_at=?,ends_at=? WHERE id=?');
            $q->execute([$name,$description,$starts,$ends,$id]);
            audit_log('room.edit','room',$id,['name'=>$name,'starts_at'=>$starts,'ends_at'=>$ends]);
            $msg='Dados da reunião atualizados com sucesso.';
        }elseif($action==='add_admin'){
            $targetUserId = (int)($_POST['admin_user_id'] ?? 0);
            if ($targetUserId <= 0) throw new RuntimeException('Selecione um usuário válido.');
            $uSt = $pdo->prepare("SELECT id, name, email FROM users WHERE id = ? LIMIT 1");
            $uSt->execute([$targetUserId]);
            $targetUser = $uSt->fetch();
            if (!$targetUser) throw new RuntimeException('Usuário selecionado não foi encontrado.');
            if ($targetUserId === (int)$room['owner_user_id']) throw new RuntimeException('Este usuário já é o anfitrião proprietário da sala.');

            $stAdd = $pdo->prepare("INSERT IGNORE INTO room_admins (room_id, user_id) VALUES (?, ?)");
            $stAdd->execute([$id, $targetUserId]);

            // Garante que o administrador tenha convite aprovado para entrar diretamente
            $tEmail = strtolower($targetUser['email']);
            $stInv = $pdo->prepare("SELECT id FROM room_invites WHERE room_id = ? AND LOWER(email) = ? LIMIT 1");
            $stInv->execute([$id, $tEmail]);
            $existingInv = $stInv->fetch();
            if (!$existingInv) {
                $tToken = bin2hex(random_bytes(32));
                $tKey = bin2hex(random_bytes(32));
                $insInv = $pdo->prepare("INSERT INTO room_invites (room_id, email, token, status, display_name, participant_key, requested_at, approved_at) VALUES (?, ?, ?, 'approved', ?, ?, NOW(), NOW())");
                $insInv->execute([$id, $tEmail, $tToken, $targetUser['name'], $tKey]);
            } else {
                $pdo->prepare("UPDATE room_invites SET status = 'approved', approved_at = NOW() WHERE id = ?")->execute([$existingInv['id']]);
            }

            audit_log('room.admin_add', 'room', $id, ['user_id' => $targetUserId, 'name' => $targetUser['name']]);
            $msg = 'Administrador ' . htmlspecialchars($targetUser['name']) . ' adicionado com sucesso à sala.';
        }elseif($action==='remove_admin'){
            $targetUserId = (int)($_POST['admin_user_id'] ?? 0);
            if ($targetUserId === (int)$room['owner_user_id']) throw new RuntimeException('O proprietário da sala não pode ser removido.');
            $pdo->prepare("DELETE FROM room_admins WHERE room_id = ? AND user_id = ?")->execute([$id, $targetUserId]);
            audit_log('room.admin_remove', 'room', $id, ['user_id' => $targetUserId]);
            $msg = 'Administrador removido da sala.';
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
        }elseif($action==='open' || ($action==='set_status' && ($_POST['status']??'')==='open')){
            if($room['status']==='cancelled') throw new RuntimeException('Uma reunião cancelada não pode ser aberta.');
            $pdo->prepare("UPDATE rooms SET status='open' WHERE id=?")->execute([$id]);
            audit_log('room.open','room',$id);
            $msg='Sala aberta com sucesso.';
        }elseif(in_array($action,['close','cancel'],true) || ($action==='set_status' && in_array($_POST['status']??'',['closed','cancelled'],true))){
            $newStatus=($action==='cancel'||($_POST['status']??'')==='cancelled')?'cancelled':'closed';
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
        <div style="flex: 1; min-width: 280px;">
          <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <h1 style="font-size: 1.5rem; margin: 0;"><?=e($room['name'])?></h1>
            <button type="button" class="sr-btn sr-btn-secondary sr-btn-sm" onclick="toggleEditModal(true)" style="padding: 4px 10px; font-size: 0.82rem; cursor: pointer;">
              ✏️ Alterar Nome / Dados
            </button>
            <?php
              $isOpen = ($room['status'] === 'open');
              $badgeClass = $isOpen ? 'sr-badge-open' : ($room['status'] === 'scheduled' ? 'sr-badge-scheduled' : 'sr-badge-closed');
            ?>
            <span class="sr-badge <?=$badgeClass?>">
              <?php if ($isOpen): ?><span class="sr-pulse-dot"></span><?php endif; ?>
              Status: <?=strtoupper(e($room['status']))?>
            </span>
          </div>
          <?php if (!empty($room['description'])): ?>
            <p style="color: var(--text-muted); font-size: 0.88rem; margin-top: 6px; margin-bottom: 4px;"><?=nl2br(e($room['description']))?></p>
          <?php endif; ?>
          <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 4px;">
            Anfitrião: <strong><?=e($room['owner_name'])?></strong> (<?=e($room['owner_email'])?>) &bull;
            Início previsto: <?=!empty($room['starts_at']) ? date('d/m/Y H:i', strtotime($room['starts_at'])) : 'Livre'?>
            <?php if (!empty($room['ends_at'])): ?>
              &bull; Fim previsto: <?=date('d/m/Y H:i', strtotime($room['ends_at']))?>
            <?php endif; ?>
          </div>
        </div>

        <!-- Room Action Controls -->
        <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
          <button type="button" class="sr-btn sr-btn-secondary" onclick="toggleEditModal(true)">
            ✏️ Editar Nome da Sala
          </button>

          <form method="post" style="display: inline; margin: 0;">
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
            <input type="hidden" name="id" value="<?=$id?>">
            <?php if ($room['status'] !== 'open'): ?>
              <input type="hidden" name="status" value="open">
              <button type="submit" name="action" value="open" class="sr-btn sr-btn-success">
                🟢 Abrir Sala Agora
              </button>
            <?php else: ?>
              <input type="hidden" name="status" value="closed">
              <button type="submit" name="action" value="close" class="sr-btn sr-btn-danger">
                🔴 Encerrar Sala
              </button>
            <?php endif; ?>
          </form>

          <?php if (!empty($hostInvite['token'])): ?>
            <?php
              $publicJoinLink = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/room.php?token=' . urlencode($hostInvite['token']);
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


    <!-- Card: Administradores da Sala -->
    <div class="sr-card" style="margin-bottom: 20px;">
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px;">
        <div>
          <h2 style="font-size: 1.25rem; margin-bottom: 4px;">🛡️ Administradores desta Sala</h2>
          <p style="color: var(--text-muted); font-size: 0.88rem;">
            Apenas administradores desta sala (ou administradores gerais) podem abrir e ingressar nesta videoconferência.
          </p>
        </div>
      </div>

      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px; margin-bottom: 20px;">
        <!-- Anfitrião Criador -->
        <div class="sr-invite-item" style="border-color: rgba(0, 210, 255, 0.3); background: rgba(0, 210, 255, 0.05);">
          <div>
            <div style="font-weight: 600; color: #fff; display: flex; align-items: center; gap: 6px;">
              <span>👑</span> <?=e($room['owner_name'])?>
              <span class="sr-badge sr-badge-open" style="font-size: 9px; padding: 1px 6px;">Proprietário</span>
            </div>
            <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 2px;">
              <?=e($room['owner_email'])?>
            </div>
          </div>
        </div>

        <!-- Demais Administradores da Sala -->
        <?php foreach ($roomAdmins as $radm): ?>
          <div class="sr-invite-item" style="border-color: rgba(59, 130, 246, 0.3);">
            <div>
              <div style="font-weight: 600; color: #fff; display: flex; align-items: center; gap: 6px;">
                <span>🛡️</span> <?=e($radm['name'])?>
                <span class="sr-badge" style="background: rgba(59, 130, 246, 0.2); color: #93c5fd; font-size: 9px; padding: 1px 6px;">Admin da Sala</span>
              </div>
              <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 2px;">
                <?=e($radm['email'])?>
              </div>
            </div>
            <form method="post" style="display: inline; margin: 0;" onsubmit="return confirm('Remover o acesso de administrador deste usuário nesta sala?');">
              <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
              <input type="hidden" name="id" value="<?=$id?>">
              <input type="hidden" name="action" value="remove_admin">
              <input type="hidden" name="admin_user_id" value="<?=(int)$radm['user_id']?>">
              <button type="submit" class="sr-btn sr-btn-danger sr-btn-sm" title="Remover administrador">
                ✕ Remover
              </button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Formulário para Incluir Administrador -->
      <div style="padding-top: 14px; border-top: 1px solid var(--border-glass);">
        <form method="post" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
          <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
          <input type="hidden" name="id" value="<?=$id?>">
          <input type="hidden" name="action" value="add_admin">

          <div style="flex: 1; min-width: 240px;">
            <select name="admin_user_id" class="sr-input" style="height: 42px;" required>
              <option value="">Selecione um usuário cadastrado para ser administrador...</option>
              <?php foreach ($availableUsers as $au): ?>
                <option value="<?=(int)$au['id']?>">
                  <?=e($au['name'])?> (<?=e($au['email'])?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <button type="submit" class="sr-btn sr-btn-primary sr-btn-sm" style="min-height: 42px;">
            ➕ Incluir como Administrador da Sala
          </button>
        </form>
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
                      $invUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/room.php?token=' . urlencode($inv['token']);
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
      <!-- Modal: Editar Nome e Dados da Sala -->
    <div id="modalEditRoom" style="display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.75); backdrop-filter: blur(8px); z-index: 999; align-items: center; justify-content: center; padding: 20px;">
      <div class="sr-card" style="max-width: 540px; width: 100%; border: 1px solid var(--border-accent); box-shadow: var(--shadow-glow);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
          <h2 style="font-size: 1.25rem; margin: 0; display: flex; align-items: center; gap: 8px;">
            <span>✏️</span> Alterar Dados da Sala
          </h2>
          <button type="button" onclick="toggleEditModal(false)" style="background: none; border: none; color: var(--text-muted); font-size: 1.5rem; cursor: pointer; line-height: 1;">&times;</button>
        </div>

        <form method="post">
          <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
          <input type="hidden" name="id" value="<?=$id?>">
          <input type="hidden" name="action" value="edit">

          <div style="margin-bottom: 16px;">
            <label class="sr-label" for="edit_room_name">Nome da Sala / Reunião *</label>
            <input type="text" id="edit_room_name" name="name" class="sr-input" required value="<?=e($room['name'])?>" placeholder="Ex: Reunião Geral de Planejamento">
          </div>

          <div style="margin-bottom: 16px;">
            <label class="sr-label" for="edit_room_description">Descrição / Pauta da Reunião</label>
            <textarea id="edit_room_description" name="description" class="sr-textarea" rows="3" placeholder="Pauta ou descrição dos tópicos abordados..."><?=e($room['description'] ?? '')?></textarea>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 24px;">
            <div>
              <label class="sr-label" for="edit_starts_at">Início Previsto</label>
              <input type="datetime-local" id="edit_starts_at" name="starts_at" class="sr-input" value="<?=!empty($room['starts_at']) ? date('Y-m-d\TH:i', strtotime($room['starts_at'])) : ''?>">
            </div>
            <div>
              <label class="sr-label" for="edit_ends_at">Término Previsto</label>
              <input type="datetime-local" id="edit_ends_at" name="ends_at" class="sr-input" value="<?=!empty($room['ends_at']) ? date('Y-m-d\TH:i', strtotime($room['ends_at'])) : ''?>">
            </div>
          </div>

          <div style="display: flex; justify-content: flex-end; gap: 10px;">
            <button type="button" class="sr-btn sr-btn-secondary" onclick="toggleEditModal(false)">Cancelar</button>
            <button type="submit" class="sr-btn sr-btn-primary">💾 Salvar Alterações</button>
          </div>
        </form>
      </div>
    </div>
  </main>

  <!-- Toast Notification -->
  <div id="sr-toast">Link copiado para a área de transferência!</div>

  <script>
    function toggleEditModal(show) {
      const modal = document.getElementById('modalEditRoom');
      if (modal) {
        modal.style.display = show ? 'flex' : 'none';
        if (show) {
          setTimeout(() => {
            const input = document.getElementById('edit_room_name');
            if (input) {
              input.focus();
              input.select();
            }
          }, 50);
        }
      }
    }

    // Fechar modal ao clicar fora da caixa
    window.addEventListener('click', function(e) {
      const modal = document.getElementById('modalEditRoom');
      if (e.target === modal) {
        toggleEditModal(false);
      }
    });

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
