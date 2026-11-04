<?php
session_start();
require 'config.php';
require_once __DIR__ . '/gateway.php';

// --- SEGURANÇA ---
if (!defined('TENANT_ID')) die("Acesso inválido.");
if (!isset($_GET['plano'])) die("Plano não informado.");

$token = mp_access_token($pdo);

// 1. BUSCA DADOS DO PLANO
$slug_plano = $_GET['plano'];
$stmt = $pdo->prepare("SELECT * FROM planos WHERE slug = ?");
$stmt->execute([$slug_plano]);
$plano = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$plano) die("Plano não encontrado.");

// 2. BUSCA DADOS DO USUÁRIO LOGADO (para evitar 403 em alguns cenários)
$email_pagador = "cliente_" . uniqid() . "@exemplo.com";
$nome_pagador = "Cliente";

if (isset($_SESSION['id'])) {
    // Ajuste conforme sua tabela real de usuários
    $stmtU = $pdo->prepare("SELECT nome, email FROM usuarios WHERE id = ?");
    $stmtU->execute([$_SESSION['id']]);
    $user = $stmtU->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        $email_pagador = $user['email'];
        $nome_pagador = $user['nome'];
    }
}

// 3. PREPARAÇÃO DOS DADOS (JSON)
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

$dados_pagamento = [
    "items" => [[
        "title" => "Plano " . $plano['nome'],
        "quantity" => 1,
        "currency_id" => "BRL",
        "unit_price" => (float)$plano['preco']
    ]],
    "payer" => [
        "name" => $nome_pagador,
        "email" => $email_pagador
    ],
    "back_urls" => [
        "success" => $scheme . $host . "/admin_financeiro.php?status=sucesso",
        "failure" => $scheme . $host . "/admin_financeiro.php?status=erro",
        "pending" => $scheme . $host . "/admin_financeiro.php?status=pendente"
    ],
    "auto_return" => "approved",
    "metadata" => [
        "tenant_id" => (int)TENANT_ID,
        "plano_novo" => $plano['slug'],
        "dias_liberacao" => 30
    ],
    "notification_url" => $scheme . $host . "/webhook_mp.php"
];

// 4. ENVIO PARA API (CURL)
$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => "https://api.mercadopago.com/checkout/preferences",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CUSTOMREQUEST => "POST",
    CURLOPT_POSTFIELDS => json_encode($dados_pagamento),
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer " . $token,
        "Content-Type: application/json"
    ],
]);

$response = curl_exec($curl);
$err = curl_error($curl);
curl_close($curl);

if ($err) {
    die("Erro de conexão: " . $err);
}

$mp = json_decode((string)$response, true);

if (!empty($mp['init_point'])) {
    header("Location: " . $mp['init_point']);
    exit;
}

echo "<div style='padding:20px; background:#ffe6e6; border:1px solid red; font-family:sans-serif;'>";
echo "<h3>Ops! O Mercado Pago recusou gerar o link.</h3>";
echo "<p><strong>Motivo:</strong> " . ($mp['message'] ?? 'Erro desconhecido') . "</p>";
echo "<pre>";
print_r($mp);
echo "</pre></div>";
