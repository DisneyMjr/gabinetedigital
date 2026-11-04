<?php
// cron_sincronizar_assinaturas_mp.php
// Rode no cPanel 1x por dia. Ele sincroniza assinaturas MP antes do robô bloquear clientes.
// Comando sugerido: /usr/local/bin/php -q /home/SEU_USUARIO/public_html/cron_sincronizar_assinaturas_mp.php

require_once __DIR__ . '/config.php';
if (file_exists(__DIR__ . '/gateway.php')) { require_once __DIR__ . '/gateway.php'; }
require_once __DIR__ . '/assinatura_mp_lib.php';

set_time_limit(600);
header('Content-Type: text/plain; charset=utf-8');

try {
    if (!isset($pdo) || !$pdo instanceof PDO) throw new RuntimeException('PDO não disponível.');
    $token = mp_token_seguro($pdo);

    $sql = "SELECT id, nome, email_contato, plano, status, data_vencimento, txid_pagamento
            FROM tenants
            WHERE txid_pagamento IS NOT NULL
              AND txid_pagamento <> ''
              AND slug <> 'master'
            ORDER BY id DESC";
    $tenants = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $ok = 0; $falhas = 0;

    foreach ($tenants as $t) {
        $tenantId = (int)$t['id'];
        $assinaturaId = trim((string)$t['txid_pagamento']);
        if ($assinaturaId === '') continue;

        $res = mp_processar_preapproval($pdo, $assinaturaId, $token);
        if (!empty($res['ok'])) $ok++; else $falhas++;
        usleep(200000); // evita bater forte na API
    }

    $msg = "Sincronização Mercado Pago finalizada. OK={$ok} FALHAS={$falhas}";
    mp_assinatura_log($msg);
    echo $msg . PHP_EOL;
} catch (Throwable $e) {
    mp_assinatura_log('ERRO CRON: ' . $e->getMessage());
    echo 'ERRO: ' . $e->getMessage() . PHP_EOL;
}
