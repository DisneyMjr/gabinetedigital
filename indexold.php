<?php
// index.php
require_once 'config.php';

// --- ROTEADOR INTELIGENTE ---

if (defined('TENANT_ID')) {

    // =========================================================================
    // [NOVO] BLOCO DE URLS AMIGÁVEIS (Insira APENAS isso aqui)
    // =========================================================================
    // Pega a URL digitada (ex: /esporte-total)
    $rota = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    
    // Se digitou algo, não é a pasta 'gabinete' e não é um arquivo real (imagem/css)
    if (!empty($rota) && $rota != 'gabinete' && !file_exists($rota)) {
        try {
            // Busca na tabela se existe uma página com esse SLUG
            // ATENÇÃO: Confirme se sua coluna é 'html' ou 'conteudo_html'. 
            // O editor novo usa 'html'.
            $stmt = $pdo->prepare("SELECT html FROM landing_pages WHERE slug = ? AND tenant_id = ?");
            $stmt->execute([$rota, TENANT_ID]);
            $pagina = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($pagina && !empty($pagina['html'])) {
                echo $pagina['html'];
                exit; // <--- O PULO DO GATO: Se achou a página, exibe e PARA o script aqui.
            }
        } catch (Exception $e) {
            // Se der erro, apenas ignora e deixa o site carregar a home normal
        }
    }
    // =========================================================================
    // FIM DO BLOCO NOVO (Daqui para baixo é o seu código original intocado)
    // =========================================================================


    // === CENÁRIO 1: É UM SITE DE VEREADOR ===
    
    // 1. Verifica se tem Landing Page Específica (Prioridade Máxima)
    // Só mostra a LP se o usuário NÃO clicou em "ver site"
    if (!isset($_GET['ver']) || $_GET['ver'] != 'site') {
        try {
            // OBS: Verifique se sua coluna no banco é 'conteudo_html' ou 'html'
            $stmt_lp = $pdo->prepare("SELECT conteudo_html FROM landing_pages WHERE tenant_id = ? AND is_home = 1 LIMIT 1");
            $stmt_lp->execute([TENANT_ID]);
            $lp_home = $stmt_lp->fetch(PDO::FETCH_ASSOC);

            if ($lp_home) {
                // Aqui mantive 'conteudo_html' como estava no seu código original
                echo $lp_home['conteudo_html']; 
                exit; 
            }
        } catch (Exception $e) {}
    }

    // 2. Verifica configuração (Gabinete vs Site Institucional)
    try {
        $stmt = $pdo->prepare("SELECT pagina_inicial FROM configuracoes WHERE tenant_id = ?");
        $stmt->execute([TENANT_ID]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        $modo = $config['pagina_inicial'] ?? 'padrao';
    } catch (Exception $e) { $modo = 'padrao'; }

    // 3. Carrega o arquivo correto
    // CORREÇÃO: Se o modo for gabinete MAS a pessoa clicou em "ver=site", carrega a home.
    if ($modo == 'gabinete' && (!isset($_GET['ver']) || $_GET['ver'] != 'site')) {
        require_once 'gabinete_online.php';
    } else {
        // Carrega o site institucional
        if(file_exists('home.php')) {
            require_once 'home.php';
        } else {
            // Se não tiver home.php, cria um aviso amigável
            echo "<div style='text-align:center; padding:50px; font-family:sans-serif;'>";
            echo "<h1>Site em Construção</h1>";
            echo "<p>O arquivo <b>home.php</b> ainda não foi criado.</p>";
            echo "<a href='gabinete_online.php'>Ir para o Gabinete Online</a>";
            echo "</div>";
        }
    }

} else {
    // === CENÁRIO 2: É A SUA PÁGINA DE VENDAS (SAAS) ===
    // Ninguém logado, domínio principal -> Mostra a Landing Page de Vendas
    if(file_exists('saas_landing.php')) {
        require_once 'saas_landing.php';
    } else {
        echo "Bem-vindo ao Gabinete Digital SaaS (Crie o arquivo saas_landing.php)";
    }
}
?>