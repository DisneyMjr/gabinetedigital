<?php
// verificar_assinatura.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'conexao.php';

if (!isset($_SESSION['tenant_id'])) {
    header("Location: login.php");
    exit;
}

$tenant_id = $_SESSION['tenant_id'];

// Verifica a data de expiração no banco
$stmt = $pdo->prepare("SELECT data_expiracao FROM tenants WHERE id = ?");
$stmt->execute([$tenant_id]);
$empresa = $stmt->fetch();

if ($empresa) {
    $hoje = new DateTime();
    $validade = new DateTime($empresa['data_expiracao']);

    // SE VENCEU (Hoje é maior que a validade)
    if ($hoje > $validade) {
        // Redireciona para a tela de pagamento (bloqueio)
        header("Location: pagamento.php");
        exit;
    }
}
?>