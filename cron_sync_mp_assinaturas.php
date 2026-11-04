<?php
/**
 * cron_sync_mp_assinaturas.php
 * Sincroniza assinaturas salvas no tenants.txid_pagamento com o Mercado Pago.
 * Rode 1x ao dia pelo Cron do cPanel.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mp_helpers.php';
require_once __DIR__ . '/gateway.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $token = mp_access_token($pdo);
    $stmt = $pdo->query("SELECT id, nome, txid_pagamento FROM tenants WHERE txid_pagamento IS NOT NULL AND txid_pagamento <> '' AND plano <> 'master' ORDER BY id DESC LIMIT 500");
    $total = 0;
    $ok = 0;

    while ($t = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $total++;
        $subId = trim((string)$t['txid_pagamento']);
        if ($subId === '' || preg_match('/^[0-9]+$/', $subId)) continue; // ignora ids de pagamentos avulsos numéricos

        $r = mp_api_request('GET', 'https://api.mercadopago.com/preapproval/' . urlencode($subId), $token);
        if (!$r['ok']) {
            mp_safe_log('cron_sync_erro', ['tenant_id' => $t['id'], 'sub_id' => $subId, 'code' => $r['code'], 'response' => $r['json'] ?: $r['raw']]);
            continue;
        }

        $status = (string)($r['json']['status'] ?? '');
        $next = $r['json']['next_payment_date'] ?? null;
        if (mp_update_tenant_subscription($pdo, (int)$t['id'], $status, $subId, $next ? (string)$next : null)) {
            $ok++;
        }
    }

    echo "Sincronização concluída. Verificados: {$total}. Atualizados: {$ok}.\n";
} catch (Throwable $e) {
    mp_safe_log('cron_sync_exception', $e->getMessage());
    echo "Erro: " . $e->getMessage() . "\n";
}
