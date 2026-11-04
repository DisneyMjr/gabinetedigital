<?php
session_start();
require 'config.php';

// --- SEGURANÇA MASTER (NÃO USE protecao.php AQUI) ---
if (!isset($_SESSION['permissoes']) || !in_array('master', $_SESSION['permissoes'])) {
    header("Location: login.php"); exit;
}

// AÇÃO: CONFIRMAR PAGAMENTO MANUAL
if (isset($_GET['pagar'])) {
    $id_fatura = (int)$_GET['pagar'];
    $pdo->prepare("UPDATE faturas SET status = 'pago', data_pagamento = NOW() WHERE id = ?")->execute([$id_fatura]);
    header("Location: master_financeiro.php"); exit;
}

// AÇÃO: EXCLUIR FATURA
if (isset($_GET['del'])) {
    $pdo->prepare("DELETE FROM faturas WHERE id = ?")->execute([(int)$_GET['del']]);
    header("Location: master_financeiro.php"); exit;
}

// LISTAR TODAS AS FATURAS
$sql = "SELECT f.*, t.nome as cliente_nome, t.slug 
        FROM faturas f 
        JOIN tenants t ON f.tenant_id = t.id 
        ORDER BY f.data_vencimento DESC";
$faturas = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8"><title>Financeiro Master</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>body{background:#f3f4f6;font-family:'Segoe UI',sans-serif}</style>
</head>
<body>

<nav class="navbar navbar-dark bg-dark mb-4">
    <div class="container-fluid px-4">
        <a href="master_painel.php" class="navbar-brand fw-bold"><i class="fas fa-arrow-left me-2"></i> VOLTAR AO PAINEL</a>
        <span class="text-white">Gestão Financeira</span>
    </div>
</nav>

<div class="container-fluid px-4">
    <div class="card shadow-sm border-0 rounded-4">
        <div class="card-header bg-white py-3">
            <h5 class="fw-bold m-0 text-success"><i class="fas fa-file-invoice-dollar me-2"></i> Faturas Geradas</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-4">Cliente</th>
                        <th>Vencimento</th>
                        <th>Valor</th>
                        <th>Status</th>
                        <th>Ref.</th>
                        <th class="text-end pe-4">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($faturas as $f): ?>
                    <tr>
                        <td class="ps-4">
                            <strong class="d-block text-dark"><?= $f['cliente_nome'] ?></strong>
                            <small class="text-muted"><?= $f['slug'] ?></small>
                        </td>
                        <td>
                            <?php 
                                $venc = new DateTime($f['data_vencimento']);
                                $hoje = new DateTime();
                                $cor = ($venc < $hoje && $f['status'] != 'pago') ? 'text-danger fw-bold' : '';
                                echo "<span class='$cor'>".$venc->format('d/m/Y')."</span>";
                            ?>
                        </td>
                        <td>R$ <?= number_format($f['valor'], 2, ',', '.') ?></td>
                        <td>
                            <?php if($f['status']=='pago'): ?>
                                <span class="badge bg-success">Pago</span>
                            <?php elseif($f['status']=='cancelado'): ?>
                                <span class="badge bg-danger">Cancelado</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">Pendente</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= $f['descricao'] ?? 'Mensalidade' ?></td>
                        <td class="text-end pe-4">
                            <?php if($f['status']!='pago'): ?>
                                <a href="?pagar=<?= $f['id'] ?>" class="btn btn-sm btn-outline-success" onclick="return confirm('Confirmar pagamento manual?')"><i class="fas fa-check"></i> Baixar</a>
                            <?php endif; ?>
                            <a href="?del=<?= $f['id'] ?>" class="btn btn-sm btn-light text-danger border" onclick="return confirm('Excluir essa fatura?')"><i class="fas fa-trash"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>