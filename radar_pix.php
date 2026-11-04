<?php
header('Content-Type: application/json');
require_once 'config.php';

$payment_id = $_GET['txid'] ?? '';
$access_token = "APP_USR-1441107200484773-020117-fb1f5a56566ea69254e34ec3ff333945-607766213"; // Certifique-se que este token é o mesmo do gateway.php

if (empty($payment_id)) exit(json_encode(['status' => 'PENDENTE']));

$ch = curl_init("https://api.mercadopago.com/v1/payments/" . $payment_id);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $access_token]);
$response = json_decode(curl_exec($ch), true);
curl_close($ch);

// O status 'approved' é o gatilho para destravar o sistema
if (isset($response['status']) && $response['status'] === 'approved') {
    
    // ATUALIZAÇÃO TRIPLA: Status, Ativo e Data
    $stmt = $pdo->prepare("
        UPDATE tenants 
        SET status = 'ativo', 
            ativo = 1, 
            data_vencimento = DATE_ADD(NOW(), INTERVAL 30 DAY) 
        WHERE txid_pagamento = ?
    ");
    $stmt->execute([$payment_id]);

    echo json_encode(['status' => 'CONCLUIDA']);
    exit;
}

echo json_encode(['status' => 'PENDENTE']);