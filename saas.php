<?php
// saas.php - Descobre quem é o dono dos dados
require_once 'config.php'; // Sua conexão PDO normal

// Pega o host atual (ex: joao.gabinetedigital.com.br)
$host = $_SERVER['HTTP_HOST'];

// Extrai o subdomínio (parte antes do primeiro ponto)
$partes = explode('.', $host);
$subdominio = $partes[0];

// Lista de domínios reservados (seu painel de venda/gestão)
$reservados = ['www', 'painel', 'admin', 'site'];

if (in_array($subdominio, $reservados)) {
    // É você acessando o painel mestre, não um cliente
    define('IS_TENANT', false);
    return; 
}

// Busca o Tenant no Banco
$stmt = $pdo->prepare("SELECT * FROM tenants WHERE subdominio = ? AND status = 'ativo'");
$stmt->execute([$subdominio]);
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tenant) {
    // EM VEZ DE MOSTRAR ERRO, CARREGA A TELA DE VENDAS
    require_once 'planos.php';
    exit; // Para a execução aqui
}

// DEFINE A CONSTANTE GLOBAL COM O ID DO CLIENTE
define('TENANT_ID', $tenant['id']);
define('IS_TENANT', true);

// Carrega as configurações DESTE cliente específico
$stmt_conf = $pdo->prepare("SELECT * FROM configuracoes WHERE tenant_id = ?");
$stmt_conf->execute([TENANT_ID]);
$site_config = $stmt_conf->fetch(PDO::FETCH_ASSOC);

// Se não tiver config ainda, usa padrão
if(!$site_config) {
    $site_config = ['nome_vereador' => 'Vereador ' . ucfirst($subdominio)];
}
?>