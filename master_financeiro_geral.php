<?php
// ATIVAR EXIBIÇÃO DE ERROS (Para Debug)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require 'config.php';

// Verificação de Segurança
if (!isset($_SESSION['permissoes']) || !in_array('master', $_SESSION['permissoes'])) {
    header("Location: login.php"); exit;
}

try {
    // 1. FATURAMENTO MENSAL (Últimos 6 meses)
    // Se a tabela não existir, o erro vai aparecer aqui
    $sql_meses = "SELECT DATE_FORMAT(data_pagamento, '%Y-%m') as mes, SUM(valor) as total 
                  FROM faturas 
                  WHERE status = 'pago' AND data_pagamento >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                  GROUP BY mes ORDER BY mes ASC";
    $res_meses = $pdo->query($sql_meses)->fetchAll(PDO::FETCH_ASSOC);

    $labels_mes = [];
    $dados_mes = [];
    
    // Preenche com dados reais ou arrays vazios se não tiver nada
    if($res_meses) {
        foreach($res_meses as $r) {
            $labels_mes[] = date('m/Y', strtotime($r['mes'] . '-01'));
            $dados_mes[] = $r['total'];
        }
    } else {
        // Dados fictícios para o gráfico não quebrar se estiver vazio
        $labels_mes = [date('m/Y')];
        $dados_mes = [0];
    }

    // 2. INADIMPLÊNCIA (Total Pendente vs Total Pago)
    $sql_status = "SELECT status, COUNT(*) as qtd, SUM(valor) as total FROM faturas GROUP BY status";
    $res_status = $pdo->query($sql_status)->fetchAll(PDO::FETCH_ASSOC);

    $total_pendente = 0;
    $total_pago = 0;
    foreach($res_status as $s) {
        if($s['status'] == 'pago') $total_pago += $s['total'];
        if($s['status'] == 'pendente') $total_pendente += $s['total'];
    }

} catch (PDOException $e) {
    // Se der erro no banco, mostra mensagem amigável
    die("<div style='padding:20px; color:red; border:1px solid red; background:#ffe6e6;'>
            <h3>Erro no Banco de Dados:</h3>
            <p>O sistema tentou acessar a tabela 'faturas' e falhou.</p>
            <p><strong>Detalhe Técnico:</strong> " . $e->getMessage() . "</p>
            <p>Certifique-se de ter rodado o SQL de criação da tabela 'faturas'.</p>
         </div>");
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8"><title>Relatórios Financeiros</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>body{background:#f3f4f6;font-family:'Segoe UI',sans-serif}</style>
</head>
<body>

<nav class="navbar navbar-dark bg-dark mb-4">
    <div class="container-fluid px-4">
        <a href="master_painel.php" class="navbar-brand fw-bold"><i class="fas fa-arrow-left me-2"></i> VOLTAR</a>
        <span class="text-white">Relatórios Financeiros</span>
    </div>
</nav>

<div class="container-fluid px-4">
    
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white py-3 fw-bold">Evolução do Faturamento (6 Meses)</div>
                <div class="card-body">
                    <canvas id="chartEvolucao"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white py-3 fw-bold">Saúde Financeira (R$)</div>
                <div class="card-body d-flex align-items-center justify-content-center">
                    <div style="width: 300px; height: 300px;">
                        <canvas id="chartPizza"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm p-4 text-center">
                <h3 class="fw-bold text-success">Total Recebido (Vitalício): R$ <?= number_format($total_pago, 2, ',', '.') ?></h3>
                <p class="text-muted">Valor em aberto (Pendente): R$ <?= number_format($total_pendente, 2, ',', '.') ?></p>
            </div>
        </div>
    </div>

</div>

<script>
    // Gráfico de Barras (Evolução)
    const ctxEvolucao = document.getElementById('chartEvolucao');
    if(ctxEvolucao) {
        new Chart(ctxEvolucao, {
            type: 'bar',
            data: {
                labels: <?= json_encode($labels_mes) ?>,
                datasets: [{
                    label: 'Faturamento (R$)',
                    data: <?= json_encode($dados_mes) ?>,
                    backgroundColor: '#0d6efd',
                    borderRadius: 5
                }]
            },
            options: { responsive: true, maintainAspectRatio: false }
        });
    }

    // Gráfico de Pizza (Status)
    const ctxPizza = document.getElementById('chartPizza');
    if(ctxPizza) {
        new Chart(ctxPizza, {
            type: 'doughnut',
            data: {
                labels: ['Recebido', 'Pendente/Atrasado'],
                datasets: [{
                    data: [<?= $total_pago ?>, <?= $total_pendente ?>],
                    backgroundColor: ['#198754', '#dc3545'],
                    borderWidth: 0
                }]
            },
            options: { responsive: true, maintainAspectRatio: false }
        });
    }
</script>

</body>
</html>