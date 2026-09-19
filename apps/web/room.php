<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$token = $_GET['token'] ?? '';
$st = $pdo->prepare("SELECT i.*, r.name room_name, r.status room_status, r.owner_user_id FROM room_invites i JOIN rooms r ON r.id=i.room_id WHERE i.token=? AND i.status='approved' LIMIT 1");
$st->execute([$token]);
$me = $st->fetch();

if (!$me) {
    http_response_code(403);
    exit('Entrada não autorizada.');
}
if ($me['room_status'] !== 'open') {
    exit('A sala ainda não foi aberta pelo administrador ou foi encerrada.');
}

$signedUser = current_user();
$canAdmit = $signedUser && !empty($signedUser['active']) && (int)$signedUser['id'] === (int)$me['owner_user_id'];

$cursorQuery = $pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM signaling_messages WHERE room_id=?');
$cursorQuery->execute([$me['room_id']]);
$signalCursor = (int)$cursorQuery->fetchColumn();

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
$inviteUrl = rtrim((string)$config['app']['base_url'], '/') . '/join.php?room_id=' . (int)$me['room_id'];
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
      <span class="room-timer" id="meetingTimer">00:00:00</span>
      <span id="iceRoute" style="font-size: 0.76rem; color: var(--text-dim, #64748b);"></span>
      
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
        <button type="button" id="mic" class="active" title="Ativar/Desativar Microfone">
          🎙️ <span class="btn-label" id="micLabel">Microfone</span>
        </button>
        <button type="button" id="cam" class="active" title="Ligar/Desligar Câmera">
          📹 <span class="btn-label" id="camLabel">Câmera</span>
        </button>
        <button type="button" id="screen" title="Compartilhar Tela">
          🖥️ <span class="btn-label">Compartilhar</span>
        </button>
        <button type="button" id="btnDockChat" onclick="openSidebarTab('chat')" title="Abrir Chat">
          💬 <span class="btn-label">Chat</span>
        </button>
        <button type="button" id="btnDockParticipants" onclick="openSidebarTab('participants')" title="Ver Participantes">
          👥 <span class="btn-label">Participantes</span>
        </button>
        <button type="button" id="leave" class="btn-danger" onclick="leaveRoom(true)" title="Sair da Reunião">
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
      if (sb) {
        if (sb.classList.contains('sr-hidden')) {
          sb.classList.remove('sr-hidden');
        }
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
      chatOpen = (tab === 'chat');
      document.getElementById('paneParticipants').classList.toggle('active', !chatOpen);
      document.getElementById('paneChat').classList.toggle('active', chatOpen);
      document.getElementById('tabParticipants').classList.toggle('active', !chatOpen);
      document.getElementById('tabChat').classList.toggle('active', chatOpen);
      if (chatOpen) {
        chatUnread = 0;
        updateUnread();
        setTimeout(() => {
          const w = document.getElementById('chatMessages');
          if (w) w.scrollTop = w.scrollHeight;
        }, 0);
      }
      updateSidebarActiveButtons();
    }
  </script>

  <script>

const CAN_ADMIT=<?=json_encode((bool)$canAdmit)?>;
const CSRF=<?=json_encode(csrf_token())?>;
const TOKEN=<?=json_encode($token)?>;
const ICE_SERVERS=<?=$ice?>;
const WS_URL=<?=json_encode($wsUrl)?>;
const WS_ENABLED=<?=json_encode($wsEnabled)?>;
const WS_RECONNECT_MS=<?=$wsReconnect?>;
const pcs=new Map(), pendingIce=new Map(), participantNames=new Map();
const peerState=new Map(), signalQueues=new Map(), deliveredSignals=new Set(), lastPresence=new Map();
let signalPollRunning=false;
let lastId=<?=json_encode($signalCursor)?>,selfKey=<?=json_encode($me['participant_key'])?>,localStream=null,cameraTrack=null,screenTrack=null;
let micEnabled=true,camEnabled=true,screenSharing=false,leaving=false,lastChatId=0,chatOpen=false,chatUnread=0,ws=null,wsReady=false,wsReconnectTimer=null;

