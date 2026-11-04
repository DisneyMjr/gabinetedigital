<?php
// Oculta erros do PHP que quebram o código Javascript
ini_set('display_errors', 0);
error_reporting(E_ALL);

require 'config.php';
header('Content-Type: application/json; charset=utf-8');

// --- SEGURANÇA ---
if (!defined('TENANT_ID')) {
    echo json_encode(['sucesso' => false, 'tem_enquete' => false, 'erro' => 'Tenant ID ausente']);
    exit;
}

function getColName($pdo, $tabela) {
    try {
        $q = $pdo->query("DESCRIBE $tabela");
        $cols = $q->fetchAll(PDO::FETCH_COLUMN);
        return in_array('id_enquete', $cols) ? 'id_enquete' : 'enquete_id';
    } catch (Exception $e) {
        return 'id_enquete';
    }
}

// Resgata os dados (seja por URL, POST ou JSON)
$acao = $_REQUEST['acao'] ?? '';
$id_opcao = $_REQUEST['id_opcao'] ?? null;
$id_enquete = $_REQUEST['id_enquete'] ?? null;

// ==========================================
// 1. PROCESSAR VOTO
// ==========================================
if ($acao === 'votar' || !empty($id_opcao)) {
    
    if (empty($id_opcao)) {
        echo json_encode(['sucesso' => false, 'erro' => 'Nenhuma opção foi recebida.']);
        exit;
    }

    try {
        $id_enquete = (int)$id_enquete;
        $id_opcao = (int)$id_opcao;
        $ip = $_SERVER['REMOTE_ADDR'];

        // Desvenda o nome das colunas para não dar erro
        $colVoto = getColName($pdo, 'enquetes_votos');
        
        $qColsOp = $pdo->query("DESCRIBE enquetes_votos");
        $colsVotoTab = $qColsOp->fetchAll(PDO::FETCH_COLUMN);
        $colOpcaoVoto = in_array('opcao_id', $colsVotoTab) ? 'opcao_id' : 'id_opcao';

        // Verifica se IP já votou
        $check = $pdo->prepare("SELECT id FROM enquetes_votos WHERE $colVoto = ? AND ip_voto = ? AND tenant_id = ?");
        $check->execute([$id_enquete, $ip, TENANT_ID]);

        if ($check->rowCount() == 0) {
            // Salva o Voto
            $sql = "INSERT INTO enquetes_votos (tenant_id, $colVoto, $colOpcaoVoto, ip_voto) VALUES (?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([TENANT_ID, $id_enquete, $id_opcao, $ip]);

            // Soma 1 na opção
            $pdo->prepare("UPDATE enquetes_opcoes SET votos = votos + 1 WHERE id = ? AND tenant_id = ?")->execute([$id_opcao, TENANT_ID]);

            echo json_encode(['sucesso' => true]);
        } else {
            echo json_encode(['sucesso' => false, 'erro' => 'Seu voto já foi registrado anteriormente.']);
        }
    } catch (Exception $e) {
        echo json_encode(['sucesso' => false, 'erro' => 'Erro interno na Tabela Votos.']);
    }
    
    exit; // Para a execução aqui para não misturar com o código de carregar!
}

// ==========================================
// 2. BUSCAR DADOS DA ENQUETE (CARREGAR TELA)
// ==========================================
try {
    // Nova busca: Só pega a enquete Ativa E que a data final seja HOJE ou no futuro (ou que não tenha validade = NULL)
    $stmt = $pdo->prepare("SELECT id, titulo FROM enquetes 
                           WHERE tenant_id = ? 
                           AND status = 'Ativa' 
                           AND (data_fim IS NULL OR data_fim >= CURDATE()) 
                           LIMIT 1");
    $stmt->execute([TENANT_ID]);
    $enquete = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$enquete) {
        echo json_encode(['tem_enquete' => false]);
        exit;
    }

    $id_enq = $enquete['id'];

    $colOpcao = getColName($pdo, 'enquetes_opcoes');
    $stmt_opt = $pdo->prepare("SELECT id, texto, votos FROM enquetes_opcoes WHERE $colOpcao = ? AND tenant_id = ? ORDER BY id ASC");
    $stmt_opt->execute([$id_enq, TENANT_ID]);
    $opcoes = $stmt_opt->fetchAll(PDO::FETCH_ASSOC);

    $colVoto = getColName($pdo, 'enquetes_votos');
    $ip = $_SERVER['REMOTE_ADDR'];
    
    $check = $pdo->prepare("SELECT id FROM enquetes_votos WHERE $colVoto = ? AND ip_voto = ? AND tenant_id = ?");
    $check->execute([$id_enq, $ip, TENANT_ID]);
    $ja_votou = ($check->rowCount() > 0);

    echo json_encode([
        'tem_enquete' => true,
        'ja_votou' => $ja_votou,
        'dados' => $enquete,
        'opcoes' => $opcoes,
        'total_votos' => array_sum(array_column($opcoes, 'votos'))
    ]);

} catch (Exception $e) {
    echo json_encode(['tem_enquete' => false, 'erro' => $e->getMessage()]);
}