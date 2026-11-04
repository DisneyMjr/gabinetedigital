<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// admin_usuarios.php - GESTÃO DE EQUIPE E ACESSOS (Ouvvi)
session_start();
require 'conexao.php';
require 'verificar_assinatura.php';

$tenant_id = $_SESSION['tenant_id'];
$user_nome = $_SESSION['user_nome'];
$mensagem = '';

// Proteção extra: Somente Admins deveriam acessar esta tela
// Como estamos implementando agora, vamos garantir que o primeiro usuário não trave
$user_logado_id = $_SESSION['user_id'] ?? 0;

// --- 1. SALVAR NOVO USUÁRIO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $nome = trim($_POST['nome']);
    $email = trim($_POST['email']);
    $senha = password_hash($_POST['senha'], PASSWORD_DEFAULT);
    $nivel_acesso = $_POST['nivel_acesso']; // 'admin' ou 'operador'
    $setor_id = ($nivel_acesso === 'operador') ? (int)$_POST['setor_id'] : null;

    // Verifica se o e-mail já existe
    $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmtCheck->execute([$email]);
    
    if ($stmtCheck->rowCount() > 0) {
        $mensagem = "<div class='alert alert-danger fw-bold'><i class='bi bi-exclamation-circle'></i> E-mail já cadastrado no sistema.</div>";
    } else {
        $sql = "INSERT INTO users (tenant_id, nome, email, senha_hash, nivel_acesso, setor_id, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $pdo->prepare($sql);
        if ($stmt->execute([$tenant_id, $nome, $email, $senha, $nivel_acesso, $setor_id])) {
            $mensagem = "<div class='alert alert-success fw-bold'><i class='bi bi-check-circle'></i> Usuário adicionado com sucesso!</div>";
        }
    }
}

