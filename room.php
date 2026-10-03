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

// 1. Busca os dados do convite informado na URL e da sala
$st = $pdo->prepare("SELECT i.*, r.id as room_id, r.name as room_name, r.status as room_status, r.owner_user_id, r.join_token 
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

// 2. Se o token ainda não estiver aprovado, redireciona para o fluxo de entrada / sala de espera em join.php
if ($tokenInvite['status'] === 'waiting') {
    header('Location: join.php?token=' . urlencode($token) . '&waiting=1');
    exit;
}
if ($tokenInvite['status'] === 'invited' || $tokenInvite['email'] === 'invite@sala.local') {
    $rTok = !empty($tokenInvite['join_token']) ? $tokenInvite['join_token'] : '';
    if ($rTok !== '') {
        header('Location: join.php?room_token=' . urlencode($rTok));
    } else {
        header('Location: join.php?token=' . urlencode($token));
    }
    exit;
}
if ($tokenInvite['status'] === 'rejected') {
    header('Location: kicked.php?room_name=' . urlencode($tokenInvite['room_name']));
    exit;
}

// 3. O token está aprovado (status === 'approved')
$me = $tokenInvite;
$signedUser = current_user();

// O participante possui convite aprovado (autorizado por um administrador na sala de espera ou convite pré-aprovado).
// Não exige cadastro de usuário nem permissão administrativa para ingressar na reunião.
// Apenas administradores (proprietário, admin da sala ou admin geral) recebem permissão para autorizar/admitir novos participantes.
$isRoomAdmin = is_token_room_admin($pdo, $token);
$canAdmit = $isRoomAdmin;

// Garante subpasta storage/<room_id> dinâmica criada
get_room_storage_dir((int)$me['room_id']);

// Pré-aquece o cache de autenticação do Relay HTTP em disco e em /tmp (12 horas)
$tokenCacheData = json_encode([
    'exp' => time() + 43200,
    'data' => [
        'room_id' => (int)$me['room_id'],
        'participant_key' => preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$me['participant_key'])
    ]
], JSON_UNESCAPED_SLASHES);

$tokenCacheDir = __DIR__ . '/storage/tokens';
if (!is_dir($tokenCacheDir)) {
    @mkdir($tokenCacheDir, 0775, true);
}
@file_put_contents($tokenCacheDir . '/' . md5($token) . '.json', $tokenCacheData, LOCK_EX);
@file_put_contents(sys_get_temp_dir() . '/tok_' . md5($token) . '.json', $tokenCacheData, LOCK_EX);

$cursorQuery = $pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM signaling_messages WHERE room_id=?');
$cursorQuery->execute([$me['room_id']]);
$signalCursor = (int)$cursorQuery->fetchColumn();

// Gera o link de convite público da sala (leva para join.php para digitar o nome e entrar na sala de espera)
$baseUrl = (string)($config['app']['base_url'] ?? '/salareuniao');
if (str_starts_with($baseUrl, 'http://') || str_starts_with($baseUrl, 'https://')) {
    $inviteBase = rtrim($baseUrl, '/');
} else {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'https';
    $host = !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'maurinsoft.com.br';
    $inviteBase = $scheme . '://' . $host . '/' . ltrim($baseUrl, '/');
}
$roomJoinToken = !empty($me['join_token']) ? $me['join_token'] : '';
if ($roomJoinToken === '') {
    $roomJoinToken = bin2hex(random_bytes(32));
    $pdo->prepare("UPDATE rooms SET join_token = ? WHERE id = ?")->execute([$roomJoinToken, $me['room_id']]);
}
$inviteUrl = rtrim($inviteBase, '/') . '/join.php?room_token=' . urlencode($roomJoinToken);

