<?php
require 'config.php';   // 1º O Cérebro (Define quem é o cliente)
require 'protecao.php'; // 2º O Porteiro (Verifica se pode entrar

// Proteção extra: Garantir que TENANT_ID existe
if (!defined('TENANT_ID')) {
    die("Erro: Acesso inválido.");
}

// 1. BUSCA DADOS DO PLANO E TENANT
$stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
$stmt->execute([TENANT_ID]);
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);

// 2. BUSCA FATURAS
$stmt = $pdo->prepare("SELECT * FROM faturas WHERE tenant_id = ? ORDER BY data_vencimento DESC");
$stmt->execute([TENANT_ID]);
$faturas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. IDENTIFICA SE HÁ FATURA PENDENTE PARA PAGAR
$fatura_aberta = null;
foreach ($faturas as $f) {
    if ($f['status'] == 'pendente') {
        $fatura_aberta = $f;
        break;
    }
}

// 4. GERAÇÃO DE LINK DE PAGAMENTO (CURL DIRETO)
$link_pagamento = "";
if ($fatura_aberta) {
    $dados_pagamento = [
        "items" => [[
            "title" => "Fatura Gabinete Digital - Vencimento " . date('d/m/Y', strtotime($fatura_aberta['data_vencimento'])),
            "quantity" => 1,
            "currency_id" => "BRL",
            "unit_price" => (float)$fatura_aberta['valor']
        ]],
        "external_reference" => (string)$fatura_aberta['id'], // ID da fatura para o webhook dar baixa
        "back_urls" => [
            "success" => "http://" . $_SERVER['HTTP_HOST'] . "/admin_minha_assinatura.php?msg=sucesso",
            "failure" => "http://" . $_SERVER['HTTP_HOST'] . "/admin_minha_assinatura.php?msg=erro"
        ],
        "auto_return" => "approved"
    ];

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://api.mercadopago.com/checkout/preferences",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($dados_pagamento),
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer " . MP_ACCESS_TOKEN,
            "Content-Type: application/json"
        ],
    ]);
    $response = curl_exec($curl);
    $mp_data = json_decode($response, true);
    $link_pagamento = $mp_data['init_point'] ?? "";
    curl_close($curl);
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Minha Assinatura - Painel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f8f9fa; font-family: 'Inter', sans-serif; }
        .card-plan { border: none; border-radius: 15px; background: white; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .status-badge { padding: 5px 15px; border-radius: 20px; font-weight: bold; font-size: 0.8rem; }
    </style>
</head>
<body>

<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold"><i class="fas fa-file-invoice-dollar text-primary me-2"></i> Minha Assinatura</h2>
        <a href="admin_painel.php" class="btn btn-outline-secondary rounded-pill btn-sm">Voltar ao Painel</a>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card card-plan p-4 mb-4">
                <h5 class="fw-bold mb-3 text-secondary border-bottom pb-2">Plano Atual</h5>
                <h3 class="text-primary fw-bold text-uppercase"><?= $tenant['plano'] ?></h3>
                <p class="text-muted small">Status da Conta: 
                    <span class="badge bg-<?= $tenant['status'] == 'ativo' ? 'success' : 'danger' ?>">
                        <?= strtoupper($tenant['status']) ?>
                    </span>
                </p>
                <hr>
                <p class="small text-muted mb-1">Cliente desde: <?= date('d/m/Y', strtotime($tenant['criado_em'])) ?></p>
                
                <?php if($tenant['plano'] == 'demo'): ?>
                    <div class="alert alert-info small mt-3">
                        <i class="fas fa-info-circle"></i> Você está no período de teste. Assine um plano para não perder o acesso.
                    </div>
                    <a href="https://wa.me/5537999129477?text=Quero%20mudar%20meu%20plano" class="btn btn-primary w-100 fw-bold">UPGRADE DE PLANO</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-8">
            <?php if ($fatura_aberta): ?>
                <div class="card border-warning bg-warning bg-opacity-10 p-4 mb-4 rounded-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="fw-bold text-dark mb-1">Fatura Pendente Encontrada!</h5>
                            <p class="mb-0">Vencimento: <b><?= date('d/m/Y', strtotime($fatura_aberta['data_vencimento'])) ?></b> | Valor: <b>R$ <?= number_format($fatura_aberta['valor'], 2, ',', '.') ?></b></p>
                        </div>
                        <a href="<?= $link_pagamento ?>" class="btn btn-warning fw-bold px-4 shadow">PAGAR AGORA</a>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card card-plan overflow-hidden">
                <div class="p-4 border-bottom bg-light">
                    <h6 class="m-0 fw-bold">Histórico de Cobrança</h6>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">Vencimento</th>
                                <th>Valor</th>
                                <th>Status</th>
                                <th class="text-end pe-4">Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($faturas)): ?>
                                <tr><td colspan="4" class="text-center py-4 text-muted">Nenhuma fatura registrada.</td></tr>
                            <?php endif; ?>

                            <?php foreach($faturas as $f): ?>
                            <tr>
                                <td class="ps-4"><?= date('d/m/Y', strtotime($f['data_vencimento'])) ?></td>
                                <td class="fw-bold">R$ <?= number_format($f['valor'], 2, ',', '.') ?></td>
                                <td>
                                    <span class="status-badge bg-<?= $f['status'] == 'pago' ? 'success' : 'danger' ?> bg-opacity-10 text-<?= $f['status'] == 'pago' ? 'success' : 'danger' ?>">
                                        <?= strtoupper($f['status']) ?>
                                    </span>
                                </td>
                                <td class="text-end pe-4">
                                    <?php if($f['status'] == 'pago'): ?>
                                        <span class="text-muted small">Liquidada em <?= date('d/m/Y', strtotime($f['data_pagamento'])) ?></span>
                                    <?php else: ?>
                                        <a href="<?= $link_pagamento ?>" class="btn btn-sm btn-outline-primary fw-bold">Pagar</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>