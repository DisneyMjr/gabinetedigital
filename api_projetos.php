<?php
// api_projetos.php
header('Content-Type: application/json');
require 'config.php';

// O config.php já define o TENANT_ID
if (!defined('TENANT_ID')) {
    echo json_encode([]);
    exit;
}

try {
    // Busca os últimos 6 projetos aprovados ou em tramitação
    $sql = "SELECT titulo, tipo, numero, ano, status, resumo, arquivo_pdf, data_criacao 
            FROM projetos_lei 
            WHERE tenant_id = ? 
            ORDER BY data_criacao DESC LIMIT 6";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([TENANT_ID]);
    $projetos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($projetos);

} catch (Exception $e) {
    echo json_encode([]);
}
?>