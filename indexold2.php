<?php
// index.php - O CÉREBRO DO SISTEMA
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';

// --- 1. SEGURANÇA SAAS (Garante o Tenant) ---
if (!defined('TENANT_ID')) {
    // Se o config.php não pegou, tentamos pegar pelo subdomínio manualmente
    $host = $_SERVER['HTTP_HOST'];
    $host = str_replace('www.', '', $host);
    $slug_url = explode('.', $host)[0];

    $stmtTenant = $pdo->prepare("SELECT id FROM tenants WHERE slug = ? OR dominio_personalizado = ? LIMIT 1");
    $stmtTenant->execute([$slug_url, $host]);
    $tenant = $stmtTenant->fetch(PDO::FETCH_ASSOC);

    if ($tenant) define('TENANT_ID', $tenant['id']);
}

// SE TIVER TENANT IDENTIFICADO (SITE DO VEREADOR)
if (defined('TENANT_ID')) {

    // (Opcional) Espião de Visitas
    if (file_exists('tracker.php')) { include 'tracker.php'; }

    // === CENÁRIO 1: PÁGINA ESPECÍFICA (?p=slug) ===
    // Ex: meugabinetedigital.com.br/?p=esporte-2024
    if (isset($_GET['p'])) {
        $slug_pagina = $_GET['p'];
        $stmt = $pdo->prepare("SELECT html FROM landing_pages WHERE slug = ? AND tenant_id = ?");
        $stmt->execute([$slug_pagina, TENANT_ID]);
        $pagina = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($pagina && !empty($pagina['html'])) {
            echo $pagina['html'];
            exit; // Para tudo e mostra a página
        } else {
            http_response_code(404);
            echo "<h1>Erro 404</h1><p>Página não encontrada.</p><a href='index.php'>Voltar ao Início</a>";
            exit;
        }
    }

    // === CENÁRIO 2: HOME PAGE PERSONALIZADA (LANDING PAGE) ===
    // Só mostra a LP se o usuário NÃO clicou em "ver site original"
    if (!isset($_GET['ver']) || $_GET['ver'] != 'site') {
        try {
            // Nota: Corrigido para buscar a coluna 'html'
            $stmt_lp = $pdo->prepare("SELECT html FROM landing_pages WHERE tenant_id = ? AND is_home = 1 LIMIT 1");
            $stmt_lp->execute([TENANT_ID]);
            $lp_home = $stmt_lp->fetch(PDO::FETCH_ASSOC);

            if ($lp_home && !empty($lp_home['html'])) {
                echo $lp_home['html'];
                exit; 
            }
        } catch (Exception $e) {}
    }

    // === CENÁRIO 3: SISTEMA PADRÃO (GABINETE OU INSTITUCIONAL) ===
    // Se não tem Landing Page ou o usuário pediu pra ver o sistema original
    try {
        $stmt = $pdo->prepare("SELECT pagina_inicial FROM configuracoes WHERE tenant_id = ?");
        $stmt->execute([TENANT_ID]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        $modo = $config['pagina_inicial'] ?? 'padrao';
    } catch (Exception $e) { $modo = 'padrao'; }

    // Se o modo for 'gabinete' e não forçar 'ver site'
    if ($modo == 'gabinete' && (!isset($_GET['ver']) || $_GET['ver'] != 'site')) {
        require_once 'gabinete_online.php';
    } else {
        // Carrega o site institucional (Hardcoded)
        if(file_exists('home.php')) {
            require_once 'home.php';
        } else {
            // Fallback amigável
            echo "<div style='font-family:sans-serif; text-align:center; padding:50px;'>";
            echo "<h2>Site em Construção</h2>";
            echo "<p>Bem-vindo ao gabinete digital.</p>";
            echo "<a href='gabinete_online.php' style='background:#0d6efd; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>Acessar Gabinete</a>";
            echo "</div>";
        }
    }

} else {
    // === CENÁRIO 4: SUA LANDING PAGE DE VENDAS (SAAS) ===
    // Ninguém logado, domínio principal
    if(file_exists('saas_landing.php')) {
        require_once 'saas_landing.php';
    } else {
        echo "<h1>Bem-vindo ao Gabinete Digital SaaS</h1>";
        echo "<p>Acesse através do subdomínio do seu cliente.</p>";
    }
}
?>