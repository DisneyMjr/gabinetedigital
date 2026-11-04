<?php
// PROTECAO.PHP - V.FINAL (BLINDAGEM DE SESSÃO)
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 1. PRIMEIRA VERIFICAÇÃO: QUEM É VOCÊ?
// Se não tem ID de usuário, manda pro login AGORA, não verifica mais nada.
if (!isset($_SESSION['id']) || !isset($_SESSION['tenant_id'])) {
    if (basename($_SERVER['PHP_SELF']) !== 'login.php') {
        header("Location: login.php");
        exit;
    }
}

// 2. CONFIGURAÇÃO (Após garantir que tem sessão)
if (!defined('TENANT_ID')) {
    http_response_code(403);
    die("Erro Crítico: Identificação do Cliente (TENANT_ID) não encontrada.");
}

// 3. SEGURANÇA CROSS-TENANT
$permissoes = $_SESSION['permissoes'] ?? [];
$eh_master  = in_array('master', $permissoes, true);

if ((string)$_SESSION['tenant_id'] !== (string)TENANT_ID && !$eh_master) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// 4. VERIFICAÇÃO DE VENCIMENTO (Apenas se estiver logado e não for Master)
$uri_atual = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$paginas_liberadas = ['renovar_conta', 'renovar_conta.php', 'assinar', 'assinar.php', 'login', 'login.php', 'logout', 'logout.php'];

if (!$eh_master && !in_array($uri_atual, $paginas_liberadas)) {
    global $pdo;
    $stmtV = $pdo->prepare("SELECT status, data_vencimento FROM tenants WHERE id = ?");
    $stmtV->execute([TENANT_ID]);
    $dados = $stmtV->fetch(PDO::FETCH_ASSOC);
    
    if ($dados && ($dados['status'] === 'bloqueado' || strtotime($dados['data_vencimento']) < time())) {
        header("Location: /renovar_conta.php");
        exit;
    }
}
?>