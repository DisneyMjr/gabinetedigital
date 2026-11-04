<?php
require_once 'config.php';

// --- 0. SEGURANÇA E DADOS ---
if (!defined('TENANT_ID')) {
    header("Location: index.php");
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// --- 1. BUSCA NOTÍCIA ---
// Alterado para selecionar tudo (*) assim pega 'imagem' ou 'foto'
$stmt = $pdo->prepare("SELECT * FROM noticias WHERE id = ? AND tenant_id = ?");
$stmt->execute([$id, TENANT_ID]);
$noticia = $stmt->fetch(PDO::FETCH_ASSOC);

// Se não existir, redireciona
if (!$noticia) { 
    header("Location: blog.php");
    exit;
}

// --- 2. CONTABILIZAR VISUALIZAÇÃO ---
try {
    $pdo->prepare("UPDATE noticias SET views = views + 1 WHERE id = ?")->execute([$id]);
} catch (Exception $e) { /* Ignora se der erro no update */ }

// --- 3. PREPARAR DADOS (SEO E IMAGEM) ---
$titulo = htmlspecialchars($noticia['titulo']);
$texto_puro = strip_tags($noticia['texto']);
$resumo = mb_strimwidth($texto_puro, 0, 150, "...");

// CORREÇÃO: Tenta pegar 'imagem' (padrão antigo) ou 'foto' (novo)
$nome_arquivo_imagem = !empty($noticia['imagem']) ? $noticia['imagem'] : ($noticia['foto'] ?? '');

$caminho_foto = $nome_arquivo_imagem;
if (!empty($caminho_foto) && file_exists(__DIR__ . '/' . $caminho_foto)) {
    $imagem_exibir = $caminho_foto;
} else {
    // Placeholder se não tiver foto
    $imagem_exibir = "https://via.placeholder.com/1200x600?text=" . urlencode($titulo);
}

// URL absoluta para o WhatsApp
$protocolo = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https://" : "http://";
$url_atual = $protocolo . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
$url_imagem_abs = (strpos($imagem_exibir, 'http') === 0) ? $imagem_exibir : $protocolo . $_SERVER['HTTP_HOST'] . '/' . $imagem_exibir;

// Busca configurações
$stmtConfig = $pdo->prepare("SELECT * FROM configuracoes WHERE tenant_id = ?");
$stmtConfig->execute([TENANT_ID]);
$site_config = $stmtConfig->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $titulo ?></title>
    
    <meta property="og:type" content="article">
    <meta property="og:title" content="<?= $titulo ?>">
    <meta property="og:description" content="<?= $resumo ?>">
    <meta property="og:image" content="<?= $url_imagem_abs ?>">
    <meta property="og:url" content="<?= $url_atual ?>">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Merriweather:wght@300;700&display=swap" rel="stylesheet">
    
    <style>
        :root { --primary-color: <?= $site_config['cor_primaria'] ?? '#0d6efd' ?>; }
        body { font-family: 'Inter', sans-serif; background: #f9f9f9; color: #333; }
        .navbar { background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        
        .header-noticia { padding: 60px 0 40px; text-align: center; max-width: 900px; margin: 0 auto; }
        .badge-categoria { background: var(--primary-color); font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1px; }
        
        .img-container { max-width: 1000px; margin: 0 auto 50px; border-radius: 12px; overflow: hidden; box-shadow: 0 15px 40px rgba(0,0,0,0.1); }
        .img-capa { width: 100%; height: auto; display: block; object-fit: cover; max-height: 600px; }
        
        .news-body { background: white; padding: 60px; border-radius: 20px; box-shadow: 0 5px 20px rgba(0,0,0,0.03); max-width: 850px; margin: -40px auto 60px; position: relative; z-index: 2; font-family: 'Merriweather', serif; font-size: 1.15rem; line-height: 1.8; color: #2c3e50; }
        .news-body img { max-width: 100%; height: auto; border-radius: 8px; margin: 20px 0; }
        
        @media(max-width: 768px) { .news-body { padding: 30px 20px; margin-top: 20px; } .img-capa { max-height: 300px; } }
        .btn-whatsapp { background-color: #25D366; color: white; border: none; font-weight: bold; }
        .btn-whatsapp:hover { background-color: #1ebc57; color: white; }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg fixed-top">
        <div class="container">
            <a href="index.php" class="btn btn-outline-secondary rounded-pill px-3 btn-sm fw-bold"><i class="fas fa-arrow-left me-2"></i> Início</a>
            <span class="navbar-text ms-auto small text-muted fw-bold d-none d-md-block"><?= $site_config['nome_vereador'] ?? 'Blog Oficial' ?></span>
        </div>
    </nav>
    <div style="padding-top: 70px;"></div>

    <header class="header-noticia px-3">
        <?php if(!empty($noticia['categoria'])): ?>
            <span class="badge badge-categoria mb-3 px-3 py-2 rounded-pill"><?= $noticia['categoria'] ?></span>
        <?php endif; ?>
        <h1 class="fw-bold display-5 mb-3 text-dark"><?= $titulo ?></h1>
        <div class="text-muted small fw-bold text-uppercase">
            <i class="far fa-calendar me-2"></i> <?= date('d de F, Y', strtotime($noticia['data_publicacao'])) ?>
            <span class="mx-2">•</span>
            <i class="far fa-eye me-2"></i> <?= $noticia['views'] ?? 0 ?> visualizações
        </div>
    </header>

    <div class="container px-3">
        <div class="img-container">
            <img src="<?= $imagem_exibir ?>" class="img-capa" alt="<?= $titulo ?>">
        </div>
    </div>

    <article class="container px-3">
        <div class="news-body">
            <?= $noticia['texto'] ?>
            <div class="mt-5 pt-4 border-top">
                <h5 class="fw-bold text-center mb-3 font-sans-serif" style="font-family: 'Inter'">Gostou? Compartilhe!</h5>
                <div class="d-flex justify-content-center gap-2">
                    <a href="https://api.whatsapp.com/send?text=<?= urlencode("*$titulo*\nLeia completo em: $url_atual") ?>" target="_blank" class="btn btn-whatsapp rounded-pill px-4 py-2"><i class="fab fa-whatsapp fa-lg me-2"></i> Enviar no WhatsApp</a>
                </div>
            </div>
        </div>
    </article>

    <section class="bg-white py-5 border-top">
        <div class="container">
            <h4 class="fw-bold mb-4 text-center">Últimas do Mandato</h4>
            <div class="row g-4 justify-content-center">
                <?php
                // AQUI ESTAVA O ERRO: Mudei 'foto' para 'imagem' no SELECT
                $stmtRel = $pdo->prepare("SELECT id, titulo, imagem, data_publicacao FROM noticias WHERE tenant_id = ? AND id != ? ORDER BY data_publicacao DESC LIMIT 3");
                $stmtRel->execute([TENANT_ID, $id]);
                $relacionadas = $stmtRel->fetchAll(PDO::FETCH_ASSOC);

                if(count($relacionadas) > 0):
                    foreach($relacionadas as $rel):
                        $arqRel = $rel['imagem'];
                        $imgRel = (!empty($arqRel) && file_exists(__DIR__ . '/' . $arqRel)) ? $arqRel : 'https://via.placeholder.com/400x250?text=Leia+Mais';
                ?>
                <div class="col-md-4">
                    <a href="noticia.php?id=<?= $rel['id'] ?>" class="text-decoration-none">
                        <div class="card h-100 border-0 shadow-sm">
                            <img src="<?= $imgRel ?>" class="card-img-top" style="height: 180px; object-fit: cover;">
                            <div class="card-body">
                                <small class="text-muted"><?= date('d/m/Y', strtotime($rel['data_publicacao'])) ?></small>
                                <h6 class="fw-bold text-dark mt-1"><?= $rel['titulo'] ?></h6>
                            </div>
                        </div>
                    </a>
                </div>
                <?php endforeach; else: ?>
                    <p class="text-center text-muted">Nenhuma outra notícia encontrada.</p>
                <?php endif; ?>
            </div>
            <div class="text-center mt-5">
                <a href="blog.php" class="btn btn-outline-primary rounded-pill px-5 fw-bold">Ver Todas as Notícias</a>
            </div>
        </div>
    </section>

    <footer class="bg-light py-4 text-center border-top mt-auto">
        <div class="container small text-muted">
            &copy; <?= date('Y') ?> <?= $site_config['nome_vereador'] ?? 'Gabinete Digital' ?>.
        </div>
    </footer>

</body>
</html>