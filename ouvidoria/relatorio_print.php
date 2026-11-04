<?php
require 'conexao.php';
session_start();
$id = $_GET['id'];
$tenant_id = $_SESSION['tenant_id'];

$stmt = $pdo->prepare("SELECT r.*, s.nome as setor_nome FROM reports r LEFT JOIN sectors s ON r.setor_id = s.id WHERE r.id = ? AND r.tenant_id = ?");
$stmt->execute([$id, $tenant_id]);
$r = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Relatório Protocolo #<?= $r['protocolo_visualizacao'] ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @media print { .no-print { display: none; } body { background: white; } }
        .cabecalho { border-bottom: 2px solid #000; margin-bottom: 20px; padding-bottom: 10px; }
    </style>
</head>
<body class="bg-white p-5">
    <div class="no-print mb-4 text-end">
        <button onclick="window.print()" class="btn btn-dark"><i class="bi bi-printer"></i> Imprimir / Salvar PDF</button>
    </div>

    <div class="cabecalho d-flex justify-content-between align-items-center">
        <div>
            <h3 class="fw-bold mb-0">OUVIDORIA MUNICIPAL</h3>
            <p class="mb-0 text-muted">Relatório Oficial de Manifestação</p>
        </div>
        <div class="text-end">
            <h5 class="fw-bold mb-0">#<?= $r['protocolo_visualizacao'] ?></h5>
            <small>Data: <?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></small>
        </div>
    </div>

    <table class="table table-bordered">
        <tr class="table-light">
            <th colspan="2">DADOS DA MANIFESTAÇÃO</th>
        </tr>
        <tr>
            <td width="30%" class="fw-bold">Status:</td>
            <td><?= strtoupper($r['status']) ?></td>
        </tr>
        <tr>
            <td class="fw-bold">Unidade Responsável:</td>
            <td><?= $r['setor_nome'] ?></td>
        </tr>
        <tr>
            <td class="fw-bold">Resumo (IA):</td>
            <td><?= $r['resumo_ia'] ?></td>
        </tr>
    </table>

    <div class="mt-4">
        <h6 class="fw-bold border-bottom pb-2">CONTEÚDO DO RELATO:</h6>
        <div class="p-3 border bg-light rounded">
            <?= nl2br(htmlspecialchars(decrypt_payload($r['texto_original_criptografado'], $r['iv']))) ?>
        </div>
    </div>

    <div class="mt-4">
        <h6 class="fw-bold border-bottom pb-2">RESPOSTA ENVIADA AO CIDADÃO:</h6>
        <div class="p-3 border rounded">
            <?= $r['resposta_publica'] ?: '<i>Aguardando resposta oficial.</i>' ?>
        </div>
    </div>

    <div class="mt-5 pt-5 text-center">
        <div class="d-inline-block border-top px-5 pt-2">
            Responsável pela Ouvidoria
        </div>
    </div>
</body>
</html>