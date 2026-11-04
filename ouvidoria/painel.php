<?php
session_start();
require 'conexao.php';

// 1. SEGURANÇA: Verifica se é Super Admin
if (!isset($_SESSION['saas_admin_id'])) {
    header("Location: saas_login.php");
    exit;
}

$msg = '';

// 2. LÓGICA: Adicionar Nova Empresa (SEM PLANO, APENAS CADASTRO)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nova_empresa'])) {
    $nome = trim($_POST['nome']);
    
    if (!empty($nome)) {
        // Gerar slug (ex: "Padaria do João" -> "padaria-do-joao-a1b2")
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $nome)));
        $slug .= '-' . substr(md5(uniqid()), 0, 4);

        try {
            $pdo->beginTransaction();

            // Insere a empresa com 3 dias de teste (removido a coluna 'plano')
            $sql = "INSERT INTO tenants (nome_empresa, slug, ativo, data_expiracao) 
                    VALUES (?, ?, 1, DATE_ADD(NOW(), INTERVAL 3 DAY))";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$nome, $slug]);
            
            $tenant_id = $pdo->lastInsertId();
            
            // Cria um Admin Padrão Automático
            $email_temp = "admin@" . preg_replace('/[^a-z0-9]/', '', substr($slug, 0, 10)) . ".com";
            $senha_padrao = password_hash('123456', PASSWORD_DEFAULT);
            
            $sqlUser = "INSERT INTO users (tenant_id, nome, email, senha_hash, perfil) 
                        VALUES (?, 'Admin Inicial', ?, ?, 'admin')";
            $stmtUser = $pdo->prepare($sqlUser);
            $stmtUser->execute([$tenant_id, $email_temp, $senha_padrao]);

            $pdo->commit();
            $msg = "<div class='alert alert-success'>
                        <strong>Empresa Criada!</strong><br>
                        Login: <b>$email_temp</b><br>
                        Senha: <b>123456</b>
                    </div>";

        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = "<div class='alert alert-danger'>Erro ao criar: " . $e->getMessage() . "</div>";
        }
    }
}

