<?php
// mercadopago_recorrente.php
require_once __DIR__ . '/mp_helpers.php';

function criarAssinaturaSaaS(
    PDO $pdo,
    int $tenant_id,
    string $nome,
    string $email,
    float $valor,
    string $plano_nome,
    ?string $plano_mp_id = null
): array {

    if (!function_exists('mp_access_token')) {
        require_once __DIR__ . '/gateway.php';
    }

    if ($tenant_id <= 0) {
        return [
            'erro' => 'tenant_id inválido para Mercado Pago recorrente.'
        ];
    }

    $email = trim($email);

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [
            'erro' => 'Informe um e-mail válido para criar a assinatura.'
        ];
    }

    try {
        $access_token = mp_access_token($pdo);
    } catch (Throwable $e) {

        mp_safe_log(
            'criar_assinatura_token_erro',
            $e->getMessage()
        );

        return [
            'erro' => 'Mercado Pago não configurado corretamente. Verifique o Access Token.'
        ];
    }

    $base = app_base_url();

    $back_url =
        $base .
        '/sucesso_pagamento.php?id=' .
        $tenant_id;

    $notification_url =
        $base .
        '/webhook_mp.php';

    /*
     * IMPORTANTE:
     *
     * O Mercado Pago exige payer_email para criar
     * a assinatura recorrente.
     *
     * Esse e-mail NÃO deve ser usado para identificar
     * o tenant posteriormente.
     *
     * A identificação será feita pelo
     * external_reference e tenant_id.
     */

    $data = [
        'reason' => 'Assinatura: ' . $plano_nome,

        'external_reference' =>
            'TENANT_' . $tenant_id,

        'payer_email' => $email,

        'back_url' => $back_url,

        'notification_url' => $notification_url
    ];

    /*
     * Se existir um plano previamente criado
     * no Mercado Pago, utiliza o Plan ID.
     */
    if (!empty($plano_mp_id)) {

        $data['preapproval_plan_id'] =
            $plano_mp_id;

    } else {

        /*
         * Caso contrário cria uma assinatura
         * recorrente diretamente.
         */
        $data['auto_recurring'] = [
            'frequency' => 1,
            'frequency_type' => 'months',
            'transaction_amount' =>
                round((float)$valor, 2),
            'currency_id' => 'BRL'
        ];
    }

    mp_safe_log(
        'criar_assinatura_payload',
        [
            'tenant_id' => $tenant_id,
            'email' => $email,
            'valor' => $valor,
            'plano' => $plano_nome,
            'notification_url' => $notification_url
        ]
    );

    $r = mp_api_request(
        'POST',
        'https://api.mercadopago.com/preapproval',
        $access_token,
        $data
    );

    /*
     * Assinatura criada.
     */
    if (
        $r['ok'] &&
        !empty($r['json']['init_point']) &&
        !empty($r['json']['id'])
    ) {

        $assinatura_id =
            (string)$r['json']['id'];

        try {

            $stmt = $pdo->prepare("
                UPDATE tenants
                SET
                    txid_pagamento = ?,
                    status = 'aguardando_pagamento',
                    ativo = 0
                WHERE id = ?
            ");

            $stmt->execute([
                $assinatura_id,
                $tenant_id
            ]);

        } catch (Throwable $e) {

            mp_safe_log(
                'salvar_assinatura_id_erro',
                $e->getMessage()
            );
        }

        return [
            'checkout_link' =>
                $r['json']['init_point'],

            'assinatura_id' =>
                $assinatura_id
        ];
    }

    /*
     * Mercado Pago recusou a criação.
     */
    mp_safe_log(
        'criar_assinatura_erro',
        [
            'http_code' => $r['code'],
            'error' => $r['error'],
            'response' =>
                $r['json'] ?: $r['raw'],
            'tenant_id' => $tenant_id
        ]
    );

    return [
        'erro' =>
            'Mercado Pago não criou a assinatura.',

        'http_code' =>
            $r['code'],

        'detalhes' =>
            $r['json'] ?: $r['raw']
    ];
}