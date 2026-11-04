<?php
require 'config.php';
header('Content-Type: application/json');

// Recebe os dados do JavaScript
$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['id']) && isset($data['status']) && defined('TENANT_ID')) {
    try {
        $stmt = $pdo->prepare("UPDATE solicitacoes SET status = ? WHERE id = ? AND tenant_id = ?");
        $sucesso = $stmt->execute([$data['status'], $data['id'], TENANT_ID]);
        
        if ($sucesso && function_exists('registrar_log')) {
            registrar_log($pdo, 'Movimentação Kanban', "Solicitação #{$data['id']} movida para {$data['status']}");
        }

        echo json_encode(['sucesso' => true]);
    } catch (Exception $e) {
        echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]);
    }
} else {
    echo json_encode(['sucesso' => false, 'erro' => 'Dados incompletos']);
}