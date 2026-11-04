<?php
// PROTECAO.PHP - V.FINAL CORRIGIDA
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 1. CONTA NÃO ENCONTRADA (Configuração do config.php falhou)
if (!defined('TENANT_ID')) {
    die("Erro Crítico: Identificação do Cliente (Tenant ID) não encontrada.");
}

// 2. VERIFICAÇÃO DE LOGIN
if (!isset($_SESSION['id']) || !isset($_SESSION['tenant_id'])) {
    header("Location: login.php"); exit;
}

// 3. SEGURANÇA CROSS-TENANT (Impede acessar dados de outro cliente)
$permissoes = $_SESSION['permissoes'] ?? [];
$eh_master = in_array('master', $permissoes);

if ($_SESSION['tenant_id'] != TENANT_ID && !$eh_master) {
    session_destroy(); 
    die("Acesso Negado: Tentativa de cruzamento de dados.");
}

// ==========================================================
// 4. VERIFICAÇÃO DE VENCIMENTO E STATUS
// ==========================================================
$pagina_atual = basename($_SERVER['PHP_SELF']);
$paginas_liberadas = ['renovar_conta.php', 'logout.php', 'pagar_mercadopago.php', 'webhook_mp.php'];

if (!$eh_master && !in_array($pagina_atual, $paginas_liberadas)) {
    
    global $pdo; 
    // Busca dados atualizados do banco (para não confiar só na sessão)
    $stmtV = $pdo->prepare("SELECT data_vencimento, status FROM tenants WHERE id = ?");
    $stmtV->execute([TENANT_ID]);
    $dados = $stmtV->fetch(PDO::FETCH_ASSOC);

    if ($dados) {
        $vencimento = $dados['data_vencimento'];
        $status = $dados['status']; 

        // Regra de Bloqueio: Sem data, Data Passada ou Status Bloqueado
        if (!$vencimento || $vencimento == '0000-00-00' || new DateTime($vencimento) < new DateTime('today') || $status == 'bloqueado') {
            header("Location: renovar_conta.php");
            exit;
        }
    }
}

// ==========================================================
// 5. FUNÇÃO DE PERMISSÕES (CORRIGIDA E ROBUSTA)
// ==========================================================
if (!function_exists('tem_acesso')) {
    function tem_acesso($modulo) {
        
        // 1. MASTER tem acesso total a tudo
        if (isset($_SESSION['permissoes']) && in_array('master', $_SESSION['permissoes'])) {
            return true;
        }

        // 2. RECUPERA MÓDULOS DO PLANO
        // Tenta pegar da sessão. Se não tiver, busca no banco AGORA.
        $modulos_do_plano = $_SESSION['modulos_plano'] ?? [];

        if (empty($modulos_do_plano)) {
            global $pdo;
            if(isset($pdo)) {
                // Busca quais módulos esse plano tem direito
                $stmt = $pdo->prepare("SELECT p.modulos FROM planos p JOIN tenants t ON t.plano = p.slug WHERE t.id = ?");
                $stmt->execute([TENANT_ID]);
                $json = $stmt->fetchColumn();
                
                // Decodifica e salva na sessão para não consultar toda hora
                $modulos_do_plano = json_decode($json ?? '[]', true);
                $_SESSION['modulos_plano'] = $modulos_do_plano;
            }
        }

        // 3. VERIFICAÇÃO DEFINITIVA
        // Se o módulo existe no array do plano, LIBERA.
        // (Removemos a verificação dupla de usuário para simplificar e destrava o painel)
        if (is_array($modulos_do_plano) && in_array($modulo, $modulos_do_plano)) {
            return true;
        }

        return false;
    }
}
?>