async function jsonFetch(url,options={}){
  const r=await fetch(url,options);
  if(!r.ok) throw new Error('HTTP '+r.status);
  return r.json();
}
async function send(type,payload={},recipient=null){
  if(wsReady&&ws){
    try {
      ws.send(JSON.stringify({type:'signal',signalType:type,payload,recipient}));
      return {ok:true,transport:'websocket'};
    } catch(e) { wsReady=false; }
  }
  return jsonFetch('api/signal_send.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token:TOKEN,type,payload,recipient})});
}

// ==============================================================================
// GESTÃO DE SALA DE ESPERA E ADMISSÃO DE CONVIDADOS (ESTILO TEAMS)
// ==============================================================================
let currentWaiting = [];
let knownWaitingIds = new Set();

function playLobbyChime() {
  try {
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = 'sine';
    osc.frequency.setValueAtTime(587.33, ctx.currentTime); // D5
    osc.frequency.setValueAtTime(880, ctx.currentTime + 0.12); // A5
    gain.gain.setValueAtTime(0.15, ctx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.4);
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.start();
    osc.stop(ctx.currentTime + 0.4);
  } catch(e) {}
}

function escapeHtml(str) {
  return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function renderWaitingList(waitingList) {
  if (!CAN_ADMIT) return;
  currentWaiting = waitingList || [];
  
  // Detecta se há novos convidados para tocar chime
  let hasNew = false;
  currentWaiting.forEach(w => {
    if (!knownWaitingIds.has(w.id)) {
      knownWaitingIds.add(w.id);
      hasNew = true;
    }
  });
  if (hasNew && currentWaiting.length > 0) {
    playLobbyChime();
  }

  // 1. Atualiza Seção na Barra Lateral de Participantes
  const waitSec = document.getElementById('sidebarWaitingSection');
  const waitCount = document.getElementById('sidebarWaitingCount');
  const waitList = document.getElementById('sidebarWaitingList');

  if (waitSec && waitList) {
    if (currentWaiting.length > 0) {
      waitSec.style.display = 'block';
      if (waitCount) waitCount.textContent = currentWaiting.length;
      waitList.innerHTML = '';
      currentWaiting.forEach(w => {
        const item = document.createElement('div');
        item.style.cssText = 'background: rgba(15, 23, 42, 0.7); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 8px 10px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center;';
        item.innerHTML = `
          <div>
            <div style="font-size: 0.84rem; font-weight: 600; color: #fff;">${escapeHtml(w.display_name)}</div>
            <div style="font-size: 0.72rem; color: var(--text-muted, #94a3b8);">Aguardando aprovação</div>
          </div>
          <div style="display: flex; gap: 6px;">
            <button type="button" class="sr-btn sr-btn-success" style="padding: 3px 8px; font-size: 0.74rem; border-radius: 4px; background: #10b981; border: none; color: #fff; cursor: pointer;" onclick="admitGuest(${w.id}, 'approve')">Permitir</button>
            <button type="button" class="sr-btn sr-btn-danger" style="padding: 3px 8px; font-size: 0.74rem; border-radius: 4px; background: #ef4444; border: none; color: #fff; cursor: pointer;" onclick="admitGuest(${w.id}, 'reject')">Recusar</button>
          </div>
        `;
        waitList.appendChild(item);
      });
    } else {
      waitSec.style.display = 'none';
    }
  }

  // 2. Notificação Flutuante no Topo do Palco (Teams Toast)
  const toast = document.getElementById('lobbyToast');
  const toastGuest = document.getElementById('lobbyGuestName');
  const toastBtns = document.getElementById('lobbyToastButtons');

  if (toast && toastGuest && toastBtns) {
    if (currentWaiting.length > 0) {
      const g = currentWaiting[0];
      const extra = currentWaiting.length > 1 ? ` (+${currentWaiting.length - 1} outro${currentWaiting.length > 2 ? 's' : ''})` : '';
      toastGuest.textContent = `${g.display_name}${extra} está na sala de espera`;
      toastBtns.innerHTML = `
        <button type="button" class="sr-btn sr-btn-success" style="padding: 4px 12px; font-size: 0.8rem; border-radius: 20px; background: #10b981; border: none; color: #fff; cursor: pointer; font-weight: 600;" onclick="admitGuest(${g.id}, 'approve')">Permitir</button>
        <button type="button" class="sr-btn sr-btn-danger" style="padding: 4px 12px; font-size: 0.8rem; border-radius: 20px; background: rgba(239, 68, 68, 0.85); border: none; color: #fff; cursor: pointer; font-weight: 600;" onclick="admitGuest(${g.id}, 'reject')">Recusar</button>
      `;
      toast.style.display = 'flex';
    } else {
      toast.style.display = 'none';
    }
  }
}

async function admitGuest(inviteId, action) {
  try {
    const res = await jsonFetch('api/room_admit.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        host_token: TOKEN,
        csrf: CSRF,
        invite_id: inviteId,
        action: action
      })
    });
    if (res.ok) {
      showToast(action === 'approve' ? `${res.display_name} foi admitido(a)!` : `${res.display_name} foi recusado(a).`);
      currentWaiting = currentWaiting.filter(w => w.id !== inviteId);
      renderWaitingList(currentWaiting);
      // Força verificação imediata de presença
    } else {
      showToast('Não foi possível autorizar: atualize a página e tente novamente.');
    }
  } catch(e) {
    showToast('Não foi possível processar a admissão.');
  }
}

