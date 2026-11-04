<?php
require_once 'config.php';
$txid = $_GET['txid'] ?? die('ID inválido');

// 1. Busca o cliente e descobre qual gateway ele usou
$stmt = $pdo->prepare("SELECT * FROM tenants WHERE txid_pagamento = ?");
$stmt->execute([$txid]);
$cliente = $stmt->fetch();

$gateway = $cliente['gateway_usado']; // Aqui ele descobre se é 'efi' ou 'vindi'

// 2. Chama o arquivo de configuração correto
if ($gateway === 'efi') {
    require_once 'efi_config.php';
    $dadosPix = consultarPixEfi($txid); 
} else {
    require_once 'vindi_config.php';
    $dadosPix = consultarPixVindi($txid);
}
?>