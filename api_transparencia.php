<?php
require_once 'config.php';
header('Content-Type: application/json');

if (!defined('TENANT_ID')) { echo json_encode([]); exit; }

try {
    // Busca os últimos 6 lançamentos da transparência (ou o que você definiu como despesa)
    $stmt = $pdo->prepare("SELECT titulo, categoria, valor, data_despesa, arquivo 
                           FROM transparencia 
                           WHERE tenant_id = ? 
                           ORDER BY data_despesa DESC LIMIT 6");
    $stmt->execute([TENANT_ID]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo json_encode([]);
}
