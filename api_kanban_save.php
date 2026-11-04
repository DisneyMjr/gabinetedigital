<?php
require 'config.php';

// EXCLUIR
if (isset($_GET['excluir']) && defined('TENANT_ID')) {
    $stmt = $pdo->prepare("DELETE FROM solicitacoes WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$_GET['excluir'], TENANT_ID]);
    header("Location: admin_kanban.php");
    exit;
}

// SALVAR OU EDITAR
if ($_SERVER['REQUEST_METHOD'] == 'POST' && defined('TENANT_ID')) {
    $id = $_POST['id'];
    $nome = $_POST['nome_cidadao'];
    $zap = $_POST['whatsapp'];
    $tipo = $_POST['tipo'];
    $bairro = $_POST['bairro'];
    $desc = $_POST['descricao'];
    $status = $_POST['status'];

    if (empty($id)) {
        // NOVO
        $sql = "INSERT INTO solicitacoes (tenant_id, nome_cidadao, whatsapp, tipo, bairro, descricao, status) VALUES (?,?,?,?,?,?,?)";
        $pdo->prepare($sql)->execute([TENANT_ID, $nome, $zap, $tipo, $bairro, $desc, $status]);
    } else {
        // EDITAR
        $sql = "UPDATE solicitacoes SET nome_cidadao=?, whatsapp=?, tipo=?, bairro=?, descricao=? WHERE id=? AND tenant_id=?";
        $pdo->prepare($sql)->execute([$nome, $zap, $tipo, $bairro, $desc, $id, TENANT_ID]);
    }

    header("Location: admin_kanban.php");
    exit;
}