// --- 2. DELETAR USUÁRIO ---
if (isset($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    if ($del_id != $user_logado_id) { // Evita que o admin apague a si mesmo
        $stmtDel = $pdo->prepare("DELETE FROM users WHERE id = ? AND tenant_id = ?");
        $stmtDel->execute([$del_id, $tenant_id]);
        header("Location: admin_usuarios.php");
        exit;
    }
}

// --- BUSCAR DADOS ---
// Setores para o formulário
$stmtSetores = $pdo->prepare("SELECT id, nome FROM sectors WHERE tenant_id = ? ORDER BY nome ASC");
$stmtSetores->execute([$tenant_id]);
$setores = $stmtSetores->fetchAll();

// Equipe atual
$stmtEquipe = $pdo->prepare("SELECT u.*, s.nome as nome_setor FROM users u LEFT JOIN sectors s ON u.setor_id = s.id WHERE u.tenant_id = ? ORDER BY u.nome ASC");
$stmtEquipe->execute([$tenant_id]);
$equipe = $stmtEquipe->fetchAll();
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Equipe e Acessos | Ouvvi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', sans-serif; overflow-x: hidden; }
        .wrapper { display: flex; width: 100%; align-items: stretch; min-height: 100vh; }
        
        #sidebar { min-width: 250px; max-width: 250px; background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%); color: #fff; }
        #sidebar .sidebar-header { padding: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        #sidebar ul li a { padding: 12px 20px; display: block; color: rgba(255,255,255,0.8); text-decoration: none; transition: 0.2s;}
        #sidebar ul li a:hover, #sidebar ul li a.active { color: #fff; background: rgba(255,255,255,0.05); border-left: 4px solid #0d6efd; }
        #sidebar ul li a i { margin-right: 10px; }
        
        #content { width: 100%; padding: 0; min-height: 100vh; }
        .top-navbar { background: #fff; padding: 15px 30px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .main-container { padding: 30px; }
        .card-custom { border: none; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
    </style>
</head>
<body>

    <div class="wrapper">
        <nav id="sidebar">
            <div class="sidebar-header">
                <h4 class="mb-0 fw-bold"><i class="bi bi-shield-check text-primary"></i> Ouvvi<span class="text-secondary fs-6">SaaS</span></h4>
            </div>
            <ul class="list-unstyled mt-4">
                <li><a href="admin.php"><i class="bi bi-arrow-left"></i> Voltar ao Painel</a></li>
                <li><a href="admin_setores.php"><i class="bi bi-building-gear"></i> Setores e Unidades</a></li>
                <li><a href="admin_usuarios.php" class="active"><i class="bi bi-people-fill"></i> Equipe e Acessos</a></li>
                <li><a href="admin_config.php"><i class="bi bi-gear-fill"></i> Configurações</a></li>
            </ul>
        </nav>

        <div id="content">
            <div class="top-navbar d-flex justify-content-between align-items-center">
                <h5 class="m-0 fw-bold text-dark"><i class="bi bi-people-fill text-primary"></i> Gestão de Usuários</h5>
                <span class="fw-bold small text-muted"><?php echo htmlspecialchars($user_nome); ?></span>
            </div>

            <div class="main-container">
                <div class="row">
                    <div class="col-md-4 mb-4">
                        <div class="card card-custom bg-white p-4">
                            <h6 class="fw-bold text-primary mb-3"><i class="bi bi-person-plus-fill"></i> Convidar Colaborador</h6>
                            <?php echo $mensagem; ?>
                            
                            <form method="POST">
                                <input type="hidden" name="add_user" value="1">
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">Nome Completo</label>
                                    <input type="text" name="nome" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">E-mail de Login</label>
                                    <input type="email" name="email" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">Senha Provisória</label>
                                    <input type="password" name="senha" class="form-control" required>
                                </div>
                                
                                <div class="mb-3 p-3 bg-light border rounded">
                                    <label class="form-label small fw-bold text-dark">Nível de Acesso</label>
                                    
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="radio" name="nivel_acesso" id="roleAdmin" value="admin" checked onchange="toggleSetor()">
                                        <label class="form-check-label fw-bold" for="roleAdmin">Ouvidor Chefe (Admin)</label>
                                        <div class="small text-muted" style="font-size:0.75rem;">Acesso total a todas as denúncias e configurações.</div>
                                    </div>
                                    
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="nivel_acesso" id="roleOperador" value="operador" onchange="toggleSetor()">
                                        <label class="form-check-label fw-bold text-primary" for="roleOperador">Operador de Setor</label>
                                        <div class="small text-muted" style="font-size:0.75rem;">Visualiza apenas denúncias de um setor específico.</div>
                                    </div>
                                </div>

                                <div class="mb-4" id="divSetor" style="display:none;">
                                    <label class="form-label small fw-bold text-primary">Vincular a qual setor?</label>
                                    <select name="setor_id" class="form-select border-primary">
                                        <option value="">Selecione...</option>
                                        <?php foreach($setores as $s): ?>
                                            <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['nome']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <button type="submit" class="btn btn-primary w-100 fw-bold">Cadastrar Usuário</button>
                            </form>
                        </div>
                    </div>

                    <div class="col-md-8">
                        <div class="card card-custom bg-white p-4">
                            <h6 class="fw-bold text-dark mb-3">Equipe Cadastrada (<?php echo count($equipe); ?>)</h6>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Nome</th>
                                            <th>E-mail</th>
                                            <th>Acesso</th>
                                            <th>Ação</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($equipe as $user): ?>
                                        <tr>
                                            <td class="fw-semibold text-dark"><?php echo htmlspecialchars($user['nome']); ?></td>
                                            <td class="text-muted small"><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td>
                                                <?php if($user['nivel_acesso'] == 'admin'): ?>
                                                    <span class="badge bg-dark"><i class="bi bi-star-fill text-warning"></i> Admin Total</span>
                                                <?php else: ?>
                                                    <span class="badge bg-primary"><i class="bi bi-tag-fill"></i> Setor: <?php echo htmlspecialchars($user['nome_setor'] ?? 'Indefinido'); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if($user['id'] != $user_logado_id): ?>
                                                    <a href="admin_usuarios.php?delete=<?php echo $user['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Excluir acesso deste usuário?')"><i class="bi bi-trash"></i></a>
                                                <?php else: ?>
                                                    <span class="badge bg-light text-muted border">Você</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleSetor() {
            var isOperador = document.getElementById('roleOperador').checked;
            var divSetor = document.getElementById('divSetor');
            var selectSetor = divSetor.querySelector('select');
            
            if (isOperador) {
                divSetor.style.display = 'block';
                selectSetor.setAttribute('required', 'required');
            } else {
                divSetor.style.display = 'none';
                selectSetor.removeAttribute('required');
            }
        }
    </script>
</body>
</html>