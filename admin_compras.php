<?php
session_start();
require 'config.php';
require 'protecao.php';
require 'compras_lib.php';

if (!compras_pode('ver')) die('Acesso negado.');

$filtroStatus = trim($_GET['status'] ?? '');
$q = trim($_GET['q'] ?? '');

$where = ["c.tenant_id=?"];
$params = [TENANT_ID];

if ($filtroStatus !== '') {
    $where[] = "c.status=?";
    $params[] = $filtroStatus;
}
if ($q !== '') {
    $where[] = "(c.titulo LIKE ? OR c.descricao LIKE ? OR f.nome LIKE ?)";
    $like = "%{$q}%";
    array_push($params, $like, $like, $like);
}

$sql = "SELECT c.*, u.nome AS solicitante_nome, f.nome AS fornecedor_nome
        FROM compras c
        LEFT JOIN usuarios u ON u.id=c.solicitante_id AND u.tenant_id=c.tenant_id
        LEFT JOIN compras_fornecedores f ON f.id=c.fornecedor_id AND f.tenant_id=c.tenant_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY c.id DESC";
$st = $pdo->prepare($sql);
$st->execute($params);
$compras = $st->fetchAll(PDO::FETCH_ASSOC);

$stM = $pdo->prepare("SELECT
    COUNT(*) total,
    SUM(status IN ('solicitada','em_aprovacao')) aguardando,
    SUM(status='aprovada') aprovadas,
    SUM(status IN ('aguardando_pagamento','comprada')) pagamento,
    COALESCE(SUM(CASE WHEN status IN ('paga','concluida') THEN COALESCE(valor_final,valor_estimado) ELSE 0 END),0) pago_mes
    FROM compras
    WHERE tenant_id=? AND DATE_FORMAT(criado_em,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')");
$stM->execute([TENANT_ID]);
$m = $stM->fetch(PDO::FETCH_ASSOC) ?: [];

$uid = compras_usuario_id();
$pendencias = [];
if ($uid) {
    $sqlPend = "SELECT DISTINCT c.*, u.nome AS solicitante_nome, f.nome AS fornecedor_nome
                FROM compras c
                LEFT JOIN usuarios u ON u.id=c.solicitante_id AND u.tenant_id=c.tenant_id
                LEFT JOIN compras_fornecedores f ON f.id=c.fornecedor_id AND f.tenant_id=c.tenant_id
                LEFT JOIN compras_aprovacoes ca
                       ON ca.compra_id=c.id
                      AND ca.tenant_id=c.tenant_id
                      AND ca.nivel=c.nivel_aprovacao_atual
                WHERE c.tenant_id=?
                  AND (
                        (c.status='em_aprovacao' AND ca.status='pendente' AND ca.aprovador_previsto_id=?)
                        OR
                        (c.status='aprovada' AND c.responsavel_compra_id=?)
                      )
                ORDER BY c.id DESC";
    $stP=$pdo->prepare($sqlPend);
    $stP->execute([TENANT_ID,$uid,$uid]);
    $pendencias=$stP->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Compras</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
body{background:#f5f7fb}.app{max-width:1400px}.stat{border:0;border-radius:18px}.table-card{border:0;border-radius:20px;overflow:hidden}
</style>
</head>
<body>
<div class="container-fluid app py-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
        <div>
            <h2 class="fw-bold mb-1"><i class="fas fa-cart-shopping text-primary me-2"></i>Compras</h2>
            <div class="text-muted">Solicitação, aprovação, compra e integração com Prestação de Contas.</div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="admin_painel.php" class="btn btn-outline-secondary rounded-pill"><i class="fas fa-arrow-left me-1"></i>Voltar</a>
            <?php if(compras_pode('config')): ?>
                <a href="admin_compras_config.php" class="btn btn-outline-dark rounded-pill"><i class="fas fa-gear me-1"></i>Regras</a>
            <?php endif; ?>
            <?php if(compras_pode('criar')): ?>
                <a href="compra_form.php" class="btn btn-primary rounded-pill px-4"><i class="fas fa-plus me-1"></i>Nova solicitação</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card stat shadow-sm p-3"><small class="text-muted fw-bold">TOTAL NO MÊS</small><h3 class="fw-bold mb-0"><?= (int)($m['total']??0) ?></h3></div></div>
        <div class="col-md-3"><div class="card stat shadow-sm p-3"><small class="text-muted fw-bold">AGUARDANDO APROVAÇÃO</small><h3 class="fw-bold text-warning mb-0"><?= (int)($m['aguardando']??0) ?></h3></div></div>
        <div class="col-md-3"><div class="card stat shadow-sm p-3"><small class="text-muted fw-bold">AGUARDANDO PAGAMENTO</small><h3 class="fw-bold text-info mb-0"><?= (int)($m['pagamento']??0) ?></h3></div></div>
        <div class="col-md-3"><div class="card stat shadow-sm p-3"><small class="text-muted fw-bold">PAGO/CONCLUÍDO</small><h5 class="fw-bold text-success mb-0"><?= compras_moeda($m['pago_mes']??0) ?></h5></div></div>
    </div>

    <?php if($pendencias): ?>
    <div class="card border-0 rounded-4 shadow-sm mb-4">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h5 class="fw-bold mb-1"><i class="fas fa-bell text-warning me-2"></i>Minhas pendências</h5>
                    <small class="text-muted">Itens que precisam da sua ação agora.</small>
                </div>
                <span class="badge bg-danger rounded-pill"><?= count($pendencias) ?></span>
            </div>
            <div class="row g-3">
                <?php foreach($pendencias as $p): ?>
                <div class="col-lg-4 col-md-6">
                    <div class="border rounded-4 p-3 h-100">
                        <small class="text-muted fw-bold">#<?= (int)$p['id'] ?></small>
                        <div class="fw-bold mb-2"><?= compras_h($p['titulo']) ?></div>
                        <div class="small text-muted mb-3">
                            <?= compras_moeda($p['valor_final'] ?: $p['valor_estimado']) ?> · <?= compras_status_label($p['status']) ?>
                        </div>
                        <a href="compra_detalhe.php?id=<?= (int)$p['id'] ?>" class="btn btn-dark btn-sm rounded-pill w-100">Abrir e resolver</a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card table-card shadow-sm">
        <div class="card-body border-bottom">
            <form class="row g-2">
                <div class="col-md-5"><input class="form-control" name="q" value="<?= compras_h($q) ?>" placeholder="Buscar compra ou fornecedor..."></div>
                <div class="col-md-4">
                    <select class="form-select" name="status">
                        <option value="">Todos os status</option>
                        <?php foreach(['solicitada','em_aprovacao','aprovada','recusada','comprada','aguardando_pagamento','paga','concluida','cancelada'] as $s): ?>
                            <option value="<?= $s ?>" <?= $filtroStatus===$s?'selected':'' ?>><?= compras_status_label($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3"><button class="btn btn-dark w-100">Filtrar</button></div>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>#</th><th>Solicitação</th><th>Solicitante</th><th>Valor</th><th>Status</th><th>Data</th><th></th></tr></thead>
                <tbody>
                <?php foreach($compras as $c): ?>
                    <tr>
                        <td class="fw-bold">#<?= (int)$c['id'] ?></td>
                        <td><strong><?= compras_h($c['titulo']) ?></strong><?php if($c['fornecedor_nome']): ?><br><small class="text-muted"><?= compras_h($c['fornecedor_nome']) ?></small><?php endif; ?></td>
                        <td><?= compras_h($c['solicitante_nome'] ?: '-') ?></td>
                        <td class="fw-bold"><?= compras_moeda($c['valor_final'] ?: $c['valor_estimado']) ?></td>
                        <td><span class="badge bg-<?= compras_status_class($c['status']) ?> rounded-pill"><?= compras_status_label($c['status']) ?></span></td>
                        <td><?= date('d/m/Y', strtotime($c['criado_em'])) ?></td>
                        <td class="text-end"><a href="compra_detalhe.php?id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-primary rounded-pill">Abrir</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if(!$compras): ?><tr><td colspan="7" class="text-center py-5 text-muted">Nenhuma compra encontrada.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>