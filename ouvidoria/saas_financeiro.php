<?php
// saas_financeiro.php
session_start();
require 'conexao.php';

// Verifica se é Super Admin
if (!isset($_SESSION['saas_admin_id'])) {
    header("Location: saas_login.php");
    exit;
}

$msg = '';

// --- LÓGICA: REGISTRAR PAGAMENTO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_pagamento'])) {
    $tenant_id = $_POST['tenant_id'];
    $valor = str_replace(',', '.', $_POST['valor']); // Troca vírgula por ponto
    $forma = $_POST['forma'];
    $obs = $_POST['obs'];

    try {
        $pdo->beginTransaction();

        // 1. Salva no Histórico
        $stmt = $pdo->prepare("INSERT INTO payments (tenant_id, valor, forma_pagamento, obs) VALUES (?, ?, ?, ?)");
        $stmt->execute([$tenant_id, $valor, $forma, $obs]);

        // 2. Renova a empresa por +30 dias (Regra de Negócio)
        // Se já venceu, renova a partir de hoje. Se não venceu, soma na data atual.
        $stmtRenova = $pdo->prepare("UPDATE tenants 
                                     SET data_expiracao = DATE_ADD(GREATEST(data_expiracao, NOW()), INTERVAL 30 DAY),
                                         ativo = 1 
                                     WHERE id = ?");
        $stmtRenova->execute([$tenant_id]);

        $pdo->commit();
        $msg = "Pagamento de R$ $valor registrado! Validade da empresa estendida.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $msg = "Erro ao registrar: " . $e->getMessage();
    }
}

// BUSCAR DADOS
// 1. Lista de Empresas (para o Select)
$empresas = $pdo->query("SELECT id, nome_empresa FROM tenants ORDER BY nome_empresa ASC")->fetchAll();

// 2. Histórico de Pagamentos (Últimos 20)
$sqlHist = "SELECT p.*, t.nome_empresa 
            FROM payments p 
            JOIN tenants t ON p.tenant_id = t.id 
            ORDER BY p.created_at DESC LIMIT 20";
$historico = $pdo->query($sqlHist)->fetchAll();

// 3. Faturamento Total do Mês Atual
$fatMes = $pdo->query("SELECT SUM(valor) FROM payments WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())")->fetchColumn();
$fatTotal = $pdo->query("SELECT SUM(valor) FROM payments")->fetchColumn();
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Financeiro | SaaS Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f0f2f5; }
        .sidebar { min-height: 100vh; background: #212529; color: white; width: 250px; position: fixed; }
        .content { margin-left: 250px; padding: 20px; }
        .card-money { border-left: 5px solid #198754; }
    </style>
</head>
<body>

<div class="sidebar p-3 d-none d-md-block">
    <h4 class="fw-bold mb-4 px-2">🚀 SaaS HQ</h4>
    <ul class="nav flex-column gap-2">
        <li class="nav-item"><a href="painel.php" class="nav-link text-white opacity-75">Dashboard</a></li>
        <li class="nav-item"><a href="#" class="nav-link text-white bg-primary rounded">Financeiro</a></li>
        <li class="nav-item"><a href="saas_config.php" class="nav-link text-white opacity-75">Configurações</a></li>
        <li class="nav-item mt-4"><a href="saas_logout.php" class="nav-link text-danger">Sair</a></li>
    </ul>
</div>

<div class="content">
    <h2 class="fw-bold text-secondary mb-4">Gestão Financeira</h2>

    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card card-money bg-white p-3 shadow-sm">
                <h6 class="text-muted text-uppercase">Faturamento (Mês Atual)</h6>
                <h2 class="fw-bold text-success">R$ <?php echo number_format($fatMes ?: 0, 2, ',', '.'); ?></h2>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card bg-white p-3 shadow-sm border-0">
                <h6 class="text-muted text-uppercase">Faturamento (Total)</h6>
                <h2 class="fw-bold text-dark">R$ <?php echo number_format($fatTotal ?: 0, 2, ',', '.'); ?></h2>
            </div>
        </div>
    </div>

    <?php if($msg): ?>
        <div class="alert alert-success"><?php echo $msg; ?></div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0 fw-bold"><i class="bi bi-cash-coin"></i> Registrar Entrada Manual</h5>
        </div>
        <div class="card-body">
            <form method="POST" class="row g-3">
                <input type="hidden" name="registrar_pagamento" value="1">
                
                <div class="col-md-4">
                    <label class="form-label fw-bold small">Cliente (Empresa)</label>
                    <select name="tenant_id" class="form-select" required>
                        <option value="">Selecione...</option>
                        <?php foreach($empresas as $e): ?>
                            <option value="<?php echo $e['id']; ?>"><?php echo htmlspecialchars($e['nome_empresa']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label fw-bold small">Valor (R$)</label>
                    <input type="text" name="valor" class="form-control" placeholder="97.00" required>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-bold small">Método</label>
                    <select name="forma" class="form-select">
                        <option value="Pix">Pix</option>
                        <option value="Cartão">Cartão de Crédito</option>
                        <option value="Boleto">Boleto</option>
                        <option value="Transferência">Transferência</option>
                    </select>
                </div>

                <div class="col-md-2 d-flex align-items-end">
                    <button class="btn btn-success w-100 fw-bold">Registrar</button>
                </div>
                
                <div class="col-12">
                    <input type="text" name="obs" class="form-control form-control-sm" placeholder="Observações (opcional)">
                    <small class="text-muted fst-italic">* Ao registrar, o sistema adicionará automaticamente 30 dias à validade do cliente.</small>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0 fw-bold">Últimos Lançamentos</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-4">Data</th>
                        <th>Cliente</th>
                        <th>Valor</th>
                        <th>Método</th>
                        <th>Obs</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($historico as $h): ?>
                    <tr>
                        <td class="ps-4 text-muted small">
                            <?php echo date('d/m/Y H:i', strtotime($h['created_at'])); ?>
                        </td>
                        <td class="fw-bold"><?php echo htmlspecialchars($h['nome_empresa']); ?></td>
                        <td class="text-success fw-bold">R$ <?php echo number_format($h['valor'], 2, ',', '.'); ?></td>
                        <td><span class="badge bg-light text-dark border"><?php echo $h['forma_pagamento']; ?></span></td>
                        <td class="small text-muted"><?php echo htmlspecialchars($h['obs']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

</body>
</html>