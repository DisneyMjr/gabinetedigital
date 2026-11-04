<?php
session_start();
require 'mercadopago.php';

$status = 'pending';
$message = 'Estamos confirmando sua assinatura.';
if (isset($_SESSION['tenant_id'])) {
    $tenantId = (int)$_SESSION['tenant_id'];
    $stmt = $pdo->prepare("SELECT mp_subscription_id FROM tenants WHERE id = ?");
    $stmt->execute([$tenantId]);
    $subId = $stmt->fetchColumn();
    if ($subId) {
        $res = mp_api($pdo, 'GET', '/preapproval/' . rawurlencode($subId));
        if ($res['ok']) {
            mp_update_tenant_subscription($pdo, $tenantId, $res['data']);
            $status = $res['data']['status'] ?? 'pending';
        }
    }
}
if ($status === 'authorized') $message = 'Assinatura ativada com sucesso. As próximas cobranças serão feitas automaticamente pelo Mercado Pago.';
elseif ($status === 'paused') $message = 'A assinatura está pausada.';
elseif (in_array($status, ['cancelled','canceled'], true)) $message = 'A assinatura foi cancelada.';
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Assinatura</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><main class="container py-5"><div class="row justify-content-center"><div class="col-lg-6"><div class="card border-0 shadow-sm rounded-4"><div class="card-body p-5 text-center"><h2 class="fw-bold mb-3"><?= htmlspecialchars(mp_subscription_status_label($status)) ?></h2><p class="text-secondary"><?= htmlspecialchars($message) ?></p><a href="admin_assinatura.php" class="btn btn-primary px-4">Ver minha assinatura</a></div></div></div></div></main></body></html>
