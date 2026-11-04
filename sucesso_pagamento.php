<?php
// sucesso_pagamento.php - retorno seguro após checkout/assinatura
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mp_helpers.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$slug = '';
if ($id > 0) {
    $stmt = $pdo->prepare("SELECT slug FROM tenants WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $slug = (string)$stmt->fetchColumn();
}

$host = preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? '');
$link = $slug ? ('https://' . $slug . '.' . $host . '/login.php') : (app_base_url() . '/login.php');
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pagamento recebido</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-success text-white d-flex align-items-center justify-content-center vh-100 text-center">
    <div class="container" style="max-width: 680px;">
        <h1 class="display-1">🎉</h1>
        <h2 class="fw-bold mb-3">Pagamento recebido!</h2>
        <p class="lead mb-4">Estamos confirmando a assinatura pelo Mercado Pago. Normalmente a liberação é automática pelo webhook.</p>
        <p class="mb-5">Se o painel ainda aparecer como pendente, aguarde alguns instantes e atualize a página.</p>
        <a href="<?= htmlspecialchars($link) ?>" class="btn btn-light btn-lg text-success fw-bold rounded-pill px-5">Acessar meu painel</a>
    </div>
</body>
</html>
