<?php
header("Content-type: application/xml");
require 'config.php';

$base_url = "https://meugabinetedigital.com.br";

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url>
        <loc><?= $base_url ?>/</loc>
        <lastmod><?= date('Y-m-d') ?></lastmod>
        <changefreq>daily</changefreq>
        <priority>1.0</priority>
    </url>

    <url>
        <loc><?= $base_url ?>/admin_login.php</loc>
        <lastmod><?= date('Y-m-d') ?></lastmod>
        <priority>0.8</priority>
    </url>

    <?php
    // Opcional: Listar os subdomínios dos clientes públicos (Se quiser que eles apareçam no Google)
    // $stmt = $pdo->query("SELECT slug FROM tenants WHERE status = 'ativo'");
    // while ($row = $stmt->fetch()) {
    //     echo "<url>";
    //     echo "<loc>http://" . $row['slug'] . ".meugabinetedigital.com.br/</loc>";
    //     echo "<priority>0.6</priority>";
    //     echo "</url>";
    // }
    ?>
</urlset>