<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$roomName = trim((string)($_GET['room_name'] ?? ''));
$reason = trim((string)($_GET['reason'] ?? ''));
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Acesso Encerrado - Maurinsoft Sala Reunião</title>
  <link rel="stylesheet" href="assets/css/salareuniao.css">
  <style>
    body {
      background: #050811;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 20px;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
      color: #f1f5f9;
      margin: 0;
    }
    .sr-kicked-card {
      max-width: 520px;
      width: 100%;
      background: rgba(15, 23, 42, 0.85);
      border: 1px solid rgba(239, 68, 68, 0.35);
      border-radius: 18px;
      padding: 36px 30px;
      text-align: center;
      box-shadow: 0 20px 50px rgba(0, 0, 0, 0.7), 0 0 35px rgba(239, 68, 68, 0.12);
      backdrop-filter: blur(16px);
      animation: fadeIn 0.35s ease-out;
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(12px) scale(0.98); }
      to { opacity: 1; transform: translateY(0) scale(1); }
    }
    .sr-kicked-icon {
      width: 76px;
      height: 76px;
      margin: 0 auto 20px;
      border-radius: 50%;
      background: rgba(239, 68, 68, 0.15);
      border: 2px solid rgba(239, 68, 68, 0.45);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 38px;
      box-shadow: 0 0 25px rgba(239, 68, 68, 0.25);
    }
    .sr-kicked-title {
      font-size: 1.55rem;
      font-weight: 700;
      color: #fff;
      margin-bottom: 12px;
      letter-spacing: -0.02em;
    }
    .sr-kicked-desc {
      color: #94a3b8;
      font-size: 0.95rem;
      line-height: 1.55;
      margin-bottom: 24px;
    }
    .sr-kicked-room-box {
      background: rgba(0, 0, 0, 0.4);
      border: 1px solid rgba(255, 255, 255, 0.08);
      border-radius: 10px;
      padding: 14px 18px;
      margin-bottom: 24px;
      font-size: 0.88rem;
      text-align: left;
    }
    .sr-kicked-room-box div {
      display: flex;
      justify-content: space-between;
      margin-bottom: 6px;
    }
    .sr-kicked-room-box div:last-child {
      margin-bottom: 0;
    }
    .sr-kicked-actions {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }
  </style>
</head>
<body>

  <div class="sr-kicked-card">
    <div class="sr-kicked-icon">🚫</div>
    <h1 class="sr-kicked-title">Você foi expulso da sala de reunião</h1>
    <p class="sr-kicked-desc">
      O administrador ou moderador encerrou sua participação e revogou seu acesso a esta sala de videoconferência.
    </p>

    <?php if ($roomName !== ''): ?>
      <div class="sr-kicked-room-box">
        <div>
          <span style="color: #64748b;">Sala:</span>
          <strong style="color: #f8fafc;"><?=htmlspecialchars($roomName)?></strong>
        </div>
        <div>
          <span style="color: #64748b;">Data/Hora:</span>
          <span style="color: #94a3b8;"><?=date('d/m/Y H:i:s')?></span>
        </div>
      </div>
    <?php endif; ?>

    <div class="sr-kicked-actions">
      <a href="index.php" class="sr-btn sr-btn-primary" style="padding: 12px 20px; font-size: 0.95rem; font-weight: 600; text-decoration: none;">
        Voltar para a Página Inicial
      </a>
      <a href="../" class="sr-btn sr-btn-secondary" style="padding: 10px 18px; font-size: 0.88rem; text-decoration: none;">
        Portal Maurinsoft
      </a>
    </div>
  </div>

</body>
</html>
