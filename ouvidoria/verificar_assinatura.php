<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'conexao.php';

if (!isset($_SESSION['tenant_id'])) { header('Location: login.php'); exit; }
$tenantId = (int)$_SESSION['tenant_id'];
$stmt = $pdo->prepare("SELECT data_expiracao, ativo, mp_subscription_status FROM tenants WHERE id = ?");
$stmt->execute([$tenantId]);
$empresa = $stmt->fetch();
if (!$empresa) { session_destroy(); header('Location: login.php'); exit; }

if ((int)$empresa['ativo'] === 0) {
    session_destroy();
    http_response_code(403);
    die("<div style='font-family:sans-serif;text-align:center;padding:60px 20px'><h1>Acesso suspenso</h1><p>O acesso desta instituição foi bloqueado pelo administrador.</p><a href='login.php'>Voltar ao login</a></div>");
}

$subscriptionStatus = $empresa['mp_subscription_status'] ?? null;
if ($subscriptionStatus === 'authorized') return;

// Compatibilidade com clientes antigos e períodos de teste.
if (!empty($empresa['data_expiracao'])) {
    $hoje = new DateTime('now');
    $validade = new DateTime($empresa['data_expiracao']);
    if ($hoje <= $validade) return;
}

header('Location: admin_assinatura.php');
exit;
