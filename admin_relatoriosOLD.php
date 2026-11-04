<?php
// --- CONFIGURAÇÕES INICIAIS ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'config.php';
require 'protecao.php';

// --- 0. SEGURANÇA SAAS ---
if (!defined('TENANT_ID')) {
    die("Erro Crítico: Tenant não identificado.");
}

// Verifica permissão
if (function_exists('tem_acesso') && !tem_acesso('relatorios') && !tem_acesso('master')) { 
    die("Acesso Negado."); 
}

// --- LÓGICA DE EXPORTAÇÃO (CSV) ---
if (isset($_GET['exportar_csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=contatos_gabinete_' . date('d-m-Y') . '.csv');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, ['Nome do Cidadão', 'WhatsApp', 'Data Nasc.', 'Bairro', 'Data Contato'], ';');

    try {
        $stmt = $pdo->prepare("SELECT nome_cidadao, whatsapp, nascimento, bairro, data_abertura FROM solicitacoes WHERE tenant_id = ? ORDER BY nome_cidadao ASC");
        $stmt->execute([TENANT_ID]);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $zap_limpo = preg_replace("/[^0-9]/", "", $row['whatsapp']);
            if(strlen($zap_limpo) <= 11 && strlen($zap_limpo) > 9) $zap_limpo = "55" . $zap_limpo;

            fputcsv($output, [
                $row['nome_cidadao'], 
                $zap_limpo, 
                $row['nascimento'] ? date('d/m/Y', strtotime($row['nascimento'])) : '-',
                $row['bairro'],
                date('d/m/Y', strtotime($row['data_abertura']))
            ], ';');
        }
    } catch (Exception $e) {
        fputcsv($output, ['Erro ao gerar dados: ' . $e->getMessage()], ';');
    }
    fclose($output);
    exit;
}

// --- CONSULTAS PARA OS GRÁFICOS ---
$res_bairros = [];
$res_sexo = [];
$res_tipo = [];
$res_status = [];
$res_idade = []; 

try {
    // 1. Bairros
    $sql_bairros = "SELECT bairro, COUNT(*) as total FROM solicitacoes WHERE tenant_id = ? AND bairro IS NOT NULL AND bairro != '' GROUP BY bairro ORDER BY total DESC LIMIT 10";
    $stmt_bairros = $pdo->prepare($sql_bairros);
    $stmt_bairros->execute([TENANT_ID]);
    $res_bairros = $stmt_bairros->fetchAll(PDO::FETCH_ASSOC);

    // 2. Sexo (CORRIGIDO: Trata NULL como "Não Informado")
    $sql_sexo = "
        SELECT 
            COALESCE(NULLIF(sexo, ''), 'Não Informado') as nome_sexo, 
            COUNT(*) as total 
        FROM solicitacoes 
        WHERE tenant_id = ? 
        GROUP BY nome_sexo
    ";
    $stmt_sexo = $pdo->prepare($sql_sexo);
    $stmt_sexo->execute([TENANT_ID]);
    $res_sexo = $stmt_sexo->fetchAll(PDO::FETCH_ASSOC);

    // 3. Tipo (Demandas)
    $sql_tipo = "SELECT tipo, COUNT(*) as total FROM solicitacoes WHERE tenant_id = ? GROUP BY tipo ORDER BY total DESC";
    $stmt_tipo = $pdo->prepare($sql_tipo);
    $stmt_tipo->execute([TENANT_ID]);
    $res_tipo = $stmt_tipo->fetchAll(PDO::FETCH_ASSOC);

    // 4. Status
    $sql_status = "SELECT status, COUNT(*) as total FROM solicitacoes WHERE tenant_id = ? GROUP BY status";
    $stmt_status = $pdo->prepare($sql_status);
    $stmt_status->execute([TENANT_ID]);
    $res_status = $stmt_status->fetchAll(PDO::FETCH_ASSOC);

    // 5. Faixa Etária
    $sql_idade = "SELECT 
        CASE 
            WHEN nascimento IS NULL THEN 'Não Informado'
            WHEN nascimento < '1900-01-01' THEN 'Não Informado'
            WHEN TIMESTAMPDIFF(YEAR, nascimento, CURDATE()) < 18 THEN 'Jovens (-18)'
            WHEN TIMESTAMPDIFF(YEAR, nascimento, CURDATE()) BETWEEN 18 AND 29 THEN '18-29 anos'
            WHEN TIMESTAMPDIFF(YEAR, nascimento, CURDATE()) BETWEEN 30 AND 44 THEN '30-44 anos'
            WHEN TIMESTAMPDIFF(YEAR, nascimento, CURDATE()) BETWEEN 45 AND 59 THEN '45-59 anos'
            WHEN TIMESTAMPDIFF(YEAR, nascimento, CURDATE()) >= 60 THEN 'Idosos (60+)'
            ELSE 'Não Informado'
        END as faixa,
        COUNT(*) as total
        FROM solicitacoes 
        WHERE tenant_id = ? 
        GROUP BY faixa
        ORDER BY faixa ASC";
    
    $stmt_idade = $pdo->prepare($sql_idade);
    $stmt_idade->execute([TENANT_ID]);
    $res_idade = $stmt_idade->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $erro_db = $e->getMessage();
}