function updateButtons(){
  const micEl = document.getElementById('mic');
  const camEl = document.getElementById('cam');
  const screenEl = document.getElementById('screen');
  if (micEl) {
    micEl.classList.toggle('active', micEnabled);
    micEl.innerHTML = (micEnabled ? '🎙️' : '🔇') + ' <span class="btn-label" id="micLabel">' + (micEnabled ? 'Microfone' : 'Mudo') + '</span>';
  }
  if (camEl) {
    camEl.classList.toggle('active', camEnabled);
    camEl.innerHTML = (camEnabled ? '📹' : '🚫') + ' <span class="btn-label" id="camLabel">' + (camEnabled ? 'Câmera' : 'Câmera Desl.') + '</span>';
  }
  if (screenEl) {
    screenEl.classList.toggle('active', screenSharing);
    screenEl.innerHTML = '🖥️ <span class="btn-label">' + (screenSharing ? 'Parar' : 'Compartilhar') + '</span>';
  }
  const ls = document.getElementById('localState');
  if (ls) {
    ls.textContent = (micEnabled ? '🎙️' : '🔇') + ' ' + (camEnabled ? '📹' : '🚫') + (screenSharing ? ' 🖥️' : '');
  }
}
function updateVideoGridCount(){
  const wrap = document.getElementById('videos');
  if (!wrap) return;
  const count = wrap.querySelectorAll('.tile').length;
  wrap.dataset.count = String(count);
}
function ensureTile(key){
  let tile=document.getElementById('tile-'+key);
  if(tile) return tile;
  tile=document.createElement('div');tile.className='tile';tile.id='tile-'+key;
  const video=document.createElement('video');video.id='peer-'+key;video.autoplay=true;video.playsInline=true;
  video.setAttribute('playsinline', '');video.setAttribute('webkit-playsinline', '');
  const name=document.createElement('div');name.className='name';name.id='name-'+key;name.textContent=participantNames.get(key)||'Participante';
  const state=document.createElement('div');state.className='state';state.id='state-'+key;state.textContent='Conectando...';
  tile.append(video,name,state);document.getElementById('videos').appendChild(tile);
  updateVideoGridCount();
  return tile;
}
async function updateIceRoute(key,pc){
  try{
    const stats=await pc.getStats();
    let transport=null,pair=null,local=null,remote=null;
    stats.forEach(r=>{if(r.type==='transport'&&r.selectedCandidatePairId)transport=r;});
    if(transport)pair=stats.get(transport.selectedCandidatePairId);
    if(!pair){
      stats.forEach(r=>{if(r.type==='candidate-pair'&&r.state==='succeeded'&&r.nominated)pair=r;});
    }
    if(pair){
      local=stats.get(pair.localCandidateId);remote=stats.get(pair.remoteCandidateId);
      const lt=local?.candidateType||'?';
      const rt=remote?.candidateType||'?';
      const via=(lt==='relay'||rt==='relay')?'TURN relay':'P2P direto';
      document.getElementById('iceRoute').textContent='ICE: '+via+' ('+lt+' ↔ '+rt+')';
      const state=document.getElementById('state-'+key);
      if(state)state.dataset.route=via;
    }
  }catch(e){console.warn('ICE stats',e);}
}
function removePeer(key){
  const pc=pcs.get(key);
  pcs.delete(key);peerState.delete(key);pendingIce.delete(key);lastPresence.delete(key);
  if(pc){pc.onconnectionstatechange=null;pc.onnegotiationneeded=null;pc.close();}
  document.getElementById('tile-'+key)?.remove();
  updateVideoGridCount();
}
function queuePeer(key,task){
  const previous=signalQueues.get(key)||Promise.resolve();
  const next=previous.catch(()=>{}).then(task);
  signalQueues.set(key,next);
  next.finally(()=>{if(signalQueues.get(key)===next)signalQueues.delete(key);}).catch(()=>{});
  return next;
}
async function playRemoteVideo(video){
  try { await video.play(); }
  catch(e){
    // Keep the image visible if the browser requires a gesture for audio.
    video.muted=true;
    try { await video.play(); } catch(err) { console.warn('Vídeo remoto',err); }
    const tile=video.parentElement;
    if(tile.querySelector('.enable-audio'))return;
    const button=document.createElement('button');
    button.className='sr-btn sr-btn-primary enable-audio';
    button.textContent='Ativar áudio';
    button.style.cssText='position:absolute;top:12px;left:12px;z-index:2';
    button.onclick=async()=>{
      video.muted=false;
      try {await video.play();button.remove();}
      catch(err){video.muted=true;}
    };
    tile.appendChild(button);
  }
}
function peer(key){
  if(pcs.has(key))return pcs.get(key);
  const pc=new RTCPeerConnection({iceServers:ICE_SERVERS});
  const state={makingOffer:false,ignoreOffer:false,remoteStream:new MediaStream()};
  pcs.set(key,pc);peerState.set(key,state);ensureTile(key);
  pc.onnegotiationneeded=()=>queuePeer(key,()=>makeOffer(key)).catch(console.warn);
  pc.onicecandidate=e=>{if(e.candidate)send('ice',e.candidate,key).catch(console.warn);};
  pc.ontrack=e=>{
    if(pcs.get(key)!==pc)return;
    const video=document.getElementById('peer-'+key);
    if(!state.remoteStream.getTracks().some(t=>t.id===e.track.id))state.remoteStream.addTrack(e.track);
    video.srcObject=state.remoteStream;
    e.track.onunmute=()=>playRemoteVideo(video);
    playRemoteVideo(video);
  };
  pc.onconnectionstatechange=()=>{
    if(pcs.get(key)!==pc)return;
    const label=document.getElementById('state-'+key);
    if(label)label.textContent=pc.connectionState==='connected'?'Conectado':pc.connectionState==='failed'?'Tentando reconectar…':'Conectando…';
    if(pc.connectionState==='connected')updateIceRoute(key,pc);
    if(pc.connectionState==='failed')pc.restartIce();
  };
  // Reserve both senders so a camera/microphone enabled later can replaceTrack.
  const options={direction:'sendrecv',streams:localStream?[localStream]:[]};
  pc.addTransceiver(localStream?.getAudioTracks()[0]||'audio',options);
  pc.addTransceiver(screenTrack||cameraTrack||'video',options);
  return pc;
}
async function replaceOutgoingTrack(kind,track){
  for(const pc of pcs.values()){
    const transceiver=pc.getTransceivers().find(t=>t.receiver.track.kind===kind);
    if(transceiver)await transceiver.sender.replaceTrack(track);
    else if(track)pc.addTrack(track,localStream);
  }
}
async function flushIce(key){
  const pc=pcs.get(key);if(!pc||!pc.remoteDescription)return;
  const queue=pendingIce.get(key)||[];
  while(queue.length){try{await pc.addIceCandidate(queue.shift());}catch(e){console.warn('ICE',e);}}
  pendingIce.delete(key);
}
async function makeOffer(key){
  if(key===selfKey||leaving)return;
  const pc=pcs.get(key),state=peerState.get(key);
  if(!pc||!state||state.makingOffer||pc.signalingState!=='stable')return;
  try{
    state.makingOffer=true;
    await pc.setLocalDescription(await pc.createOffer());
    await send('offer',pc.localDescription,key);
  }finally{state.makingOffer=false;}
}
async function processSignal(m){
  const key=m.sender_key,p=m.payload||{};
  if(!key||key===selfKey||leaving)return;
  if(m.message_type==='peer-ready'){
    // Discover peers in either arrival order; no dependence on random key order.
    peer(key);return;
  }
  if(m.message_type==='leave'){
    if(p.removed){alert('Você foi removido da reunião pelo administrador.');await leaveRoom(false);return;}
    removePeer(key);return;
  }
  if(m.message_type==='offer'||m.message_type==='answer'){
    const pc=peer(key),state=peerState.get(key);
    const collision=m.message_type==='offer'&&(state.makingOffer||pc.signalingState!=='stable');
    state.ignoreOffer=collision&&selfKey<key;
    if(state.ignoreOffer){pendingIce.delete(key);return;}
    if(m.message_type==='answer'&&pc.signalingState!=='have-local-offer')return;
    // The polite peer rolls back its offer when both participants call at once.
    await pc.setRemoteDescription(p);
    await flushIce(key);
    if(m.message_type==='offer'){
      await pc.setLocalDescription(await pc.createAnswer());
      await send('answer',pc.localDescription,key);
    }
    return;
  }
  if(m.message_type==='ice'){
    const pc=peer(key),state=peerState.get(key);
    if(state.ignoreOffer)return;
    if(pc.remoteDescription){try{await pc.addIceCandidate(p);}catch(e){console.warn('ICE',e);}}
    else{const q=pendingIce.get(key)||[];q.push(p);pendingIce.set(key,q);}
  }
}
async function handleSignal(m){
  const id=m.id?String(m.id):null;
  if(id&&deliveredSignals.has(id))return;
  if(id)deliveredSignals.add(id);
  try{await queuePeer(m.sender_key,()=>processSignal(m));}
  catch(e){if(id)deliveredSignals.delete(id);throw e;}
  if(deliveredSignals.size>2000)deliveredSignals.delete(deliveredSignals.values().next().value);
}
async function pollSignals(){
  if(signalPollRunning)return;
  signalPollRunning=true;
  try{
    while(!leaving){
      try{
        // Also catch HTTP messages while other participants use WebSocket.
        const d=await jsonFetch('api/signal_poll.php?token='+encodeURIComponent(TOKEN)+'&after='+lastId,{cache:'no-store'});
        selfKey=d.self;
        for(const m of d.messages||[]){
          try{await handleSignal(m);}catch(e){console.warn('Sinalização',e);}
          lastId=Math.max(lastId,Number(m.id));
        }
      }catch(e){document.getElementById('status').textContent='Reconectando sinalização…';}
      await new Promise(resolve=>setTimeout(resolve,700));
    }
  }finally{signalPollRunning=false;}
}
// setSideTab handled in primary controls script
function updateUnread(){document.getElementById('chatUnread').textContent=chatUnread?('('+chatUnread+')'):'';}
function appendChatMessage(m){
  const wrap=document.getElementById('chatMessages');
  if(wrap.querySelector('.empty'))wrap.innerHTML='';
  if(document.getElementById('chat-'+m.id))return;
  const div=document.createElement('div');div.className='chatMsg'+(m.participant_key===selfKey?' self':'');div.id='chat-'+m.id;
  const meta=document.createElement('div');meta.className='chatMeta';meta.textContent=m.display_name+' · '+String(m.created_at||'').slice(11,16);
  const text=document.createElement('div');text.className='chatText';text.textContent=m.message;
  div.append(meta,text);wrap.appendChild(div);lastChatId=Math.max(lastChatId,Number(m.id)||0);
  if(chatOpen)wrap.scrollTop=wrap.scrollHeight;else if(m.participant_key!==selfKey){chatUnread++;updateUnread();}
}
async function loadChatHistory(){
  try{const d=await jsonFetch('api/chat_history.php?token='+encodeURIComponent(TOKEN)+'&limit=100',{cache:'no-store'});selfKey=d.self;for(const m of d.messages||[])appendChatMessage(m);chatUnread=0;updateUnread();}catch(e){console.warn('chat history',e);}
}
async function pollChat(){
  if(leaving||wsReady)return;
  try{const d=await jsonFetch('api/chat_poll.php?token='+encodeURIComponent(TOKEN)+'&after='+lastChatId,{cache:'no-store'});for(const m of d.messages||[])appendChatMessage(m);}catch(e){console.warn('chat poll',e);}
  setTimeout(pollChat,1200);
}
async function sendChatMessage(text){
  const msg=text.trim();if(!msg)return;
  if(wsReady&&ws){
    ws.send(JSON.stringify({type:'chat',message:msg}));
    return;
  }
  const d=await jsonFetch('api/chat_send.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token:TOKEN,message:msg})});
  if(d.message)appendChatMessage(d.message);
}
async function heartbeat(){
  if(leaving)return;
  if(wsReady&&ws){
    try{ws.send(JSON.stringify({type:'presence',mic:micEnabled,cam:camEnabled,screen:screenSharing}));}
    catch(e){wsReady=false;}
  }
  try{
    const d=await jsonFetch('api/presence.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token:TOKEN,mic:micEnabled,cam:camEnabled,screen:screenSharing})});
    selfKey=d.self;
    if(d.room_status!=='open'){alert('A reunião foi encerrada.');await leaveRoom(false);return;}
    renderParticipants(d.participants||[]);
    renderWaitingList(d.waiting||[]);
    document.getElementById('status').textContent=wsReady?'Conectado em tempo real':'Conectado';
  }catch(e){document.getElementById('status').textContent='Reconectando…';}
  for(const [key,pc] of pcs) if(pc.connectionState==='connected') updateIceRoute(key,pc);
  setTimeout(heartbeat,4000);
}
function renderParticipants(list){
  const otherCount = list.filter(p => p.participant_key !== selfKey).length;
  const wn = document.getElementById('waitingNotice');
  if (wn) wn.style.display = (otherCount === 0) ? 'flex' : 'none';

  const wrap=document.getElementById('participants');wrap.innerHTML='';
  const pCount = list.length;
  const badgeText = document.getElementById('participantBadgeText');
  if (badgeText) badgeText.innerHTML = '<span class="participant-count-num">' + pCount + '</span><span class="online-text"> Online</span>';
  const sbCount = document.getElementById('sidebarParticipantCount');
  if (sbCount) sbCount.textContent = pCount;
  const pCountEl = document.getElementById('participantCount');
  if (pCountEl) pCountEl.textContent = pCount;
  const activeKeys=new Set();
  for(const p of list){
    activeKeys.add(p.participant_key);participantNames.set(p.participant_key,p.display_name);
    lastPresence.set(p.participant_key,Date.now());
    if(selfKey&&p.participant_key!==selfKey)peer(p.participant_key);
    const div=document.createElement('div');div.className='person';
    div.innerHTML='<div><span class="dot"></span><strong></strong></div><div class="badges"></div>';
    div.querySelector('strong').textContent=p.display_name+(p.participant_key===selfKey?' (você)':'');
    div.querySelector('.badges').textContent=(Number(p.mic_enabled)?'🎙 mic':'🔇 sem mic')+' · '+(Number(p.cam_enabled)?'📹 câmera':'🚫 sem câmera')+(Number(p.screen_sharing)?' · 🖥 tela':'');
    wrap.appendChild(div);
    const n=document.getElementById('name-'+p.participant_key);if(n)n.textContent=p.display_name;
    const s=document.getElementById('state-'+p.participant_key);if(s)s.textContent=(Number(p.mic_enabled)?'🎙':'🔇')+' '+(Number(p.cam_enabled)?'📹':'🚫')+(Number(p.screen_sharing)?' 🖥':'');
  }
  if(!list.length)wrap.innerHTML='<div class="empty">Nenhum participante online.</div>';
  for(const key of pcs.keys()) if(!activeKeys.has(key)&&Date.now()-(lastPresence.get(key)||Date.now())>25000)removePeer(key);
}
function connectWebSocket(){
  if(!WS_ENABLED||!WS_URL||leaving)return;
  try{
    ws=new WebSocket(WS_URL+(WS_URL.includes('?')?'&':'?')+'token='+encodeURIComponent(TOKEN));
    ws.onopen=()=>{
      wsReady=true;
      document.getElementById('status').textContent='Conectado em tempo real';
      if(wsReconnectTimer){clearTimeout(wsReconnectTimer);wsReconnectTimer=null;}
      ws.send(JSON.stringify({type:'presence',mic:micEnabled,cam:camEnabled,screen:screenSharing}));
      ws.send(JSON.stringify({type:'signal',signalType:'peer-ready',payload:{},recipient:null}));
    };
    ws.onmessage=async ev=>{
      try{
        const d=JSON.parse(ev.data);
        if(d.type==='hello'){selfKey=d.self||selfKey;return;}
        if(d.type==='signal'&&d.message){
          // HTTP cursor is advanced only by polling, never by out-of-order WS delivery.
          await handleSignal(d.message);
          return;
        }
        if(d.type==='chat'&&d.message){appendChatMessage(d.message);return;}
        if(d.type==='presence'){renderParticipants(d.participants||[]);
      if(d.waiting) renderWaitingList(d.waiting);return;}
        if(d.type==='session-ended'){
          alert('Sua participação foi encerrada ou a reunião foi fechada.');
          await leaveRoom(false);
          return;
        }
        if(d.type==='error'){console.warn('WebSocket',d.error);}
      }catch(e){console.warn('WS message',e);}
    };
    ws.onclose=()=>{
      wsReady=false;
      document.getElementById('status').textContent='WebSocket desconectado; usando fallback';
      pollSignals();pollChat();
      if(!leaving)wsReconnectTimer=setTimeout(connectWebSocket,WS_RECONNECT_MS);
    };
    ws.onerror=()=>{wsReady=false;};
  }catch(e){
    wsReady=false;
    if(!leaving)wsReconnectTimer=setTimeout(connectWebSocket,WS_RECONNECT_MS);
  }
}

async function toggleScreen(){
  if(screenSharing){await stopScreen();return;}
  try{
    const display=await navigator.mediaDevices.getDisplayMedia({video:true,audio:false});
    screenTrack=display.getVideoTracks()[0];screenSharing=true;
    screenTrack.onended=()=>stopScreen();
    await replaceOutgoingTrack('video',screenTrack);
    document.getElementById('local').srcObject=new MediaStream([screenTrack,...localStream.getAudioTracks()]);
    updateButtons();
  }catch(e){if(e.name!=='NotAllowedError')alert('Não foi possível compartilhar a tela: '+e.message);}
}
async function stopScreen(){
  if(!screenSharing)return;
  screenSharing=false;
  if(screenTrack){screenTrack.onended=null;screenTrack.stop();screenTrack=null;}
  await replaceOutgoingTrack('video',cameraTrack);
  document.getElementById('local').srcObject=localStream;updateButtons();
}
async function leaveRoom(navigate=true){
  if(leaving) return;
  leaving = true;

  // 1. Libera imediatamente todo o hardware de câmera e microfone
  try {
    if (localStream) {
      localStream.getTracks().forEach(t => {
        try { t.stop(); } catch(e){}
      });
      localStream = null;
    }
  } catch(e){}

  try {
    if (screenTrack) {
      screenTrack.stop();
      screenTrack = null;
    }
  } catch(e){}

  // 2. Notifica saída via sendBeacon (imediato e garantido)
  try {
    const blob = new Blob([JSON.stringify({token: TOKEN})], {type: 'application/json'});
    navigator.sendBeacon('api/leave.php', blob);
  } catch(e){}

  // 3. Notifica via WebSocket se conectado
  try {
    if (wsReady && ws) {
      ws.send(JSON.stringify({type:'signal', signalType:'leave', payload:{}, recipient:null}));
      ws.close();
    }
  } catch(e){}

  // 4. Fecha conexões WebRTC
  try {
    for (const pc of pcs.values()) {
      try { pc.close(); } catch(e){}
    }
    pcs.clear();
  } catch(e){}

  // 5. Redireciona diretamente para o início
  if (navigate) {
    window.location.href = 'index.php?left=1';
  }
}
document.getElementById('screen').onclick=toggleScreen;
document.getElementById('leave').onclick=()=>leaveRoom(true);
document.getElementById('tabParticipants').onclick=()=>setSideTab('participants');
document.getElementById('tabChat').onclick=()=>setSideTab('chat');
document.getElementById('chatForm').addEventListener('submit',async e=>{
  e.preventDefault();const input=document.getElementById('chatInput');const value=input.value;if(!value.trim())return;
  input.disabled=true;
  try{await sendChatMessage(value);input.value='';}catch(err){alert('Não foi possível enviar a mensagem.');}
  finally{input.disabled=false;input.focus();}
});
document.getElementById('chatInput').addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();document.getElementById('chatForm').requestSubmit();}});

