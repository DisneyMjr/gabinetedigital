<?php
// index.php
require_once 'config.php';

// =========================================================================
// 1. ROTEADOR E LÓGICA DE SERVIDOR (BACKEND)
// =========================================================================

if (defined('TENANT_ID')) {

    // Captura a URL digitada
    $rota = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

    // --- A. ENCURTADOR DE LINKS (/go/xxxx) ---
    if (strpos($rota, 'go/') === 0) {
        $codigo = str_replace('go/', '', $rota);
        try {
            $stmtLink = $pdo->prepare("SELECT id, url_destino FROM links_curtos WHERE codigo = ? AND tenant_id = ? LIMIT 1");
            $stmtLink->execute([$codigo, TENANT_ID]);
            $link = $stmtLink->fetch(PDO::FETCH_ASSOC);

            if ($link) {
                $pdo->prepare("UPDATE links_curtos SET cliques = cliques + 1 WHERE id = ?")->execute([$link['id']]);
                header("Location: " . $link['url_destino']);
                exit;
            }
        } catch (Exception $e) { }
    }

    // --- B. CARREGAR CONFIGURAÇÕES GERAIS ---
    try {
        $stmt = $pdo->prepare("SELECT * FROM configuracoes WHERE tenant_id = ?");
        $stmt->execute([TENANT_ID]);
        $site_config = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$site_config) $site_config = [];
    } catch (Exception $e) { $site_config = []; }

    // --- C. PREVIEW WHATSAPP (OPEN GRAPH) ---
    $og_title = !empty($site_config['nome_vereador']) ? $site_config['nome_vereador'] : "Gabinete Digital";
    $og_desc  = !empty($site_config['hero_texto']) ? mb_strimwidth(strip_tags($site_config['hero_texto']), 0, 150, "...") : "Acesse meu gabinete online.";
    
    $protocolo = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https://" : "http://";
    $base_url = $protocolo . $_SERVER['HTTP_HOST'];

    if (!empty($site_config['foto_hero'])) {
        $og_image = $base_url . "/" . $site_config['foto_hero'];
    } elseif (!empty($site_config['logo_site'])) {
        $og_image = $base_url . "/" . $site_config['logo_site'];
    } else {
        $og_image = "https://via.placeholder.com/1200x630?text=Visite+Nosso+Site";
    }

    // --- D. ROTA DE LANDING PAGES (URL AMIGÁVEL) ---
    if (!empty($rota) && $rota != 'gabinete' && !file_exists($rota)) {
        try {
            $stmt = $pdo->prepare("SELECT html FROM landing_pages WHERE slug = ? AND tenant_id = ?");
            $stmt->execute([$rota, TENANT_ID]);
            $pagina = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($pagina && !empty($pagina['html'])) { echo $pagina['html']; exit; }
        } catch (Exception $e) {}
    }

    // --- E. VERIFICAÇÃO DE HOME CUSTOMIZADA (BUILDER) ---
    if (!isset($_GET['ver']) || $_GET['ver'] != 'site') {
        try {
            $stmtLP = $pdo->prepare("SELECT conteudo_html FROM landing_pages WHERE tenant_id = ? AND is_home = 1 LIMIT 1");
            $stmtLP->execute([TENANT_ID]);
            $home_customizada = $stmtLP->fetch(PDO::FETCH_ASSOC);
            if ($home_customizada) { echo $home_customizada['conteudo_html']; exit; }
        } catch (Exception $e) {}
    }

    // --- F. MODO GABINETE ONLINE APENAS ---
    $modo = $site_config['pagina_inicial'] ?? 'padrao';
    if ($modo == 'gabinete' && (!isset($_GET['ver']) || $_GET['ver'] != 'site')) {
        require_once 'gabinete_online.php';
        exit;
    }

} else {
    if(file_exists('saas_landing.php')) { require_once 'saas_landing.php'; } else { echo "Gabinete Digital SaaS"; }
    exit;
}

