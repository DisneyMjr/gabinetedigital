<?php
// --- LIGA O RADAR DE ERROS ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Verificação segura de sessão
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require 'config.php';

// ==========================================================
// 1. VERIFICAÇÃO BÁSICA DE LOGIN
// ==========================================================
if (!isset($_SESSION['permissoes']) || !in_array('master', $_SESSION['permissoes'])) {
    header("Location: login.php"); 
    exit;
}

// ==========================================================
// 2. TRAVA ABSOLUTA DE SEGURANÇA (APENAS O FUNDADOR)
// ==========================================================
$email_logado = $_SESSION['email'] ?? $_SESSION['usuario_email'] ?? '';
$id_logado = $_SESSION['usuario_id'] ?? $_SESSION['id'] ?? $_SESSION['admin_id'] ?? 0;

if (empty($email_logado) && $id_logado > 0) {
    $stmt_email = $pdo->prepare("SELECT email FROM usuarios WHERE id = ?");
    $stmt_email->execute([$id_logado]);
    $email_logado = $stmt_email->fetchColumn();
}

// A REGRA DE OURO:
if (strtolower(trim($email_logado)) !== 'disneymjr@gmail.com') {
    die("
    <div style='display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100vh; background-color: #111827; color: white; font-family: sans-serif; text-align: center; padding: 20px;'>
        <div style='background: #ef4444; padding: 20px; border-radius: 50%; margin-bottom: 20px;'>
            <svg width='64' height='64' fill='none' stroke='currentColor' stroke-width='2' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' d='M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z'></path></svg>
        </div>
        <h1 style='font-size: 2.5rem; margin-bottom: 10px;'>Acesso Restrito</h1>
        <p style='color: #9ca3af; font-size: 1.2rem; max-width: 500px;'>Esta área é de acesso exclusivo do Fundador do sistema. O seu e-mail (" . htmlspecialchars($email_logado) . ") não tem permissão para gerir administradores.</p>
        <a href='master_painel.php' style='margin-top: 30px; padding: 12px 30px; background: #374151; color: white; text-decoration: none; border-radius: 50px; font-weight: bold; transition: 0.3s;'>Voltar ao Painel Master</a>
    </div>
    ");
}

// ==========================================================
// 3. AÇÕES (SALVAR / EXCLUIR)
// ==========================================================
$msg_sistema = "";

// MENSAGENS DE FEEDBACK
if(isset($_GET['msg'])) {
    if($_GET['msg'] == 'add_ok') $msg_sistema = "<div class='alert alert-success shadow-sm fw-bold'><i class='fas fa-check-circle me-2'></i> Novo administrador adicionado!</div>";
    if($_GET['msg'] == 'edit_ok') $msg_sistema = "<div class='alert alert-success shadow-sm fw-bold'><i class='fas fa-check-circle me-2'></i> Administrador atualizado!</div>";
    if($_GET['msg'] == 'del_ok') $msg_sistema = "<div class='alert alert-warning shadow-sm fw-bold'><i class='fas fa-trash me-2'></i> Administrador removido.</div>";
    if($_GET['msg'] == 'erro_email') $msg_sistema = "<div class='alert alert-danger shadow-sm fw-bold'><i class='fas fa-exclamation-triangle me-2'></i> Este e-mail já está em uso!</div>";
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'salvar_admin') {
    try {
        $id_admin = (int)$_POST['id_admin'];
        $nome = trim($_POST['nome']);
        $email = trim($_POST['email']);
        
        $check = $pdo->prepare("SELECT id FROM usuarios WHERE email = ? AND id != ?");
        $check->execute([$email, $id_admin]);
        
        if ($check->rowCount() > 0) {
            header("Location: ?msg=erro_email"); 
            exit;
        } else {
            if ($id_admin > 0) {
                if (!empty($_POST['senha'])) {
                    $senha = password_hash($_POST['senha'], PASSWORD_DEFAULT);
                    $pdo->prepare("UPDATE usuarios SET nome=?, email=?, senha=? WHERE id=?")->execute([$nome, $email, $senha, $id_admin]);
                } else {
                    $pdo->prepare("UPDATE usuarios SET nome=?, email=? WHERE id=?")->execute([$nome, $email, $id_admin]);
                }
                header("Location: ?msg=edit_ok"); 
                exit;
            } else {
                $senha = password_hash($_POST['senha'], PASSWORD_DEFAULT);
                // INSERE O ADMIN SEMPRE NO TENANT 1 (MASTER)
                $pdo->prepare("INSERT INTO usuarios (tenant_id, nome, email, senha, nivel) VALUES (1, ?, ?, ?, 'master')")->execute([$nome, $email, $senha]);
                header("Location: ?msg=add_ok"); 
                exit;
            }
        }
    } catch (Exception $e) {
        $msg_sistema = "<div class='alert alert-danger shadow-sm'><b>Erro no DB:</b> " . $e->getMessage() . "</div>";
    }
}

if (isset($_GET['acao']) && $_GET['acao'] == 'del_admin') {
    $id_del = (int)$_GET['id'];
    
    $stmt_check = $pdo->prepare("SELECT email FROM usuarios WHERE id = ?");
    $stmt_check->execute([$id_del]);
    $email_del = strtolower($stmt_check->fetchColumn());

    if ($email_del === 'disneymjr@gmail.com') {
        $msg_sistema = "<div class='alert alert-danger fw-bold shadow-sm'>Ação Negada: A conta do fundador não pode ser excluída!</div>";
    } else {
        $pdo->prepare("DELETE FROM usuarios WHERE id=? AND nivel='master'")->execute([$id_del]);
        header("Location: ?msg=del_ok"); 
        exit;
    }
}

$lista_admins = $pdo->query("SELECT id, nome, email FROM usuarios WHERE nivel = 'master' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Segurança Máxima | Gestão de Administradores</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f3f4f6; font-family: 'Inter', sans-serif; color: #374151; }
        .sidebar { min-height: 100vh; background: #000000; color: #fff; position: fixed; width: 260px; left: 0; top: 0; padding-top: 25px; z-index: 1000; border-right: 2px solid #333; }
        .main-content { margin-left: 260px; padding: 40px; }
        .sidebar .nav-link { color: rgba(255,255,255,0.7); padding: 14px 25px; font-weight: 500; transition: 0.3s; display: flex; align-items: center; gap: 10px; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: #fff; background: rgba(255,255,255,0.1); border-left: 4px solid #ef4444; }
        .card-dash { border: none; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); background: #fff; overflow: hidden; }
        @media (max-width: 768px) { .sidebar { display: none; } .main-content { margin-left: 0; padding: 20px; } }
    </style>
</head>
<body>

<div class="sidebar d-none d-md-block">
    <div class="px-4 mb-5 d-flex align-items-center gap-2">
        <div class="bg-danger text-white rounded p-1"><i class="fas fa-lock fa-lg"></i></div>
        <h5 class="fw-bold m-0 text-white tracking-wide">FUNDADOR</h5>
    </div>
    <ul class="nav flex-column gap-1">
        <li class="nav-item"><a href="master_painel.php" class="nav-link"><i class="fas fa-arrow-left me-2"></i> Voltar ao Painel</a></li>
        <li class="nav-item"><a href="#" class="nav-link active"><i class="fas fa-user-shield me-2"></i> Administradores</a></li>
    </ul>
</div>

<div class="main-content">
    <?= $msg_sistema ?>

    <div class="d-flex justify-content-between align-items-end mb-5 border-bottom border-danger pb-3 border-3">
        <div>
            <h3 class="fw-bold text-dark mb-1"><i class="fas fa-shield-alt text-danger me-2"></i> Controle de Acesso Master</h3>
            <p class="text-muted m-0">Apenas <b>disneymjr@gmail.com</b> tem acesso a esta tela.</p>
        </div>
        <button class="btn btn-danger rounded-pill fw-bold px-4 shadow" onclick="novoAdmin()">
            <i class="fas fa-user-plus me-1"></i> Adicionar Admin
        </button>
    </div>

    <div class="card card-dash mb-5 border-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light text-muted small text-uppercase">
                    <tr>
                        <th class="ps-4 py-3">Nome</th>
                        <th class="py-3">E-mail de Acesso</th>
                        <th class="py-3">Status</th>
                        <th class="text-end pe-4 py-3">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($lista_admins as $admin): ?>
                    <tr>
                        <td class="ps-4 fw-bold text-dark"><?= htmlspecialchars($admin['nome']) ?></td>
                        <td class="text-muted"><?= htmlspecialchars($admin['email']) ?></td>
                        <td><span class="badge bg-success">Ativo</span></td>
                        <td class="text-end pe-4">
                            <?php if(strtolower($admin['email']) === 'disneymjr@gmail.com'): ?>
                                <span class="badge bg-danger rounded-pill px-3 py-2"><i class="fas fa-crown me-1"></i> Você (Fundador)</span>
                            <?php else: ?>
                                <button class="btn btn-sm btn-light border text-primary me-2 fw-bold px-3" onclick='editarAdmin(<?= json_encode($admin) ?>)'><i class="fas fa-pen me-1"></i> Editar</button>
                                <a href="?acao=del_admin&id=<?= $admin['id'] ?>" class="btn btn-sm btn-light border text-danger fw-bold px-3" onclick="return confirm('Tem certeza que deseja remover o acesso deste administrador?')"><i class="fas fa-trash me-1"></i> Remover</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalAdmin" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-dark text-white border-0">
                <h5 class="modal-title fw-bold"><i class="fas fa-user-shield me-2"></i> Dados do Administrador</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" name="acao" value="salvar_admin">
                <input type="hidden" name="id_admin" id="admin_id">
                
                <div class="mb-3">
                    <label class="small fw-bold mb-1 text-muted">NOME COMPLETO</label>
                    <input type="text" name="nome" id="admin_nome" class="form-control bg-light" required placeholder="Ex: João da Silva">
                </div>
                
                <div class="mb-3">
                    <label class="small fw-bold mb-1 text-muted">E-MAIL (LOGIN)</label>
                    <input type="email" name="email" id="admin_email" class="form-control bg-light" required placeholder="joao@seusistema.com">
                </div>
                
                <div class="mb-2">
                    <label class="small fw-bold mb-1 text-muted">SENHA</label>
                    <input type="text" name="senha" id="admin_senha" class="form-control bg-light" placeholder="Digite a senha">
                    <small class="text-danger fw-bold mt-1 d-block" id="admin_senha_help" style="font-size: 0.75rem;">Para criar um novo admin, a senha é obrigatória.</small>
                </div>
            </div>
            <div class="modal-footer border-0 bg-light">
                <button type="button" class="btn btn-secondary px-4 fw-bold" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-danger px-4 fw-bold">Salvar Acesso</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function novoAdmin() {
        document.getElementById('admin_id').value = '';
        document.getElementById('admin_nome').value = '';
        document.getElementById('admin_email').value = '';
        document.getElementById('admin_senha').required = true;
        document.getElementById('admin_senha_help').innerText = "Obrigatório para novos admins.";
        new bootstrap.Modal(document.getElementById('modalAdmin')).show();
    }

    function editarAdmin(admin) {
        document.getElementById('admin_id').value = admin.id;
        document.getElementById('admin_nome').value = admin.nome;
        document.getElementById('admin_email').value = admin.email;
        document.getElementById('admin_senha').required = false;
        document.getElementById('admin_senha').value = '';
        document.getElementById('admin_senha_help').innerText = "Deixe em branco se não quiser alterar a senha atual.";
        document.getElementById('admin_senha_help').classList.replace('text-danger', 'text-muted');
        new bootstrap.Modal(document.getElementById('modalAdmin')).show();
    }
</script>
</body>
</html>