// Prepara JSON para o JS
$json_bairros_labels = json_encode(array_column($res_bairros, 'bairro'));
$json_bairros_data = json_encode(array_column($res_bairros, 'total'));

// CORREÇÃO AQUI: Usar 'nome_sexo' que definimos no SQL
$json_sexo_labels = json_encode(array_column($res_sexo, 'nome_sexo'));
$json_sexo_data = json_encode(array_column($res_sexo, 'total'));

$json_tipo_labels = json_encode(array_column($res_tipo, 'tipo'));
$json_tipo_data = json_encode(array_column($res_tipo, 'total'));

$json_idade_labels = json_encode(array_column($res_idade, 'faixa'));
$json_idade_data = json_encode(array_column($res_idade, 'total'));
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Relatórios e Dados</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-light p-4">

<div class="container bg-white p-4 rounded shadow">
    <div class="d-flex justify-content-between align-items-center mb-5">
        <h3 class="text-primary fw-bold"><i class="fas fa-chart-pie me-2"></i>Inteligência de Dados</h3>
        <a href="admin_painel.php" class="btn btn-secondary btn-sm">Voltar ao Painel</a>
    </div>

    <?php if(isset($erro_db)): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i> <b>Atenção:</b> Erro ao processar dados. <br>
            <small><?= $erro_db ?></small>
        </div>
    <?php endif; ?>

    <div class="card bg-primary text-white mb-5 shadow-sm border-0">
        <div class="card-body p-4">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h4 class="fw-bold"><i class="fab fa-whatsapp me-2"></i>Base de Contatos (Leads)</h4>
                    <p class="mb-0 opacity-75">Exporte a lista de cidadãos e filtre por dados demográficos.</p>
                </div>
                <div class="col-md-4 text-end">
                    <a href="?exportar_csv=true" class="btn btn-light fw-bold text-primary me-2">
                        <i class="fas fa-file-csv"></i> Baixar CSV
                    </a>
                    <button type="button" class="btn btn-outline-light fw-bold" data-bs-toggle="modal" data-bs-target="#modalLista">
                        <i class="fas fa-copy"></i> Copiar Lista
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-8">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-header bg-white fw-bold">Atendimentos por Bairro (Top 10)</div>
                <div class="card-body"><canvas id="chartBairros"></canvas></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-header bg-white fw-bold">Perfil (Sexo)</div>
                <div class="card-body"><canvas id="chartSexo"></canvas></div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-header bg-white fw-bold">Faixa Etária (Idade)</div>
                <div class="card-body"><canvas id="chartIdade"></canvas></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-header bg-white fw-bold">Tipos de Demanda</div>
                <div class="card-body"><canvas id="chartTipo"></canvas></div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white fw-bold">Resumo Geral de Status</div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0 text-center">
                        <thead class="table-light"><tr><th>Status Atual</th><th>Quantidade</th><th>Percentual</th></tr></thead>
                        <tbody>
                            <?php 
                            $total_geral = 0;
                            foreach($res_status as $r) $total_geral += $r['total'];

                            if(empty($res_status)): ?>
                                <tr><td colspan="3" class="text-muted py-3">Sem dados registrados.</td></tr>
                            <?php else:
                                foreach($res_status as $row): 
                                    $bg = match($row['status']) { 'Concluído'=>'success', 'Pendente'=>'warning', 'Arquivado'=>'secondary', default=>'primary' };
                                    $pct = $total_geral > 0 ? round(($row['total'] / $total_geral) * 100, 1) : 0;
                            ?>
                            <tr>
                                <td><span class="badge bg-<?= $bg ?> rounded-pill px-3"><?= $row['status'] ?></span></td>
                                <td class="fw-bold"><?= $row['total'] ?></td>
                                <td class="text-muted small"><?= $pct ?>%</td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <tfoot class="table-dark">
                            <tr>
                                <td>TOTAL GERAL</td>
                                <td colspan="2"><?= $total_geral ?> atendimentos</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

