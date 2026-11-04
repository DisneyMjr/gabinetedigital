<?php
// admin_relatorios_crm.php
// Dashboard de Inteligência e Gráficos do CRM

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require 'config.php';
require 'protecao.php';

if (!defined('TENANT_ID')) die("Acesso Restrito.");

// Verifica permissão genérica
if (function_exists('tem_acesso') && !tem_acesso('relatorios') && !tem_acesso('master')) {
    die("Acesso Negado.");
}

// =========================================================================
// 1. CONSULTAS DE INTELIGÊNCIA DE DADOS (BLINDADAS)
// =========================================================================

$total_eleitores = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM eleitores WHERE tenant_id = ?");
    $stmt->execute([TENANT_ID]);
    $total_eleitores = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// B. ESPECTRO POLÍTICO (Ideologia)
$lbl_espectro = []; $val_espectro = [];
try {
    $stmt = $pdo->prepare("SELECT espectro_politico, COUNT(*) as qtd FROM eleitores WHERE tenant_id = ? GROUP BY espectro_politico ORDER BY qtd DESC");
    $stmt->execute([TENANT_ID]);
    $dados_espectro = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach($dados_espectro as $d) {
        $lbl_espectro[] = empty($d['espectro_politico']) ? 'Não Informado' : $d['espectro_politico'];
        $val_espectro[] = $d['qtd'];
    }
} catch (Exception $e) {}

// C. GÊNERO (Sexo)
$lbl_sexo = []; $val_sexo = [];
try {
    $stmt = $pdo->prepare("SELECT sexo, COUNT(*) as qtd FROM eleitores WHERE tenant_id = ? GROUP BY sexo ORDER BY qtd DESC");
    $stmt->execute([TENANT_ID]);
    $dados_sexo = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach($dados_sexo as $d) {
        $lbl_sexo[] = empty($d['sexo']) ? 'Não Informado' : $d['sexo'];
        $val_sexo[] = $d['qtd'];
    }
} catch (Exception $e) {}

// D. TERMÔMETRO DE VOTO (Status)
$lbl_status = ['Indeciso/Frio', 'Apoio Provável', 'Voto Certo']; 
$val_status = [0, 0, 0];
try {
    $stmt = $pdo->prepare("SELECT status_voto, COUNT(*) as qtd FROM eleitores WHERE tenant_id = ? GROUP BY status_voto");
    $stmt->execute([TENANT_ID]);
    $dados_status = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach($dados_status as $d) {
        if ($d['status_voto'] == 1) $val_status[0] = $d['qtd'];
        if ($d['status_voto'] == 2) $val_status[1] = $d['qtd'];
        if ($d['status_voto'] == 3) $val_status[2] = $d['qtd'];
    }
} catch (Exception $e) {}

// E. TOP 10 BAIRROS
$lbl_bairros = []; $val_bairros = [];
try {
    $stmt = $pdo->prepare("SELECT bairro, COUNT(*) as qtd FROM eleitores WHERE tenant_id = ? AND bairro IS NOT NULL AND trim(bairro) != '' GROUP BY bairro ORDER BY qtd DESC LIMIT 10");
    $stmt->execute([TENANT_ID]);
    $dados_bairros = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach($dados_bairros as $d) {
        $lbl_bairros[] = mb_strimwidth($d['bairro'], 0, 20, "...");
        $val_bairros[] = $d['qtd'];
    }
} catch (Exception $e) {}

