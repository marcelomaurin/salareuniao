<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
$me = require_admin();

$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            $error = 'Informe um nome, um e-mail válido e uma senha com pelo menos 8 caracteres.';
        } else {
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $st = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, active) VALUES (?, ?, ?, ?, 1)");
                $st->execute([$name, $email, $hash, $role]);
                $newId = (int)$pdo->lastInsertId();
                audit_log('user.create', 'user', $newId, ['email' => $email, 'role' => $role]);
                $msg = 'Usuário cadastrado com sucesso!';
            } catch (PDOException $e) {
                $error = 'Não foi possível cadastrar o usuário. Verifique se o e-mail já está em uso.';
            }
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['user_id'] ?? 0);
        if ($id === (int)$me['id']) {
            $error = 'Você não pode desativar seu próprio usuário logado.';
        } else {
            try {
                $st = $pdo->prepare("UPDATE users SET active = IF(active = 1, 0, 1) WHERE id = ?");
                $st->execute([$id]);
                audit_log('user.toggle_active', 'user', $id);
                $msg = 'Status do usuário alterado com sucesso.';
            } catch (Throwable $e) {
                $error = 'Erro ao alterar status: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'role') {
        $id = (int)($_POST['user_id'] ?? 0);
        $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
        if ($id === (int)$me['id'] && $role !== 'admin') {
            $error = 'Você não pode revogar seus próprios privilégios de administrador.';
        } else {
            try {
                $st = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
                $st->execute([$role, $id]);
                audit_log('user.change_role', 'user', $id, ['role' => $role]);
                $msg = 'Perfil do usuário alterado para ' . ($role === 'admin' ? 'Administrador' : 'Usuário') . '.';
            } catch (Throwable $e) {
                $error = 'Erro ao alterar perfil: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'reset_pass') {
        $id = (int)($_POST['user_id'] ?? 0);
        $newPass = $_POST['new_password'] ?? '';
        if (strlen($newPass) < 8) {
            $error = 'A nova senha deve ter no mínimo 8 caracteres.';
        } else {
            try {
                $hash = password_hash($newPass, PASSWORD_DEFAULT);
                $st = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $st->execute([$hash, $id]);
                audit_log('user.reset_password', 'user', $id);
                $msg = 'Senha do usuário atualizada com sucesso.';
            } catch (Throwable $e) {
                $error = 'Erro ao redefinir senha: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['user_id'] ?? 0);
        if ($id === (int)$me['id']) {
            $error = 'Você não pode excluir sua própria conta logada.';
        } else {
            try {
                $pdo->beginTransaction();
                // Reatribui salas do usuário a excluir para o admin atual para não perder histórico
                $pdo->prepare("UPDATE rooms SET owner_user_id = ? WHERE owner_user_id = ?")->execute([$me['id'], $id]);
                try { $pdo->prepare("DELETE FROM api_tokens WHERE user_id = ?")->execute([$id]); } catch (Throwable $e) {}
                try { $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$id]); } catch (Throwable $e) {}
                $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
                $pdo->commit();
                audit_log('user.delete', 'user', $id);
                $msg = 'Usuário excluído com sucesso.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Não foi possível excluir o usuário: ' . $e->getMessage();
            }
        }
    }
}

// Estatísticas e listagem
try {
    $stats = $pdo->query("
        SELECT
          COUNT(*) as total,
          SUM(role = 'admin') as admins,
          SUM(active = 1) as actives,
          SUM(active = 0) as inactives
        FROM users
    ")->fetch();

    $users = $pdo->query("
        SELECT id, name, email, role, active, created_at,
          (SELECT COUNT(*) FROM rooms WHERE owner_user_id = users.id) as rooms_count
        FROM users
        ORDER BY role DESC, name ASC
    ")->fetchAll();
} catch (Throwable $e) {
    $stats = ['total' => 0, 'admins' => 0, 'actives' => 0, 'inactives' => 0];
    $users = [];
    $error = 'Erro ao carregar dados: ' . $e->getMessage();
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Gerenciamento de Usuários - Sala Reunião | Maurinsoft</title>
  <link rel="stylesheet" href="assets/css/salareuniao.css">
  <style>
    .sr-admin-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
      gap: 16px;
      margin-bottom: 24px;
    }
    .sr-stat-card {
      background: var(--bg-card);
      border: 1px solid var(--border-glass);
      border-radius: var(--radius-md);
      padding: 18px 22px;
      backdrop-filter: blur(12px);
    }
    .sr-stat-val {
      font-size: 2.2rem;
      font-weight: 700;
      color: var(--primary);
      margin: 4px 0;
    }
    .sr-stat-desc {
      font-size: 0.82rem;
      color: var(--text-muted);
    }
    .sr-filter-bar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 16px;
      margin-bottom: 20px;
      flex-wrap: wrap;
    }
    .sr-search-input {
      background: rgba(15, 23, 42, 0.7);
      border: 1px solid var(--border-glass);
      border-radius: var(--radius-sm);
      color: #fff;
      padding: 10px 16px;
      font-size: 0.9rem;
      min-width: 280px;
      flex: 1;
      max-width: 400px;
    }
    .sr-search-input:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 10px rgba(0, 210, 255, 0.25);
    }
    .sr-filter-select {
      background: rgba(15, 23, 42, 0.7);
      border: 1px solid var(--border-glass);
      border-radius: var(--radius-sm);
      color: #fff;
      padding: 10px 14px;
      font-size: 0.88rem;
    }
    .sr-user-avatar-sm {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: linear-gradient(135deg, #0284c7, #0369a1);
      color: #fff;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 0.88rem;
      flex-shrink: 0;
    }
    .sr-role-admin {
      background: rgba(0, 210, 255, 0.15);
      color: #38bdf8;
      border: 1px solid rgba(0, 210, 255, 0.3);
    }
    .sr-role-user {
      background: rgba(148, 163, 184, 0.12);
      color: #cbd5e1;
      border: 1px solid rgba(148, 163, 184, 0.2);
    }
    .sr-card-form {
      background: var(--bg-card);
      border: 1px solid var(--border-glass);
      border-radius: var(--radius-md);
      padding: 24px;
      margin-bottom: 24px;
      backdrop-filter: blur(12px);
      display: none;
      animation: srFadeIn 0.2s ease-out;
    }
    .sr-card-form.active {
      display: block;
    }
    .sr-table-container {
      background: var(--bg-card);
      border: 1px solid var(--border-glass);
      border-radius: var(--radius-md);
      overflow-x: auto;
      backdrop-filter: blur(12px);
    }
    .sr-action-group {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      flex-wrap: wrap;
    }
  </style>
</head>
<body class="sr-body">

  <!-- Topbar -->
  <header class="sr-topbar">
    <div class="sr-topbar-inner">
      <div class="sr-brand">
        <div class="sr-brand-logo">M</div>
        <div>
          <div class="sr-brand-title">Maurinsoft <span style="font-weight: 400; color: var(--primary);">Administração</span></div>
        </div>
        <span class="sr-brand-badge" style="background: rgba(0, 210, 255, 0.15); color: var(--primary); border: 1px solid rgba(0, 210, 255, 0.3);">Gestão de Usuários</span>
      </div>

      <div class="sr-nav-links">
        <a href="../" class="sr-btn sr-btn-secondary sr-btn-sm">&larr; Portal Principal</a>
        <a href="index.php" class="sr-btn sr-btn-secondary sr-btn-sm">Central de Salas</a>

        <!-- Dropdown Administração -->
        <div class="sr-dropdown" id="adminDropdownContainer">
          <button type="button" class="sr-btn sr-btn-secondary sr-btn-sm" onclick="toggleAdminDropdown(event)">
            ⚙️ Administração <span style="font-size: 0.72rem; margin-left: 3px;">▼</span>
          </button>
          <div class="sr-dropdown-menu" id="adminDropdownMenu">
            <div class="sr-dropdown-header">Funções Administrativas</div>
            <a href="admin_users.php" class="sr-dropdown-item" style="background: rgba(0, 210, 255, 0.1);">
              <span class="sr-dropdown-icon">👥</span>
              <div>
                <div class="sr-dropdown-title" style="color: var(--primary);">Usuários Cadastrados</div>
                <div class="sr-dropdown-desc">Gerenciamento de contas e perfis</div>
              </div>
            </a>
            <a href="admin.php" class="sr-dropdown-item">
              <span class="sr-dropdown-icon">📊</span>
              <div>
                <div class="sr-dropdown-title">Painel WebRTC & Salas</div>
                <div class="sr-dropdown-desc">Telemetria ao vivo e monitoramento</div>
              </div>
            </a>
            <a href="admin_devices.php" class="sr-dropdown-item">
              <span class="sr-dropdown-icon">📟</span>
              <div>
                <div class="sr-dropdown-title">Dispositivos & Terminais</div>
                <div class="sr-dropdown-desc">Controladores de sala ESP32</div>
              </div>
            </a>
            <a href="admin_audit.php" class="sr-dropdown-item">
              <span class="sr-dropdown-icon">📜</span>
              <div>
                <div class="sr-dropdown-title">Auditoria do Sistema</div>
                <div class="sr-dropdown-desc">Logs de segurança e eventos</div>
              </div>
            </a>
            <a href="admin_system.php" class="sr-dropdown-item">
              <span class="sr-dropdown-icon">🛠️</span>
              <div>
                <div class="sr-dropdown-title">Diagnóstico & Configurações</div>
                <div class="sr-dropdown-desc">SMTP, TURN e testes do ambiente</div>
              </div>
            </a>
          </div>
        </div>

        <div class="sr-user-pill">
          <div class="sr-user-avatar"><?=strtoupper(substr($me['name'], 0, 1))?></div>
          <span><?=e($me['name'])?> (Admin)</span>
        </div>
        <a href="logout.php" class="sr-btn sr-btn-danger sr-btn-sm">Sair</a>
      </div>
    </div>
  </header>

  <!-- Container Principal -->
  <main class="sr-container" style="padding-top: 24px; padding-bottom: 60px;">

    <!-- Alertas -->
    <?php if ($msg): ?>
      <div style="margin-bottom: 20px; background: rgba(34, 197, 94, 0.15); border: 1px solid rgba(34, 197, 94, 0.3); color: #86efac; padding: 12px 18px; border-radius: 10px; font-size: 0.95rem; display: flex; align-items: center; gap: 8px;">
        <span>✅</span> <?=e($msg)?>
      </div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div style="margin-bottom: 20px; background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: #fca5a5; padding: 12px 18px; border-radius: 10px; font-size: 0.95rem; display: flex; align-items: center; gap: 8px;">
        <span>⚠️</span> <?=e($error)?>
      </div>
    <?php endif; ?>

    <!-- Cabeçalho da Seção -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 14px;">
      <div>
        <h1 style="font-size: 1.7rem; font-weight: 700; margin-bottom: 4px;">Gerenciamento de Usuários</h1>
        <p style="color: var(--text-muted); font-size: 0.9rem;">
          Cadastre novos usuários, altere níveis de permissão e controle as contas ativas na plataforma.
        </p>
      </div>
      <div>
        <button type="button" class="sr-btn sr-btn-primary" onclick="toggleNewUserForm()">
          ➕ Novo Usuário
        </button>
      </div>
    </div>

    <!-- KPI Stats -->
    <section class="sr-admin-grid">
      <div class="sr-stat-card">
        <div class="sr-stat-desc">Total de Usuários</div>
        <div class="sr-stat-val"><?=(int)($stats['total'] ?? 0)?></div>
        <div class="sr-stat-desc">Contas cadastradas</div>
      </div>
      <div class="sr-stat-card">
        <div class="sr-stat-desc">Administradores</div>
        <div class="sr-stat-val" style="color: var(--primary);"><?=(int)($stats['admins'] ?? 0)?></div>
        <div class="sr-stat-desc">Acesso administrativo completo</div>
      </div>
      <div class="sr-stat-card">
        <div class="sr-stat-desc">Usuários Ativos</div>
        <div class="sr-stat-val" style="color: var(--accent-green);"><?=(int)($stats['actives'] ?? 0)?></div>
        <div class="sr-stat-desc">Acesso liberado ao sistema</div>
      </div>
      <div class="sr-stat-card">
        <div class="sr-stat-desc">Usuários Inativos</div>
        <div class="sr-stat-val" style="color: var(--accent-amber);"><?=(int)($stats['inactives'] ?? 0)?></div>
        <div class="sr-stat-desc">Acesso temporariamente bloqueado</div>
      </div>
    </section>

    <!-- Formulário: Novo Usuário (Retrátil) -->
    <div class="sr-card-form" id="newUserForm">
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 style="font-size: 1.25rem;">Cadastrar Novo Usuário</h2>
        <button type="button" class="sr-btn sr-btn-secondary sr-btn-sm" onclick="toggleNewUserForm()">✕ Fechar</button>
      </div>

      <form method="post">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <input type="hidden" name="action" value="create">

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; margin-bottom: 20px;">
          <div>
            <label class="sr-label" for="new_name">Nome Completo</label>
            <input type="text" id="new_name" name="name" class="sr-input" placeholder="Ex: João da Silva" required>
          </div>
          <div>
            <label class="sr-label" for="new_email">E-mail</label>
            <input type="email" id="new_email" name="email" class="sr-input" placeholder="usuario@empresa.com.br" required>
          </div>
          <div>
            <label class="sr-label" for="new_password">Senha Inicial (mínimo 8 dígitos)</label>
            <input type="password" id="new_password" name="password" class="sr-input" placeholder="••••••••" minlength="8" required>
          </div>
          <div>
            <label class="sr-label" for="new_role">Perfil de Acesso</label>
            <select id="new_role" name="role" class="sr-input" style="height: 44px;">
              <option value="user">Usuário Comum (Salas e Reuniões)</option>
              <option value="admin">Administrador (Acesso Geral)</option>
            </select>
          </div>
        </div>

        <div style="display: flex; justify-content: flex-end; gap: 10px;">
          <button type="button" class="sr-btn sr-btn-secondary" onclick="toggleNewUserForm()">Cancelar</button>
          <button type="submit" class="sr-btn sr-btn-primary">✓ Concluir Cadastro</button>
        </div>
      </form>
    </div>

    <!-- Barra de Filtros e Busca -->
    <div class="sr-filter-bar">
      <input type="text" id="userSearch" class="sr-search-input" placeholder="🔍 Buscar por nome ou e-mail..." oninput="filterUsers()">

      <div style="display: flex; gap: 10px;">
        <select id="filterRole" class="sr-filter-select" onchange="filterUsers()">
          <option value="">Todos os Perfis</option>
          <option value="admin">Administradores</option>
          <option value="user">Usuários Comuns</option>
        </select>

        <select id="filterStatus" class="sr-filter-select" onchange="filterUsers()">
          <option value="">Todos os Status</option>
          <option value="1">Ativos</option>
          <option value="0">Inativos</option>
        </select>
      </div>
    </div>

    <!-- Tabela de Usuários -->
    <div class="sr-table-container">
      <table class="sr-table" id="usersTable">
        <thead>
          <tr>
            <th>Usuário</th>
            <th>E-mail</th>
            <th>Perfil</th>
            <th>Status</th>
            <th>Salas</th>
            <th>Criado em</th>
            <th style="text-align: right;">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
            <?php
              $isSelf = ((int)$u['id'] === (int)$me['id']);
              $isAdmin = ($u['role'] === 'admin');
              $isActive = (bool)$u['active'];
            ?>
            <tr class="user-row" data-name="<?=e(strtolower($u['name']))?>" data-email="<?=e(strtolower($u['email']))?>" data-role="<?=$u['role']?>" data-active="<?=$isActive ? '1' : '0'?>">
              <td>
                <div style="display: flex; align-items: center; gap: 12px;">
                  <div class="sr-user-avatar-sm" style="<?=$isAdmin ? 'background: linear-gradient(135deg, #0284c7, #00d2ff);' : ''?>">
                    <?=strtoupper(substr($u['name'], 0, 1))?>
                  </div>
                  <div>
                    <div style="font-weight: 600; color: #fff;">
                      <?=e($u['name'])?>
                      <?php if ($isSelf): ?>
                        <span style="font-size: 0.72rem; color: var(--primary); margin-left: 4px;">(Você)</span>
                      <?php endif; ?>
                    </div>
                    <div style="font-size: 0.75rem; color: var(--text-dim);">ID #<?=(int)$u['id']?></div>
                  </div>
                </div>
              </td>
              <td>
                <span style="color: var(--text-muted);"><?=e($u['email'])?></span>
              </td>
              <td>
                <span class="sr-badge <?=$isAdmin ? 'sr-role-admin' : 'sr-role-user'?>">
                  <?=$isAdmin ? '🛡️ Administrador' : '👤 Usuário'?>
                </span>
              </td>
              <td>
                <span class="sr-badge <?=$isActive ? 'sr-badge-open' : 'sr-badge-closed'?>">
                  <?php if ($isActive): ?><span class="sr-pulse-dot"></span><?php endif; ?>
                  <?=$isActive ? 'Ativo' : 'Inativo'?>
                </span>
              </td>
              <td>
                <span style="color: var(--text-muted); font-size: 0.85rem;"><?=(int)$u['rooms_count']?></span>
              </td>
              <td style="color: var(--text-dim); font-size: 0.82rem;">
                <?=!empty($u['created_at']) ? date('d/m/Y H:i', strtotime($u['created_at'])) : '-'?>
              </td>
              <td style="text-align: right;">
                <div class="sr-action-group">
                  
                  <!-- Alternar Perfil -->
                  <?php if (!$isSelf): ?>
                    <form method="post" style="display: inline-block; margin: 0;">
                      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
                      <input type="hidden" name="action" value="role">
                      <input type="hidden" name="user_id" value="<?=(int)$u['id']?>">
                      <input type="hidden" name="role" value="<?=$isAdmin ? 'user' : 'admin'?>">
                      <button type="submit" class="sr-btn sr-btn-secondary sr-btn-sm" title="Mudar para <?=$isAdmin ? 'Usuário Comum' : 'Administrador'?>">
                        <?=$isAdmin ? 'Tornar Usuário' : 'Tornar Admin'?>
                      </button>
                    </form>
                  <?php endif; ?>

                  <!-- Alternar Ativo/Inativo -->
                  <?php if (!$isSelf): ?>
                    <form method="post" style="display: inline-block; margin: 0;">
                      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="user_id" value="<?=(int)$u['id']?>">
                      <button type="submit" class="sr-btn sr-btn-secondary sr-btn-sm" title="<?=$isActive ? 'Desativar acesso' : 'Ativar acesso'?>">
                        <?=$isActive ? 'Desativar' : 'Ativar'?>
                      </button>
                    </form>
                  <?php endif; ?>

                  <!-- Botão Redefinir Senha -->
                  <button type="button" class="sr-btn sr-btn-secondary sr-btn-sm" onclick="openResetModal(<?=(int)$u['id']?>, '<?=e(addslashes($u['name']))?>')" title="Alterar senha deste usuário">
                    🔑 Senha
                  </button>

                  <!-- Botão Excluir -->
                  <?php if (!$isSelf): ?>
                    <form method="post" style="display: inline-block; margin: 0;" onsubmit="return confirm('Tem certeza que deseja excluir o usuário &quot;<?=e(addslashes($u['name']))?>&quot;? As salas vinculadas serão preservadas.');">
                      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="user_id" value="<?=(int)$u['id']?>">
                      <button type="submit" class="sr-btn sr-btn-danger sr-btn-sm" title="Excluir usuário">
                        🗑️
                      </button>
                    </form>
                  <?php endif; ?>

                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  </main>

  <!-- Modal de Redefinição de Senha -->
  <div class="sr-modal-overlay" id="resetModal">
    <div class="sr-modal-box">
      <h3 style="font-size: 1.3rem; margin-bottom: 8px;">Redefinir Senha</h3>
      <p style="color: var(--text-muted); font-size: 0.88rem; margin-bottom: 20px;">
        Defina uma nova senha para <strong id="modalUserName" style="color: #fff;"></strong>:
      </p>

      <form method="post">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <input type="hidden" name="action" value="reset_pass">
        <input type="hidden" name="user_id" id="modalUserId" value="0">

        <div style="margin-bottom: 20px;">
          <label class="sr-label" for="new_password_field">Nova Senha (mínimo 8 caracteres)</label>
          <input type="password" id="new_password_field" name="new_password" class="sr-input" placeholder="Digite a nova senha segura" minlength="8" required>
        </div>

        <div style="display: flex; justify-content: flex-end; gap: 10px;">
          <button type="button" class="sr-btn sr-btn-secondary" onclick="closeResetModal()">Cancelar</button>
          <button type="submit" class="sr-btn sr-btn-primary">Salvar Nova Senha</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    function toggleNewUserForm() {
      const form = document.getElementById('newUserForm');
      form.classList.toggle('active');
      if (form.classList.contains('active')) {
        document.getElementById('new_name').focus();
      }
    }

    function toggleAdminDropdown(e) {
      e.stopPropagation();
      const dropdown = document.getElementById('adminDropdownContainer');
      if (dropdown) dropdown.classList.toggle('active');
    }

    document.addEventListener('click', function(e) {
      const dropdown = document.getElementById('adminDropdownContainer');
      if (dropdown && !dropdown.contains(e.target)) {
        dropdown.classList.remove('active');
      }
    });

    function filterUsers() {
      const search = document.getElementById('userSearch').value.toLowerCase().trim();
      const role = document.getElementById('filterRole').value;
      const status = document.getElementById('filterStatus').value;
      const rows = document.querySelectorAll('.user-row');

      rows.forEach(row => {
        const name = row.dataset.name || '';
        const email = row.dataset.email || '';
        const rowRole = row.dataset.role || '';
        const rowStatus = row.dataset.active || '';

        const matchSearch = !search || name.includes(search) || email.includes(search);
        const matchRole = !role || rowRole === role;
        const matchStatus = !status || rowStatus === status;

        if (matchSearch && matchRole && matchStatus) {
          row.style.display = '';
        } else {
          row.style.display = 'none';
        }
      });
    }

    function openResetModal(userId, userName) {
      document.getElementById('modalUserId').value = userId;
      document.getElementById('modalUserName').textContent = userName;
      document.getElementById('new_password_field').value = '';
      document.getElementById('resetModal').classList.add('active');
      document.getElementById('new_password_field').focus();
    }

    function closeResetModal() {
      document.getElementById('resetModal').classList.remove('active');
    }
  </script>
</body>
</html>
