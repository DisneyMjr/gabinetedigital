<?php
require 'config.php';
// O config.php carrega saas.php e define TENANT_ID

// --- 0. SEGURANÇA SAAS ---
if (!defined('TENANT_ID')) {
    die("Erro: Site não identificado.");
}

$slug = $_GET['slug'] ?? '';

// --- 1. BUSCA A PÁGINA (COM FILTRO DE TENANT) ---
// Antes: WHERE slug = ?
// Agora: WHERE slug = ? AND tenant_id = ?
// Nota: Usei 'conteudo_html' pois foi o nome padrão que definimos no editor (admin_lp_editor.php)
$stmt = $pdo->prepare("SELECT * FROM landing_pages WHERE slug = ? AND tenant_id = ?");
$stmt->execute([$slug, TENANT_ID]);
$pagina = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pagina) {
    // Redirecionamento 404 amigável
    header("HTTP/1.0 404 Not Found");
    die("
        <div style='font-family: sans-serif; text-align: center; padding: 50px;'>
            <h1>Página não encontrada</h1>
            <p>O link que você acessou não existe neste gabinete.</p>
            <a href='index.php'>Voltar ao Início</a>
        </div>
    ");
}

// --- 2. CONTADOR DE VISUALIZAÇÕES (OPCIONAL) ---
// Incrementa apenas se a página pertencer ao tenant
$pdo->prepare("UPDATE landing_pages SET visualizacoes = visualizacoes + 1 WHERE id = ? AND tenant_id = ?")
    ->execute([$pagina['id'], TENANT_ID]);

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pagina['titulo']) ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        /* CSS Base */
        body { margin: 0; padding: 0; font-family: 'Segoe UI', sans-serif; overflow-x: hidden; }
        
        /* Se você salvou CSS separado no banco, ele entra aqui: */
        <?= $pagina['css_content'] ?? '' ?>
    </style>
</head>
<body>

    <?= $pagina['conteudo_html'] ?>

    <footer class="bg-dark text-white text-center py-3 mt-auto">
        <small>&copy; <?= date('Y') ?> Gabinete Digital.</small>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>