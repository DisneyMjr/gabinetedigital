<?php
// webhook_efi.php - Recebedor Silencioso de Pagamentos Efí
require 'config.php';

// A Efí manda os dados em JSON
$json = file_get_contents('php://input');
$dados = json_decode($json, true);

// A Efí exige que retornemos código 200 no momento da configuração
if (empty($dados) || !isset($dados['pix'])) {
    http_response_code(200);
    exit;
}

// Se chegou até aqui, o cliente pagou!
foreach ($dados['pix'] as $pagamento) {
    $txid = $pagamento['txid'];
    
    // Procura no banco qual cliente tem esse Pix pendente
    $stmt = $pdo->prepare("SELECT id FROM tenants WHERE txid_pagamento = ? LIMIT 1");
    $stmt->execute([$txid]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($tenant) {
        $id_tenant = $tenant['id'];

        // Destranca o sistema e dá mais 30 dias!
        $sql_renovar = "UPDATE tenants 
                        SET status = 'ativo', 
                            ativo = 1, 
                            data_vencimento = DATE_ADD(NOW(), INTERVAL 30 DAY) 
                        WHERE id = ?";
        $pdo->prepare($sql_renovar)->execute([$id_tenant]);
    }
}

// Confirma o recebimento para a Efí
http_response_code(200);
?>