$iceServers = $config['webrtc']['ice_servers'] ?? [];
$turn = $config['webrtc']['turn'] ?? [];
if (!empty($turn['enabled']) && !empty($turn['urls'])) {
    if (!empty($turn['secret']) && empty($turn['username'])) {
        // Coturn ephemeral HMAC token
        $ttl = max(300, (int)($turn['ttl'] ?? 3600));
        $turnUsername = (string)(time() + $ttl) . ':' . $me['participant_key'];
        $turnCredential = base64_encode(hash_hmac('sha1', $turnUsername, (string)$turn['secret'], true));
    } else {
        // Credenciais estáticas de provedor TURN gerenciado (Metered, Xirsys, OpenRelay, etc.)
        $turnUsername = (string)($turn['username'] ?? '');
        $turnCredential = (string)($turn['credential'] ?? $turn['password'] ?? $turn['secret'] ?? '');
    }
    if ($turnUsername !== '' && $turnCredential !== '') {
        $iceServers[] = [
            'urls' => $turn['urls'],
            'username' => $turnUsername,
            'credential' => $turnCredential,
        ];
    }
}
$ice = json_encode($iceServers, JSON_UNESCAPED_SLASHES);
$wsEnabled = !empty($config['websocket']['enabled']) && !empty($config['websocket']['public_url']);
$wsUrl = $wsEnabled ? (string)$config['websocket']['public_url'] : '';
$wsReconnect = max(500, (int)($config['websocket']['reconnect_ms'] ?? 2000));
$turnEnabled = !empty($turn['enabled']) && !empty($turn['urls']);
// Se não houver WebSocket nem TURN configurados, opera no modo HTTP Relay direto (ideal para Hospedagem Compartilhada)
$relayMode = !$wsEnabled && !$turnEnabled;
// Na Hospedagem Compartilhada (sem daemons), o Bridge opera via Webservice HTTP Relay sem abrir WebSockets
$bridgeEnabled = $wsEnabled && !empty($config['bridge']['enabled']) && !empty($config['bridge']['public_url']);
$bridgeUrl = $bridgeEnabled ? (string)$config['bridge']['public_url'] : '';
$bridgeChunkMs = max(100, (int)($config['bridge']['chunk_ms'] ?? 250));
$bridgeFallbackTimeoutMs = max(2000, (int)($config['bridge']['fallback_timeout_ms'] ?? 8000));
$maxMeshParticipants = (int)get_system_parameter('webrtc.max_mesh_participants', $config['webrtc']['max_mesh_participants'] ?? 4);
$runtimeState = get_room_runtime_state((int)$me['room_id']);
if ($canAdmit && empty($runtimeState['active_presenter_key'])) {
    $runtimeState = set_room_presentation((int)$me['room_id'], $me['participant_key'], 'camera');
}
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
    .grid {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      width: 100%;
      height: 100%;
      align-items: center;
      justify-content: center;
      padding: 16px;
      padding-bottom: 84px;
      box-sizing: border-box;
      overflow-y: auto;
      -webkit-overflow-scrolling: touch;
    }

    /* Grid de Vídeo Padronizado: Exatamente 5 participantes por linha */
    .tile {
      position: relative;
      background: #0b1120;
      border: 1px solid rgba(255, 255, 255, 0.12);
      border-radius: 14px;
      overflow: hidden;
      aspect-ratio: 16/9;
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.6);
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
      flex: 0 0 calc(20% - 10px);
      width: calc(20% - 10px);
      max-width: calc(20% - 10px);
      min-width: 180px;
    }

    @media (max-width: 1180px) {
      .tile {
        flex: 0 0 calc(33.333% - 10px);
        max-width: calc(33.333% - 10px);
      }
    }

    @media (max-width: 768px) {
      .tile {
        flex: 0 0 calc(50% - 8px);
        max-width: calc(50% - 8px);
        min-width: 140px;
      }
    }

    @keyframes pulse-glow-border {
      0% {
        border-color: rgba(234, 179, 8, 0.45);
        box-shadow: 0 0 10px rgba(234, 179, 8, 0.35), inset 0 0 8px rgba(234, 179, 8, 0.15);
        opacity: 0.92;
      }
      50% {
        border-color: #facc15;
        box-shadow: 0 0 26px rgba(250, 204, 21, 0.8), inset 0 0 16px rgba(250, 204, 21, 0.3);
        opacity: 1;
      }
      100% {
        border-color: rgba(234, 179, 8, 0.45);
        box-shadow: 0 0 10px rgba(234, 179, 8, 0.35), inset 0 0 8px rgba(234, 179, 8, 0.15);
        opacity: 0.92;
      }
    }

    @keyframes pulse-avatar-glow {
      0% {
        transform: scale(1);
        filter: drop-shadow(0 0 4px rgba(234, 179, 8, 0.4));
      }
      50% {
        transform: scale(1.06);
        filter: drop-shadow(0 0 14px rgba(250, 204, 21, 0.85));
      }
      100% {
        transform: scale(1);
        filter: drop-shadow(0 0 4px rgba(234, 179, 8, 0.4));
      }
    }

    /* O usuário que solicitou falar fica piscando suavemente */
    .hand-raised-glow,
    .tile.hand-raised-glow,
    .person.hand-raised-glow {
      border: 2px solid #eab308 !important;
      animation: pulse-glow-border 1.5s ease-in-out infinite !important;
    }

    .audience-strip .tile.hand-raised-glow {
      border: 2px solid #eab308 !important;
      animation: pulse-glow-border 1.5s ease-in-out infinite !important;
    }

    .hand-raised-glow .peer-avatar,
    .hand-raised-glow #localAvatar {
      animation: pulse-avatar-glow 1.5s ease-in-out infinite !important;
    }

        /* Ícone / Imagem de Mãozinha na Janela do Participante */
    .tile-hand-action {
      position: absolute;
      top: 10px;
      left: 10px;
      background: linear-gradient(135deg, #f59e0b, #eab308);
      color: #0f172a;
      font-size: 1.35rem;
      width: 40px;
      height: 40px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 0 20px rgba(245, 158, 11, 0.65), 0 4px 10px rgba(0,0,0,0.5);
      border: 2px solid #fef08a;
      z-index: 12;
      transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
      animation: pulseHandTile 1.3s infinite alternate ease-in-out;
      user-select: none;
    }

    @keyframes pulseHandTile {
      0% { transform: scale(1); box-shadow: 0 0 12px rgba(245, 158, 11, 0.5); }
      100% { transform: scale(1.15); box-shadow: 0 0 28px rgba(245, 158, 11, 0.95); }
    }

    .tile-hand-action.admin-clickable {
      cursor: pointer;
    }

    .tile-hand-action.admin-clickable:hover {
      transform: scale(1.25) !important;
      background: linear-gradient(135deg, #eab308, #facc15) !important;
      box-shadow: 0 0 35px rgba(250, 204, 21, 1), 0 0 15px #fff !important;
    }

    .tile-hand-action.admin-clickable::after {
      content: "Aprovar Full";
      position: absolute;
      left: 48px;
      white-space: nowrap;
      background: rgba(15, 23, 42, 0.96);
      border: 1px solid #facc15;
      color: #fef08a;
      font-size: 0.74rem;
      font-weight: 700;
      padding: 3px 10px;
      border-radius: 12px;
      box-shadow: 0 4px 16px rgba(0,0,0,0.7);
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.2s, transform 0.2s;
      transform: translateX(-4px);
    }

    .tile-hand-action.admin-clickable:hover::after {
      opacity: 1;
      transform: translateX(0);
    }

    .tile-hand-badge {
      position: absolute;
      top: 10px;
      left: 10px;
      background: rgba(234, 179, 8, 0.95);
      color: #0f172a;
      font-weight: 700;
      font-size: 0.74rem;
      padding: 3px 10px;
      border-radius: 20px;
      z-index: 6;
      display: flex;
      align-items: center;
      gap: 4px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.5);
      animation: pulseHand 1.6s infinite ease-in-out;
    }

    @keyframes pulseHand {
      0% { transform: scale(1); }
      50% { transform: scale(1.06); }
      100% { transform: scale(1); }
    }

    
    /* ========================================================
       MODO DE EXIBIÇÃO FULL (SPOTLIGHT / COMPARTILHAMENTO)
       As demais exibições são pausadas e o destaque ganha resolução máxima
       ======================================================== */
    .grid.full-mode {
      display: flex !important;
      flex-direction: column !important;
      align-items: center !important;
      justify-content: center !important;
      padding: 8px !important;
      padding-bottom: 84px !important;
      gap: 0 !important;
      overflow: hidden !important;
    }

    .grid.full-mode .tile {
      display: none !important;
    }

    .grid.full-mode .tile.full-spotlight {
      display: flex !important;
      width: 100% !important;
      height: 100% !important;
      max-width: 100% !important;
      max-height: calc(100vh - 110px) !important;
      flex: 1 1 100% !important;
      border-radius: 14px !important;
      border: 2px solid var(--primary, #00d2ff) !important;
      box-shadow: 0 0 45px rgba(0, 210, 255, 0.35) !important;
      background: #000 !important;
    }

    .grid.full-mode .tile.full-spotlight video {
      object-fit: contain !important; /* Preserva proporção exata e nitidez máxima */
      width: 100% !important;
      height: 100% !important;
    }

    .tile-full-btn {
      position: absolute;
      top: 10px;
      right: 10px;
      background: rgba(15, 23, 42, 0.85);
      backdrop-filter: blur(8px);
      border: 1px solid rgba(255, 255, 255, 0.2);
      color: #fff;
      width: 28px;
      height: 28px;
      border-radius: 6px;
      font-size: 0.85rem;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      z-index: 8;
      transition: all 0.2s;
    }

    .tile-full-btn:hover {
      background: rgba(0, 210, 255, 0.3);
      border-color: #00d2ff;
      transform: scale(1.1);
    }

    .full-mode-banner {
      position: absolute;
      top: 18px;
      left: 50%;
      transform: translateX(-50%);
      background: rgba(15, 23, 42, 0.95);
      backdrop-filter: blur(20px);
      border: 1px solid rgba(0, 210, 255, 0.5);
      border-radius: 30px;
      padding: 8px 20px;
      z-index: 90;
      display: none;
      align-items: center;
      gap: 16px;
      box-shadow: 0 10px 35px rgba(0, 0, 0, 0.7);
      animation: fadeInDown 0.25s ease-out;
    }

    @keyframes fadeInDown {
      from { opacity: 0; transform: translate(-50%, -10px); }
      to { opacity: 1; transform: translate(-50%, 0); }
    }

    .controls button.hand-active {
      background: rgba(234, 179, 8, 0.25) !important;
      border-color: #eab308 !important;
      color: #fef08a !important;
      box-shadow: 0 0 15px rgba(234, 179, 8, 0.35);
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
      padding: 7px 12px;
      background: none;
      border: none;
      color: var(--text-muted, #94a3b8);
      font-size: 0.84rem;
      font-weight: 600;
      border-radius: 6px;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 6px;
      transition: all 0.2s;
      white-space: nowrap;
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

    /* Estilos do Espaço de Transferência de Arquivos */
    .chat-file-card {
      background: rgba(15, 23, 42, 0.7);
      border: 1px solid var(--border-accent, rgba(0, 210, 255, 0.3));
      border-radius: 8px;
      padding: 10px 12px;
      margin-top: 4px;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .chat-file-icon {
      font-size: 1.5rem;
      flex-shrink: 0;
    }
    .chat-file-info {
      flex: 1;
      min-width: 0;
    }
    .chat-file-name {
      font-weight: 600;
      font-size: 0.86rem;
      color: #fff;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .chat-file-size {
      font-size: 0.74rem;
      color: var(--text-muted);
      margin-top: 2px;
    }
    .chat-file-btn {
      background: var(--primary, #00d2ff);
      color: #0b1120 !important;
      font-weight: 600;
      font-size: 0.78rem;
      padding: 6px 12px;
      border-radius: 6px;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      transition: all 0.2s;
      flex-shrink: 0;
    }
    .chat-file-btn:hover {
      background: var(--primary-hover, #38bdf8);
      transform: translateY(-1px);
    }
    .file-upload-btn {
      background: transparent;
      border: 1px solid var(--border-glass);
      color: var(--text-muted);
      border-radius: 8px;
      width: 44px;
      height: 48px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 1.25rem;
      cursor: pointer;
      transition: all 0.2s;
      flex-shrink: 0;
    }
    .file-upload-btn:hover {
      border-color: var(--primary, #00d2ff);
      color: var(--primary, #00d2ff);
      background: rgba(0, 210, 255, 0.08);
    }
    .room-file-item {
      background: rgba(255, 255, 255, 0.03);
      border: 1px solid var(--border-glass);
      border-radius: 8px;
      padding: 10px 12px;
      margin-bottom: 8px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
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
      /* Header Compacto e Sem Sobreposições */
      .room-header {
        height: 50px;
        padding: max(6px, env(safe-area-inset-top, 6px)) 12px 6px 12px;
        gap: 8px;
        width: 100vw;
        max-width: 100vw;
        box-sizing: border-box;
        overflow: hidden;
      }

      .room-info {
        gap: 8px;
        min-width: 0;
        flex: 1 1 auto;
        overflow: hidden;
      }

      .sr-brand-logo {
        width: 28px !important;
        height: 28px !important;
        min-width: 28px !important;
        font-size: 13px !important;
        border-radius: 6px !important;
        flex-shrink: 0;
      }

      .room-title {
        font-size: 0.88rem;
        font-weight: 700;
        max-width: 140px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }

      /* Oculta métricas de desktop no mobile para evitar colisão */
      #peerConnectionCounter,
      #status,
      #iceRoute {
        display: none !important;
      }

      .room-timer {
        font-size: 0.72rem;
        padding: 2px 7px;
        border-radius: 12px;
        flex-shrink: 0;
      }

      .header-actions {
        gap: 6px;
        flex-shrink: 0;
      }

      /* No mobile, Chat e Participantes ficam na barra inferior de controles */
      #topBtnParticipants,
      #topBtnChat {
        display: none !important;
      }

      .header-btn {
        padding: 0;
        width: 36px;
        height: 36px;
        min-width: 36px;
        border-radius: 8px;
        font-size: 0.95rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
      }

      .header-btn .header-btn-text,
      .header-btn .online-text {
        display: none !important;
      }

      /* Layout Principal Mobile */
      .room-main-layout {
        height: calc(100% - 50px);
        height: calc(100dvh - 50px);
        width: 100vw;
        max-width: 100vw;
        overflow: hidden;
      }

      .room-stage {
        padding: 6px 6px 82px 6px;
        justify-content: center;
        width: 100%;
        box-sizing: border-box;
      }

      .grid {
        gap: 8px;
        padding-bottom: 0;
        align-content: center;
        justify-content: center;
        width: 100%;
        height: 100%;
      }

      /* 1 Participante no Celular (Em pé / Retrato) */
      @media (orientation: portrait) {
        .grid[data-count="1"] .tile,
        .grid:not([data-count]) > .tile:only-child,
        .grid > .tile:only-child {
          width: 100% !important;
          max-width: 100% !important;
          height: 100% !important;
          max-height: calc(100% - 4px) !important;
          aspect-ratio: 9/16;
          max-width: 440px !important;
          margin: auto;
          border-radius: 16px;
          min-width: 0;
        }

        /* 2 Participantes no Celular (Em pé / Retrato - Divisão Vertical 50/50) */
        .grid[data-count="2"],
        .grid:has(> .tile:nth-child(2):last-child) {
          flex-direction: column !important;
          flex-wrap: nowrap !important;
        }

        .grid[data-count="2"] .tile,
        .grid:has(> .tile:nth-child(2):last-child) .tile {
          width: 100% !important;
          max-width: 100% !important;
          flex: 1 1 0 !important;
          min-height: 0 !important;
          max-height: calc(50% - 4px) !important;
          aspect-ratio: auto !important;
          border-radius: 12px;
          min-width: 0;
        }
      }

      /* 1 Participante no Celular (Deitado / Paisagem) */
      @media (orientation: landscape) {
        .grid[data-count="1"] .tile,
        .grid:not([data-count]) > .tile:only-child,
        .grid > .tile:only-child {
          width: auto !important;
          max-width: 100% !important;
          height: 100% !important;
          aspect-ratio: 16/9;
          max-height: calc(100% - 4px) !important;
          margin: auto;
          border-radius: 14px;
          min-width: 0;
        }

        /* 2 Participantes no Celular (Deitado / Paisagem - Lado a Lado 50/50) */
        .grid[data-count="2"],
        .grid:has(> .tile:nth-child(2):last-child) {
          flex-direction: row !important;
          flex-wrap: nowrap !important;
        }

        .grid[data-count="2"] .tile,
        .grid:has(> .tile:nth-child(2):last-child) .tile {
          width: calc(50% - 4px) !important;
          max-width: calc(50% - 4px) !important;
          flex: 1 1 0 !important;
          height: 100% !important;
          max-height: 100% !important;
          aspect-ratio: auto !important;
          border-radius: 12px;
          min-width: 0;
        }
      }

      /* 3 ou 4 Participantes no Celular (Grid 2x2) */
      .grid[data-count="3"] .tile,
      .grid[data-count="4"] .tile,
      .grid:has(> .tile:nth-child(3)) .tile {
        flex: 1 1 calc(50% - 4px) !important;
        max-width: calc(50% - 4px) !important;
        min-width: 0 !important;
        max-height: calc(50% - 4px) !important;
        aspect-ratio: 4/3;
        border-radius: 10px;
      }

      /* 5+ Participantes */
      .grid[data-count="5"] .tile,
      .grid:has(> .tile:nth-child(5)) .tile {
        flex: 1 1 calc(50% - 4px) !important;
        max-width: calc(50% - 4px) !important;
        min-width: 0 !important;
        aspect-ratio: 4/3;
        border-radius: 10px;
      }

      .tile .name {
        font-size: 0.72rem;
        padding: 3px 8px;
        bottom: 8px;
        left: 8px;
        max-width: 72%;
        border-radius: 5px;
      }

      .tile .state {
        font-size: 0.64rem;
        padding: 2px 6px;
        top: 8px;
        right: 8px;
        border-radius: 5px;
      }

      .tile-mute-btn {
        right: 8px !important;
        bottom: 8px !important;
        padding: 3px 6px !important;
        font-size: 0.72rem !important;
      }

      #localAvatar {
        width: 64px !important;
        height: 64px !important;
        font-size: 1.6rem !important;
      }

      /* Dock Flutuante de Controles no Celular (Estilo Teams) */
      .controls {
        bottom: max(14px, env(safe-area-inset-bottom, 14px));
        padding: 6px 12px;
        gap: 8px;
        border-radius: 9999px;
        width: auto;
        max-width: calc(100vw - 16px);
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
        display: inline-flex;
        align-items: center;
        justify-content: center;
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
        font-size: 0.88rem;
        padding: 6px 12px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 8px;
        color: #fff;
        display: flex;
        align-items: center;
        gap: 4px;
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
        top: max(12px, env(safe-area-inset-top, 12px)) !important;
        width: calc(100% - 24px) !important;
        max-width: 360px !important;
        flex-direction: column !important;
        padding: 10px 14px !important;
        gap: 8px !important;
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
        top: max(12px, env(safe-area-inset-top, 12px)) !important;
        width: calc(100% - 24px) !important;
        max-width: 360px !important;
        flex-direction: column !important;
        padding: 8px 14px !important;
        gap: 6px !important;
        border-radius: 16px !important;
        text-align: center !important;
        font-size: 0.78rem !important;
      }

      #waitingNotice button {
        width: 100% !important;
        min-height: 34px !important;
        font-size: 0.75rem !important;
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
        max-width: 105px;
      }
      .room-timer {
        font-size: 0.68rem;
        padding: 2px 5px;
      }
      .controls {
        gap: 6px;
        padding: 5px 8px;
      }
      .controls button {
        width: 38px;
        height: 38px;
        min-width: 38px;
        font-size: 1.05rem;
      }
    }
  
    /* ========================================================
       CONCEITO 1 TRANSMITE (EM CIMA), VÁRIOS OUVEM (EM BAIXO)
       ======================================================== */
    .stage-container {
      display: flex !important;
      flex-direction: column !important;
      width: 100% !important;
      height: 100% !important;
      max-height: calc(100vh - 72px) !important;
      padding: 10px 14px 84px 14px !important;
      gap: 12px !important;
      box-sizing: border-box !important;
      position: relative !important;
      overflow: hidden !important;
    }

    /* ÁREA SUPERIOR (TRANSMISSOR / PALCO PRINCIPAL) */
    .stage-area {
      flex: 1 1 0 !important;
      width: 100% !important;
      min-height: 220px !important;
      display: flex !important;
      align-items: center !important;
      justify-content: center !important;
      position: relative !important;
      overflow: hidden !important;
      border-radius: 16px !important;
      background: radial-gradient(circle at center, #0f172a 0%, #020617 100%) !important;
      border: 2px solid rgba(0, 210, 255, 0.4) !important;
      box-shadow: 0 8px 35px rgba(0, 0, 0, 0.7) !important;
    }

    .stage-area .tile,
    .stage-speaker {
      flex: 1 1 100% !important;
      width: 100% !important;
      height: 100% !important;
      max-width: 100% !important;
      max-height: 100% !important;
      min-width: 0 !important;
      min-height: 0 !important;
      aspect-ratio: unset !important;
      display: flex !important;
      align-items: center !important;
      justify-content: center !important;
      position: relative !important;
      background: #000 !important;
      border-radius: 14px !important;
      border: none !important;
      box-shadow: none !important;
      overflow: hidden !important;
    }

    .stage-area .tile video,
    .stage-speaker video {
      width: 100% !important;
      height: 100% !important;
      max-width: 100% !important;
      max-height: 100% !important;
      object-fit: cover !important; /* Ocupa a tela toda como uma apresentação mesmo */
      display: block !important;
    }

    .stage-area.fit-contain .tile video,
    .stage-area .tile.is-screen-share video {
      object-fit: contain !important;
    }

    .stage-area .tile .state {
      position: absolute !important;
      top: 14px !important;
      right: 16px !important;
      background: rgba(16, 185, 129, 0.95) !important;
      color: #fff !important;
      font-weight: 700 !important;
      padding: 5px 14px !important;
      border-radius: 20px !important;
      font-size: 0.8rem !important;
      box-shadow: 0 0 15px rgba(16, 185, 129, 0.5) !important;
      letter-spacing: 0.5px !important;
      z-index: 5 !important;
    }

    .stage-area .tile .name {
      position: absolute !important;
      bottom: 14px !important;
      left: 16px !important;
      background: rgba(15, 23, 42, 0.85) !important;
      backdrop-filter: blur(8px) !important;
      padding: 6px 16px !important;
      border-radius: 8px !important;
      font-size: 0.92rem !important;
      font-weight: 600 !important;
      color: #fff !important;
      border: 1px solid rgba(255, 255, 255, 0.15) !important;
      z-index: 5 !important;
    }

    /* ÁREA INFERIOR (OUVINTES / PARTICIPANTES EM BAIXO - TODA A LINHA) */
    .audience-strip {
      flex: 1 1 100% !important;
      width: 100% !important;
      min-width: 0 !important;
      height: 120px !important;
      max-height: 120px !important;
      min-height: 120px !important;
      display: flex !important;
      flex-direction: row !important;
      align-items: center !important;
      justify-content: flex-start !important;
      gap: 12px !important;
      overflow-x: auto !important;
      overflow-y: hidden !important;
      padding: 4px 8px !important;
      box-sizing: border-box !important;
      scroll-behavior: smooth !important;
      -webkit-overflow-scrolling: touch !important;
      scrollbar-width: none !important; /* Sem barra feia quebra-layout */
    }

    .audience-strip::-webkit-scrollbar {
      display: none !important;
    }

    /* Cards de participantes ouvintes em baixo */
    .audience-strip .tile {
      flex: 0 0 136px !important;
      width: 136px !important;
      height: 106px !important;
      min-width: 136px !important;
      max-width: 136px !important;
      border-radius: 12px !important;
      background: rgba(15, 23, 42, 0.9) !important;
      backdrop-filter: blur(10px) !important;
      border: 1px solid rgba(255, 255, 255, 0.12) !important;
      display: flex !important;
      flex-direction: column !important;
      align-items: center !important;
      justify-content: center !important;
      position: relative !important;
      overflow: hidden !important;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4) !important;
      transition: all 0.2s ease !important;
      cursor: pointer !important;
    }

    .audience-strip .tile:hover {
      border-color: rgba(0, 210, 255, 0.6) !important;
      transform: translateY(-2px) !important;
      box-shadow: 0 6px 20px rgba(0, 210, 255, 0.25) !important;
    }

    .audience-strip .tile.hand-raised-glow {
      border: 2px solid #eab308 !important;
      box-shadow: 0 0 20px rgba(234, 179, 8, 0.7) !important;
      animation: pulse-glow-border 1.5s infinite alternate !important;
    }

    .audience-strip .tile video {
      display: none !important;
    }

    .audience-strip .tile .peer-avatar,
    .audience-strip .tile #localAvatar {
      display: flex !important;
      width: 48px !important;
      height: 48px !important;
      font-size: 1.35rem !important;
      position: static !important;
      margin-bottom: 6px !important;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.5) !important;
    }

    .audience-strip .tile .name {
      position: static !important;
      font-size: 0.76rem !important;
      font-weight: 600 !important;
      color: #e2e8f0 !important;
      max-width: 90% !important;
      text-align: center !important;
      white-space: nowrap !important;
      overflow: hidden !important;
      text-overflow: ellipsis !important;
      background: none !important;
      border: none !important;
      padding: 0 !important;
    }

    .audience-strip .tile .state {
      position: absolute !important;
      top: 6px !important;
      right: 6px !important;
      font-size: 0.62rem !important;
      padding: 2px 6px !important;
      border-radius: 6px !important;
      background: rgba(100, 116, 139, 0.3) !important;
      color: #94a3b8 !important;
    }

    .audience-strip .tile-mute-btn,
    .audience-strip .tile-full-btn {
      display: none !important;
    }


    /* Rótulos: Nome de quem apresenta (topo) e participantes (baixo) */
    .stage-header-title {
      font-size: 0.92rem;
      font-weight: 700;
      color: #f1f5f9;
      letter-spacing: 0.3px;
      margin-bottom: 4px;
      margin-top: 4px;
      display: flex;
      align-items: center;
      gap: 6px;
      width: 100%;
    }

    .audience-header-title {
      font-size: 0.85rem;
      font-weight: 700;
      color: #94a3b8;
      text-transform: lowercase;
      letter-spacing: 0.5px;
      margin-bottom: 4px;
      margin-top: 4px;
      display: flex;
      align-items: center;
      gap: 6px;
      width: 100%;
    }

    .audience-carousel-wrap {
      display: flex;
      align-items: center;
      position: relative;
      width: 100%;
      height: 124px;
      margin-top: 2px;
      overflow: hidden;
    }

    .audience-nav-btn {
      position: absolute !important;
      top: 50% !important;
      transform: translateY(-50%) !important;
      width: 34px !important;
      height: 72px !important;
      background: rgba(15, 23, 42, 0.95) !important;
      backdrop-filter: blur(12px) !important;
      -webkit-backdrop-filter: blur(12px) !important;
      border: 1px solid rgba(0, 210, 255, 0.45) !important;
      color: #00d2ff !important;
      font-size: 1.15rem !important;
      border-radius: 10px !important;
      display: none;
      align-items: center !important;
      justify-content: center !important;
      cursor: pointer !important;
      z-index: 30 !important;
      transition: all 0.2s ease !important;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.7), 0 0 12px rgba(0, 210, 255, 0.25) !important;
    }

    .audience-nav-prev {
      left: 2px !important;
    }

    .audience-nav-next {
      right: 2px !important;
    }

    .audience-nav-btn:hover {
      background: rgba(0, 210, 255, 0.28) !important;
      border-color: #00d2ff !important;
      transform: translateY(-50%) scale(1.08) !important;
      box-shadow: 0 0 20px rgba(0, 210, 255, 0.6) !important;
    }

    /* Responsividade do Sidebar */
    @media (min-width: 901px) {
      .sidebar {
        display: flex !important;
        position: relative !important;
        width: 360px !important;
        min-width: 320px !important;
        max-width: 380px !important;
        height: 100% !important;
      }
      .sidebar.sr-hidden {
        display: none !important;
      }
    }

    @media (max-width: 900px) {
      .sidebar {
        position: fixed !important;
        inset: 0 !important;
        width: 100vw !important;
        max-width: 100vw !important;
        z-index: 250 !important;
      }
      .sidebar.sr-hidden {
        display: none !important;
      }
    }


    /* ========================================================
       BARRA DE NAVEGAÇÃO FIXA NO RODAPÉ DO CELULAR
       ======================================================== */
    .mobile-bottom-nav {
      display: none;
    }
    .mobile-media-controls {
      display: none;
    }

    @media (max-width: 900px) {
      .mobile-bottom-nav {
        display: flex !important;
        position: fixed !important;
        bottom: 0 !important;
        left: 0 !important;
        right: 0 !important;
        width: 100vw !important;
        height: 60px !important;
        height: max(60px, calc(52px + env(safe-area-inset-bottom, 8px))) !important;
        background: rgba(11, 17, 32, 0.98) !important;
        backdrop-filter: blur(20px) !important;
        -webkit-backdrop-filter: blur(20px) !important;
        border-top: 1px solid rgba(255, 255, 255, 0.12) !important;
        z-index: 260 !important;
        justify-content: space-around !important;
        align-items: center !important;
        padding-bottom: env(safe-area-inset-bottom, 4px) !important;
        box-sizing: border-box !important;
        box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.5) !important;
      }

      .mobile-nav-item {
        flex: 1 !important;
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        justify-content: center !important;
        background: none !important;
        border: none !important;
        color: #94a3b8 !important;
        padding: 4px 0 !important;
        cursor: pointer !important;
        font-family: inherit !important;
        transition: color 0.15s ease !important;
        position: relative !important;
      }

      .mobile-nav-item.active {
        color: #00d2ff !important;
      }

      .mobile-nav-item .m-nav-icon {
        font-size: 1.32rem !important;
        line-height: 1.1 !important;
      }

      .mobile-nav-item .m-nav-text {
        font-size: 0.68rem !important;
        font-weight: 600 !important;
        margin-top: 2px !important;
        letter-spacing: 0.2px !important;
      }

      .mobile-nav-item.m-nav-danger {
        color: #ef4444 !important;
      }

      /* Controles rápidos de mídia no celular flutuando acima da barra fixa */
      .mobile-media-controls {
        display: flex !important;
        position: fixed !important;
        bottom: max(68px, calc(64px + env(safe-area-inset-bottom, 8px))) !important;
        left: 50% !important;
        transform: translateX(-50%) !important;
        background: rgba(15, 23, 42, 0.94) !important;
        backdrop-filter: blur(16px) !important;
        border: 1px solid rgba(0, 210, 255, 0.3) !important;
        border-radius: 9999px !important;
        padding: 5px 12px !important;
        gap: 10px !important;
        z-index: 120 !important;
        box-shadow: 0 4px 25px rgba(0, 0, 0, 0.6) !important;
      }

      .mobile-media-btn {
        width: 42px !important;
        height: 42px !important;
        min-width: 42px !important;
        border-radius: 50% !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        font-size: 1.2rem !important;
        background: rgba(255, 255, 255, 0.08) !important;
        border: 1px solid rgba(255, 255, 255, 0.15) !important;
        color: #fff !important;
        cursor: pointer !important;
        transition: all 0.2s ease !important;
      }

      .mobile-media-btn.active {
        background: rgba(0, 210, 255, 0.25) !important;
        border-color: #00d2ff !important;
        color: #00d2ff !important;
      }

      .mobile-media-btn.hand-active {
        background: rgba(234, 179, 8, 0.35) !important;
        border-color: #eab308 !important;
        color: #fef08a !important;
      }

      /* No mobile, o dock de desktop é ocultado */
      .controls {
        display: none !important;
      }

      /* Sidebar no mobile ocupa toda a tela acima da barra fixa */
      .sidebar {
        position: fixed !important;
        top: 50px !important;
        left: 0 !important;
        right: 0 !important;
        bottom: max(60px, calc(52px + env(safe-area-inset-bottom, 8px))) !important;
        width: 100vw !important;
        max-width: 100vw !important;
        height: auto !important;
        z-index: 250 !important;
        border-left: none !important;
        border-top: 1px solid rgba(255, 255, 255, 0.1) !important;
      }

      .sidebar.sr-hidden {
        display: none !important;
      }

      .sidebar-close-btn {
        display: none !important;
      }

      .room-stage {
        padding: 6px 6px 125px 6px !important;
      }
    }


    /* ========================================================
       MENU DE CONTEXTO DE RESOLUÇÃO (BOTÃO DIREITO NO VÍDEO)
       ======================================================== */
    .video-context-menu {
      position: fixed;
      z-index: 99999;
      background: rgba(15, 23, 42, 0.96);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      border: 1px solid rgba(0, 210, 255, 0.35);
      border-radius: 10px;
      padding: 6px;
      min-width: 230px;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.75), 0 0 15px rgba(0, 210, 255, 0.15);
      font-family: inherit;
      color: #f1f5f9;
      user-select: none;
      animation: vcmFadeIn 0.15s ease-out;
    }

    @keyframes vcmFadeIn {
      from { opacity: 0; transform: scale(0.96); }
      to { opacity: 1; transform: scale(1); }
    }

    .vcm-header {
      padding: 6px 10px;
      display: flex;
      flex-direction: column;
      gap: 2px;
    }

    .vcm-title {
      font-size: 0.78rem;
      font-weight: 700;
      color: #00d2ff;
      letter-spacing: 0.3px;
    }

    .vcm-subtitle {
      font-size: 0.68rem;
      color: #94a3b8;
    }

    .vcm-divider {
      height: 1px;
      background: rgba(255, 255, 255, 0.12);
      margin: 4px 0;
    }

    .vcm-item {
      padding: 8px 12px;
      border-radius: 6px;
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 0.78rem;
      cursor: pointer;
      transition: background 0.15s, color 0.15s;
      position: relative;
    }

    .vcm-item:hover {
      background: rgba(0, 210, 255, 0.2);
      color: #fff;
    }

    .vcm-item .vcm-icon {
      font-size: 0.95rem;
    }

    .vcm-item .vcm-label {
      flex: 1;
      font-weight: 500;
      white-space: nowrap;
    }

    .vcm-item .vcm-arrow {
      font-size: 0.65rem;
      color: #64748b;
    }

    .vcm-item .vcm-check {
      width: 14px;
      font-size: 0.75rem;
      color: #10b981;
      font-weight: bold;
      display: inline-block;
    }

    .vcm-item.selected .vcm-check::before {
      content: '✓';
    }

    .vcm-item.selected {
      background: rgba(16, 185, 129, 0.15);
      color: #6ee7b7;
      font-weight: 600;
    }

    /* Submenu cascateado */
    .vcm-parent-item {
      position: relative;
    }

    .vcm-submenu {
      display: none;
      position: absolute;
      top: 0;
      left: 100%;
      margin-left: 4px;
      background: rgba(15, 23, 42, 0.98);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      border: 1px solid rgba(0, 210, 255, 0.35);
      border-radius: 10px;
      padding: 6px;
      min-width: 250px;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.75);
    }

    .vcm-parent-item:hover .vcm-submenu,
    .vcm-parent-item.open .vcm-submenu {
      display: block;
    }

    @media (max-width: 600px) {
      .vcm-submenu {
        position: static;
        margin-left: 0;
        margin-top: 4px;
        border: none;
        background: rgba(0, 0, 0, 0.3);
        box-shadow: none;
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
        <span id="controlStatusIndicator" style="font-size: 0.72rem; padding: 2px 6px; border-radius: 4px; background: rgba(59, 130, 246, 0.15); color: #60a5fa; display: none; margin-left: 6px;" title="Canal de Controle Administrativo">Controle: conectando...</span>
      </div>
    </div>

    <div class="header-actions">
      <div id="peerConnectionCounter" style="font-size: 0.78rem; color: var(--text-muted, #94a3b8); margin-right: 4px; display: flex; align-items: center; gap: 4px;">
        Participantes: <strong>1</strong> · Peers esperados: <strong>0</strong> · Conectados: <strong>0</strong>
      </div>
      <span class="room-timer" id="meetingTimer">00:00:00</span>
      <span id="iceRoute" style="font-size: 0.76rem; color: var(--text-dim, #64748b);"></span>
      
      <!-- Botão de Diagnóstico WebRTC em tempo real -->
      <button type="button" class="header-btn" id="topBtnDiagnostics" onclick="MeetingDiagnostics.openDiagnosticsModal()" title="Diagnóstico WebRTC em tempo real">
        📊 <span class="header-btn-text">Diagnóstico</span>
      </button>

      <!-- Botões de Ação Topo Estilo Teams -->
      <button type="button" class="header-btn" id="topBtnParticipants" onclick="openSidebarTab('participants')" title="Lista de Participantes">
        👥 <span id="participantBadgeText"><span class="participant-count-num">1</span><span class="online-text"> Online</span></span>
      </button>
      
      <button type="button" class="header-btn" id="topBtnChat" onclick="openSidebarTab('chat')" title="Bate-papo">
        💬 <span class="header-btn-text">Chat</span> <span id="chatBadgeCount" style="display:none; background: #ef4444; border-radius: 10px; padding: 1px 6px; font-size: 11px;">0</span>
      </button>

      <button type="button" class="header-btn" id="topBtnActions" onclick="toggleRoomActionsMenu(this)" title="Menu de Ações da Reunião">
        ⚙️ <span class="header-btn-text">Ações</span> <span id="actionsDotBadge" style="display:none; width: 7px; height: 7px; background: var(--primary, #00d2ff); border-radius: 50%; margin-left: 4px;" title="Novos arquivos disponíveis"></span>
      </button>

      <button type="button" class="header-btn" id="topBtnInvite" onclick="copyInvite()" title="Copiar link de convite">
        📋 <span class="header-btn-text">Convidar</span>
      </button>

      <a href="index.php" class="header-btn" id="topBtnDashboard" style="text-decoration: none;" title="Voltar ao Painel">
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
            <!-- Notificação Flutuante de Pedido da Palavra (Teams Style Toast) -->
      <!-- Indicador Compacto de Mão Levantada no Canto Superior Esquerdo (Tarefas 24 a 31) -->
      <div id="raisedHandIndicator" class="raised-hand-indicator" style="display: none;" onclick="MeetingParticipants.onRaisedHandIndicatorClick()" title="Pedidos de palavra">
        <span>✋</span>
        <span id="raisedHandCount" style="font-size: 0.85rem; margin-left: 2px;"></span>
      </div>

      <!-- Toast Flutuante Central de Pedido de Palavra para o Administrador -->
      <div id="handToast" style="display: none; position: absolute; top: 72px; left: 50%; transform: translateX(-50%); background: rgba(15, 23, 42, 0.96); backdrop-filter: blur(16px); border: 1px solid rgba(234, 179, 8, 0.5); border-radius: 30px; padding: 8px 20px; z-index: 75; box-shadow: 0 10px 30px rgba(0,0,0,0.7); align-items: center; gap: 14px;">
        <span id="handToastText" style="color: #fef08a; font-weight: 700; font-size: 0.92rem;"></span>
        <div id="handToastButtons" style="display: flex; gap: 8px;"></div>
      </div>
            <!-- Banner de Controle da Exibição Full (Resolução Máxima / Compartilhamento) -->
      <div id="fullModeBanner" class="full-mode-banner">
        <div style="display: flex; align-items: center; gap: 8px;">
          <span style="font-size: 1.1rem; color: #00d2ff;">⛶</span>
          <strong id="fullModeTitle" style="color: #fff; font-size: 0.9rem;">Exibição Full</strong>
          <span style="font-size: 0.72rem; background: rgba(0, 210, 255, 0.2); color: #00d2ff; padding: 2px 8px; border-radius: 12px; border: 1px solid rgba(0, 210, 255, 0.4); font-weight: 600;">Resolução Máxima</span>
        </div>
        <button type="button" id="btnNormalViewBanner" class="sr-btn sr-btn-sm sr-btn-primary" onclick="MeetingParticipants.revokeFullMode()" style="border-radius: 20px; padding: 6px 18px; font-weight: 700; cursor: pointer; background: #00d2ff; color: #050811; border: none; box-shadow: 0 0 15px rgba(0,210,255,0.4); display: inline-flex; align-items: center; gap: 6px;">
          ▦ Exibição Normal
        </button>
        <?php if ($canAdmit): ?>
        <button type="button" id="btnTakeBackBanner" class="sr-btn sr-btn-sm" onclick="MeetingPresentation.takeBackConduction()" style="display: none; border-radius: 20px; padding: 6px 18px; font-weight: 700; cursor: pointer; background: #ef4444; color: #fff; border: none; box-shadow: 0 0 15px rgba(239,68,68,0.4); margin-left: 8px;">
          🎙️ Retomar Condução
        </button>
        <?php endif; ?>
      </div>

      <!-- Toast de Compartilhamento de Tela para Aprovação do Administrador -->
      <div id="screenShareToast" style="display: none; position: absolute; top: 62px; left: 50%; transform: translateX(-50%); background: rgba(15, 23, 42, 0.95); backdrop-filter: blur(16px); border: 1px solid rgba(0, 210, 255, 0.5); border-radius: 30px; padding: 8px 18px; z-index: 76; box-shadow: 0 10px 30px rgba(0,0,0,0.6); align-items: center; gap: 14px;">
        <span id="screenShareToastText" style="color: #00d2ff; font-weight: 600; font-size: 0.88rem;">🖥️ Convidado iniciou compartilhamento</span>
        <div style="display: flex; gap: 8px;" id="screenShareToastButtons"></div>
      </div>
<?php
$activePresKey = $runtimeState['active_presenter_key'] ?? null;
$isLocalPresenter = ($activePresKey && $activePresKey === $me['participant_key']) || (!$activePresKey && $canAdmit);
$initialPresenterTitle = 'Aguardando Apresentação';
if ($isLocalPresenter) {
    $initialPresenterTitle = 'Você (' . ($me['display_name'] ?: 'Você') . ')';
} elseif (!empty($activePresKey)) {
    try {
        $stP = $pdo->prepare("SELECT display_name FROM room_presence WHERE room_id = ? AND participant_key = ? LIMIT 1");
        $stP->execute([(int)$me['room_id'], $activePresKey]);
        if ($pRow = $stP->fetch()) {
            $initialPresenterTitle = $pRow['display_name'];
        }
    } catch (Throwable $e) {}
}
?>
      <!-- Layout: quem apresenta (topo) + participantes com carrossel (baixo) -->
      <div class="stage-container" id="videos">
        <!-- Rótulo Superior: Nome do usuário que apresenta -->
        <div class="stage-header-title" id="stagePresenterName"><?= e($initialPresenterTitle) ?></div>
        <div id="stageArea" class="stage-area">
          <?php if ($isLocalPresenter): ?>
          <div class="tile local stage-speaker" id="tile-local">
            <video id="local" autoplay muted playsinline webkit-playsinline></video>
            <div id="localAvatar" style="display: none; width: 96px; height: 96px; border-radius: 50%; background: linear-gradient(135deg, var(--brand-primary, #00d2ff), #3b82f6); color: #fff; font-size: 2.4rem; font-weight: 700; align-items: center; justify-content: center; position: absolute; z-index: 2; box-shadow: 0 4px 25px rgba(0,0,0,0.6);">
              <?= strtoupper(substr(trim($me['display_name'] ?: 'U'), 0, 1)) ?>
            </div>
            <div class="name">Você (<?=e($me['display_name'])?>)</div>
            <div class="state" id="localState">AO VIVO</div>
          </div>
          <?php endif; ?>
        </div>

        <!-- Carrossel de participantes na base -->
        <div class="audience-carousel-wrap">
          <button type="button" class="audience-nav-btn audience-nav-prev" onclick="MeetingParticipants.scrollAudience(-1)" title="Ver participantes anteriores" style="display: none;">
            ◀
          </button>
          <div id="audienceStrip" class="audience-strip">
            <?php if (!$isLocalPresenter): ?>
            <div class="tile local audience-listener" id="tile-local">
              <video id="local" autoplay muted playsinline webkit-playsinline style="display: none;"></video>
              <div id="localAvatar" style="display: flex; width: 64px; height: 64px; border-radius: 50%; background: linear-gradient(135deg, var(--brand-primary, #00d2ff), #3b82f6); color: #fff; font-size: 1.8rem; font-weight: 700; align-items: center; justify-content: center; position: absolute; z-index: 2; box-shadow: 0 4px 25px rgba(0,0,0,0.6);">
                <?= strtoupper(substr(trim($me['display_name'] ?: 'U'), 0, 1)) ?>
              </div>
              <div class="name">Você (<?=e($me['display_name'])?>)</div>
              <div class="state" id="localState" style="display: none;"></div>
            </div>
            <?php endif; ?>
          </div>
          <button type="button" class="audience-nav-btn audience-nav-next" onclick="MeetingParticipants.scrollAudience(1)" title="Ver mais participantes" style="display: none;">
            ▶
          </button>
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
        <button type="button" id="btnHand" onclick="MeetingParticipants.toggleRaiseHand()" title="Pedir a Palavra (Levantar a Mão)">
          ✋ <span class="btn-label" id="handLabel">Pedir Palavra</span>
        </button>
        <?php if ($canAdmit): ?>
        <button type="button" id="btnTakeBackConduction" style="display: none; background: linear-gradient(135deg, #ef4444, #dc2626); border: none; color: #fff; font-weight: 700; border-radius: 20px; padding: 6px 16px; box-shadow: 0 0 15px rgba(239, 68, 68, 0.4);" onclick="MeetingPresentation.takeBackConduction()" title="Retomar a Condução da Reunião imediatamente">
          🎙️ <span class="btn-label">Retomar Condução</span>
        </button>
        <?php endif; ?>
        <button type="button" id="btnNormalViewDock" style="display: none; background: rgba(0, 210, 255, 0.2); border: 1px solid #00d2ff; color: #00d2ff; font-weight: 700;" onclick="MeetingParticipants.revokeFullMode()" title="Voltar para a Exibição Normal (Grade)">
          ▦ <span class="btn-label">Exibição Normal</span>
        </button>
        <button type="button" id="screen" onclick="MeetingMedia.toggleScreen()" title="Compartilhar Tela">
          🖥️ <span class="btn-label">Compartilhar</span>
        </button>
        <button type="button" id="btnDockChat" onclick="openSidebarTab('chat')" title="Abrir Chat">
          💬 <span class="btn-label">Chat</span>
        </button>
        <button type="button" id="btnDockActions" onclick="toggleRoomActionsMenu(this)" title="Ações da Reunião" style="position: relative;">
          ⚙️ <span class="btn-label">Ações</span> <span id="dockActionsDotBadge" style="display:none; width: 6px; height: 6px; background: var(--primary, #00d2ff); border-radius: 50%; position: absolute; top: 6px; right: 8px;"></span>
        </button>
        <button type="button" id="btnDockParticipants" onclick="openSidebarTab('participants')" title="Ver Participantes">
          👥 <span class="btn-label">Participantes</span>
        </button>
        <button type="button" id="btnDockInvite" onclick="copyInvite()" title="Copiar Link de Convite">
          📋 <span class="btn-label">Convidar</span>
        </button>
        <button type="button" id="leave" class="btn-danger" onclick="MeetingApp.leaveRoom(true)" title="Sair da Reunião">
          🔴 <span class="btn-label">Sair</span>
        </button>
      </div>

      <!-- Controles Rápidos de Mídia no Celular (Flutuando acima da barra fixa) -->
      <div id="mobileMediaControls" class="mobile-media-controls">
        <button type="button" id="mobileMic" class="mobile-media-btn active" onclick="MeetingMedia.toggleMicrophone()" title="Ativar/Desativar Microfone">
          🎙️
        </button>
        <button type="button" id="mobileCam" class="mobile-media-btn active" onclick="MeetingMedia.toggleCamera()" title="Ligar/Desligar Câmera">
          📹
        </button>
        <button type="button" id="mobileBtnHand" class="mobile-media-btn" onclick="MeetingParticipants.toggleRaiseHand()" title="Pedir a Palavra (Levantar a Mão)">
          ✋
        </button>
        <?php if ($canAdmit): ?>
        <button type="button" id="mobileBtnTakeBack" class="mobile-media-btn" style="display: none; background: #ef4444; border-color: #ef4444; color: #fff;" onclick="MeetingPresentation.takeBackConduction()" title="Retomar a Condução da Reunião">
          🎙️
        </button>
        <?php endif; ?>
      </div>
    </main>

    <!-- Barra Lateral Fixa com Abas: Chat, Usuários, Arquivos -->
    <aside class="sidebar" id="roomSidebar">
      <div class="sidebar-header">
        <div class="sidebar-tabs">
          <button type="button" id="tabChat" class="sidebar-tab-btn active" onclick="setSideTab('chat')">
            💬 chat <span id="chatUnread" class="sr-badge sr-badge-scheduled" style="display:none; padding: 1px 6px; font-size: 10px;">0</span>
          </button>
          <button type="button" id="tabParticipants" class="sidebar-tab-btn" onclick="setSideTab('participants')">
            👥 usuarios (<span id="sidebarParticipantCount">1</span>)
          </button>
          <button type="button" id="tabFiles" class="sidebar-tab-btn" onclick="setSideTab('files')">
            📁 arquivos (<span id="sidebarFilesCount">0</span>)
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
            <input type="file" id="chatFileInput" style="display: none;" onchange="MeetingFiles.uploadFileFromInput(this)">
            <button type="button" class="file-upload-btn" onclick="document.getElementById('chatFileInput').click()" title="Anexar e transferir arquivo para a sala">
              📎
            </button>
            <textarea id="chatInput" placeholder="Digite uma mensagem e pressione Enter (ou anexe com 📎)..." rows="2"></textarea>
            <button type="submit" class="sr-btn sr-btn-primary sr-btn-sm" style="align-self: flex-end; height: 48px; padding: 0 16px;">
              Enviar
            </button>
          </form>
        </div>
      </div>

      <!-- Files Pane (Espaço de Transferência de Arquivos) -->
      <div id="paneFiles" class="sidepane">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
          <strong style="font-size: 0.88rem; color: #fff;">📁 Arquivos Compartilhados</strong>
          <button type="button" class="sr-btn sr-btn-primary sr-btn-sm" onclick="document.getElementById('paneFileInput').click()" style="padding: 4px 10px; font-size: 0.78rem;">
            📤 Enviar Arquivo
          </button>
          <input type="file" id="paneFileInput" style="display: none;" onchange="MeetingFiles.uploadFileFromInput(this)">
        </div>

        <div id="fileUploadProgress" style="display: none; margin-bottom: 12px; background: rgba(0, 210, 255, 0.1); border: 1px solid rgba(0, 210, 255, 0.3); border-radius: 8px; padding: 10px; font-size: 0.82rem; color: var(--primary, #00d2ff);">
          <span class="sr-pulse-dot"></span> Enviando arquivo para o storage da sala...
        </div>

        <!-- Drop / Upload Box -->
        <div id="fileDropZone" style="border: 2px dashed var(--border-glass); border-radius: 10px; padding: 16px; text-align: center; margin-bottom: 16px; background: rgba(255, 255, 255, 0.02); cursor: pointer;" onclick="document.getElementById('paneFileInput').click()">
          <div style="font-size: 1.6rem; margin-bottom: 4px;">📂</div>
          <div style="font-size: 0.84rem; color: #cbd5e1; font-weight: 600;">Clique ou arraste arquivos aqui</div>
          <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 2px;">Armazenado na subpasta dinâmica desta sala</div>
        </div>

        <div id="roomFilesList" style="display: flex; flex-direction: column; gap: 8px; overflow-y: auto; max-height: calc(100vh - 350px);">
          <div style="color: var(--text-dim); font-size: 0.84rem; text-align: center; padding: 20px;">Carregando arquivos...</div>
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

    
    // Controle do Menu de Ações da Reunião (Tarefas 32 a 38)
    let activeActionsAnchor = null;

    function toggleRoomActionsMenu(anchor) {
      const menu = document.getElementById('roomActionsMenu');
      if (!menu) return;
      if (menu.style.display === 'block' && activeActionsAnchor === anchor) {
        closeRoomActionsMenu();
        return;
      }
      openRoomActionsMenu(anchor);
    }

    function openRoomActionsMenu(anchor) {
      const menu = document.getElementById('roomActionsMenu');
      if (!menu || !anchor) return;
      activeActionsAnchor = anchor;

      const rect = anchor.getBoundingClientRect();
      menu.style.display = 'block';

      const menuWidth = 230;
      const menuHeight = menu.offsetHeight || 140;
      const isDock = (anchor.id === 'btnDockActions');

      let left = rect.left + (rect.width / 2) - (menuWidth / 2);
      if (left < 10) left = 10;
      if (left + menuWidth > window.innerWidth - 10) {
        left = window.innerWidth - menuWidth - 10;
      }

      let top = 0;
      if (isDock) {
        top = rect.top - menuHeight - 10;
        if (top < 10) top = rect.bottom + 10;
      } else {
        top = rect.bottom + 8;
        if (top + menuHeight > window.innerHeight - 10) {
          top = rect.top - menuHeight - 8;
        }
      }

      menu.style.position = 'fixed';
      menu.style.top = Math.round(top) + 'px';
      menu.style.left = Math.round(left) + 'px';
      menu.style.zIndex = '10000';
    }

    function closeRoomActionsMenu() {
      const menu = document.getElementById('roomActionsMenu');
      if (menu) menu.style.display = 'none';
      activeActionsAnchor = null;
    }

    function onActionMenuItem(action) {
      closeRoomActionsMenu();
      if (action === 'files') {
        openSidebarTab('files');
      } else if (action === 'invite') {
        copyInvite();
      } else if (action === 'screen') {
        if (window.MeetingMedia) window.MeetingMedia.toggleScreen();
      }
    }

    document.addEventListener('click', (e) => {
      const menu = document.getElementById('roomActionsMenu');
      if (!menu || menu.style.display !== 'block') return;
      if (menu.contains(e.target)) return;
      if (activeActionsAnchor && activeActionsAnchor.contains(e.target)) return;
      closeRoomActionsMenu();
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closeRoomActionsMenu();
    });

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
      const isVisible = !!(sb && !sb.classList.contains('sr-hidden'));
      const isChat = !!(isVisible && document.getElementById('paneChat')?.classList.contains('active'));
      const isFiles = !!(isVisible && document.getElementById('paneFiles')?.classList.contains('active'));
      const isPart = !!(isVisible && document.getElementById('paneParticipants')?.classList.contains('active'));
      
      const topChat = document.getElementById('topBtnChat');
      const topActions = document.getElementById('topBtnActions');
      const topPart = document.getElementById('topBtnParticipants');
      const dockChat = document.getElementById('btnDockChat');
      const dockActions = document.getElementById('btnDockActions');
      const dockPart = document.getElementById('btnDockParticipants');

      if (topChat) topChat.classList.toggle('active', isChat);
      if (topActions) topActions.classList.toggle('active', isFiles);
      if (dockActions) dockActions.classList.toggle('active', isFiles);
      if (topPart) topPart.classList.toggle('active', isPart);
      if (dockChat) dockChat.classList.toggle('active', isChat);
      if (dockPart) dockPart.classList.toggle('active', isPart);
    }

    
    function selectMobileTab(tab) {
      const isMobile = (window.innerWidth <= 900);
      const stageContainer = document.getElementById('videos');
      const sidebar = document.getElementById('roomSidebar');
      const mediaPill = document.getElementById('mobileMediaControls');

      document.querySelectorAll('.mobile-nav-item').forEach(el => el.classList.remove('active'));

      if (tab === 'stage' || tab === 'conference') {
        const btn = document.getElementById('mNavStage');
        if (btn) btn.classList.add('active');

        if (isMobile) {
          if (sidebar) sidebar.classList.add('sr-hidden');
          if (stageContainer) stageContainer.style.display = 'flex';
          if (mediaPill) mediaPill.style.display = 'flex';
        }
        if (window.MeetingPresentation && typeof window.MeetingPresentation.updateStageUI === 'function') {
          window.MeetingPresentation.updateStageUI();
        }
      } else {
        const btnMap = {
          'chat': 'mNavChat',
          'participants': 'mNavUsers',
          'files': 'mNavFiles'
        };
        const btn = document.getElementById(btnMap[tab]);
        if (btn) btn.classList.add('active');

        if (isMobile) {
          if (stageContainer) stageContainer.style.display = 'none';
          if (mediaPill) mediaPill.style.display = 'none';
          if (sidebar) {
            sidebar.classList.remove('sr-hidden');
            sidebar.style.display = 'flex';
          }
        }
        setSideTab(tab);
      }
    }

    window.addEventListener('DOMContentLoaded', () => {
      if (window.innerWidth <= 900) {
        selectMobileTab('stage');
      }
    });

    window.addEventListener('resize', () => {
      if (window.innerWidth > 900) {
        const stageContainer = document.getElementById('videos');
        const sidebar = document.getElementById('roomSidebar');
        if (stageContainer) stageContainer.style.display = 'flex';
        if (sidebar) {
          sidebar.style.display = 'flex';
          sidebar.classList.remove('sr-hidden');
        }
      }
    });

    function setSideTab(tab) {
      const isChat = (tab === 'chat');
      const isFiles = (tab === 'files');
      const isPart = (tab === 'participants');

      document.getElementById('paneParticipants')?.classList.toggle('active', isPart);
      document.getElementById('paneChat')?.classList.toggle('active', isChat);
      document.getElementById('paneFiles')?.classList.toggle('active', isFiles);

      document.getElementById('tabParticipants')?.classList.toggle('active', isPart);
      document.getElementById('tabChat')?.classList.toggle('active', isChat);
      document.getElementById('tabFiles')?.classList.toggle('active', isFiles);

      if (window.MeetingChat) {
        window.MeetingChat.setChatOpen(isChat);
      }
      if (isFiles && window.MeetingFiles) {
        window.MeetingFiles.loadFiles();
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
      WS_CONTROL_URL: <?=json_encode(!empty($config['websocket']['control_url']) ? (string)$config['websocket']['control_url'] : ($wsEnabled ? rtrim(str_replace('/ws', '', $wsUrl), '/') . '/control' : 'wss://maurinsoft.com.br/control'))?>,
      WS_ENABLED: <?=json_encode($wsEnabled)?>,
      WS_RECONNECT_MS: <?=$wsReconnect?>,
      RELAY_MODE: <?=json_encode($relayMode)?>,
      BRIDGE_ENABLED: <?=json_encode($bridgeEnabled)?>,
      BRIDGE_URL: <?=json_encode($bridgeUrl)?>,
      BRIDGE_CHUNK_MS: <?=$bridgeChunkMs?>,
      BRIDGE_FALLBACK_TIMEOUT_MS: <?=$bridgeFallbackTimeoutMs?>,
      MAX_MESH_PARTICIPANTS: <?=$maxMeshParticipants?>,
      selfKey: <?=json_encode($me['participant_key'])?>,
      roomId: <?=(int)$me['room_id']?>,
      roomName: <?=json_encode($me['room_name'])?>,
      displayName: <?=json_encode($me['display_name'])?>,
      inviteUrl: <?=json_encode($inviteUrl)?>,
      initialRuntimeState: <?=json_encode($runtimeState, JSON_UNESCAPED_UNICODE)?>
    };
  </script>

  <!-- Módulos JavaScript Especializados do Sala Reunião -->
  <script src="assets/js/meeting/logger.js?v=20261003_35"></script>
  <script src="assets/js/meeting/media.js?v=20261003_35"></script>
  <script src="assets/js/meeting/bridge.js?v=20261003_35"></script>
  <script src="assets/js/meeting/signaling.js?v=20261003_35"></script>
  <script src="assets/js/meeting/webrtc.js?v=20261003_35"></script>
  <script src="assets/js/meeting/participants.js?v=20261003_35"></script>
  <script src="assets/js/meeting/control.js?v=20261003_35"></script>
  <script src="assets/js/meeting/presentation.js?v=20261003_35"></script>
  <script src="assets/js/meeting/files.js?v=20261003_35"></script>
  <script src="assets/js/meeting/chat.js?v=20261003_35"></script>
  <script src="assets/js/meeting/diagnostics.js?v=20261003_35"></script>
  <script src="assets/js/meeting/meeting.js?v=20261003_35"></script>

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

    // Inicia a aplicação e central de arquivos
    if (window.MeetingFiles) {
      MeetingFiles.initDropZone();
      MeetingFiles.loadFiles();
    }
    MeetingSignaling.setSignalCursor(<?=$signalCursor?>);
    MeetingApp.initMeeting();
  </script>

  <!-- Menu Único de Ações da Reunião (Tarefas 32 a 38) -->
  <div id="roomActionsMenu" class="room-actions-menu" style="display: none;" role="menu">
    <button type="button" class="actions-menu-item" onclick="onActionMenuItem('files')">
      <span>📁 Arquivos da Sala</span>
      <span id="menuFilesCountBadge" class="sr-badge sr-badge-primary" style="display:none; margin-left: auto; font-size: 0.72rem; padding: 2px 7px; border-radius: 10px; background: rgba(0, 210, 255, 0.2); color: #00d2ff; font-weight: 700;">0</span>
    </button>
    <button type="button" class="actions-menu-item" onclick="onActionMenuItem('invite')">
      <span>📋 Convidar / Copiar Link</span>
    </button>
    <button type="button" class="actions-menu-item" onclick="onActionMenuItem('screen')">
      <span>🖥 Compartilhar Tela</span>
    </button>
  </div>


  <!-- Barra de Navegação FIXA no Rodapé do Celular (Estilo Teams / Zoom / WhatsApp) -->
  <nav class="mobile-bottom-nav" id="mobileBottomNav">
    <button type="button" class="mobile-nav-item active" id="mNavStage" onclick="selectMobileTab('stage')">
      <span class="m-nav-icon">📹</span>
      <span class="m-nav-text">Conferência</span>
    </button>
    <button type="button" class="mobile-nav-item" id="mNavChat" onclick="selectMobileTab('chat')">
      <span class="m-nav-icon">💬</span>
      <span class="m-nav-text">Chat</span>
    </button>
    <button type="button" class="mobile-nav-item" id="mNavUsers" onclick="selectMobileTab('participants')">
      <span class="m-nav-icon">👥</span>
      <span class="m-nav-text">Usuários</span>
    </button>
    <button type="button" class="mobile-nav-item" id="mNavFiles" onclick="selectMobileTab('files')">
      <span class="m-nav-icon">📁</span>
      <span class="m-nav-text">Arquivos</span>
    </button>
    <button type="button" class="mobile-nav-item m-nav-danger" id="mNavLeave" onclick="MeetingApp.leaveRoom(true)">
      <span class="m-nav-icon">🔴</span>
      <span class="m-nav-text">Sair</span>
    </button>
  </nav>


  <!-- Menu de Contexto para Seleção de Resolução de Exibição (Apenas Administrador) -->
  <?php if ($canAdmit): ?>
  <div id="videoResolutionContextMenu" class="video-context-menu" style="display: none;">
    <div class="vcm-header">
      <span class="vcm-title">⚙️ Imagem em Exibição</span>
      <span class="vcm-subtitle" id="vcmTargetSubtitle">Palco Principal</span>
    </div>
    <div class="vcm-divider"></div>
    <div class="vcm-parent-item" id="vcmResolutionParent" onclick="this.classList.toggle('open')">
      <div class="vcm-item vcm-has-submenu">
        <span class="vcm-icon">📐</span>
        <span class="vcm-label">Resolução</span>
        <span class="vcm-arrow">▶</span>
      </div>
      <div class="vcm-submenu" id="vcmResolutionSubmenu">
        <div class="vcm-item" data-res="1080p" onclick="MeetingPresentation.changeDisplayResolution('1080p')">
          <span class="vcm-check"></span>
          <span class="vcm-label">1080p (Full HD - 1920×1080)</span>
        </div>
        <div class="vcm-item" data-res="720p" onclick="MeetingPresentation.changeDisplayResolution('720p')">
          <span class="vcm-check"></span>
          <span class="vcm-label">720p (HD - 1280×720)</span>
        </div>
        <div class="vcm-item" data-res="480p" onclick="MeetingPresentation.changeDisplayResolution('480p')">
          <span class="vcm-check"></span>
          <span class="vcm-label">480p (SD - 854×480)</span>
        </div>
        <div class="vcm-item" data-res="360p" onclick="MeetingPresentation.changeDisplayResolution('360p')">
          <span class="vcm-check"></span>
          <span class="vcm-label">360p (Normal - 640×360)</span>
        </div>
        <div class="vcm-item" data-res="240p" onclick="MeetingPresentation.changeDisplayResolution('240p')">
          <span class="vcm-check"></span>
          <span class="vcm-label">240p (Baixa - 320×240)</span>
        </div>
        <div class="vcm-item" data-res="120p" onclick="MeetingPresentation.changeDisplayResolution('120p')">
          <span class="vcm-check"></span>
          <span class="vcm-label">120p (Econômica - 160×120)</span>
        </div>
        <div class="vcm-divider"></div>
        <div class="vcm-item" data-res="auto" onclick="MeetingPresentation.changeDisplayResolution('auto')">
          <span class="vcm-check"></span>
          <span class="vcm-label">⚡ Automática (Adaptativa)</span>
        </div>
      </div>
    </div>
    <div class="vcm-divider"></div>
    <div class="vcm-item" id="vcmToggleFit" onclick="MeetingPresentation.toggleStageFit()">
      <span class="vcm-icon">🖼️</span>
      <span class="vcm-label" id="vcmFitLabel">Ajustar Proporção (Sem Cortes)</span>
    </div>
  </div>
  <?php endif; ?>

</body>
</html>
