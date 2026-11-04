<?php
// webhook_simulado.php
// Esse arquivo simula o que o sistema faria ao receber a confirmação do PIX
session_start();
require 'conexao.php';

if (!isset($_SESSION['tenant_id'])) {
    die("Erro: Nenhuma empresa logada.");
}

$tenant_id = $_SESSION['tenant_id'];

// ADICIONA 30 DIAS À DATA DE HOJE (Renovação)
$sql = "UPDATE tenants SET data_expiracao = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$tenant_id]);

// Redireciona de volta para o admin com sucesso
header("Location: admin.php?status=renovado");
exit;
?>