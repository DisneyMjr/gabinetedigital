<?php
session_start();
require 'mercadopago.php';
header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['tenant_id'])) {
    http_response_code(401);
    echo json_encode(['erro' => 'Sessão inválida.']);
    exit;
}
$tenantId = (int)$_SESSION['tenant_id'];

$stmt = $pdo->prepare("SELECT id, nome_empresa, mp_subscription_id, mp_subscription_status FROM tenants WHERE id = ? LIMIT 1");
$stmt->execute([$tenantId]);
$tenant = $stmt->fetch();
if (!$tenant) {
    http_response_code(404);
    echo json_encode(['erro' => 'Empresa não encontrada.']);
    exit;
}

$stmt = $pdo->prepare("SELECT email, nome FROM users WHERE tenant_id = ? AND (perfil = 'admin' OR nivel_acesso = 'admin') ORDER BY id ASC LIMIT 1");
$stmt->execute([$tenantId]);
$user = $stmt->fetch();
if (!$user || empty($user['email'])) {
    http_response_code(422);
    echo json_encode(['erro' => 'Cadastre um e-mail válido no usuário administrador antes de assinar.']);
    exit;
}

$cfg = mp_configs($pdo);
$amount = (float)($cfg['valor_mensalidade'] ?? 97.00);
$appUrl = rtrim($cfg['app_url'] ?? '', '/');
if (!$appUrl) {
    http_response_code(422);
    echo json_encode(['erro' => 'Configure a URL pública do sistema no Painel SaaS antes de criar a assinatura.']);
    exit;
}

// Reaproveita assinatura pendente/ativa existente.
if (!empty($tenant['mp_subscription_id'])) {
    $existing = mp_api($pdo, 'GET', '/preapproval/' . rawurlencode($tenant['mp_subscription_id']));
    if ($existing['ok']) {
        $sub = $existing['data'];
        mp_update_tenant_subscription($pdo, $tenantId, $sub);
        if (($sub['status'] ?? '') === 'authorized') {
            echo json_encode(['status' => 'authorized', 'mensagem' => 'Sua assinatura já está ativa.']);
            exit;
        }
        if (!empty($sub['init_point']) && in_array(($sub['status'] ?? ''), ['pending', 'paused'], true)) {
            echo json_encode(['status' => $sub['status'], 'checkout_url' => $sub['init_point'], 'subscription_id' => $sub['id']]);
            exit;
        }
    }
}

$payload = [
    'reason' => ($cfg['nome_sistema'] ?? 'Ouvidoria SaaS') . ' - Plano mensal',
    'external_reference' => (string)$tenantId,
    'payer_email' => $user['email'],
    'auto_recurring' => [
        'frequency' => 1,
        'frequency_type' => 'months',
        'transaction_amount' => round($amount, 2),
        'currency_id' => 'BRL'
    ],
    'back_url' => $appUrl . '/retorno_assinatura.php',
    'status' => 'pending'
];

$result = mp_api($pdo, 'POST', '/preapproval', $payload);
if (!$result['ok']) {
    http_response_code(502);
    echo json_encode(['erro' => $result['error'], 'detalhes' => $result['data']]);
    exit;
}

$sub = $result['data'];
mp_update_tenant_subscription($pdo, $tenantId, $sub);

echo json_encode([
    'status' => $sub['status'] ?? 'pending',
    'checkout_url' => $sub['init_point'] ?? null,
    'subscription_id' => $sub['id'] ?? null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
