<?php
// Mercado Pago: renovacao apenas apos pagamento aprovado, com idempotencia no banco.
// Requer a tabela mp_pagamentos_processados do SQL incluido no pacote.
require_once __DIR__ . '/config.php';
if (file_exists(__DIR__ . '/gateway.php')) { require_once __DIR__ . '/gateway.php'; }
require_once __DIR__ . '/assinatura_mp_lib.php';

function mgd_mp_processar_pagamento($pdo, $id, $token) {
    if (!ctype_digit((string)$id)) throw new RuntimeException('ID de pagamento invalido.');
    $r = mp_api_get('https://api.mercadopago.com/v1/payments/' . rawurlencode((string)$id), $token);
    if ($r['code'] !== 200 || empty($r['json'])) throw new RuntimeException('Consulta pagamento falhou: HTTP ' . $r['code']);
    $pay = $r['json'];
    $status = strtolower((string)($pay['status'] ?? ''));
    if ($status !== 'approved') {
        mp_assinatura_log("Pagamento {$id} status={$status}; nao ativa.");
        return;
    }
    if (empty($pay['live_mode'])) {
        mp_assinatura_log("Pagamento {$id} nao e de producao; nao ativa.");
        return;
    }
    if (strtoupper((string)($pay['currency_id'] ?? '')) !== 'BRL' || (float)($pay['transaction_amount'] ?? 0) <= 0) {
        throw new RuntimeException('Pagamento com moeda ou valor invalido.');
    }
    $tenantId = mp_find_tenant_id($pdo, $pay);
    if ($tenantId <= 0) throw new RuntimeException("Pagamento {$id} sem tenant identificado.");
    // Apenas pagamentos explicitamente vinculados a um tenant/plano no metadata
    // ou external_reference, ou a assinatura vinculada ao tenant.
    $ref = (string)($pay['external_reference'] ?? '');
    $meta = is_array($pay['metadata'] ?? null) ? $pay['metadata'] : [];
    $preapproval = (string)($pay['preapproval_id'] ?? $pay['subscription_id'] ?? $meta['preapproval_id'] ?? '');
    $temReferencia = preg_match('/TENANT[_\-]'.preg_quote((string)$tenantId,'/').'(?=_|$)/i', $ref)
        || (int)($meta['tenant_id'] ?? 0) === $tenantId;
    if (!$temReferencia && $preapproval !== '') {
        $st = $pdo->prepare('SELECT id FROM tenants WHERE id=? AND txid_pagamento=? LIMIT 1');
        $st->execute([$tenantId, $preapproval]);
        $temReferencia = (bool)$st->fetchColumn();
    }
    if (!$temReferencia) throw new RuntimeException("Pagamento {$id} sem vinculo confiavel com tenant {$tenantId}.");
    // Para cobrancas recorrentes, a assinatura do pagamento deve ser a atualmente vinculada.
    // Evita aplicar pagamentos tardios de assinaturas antigas/canceladas sem conciliacao.
    if ($preapproval !== '') {
        $st = $pdo->prepare('SELECT txid_pagamento FROM tenants WHERE id=? LIMIT 1');
        $st->execute([$tenantId]);
        $atual = (string)$st->fetchColumn();
        if ($atual === '' || !hash_equals($atual, $preapproval)) {
            throw new RuntimeException("Pagamento {$id} de assinatura diferente da vinculada ao tenant {$tenantId}; requer conciliacao manual.");
        }
    }


    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id, status, data_vencimento FROM tenants WHERE id=? FOR UPDATE');
        $st->execute([$tenantId]);
        $tenant = $st->fetch(PDO::FETCH_ASSOC);
        if (!$tenant) throw new RuntimeException('Tenant inexistente.');
        $st = $pdo->prepare('INSERT IGNORE INTO mp_pagamentos_processados (payment_id, tenant_id, valor) VALUES (?,?,?)');
        $st->execute([(string)$id, $tenantId, (float)$pay['transaction_amount']]);
        if ($st->rowCount() === 0) {
            $pdo->commit();
            mp_assinatura_log("Pagamento {$id} ja aplicado; ignorando webhook repetido.");
            return;
        }
        $dias = (int)($meta['dias_liberacao'] ?? 31);
        $dias = max(1, min(366, $dias));
        $agora = time();
        $vencAtual = strtotime((string)($tenant['data_vencimento'] ?? ''));
        $base = ($vencAtual !== false && $vencAtual > $agora) ? $vencAtual : $agora;
        $venc = date('Y-m-d H:i:s', strtotime('+' . $dias . ' days', $base));
        $plano = isset($meta['plano_novo']) ? (string)$meta['plano_novo'] : null;
        mp_ativar_tenant($pdo, $tenantId, $venc, null, $plano);
        mp_registrar_fatura($pdo, $tenantId, $pay, 'pago');
        $pdo->commit();
        mp_assinatura_log("Pagamento {$id} aprovado; tenant {$tenantId} renovado ate {$venc}.");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) $payload = [];
$type = (string)($payload['type'] ?? $payload['topic'] ?? '');
$action = (string)($payload['action'] ?? '');
$id = (string)($payload['data']['id'] ?? $payload['id'] ?? $_GET['data_id'] ?? '');
if ($id === '' && !empty($payload['resource'])) {
    $id = basename(parse_url((string)$payload['resource'], PHP_URL_PATH));
}
try {
    if (!isset($pdo) || !($pdo instanceof PDO)) throw new RuntimeException('PDO indisponivel.');
    if ($id === '') throw new RuntimeException('Notificacao sem ID.');
    $token = mp_token_seguro($pdo);
    if ($type === 'payment' || strpos($action, 'payment.') === 0) {
        mgd_mp_processar_pagamento($pdo, $id, $token);
    } elseif ($type === 'subscription_authorized_payment' || strpos($action, 'authorized_payment') !== false) {
        // Apenas registrar: o evento payment aprovado fara a liberacao.
        mp_assinatura_log('Authorized payment recebido: ' . $id . '; sem renovar acesso.');
    } elseif ($type === 'subscription_preapproval' || strpos($action, 'preapproval') !== false) {
        mp_processar_preapproval($pdo, $id, $token);
    } else {
        mp_assinatura_log("Webhook ignorado: tipo={$type} action={$action} id={$id}");
    }
    http_response_code(200);
} catch (Throwable $e) {
    mp_assinatura_log('ERRO WEBHOOK: ' . $e->getMessage());
    // Erros temporarios podem ser reentregues pelo Mercado Pago.
    http_response_code(500);
}
exit;
