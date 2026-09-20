<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') {
    header('Location: index.php');
    exit;
}

// 1. OBRIGAÇÃO DE LOGIN: Se o usuário não estiver logado, redireciona para login.php
$signedUser = current_user();
if (!$signedUser || empty($signedUser['active'])) {
    $redirectUrl = 'room.php?token=' . urlencode($token);
    header('Location: login.php?redirect=' . urlencode($redirectUrl));
    exit;
}

// 2. Valida o token e os dados da sala
$st = $pdo->prepare("SELECT i.*, r.id as room_id, r.name as room_name, r.status as room_status, r.owner_user_id 
                     FROM room_invites i 
                     JOIN rooms r ON r.id=i.room_id 
                     WHERE i.token=? LIMIT 1");
$st->execute([$token]);
$tokenInvite = $st->fetch();

if (!$tokenInvite) {
    http_response_code(404);
    exit('Convite ou sala não encontrada.');
}
if ($tokenInvite['room_status'] !== 'open') {
    exit('A sala ainda não foi aberta pelo administrador ou foi encerrada.');
}

$roomId = (int)$tokenInvite['room_id'];
$userEmail = strtolower(trim((string)$signedUser['email']));
$userName = trim((string)$signedUser['name']) ?: explode('@', $userEmail)[0];

// 3. Garante que o usuário logado utilize seu próprio convite individual aprovado
$stMyInvite = $pdo->prepare("SELECT * FROM room_invites WHERE room_id=? AND LOWER(email)=? LIMIT 1");
$stMyInvite->execute([$roomId, $userEmail]);
$myInvite = $stMyInvite->fetch();

if (!$myInvite) {
    $myToken = bin2hex(random_bytes(32));
    $myKey = bin2hex(random_bytes(32));
    $ins = $pdo->prepare("INSERT INTO room_invites (room_id, email, token, status, display_name, participant_key, requested_at, approved_at)
                          VALUES (?, ?, ?, 'approved', ?, ?, NOW(), NOW())");
    $ins->execute([$roomId, $userEmail, $myToken, $userName, $myKey]);

    header('Location: room.php?token=' . urlencode($myToken));
    exit;
} else {
    if ($myInvite['status'] !== 'approved') {
        $myKey = !empty($myInvite['participant_key']) ? $myInvite['participant_key'] : bin2hex(random_bytes(32));
        $up = $pdo->prepare("UPDATE room_invites SET status='approved', approved_at=NOW(), display_name=COALESCE(NULLIF(display_name, ''), ?), participant_key=? WHERE id=?");
        $up->execute([$userName, $myKey, $myInvite['id']]);
        $stMyInvite->execute([$roomId, $userEmail]);
        $myInvite = $stMyInvite->fetch();
    }
    if ($myInvite['token'] !== $token) {
        header('Location: room.php?token=' . urlencode($myInvite['token']));
        exit;
    }
}

$me = $myInvite;
$me['room_name'] = $tokenInvite['room_name'];
$me['room_status'] = $tokenInvite['room_status'];
$me['owner_user_id'] = $tokenInvite['owner_user_id'];

$canAdmit = !empty($signedUser['active']) && (int)$signedUser['id'] === (int)$me['owner_user_id'];

$cursorQuery = $pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM signaling_messages WHERE room_id=?');
$cursorQuery->execute([$me['room_id']]);
$signalCursor = (int)$cursorQuery->fetchColumn();

// Gera ou obtém o token compartilhado da sala para o botão Convidar
$stRoomInvite = $pdo->prepare("SELECT token FROM room_invites WHERE room_id=? AND email='invite@sala.local' LIMIT 1");
$stRoomInvite->execute([$me['room_id']]);
$roomInviteToken = $stRoomInvite->fetchColumn();
if (!$roomInviteToken) {
    $roomInviteToken = bin2hex(random_bytes(32));
    $stIns = $pdo->prepare("INSERT INTO room_invites (room_id, email, token, status, display_name, participant_key, requested_at, approved_at) 
                            VALUES (?, 'invite@sala.local', ?, 'approved', 'Convite da Sala', ?, NOW(), NOW())");
    $stIns->execute([$me['room_id'], $roomInviteToken, bin2hex(random_bytes(32))]);
}

$baseUrl = (string)($config['app']['base_url'] ?? '/salareuniao');
if (str_starts_with($baseUrl, 'http://') || str_starts_with($baseUrl, 'https://')) {
    $inviteBase = rtrim($baseUrl, '/');
} else {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'https';
    $host = !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'maurinsoft.com.br';
    $inviteBase = $scheme . '://' . $host . '/' . ltrim($baseUrl, '/');
}
$inviteUrl = rtrim($inviteBase, '/') . '/room.php?token=' . urlencode($roomInviteToken);

$iceServers = $config['webrtc']['ice_servers'] ?? [];
$turn = $config['webrtc']['turn'] ?? [];
if (!empty($turn['enabled']) && !empty($turn['secret']) && !empty($turn['urls'])) {
    $ttl = max(300, (int)($turn['ttl'] ?? 3600));
    $turnUsername = (string)(time() + $ttl) . ':' . $me['participant_key'];
    $turnCredential = base64_encode(hash_hmac('sha1', $turnUsername, (string)$turn['secret'], true));
    $iceServers[] = [
        'urls' => $turn['urls'],
        'username' => $turnUsername,
        'credential' => $turnCredential,
    ];
}
$ice = json_encode($iceServers, JSON_UNESCAPED_SLASHES);
$wsEnabled = !empty($config['websocket']['enabled']) && !empty($config['websocket']['public_url']);
$wsUrl = $wsEnabled ? (string)$config['websocket']['public_url'] : '';
$wsReconnect = max(500, (int)($config['websocket']['reconnect_ms'] ?? 2000));
$maxMeshParticipants = (int)($config['webrtc']['max_mesh_participants'] ?? 4);
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
  <title><?=e($me['room_name'])?> - Maurinsoft Sala Reunião</title>
  <link rel="stylesheet" href="assets/css/salareuniao.css?v=20260919_3">
  <style>
    :root {
      --primary: #00d2ff;
      --brand-primary: #00d2ff;
      --border-glass: rgba(255, 255, 255, 0.12);
      --text-muted: #94a3b8;
      --text-dim: #64748b;
    }

    html {
      height: 100%;
      height: -webkit-fill-available;
      overflow: hidden;
    }

    body {
      background: #050811;
      overflow: hidden;
      margin: 0;
      padding: 0;
      width: 100vw;
      height: 100%;
      height: 100dvh;
      min-height: 100%;
      min-height: -webkit-fill-available;
      position: fixed;
      top: 0;
      left: 0;
      display: flex;
      flex-direction: column;
      font-family: var(--font-body, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif);
      color: #fff;
      -webkit-text-size-adjust: 100%;
      touch-action: manipulation;
    }

    /* Top Navigation Bar - Teams Style */
    .room-header {
      background: rgba(11, 17, 32, 0.95);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      border-bottom: 1px solid var(--border-glass);
      padding: 10px 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      height: 54px;
      box-sizing: border-box;
      z-index: 100;
      flex-shrink: 0;
    }

    .room-info {
      display: flex;
      align-items: center;
      gap: 12px;
      min-width: 0;
    }

    .room-title {
      font-size: 1.05rem;
      font-weight: 700;
      color: #fff;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 320px;
    }

    .room-timer {
      font-family: monospace;
      font-size: 0.88rem;
      color: var(--primary, #00d2ff);
      background: rgba(0, 210, 255, 0.1);
      padding: 3px 10px;
      border-radius: 20px;
      border: 1px solid rgba(0, 210, 255, 0.25);
      white-space: nowrap;
    }

    .header-actions {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-shrink: 0;
    }

    .header-btn {
      background: rgba(255, 255, 255, 0.07);
      border: 1px solid rgba(255, 255, 255, 0.12);
      color: #e2e8f0;
      padding: 6px 14px;
      border-radius: 8px;
      font-size: 0.84rem;
      font-weight: 600;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 6px;
      transition: all 0.2s ease;
      white-space: nowrap;
      user-select: none;
    }

    .header-btn:hover, .header-btn:active {
      background: rgba(255, 255, 255, 0.14);
      color: #fff;
    }

    .header-btn.active {
      background: rgba(0, 210, 255, 0.18);
      border-color: rgba(0, 210, 255, 0.4);
      color: var(--primary, #00d2ff);
    }

    /* Main Viewport Layout (Stage + Right Sidebar) */
    .room-main-layout {
      display: flex;
      flex-direction: row;
      flex: 1;
      height: calc(100% - 54px);
      height: calc(100dvh - 54px);
      width: 100vw;
      overflow: hidden;
      position: relative;
    }

    /* Stage Area - Centers Videos Responsively */
    .room-stage {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 20px;
      position: relative;
      overflow: hidden;
      background: #050811;
      min-width: 0;
      box-sizing: border-box;
    }

    .grid {
      display: flex;
      flex-wrap: wrap;
      gap: 16px;
      width: 100%;
      height: 100%;
      align-items: center;
      justify-content: center;
      padding-bottom: 74px; /* Space for floating controls dock */
      box-sizing: border-box;
      overflow-y: auto;
      -webkit-overflow-scrolling: touch;
    }

    /* Regra para 1 participante no desktop: Vídeo proporcional centralizado estilo Teams */
    .grid > .tile:only-child {
      max-width: 880px;
      max-height: 495px;
      width: 100%;
      height: auto;
      aspect-ratio: 16/9;
      margin: auto;
    }

    /* Regra para múltiplos participantes */
    .tile {
      position: relative;
      background: #0b1120;
      border: 1px solid rgba(255, 255, 255, 0.12);
      border-radius: 16px;
      overflow: hidden;
      aspect-ratio: 16/9;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.6);
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
      flex: 1 1 360px;
      max-width: calc(50% - 12px);
      max-height: calc(50% - 12px);
      min-width: 280px;
    }

    .tile video {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
      background: #070b14;
    }

    /* Espelha vídeo local (efeito espelho natural) */
    #tile-local video {
      transform: scaleX(-1);
    }

    .tile .name {
      position: absolute;
      left: 12px;
      bottom: 12px;
      background: rgba(11, 17, 32, 0.85);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      color: #f8fafc;
      padding: 4px 10px;
      border-radius: 6px;
      font-size: 0.82rem;
      font-weight: 600;
      border: 1px solid rgba(255, 255, 255, 0.15);
      z-index: 5;
      pointer-events: none;
    }

    .tile .state {
      position: absolute;
      right: 12px;
      top: 12px;
      background: rgba(0, 210, 255, 0.2);
      color: var(--primary, #00d2ff);
      padding: 3px 8px;
      border-radius: 6px;
      font-size: 0.72rem;
      font-weight: 700;
      letter-spacing: 0.05em;
      border: 1px solid rgba(0, 210, 255, 0.4);
      z-index: 5;
      pointer-events: none;
    }

    /* Floating Bottom Dock - Microsoft Teams Style */
    .controls {
      position: absolute;
      bottom: 22px;
      left: 50%;
      transform: translateX(-50%);
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 8px 18px;
      background: rgba(15, 23, 42, 0.94);
      backdrop-filter: blur(24px);
      -webkit-backdrop-filter: blur(24px);
      border: 1px solid rgba(255, 255, 255, 0.16);
      border-radius: 9999px;
      box-shadow: 0 12px 40px rgba(0, 0, 0, 0.75);
      z-index: 80;
    }

    .controls button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 9px 16px;
      border-radius: 9999px;
      border: 1px solid transparent;
      font-family: inherit;
      font-size: 0.85rem;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
      background: rgba(255, 255, 255, 0.08);
      color: #fff;
      user-select: none;
      -webkit-tap-highlight-color: transparent;
    }

    .controls button:hover, .controls button:active {
      background: rgba(255, 255, 255, 0.18);
      transform: translateY(-2px);
    }

    .controls button.active {
      background: rgba(0, 210, 255, 0.2);
      border-color: rgba(0, 210, 255, 0.45);
      color: var(--primary, #00d2ff);
    }

    .controls button.btn-danger {
      background: #dc2626;
      border-color: #ef4444;
      color: #fff;
      padding: 9px 20px;
    }

    .controls button.btn-danger:hover, .controls button.btn-danger:active {
      background: #b91c1c;
    }

    /* Right-side Docked Drawer - Teams Style */
    .sidebar {
      width: 360px;
      min-width: 320px;
      max-width: 400px;
      height: 100%;
      background: rgba(11, 17, 32, 0.97);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      border-left: 1px solid var(--border-glass);
      display: flex;
      flex-direction: column;
      z-index: 70;
      transition: transform 0.25s ease, opacity 0.25s ease;
      box-shadow: -6px 0 25px rgba(0, 0, 0, 0.5);
    }

    .sidebar.sr-hidden {
      display: none !important;
    }

    .sidebar-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 10px 16px;
      border-bottom: 1px solid var(--border-glass);
      background: rgba(15, 23, 42, 0.6);
      flex-shrink: 0;
    }

    .sidebar-tabs {
      display: flex;
      gap: 4px;
    }

    .sidebar-tab-btn {
      padding: 7px 14px;
      background: none;
      border: none;
      color: var(--text-muted, #94a3b8);
      font-size: 0.86rem;
      font-weight: 600;
      border-radius: 6px;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 6px;
      transition: all 0.2s;
    }

    .sidebar-tab-btn:hover, .sidebar-tab-btn:active {
      background: rgba(255, 255, 255, 0.06);
      color: #fff;
    }

    .sidebar-tab-btn.active {
      background: rgba(0, 210, 255, 0.14);
      color: var(--primary, #00d2ff);
    }

    .sidebar-close-btn {
      background: none;
      border: none;
      color: var(--text-muted, #94a3b8);
      font-size: 1.1rem;
      cursor: pointer;
      padding: 6px 10px;
      border-radius: 6px;
      line-height: 1;
      display: flex;
      align-items: center;
      gap: 4px;
    }

    .sidebar-close-btn:hover, .sidebar-close-btn:active {
      background: rgba(255, 255, 255, 0.1);
      color: #fff;
    }

    .sidepane {
      display: none;
      padding: 16px;
      flex: 1;
      overflow-y: auto;
      min-height: 0;
      -webkit-overflow-scrolling: touch;
    }

    .sidepane.active {
      display: flex;
      flex-direction: column;
    }

    .chatWrap {
      display: flex;
      flex-direction: column;
      height: 100%;
    }

    .chatMessages {
      flex: 1;
      overflow-y: auto;
      display: flex;
      flex-direction: column;
      gap: 10px;
      padding-bottom: 12px;
      max-height: calc(100vh - 200px);
      -webkit-overflow-scrolling: touch;
    }

    .chatMsg {
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid var(--border-glass);
      border-radius: 10px;
      padding: 10px 12px;
      font-size: 0.88rem;
      max-width: 90%;
      align-self: flex-start;
    }

    .chatMsg.self {
      background: rgba(0, 210, 255, 0.12);
      border-color: rgba(0, 210, 255, 0.3);
      align-self: flex-end;
    }

    .chatMeta {
      font-size: 0.72rem;
      color: var(--text-muted, #94a3b8);
      margin-bottom: 4px;
    }

    .chatText {
      white-space: pre-wrap;
      word-break: break-word;
      color: #f1f5f9;
    }

    .chatForm {
      display: flex;
      gap: 8px;
      margin-top: auto;
      padding-top: 10px;
      border-top: 1px solid var(--border-glass);
      flex-shrink: 0;
    }

    .chatForm textarea {
      flex: 1;
      resize: none;
      height: 48px;
      background: rgba(15, 23, 42, 0.6);
      border: 1px solid var(--border-glass);
      border-radius: 8px;
      padding: 10px;
      color: #fff;
      font-family: inherit;
      font-size: 0.86rem;
      box-sizing: border-box;
    }

    .chatForm textarea:focus {
      outline: none;
      border-color: var(--primary, #00d2ff);
    }

    .person {
      padding: 10px 12px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.08);
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-radius: 6px;
    }

    .person:hover {
      background: rgba(255, 255, 255, 0.04);
    }

    .person .badges {
      font-size: 0.75rem;
      color: var(--text-muted, #94a3b8);
      margin-top: 2px;
    }

    .dot {
      display: inline-block;
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #10b981;
      margin-right: 8px;
      box-shadow: 0 0 8px rgba(16, 185, 129, 0.6);
    }

    /* ==============================================================================
       RESPONSIVIDADE COMPLETA PARA SMARTPHONES E TELAS MÓVEIS (<= 768px)
       ============================================================================== */
    @media (max-width: 768px) {
      /* Header Compacto */
      .room-header {
        height: 48px;
        padding: max(6px, env(safe-area-inset-top, 6px)) 10px 6px 10px;
        gap: 6px;
      }

      .room-info {
        gap: 8px;
      }

      .sr-brand-logo {
        width: 28px !important;
        height: 28px !important;
        font-size: 13px !important;
        border-radius: 6px !important;
      }

      .room-title {
        font-size: 0.86rem;
        max-width: 110px;
      }

      #status {
        display: none;
      }

      #iceRoute {
        display: none !important;
      }

      .room-timer {
        font-size: 0.72rem;
        padding: 2px 6px;
      }

      .header-actions {
        gap: 5px;
      }

      .header-btn {
        padding: 6px 8px;
        font-size: 0.8rem;
        border-radius: 8px;
        gap: 3px;
      }

      .header-btn .header-btn-text,
      .header-btn .online-text {
        display: none !important;
      }

      /* Layout Principal Mobile */
      .room-main-layout {
        height: calc(100% - 48px);
        height: calc(100dvh - 48px);
      }

      .room-stage {
        padding: 6px 6px 74px 6px;
        justify-content: center;
      }

      .grid {
        gap: 8px;
        padding-bottom: 0;
        align-content: center;
        justify-content: center;
      }

      /* 1 Participante no Celular */
      .grid > .tile:only-child {
        width: 100%;
        max-width: 100%;
        height: 100%;
        max-height: calc(100dvh - 134px);
        aspect-ratio: auto;
        border-radius: 14px;
        margin: 0;
      }

      /* 2 Participantes no Celular (Em pé / Retrato) */
      @media (orientation: portrait) {
        .grid[data-count="2"],
        .grid:has(> .tile:nth-child(2):last-child) {
          flex-direction: column;
          flex-wrap: nowrap;
        }

        .grid[data-count="2"] .tile,
        .grid:has(> .tile:nth-child(2):last-child) .tile {
          width: 100%;
          max-width: 100%;
          flex: 1 1 0;
          min-height: 0;
          max-height: calc(50% - 4px);
          aspect-ratio: auto;
          border-radius: 12px;
          min-width: 0;
        }
      }

      /* 2 Participantes no Celular (Deitado / Paisagem) */
      @media (orientation: landscape) {
        .grid[data-count="2"],
        .grid:has(> .tile:nth-child(2):last-child) {
          flex-direction: row;
          flex-wrap: nowrap;
        }

        .grid[data-count="2"] .tile,
        .grid:has(> .tile:nth-child(2):last-child) .tile {
          width: calc(50% - 4px);
          max-width: calc(50% - 4px);
          flex: 1 1 0;
          height: 100%;
          max-height: 100%;
          aspect-ratio: auto;
          border-radius: 12px;
          min-width: 0;
        }
      }

      /* 3 ou 4 Participantes no Celular */
      .grid[data-count="3"] .tile,
      .grid[data-count="4"] .tile,
      .grid:has(> .tile:nth-child(3)) .tile {
        flex: 1 1 calc(50% - 4px);
        max-width: calc(50% - 4px);
        min-width: 0;
        max-height: calc(50% - 4px);
        aspect-ratio: 1;
        border-radius: 10px;
      }

      /* 5+ Participantes */
      .grid[data-count="5"] .tile,
      .grid:has(> .tile:nth-child(5)) .tile {
        flex: 1 1 calc(50% - 4px);
        max-width: calc(50% - 4px);
        min-width: 0;
        aspect-ratio: 4/3;
        border-radius: 10px;
      }

      .tile .name {
        font-size: 0.72rem;
        padding: 2px 6px;
        bottom: 6px;
        left: 6px;
        max-width: 75%;
        border-radius: 4px;
      }

      .tile .state {
        font-size: 0.64rem;
        padding: 2px 5px;
        top: 6px;
        right: 6px;
        border-radius: 4px;
      }

      #localAvatar {
        width: 64px !important;
        height: 64px !important;
        font-size: 1.6rem !important;
      }

      /* Dock Flutuante de Controles no Celular */
      .controls {
        bottom: max(12px, env(safe-area-inset-bottom, 12px));
        padding: 6px 12px;
        gap: 10px;
        border-radius: 9999px;
        width: auto;
        max-width: calc(100vw - 20px);
        box-sizing: border-box;
        justify-content: center;
        z-index: 85;
      }

      /* Oculta compartilhamento de tela no celular */
      #screen {
        display: none !important;
      }

      .controls button {
        width: 44px;
        height: 44px;
        min-width: 44px;
        padding: 0;
        border-radius: 50%;
        font-size: 1.18rem;
        gap: 0;
      }

      .controls button .btn-label {
        display: none !important;
      }

      .controls button.btn-danger {
        width: 44px;
        height: 44px;
        min-width: 44px;
        padding: 0;
        border-radius: 50%;
      }

      /* Gaveta Lateral (Chat / Participantes) em Tela Cheia no Celular */
      .sidebar {
        position: fixed !important;
        inset: 0 !important;
        top: 0 !important;
        left: 0 !important;
        right: 0 !important;
        bottom: 0 !important;
        width: 100vw !important;
        max-width: 100vw !important;
        height: 100% !important;
        height: 100dvh !important;
        z-index: 250 !important;
        border-left: none;
        box-shadow: none;
        background: rgba(11, 17, 32, 0.98);
      }

      .sidebar-header {
        padding: max(10px, env(safe-area-inset-top, 10px)) 14px 10px 14px;
        height: 52px;
      }

      .sidebar-tab-btn {
        padding: 6px 10px;
        font-size: 0.82rem;
      }

      .sidebar-close-btn {
        font-size: 1.2rem;
        padding: 6px 12px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 8px;
        color: #fff;
      }

      .chatMessages {
        max-height: none !important;
        flex: 1;
        padding-bottom: 8px;
      }

      .chatForm {
        padding-bottom: max(12px, env(safe-area-inset-bottom, 12px));
      }

      .chatForm textarea {
        font-size: 16px; /* Evita auto-zoom do Safari iOS */
        height: 44px;
      }

      .chatForm button {
        height: 44px;
        padding: 0 14px;
      }

      /* Toasts e Alertas em Telas Estreitas */
      #lobbyToast {
        top: max(54px, calc(env(safe-area-inset-top, 0px) + 50px)) !important;
        width: calc(100% - 24px) !important;
        max-width: 360px !important;
        flex-direction: column !important;
        padding: 12px 14px !important;
        gap: 10px !important;
        border-radius: 16px !important;
        text-align: center !important;
      }

      #lobbyToastButtons {
        width: 100% !important;
        justify-content: stretch !important;
      }

      #lobbyToastButtons button {
        flex: 1 !important;
        min-height: 38px !important;
      }

      #waitingNotice {
        top: max(54px, calc(env(safe-area-inset-top, 0px) + 50px)) !important;
        width: calc(100% - 24px) !important;
        max-width: 360px !important;
        flex-direction: column !important;
        padding: 10px 14px !important;
        gap: 8px !important;
        border-radius: 16px !important;
        text-align: center !important;
      }

      #waitingNotice button {
        width: 100% !important;
        min-height: 36px !important;
      }

      #sr-toast {
        bottom: max(80px, calc(env(safe-area-inset-bottom, 16px) + 74px)) !important;
        max-width: calc(100vw - 32px) !important;
        font-size: 0.84rem !important;
        text-align: center !important;
      }
    }

    @media (max-width: 380px) {
      .room-title {
        max-width: 90px;
      }
      .room-timer {
        display: none;
      }
      .controls {
        gap: 6px;
        padding: 5px 8px;
      }
      .controls button {
        width: 40px;
        height: 40px;
        min-width: 40px;
        font-size: 1.1rem;
      }
    }
  </style>
