<?php
require 'config.php';
require 'protecao.php';

if (!tem_acesso('marketing')) { header("Location: admin_painel.php"); exit; }

// AÇÃO: EXCLUIR
if (isset($_GET['del'])) {
    $pdo->prepare("DELETE FROM campanhas WHERE id = ? AND tenant_id = ?")->execute([(int)$_GET['del'], TENANT_ID]);
    header("Location: admin_marketing.php"); exit;
}

// LISTAR CAMPANHAS
$stmt = $pdo->prepare("SELECT * FROM campanhas WHERE tenant_id = ? ORDER BY data_criacao DESC");
$stmt->execute([TENANT_ID]);
$campanhas = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8"><title>E-mail Marketing</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>body{background:#f3f4f6;font-family:'Segoe UI',sans-serif}</style>
</head>
<body>
    <div class="container mt-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="fw-bold text-primary"><i class="fas fa-paper-plane me-2"></i> Campanhas de E-mail</h3>
            <div>
                <a href="admin_painel.php" class="btn btn-secondary me-2">Voltar</a>
                <a href="admin_marketing_criar.php" class="btn btn-primary fw-bold"><i class="fas fa-plus"></i> Nova Campanha</a>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Assunto</th>
                                <th>Status</th>
                                <th>Enviados</th>
                                <th>Data</th>
                                <th class="text-end pe-4">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($campanhas as $c): ?>
                            <tr>
                                <td class="ps-4 fw-bold"><?= htmlspecialchars($c['assunto']) ?></td>
                                <td>
                                    <?php if($c['status'] == 'enviado'): ?>
                                        <span class="badge bg-success">Enviado</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Rascunho</span>
                                    <?php endif; ?>
                                </td>
                                <td><i class="fas fa-users me-1 text-muted"></i> <?= $c['total_enviados'] ?></td>
                                <td><?= date('d/m/Y H:i', strtotime($c['data_criacao'])) ?></td>
                                <td class="text-end pe-4">
                                    <?php if($c['status'] == 'rascunho'): ?>
                                        <a href="admin_marketing_disparo.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary fw-bold"><i class="fas fa-play"></i> Disparar</a>
                                        <a href="admin_marketing_criar.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-light border"><i class="fas fa-pen"></i></a>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-light text-success" disabled><i class="fas fa-check-double"></i> Concluído</button>
                                    <?php endif; ?>
                                    <a href="?del=<?= $c['id'] ?>" class="btn btn-sm btn-light border text-danger" onclick="return confirm('Excluir?')"><i class="fas fa-trash"></i></a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>