// ==============================================================================
// GESTÃO RESILIENTE DE DISPOSITIVOS DE MÍDIA (CÂMERA E MICROFONE)
// ==============================================================================
async function initLocalMedia() {
  let hasVideo = false;
  let hasAudio = false;

  // Tier 1: Tenta Câmera + Microfone
  try {
    localStream = await navigator.mediaDevices.getUserMedia({
      video: { width: { ideal: 1280 }, height: { ideal: 720 } },
      audio: true
    });
    hasVideo = true;
    hasAudio = true;
  } catch (err1) {
    console.warn('Tier 1 (Video+Audio) falhou:', err1.name, err1.message);

    // Tier 2: Tenta apenas Áudio (câmera ocupada ou negada)
    try {
      localStream = await navigator.mediaDevices.getUserMedia({ audio: true });
      hasAudio = true;
      showToast('Câmera ocupada ou indisponível. Entrando em modo áudio.');
    } catch (err2) {
      console.warn('Tier 2 (Audio) falhou:', err2.name, err2.message);
      // Tier 3: Modo ouvinte (stream local vazio)
      localStream = new MediaStream();
      showToast('Mídia bloqueada. Você entrou na sala como ouvinte.');
    }
  }

  cameraTrack = (localStream && localStream.getVideoTracks().length > 0) ? localStream.getVideoTracks()[0] : null;
  const audioTracks = localStream ? localStream.getAudioTracks() : [];
  
  camEnabled = hasVideo && (cameraTrack !== null);
  micEnabled = hasAudio && (audioTracks.length > 0);

  const localVideo = document.getElementById('local');
  const localAvatar = document.getElementById('localAvatar');
  if (localVideo && localStream) {
    localVideo.srcObject = localStream;
    localVideo.style.display = camEnabled ? 'block' : 'none';
  }
  if (localAvatar) {
    localAvatar.style.display = camEnabled ? 'none' : 'flex';
  }
  updateButtons();
}