$avatar_padrao = 'https://via.placeholder.com/600x600?text=Foto+Aqui';
$foto_hero = (!empty($site_config['foto_hero']) && file_exists($site_config['foto_hero'])) ? $site_config['foto_hero'] : $avatar_padrao;
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $site_config['nome_vereador'] ?? 'Site Oficial' ?></title>
    
    <?php if(!empty($site_config['logo_site'])): ?>
    <link rel="icon" href="<?= $site_config['logo_site'] ?>">
    <?php endif; ?>

    <meta property="og:locale" content="pt_BR">
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= htmlspecialchars($og_title) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($og_desc) ?>">
    <meta property="og:url" content="<?= $base_url . $_SERVER['REQUEST_URI'] ?>">
    <meta property="og:site_name" content="<?= htmlspecialchars($og_title) ?>">
    <meta property="og:image" content="<?= $og_image ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Montserrat:wght@700;900&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-blue: <?= $site_config['cor_primaria'] ?? '#0d6efd' ?>; 
            --accent-yellow: <?= $site_config['cor_secundaria'] ?? '#ffc107' ?>;
            --dark-blue: color-mix(in srgb, var(--primary-blue), black 20%);
            --text-dark: #1A2B49;
            --white: #ffffff;
        }
        
        body { font-family: 'Inter', sans-serif; color: var(--text-dark); overflow-x: hidden; }
        h1, h2, h3, .btn-font { font-family: 'Montserrat', sans-serif; font-weight: 800; }
        
        .navbar { background: var(--white); box-shadow: 0 4px 20px rgba(0,0,0,0.05); padding: 15px 0; }
        .nav-link { font-weight: 600; color: var(--text-dark); margin: 0 10px; text-transform: uppercase; font-size: 0.9rem; }
        .btn-nav { background: var(--primary-blue); color: white; border-radius: 50px; padding: 8px 25px; font-weight: 700; transition: 0.3s; }
        .btn-nav:hover { background: var(--dark-blue); color: white; transform: translateY(-2px); }
        
        .hero-section { background: radial-gradient(circle at 70% 50%, var(--dark-blue) 0%, var(--primary-blue) 100%); min-height: 85vh; position: relative; display: flex; align-items: center; overflow: hidden; padding-top: 80px; }
        .hero-bg-pattern { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background-image: radial-gradient(rgba(255,255,255,0.1) 2px, transparent 2px); background-size: 30px 30px; pointer-events: none; }
        .hero-title { font-size: 4rem; line-height: 1; color: white; text-transform: uppercase; margin-bottom: 10px; }
        .hero-subtitle { font-size: 1.5rem; color: var(--accent-yellow); font-weight: 700; margin-bottom: 30px; }
        .hero-img { position: relative; z-index: 2; max-height: 650px; object-fit: contain; mask-image: linear-gradient(to bottom, black 85%, transparent 100%); }
        
        .icon-strip { background: var(--accent-yellow); padding: 20px 0; overflow: hidden; white-space: nowrap; position: relative; z-index: 3; margin-top: -5px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .icon-strip i { font-size: 1.5rem; color: rgba(255,255,255,0.8); margin: 0 40px; display: inline-block; }
        
        .section-bio { padding: 100px 0; background: white; }
        .bio-img-main { border-radius: 20px; box-shadow: 20px 20px 0px var(--primary-blue); width: 100%; }
        
        .card-news { border: none; border-radius: 20px; overflow: hidden; background: white; transition: 0.3s; height: 100%; box-shadow: 0 10px 20px rgba(0,0,0,0.05); }
        .card-news:hover { transform: translateY(-10px); }
        .card-news img { height: 250px; object-fit: cover; width: 100%; }
        
        .card-agenda { transition: 0.3s; border: none; border-radius: 15px; overflow: hidden; }
        .card-agenda:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.1) !important; }
        
        .card-projeto { transition: 0.3s; cursor: pointer; border: none; border-radius: 1rem; }
        .card-projeto:hover { transform: translateY(-5px); box-shadow: 0 15px 30px rgba(0,0,0,0.15) !important; }

        .footer-cta { background: var(--primary-blue); color: white; padding: 80px 0 0; }
        
        .btn-social-modern {
            display: flex; align-items: center; justify-content: center; width: 100%; padding: 12px 15px; margin-bottom: 15px;
            border-radius: 50px; font-weight: 700; font-size: 1rem; text-decoration: none; transition: all 0.3s;
            border: none; position: relative; overflow: hidden; color: white !important; gap: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); white-space: nowrap;
        }
        .btn-social-modern i { font-size: 1.3rem; transition: transform 0.3s; color: white !important; }
        .btn-social-modern:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.2); }
        
        .btn-zap-modern { background-color: #25D366; }
        .btn-insta-modern { background: linear-gradient(45deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%) !important; }

        @media (max-width: 768px) { .hero-title { font-size: 2.5rem; } .hero-img { margin-top: 40px; max-height: 400px; } }
    </style>
    <?php if (!empty($site_config['onesignal_app_id'])): ?>
    <script src="https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js" defer></script>
    <script>
      window.OneSignalDeferred = window.OneSignalDeferred || [];
      OneSignalDeferred.push(async function(OneSignal) {
        await OneSignal.init({
          appId: "<?= $site_config['onesignal_app_id'] ?>", 
          serviceWorkerParam: { scope: "/", path: "/sw.js" }, 
          
          promptOptions: {
            slidedown: {
              prompts: [{
                type: "push",
                autoPrompt: true,
                text: {
                  actionMessage: "Gostaríamos de lhe enviar notificações com as últimas novidades e atualizações do mandato.",
                  acceptButton: "Permitir",
                  cancelButton: "Cancelar"
                }
              }]
            }
          },

          notifyButton: {
            enable: true,
            colors: {
                'circle.background': '<?= $site_config['cor_primaria'] ?? '#0d6efd' ?>',
                'circle.foreground': 'white',
            },
            text: {
                'tip.state.unsubscribed': 'Receber novidades do mandato',
                'tip.state.subscribed': 'Você está inscrito!',
                'tip.state.blocked': 'Você bloqueou as notificações'
            }
          }
        });

        OneSignal.Slidedown.promptPush();

        OneSignal.User.addTags({
            tenant_id: "<?= TENANT_ID ?>",
            tipo: "eleitor"
        });
      });
    </script>
    <?php endif; ?>
</head>
<body>

    <nav class="navbar navbar-expand-lg fixed-top">
        <div class="container">
            <a class="navbar-brand" href="#">
                <?php if (!empty($site_config['logo_site']) && file_exists($site_config['logo_site'])): ?>
                    <img src="<?= $site_config['logo_site'] ?>" alt="Logo" style="height: 50px; width: auto;">
                <?php else: ?>
                    <span class="text-primary fw-bold text-uppercase fs-4">
                        <i class="fas fa-landmark"></i> <?= $site_config['nome_vereador'] ?? 'MANDATO' ?>
                    </span>
                <?php endif; ?>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item"><a class="nav-link" href="#bio">Quem Sou</a></li>
                    <li class="nav-item"><a class="nav-link" href="#agenda">Agenda</a></li>
                    <li class="nav-item"><a class="nav-link" href="#projetos">Atuação</a></li>
                    <li class="nav-item"><a class="nav-link" href="#noticias">Notícias</a></li>
                    <li class="nav-item"><a class="nav-link" href="transparencia.php">Transparência</a></li>
                    
                    <li class="nav-item ms-3">
                        <a href="gabinete_online.php" class="btn btn-nav shadow-sm"><i class="fas fa-bullhorn me-2"></i> Gabinete Online</a>
                    </li>
                    <a href="meus_pedidos.php" class="btn btn-outline-primary rounded-pill fw-bold ms-2"><i class="fas fa-search me-2"></i> Pedidos</a>
                </ul>
            </div>
        </div>
    </nav>

    <section class="hero-section">
        <div class="hero-bg-pattern"></div>
        <div class="container h-100">
            <div class="row h-100 align-items-center">
                <div class="col-lg-6 pt-5 pt-lg-0">
                    <span class="badge bg-warning text-dark mb-3 px-3 py-2 fw-bold rounded-pill text-uppercase">
                        <?= $site_config['hero_badge'] ?? 'Site Oficial' ?>
                    </span>
                    <h1 class="hero-title"><?= $site_config['nome_vereador'] ?? 'Seu Nome' ?></h1>
                    <p class="hero-subtitle"><?= $site_config['hero_cargo'] ?? 'Vereador' ?></p>
                    <p class="lead text-white mb-4 opacity-75">
                        <?= $site_config['hero_texto'] ?? 'Trabalho e compromisso por nossa cidade.' ?>
                    </p>
                    <div class="d-flex gap-3 flex-wrap">
                        <a href="gabinete_online.php" class="btn btn-light rounded-pill px-4 py-3 fw-bold text-primary shadow text-uppercase">
                            <?= $site_config['hero_btn_acao'] ?? 'Fazer Pedido' ?>
                        </a>
                        <a href="#bio" class="btn btn-outline-light rounded-pill px-4 py-3 fw-bold text-uppercase">
                            <?= $site_config['hero_btn_bio'] ?? 'Minha História' ?>
                        </a>
                    </div>
                </div>
                <div class="col-lg-6 text-center text-lg-end d-flex align-items-end justify-content-center h-100">
                    <img src="<?= $foto_hero ?>" class="hero-img img-fluid">
                </div>
            </div>
        </div>
    </section>

    <div class="icon-strip">
        <?php for($i=0; $i<15; $i++): ?>
            <i class="fas fa-heartbeat"></i> <i class="fas fa-book-open"></i> <i class="fas fa-users"></i> <i class="fas fa-city"></i>
        <?php endfor; ?>
    </div>

    <section id="bio" class="section-bio">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-5 mb-5 mb-lg-0">
                    <div class="bio-img-container position-relative">
                        <img src="<?= $foto_hero ?>" class="bio-img-main img-fluid" alt="Perfil">
                        <div class="position-absolute bottom-0 end-0 bg-warning p-4 rounded shadow fw-bold text-primary m-3" 
                             style="min-width: 180px; transform: translate(30px, 30px); z-index: 2;">
                            <i class="fas fa-handshake fa-2x mb-2 d-block"></i>
                            <span class="text-uppercase"><?= $site_config['bio_card_texto'] ?? 'Compromisso' ?></span>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 offset-lg-1">
                    <h5 class="text-primary text-uppercase fw-bold mb-3"><?= $site_config['bio_titulo'] ?? 'Sobre Mim' ?></h5>
                    <h2 class="mb-4 display-6 fw-bold"><?= $site_config['bio_destaque'] ?? 'Uma vida dedicada ao povo.' ?></h2>
                    <p class="text-muted mb-4 lead" style="white-space: pre-line; font-size: 1.1rem;">
                        <?= $site_config['biografia'] ?? 'Texto da biografia aqui...' ?>
                    </p>
                </div>
            </div>
        </div>
    </section>

    <section id="agenda" class="py-5 bg-light">
        <div class="container">
            <div class="d-flex justify-content-between align-items-end mb-4">
                <div>
                    <h2 class="fw-bold text-primary display-6">Agenda Oficial</h2>
                    <p class="text-muted">Acompanhe nossos compromissos</p>
                </div>
            </div>
            <div id="lista-agenda" class="row g-4">
                <div class="col-12 text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                </div>
            </div>
        </div>
    </section>

    <section id="secao-enquete" class="py-5 d-none">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-8 col-lg-6">
                    <div class="card shadow border-0 rounded-4">
                        <div class="card-header text-white text-center py-3 rounded-top-4" style="background: var(--primary-blue);">
                            <h5 class="mb-0 fw-bold"><i class="fas fa-poll"></i> Sua Opinião</h5>
                        </div>
                        <div class="card-body p-4" id="conteudo-enquete"></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="projetos" class="py-5" style="background: var(--primary-blue);">
        <div class="container">
            <div class="text-center mb-5">
                <span class="badge bg-white text-primary px-3 py-2 rounded-pill fw-bold text-uppercase mb-2 shadow-sm">Transparência</span>
                <h2 class="text-white fw-bold display-5">Atuação Legislativa</h2>
                <p class="text-white opacity-75 lead">Projetos de Lei, Requerimentos e Indicações.</p>
            </div>
            <div id="lista-projetos" class="row g-4">
                <div class="col-12 text-center py-5 text-white">
                    <div class="spinner-border" role="status"></div>
                    <p>Carregando projetos...</p>
                </div>
            </div>
        </div>
    </section>

    <div class="modal fade" id="modalProjetoDetalhe" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content rounded-4 border-0 shadow-lg">
                <div class="modal-header border-bottom-0 pb-0">
                    <div class="d-flex align-items-center gap-2">
                        <span id="modalProjBadge" class="badge rounded-pill px-3 py-2 fw-bold"></span>
                        <span id="modalProjTipoNum" class="text-muted fw-bold small text-uppercase"></span>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body pt-3 px-4 pb-4">
                    <h3 id="modalProjTitulo" class="fw-bold text-dark mb-4" style="line-height: 1.3;"></h3>
                    
                    <div class="bg-light p-4 rounded-3 mb-4">
                        <h6 class="fw-bold text-primary mb-2"><i class="fas fa-info-circle me-1"></i> Resumo da Matéria</h6>
                        <p id="modalProjResumo" class="text-dark mb-0" style="white-space: pre-wrap; font-size: 1.05rem;"></p>
                    </div>
                    
                    <div id="modalProjBtnContainer" class="text-center mt-4">
                        </div>
                </div>
            </div>
        </div>
    </div>

    <section id="noticias" class="section-news bg-white py-5">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="text-dark fw-bold display-5"><?= $site_config['news_titulo'] ?? 'Últimas Notícias' ?></h2>
                <p class="text-muted"><?= $site_config['news_subtitulo'] ?? 'Fique por dentro do mandato' ?></p>
            </div>
            
            <div id="lista-noticias" class="row g-4">
                <div class="col-12 text-center py-5 text-muted">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p>Carregando novidades...</p>
                </div>
            </div>

            <div class="text-center mt-5">
                 <a href="blog.php" class="btn btn-outline-warning text-dark fw-bold rounded-pill px-5 py-3 shadow-sm border-2">
                    <?= $site_config['news_btn'] ?? 'Ler Mais' ?>
                 </a>
            </div>
        </div>
    </section>

    <section class="footer-cta">
        <div class="container mb-5">
            <div class="row align-items-center">
                <div class="col-md-5 d-none d-md-block text-center">
                    <img src="<?= $foto_hero ?>" class="img-fluid" style="max-height: 400px; mask-image: linear-gradient(to bottom, black 80%, transparent 100%);">
                </div>
                <div class="col-md-6 offset-md-1 pb-5">
                    <h2 class="mb-4 text-white fw-bold display-5"><?= $site_config['footer_titulo'] ?? 'Contato' ?></h2>
                    <p class="mb-5 text-white opacity-75 lead"><?= $site_config['footer_texto'] ?? 'Entre em contato.' ?></p>
                    
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <a href="https://wa.me/55<?= preg_replace('/[^0-9]/', '', $site_config['whatsapp_oficial'] ?? '') ?>" class="btn-social-modern btn-zap-modern" target="_blank">
                                <i class="fab fa-whatsapp"></i> 
                                <span class="text-truncate"><?= $site_config['footer_btn_zap'] ?? 'WhatsApp' ?></span>
                            </a>
                        </div>
                        <?php if (!empty($site_config['instagram'])): ?>
                        <div class="col-sm-6 mb-3">
                            <?php
                                $insta_input = $site_config['instagram'];
                                $usuario_insta = str_replace(['https://', 'http://', 'www.', 'instagram.com/', '@'], '', $insta_input);
                                $usuario_insta = trim($usuario_insta, '/');
                            ?>
                            <a href="https://instagram.com/<?= $usuario_insta ?>" class="btn-social-modern btn-insta-modern" target="_blank">
                                <i class="fab fa-instagram"></i> 
                                <span class="text-truncate"><?= $site_config['footer_btn_insta'] ?? 'Siga no Insta' ?></span>
                            </a>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($site_config['tiktok'])): ?>
                        <div class="col-sm-6 mb-3">
                            <?php
                                $tiktok_input = $site_config['tiktok'];
                                $url_tiktok = (strpos($tiktok_input, 'http') === 0) ? $tiktok_input : 'https://tiktok.com/@' . ltrim(str_replace('tiktok.com/', '', $tiktok_input), '@/');
                            ?>
                            <a href="<?= $url_tiktok ?>" class="btn-social-modern" target="_blank" style="background: linear-gradient(90deg, #000000, #333333); color: white; border: none; width: 100%; display: flex; align-items: center; justify-content: center; gap: 8px;">
                                <i class="fab fa-tiktok"></i> 
                                <span class="text-truncate">Siga no TikTok</span>
                            </a>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($site_config['youtube'])): ?>
                        <div class="col-sm-6 mb-3">
                            <?php
                                $yt_input = $site_config['youtube'];
                                $url_yt = (strpos($yt_input, 'http') === 0) ? $yt_input : 'https://youtube.com/' . ltrim(str_replace('youtube.com/', '', $yt_input), '/');
                            ?>
                            <a href="<?= $url_yt ?>" class="btn-social-modern" target="_blank" style="background: linear-gradient(90deg, #ff0000, #cc0000); color: white; border: none; width: 100%; display: flex; align-items: center; justify-content: center; gap: 8px;">
                                <i class="fab fa-youtube"></i> 
                                <span class="text-truncate">Ver no YouTube</span>
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="bg-dark py-4 text-center text-white-50 small">
            &copy; <?= date('Y') ?> <?= $site_config['nome_vereador'] ?? '' ?>. Todos os direitos reservados.
            <?php if (!empty($site_config['cnpj_campanha'])): ?>
                <br><span class="opacity-75">CNPJ de Campanha: <?= $site_config['cnpj_campanha'] ?></span>
            <?php endif; ?>

            <div class="mt-3">
                <a href="login.php" class="link-login-footer text-white-50 text-decoration-none small" style="transition: 0.3s;">
                    <i class="fas fa-lock me-1"></i> Área Restrita
                </a>
            </div>
            
        </div>
    </section>

    <!-- ========================================================= -->
    <!-- COMPONENTES DE INSTALAÇÃO DO APP (PWA) -->
    <!-- ========================================================= -->
    
    <!-- Botão Flutuante -->
    <button class="btn border-0 shadow-lg position-fixed fw-bold text-white d-flex align-items-center gap-2" 
            style="bottom: 25px; right: 25px; z-index: 1040; padding: 12px 24px; border-radius: 50px; background: linear-gradient(135deg, var(--primary-blue), var(--dark-blue));"
            data-bs-toggle="offcanvas" data-bs-target="#pwaGuideOffcanvas">
        <i class="fas fa-download fa-lg"></i> Instalar App
    </button>

    <!-- Offcanvas (Modal Deslizante) do Bootstrap -->
    <div class="offcanvas offcanvas-bottom shadow-lg" tabindex="-1" id="pwaGuideOffcanvas" style="height: auto; max-height: 90vh; border-top-left-radius: 25px; border-top-right-radius: 25px; border: none;">
        
        <div class="offcanvas-header text-white" id="pwaHeader" style="background: var(--primary-blue); border-top-left-radius: 25px; border-top-right-radius: 25px; transition: background 0.4s;">
            <div class="d-flex align-items-center gap-2 w-100">
                <button class="btn text-white p-0 me-2 d-none" id="btnVoltarGuia" onclick="voltarSelecaoGuia()" style="border:none;"><i class="fas fa-arrow-left fa-lg"></i></button>
                <i class="fas fa-mobile-alt fa-lg"></i>
                <h6 class="offcanvas-title fw-bold m-0" id="offcanvasLabel">Adicionar à Tela Inicial</h6>
            </div>
            <button type="button" class="btn-close btn-close-white m-0" data-bs-dismiss="offcanvas" aria-label="Close" onclick="resetPwaGuide()"></button>
        </div>
        
        <div class="offcanvas-body px-4 py-4" id="guiaAppContent">
            <!-- O JS Injeta o conteúdo inicial aqui -->
        </div>
    </div>

    <!-- Script de Lógica do Guia PWA -->
    <script>
        const pwaColorPrimary = '<?= $site_config['cor_primaria'] ?? '#0d6efd' ?>';

        const passosAndroid = [
            { titulo: "Abra o Menu", descricao: "Toque nos três pontinhos (⋮) no canto superior direito do seu navegador Chrome.", icone: "fas fa-ellipsis-v", cor: "success" },
            { titulo: "Adicionar à Tela", descricao: "Role o menu para baixo e toque em 'Adicionar à tela inicial' ou 'Instalar aplicativo'.", icone: "fas fa-plus-square", cor: "success" },
            { titulo: "Confirme", descricao: "Toque em 'Adicionar' na janela que aparecer. O ícone aparecerá no seu celular!", icone: "fas fa-check-circle", cor: "success" }
        ];

        const passosIphone = [
            { titulo: "Compartilhar", descricao: "Na barra inferior do Safari, toque no ícone de compartilhar (quadrado com seta ↑).", icone: "fas fa-share-square", cor: "primary" },
            { titulo: "Adicionar à Tela", descricao: "Role o menu um pouco para baixo e toque em 'Adicionar à Tela de Início'.", icone: "fas fa-plus-square", cor: "primary" },
            { titulo: "Confirme", descricao: "No canto superior direito toque em 'Adicionar'. O ícone aparecerá no seu celular!", icone: "fas fa-check-circle", cor: "primary" }
        ];

        let pwaSistema = null;
        let pwaPasso = 0;

        function renderizarTelaSelecao() {
            document.getElementById('guiaAppContent').innerHTML = `
                <div class="text-center mb-4">
                    <h5 class="fw-bold text-dark mb-1">Qual é o seu celular?</h5>
                    <p class="text-muted small">Vamos te ensinar a instalar o nosso App na tela inicial.</p>
                </div>
                
                <button onclick="selecionarSistemaPwa('android')" class="btn btn-outline-success w-100 d-flex align-items-center text-start p-3 mb-3" style="border-radius: 15px; border-width: 2px;">
                    <div class="bg-success text-white rounded p-3 me-3"><i class="fab fa-android fa-xl"></i></div>
                    <div class="flex-grow-1">
                        <strong class="d-block fs-5">Android</strong>
                        <small class="text-muted">Samsung, Motorola, Xiaomi...</small>
                    </div>
                    <i class="fas fa-chevron-right text-muted"></i>
                </button>

                <button onclick="selecionarSistemaPwa('iphone')" class="btn btn-outline-primary w-100 d-flex align-items-center text-start p-3 mb-3" style="border-radius: 15px; border-width: 2px;">
                    <div class="bg-primary text-white rounded p-3 me-3"><i class="fab fa-apple fa-xl" style="padding: 0 2px;"></i></div>
                    <div class="flex-grow-1">
                        <strong class="d-block fs-5">iPhone (Apple)</strong>
                        <small class="text-muted">iPhone, iPad...</small>
                    </div>
                    <i class="fas fa-chevron-right text-muted"></i>
                </button>

                <button class="btn btn-link text-muted w-100 fw-bold text-decoration-none mt-2" data-bs-dismiss="offcanvas" onclick="resetPwaGuide()">Agora não</button>
            `;
        }

        function selecionarSistemaPwa(sys) {
            pwaSistema = sys;
            pwaPasso = 0;
            document.getElementById('pwaHeader').style.background = (sys === 'android') ? '#198754' : '#0d6efd';
            document.getElementById('btnVoltarGuia').classList.remove('d-none');
            renderizarPassosPwa();
        }

        function voltarSelecaoGuia() {
            resetPwaGuide();
            renderizarTelaSelecao();
        }

        function resetPwaGuide() {
            pwaSistema = null;
            pwaPasso = 0;
            document.getElementById('pwaHeader').style.background = pwaColorPrimary;
            document.getElementById('btnVoltarGuia').classList.add('d-none');
        }

        function renderizarPassosPwa() {
            const passos = pwaSistema === 'android' ? passosAndroid : passosIphone;
            const dados = passos[pwaPasso];
            const cor = dados.cor;

            let dots = '<div class="d-flex justify-content-center gap-2 mb-4">';
            passos.forEach((_, i) => {
                dots += `<div class="rounded-pill ${i === pwaPasso ? 'bg-'+cor : 'bg-secondary opacity-25'}" style="height: 8px; width: ${i === pwaPasso ? '30px' : '8px'}; transition: 0.3s;"></div>`;
            });
            dots += '</div>';

            let botoes = '<div class="d-flex gap-3 w-100 mt-4">';
            if (pwaPasso > 0) {
                botoes += `<button onclick="pwaPasso--; renderizarPassosPwa()" class="btn btn-outline-secondary fw-bold flex-grow-1 py-2" style="border-radius: 12px;"><i class="fas fa-arrow-left me-1"></i> Anterior</button>`;
            }
            if (pwaPasso < passos.length - 1) {
                botoes += `<button onclick="pwaPasso++; renderizarPassosPwa()" class="btn btn-${cor} fw-bold text-white flex-grow-1 py-2" style="border-radius: 12px;">Próximo <i class="fas fa-arrow-right ms-1"></i></button>`;
            } else {
                botoes += `<button data-bs-dismiss="offcanvas" onclick="resetPwaGuide()" class="btn btn-${cor} fw-bold text-white flex-grow-1 py-2" style="border-radius: 12px;"><i class="fas fa-check-circle me-1"></i> Entendi!</button>`;
            }
            botoes += '</div>';

            document.getElementById('guiaAppContent').innerHTML = `
                ${dots}
                <div class="text-center px-2">
                    <div class="d-inline-flex align-items-center justify-content-center bg-${cor} bg-opacity-10 text-${cor} rounded-4 mb-4" style="width: 80px; height: 80px;">
                        <i class="${dados.icone} fa-3x"></i>
                    </div>
                    <p class="text-muted small fw-bold text-uppercase tracking-widest mb-2">Passo ${pwaPasso + 1} de ${passos.length}</p>
                    <h4 class="fw-bold text-dark mb-3">${dados.titulo}</h4>
                    <p class="text-muted" style="font-size: 1.05rem;">${dados.descricao}</p>
                </div>
                ${botoes}
            `;
        }

        // Renderiza a tela de seleção inicial assim que a página carrega
        renderizarTelaSelecao();
        
        // Garante que o guia seja resetado se o offcanvas for fechado clicando fora dele
        document.getElementById('pwaGuideOffcanvas').addEventListener('hidden.bs.offcanvas', function () {
            resetPwaGuide();
            renderizarTelaSelecao();
        });
    </script>
    <!-- ========================================================= -->


    <script src="https://code.jquery.com/jquery-3.4.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            carregarAgenda();
            carregarEnquete();
            carregarNoticias();
            carregarProjetos();
        });

        function carregarNoticias() {
            fetch('api_noticias.php')
                .then(res => res.json())
                .then(dados => {
                    const container = document.getElementById('lista-noticias');
                    container.innerHTML = '';
                    if (!dados || dados.length === 0) {
                        container.innerHTML = '<p class="text-center w-100">Nenhuma notícia no momento.</p>';
                        return;
                    }
                    dados.forEach(news => {
                        let img = news.imagem ? news.imagem : 'https://via.placeholder.com/400x300';
                        let tempDiv = document.createElement("div"); tempDiv.innerHTML = news.texto;
                        let resumo = (tempDiv.textContent || tempDiv.innerText || "").substring(0, 90) + '...';

                        let html = `
                            <div class="col-lg-4 col-md-6">
                                <div class="card card-news h-100 shadow-sm border">
                                    <img src="${img}" class="card-img-top">
                                    <div class="card-body d-flex flex-column">
                                        <small class="text-uppercase text-primary fw-bold mb-2">${news.categoria || 'Geral'}</small>
                                        <h5 class="fw-bold mb-3 text-dark">${news.titulo}</h5>
                                        <p class="text-muted small flex-grow-1">${resumo}</p>
                                    </div>
                                </div>
                            </div>`;
                        container.innerHTML += html;
                    });
                }).catch(e => { document.getElementById('lista-noticias').innerHTML = '<p class="text-center">Erro ao carregar notícias.</p>'; });
        }

        function carregarAgenda() {
            fetch('api_agenda.php')
                .then(res => res.json())
                .then(eventos => {
                    const container = document.getElementById('lista-agenda');
                    container.innerHTML = '';
                    
                    if (!eventos || eventos.length === 0) {
                        container.innerHTML = '<div class="alert alert-light w-100 text-center text-muted">Nenhum compromisso agendado.</div>';
                        return;
                    }
        
                    eventos.forEach(evt => {
                        let partesData = evt.data_evento.split('-');
                        let dataObj = new Date(partesData[0], partesData[1] - 1, partesData[2]);
                        let dia = String(dataObj.getDate()).padStart(2, '0');
                        let mes = dataObj.toLocaleString('pt-BR', { month: 'short' }).toUpperCase().replace('.', ''); 
                        let hora = evt.hora_evento ? evt.hora_evento.substring(0, 5) : 'A definir';
        
                        let html = `
                            <div class="col-md-6 col-lg-3">
                                <div class="card h-100 border-0 shadow-sm card-agenda">
                                    <div class="card-body d-flex align-items-center">
                                        <div class="bg-primary text-white rounded p-3 text-center me-3" style="min-width: 75px;">
                                            <h3 class="m-0 fw-bold display-6">${dia}</h3>
                                            <small class="fw-bold" style="font-size:0.7rem">${mes}</small>
                                        </div>
                                        <div>
                                            <h6 class="fw-bold mb-1 text-dark">${evt.titulo}</h6>
                                            <small class="text-muted"><i class="far fa-clock"></i> ${hora} | ${evt.local || 'Local a definir'}</small>
                                        </div>
                                    </div>
                                </div>
                            </div>`;
                        container.innerHTML += html;
                    });
                });
        }

        function carregarEnquete() {
            fetch('api_enquete.php')
                .then(res => res.json())
                .then(resp => {
                    if (!resp.tem_enquete) return;
                    document.getElementById('secao-enquete').classList.remove('d-none');
                    const box = document.getElementById('conteudo-enquete');
                    let titulo = `<h5 class="fw-bold text-center mb-4 text-dark">${resp.dados.titulo}</h5>`;
                    
                    if (resp.ja_votou) {
                        let htmlOpcoes = '<div class="vstack gap-3">';
                        resp.opcoes.forEach(opt => {
                            let pct = resp.total_votos > 0 ? Math.round((opt.votos / resp.total_votos) * 100) : 0;
                            htmlOpcoes += `<div><div class="d-flex justify-content-between small fw-bold mb-1 text-dark"><span>${opt.texto}</span><span>${pct}%</span></div><div class="progress" style="height: 10px;"><div class="progress-bar bg-primary" style="width: ${pct}%"></div></div></div>`;
                        });
                        box.innerHTML = titulo + htmlOpcoes + '</div><div class="alert alert-success mt-4 text-center small"><i class="fas fa-check-circle"></i> Voto computado!</div>';
                    } else {
                        let htmlForm = '<div class="vstack gap-2">';
                        resp.opcoes.forEach(opt => {
                            htmlForm += `<label class="list-group-item d-flex gap-3 align-items-center border rounded p-3" style="cursor: pointer;"><input class="form-check-input flex-shrink-0" type="radio" name="voto_id" value="${opt.id}"><span class="pt-1 fw-bold text-dark">${opt.texto}</span></label>`;
                        });
                        htmlForm += `<button onclick="votar(${resp.dados.id})" class="btn btn-primary w-100 mt-4 fw-bold py-2 rounded-pill shadow">VOTAR</button></div>`;
                        box.innerHTML = titulo + htmlForm;
                    }
                });
        }

        function votar(idEnquete) {
            const selecionado = document.querySelector('input[name="voto_id"]:checked');
            if (!selecionado) { alert("Escolha uma opção!"); return; }
            
            const btn = document.querySelector('button[onclick^="votar"]');
            btn.innerHTML = 'Computando... <i class="fas fa-spinner fa-spin ms-1"></i>'; 
            btn.disabled = true;

            const urlBlindada = `api_enquete.php?acao=votar&id_enquete=${idEnquete}&id_opcao=${selecionado.value}`;

            fetch(urlBlindada, { method: 'POST' })
            .then(res => res.text())
            .then(texto => {
                try {
                    const dados = JSON.parse(texto);
                    if(dados.sucesso) { 
                        carregarEnquete(); 
                    } else {
                        alert("Não foi possível votar: " + (dados.erro || "Você já votou."));
                        btn.innerHTML = 'VOTAR'; 
                        btn.disabled = false;
                    }
                } catch (e) {
                    btn.innerHTML = 'VOTAR'; 
                    btn.disabled = false;
                }
            });
        }

        // ==========================================
        // SISTEMA DE PROJETOS COM MODAL
        // ==========================================
        // Guarda os projetos carregados para não ter que fazer novo fetch no clique
        let dadosProjetosGlobal = [];

        function carregarProjetos() {
            fetch('api_projetos.php')
                .then(res => res.json())
                .then(projetos => {
                    dadosProjetosGlobal = projetos; // Guarda em variável global
                    const container = document.getElementById('lista-projetos');
                    container.innerHTML = '';
                    
                    if (!projetos || projetos.length === 0) {
                        container.innerHTML = '<p class="text-center text-white w-100 opacity-50">Nenhum projeto cadastrado.</p>';
                        return;
                    }

                    projetos.forEach((p, index) => {
                        let badgeClass = p.status === 'Aprovado' || p.status === 'Sancionado' ? 'bg-success' : (p.status === 'Em Tramitação' ? 'bg-warning text-dark' : 'bg-secondary');
                        
                        // Adicionado onclick chamando a função abrirModalProjeto e passando o índice
                        let html = `
                            <div class="col-md-6 col-lg-4">
                                <div class="card h-100 card-projeto shadow p-3" onclick="abrirModalProjeto(${index})">
                                    <div class="card-body d-flex flex-column p-0">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <span class="badge ${badgeClass} rounded-pill px-3">${p.status}</span>
                                            <small class="text-muted fw-bold text-uppercase">${p.tipo} ${p.numero}/${p.ano}</small>
                                        </div>
                                        <h5 class="fw-bold text-dark mb-3" style="display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;">${p.titulo}</h5>
                                        <p class="text-muted small flex-grow-1" style="display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">${p.resumo || 'Clique para ler os detalhes da matéria...'}</p>
                                        
                                        <div class="text-end mt-2 pt-2 border-top">
                                            <span class="text-primary small fw-bold">Ler Detalhes <i class="fas fa-arrow-right ms-1"></i></span>
                                        </div>
                                    </div>
                                </div>
                            </div>`;
                        container.innerHTML += html;
                    });
                });
        }

        // Função ativada pelo clique no cartão
        function abrirModalProjeto(index) {
            const p = dadosProjetosGlobal[index];
            if(!p) return;

            // Define a cor da badge do modal baseada no status
            let badgeClass = p.status === 'Aprovado' || p.status === 'Sancionado' ? 'bg-success' : (p.status === 'Em Tramitação' ? 'bg-warning text-dark' : 'bg-secondary');
            
            // Preenche os dados no HTML do Modal
            const badge = document.getElementById('modalProjBadge');
            badge.className = 'badge rounded-pill px-3 py-2 fw-bold ' + badgeClass;
            badge.innerText = p.status;

            document.getElementById('modalProjTipoNum').innerText = `${p.tipo} ${p.numero}/${p.ano}`;
            document.getElementById('modalProjTitulo').innerText = p.titulo;
            document.getElementById('modalProjResumo').innerText = p.resumo || 'Não há detalhes adicionais cadastrados para esta matéria.';

            // Verifica se tem PDF para adicionar o botão de download
            const btnContainer = document.getElementById('modalProjBtnContainer');
            if (p.arquivo_pdf && p.arquivo_pdf.trim() !== '') {
                btnContainer.innerHTML = `<a href="${p.arquivo_pdf}" target="_blank" class="btn btn-danger fw-bold rounded-pill px-4 py-2 shadow-sm"><i class="fas fa-file-pdf me-2"></i> Baixar Documento (PDF)</a>`;
            } else {
                btnContainer.innerHTML = `<span class="text-muted small"><i class="fas fa-ban me-1"></i> Nenhum anexo em PDF disponível para download.</span>`;
            }

            // Exibe o modal na tela usando a biblioteca do Bootstrap
            var myModal = new bootstrap.Modal(document.getElementById('modalProjetoDetalhe'));
            myModal.show();
        }
    </script>
</body>
</html>