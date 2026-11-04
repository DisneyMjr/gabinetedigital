<?php
// admin_cac.php
// Calculadora de Custo por Voto (CAC Político)
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require 'config.php';
require 'protecao.php';

if (!defined('TENANT_ID')) die("Acesso Restrito.");

$msg = '';

// SALVAR INVESTIMENTO NA LIDERANÇA
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'salvar_investimento') {
    try {
        $lider_id = (int)$_POST['lider_id'];
        // Converte formato brasileiro (10.000,50) para SQL (10000.50)
        $valor_cru = str_replace('.', '', $_POST['valor_investido']);
        $valor_sql = str_replace(',', '.', $valor_cru);
        
        $stmt = $pdo->prepare("UPDATE eleitores SET investimento_campanha = ? WHERE id = ? AND tenant_id = ? AND is_lider = 1");
        $stmt->execute([$valor_sql, $lider_id, TENANT_ID]);
        
        $msg = "<div class='alert alert-success shadow-sm fw-bold'><i class='fas fa-check-circle'></i> Investimento atualizado com sucesso!</div>";
    } catch (Exception $e) {
        $msg = "<div class='alert alert-danger'>Erro: " . $e->getMessage() . "</div>";
    }
}

// BUSCAR DADOS DAS LIDERANÇAS E CÁLCULOS
$stmt = $pdo->prepare("
    SELECT id, nome, investimento_campanha as investimento,
           (SELECT COUNT(*) FROM eleitores sub WHERE sub.responsavel_id = e.id AND sub.tenant_id = ?) as total_votos
    FROM eleitores e
    WHERE e.tenant_id = ? AND e.is_lider = 1
    ORDER BY total_votos DESC, nome ASC
");
$stmt->execute([TENANT_ID, TENANT_ID]);
$liderancas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// MÉTRICAS GERAIS
$investimento_total = 0;
$votos_totais_liderancas = 0;

foreach ($liderancas as $l) {
    $investimento_total += (float)$l['investimento'];
    $votos_totais_liderancas += (int)$l['total_votos'];
}

$cac_geral = ($votos_totais_liderancas > 0) ? ($investimento_total / $votos_totais_liderancas) : 0;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Custo por Voto (CAC)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f3f4f6; font-family: 'Inter', sans-serif; }
        .card-stat { border-left: 5px solid; border-radius: 15px; background: white; transition: 0.3s; }
        .card-stat:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.05); }
        .cac-excelente { color: #198754; background: #d1e7dd; }
        .cac-alerta { color: #ffc107; background: #fff3cd; }
        .cac-critico { color: #dc3545; background: #f8d7da; }
        .table-liderancas th { background-color: #f8f9fa; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.5px; }
    </style>
</head>
<body class="p-4">

<div class="container bg-white p-4 rounded-4 shadow-sm" style="max-width: 1200px;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="text-primary fw-bold mb-0"><i class="fas fa-bullseye me-2"></i> Custo por Voto (CAC Político)</h3>
            <small class="text-muted">Acompanhe o retorno sobre o investimento de cada Liderança / Dobrada.</small>
        </div>
        <a href="admin_painel.php" class="btn btn-outline-secondary rounded-pill fw-bold px-4">Voltar</a>
    </div>

    <?= $msg ?>

    <!-- PAINEL DE MÉTRICAS -->
    <div class="row g-4 mb-5">
        <div class="col-md-4">
            <div class="card card-stat shadow-sm p-4 h-100" style="border-color: #0d6efd;">
                <h6 class="text-muted fw-bold text-uppercase mb-1">Total Investido</h6>
                <h2 class="fw-bold text-primary mb-0">R$ <?= number_format($investimento_total, 2, ',', '.') ?></h2>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-stat shadow-sm p-4 h-100" style="border-color: #198754;">
                <h6 class="text-muted fw-bold text-uppercase mb-1">Votos Via Lideranças</h6>
                <h2 class="fw-bold text-success mb-0"><?= $votos_totais_liderancas ?> <i class="fas fa-users small"></i></h2>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-stat shadow-sm p-4 h-100" style="border-color: #dc3545;">
                <h6 class="text-muted fw-bold text-uppercase mb-1">Custo Médio por Voto (Geral)</h6>
                <h2 class="fw-bold text-danger mb-0">R$ <?= number_format($cac_geral, 2, ',', '.') ?></h2>
            </div>
        </div>
    </div>

    <!-- LISTAGEM DAS LIDERANÇAS -->
    <h5 class="fw-bold text-dark border-bottom pb-2 mb-3">Desempenho por Multiplicador</h5>
    
    <div class="table-responsive">
        <table class="table table-hover align-middle table-liderancas border">
            <thead>
                <tr>
                    <th>Nome da Liderança</th>
                    <th class="text-center">Votos Captados</th>
                    <th class="text-end">Investimento (R$)</th>
                    <th class="text-end">CAC (Custo do Voto)</th>
                    <th class="text-end">Ação</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($liderancas as $l): 
                    $invest = (float)$l['investimento'];
                    $votos = (int)$l['total_votos'];
                    $cac = ($votos > 0) ? ($invest / $votos) : $invest; // Se não tem voto, o CAC é o investimento inteiro perdido
                    
                    // Lógica de Cores do CAC
                    if($cac == 0) { $classe_cac = "bg-light text-muted"; }
                    elseif($cac <= 50) { $classe_cac = "cac-excelente"; } // Voto barato
                    elseif($cac <= 100) { $classe_cac = "cac-alerta"; } // Voto médio
                    else { $classe_cac = "cac-critico"; } // Voto caro
                ?>
                <tr>
                    <td><strong class="text-dark"><i class="fas fa-user-tie text-muted me-2"></i> <?= htmlspecialchars($l['nome']) ?></strong></td>
                    <td class="text-center"><span class="badge bg-primary rounded-pill fs-6"><?= $votos ?></span></td>
                    <td class="text-end fw-bold text-secondary">R$ <?= number_format($invest, 2, ',', '.') ?></td>
                    <td class="text-end">
                        <span class="badge px-3 py-2 rounded-pill <?= $classe_cac ?>" style="font-size: 0.9rem;">
                            R$ <?= number_format($cac, 2, ',', '.') ?>
                        </span>
                    </td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-dark fw-bold rounded-pill" onclick="lancarValor(<?= $l['id'] ?>, '<?= addslashes($l['nome']) ?>', '<?= number_format($invest, 2, ',', '') ?>')">
                            <i class="fas fa-edit me-1"></i> Lançar Valor
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if(empty($liderancas)): ?>
                <tr>
                    <td colspan="5" class="text-center py-5 text-muted">Nenhuma liderança cadastrada. Vá em "Novo Eleitor" e marque a opção "É Liderança".</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL LANÇAR VALOR -->
<div class="modal fade" id="modalInvestimento" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <form method="POST" class="modal-content shadow border-0">
            <div class="modal-header bg-dark text-white">
                <h6 class="modal-title fw-bold"><i class="fas fa-money-bill-wave me-2"></i> Lançar Investimento</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center p-4">
                <input type="hidden" name="acao" value="salvar_investimento">
                <input type="hidden" name="lider_id" id="lider_id">
                
                <p class="small text-muted mb-3">Valor financeiro investido na dobrada com <br><strong id="lider_nome" class="text-dark"></strong>:</p>
                
                <div class="input-group input-group-lg mb-2">
                    <span class="input-group-text bg-light fw-bold">R$</span>
                    <input type="text" name="valor_investido" id="valor_investido" class="form-control fw-bold text-end text-primary" required>
                </div>
            </div>
            <div class="modal-footer bg-light border-0 justify-content-center">
                <button type="submit" class="btn btn-success fw-bold w-100 rounded-pill"><i class="fas fa-save me-1"></i> Salvar</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js"></script>

<script>
    $(document).ready(function(){
        // Máscara de dinheiro padrão brasileiro
        $('#valor_investido').mask('000.000.000.000.000,00', {reverse: true});
    });

    function lancarValor(id, nome, valorAtual) {
        $('#lider_id').val(id);
        $('#lider_nome').text(nome);
        $('#valor_investido').val(valorAtual).trigger('input'); // Trigger para aplicar a máscara
        new bootstrap.Modal(document.getElementById('modalInvestimento')).show();
    }
</script>

</body>
</html>