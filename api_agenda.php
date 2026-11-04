<?php
// Desativa a exibição de erros que podem sujar o JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

require 'config.php';

// Limpa qualquer texto que tenha sido impresso por erro em outros arquivos
if (ob_get_length()) ob_clean();

header('Content-Type: application/json; charset=utf-8');

if (!defined('TENANT_ID')) {
    echo json_encode([]);
    exit;
}

try {
    // Consulta simplificada para teste
    $sql = "SELECT titulo, local, data_evento, hora_evento 
            FROM agenda 
            WHERE tenant_id = ? 
            AND data_evento >= CURDATE() 
            ORDER BY data_evento ASC, hora_evento ASC 
            LIMIT 4";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([TENANT_ID]);
    
    $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($dados ?: []);

} catch(Exception $e) { 
    // Se der erro de banco, envia o erro no formato JSON para você ver no console
    echo json_encode(["erro_debug" => $e->getMessage()]); 
}
exit;