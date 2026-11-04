<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/assinatura_mp_lib.php';

if (empty($_SESSION['tenant_id'])) {
    header('Location: /login.php');
    exit;
}

$tenant_id = (int)$_SESSION['tenant_id'];
$erro = '';
$assinatura_criada = null;

try {
    $stmt = $pdo->prepare("SELECT id, status, data_vencimento, nome, email_contato, slug, plano, txid_pagamento FROM tenants WHERE id = ? LIMIT 1");
    $stmt->execute([$tenant_id]);
    $dados_tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$dados_tenant) { session_destroy(); header('Location: /login.php'); exit; }
} catch (Throwable $e) {
    die('Erro ao buscar dados do cliente.');
}

if (isset($_GET['mp_retorno'])) {
    try {
        if (!empty($dados_tenant['txid_pagamento'])) {
            mp_processar_preapproval($pdo, $dados_tenant['txid_pagamento'], mp_token_seguro($pdo));
            $stmt = $pdo->prepare("SELECT id, status, data_vencimento, nome, email_contato, slug, plano, txid_pagamento FROM tenants WHERE id = ? LIMIT 1");
            $stmt->execute([$tenant_id]);
            $dados_tenant = $stmt->fetch(PDO::FETCH_ASSOC) ?: $dados_tenant;
        }
    } catch (Throwable $e) { mp_assinatura_log('Retorno MP: ' . $e->getMessage()); }
}

if (empty($_SESSION['mgd_mp_csrf'])) { $_SESSION['mgd_mp_csrf'] = bin2hex(random_bytes(24)); }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['escolher_plano'])) {
    try {
        if (!hash_equals((string)$_SESSION['mgd_mp_csrf'], (string)($_POST['csrf'] ?? ''))) { throw new RuntimeException('Sessao invalida. Recarregue a pagina.'); }
        $id_plano = (int)$_POST['escolher_plano'];
        $stmtPlano = $pdo->prepare("SELECT * FROM planos WHERE id = ? AND preco > 0 LIMIT 1");
        $stmtPlano->execute([$id_plano]);
        $plano_escolhido = $stmtPlano->fetch(PDO::FETCH_ASSOC);
        if (!$plano_escolhido) throw new RuntimeException('Plano não encontrado.');

        $assinatura_criada = mp_criar_assinatura_recorrente($pdo, $dados_tenant, $plano_escolhido);
        if (!empty($assinatura_criada['init_point'])) {
            header('Location: ' . $assinatura_criada['init_point']);
            exit;
        }
        $erro = 'Assinatura criada, mas o Mercado Pago não retornou link de pagamento. Veja os logs.';
    } catch (Throwable $e) {
        $erro = $e->getMessage();
        mp_assinatura_log('Erro renovar_conta: ' . $e->getMessage());
    }
}

try {
    $planos = $pdo->query("SELECT * FROM planos WHERE preco > 0 ORDER BY preco ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { die('Erro ao buscar planos.'); }

$nome_cliente = $dados_tenant['nome'] ?? 'Cliente';
$data_raw = $dados_tenant['data_vencimento'] ?? '';
$vencimento_banco = ($data_raw && $data_raw != '0000-00-00') ? date('d/m/Y', strtotime($data_raw)) : 'Pendente';
$ativo = (($dados_tenant['status'] ?? '') === 'ativo' && strtotime((string)$data_raw) >= strtotime(date('Y-m-d')));
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Renovar Assinatura Recorrente</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background:#f0f2f5; font-family:Segoe UI,sans-serif; min-height:100vh; }
        .card-plano { transition:.25s; border:2px solid transparent; }
        .card-plano:hover { border-color:#0d6efd; transform:translateY(-4px); }
    </style>
</head>
<body class="py-5">
<div class="container">
    <div class="text-center mb-5">
        <span class="fa-stack fa-2x <?= $ativo ? 'text-success' : 'text-danger' ?>">
            <i class="fas fa-circle fa-stack-2x"></i>
            <i class="fas <?= $ativo ? 'fa-check' : 'fa-lock' ?> fa-stack-1x fa-inverse"></i>
        </span>
        <h2 class="fw-bold mt-3 <?= $ativo ? 'text-success' : 'text-danger' ?>">
            <?= $ativo ? 'Assinatura ativa' : 'Acesso expirado' ?>
        </h2>
        <p class="text-muted fs-5">Olá, <strong><?= htmlspecialchars($nome_cliente) ?></strong>.<br>
            Vencimento atual: <strong class="text-dark"><?= $vencimento_banco ?></strong>.</p>
        <div class="alert alert-info d-inline-block py-2 px-4 shadow-sm mt-2">
            <i class="fas fa-sync-alt me-2"></i>
            Escolha um plano para ativar cobrança mensal recorrente pelo Mercado Pago.
        </div>
        <?php if ($erro): ?>
            <div class="alert alert-danger mt-3 mx-auto" style="max-width:720px;"><?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>
        <?php if ($ativo): ?>
            <div class="mt-3"><a href="admin_painel.php" class="btn btn-success rounded-pill px-4 fw-bold">Entrar no painel</a></div>
        <?php endif; ?>
    </div>

    <div class="row justify-content-center g-4">
        <?php foreach($planos as $p):
            $beneficios = explode("\n", $p['beneficios'] ?? '');
            $is_current = (($dados_tenant['plano'] ?? '') == ($p['slug'] ?? ''));
        ?>
        <div class="col-md-4">
            <div class="card card-plano h-100 rounded-4 <?= $is_current ? 'border-primary shadow' : '' ?>">
                <?php if($is_current): ?><div class="position-absolute top-0 start-50 translate-middle badge rounded-pill bg-primary px-3 py-2">SEU PLANO ATUAL</div><?php endif; ?>
                <div class="card-body p-4 text-center d-flex flex-column mt-2">
                    <h5 class="fw-bold text-uppercase text-secondary"><?= htmlspecialchars($p['nome'] ?? $p['slug']) ?></h5>
                    <h1 class="my-3 fw-bold text-dark">R$ <?= number_format((float)$p['preco'], 2, ',', '.') ?></h1>
                    <span class="badge bg-light text-dark border mb-4 align-self-center">Cobrança mensal recorrente</span>
                    <ul class="list-unstyled text-start small text-muted mb-4 flex-grow-1 ps-3 border-top pt-3">
                        <?php foreach($beneficios as $b): if(trim($b)): ?><li class="mb-2"><i class="fas fa-check text-success me-2"></i><?= htmlspecialchars(trim($b)) ?></li><?php endif; endforeach; ?>
                    </ul>
                    <form method="post" action="renovar_conta.php">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['mgd_mp_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="escolher_plano" value="<?= (int)$p['id'] ?>">
                        <button type="submit" class="btn <?= $is_current ? 'btn-primary' : 'btn-outline-primary' ?> rounded-pill fw-bold w-100 py-2">
                            <i class="fas fa-credit-card me-2"></i><?= $is_current ? 'VERIFICAR ASSINATURA' : 'ASSINAR RECORRENTE' ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="text-center mt-5">
        <a href="logout.php" class="text-decoration-none text-secondary"><i class="fas fa-sign-out-alt me-1"></i> Sair do Sistema</a>
    </div>
</div>
</body>
</html>