// Liberação completa de hardware ao sair ou recarregar a página
function releaseAllMedia() {
  if (localStream) {
    localStream.getTracks().forEach(t => {
      try { t.stop(); } catch(e){}
    });
  }
  if (screenTrack) {
    try { screenTrack.stop(); } catch(e){}
  }
}
window.addEventListener('beforeunload', releaseAllMedia);
window.addEventListener('pagehide', releaseAllMedia);

window.addEventListener('beforeunload',()=>{
  if(leaving) return;
  const blob=new Blob([JSON.stringify({token:TOKEN})],{type:'application/json'});
  navigator.sendBeacon('api/leave.php',blob);
});

// Clique dinâmico no botão da CÂMERA (ativa/desativa ou solicita do zero)
const camBtn = document.getElementById('cam');
if (camBtn) {
  camBtn.onclick = async () => {
    if (cameraTrack && cameraTrack.readyState === 'live') {
      camEnabled = !camEnabled;
      cameraTrack.enabled = camEnabled;
      updateButtons();
      const localVideo = document.getElementById('local');
      if (localVideo) localVideo.style.opacity = camEnabled ? '1' : '0.2';
    } else {
      try {
        showToast('Solicitando acesso à câmera...');
        const stream = await navigator.mediaDevices.getUserMedia({
          video: { width: { ideal: 1280 }, height: { ideal: 720 } }
        });
        const newTrack = stream.getVideoTracks()[0];
        if (newTrack) {
          cameraTrack = newTrack;
          if (!localStream) localStream = new MediaStream();
          localStream.addTrack(cameraTrack);
          camEnabled = true;

          const localVideo = document.getElementById('local');
          if (localVideo) {
            localVideo.srcObject = localStream;
            localVideo.style.opacity = '1';
          }

          if(!screenSharing)await replaceOutgoingTrack('video',cameraTrack);
          if(localVideo)localVideo.style.display='block';
          const avatar=document.getElementById('localAvatar');
          if(avatar)avatar.style.display='none';
          updateButtons();
          showToast('Câmera ativada com sucesso!');
        }
      } catch (err) {
        console.warn('Falha ao reativar câmera:', err);
        showToast('Câmera ocupada ou permissão negada: ' + (err.message || err.name));
      }
    }
  };
}

