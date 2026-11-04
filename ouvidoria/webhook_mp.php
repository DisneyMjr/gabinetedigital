<?php
require 'mercadopago.php';
header('Content-Type: text/plain; charset=UTF-8');

$raw = file_get_contents('php://input');
$event = json_decode($raw, true) ?: [];
$GLOBALS['MP_WEBHOOK_DATA_ID'] = (string)($event['data']['id'] ?? '');

if (!mp_validate_webhook_signature($pdo)) {
    http_response_code(401);
    echo 'invalid signature';
    exit;
}

$type = $event['type'] ?? $event['topic'] ?? '';
$resourceId = (string)($event['data']['id'] ?? $_GET['id'] ?? $_GET['data_id'] ?? $_GET['data.id'] ?? '');

if (!$resourceId) {
    http_response_code(200);
    echo 'ignored';
    exit;
}

$tenantId = null;
try {
    $log = $pdo->prepare("INSERT INTO subscription_events (tenant_id, gateway, event_type, resource_id, payload) VALUES (NULL, 'mercadopago', ?, ?, ?)");
    $log->execute([(string)$type, $resourceId, $raw]);
} catch (Throwable $e) {
    error_log('Falha ao registrar webhook MP: ' . $e->getMessage());
}

// Eventos de assinatura
if (in_array($type, ['subscription_preapproval', 'preapproval'], true) || str_contains((string)($event['action'] ?? ''), 'preapproval')) {
    $res = mp_api($pdo, 'GET', '/preapproval/' . rawurlencode($resourceId));
    if ($res['ok']) {
        $sub = $res['data'];
        $tenantId = (int)($sub['external_reference'] ?? 0);
        if (!$tenantId && !empty($sub['id'])) {
            $st = $pdo->prepare("SELECT id FROM tenants WHERE mp_subscription_id = ? LIMIT 1");
            $st->execute([$sub['id']]);
            $tenantId = (int)$st->fetchColumn();
        }
        if ($tenantId) {
            mp_update_tenant_subscription($pdo, $tenantId, $sub);
            $status = $sub['status'] ?? '';
            if ($status === 'authorized') {
                $pdo->prepare("UPDATE tenants SET ativo = 1 WHERE id = ?")->execute([$tenantId]);
            }
            if (in_array($status, ['cancelled','canceled'], true)) {
                $pdo->prepare("UPDATE tenants SET mp_subscription_status = 'cancelled' WHERE id = ?")->execute([$tenantId]);
            }
            try {
                $pdo->prepare("UPDATE subscription_events SET tenant_id = ? WHERE gateway='mercadopago' AND resource_id=? ORDER BY id DESC LIMIT 1")->execute([$tenantId, $resourceId]);
            } catch (Throwable $e) {}
        }
    }

    http_response_code(200);
    echo 'OK';
    exit;
}

// Pagamentos gerados pelas assinaturas
if ($type === 'payment' || str_starts_with((string)($event['action'] ?? ''), 'payment.')) {
    $res = mp_api($pdo, 'GET', '/v1/payments/' . rawurlencode($resourceId));
    if ($res['ok']) {
        $payment = $res['data'];
        $tenantId = (int)($payment['external_reference'] ?? 0);
        $subscriptionId = $payment['metadata']['preapproval_id'] ?? $payment['subscription_id'] ?? null;

        if (!$tenantId && $subscriptionId) {
            $st = $pdo->prepare("SELECT id FROM tenants WHERE mp_subscription_id = ? LIMIT 1");
            $st->execute([$subscriptionId]);
            $tenantId = (int)$st->fetchColumn();
        }

        if ($tenantId) {
            $status = (string)($payment['status'] ?? '');
            $amount = (float)($payment['transaction_amount'] ?? 0);
            $paidAt = $payment['date_approved'] ?? null;
            try {
                $stmt = $pdo->prepare("INSERT INTO payments
                    (tenant_id, valor, forma_pagamento, obs, gateway, gateway_payment_id, gateway_status, subscription_id, paid_at)
                    VALUES (?, ?, ?, ?, 'mercadopago', ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE gateway_status=VALUES(gateway_status), paid_at=VALUES(paid_at), valor=VALUES(valor)");
                $stmt->execute([
                    $tenantId,
                    $amount,
                    'Mercado Pago Recorrente',
                    'Pagamento recorrente Mercado Pago',
                    (string)$payment['id'],
                    $status,
                    $subscriptionId,
                    $paidAt ? date('Y-m-d H:i:s', strtotime($paidAt)) : null
                ]);
            } catch (Throwable $e) {
                error_log('Falha ao salvar pagamento MP: ' . $e->getMessage());
            }

            if ($status === 'approved') {
                // Mantém uma data local de cortesia/compatibilidade com o bloqueio antigo.
                // A fonte principal de verdade passa a ser a assinatura do Mercado Pago.
                $pdo->prepare("UPDATE tenants SET ativo=1, data_expiracao=DATE_ADD(NOW(), INTERVAL 35 DAY) WHERE id=?")->execute([$tenantId]);
            }
            try {
                $pdo->prepare("UPDATE subscription_events SET tenant_id = ? WHERE gateway='mercadopago' AND resource_id=? ORDER BY id DESC LIMIT 1")->execute([$tenantId, $resourceId]);
            } catch (Throwable $e) {}
        }
    }

    http_response_code(200);
    echo 'OK';
    exit;
}

http_response_code(200);
echo 'ignored';