<div class="modal fade" id="modalLista" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Lista Rápida de Telefones</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <textarea class="form-control" rows="10" readonly><?php
                    try {
                        $stmt_lista = $pdo->prepare("SELECT whatsapp, nome_cidadao FROM solicitacoes WHERE tenant_id = ?");
                        $stmt_lista->execute([TENANT_ID]);
                        while($c = $stmt_lista->fetch(PDO::FETCH_ASSOC)) {
                            $z = preg_replace("/[^0-9]/", "", $c['whatsapp']);
                            if(strlen($z) >= 10) {
                                if(strlen($z) <= 11) $z = "55" . $z;
                                echo "$z," . $c['nome_cidadao'] . "\n";
                            }
                        }
                    } catch (Exception $e) { echo "Erro ao listar."; }
                ?></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="navigator.clipboard.writeText(document.querySelector('textarea').value); alert('Copiado!');">
                    <i class="fas fa-copy"></i> Copiar Tudo
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    // Dados vindos do PHP
    const bairrosLabels = <?= $json_bairros_labels ?: '[]' ?>;
    const bairrosData = <?= $json_bairros_data ?: '[]' ?>;
    const sexoLabels = <?= $json_sexo_labels ?: '[]' ?>;
    const sexoData = <?= $json_sexo_data ?: '[]' ?>;
    const tipoLabels = <?= $json_tipo_labels ?: '[]' ?>;
    const tipoData = <?= $json_tipo_data ?: '[]' ?>;
    const idadeLabels = <?= $json_idade_labels ?: '[]' ?>;
    const idadeData = <?= $json_idade_data ?: '[]' ?>;

    // 1. Gráfico Bairros
    new Chart(document.getElementById('chartBairros'), {
        type: 'bar',
        data: { labels: bairrosLabels, datasets: [{ label: 'Chamados', data: bairrosData, backgroundColor: '#0d6efd', borderRadius: 4 }] },
        options: { responsive: true, plugins: { legend: {display: false} }, scales: { y: { beginAtZero: true } } }
    });

    // 2. Gráfico Sexo (CORRIGIDO CORES)
    new Chart(document.getElementById('chartSexo'), {
        type: 'doughnut',
        data: { 
            labels: sexoLabels, 
            datasets: [{ 
                data: sexoData, 
                backgroundColor: function(context) {
                    // Tenta colorir dinamicamente
                    const label = context.chart.data.labels[context.dataIndex];
                    if(label === 'Masculino' || label === 'M') return '#36b9cc';
                    if(label === 'Feminino' || label === 'F') return '#e74a3b';
                    return '#858796'; // Cinza para Não Informado
                }
            }] 
        }
    });

    // 3. Gráfico Tipo
    new Chart(document.getElementById('chartTipo'), {
        type: 'radar',
        data: { labels: tipoLabels, datasets: [{ label: 'Volume', data: tipoData, backgroundColor: 'rgba(255, 193, 7, 0.5)', borderColor: '#ffc107', pointBackgroundColor: '#ffc107', fill: true }] },
        options: { scales: { r: { suggestMin: 0 } } }
    });

    // 4. Gráfico Idade
    new Chart(document.getElementById('chartIdade'), {
        type: 'bar', 
        data: { 
            labels: idadeLabels, 
            datasets: [{ 
                label: 'Pessoas', 
                data: idadeData, 
                backgroundColor: '#198754', 
                borderRadius: 4
            }] 
        },
        options: { 
            indexAxis: 'y', 
            responsive: true, 
            plugins: { legend: {display: false} } 
        }
    });
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>