</head>
<body>

  <!-- Top Navigation Bar - Microsoft Teams Style -->
  <header class="room-header">
    <div class="room-info">
      <div class="sr-brand-logo" style="width: 32px; height: 32px; font-size: 15px; border-radius: 8px;">M</div>
      <div>
        <div class="room-title"><?=e($me['room_name'])?></div>
        <small id="status" style="color: var(--text-muted, #94a3b8); font-size: 0.76rem;">Conectado</small>
      </div>
    </div>

    <div class="header-actions">
      <div id="peerConnectionCounter" style="font-size: 0.78rem; color: var(--text-muted, #94a3b8); margin-right: 4px; display: flex; align-items: center; gap: 4px;">
        Participantes: <strong>1</strong> · Peers esperados: <strong>0</strong> · Conectados: <strong>0</strong>
      </div>
      <span class="room-timer" id="meetingTimer">00:00:00</span>
      <span id="iceRoute" style="font-size: 0.76rem; color: var(--text-dim, #64748b);"></span>
      
      <!-- Botão de Diagnóstico WebRTC em tempo real -->
      <button type="button" class="header-btn" onclick="MeetingDiagnostics.openDiagnosticsModal()" title="Diagnóstico WebRTC em tempo real">
        📊 <span class="header-btn-text">Diagnóstico</span>
      </button>

      <!-- Botões de Ação Topo Estilo Teams -->
      <button type="button" class="header-btn" id="topBtnParticipants" onclick="openSidebarTab('participants')" title="Lista de Participantes">
        👥 <span id="participantBadgeText"><span class="participant-count-num">1</span><span class="online-text"> Online</span></span>
      </button>
      
      <button type="button" class="header-btn" id="topBtnChat" onclick="openSidebarTab('chat')" title="Bate-papo">
        💬 <span class="header-btn-text">Chat</span> <span id="chatBadgeCount" style="display:none; background: #ef4444; border-radius: 10px; padding: 1px 6px; font-size: 11px;">0</span>
      </button>

      <button type="button" class="header-btn" onclick="copyInvite()" title="Copiar link de convite">
        📋 <span class="header-btn-text">Convidar</span>
      </button>

      <a href="index.php" class="header-btn" style="text-decoration: none;" title="Voltar ao Painel">
        🏠 <span class="header-btn-text">Painel</span>
      </a>
    </div>
  </header>

  <!-- Main Viewport Layout (Stage + Right Docked Sidebar) -->
  <div class="room-main-layout">
    
    <!-- Central Video Stage -->
    <main class="room-stage">
      <!-- Aviso de Limite Mesh Excedido -->
      <div id="meshLimitWarning" style="display: none; position: absolute; top: 62px; left: 50%; transform: translateX(-50%); background: rgba(245, 158, 11, 0.95); backdrop-filter: blur(16px); color: #000; font-weight: 600; padding: 6px 18px; border-radius: 20px; z-index: 80; font-size: 0.82rem; box-shadow: 0 4px 20px rgba(0,0,0,0.5); align-items: center; gap: 8px;">
        ⚠️ Esta sala possui muitos participantes para o modo P2P. O desempenho pode ser reduzido.
      </div>

      <!-- Toast Flutuante de Admissão de Participantes (Estilo Teams) -->
      <div id="lobbyToast" style="display: none; position: absolute; top: 16px; left: 50%; transform: translateX(-50%); background: rgba(15, 23, 42, 0.95); backdrop-filter: blur(20px); border: 1px solid var(--primary, #00d2ff); border-radius: 30px; padding: 8px 18px; z-index: 90; box-shadow: 0 10px 35px rgba(0, 210, 255, 0.3); align-items: center; gap: 14px; animation: slideDown 0.3s ease;">
        <span style="font-size: 1.2rem;">🔔</span>
        <span id="lobbyGuestName" style="color: #f1f5f9; font-size: 0.88rem; font-weight: 600;">Participante está pedindo para entrar</span>
        <div style="display: flex; gap: 8px;" id="lobbyToastButtons">
          <!-- Botões injetados dinamicamente -->
        </div>
      </div>
      
      <!-- Banner de espera por outros participantes -->
      <div id="waitingNotice" style="display: none; position: absolute; top: 16px; left: 50%; transform: translateX(-50%); background: rgba(15, 23, 42, 0.92); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 30px; padding: 8px 18px; z-index: 60; box-shadow: 0 10px 30px rgba(0,0,0,0.6); align-items: center; gap: 12px;">
        <span style="color: #cbd5e1; font-size: 0.84rem;">⏳ Você é o único na sala. Aguardando outros participantes...</span>
        <button onclick="copyInvite()" class="sr-btn sr-btn-primary" style="padding: 4px 12px; font-size: 0.78rem; border-radius: 20px;">📋 Copiar Link de Convite</button>
      </div>

      <!-- Grid de Vídeos Centralizado -->
      <div class="grid" id="videos">
        <div class="tile" id="tile-local">
          <video id="local" autoplay muted playsinline webkit-playsinline></video>
          <div id="localAvatar" style="display: none; width: 88px; height: 88px; border-radius: 50%; background: linear-gradient(135deg, var(--brand-primary, #00d2ff), #3b82f6); color: #fff; font-size: 2.2rem; font-weight: 700; align-items: center; justify-content: center; position: absolute; z-index: 2; box-shadow: 0 4px 25px rgba(0,0,0,0.6);">
            <?= strtoupper(substr(trim($me['display_name'] ?: 'U'), 0, 1)) ?>
          </div>
          <div class="name">Você (<?=e($me['display_name'])?>)</div>
          <div class="state" id="localState">AO VIVO</div>
        </div>
      </div>

      <!-- Floating Bottom Dock (Microsoft Teams Style) -->
      <div class="controls">
        <button type="button" id="mic" class="active" onclick="MeetingMedia.toggleMicrophone()" title="Ativar/Desativar Microfone">
          🎙️ <span class="btn-label" id="micLabel">Microfone</span>
        </button>
        <button type="button" id="cam" class="active" onclick="MeetingMedia.toggleCamera()" title="Ligar/Desligar Câmera">
          📹 <span class="btn-label" id="camLabel">Câmera</span>
        </button>
        <button type="button" id="screen" onclick="MeetingMedia.toggleScreen()" title="Compartilhar Tela">
          🖥️ <span class="btn-label">Compartilhar</span>
        </button>
        <button type="button" id="btnDockChat" onclick="openSidebarTab('chat')" title="Abrir Chat">
          💬 <span class="btn-label">Chat</span>
        </button>
        <button type="button" id="btnDockParticipants" onclick="openSidebarTab('participants')" title="Ver Participantes">
          👥 <span class="btn-label">Participantes</span>
        </button>
        <button type="button" id="leave" class="btn-danger" onclick="MeetingApp.leaveRoom(true)" title="Sair da Reunião">
          🔴 <span class="btn-label">Sair</span>
        </button>
      </div>
    </main>

    <!-- Microsoft Teams Style Right-Side Docked Sidebar -->
    <aside class="sidebar sr-hidden" id="roomSidebar">
      <div class="sidebar-header">
        <div class="sidebar-tabs">
          <button type="button" id="tabChat" class="sidebar-tab-btn active" onclick="setSideTab('chat')">
            💬 Chat <span id="chatUnread" class="sr-badge sr-badge-scheduled" style="display:none; padding: 1px 6px; font-size: 10px;">0</span>
          </button>
          <button type="button" id="tabParticipants" class="sidebar-tab-btn" onclick="setSideTab('participants')">
            👥 Participantes (<span id="sidebarParticipantCount">1</span>)
          </button>
        </div>
        <button type="button" class="sidebar-close-btn" onclick="toggleSidebar(false)" title="Fechar Painel">✕ <span class="header-btn-text" style="font-size:0.82rem;font-weight:600;">Fechar</span></button>
      </div>

      <!-- Chat Pane -->
      <div id="paneChat" class="sidepane active">
        <div class="chatWrap">
          <div class="chatMessages" id="chatMessages">
            <div class="chatMsg" style="background: rgba(0, 210, 255, 0.08); border: 1px dashed rgba(0, 210, 255, 0.2);">
              <div class="chatMeta">Sistema Maurinsoft</div>
              <div class="chatText">Bem-vindo(a) à sala de reunião criptografada ponta-a-ponta. As mensagens enviadas aqui são visíveis para todos os participantes da sessão.</div>
            </div>
          </div>
          <form id="chatForm" class="chatForm">
            <textarea id="chatInput" placeholder="Digite uma mensagem e pressione Enter..." rows="2"></textarea>
            <button type="submit" class="sr-btn sr-btn-primary sr-btn-sm" style="align-self: flex-end; height: 48px; padding: 0 16px;">
              Enviar
            </button>
          </form>
        </div>
      </div>

      <!-- Participants Pane -->
      <div id="paneParticipants" class="sidepane">
        <div style="font-size: 0.82rem; color: var(--text-muted, #94a3b8); margin-bottom: 12px;">
          Participantes conectados nesta chamada:
        </div>
        <!-- Seção Sala de Espera na Barra Lateral -->
        <div id="sidebarWaitingSection" style="display: none; margin-bottom: 18px; background: rgba(0, 210, 255, 0.06); border: 1px solid rgba(0, 210, 255, 0.2); border-radius: 10px; padding: 12px;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <strong style="font-size: 0.84rem; color: var(--primary, #00d2ff);">⏳ Sala de Espera (<span id="sidebarWaitingCount">0</span>)</strong>
          </div>
          <div id="sidebarWaitingList"></div>
        </div>
        <div id="participants"></div>
      </div>
    </aside>

  </div>

  <div id="sr-toast" class="sr-toast" style="position: fixed; bottom: 84px; left: 50%; transform: translateX(-50%); background: rgba(15, 23, 42, 0.95); backdrop-filter: blur(16px); border: 1px solid var(--border-glass, rgba(255, 255, 255, 0.15)); border-radius: 20px; padding: 10px 20px; font-size: 0.88rem; color: #fff; z-index: 1000; box-shadow: 0 10px 30px rgba(0,0,0,0.6); display: none;">Link copiado!</div>

  <script>
    // Meeting Timer
    let meetingSeconds = 0;
    setInterval(() => {
      meetingSeconds++;
      const hrs = String(Math.floor(meetingSeconds / 3600)).padStart(2, '0');
      const mins = String(Math.floor((meetingSeconds % 3600) / 60)).padStart(2, '0');
      const secs = String(meetingSeconds % 60).padStart(2, '0');
      const el = document.getElementById('meetingTimer');
      if (el) el.textContent = hrs + ':' + mins + ':' + secs;
    }, 1000);

    function copyInvite() {
      const url = <?=json_encode($inviteUrl)?>;
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(url).then(() => showToast('Link de convite copiado!'));
      } else {
        const input = document.createElement('input');
        input.value = url;
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        document.body.removeChild(input);
        showToast('Link de convite copiado!');
      }
    }

    function showToast(text) {
      const toast = document.getElementById('sr-toast');
      if (toast) {
        if (text) toast.textContent = text;
        toast.classList.add('show');
        setTimeout(() => toast.classList.remove('show'), 4000);
      }
    }

    function toggleSidebar(forceState) {
      const sb = document.getElementById('roomSidebar');
      if (!sb) return;
      if (typeof forceState === 'boolean') {
        sb.classList.toggle('sr-hidden', !forceState);
      } else {
        sb.classList.toggle('sr-hidden');
      }
      updateSidebarActiveButtons();
    }

    function openSidebarTab(tab) {
      const sb = document.getElementById('roomSidebar');
      if (sb && sb.classList.contains('sr-hidden')) {
        sb.classList.remove('sr-hidden');
      }
      setSideTab(tab);
      updateSidebarActiveButtons();
    }

    function updateSidebarActiveButtons() {
      const sb = document.getElementById('roomSidebar');
      const isVisible = sb && !sb.classList.contains('sr-hidden');
      const isChat = isVisible && document.getElementById('paneChat').classList.contains('active');
      const isPart = isVisible && document.getElementById('paneParticipants').classList.contains('active');
      
      const topChat = document.getElementById('topBtnChat');
      const topPart = document.getElementById('topBtnParticipants');
      const dockChat = document.getElementById('btnDockChat');
      const dockPart = document.getElementById('btnDockParticipants');

      if (topChat) topChat.classList.toggle('active', isChat);
      if (topPart) topPart.classList.toggle('active', isPart);
      if (dockChat) dockChat.classList.toggle('active', isChat);
      if (dockPart) dockPart.classList.toggle('active', isPart);
    }

    function setSideTab(tab) {
      const isChat = (tab === 'chat');
      document.getElementById('paneParticipants').classList.toggle('active', !isChat);
      document.getElementById('paneChat').classList.toggle('active', isChat);
      document.getElementById('tabParticipants').classList.toggle('active', !isChat);
      document.getElementById('tabChat').classList.toggle('active', isChat);
      if (window.MeetingChat) {
        window.MeetingChat.setChatOpen(isChat);
      }
      updateSidebarActiveButtons();
    }

    // Configurações Globais injetadas pelo PHP
    window.MEETING_CONFIG = {
      CAN_ADMIT: <?=json_encode((bool)$canAdmit)?>,
      CSRF: <?=json_encode(csrf_token())?>,
      TOKEN: <?=json_encode($token)?>,
      ICE_SERVERS: <?=$ice?>,
      WS_URL: <?=json_encode($wsUrl)?>,
      WS_ENABLED: <?=json_encode($wsEnabled)?>,
      WS_RECONNECT_MS: <?=$wsReconnect?>,
      MAX_MESH_PARTICIPANTS: <?=$maxMeshParticipants?>,
      selfKey: <?=json_encode($me['participant_key'])?>,
      roomId: <?=(int)$me['room_id']?>,
      displayName: <?=json_encode($me['display_name'])?>,
      inviteUrl: <?=json_encode($inviteUrl)?>
    };
  </script>

  <!-- Módulos JavaScript Especializados do Sala Reunião -->
  <script src="assets/js/meeting/logger.js?v=20260920_1"></script>
  <script src="assets/js/meeting/media.js?v=20260920_1"></script>
  <script src="assets/js/meeting/signaling.js?v=20260920_1"></script>
  <script src="assets/js/meeting/webrtc.js?v=20260920_1"></script>
  <script src="assets/js/meeting/participants.js?v=20260920_1"></script>
  <script src="assets/js/meeting/chat.js?v=20260920_1"></script>
  <script src="assets/js/meeting/diagnostics.js?v=20260920_1"></script>
  <script src="assets/js/meeting/meeting.js?v=20260920_1"></script>

  <script>
    // Inicialização do Chat e Orquestrador
    document.getElementById('chatForm').addEventListener('submit', async e => {
      e.preventDefault();
      const input = document.getElementById('chatInput');
      const value = input.value;
      if (!value.trim()) return;
      input.disabled = true;
      try {
        await MeetingChat.sendChatMessage(value);
        input.value = '';
      } catch (err) {
        alert('Não foi possível enviar a mensagem.');
      } finally {
        input.disabled = false;
        input.focus();
      }
    });

    document.getElementById('chatInput').addEventListener('keydown', e => {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        document.getElementById('chatForm').requestSubmit();
      }
    });

    // Inicia a aplicação
    MeetingSignaling.setSignalCursor(<?=$signalCursor?>);
    MeetingApp.initMeeting();
  </script>
</body>
</html>
