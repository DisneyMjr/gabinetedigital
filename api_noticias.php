<?php
require 'config.php';
// O config.php carrega o saas.php e define a constante TENANT_ID

header('Content-Type: application/json; charset=utf-8');

// --- SEGURANÇA SAAS ---
if (!defined('TENANT_ID')) {
    // Se não identificar o cliente, retorna lista vazia
    echo json_encode([]);
    exit;
}

try {
    // --- CONSULTA FILTRADA PELO TENANT E PELA DATA ---
    // Adicionei: AND data_publicacao <= NOW()
    $sql = "SELECT * FROM noticias 
            WHERE tenant_id = ? 
            AND data_publicacao <= NOW() 
            ORDER BY data_publicacao DESC 
            LIMIT 3";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([TENANT_ID]);
    
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));

} catch(Exception $e) { 
    echo json_encode([]); 
}
?>