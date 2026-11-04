<?php
require_once __DIR__ . '/conexao.php';

function mp_configs(PDO $pdo): array {
    try {
        return $pdo->query("SELECT chave, valor FROM system_config")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function mp_cfg(PDO $pdo, string $key, string $default = ''): string {
    $cfg = mp_configs($pdo);
    return isset($cfg[$key]) ? trim((string)$cfg[$key]) : $default;
}

function mp_access_token(PDO $pdo): string {
    return mp_cfg($pdo, 'mp_access_token', (string)cfg('MP_ACCESS_TOKEN', 'mp_access_token', ''));
}

function mp_base_url(PDO $pdo): string {
    $base = mp_cfg($pdo, 'app_url', (string)cfg('OUV_APP_URL', 'app_url', ''));
    return rtrim($base, '/');
}

function mp_api(PDO $pdo, string $method, string $path, ?array $body = null): array {
    $token = mp_access_token($pdo);
    if (!$token) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Access Token do Mercado Pago não configurado.'];
    }

    $ch = curl_init('https://api.mercadopago.com' . $path);
    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: application/json'
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'status' => $status, 'data' => null, 'error' => $curlError ?: 'Falha na comunicação com Mercado Pago.'];
    }

    $data = json_decode($raw, true);
    if ($status >= 200 && $status < 300) {
        return ['ok' => true, 'status' => $status, 'data' => is_array($data) ? $data : [], 'error' => null];
    }

    $message = $data['message'] ?? $data['error'] ?? 'Erro retornado pelo Mercado Pago.';
    return ['ok' => false, 'status' => $status, 'data' => is_array($data) ? $data : [], 'error' => (string)$message];
}

function mp_subscription_status_label(?string $status): string {
    return match ((string)$status) {
        'authorized' => 'Ativa',
        'pending' => 'Aguardando pagamento',
        'paused' => 'Pausada',
        'cancelled', 'canceled' => 'Cancelada',
        default => $status ? ucfirst($status) : 'Sem assinatura',
    };
}

function mp_update_tenant_subscription(PDO $pdo, int $tenantId, array $subscription): void {
    $status = (string)($subscription['status'] ?? '');
    $nextPayment = $subscription['next_payment_date'] ?? null;
    $payerEmail = $subscription['payer_email'] ?? null;
    $subId = $subscription['id'] ?? null;

    $stmt = $pdo->prepare("UPDATE tenants SET
        mp_subscription_id = COALESCE(?, mp_subscription_id),
        mp_subscription_status = ?,
        mp_payer_email = COALESCE(?, mp_payer_email),
        mp_next_payment_date = ?,
        mp_subscription_updated_at = NOW(),
        ativo = CASE WHEN ? = 'authorized' THEN 1 ELSE ativo END
        WHERE id = ?");
    $stmt->execute([$subId, $status, $payerEmail, $nextPayment, $status, $tenantId]);
}

function mp_validate_webhook_signature(PDO $pdo): bool {
    $secret = mp_cfg($pdo, 'mp_webhook_secret', (string)cfg('MP_WEBHOOK_SECRET', 'mp_webhook_secret', ''));
    if ($secret === '') return true;

    $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
    $requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
    if (!$signature || !$requestId) return false;

    $parts = [];
    foreach (explode(',', $signature) as $piece) {
        $kv = explode('=', trim($piece), 2);
        if (count($kv) === 2) $parts[$kv[0]] = $kv[1];
    }
    $ts = $parts['ts'] ?? '';
    $v1 = $parts['v1'] ?? '';
    if (!$ts || !$v1) return false;

    $dataId = $_GET['data_id'] ?? $_GET['data.id'] ?? ($GLOBALS['MP_WEBHOOK_DATA_ID'] ?? '');
    if (!$dataId) {
        $payload = json_decode(file_get_contents('php://input'), true);
        $dataId = $payload['data']['id'] ?? '';
    }
    if (!$dataId) return false;

    $manifest = 'id:' . strtolower((string)$dataId) . ';request-id:' . $requestId . ';ts:' . $ts . ';';
    $expected = hash_hmac('sha256', $manifest, $secret);
    return hash_equals($expected, $v1);
}
