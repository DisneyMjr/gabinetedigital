<?php
/**
 * Diagnóstico somente leitura das assinaturas Mercado Pago — tenant 81.
 * Executar SOMENTE por CLI no cPanel, nunca por URL pública.
 * Exemplo: /usr/local/bin/php -q /home/USUARIO/public_html/diagnostico_assinaturas_mp.php
 * Remover o arquivo depois do diagnóstico.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/assinatura_mp_lib.php';

$tenantId = 81;
$ids = [
    'assinatura_em_atraso_interface' => '219dcf9e1f1ddcfeeb3d6c3458bf2f36d563ebb2',
    'assinatura_cancelada_interface' => '8531219ddd18caae94c7282f4eb838e715c6cfd3',
    'assinatura_registrada_banco' => 'f36e8ea946e948989a669914b87e4ba4',
    'assinatura_log_setembro' => 'c7dfc5868e194231898e2b70e4fbf579',
];

function consultaMpSomenteLeitura(string $url, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
    ]);
    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode((string)$raw, true);
    return ['http' => $http, 'error' => $error, 'data' => is_array($json) ? $json : []];
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) throw new RuntimeException('Conexão PDO indisponível.');
    if (!function_exists('mp_token_seguro')) throw new RuntimeException('Função mp_token_seguro indisponível.');
    $token = mp_token_seguro($pdo);
    if (!is_string($token) || trim($token) === '') throw new RuntimeException('Token MP indisponível.');

    $stmt = $pdo->prepare('SELECT id, status, ativo, data_vencimento, txid_pagamento FROM tenants WHERE id = ? LIMIT 1');
    $stmt->execute([$tenantId]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tenant) throw new RuntimeException('Tenant não encontrado.');

    echo "DIAGNÓSTICO MERCADO PAGO (SOMENTE LEITURA)\n";
    echo "Tenant: {$tenantId}\n";
    echo 'Status local: ' . ($tenant['status'] ?? '') . ' | ativo=' . ($tenant['ativo'] ?? '') . ' | vencimento=' . ($tenant['data_vencimento'] ?? '') . "\n";
    echo 'ID vinculado: ' . ($tenant['txid_pagamento'] ?? '') . "\n\n";

    foreach ($ids as $label => $id) {
        echo "=== {$label} ===\n";
        echo "ID consultado: {$id}\n";
        $result = consultaMpSomenteLeitura('https://api.mercadopago.com/preapproval/' . rawurlencode($id), $token);
        echo 'HTTP: ' . $result['http'] . ($result['error'] ? ' | Erro cURL: ' . $result['error'] : '') . "\n";
        $d = $result['data'];
        if ($result['http'] >= 200 && $result['http'] < 300) {
            foreach (['id', 'status', 'external_reference', 'reason', 'date_created', 'next_payment_date', 'last_modified'] as $key) {
                echo $key . ': ' . (isset($d[$key]) && is_scalar($d[$key]) ? (string)$d[$key] : '(não informado)') . "\n";
            }
            $expected = 'TENANT_' . $tenantId;
            $ref = (string)($d['external_reference'] ?? '');
            echo 'Referência tenant 81: ' . (preg_match('/(?:^|[^A-Z0-9])TENANT_81(?:[^0-9]|$)/i', $ref) ? 'COMPATÍVEL' : 'NÃO CONFIRMADA') . "\n";
        } else {
            echo 'Erro API: ' . (string)($d['message'] ?? $d['error'] ?? 'sem detalhes') . "\n";
        }
        echo "\n";
        usleep(300000);
    }
    echo "Nenhum dado foi alterado. Remova este arquivo após o uso.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . "\n");
    exit(1);
}