// Clique dinâmico no botão do MICROFONE
const micBtn = document.getElementById('mic');
if (micBtn) {
  micBtn.onclick = async () => {
    const audioTrack = (localStream && localStream.getAudioTracks().length > 0) ? localStream.getAudioTracks()[0] : null;
    if (audioTrack && audioTrack.readyState === 'live') {
      micEnabled = !micEnabled;
      audioTrack.enabled = micEnabled;
      updateButtons();
    } else {
      try {
        showToast('Solicitando acesso ao microfone...');
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        const newTrack = stream.getAudioTracks()[0];
        if (newTrack) {
          if (!localStream) localStream = new MediaStream();
          localStream.addTrack(newTrack);
          micEnabled = true;

          await replaceOutgoingTrack('audio',newTrack);
          updateButtons();
          showToast('Microfone ativado com sucesso!');
        }
      } catch (err) {
        showToast('Microfone bloqueado: ' + (err.message || err.name));
      }
    }
  };
}

// INICIALIZAÇÃO RESILIENTE DA SALA
(async () => {
  try {
    await initLocalMedia();

    const first = await jsonFetch('api/presence.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: TOKEN, mic: micEnabled, cam: camEnabled, screen: false })
    });
    selfKey = first.self;
    renderParticipants(first.participants || []);
    if(first.waiting) renderWaitingList(first.waiting);
    await loadChatHistory();
    connectWebSocket();
    pollSignals();
    pollChat();
    await send('peer-ready', {});
    document.getElementById('status').textContent = WS_ENABLED ? 'Conectando WebSocket...' : 'Conectado';
    setTimeout(heartbeat, 1200);
  } catch (e) {
    console.error('Erro na entrada da sala:', e);
    document.getElementById('status').textContent = 'Conectado (fallback)';
    try {
      await send('peer-ready', {});
      pollSignals();
      pollChat();
      setTimeout(heartbeat, 1200);
    } catch(errPoll) {}
  }
})();
  </script>
</body>
</html>
