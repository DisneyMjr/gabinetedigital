<?php
// api_pagamento.php - GERA O PIX NO MERCADO PAGO
session_start();
require 'conexao.php';

header('Content-Type: application/json');

if (!isset($_SESSION['tenant_id'])) {
    echo json_encode(['erro' => 'Sessão inválida']);
    exit;
}

$tenant_id = $_SESSION['tenant_id'];

// 1. Busca Configurações (Token e Valor)
$configs = $pdo->query("SELECT chave, valor FROM system_config")->fetchAll(PDO::FETCH_KEY_PAIR);
$access_token = $configs['mp_access_token'] ?? '';
$valor = (float)($configs['valor_mensalidade'] ?? 97.00);

if (empty($access_token)) {
    echo json_encode(['erro' => 'Token do Mercado Pago não configurado no Admin.']);
    exit;
}

// 2. Busca Email do Admin da Empresa (Para o MP enviar comprovante)
$stmt = $pdo->prepare("SELECT email, nome FROM users WHERE tenant_id = ? AND perfil = 'admin' LIMIT 1");
$stmt->execute([$tenant_id]);
$user = $stmt->fetch();
$email_pagador = $user['email'] ?? 'cliente@email.com';
$nome_pagador = $user['nome'] ?? 'Cliente SaaS';

// 3. Monta o Pedido para o Mercado Pago
$dados = [
    "transaction_amount" => $valor,
    "description" => "Assinatura Mensal - Ouvidoria SaaS",
    "payment_method_id" => "pix",
    "payer" => [
        "email" => $email_pagador,
        "first_name" => $nome_pagador
    ],
    "external_reference" => (string)$tenant_id, // O SEGREDINHO: Enviamos o ID da empresa escondido
    "notification_url" => "https://SEU-SITE.com/webhook_mp.php" // <--- IMPORTANTE: PRECISA SER URL PÚBLICA (HTTPS)
];

// 4. Envia via cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.mercadopago.com/v1/payments");
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dados));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $access_token",
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$resposta = curl_exec($ch);
curl_close($ch);

$json = json_decode($resposta, true);

if (isset($json['id'])) {
    echo json_encode([
        'copia_cola' => $json['point_of_interaction']['transaction_data']['qr_code'],
        'qr_code_base64' => $json['point_of_interaction']['transaction_data']['qr_code_base64'],
        'payment_id' => $json['id']
    ]);
} else {
    echo json_encode(['erro' => 'Erro ao gerar Pix no Mercado Pago', 'debug' => $json]);
}
?>