// F. FAIXA ETÁRIA (Corrigido para evitar erro de Strict Mode do MySQL)
$lbl_idade = ['16 a 24 anos', '25 a 34 anos', '35 a 44 anos', '45 a 59 anos', '60+ anos'];
$val_idade = [0, 0, 0, 0, 0];
try {
    // Apenas busca as datas não-nulas. Verificações de datas inválidas passam pro PHP
    $stmt = $pdo->prepare("SELECT nascimento FROM eleitores WHERE tenant_id = ? AND nascimento IS NOT NULL");
    $stmt->execute([TENANT_ID]);
    $rows_idade = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $hoje = date_create('today');
    
    foreach($rows_idade as $r) {
        $nasc_str = trim($r['nascimento']);
        
        // Ignora campos vazios ou datas zeradas (0000-00-00)
        if (empty($nasc_str) || strpos($nasc_str, '0000') !== false) {
            continue;
        }
        
        // Tradutor: Se a data estiver no padrão brasileiro (DD/MM/AAAA), converte para SQL (AAAA-MM-DD)
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $nasc_str, $matches)) {
            $nasc_str = $matches[3] . '-' . sprintf("%02d", $matches[2]) . '-' . sprintf("%02d", $matches[1]);
        }
        
        $data_nasc = @date_create($nasc_str);
        
        if($data_nasc) {
            $idade = date_diff($data_nasc, $hoje)->y;
            
            // Distribui nas faixas etárias
            if ($idade >= 16 && $idade <= 24) $val_idade[0]++;
            elseif ($idade >= 25 && $idade <= 34) $val_idade[1]++;
            elseif ($idade >= 35 && $idade <= 44) $val_idade[2]++;
            elseif ($idade >= 45 && $idade <= 59) $val_idade[3]++;
            elseif ($idade >= 60) $val_idade[4]++;
        }
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Relatórios e Inteligência</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    
    <style>
        body { background-color: #f3f4f6; font-family: 'Inter', sans-serif; }
        .chart-container { position: relative; height: 300px; width: 100%; }
        .chart-container-large { position: relative; height: 400px; width: 100%; }
        
        /* Ajustes para impressão limpa */
        @media print {
            body { background-color: white !important; }
            .no-print { display: none !important; }
            .card { border: 1px solid #ddd !important; box-shadow: none !important; page-break-inside: avoid; margin-bottom: 20px !important;}
            .col-md-6 { width: 50% !important; float: left !important; }
            .col-md-12 { width: 100% !important; clear: both !important;}
            .chart-container { height: 250px !important; }
        }
    </style>
</head>
<body class="p-4">

<div class="container bg-white p-4 rounded-4 shadow-sm" style="max-width: 1200px;">
    
    <div class="d-flex justify-content-between align-items-center mb-4 no-print">
        <div>
            <h3 class="text-primary fw-bold mb-0"><i class="fas fa-chart-pie me-2"></i> Relatório de Inteligência</h3>
            <small class="text-muted">Métricas, Perfil e Desempenho da Base Eleitoral.</small>
        </div>
        <div class="d-flex gap-2">
            <a href="admin_duplicidades_eleitores.php" class="btn btn-outline-primary rounded-pill fw-bold px-3">Duplicidades bloqueadas</a>
            <a href="admin_eleitores.php" class="btn btn-outline-secondary rounded-pill fw-bold px-3"><i class="fas fa-arrow-left me-1"></i> Voltar</a>
            <button onclick="window.print()" class="btn btn-danger rounded-pill fw-bold px-4 shadow-sm"><i class="fas fa-file-pdf me-2"></i> Salvar PDF</button>
        </div>
    </div>

    <!-- Cabeçalho visível apenas na impressão -->
    <div class="d-none d-print-block text-center mb-4 pb-3 border-bottom">
        <h2 class="fw-bold m-0">Relatório Estratégico da Base</h2>
        <p class="text-muted m-0">Análise de Perfil do Eleitorado - Gerado em: <?= date('d/m/Y H:i') ?></p>
        <h4 class="mt-2 fw-bold text-dark">Total de Contatos na Base: <?= number_format($total_eleitores, 0, ',', '.') ?></h4>
    </div>

    <?php if($total_eleitores == 0): ?>
        <div class="text-center py-5">
            <i class="fas fa-chart-bar fa-3x text-muted mb-3 opacity-50"></i>
            <h5 class="text-muted">Ainda não há dados suficientes para gerar os gráficos.</h5>
            <p>Cadastre eleitores no seu CRM para ativar a inteligência de dados.</p>
        </div>
    <?php else: ?>

    <div class="row g-4">
        
        <!-- TERMÔMETRO DO VOTO -->
        <div class="col-md-12">
            <div class="card border-0 shadow-sm rounded-4 h-100 p-3 bg-light">
                <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fas fa-thermometer-half text-danger me-2"></i> Termômetro de Apoio (Status do Voto)</h6>
                <div class="chart-container-large">
                    <canvas id="chartStatus"></canvas>
                </div>
            </div>
        </div>

        <!-- ESPECTRO POLÍTICO -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-4 h-100 p-3">
                <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fas fa-flag text-primary me-2"></i> Posicionamento Ideológico</h6>
                <div class="chart-container">
                    <canvas id="chartEspectro"></canvas>
                </div>
            </div>
        </div>

        <!-- FAIXA ETÁRIA -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-4 h-100 p-3">
                <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fas fa-birthday-cake text-warning me-2"></i> Faixa Etária</h6>
                <div class="chart-container">
                    <canvas id="chartIdade"></canvas>
                </div>
            </div>
        </div>

        <!-- GÊNERO -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 p-3">
                <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fas fa-venus-mars text-info me-2"></i> Distribuição por Gênero</h6>
                <div class="chart-container">
                    <canvas id="chartSexo"></canvas>
                </div>
            </div>
        </div>

        <!-- TOP BAIRROS -->
        <div class="col-md-8">
            <div class="card border-0 shadow-sm rounded-4 h-100 p-3">
                <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fas fa-map-marked-alt text-success me-2"></i> Top 10 Bairros (Maior Concentração)</h6>
                <div class="chart-container">
                    <canvas id="chartBairros"></canvas>
                </div>
            </div>
        </div>

    </div>

    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<!-- Plugin para exibir as porcentagens e números dentro dos gráficos de pizza -->
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>

<script>
    // Ativa o plugin de labels em todos os gráficos
    Chart.register(ChartDataLabels);
    
    // Configurações Globais Padrão
    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.color = '#6c757d';
    
    // Opções padrão para o plugin de textos dentro do gráfico
    const dataLabelsOptions = {
        color: '#fff',
        font: { weight: 'bold', size: 12 },
        formatter: (value, ctx) => {
            let sum = 0;
            let dataArr = ctx.chart.data.datasets[0].data;
            dataArr.map(data => { sum += data; });
            if (sum === 0) return null;
            let percentage = (value*100 / sum).toFixed(1)+"%";
            if (value === 0) return null; // Não mostra label se for zero
            return percentage;
        }
    };

    <?php if($total_eleitores > 0): ?>

    // 1. TERMÔMETRO DO VOTO (Gráfico de Barras)
    new Chart(document.getElementById('chartStatus'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($lbl_status) ?>,
            datasets: [{
                label: 'Quantidade de Eleitores',
                data: <?= json_encode($val_status) ?>,
                backgroundColor: ['#ffc107', '#0dcaf0', '#198754'],
                borderRadius: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                datalabels: {
                    color: '#333',
                    anchor: 'end',
                    align: 'top',
                    formatter: Math.round,
                    font: { weight: 'bold', size: 14 }
                }
            },
            scales: {
                y: { beginAtZero: true, grid: { display: false } },
                x: { grid: { display: false } }
            }
        }
    });

    // 2. ESPECTRO POLÍTICO (Doughnut)
    new Chart(document.getElementById('chartEspectro'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($lbl_espectro) ?>,
            datasets: [{
                data: <?= json_encode($val_espectro) ?>,
                backgroundColor: ['#dc3545', '#0d6efd', '#ffc107', '#6c757d', '#6f42c1', '#20c997'],
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '60%',
            plugins: {
                legend: { position: 'right' },
                datalabels: dataLabelsOptions
            }
        }
    });

    // 3. GÊNERO (Pie)
    new Chart(document.getElementById('chartSexo'), {
        type: 'pie',
        data: {
            labels: <?= json_encode($lbl_sexo) ?>,
            datasets: [{
                data: <?= json_encode($val_sexo) ?>,
                backgroundColor: ['#d63384', '#0d6efd', '#20c997', '#adb5bd'],
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' },
                datalabels: dataLabelsOptions
            }
        }
    });

    // 4. FAIXA ETÁRIA (Bar Horizontal)
    new Chart(document.getElementById('chartIdade'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($lbl_idade) ?>,
            datasets: [{
                label: 'Eleitores por Idade',
                data: <?= json_encode($val_idade) ?>,
                backgroundColor: '#fd7e14',
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y', // Transforma a barra em horizontal
            plugins: {
                legend: { display: false },
                datalabels: {
                    color: '#fff',
                    anchor: 'center',
                    align: 'center',
                    formatter: function(value) { return value > 0 ? value : ''; },
                    font: { weight: 'bold' }
                }
            },
            scales: {
                x: { beginAtZero: true, grid: { display: false } },
                y: { grid: { display: false } }
            }
        }
    });

    // 5. TOP BAIRROS (Barra Horizontal)
    new Chart(document.getElementById('chartBairros'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($lbl_bairros) ?>,
            datasets: [{
                label: 'Eleitores Registrados',
                data: <?= json_encode($val_bairros) ?>,
                backgroundColor: '#0dcaf0',
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            plugins: {
                legend: { display: false },
                datalabels: {
                    color: '#333',
                    anchor: 'end',
                    align: 'right',
                    formatter: function(value) { return value > 0 ? value : ''; },
                    font: { weight: 'bold' }
                }
            },
            scales: {
                x: { beginAtZero: true, grid: { display: false }, grace: '10%' }, // Grace dá um espaço a mais pra caber o numero
                y: { grid: { display: false } }
            }
        }
    });

    <?php endif; ?>
</script>
</body>
</html>