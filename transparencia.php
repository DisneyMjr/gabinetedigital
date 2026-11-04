<?php
require 'config.php';   // 1º O Cérebro (Define quem é o cliente)
require 'protecao.php'; // 2º O Porteiro (Verifica se pode entrar

// --- 0. SEGURANÇA SAAS ---
if (!defined('TENANT_ID')) {
    die("Erro: Gabinete não identificado.");
}

// --- LÓGICA DE FILTRO POR ANO ---
$ano_selecionado = $_GET['ano'] ?? date('Y');

// 1. Busca os anos disponíveis (COM FILTRO DE TENANT)
// Antes: SELECT DISTINCT YEAR(data_despesa)...
// Agora: ... WHERE tenant_id = ? ...
$sql_anos = "SELECT DISTINCT YEAR(data_despesa) as ano FROM transparencia WHERE tenant_id = ? ORDER BY ano DESC";
$stmt_anos = $pdo->prepare($sql_anos);
$stmt_anos->execute([TENANT_ID]);
$lista_anos = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

// 2. Busca as despesas do ano selecionado (COM FILTRO DE TENANT)
// Antes: WHERE YEAR(data_despesa) = ?
// Agora: WHERE YEAR(data_despesa) = ? AND tenant_id = ?
$sql = "SELECT * FROM transparencia 
        WHERE YEAR(data_despesa) = ? AND tenant_id = ?
        ORDER BY data_despesa DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$ano_selecionado, TENANT_ID]);
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Portal da Transparência</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .bg-hero { background: linear-gradient(135deg, #2c3e50, #4ca1af); color: white; padding: 60px 0; }
    </style>
</head>
<body>

    <header class="bg-hero text-center mb-5">
        <div class="container">
            <h1 class="fw-bold"><i class="fas fa-file-invoice-dollar"></i> Prestação de Contas</h1>
            <p class="lead">Transparência total com o dinheiro público.</p>
            <a href="index.php" class="btn btn-outline-light btn-sm mt-3">Voltar ao Site</a>
        </div>
    </header>

    <div class="container mb-5">
        
        <div class="d-flex justify-content-center mb-4">
            <div class="btn-group shadow">
                <?php foreach($lista_anos as $a): ?>
                    <a href="?ano=<?= $a['ano'] ?>" class="btn btn-<?= $a['ano'] == $ano_selecionado ? 'primary' : 'light' ?>">
                        <?= $a['ano'] ?>
                    </a>
                <?php endforeach; ?>
                
                <?php if(empty($lista_anos)): ?>
                    <button class="btn btn-primary" disabled><?= date('Y') ?></button>
                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow border-0">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Data</th>
                                <th>Descrição / Título</th>
                                <th>Categoria</th>
                                <th>Valor (R$)</th>
                                <th class="text-end pe-4">Comprovante</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($row = $stmt->fetch(PDO::FETCH_ASSOC)): ?>
                            <tr>
                                <td class="ps-4 text-nowrap">
                                    <i class="far fa-calendar-alt text-muted"></i> 
                                    <?= date('d/m/Y', strtotime($row['data_despesa'])) ?>
                                </td>
                                
                                <td>
                                    <strong class="text-dark d-block"><?= $row['titulo'] ?></strong>
                                    <?php if($row['descricao']): ?>
                                        <small class="text-muted"><?= $row['descricao'] ?></small>
                                    <?php endif; ?>
                                </td>
                                
                                <td>
                                    <span class="badge bg-secondary"><?= $row['categoria'] ?></span>
                                </td>
                                
                                <td class="fw-bold text-dark">
                                    R$ <?= number_format($row['valor'], 2, ',', '.') ?>
                                </td>
                                
                                <td class="text-end pe-4">
                                    <?php if($row['arquivo']): ?>
                                        <a href="<?= $row['arquivo'] ?>" target="_blank" class="btn btn-sm btn-outline-danger">
                                            <i class="fas fa-file-pdf"></i> Baixar
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted small">Indisponível</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>

                            <?php if($stmt->rowCount() == 0): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-5 text-muted">
                                        <i class="fas fa-search-dollar fa-3x mb-3"></i><br>
                                        Nenhuma despesa lançada neste ano ainda.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php 
            // 3. Calcula o total do ano selecionado (COM FILTRO DE TENANT)
            $stmt_total = $pdo->prepare("SELECT SUM(valor) FROM transparencia WHERE YEAR(data_despesa) = ? AND tenant_id = ?");
            $stmt_total->execute([$ano_selecionado, TENANT_ID]);
            $total = $stmt_total->fetchColumn();
        ?>
        <div class="alert alert-info mt-4 text-center fw-bold shadow-sm">
            Total em <?= $ano_selecionado ?>: R$ <?= number_format($total, 2, ',', '.') ?>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>