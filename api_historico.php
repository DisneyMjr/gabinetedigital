<?php
// api_historico.php
header('Content-Type: application/json');
require 'config.php';
require 'protecao.php';

if (!defined('TENANT_ID')) { echo json_encode([]); exit; }

$contato = $_GET['contato'] ?? '';

if(empty($contato)) { echo json_encode([]); exit; }

// Limpa o contato para comparar apenas números (se for telefone)
$contato_limpo = preg_replace('/[^0-9]/', '', $contato);

try {
    // Busca por WhatsApp OU E-mail OU Nome exato
    $sql = "SELECT id, tipo, descricao, status, criado_em, protocolo 
            FROM solicitacoes 
            WHERE tenant_id = :tenant 
            AND (whatsapp LIKE :zap OR email = :email OR nome_cidadao = :nome)
            ORDER BY id DESC";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':tenant' => TENANT_ID,
        ':zap'    => "%$contato_limpo%",
        ':email'  => $contato,
        ':nome'   => $contato // Caso busque pelo nome
    ]);
    
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));

} catch (Exception $e) {
    echo json_encode(['erro' => $e->getMessage()]);
}