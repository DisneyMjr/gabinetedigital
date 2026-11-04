<?php
// admin_graficos.php - DASHBOARD ANALÍTICO COMPLETO (Ouvvi)
session_start();
require 'conexao.php';
require 'verificar_assinatura.php';

$tenant_id = $_SESSION['tenant_id'];
$user_nome = $_SESSION['user_nome'];

// --- 1. DADOS PARA O GRÁFICO POR STATUS ---
$sqlStatus = "SELECT status, COUNT(*) as total FROM reports WHERE tenant_id = ? GROUP BY status";
$stmtStatus = $pdo->prepare($sqlStatus);
$stmtStatus->execute([$tenant_id]);
$dadosStatus = $stmtStatus->fetchAll();

// --- 2. DADOS PARA O GRÁFICO POR SETOR ---
$sqlSetor = "SELECT s.nome, COUNT(r.id) as total 
             FROM sectors s 
             LEFT JOIN reports r ON s.id = r.setor_id 
             WHERE s.tenant_id = ? 
             GROUP BY s.nome";
$stmtSetor = $pdo->prepare($sqlSetor);
$stmtSetor->execute([$tenant_id]);
$dadosSetor = $stmtSetor->fetchAll();

// --- 3. DADOS PARA O GRÁFICO POR URGÊNCIA (IA) ---
$sqlUrgencia = "SELECT urgencia_ia, COUNT(*) as total FROM reports WHERE tenant_id = ? GROUP BY urgencia_ia ORDER BY urgencia_ia ASC";
$stmtUrgencia = $pdo->prepare($sqlUrgencia);
$stmtUrgencia->execute([$tenant_id]);
$dadosUrgencia = $stmtUrgencia->fetchAll();