// 3. LÓGICA: Bloquear/Ativar Empresa
if (isset($_GET['acao']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $ativo = ($_GET['acao'] == 'bloquear') ? 0 : 1;
    
    $stmt = $pdo->prepare("UPDATE tenants SET ativo = ? WHERE id = ?");
    $stmt->execute([$ativo, $id]);
    
    // REDIRECIONAMENTO SEGURO (Mantém a porta 8080)
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// 4. ESTATÍSTICAS
$total_empresas = $pdo->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
$total_relatos = $pdo->query("SELECT COUNT(*) FROM reports")->fetchColumn();
$empresas_ativas = $pdo->query("SELECT COUNT(*) FROM tenants WHERE ativo = 1")->fetchColumn();

// Busca valor da mensalidade para exibir (informativo)
$valor_mensalidade = $pdo->query("SELECT valor FROM system_config WHERE chave = 'valor_mensalidade'")->fetchColumn();
if(!$valor_mensalidade) $valor_mensalidade = '97.00';

// 5. LISTAGEM DE CLIENTES
// Traz também o total pago por cada empresa (Lifetime Value)
$sqlLista = "SELECT t.*, 
            (SELECT COUNT(*) FROM reports WHERE reports.tenant_id = t.id) as total_relatos,
            (SELECT SUM(valor) FROM payments WHERE payments.tenant_id = t.id) as total_pago
            FROM tenants t 
            ORDER BY t.id DESC";
$empresas = $pdo->query($sqlLista)->fetchAll();
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Painel SaaS | Master</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        .card-stat { border: none; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); transition: 0.3s; }
        .card-stat:hover { transform: translateY(-3px); }
        .sidebar { min-height: 100vh; background: #212529; color: white; width: 250px; position: fixed; }
        .content { margin-left: 250px; padding: 30px; }
        @media (max-width: 768px) {
            .sidebar { width: 100%; position: relative; min-height: auto; }
            .content { margin-left: 0; }
        }
    </style>
</head>
<body>

<div class="sidebar p-3">
    <h4 class="fw-bold mb-4 px-2 text-warning"><i class="bi bi-rocket-takeoff"></i> OUVIDORIA PRO</h4>
    <ul class="nav flex-column gap-2">
        <li class="nav-item"><a href="saas_painel.php" class="nav-link text-white bg-primary rounded fw-bold"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
        <li class="nav-item"><a href="saas_financeiro.php" class="nav-link text-white opacity-75"><i class="bi bi-cash-stack"></i> Financeiro</a></li>
        <li class="nav-item"><a href="saas_config.php" class="nav-link text-white opacity-75"><i class="bi bi-gear"></i> Configurações</a></li>
        <li class="nav-item mt-5 border-top pt-3"><a href="saas_logout.php" class="nav-link text-danger"><i class="bi bi-box-arrow-left"></i> Sair</a></li>
    </ul>
    
    <div class="mt-auto p-3 text-center text-secondary small">
        <small>Mensalidade Atual:<br><strong class="text-white">R$ <?php echo number_format($valor_mensalidade, 2, ',', '.'); ?></strong></small>
    </div>
</div>

<div class="content">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold text-secondary">Visão Geral</h2>
        <span class="badge bg-dark p-2">Admin Logado</span>
    </div>

    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card card-stat bg-white p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted text-uppercase mb-1">Total Clientes</h6>
                        <h3 class="fw-bold text-dark mb-0"><?php echo $total_empresas; ?></h3>
                    </div>
                    <div class="bg-light rounded-circle p-3 text-primary">
                        <i class="bi bi-buildings fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-stat bg-white p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted text-uppercase mb-1">Empresas Ativas</h6>
                        <h3 class="fw-bold text-success mb-0"><?php echo $empresas_ativas; ?></h3>
                    </div>
                    <div class="bg-light rounded-circle p-3 text-success">
                        <i class="bi bi-check-circle fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-stat bg-white p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted text-uppercase mb-1">Total Relatos</h6>
                        <h3 class="fw-bold text-info mb-0"><?php echo $total_relatos; ?></h3>
                    </div>
                    <div class="bg-light rounded-circle p-3 text-info">
                        <i class="bi bi-chat-right-text fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php echo $msg; ?>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3 border-bottom-0">
            <h5 class="mb-0 fw-bold text-primary"><i class="bi bi-plus-circle-dotted"></i> Cadastrar Nova Empresa</h5>
        </div>
        <div class="card-body bg-white rounded-bottom">
            <form method="POST" class="row g-3 align-items-end">
                <input type="hidden" name="nova_empresa" value="1">
                <div class="col-md-9">
                    <label class="form-label fw-bold small text-secondary">NOME DA EMPRESA</label>
                    <input type="text" name="nome" class="form-control form-control-lg" placeholder="Ex: Farmácia Saúde Total" required>
                </div>
                <div class="col-md-3">
                    <button class="btn btn-primary btn-lg w-100 fw-bold">
                        <i class="bi bi-save"></i> Cadastrar
                    </button>
                </div>
            </form>
            <div class="mt-2 text-muted small fst-italic">
                * Ao cadastrar, um usuário Admin e senha (123456) serão gerados automaticamente.
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold">Carteira de Clientes</h5>
            <span class="badge bg-light text-dark border"><?php echo count($empresas); ?> Registros</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-4">Empresa / Detalhes</th>
                        <th>Status</th>
                        <th>Total Pago (LTV)</th>
                        <th>Uso</th>
                        <th>Validade</th>
                        <th>Ações Rápidas</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($empresas as $emp): 
                        $validade = new DateTime($emp['data_expiracao']);
                        $hoje = new DateTime();
                        $vencido = $hoje > $validade;
                    ?>
                    <tr>
                        <td class="ps-4">
                            <div class="fw-bold text-dark fs-5"><?php echo htmlspecialchars($emp['nome_empresa']); ?></div>
                            <div class="small text-muted font-monospace">ID: <?php echo $emp['id']; ?></div>
                        </td>
                        <td>
                            <?php if(!$emp['ativo']): ?>
                                <span class="badge bg-danger rounded-pill px-3">BLOQUEADO</span>
                            <?php elseif($vencido): ?>
                                <span class="badge bg-warning text-dark rounded-pill px-3">VENCIDO</span>
                            <?php else: ?>
                                <span class="badge bg-success rounded-pill px-3">ATIVO</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="fw-bold text-success">
                                R$ <?php echo number_format($emp['total_pago'] ?: 0, 2, ',', '.'); ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge bg-light text-dark border">
                                <?php echo $emp['total_relatos']; ?> relatos
                            </span>
                        </td>
                        <td>
                            <?php if($vencido): ?>
                                <span class="text-danger fw-bold"><i class="bi bi-calendar-x"></i> <?php echo date('d/m/Y', strtotime($emp['data_expiracao'])); ?></span>
                            <?php else: ?>
                                <span class="text-muted"><i class="bi bi-calendar-check"></i> <?php echo date('d/m/Y', strtotime($emp['data_expiracao'])); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="btn-group">
                                <a href="index.php?empresa=<?php echo $emp['slug']; ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="Ver Ouvidoria">
                                    <i class="bi bi-box-arrow-up-right"></i>
                                </a>
                                
                                <?php if($emp['ativo']): ?>
                                    <a href="?acao=bloquear&id=<?php echo $emp['id']; ?>" class="btn btn-sm btn-outline-danger" title="Bloquear Acesso" onclick="return confirm('Deseja BLOQUEAR o acesso desta empresa? Ninguém conseguirá logar.')">
                                        <i class="bi bi-slash-circle"></i>
                                    </a>
                                <?php else: ?>
                                    <a href="?acao=ativar&id=<?php echo $emp['id']; ?>" class="btn btn-sm btn-outline-success" title="Reativar Acesso">
                                        <i class="bi bi-check-lg"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>