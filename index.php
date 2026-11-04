<?php
// index.php
require_once 'config.php';

// =========================================================================
// 1. BARREIRA DE DOMÍNIO PRINCIPAL (SaaS)
// =========================================================================

$host_atual = $_SERVER['HTTP_HOST'];
$host_limpo = str_replace('www.', '', $host_atual);
$meu_dominio_saas = 'meugabinetedigital.com.br';

if ($host_limpo === $meu_dominio_saas) {

    if (file_exists('saas_landing.php')) {
        require_once 'saas_landing.php';
    } else {
        echo "<div style='text-align:center; padding:50px; font-family:sans-serif;'>";
        echo "<h1>Gabinete Digital</h1>";
        echo "<p>Bem-vindo à página principal do SaaS.</p>";
        echo "<a href='admin_login.php'>Fazer Login</a>";
        echo "</div>";
    }

    exit;
}

// =========================================================================
// 2. ROTEADOR DE CLIENTES
// =========================================================================

if (!defined('TENANT_ID')) {
    header("Location: https://" . $meu_dominio_saas);
    exit;
}

$rota = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

// Permite visualizar também por index.php?p=slug
if (!empty($_GET['p'])) {
    $rota = trim($_GET['p']);
}

// DEBUG
if (isset($_GET['debug_home'])) {
    header('Content-Type: text/plain; charset=utf-8');

    echo "INDEX CLIENTE CARREGADO\n";
    echo "HOST: " . $host_atual . "\n";
    echo "TENANT_ID: " . TENANT_ID . "\n";
    echo "ROTA: " . $rota . "\n\n";

    $stmtDebug = $pdo->prepare("
        SELECT 
            id,
            tenant_id,
            titulo,
            slug,
            is_home,
            LENGTH(html) AS tamanho_html,
            LENGTH(conteudo_html) AS tamanho_conteudo_html,
            LEFT(html, 500) AS inicio_html
        FROM landing_pages
        WHERE tenant_id = ?
        ORDER BY id DESC
        LIMIT 10
    ");
    $stmtDebug->execute([TENANT_ID]);

    print_r($stmtDebug->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// =========================================================================
// 3. URLS AMIGÁVEIS / LANDING POR SLUG
// =========================================================================

if (!empty($rota) && $rota !== 'gabinete' && $rota !== 'index.php' && !file_exists($rota)) {
    try {
        $stmt = $pdo->prepare("
            SELECT html, conteudo_html
            FROM landing_pages
            WHERE slug = ?
              AND tenant_id = ?
            LIMIT 1
        ");
        $stmt->execute([$rota, TENANT_ID]);
        $pagina = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($pagina) {
            $html_final = '';

            if (!empty($pagina['html'])) {
                $html_final = $pagina['html'];
            } elseif (!empty($pagina['conteudo_html'])) {
                $html_final = $pagina['conteudo_html'];
            }

            if (trim($html_final) !== '') {
                echo $html_final;
                exit;
            }
        }

    } catch (Exception $e) {
        echo "Erro ao carregar landing: " . $e->getMessage();
        exit;
    }
}

// =========================================================================
// 4. HOME CUSTOMIZADA
// =========================================================================

if (!isset($_GET['ver']) || $_GET['ver'] != 'site') {
    try {
        $stmt_lp = $pdo->prepare("
            SELECT html, conteudo_html
            FROM landing_pages
            WHERE tenant_id = ?
              AND is_home = 1
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt_lp->execute([TENANT_ID]);
        $lp_home = $stmt_lp->fetch(PDO::FETCH_ASSOC);

        if ($lp_home) {
            $html_final = '';

            if (!empty($lp_home['html'])) {
                $html_final = $lp_home['html'];
            } elseif (!empty($lp_home['conteudo_html'])) {
                $html_final = $lp_home['conteudo_html'];
            }

            if (trim($html_final) !== '') {
                echo $html_final;
                exit;
            }
        }

    } catch (Exception $e) {
        echo "Erro ao carregar home: " . $e->getMessage();
        exit;
    }
}

// =========================================================================
// 5. CONFIGURAÇÃO SITE / GABINETE
// =========================================================================

try {
    $stmt = $pdo->prepare("SELECT pagina_inicial FROM configuracoes WHERE tenant_id = ?");
    $stmt->execute([TENANT_ID]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    $modo = $config['pagina_inicial'] ?? 'padrao';
} catch (Exception $e) {
    $modo = 'padrao';
}

if ($modo == 'gabinete' && (!isset($_GET['ver']) || $_GET['ver'] != 'site')) {
    require_once 'gabinete_online.php';
    exit;
}

if (file_exists('home.php')) {
    require_once 'home.php';
} else {
    require_once 'gabinete_online.php';
}

exit;
?>