// --- 4. MÉDIA DE SATISFAÇÃO (AVALIAÇÃO DO CIDADÃO) ---
$sqlSatis = "SELECT AVG(nota) as media, COUNT(*) as total_votos FROM feedbacks WHERE tenant_id = ?";
$stmtSatis = $pdo->prepare($sqlSatis);
$stmtSatis->execute([$tenant_id]);
$resSatis = $stmtSatis->fetch();
$mediaSatis = round($resSatis['media'] ?? 0, 1);
$totalVotos = $resSatis['total_votos'] ?? 0;
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Analítico | Ouvvi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', sans-serif; overflow-x: hidden; }
        .wrapper { display: flex; width: 100%; align-items: stretch; min-height: 100vh; }
        #sidebar { min-width: 250px; max-width: 250px; background: #0f172a; color: #fff; transition: all 0.3s; }
        #sidebar .sidebar-header { padding: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        #sidebar ul li a { padding: 12px 20px; display: block; color: rgba(255,255,255,0.7); text-decoration: none; }
        #sidebar ul li a:hover, #sidebar ul li a.active { color: #fff; background: rgba(255,255,255,0.1); border-left: 4px solid #0d6efd; }
        #content { width: 100%; }
        .card-dash { border: none; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); background: #fff; height: 100%; padding: 25px; }
        .stat-val { font-size: 2.5rem; font-weight: 800; color: #0f172a; }
    </style>
</head>
<body>
    <div class="wrapper">
        <nav id="sidebar">
            <div class="sidebar-header text-center"><h4 class="fw-bold text-primary">Ouvvi</h4></div>
            <ul class="list-unstyled">
                <li><a href="admin.php"><i class="bi bi-arrow-left"></i> Voltar ao Painel</a></li>
                <li class="px-4 mt-3 small text-muted text-uppercase">Relatórios</li>
                <li><a href="admin_graficos.php" class="active"><i class="bi bi-graph-up"></i> Gráficos da Ouvidoria</a></li>
            </ul>
        </nav>

        <div id="content" class="p-4 p-md-5">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3 class="fw-bold m-0 text-dark">Dashboard de Qualidade</h3>
                <button onclick="window.print()" class="btn btn-outline-dark btn-sm d-none d-md-block"><i class="bi bi-printer"></i> Imprimir Relatório</button>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="card-dash text-center">
                        <h6 class="fw-bold text-muted text-uppercase mb-3">Satisfação Geral</h6>
                        <div class="stat-val text-warning"><?php echo $mediaSatis; ?></div>
                        <div class="text-warning mb-2">
                            <?php for($i=1; $i<=5; $i++) echo $i <= $mediaSatis ? '<i class="bi bi-star-fill"></i>' : '<i class="bi bi-star"></i>'; ?>
                        </div>
                        <small class="text-muted">Média baseada em <?php echo $totalVotos; ?> avaliações</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card-dash text-center">
                        <h6 class="fw-bold text-muted text-uppercase mb-3">Total de Manifestações</h6>
                        <div class="stat-val text-primary"><?php echo array_sum(array_column($dadosStatus, 'total')); ?></div>
                        <small class="text-muted">Volume total desde o início</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card-dash text-center">
                        <h6 class="fw-bold text-muted text-uppercase mb-3">Nível de Risco Médio</h6>
                        <div class="stat-val text-danger">
                            <?php 
                                $somaRisco = 0; $contRisco = 0;
                                foreach($dadosUrgencia as $u) { $somaRisco += ($u['urgencia_ia'] * $u['total']); $contRisco += $u['total']; }
                                echo $contRisco > 0 ? round($somaRisco / $contRisco, 1) : '0';
                            ?>
                        </div>
                        <small class="text-muted">Gravidade detectada pela IA</small>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-md-6">
                    <div class="card-dash">
                        <h6 class="fw-bold text-muted mb-4">Manifestações por Unidade (Setor)</h6>
                        <canvas id="chartSetor"></canvas>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card-dash">
                        <h6 class="fw-bold text-muted mb-4">Fluxo por Status</h6>
                        <canvas id="chartStatus"></canvas>
                    </div>
                </div>
                <div class="col-md-12">
                    <div class="card-dash">
                        <h6 class="fw-bold text-muted mb-4">Análise de Risco Jurídico (Escala 1-5)</h6>
                        <canvas id="chartUrgencia" height="100"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Gráfico de Setores (Barra)
        new Chart(document.getElementById('chartSetor'), {
            type: 'bar',
            data: {
                labels: [<?php foreach($dadosSetor as $d) echo "'".$d['nome']."',"; ?>],
                datasets: [{
                    label: 'Relatos',
                    data: [<?php foreach($dadosSetor as $d) echo $d['total'].","; ?>],
                    backgroundColor: '#6366f1',
                    borderRadius: 8
                }]
            },
            options: { plugins: { legend: { display: false } } }
        });

        // Gráfico de Status (Donut)
        new Chart(document.getElementById('chartStatus'), {
            type: 'doughnut',
            data: {
                labels: [<?php foreach($dadosStatus as $d) echo "'".strtoupper($d['status'])."',"; ?>],
                datasets: [{
                    data: [<?php foreach($dadosStatus as $d) echo $d['total'].","; ?>],
                    backgroundColor: ['#3b82f6', '#f59e0b', '#10b981', '#ef4444'],
                    borderWidth: 0
                }]
            },
            options: { cutout: '70%' }
        });

        // Gráfico de Urgência (Linha)
        new Chart(document.getElementById('chartUrgencia'), {
            type: 'line',
            data: {
                labels: ['Nível 1', 'Nível 2', 'Nível 3', 'Nível 4', 'Nível 5'],
                datasets: [{
                    label: 'Volume de Risco',
                    data: [
                        <?php 
                        for($i=1; $i<=5; $i++) {
                            $val = 0;
                            foreach($dadosUrgencia as $u) { if($u['urgencia_ia'] == $i) $val = $u['total']; }
                            echo $val.",";
                        }
                        ?>
                    ],
                    borderColor: '#ef4444',
                    backgroundColor: 'rgba(239, 68, 68, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
            }
        });
    </script>
</body>
</html>