<?php
require_once __DIR__ . '/config.php';

if (file_exists(__DIR__ . '/gateway.php')) {
    require_once __DIR__ . '/gateway.php';
}

require_once __DIR__ . '/assinatura_mp_lib.php';

http_response_code(200);

$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true) ?: [];

mp_assinatura_log('WEBHOOK RECEBIDO: ' . substr((string)$raw, 0, 3000));

try {
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('PDO não disponível no webhook.');
    }

    $token = mp_token_seguro($pdo);

    $type = (string)($payload['type'] ?? $payload['topic'] ?? '');
    $action = (string)($payload['action'] ?? '');
    $id = (string)($payload['data']['id'] ?? $payload['id'] ?? '');

    if ($id === '' && !empty($payload['resource'])) {
        $parts = explode('/', (string)$payload['resource']);
        $id = (string)end($parts);
    }

    if ($id === '') {
        mp_assinatura_log('Webhook sem ID. Nada a processar.');
        exit;
    }

    if ($type === 'payment' || str_starts_with($action, 'payment.')) {
        $r = mp_api_get('https://api.mercadopago.com/v1/payments/' . urlencode($id), $token);

        if ($r['code'] === 200 && !empty($r['json'])) {
            $pay = $r['json'];
            $tenant_id = mp_find_tenant_id($pdo, $pay);
            $status = (string)($pay['status'] ?? '');

            if ($tenant_id <= 0) {
                mp_assinatura_log('PAYMENT SEM TENANT DEBUG: ' . json_encode([
                    'id' => $pay['id'] ?? null,
                    'status' => $pay['status'] ?? null,
                    'status_detail' => $pay['status_detail'] ?? null,
                    'external_reference' => $pay['external_reference'] ?? null,
                    'metadata' => $pay['metadata'] ?? null,
                    'preapproval_id' => $pay['preapproval_id'] ?? null,
                    'subscription_id' => $pay['subscription_id'] ?? null,
                    'point_of_interaction' => $pay['point_of_interaction'] ?? null
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            if ($tenant_id > 0 && $status === 'approved') {
                $dias = (int)($pay['metadata']['dias_liberacao'] ?? 31);
                $plano = $pay['metadata']['plano_novo'] ?? null;

                mp_ativar_tenant(
                    $pdo,
                    $tenant_id,
                    date('Y-m-d H:i:s', strtotime('+' . max(1, $dias) . ' days')),
                    $pay['preapproval_id'] ?? null,
                    $plano
                );

                mp_registrar_fatura($pdo, $tenant_id, $pay, 'pago');

                mp_assinatura_log("Payment {$id} aprovado tenant={$tenant_id}; ativado.");
            } else {
                mp_assinatura_log("Payment {$id} status={$status} tenant={$tenant_id}; sem ativação. status_detail=" . ($pay['status_detail'] ?? ''));
            }
        } else {
            mp_assinatura_log("Falha payment {$id}. HTTP {$r['code']} RAW: " . substr((string)$r['raw'], 0, 1000));
        }

        exit;
    }

    if ($type === 'subscription_authorized_payment' || str_contains($action, 'authorized_payment')) {
        mp_processar_authorized_payment($pdo, $id, $token);
        exit;
    }

    if ($type === 'subscription_preapproval' || str_contains($action, 'preapproval')) {
        mp_processar_preapproval($pdo, $id, $token);
        exit;
    }

    mp_assinatura_log("Tipo não tratado: type={$type} action={$action} id={$id}");
} catch (Throwable $e) {
    mp_assinatura_log('ERRO WEBHOOK: ' . $e->getMessage());
}

exit;