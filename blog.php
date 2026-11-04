<?php
require_once 'config.php';

// --- CONFIGURAÇÃO DA PAGINAÇÃO ---
$itens_por_pagina = 9;
$pagina_atual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
if ($pagina_atual < 1) $pagina_atual = 1;
$offset = ($pagina_atual - 1) * $itens_por_pagina;

// --- BUSCAR DADOS ---
if (defined('TENANT_ID')) {
    try {
        // 1. Contar total de notícias
        $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM noticias WHERE tenant_id = ?");
        $stmtTotal->execute([TENANT_ID]);
        $total_noticias = $stmtTotal->fetchColumn();
        $total_paginas = ceil($total_noticias / $itens_por_pagina);

        // 2. Buscar notícias da página atual
        $stmt = $pdo->prepare("SELECT * FROM noticias WHERE tenant_id = ? ORDER BY data_publicacao DESC LIMIT $itens_por_pagina OFFSET $offset");
        $stmt->execute([TENANT_ID]);
        $noticias = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. Configurações visuais
        $stmtConfig = $pdo->prepare("SELECT * FROM configuracoes WHERE tenant_id = ?");
        $stmtConfig->execute([TENANT_ID]);
        $site_config = $stmtConfig->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $noticias = [];
        $total_paginas = 0;
    }
} else {
    die("Erro de configuração.");
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blog e Notícias - <?= $site_config['nome_vereador'] ?? 'Gabinete Digital' ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        :root { --primary-color: <?= $site_config['cor_primaria'] ?? '#0d6efd' ?>; }
        body { font-family: 'Inter', sans-serif; background: #f8f9fa; }
        .bg-header { background: var(--primary-color); padding: 80px 0; color: white; margin-bottom: 40px; }
        .card-news { border: none; border-radius: 15px; transition: 0.3s; overflow: hidden; height: 100%; box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
        .card-news:hover { transform: translateY(-5px); box-shadow: 0 15px 30px rgba(0,0,0,0.1); }
        .card-news img { height: 220px; object-fit: cover; width: 100%; }
        .badge-cat { position: absolute; top: 15px; right: 15px; background: rgba(0,0,0,0.6); color: white; padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; text-transform: uppercase; font-weight: bold; backdrop-filter: blur(5px); }
        .pagination .page-link { color: var(--primary-color); border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; margin: 0 5px; border: none; font-weight: bold; }
        .pagination .active .page-link { background-color: var(--primary-color); color: white; }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg bg-white shadow-sm fixed-top">
        <div class="container">
            <a class="navbar-brand fw-bold text-uppercase text-primary" href="index.php">
                <i class="fas fa-arrow-left me-2"></i> Voltar ao Início
            </a>
        </div>
    </nav>

    <header class="bg-header text-center mt-5">
        <div class="container">
            <h1 class="fw-bold display-5">Notícias do Mandato</h1>
            <p class="lead opacity-75">Acompanhe nossas ações e projetos.</p>
        </div>
    </header>

    <div class="container pb-5">
        <?php if(count($noticias) > 0): ?>
            <div class="row g-4">
                <?php foreach($noticias as $n): 
                    // CORREÇÃO AQUI: Usando 'imagem' em vez de 'foto'
                    $arquivo_imagem = $n['imagem']; 
                    
                    if (!empty($arquivo_imagem) && file_exists(__DIR__ . '/' . $arquivo_imagem)) {
                        $img = $arquivo_imagem;
                    } else {
                        $img = 'https://via.placeholder.com/800x500?text=' . urlencode($n['titulo']);
                    }

                    $resumo = mb_strimwidth(strip_tags($n['texto']), 0, 120, "...");
                    $data = date('d/m/Y', strtotime($n['data_publicacao']));
                ?>
                <div class="col-md-6 col-lg-4">
                    <a href="noticia.php?id=<?= $n['id'] ?>" class="text-decoration-none text-dark">
                        <div class="card card-news">
                            <div class="position-relative">
                                <img src="<?= $img ?>" alt="<?= htmlspecialchars($n['titulo']) ?>">
                                <span class="badge-cat"><?= $n['categoria'] ?? 'Geral' ?></span>
                            </div>
                            <div class="card-body d-flex flex-column p-4">
                                <small class="text-muted mb-2"><i class="far fa-calendar-alt me-1"></i> <?= $data ?></small>
                                <h5 class="fw-bold mb-3"><?= $n['titulo'] ?></h5>
                                <p class="text-muted small flex-grow-1"><?= $resumo ?></p>
                                <div class="text-primary fw-bold small mt-3">LER MATÉRIA COMPLETA <i class="fas fa-arrow-right ms-1"></i></div>
                            </div>
                        </div>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if($total_paginas > 1): ?>
            <nav class="mt-5">
                <ul class="pagination justify-content-center">
                    <?php for($i=1; $i<=$total_paginas; $i++): ?>
                        <li class="page-item <?= ($i == $pagina_atual) ? 'active' : '' ?>">
                            <a class="page-link" href="?pagina=<?= $i ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
            <?php endif; ?>

        <?php else: ?>
            <div class="text-center py-5 text-muted">
                <i class="far fa-newspaper fa-4x mb-3 opacity-25"></i>
                <h3>Nenhuma notícia encontrada.</h3>
                <p>Em breve teremos novidades por aqui.</p>
            </div>
        <?php endif; ?>
    </div>

    <footer class="bg-dark text-white py-4 text-center mt-auto">
        <div class="container small opacity-75">
            &copy; <?= date('Y') ?> <?= $site_config['nome_vereador'] ?? 'Gabinete Digital' ?>.
        </div>
    </footer>

</body>
</html>