<?php
// ajax_tse.php
require 'config.php';

$acao = $_GET['acao'] ?? '';

if ($acao == 'get_secoes') {
    $zona = $_GET['zona'] ?? '';
    
    // Busca as seções vinculadas àquela zona
    $stmt = $pdo->prepare("SELECT secao, nome_local, bairro FROM tse_locais_votacao WHERE zona = ? ORDER BY CAST(secao AS UNSIGNED) ASC");
    $stmt->execute([$zona]);
    $secoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($secoes);
    exit;
}
?>