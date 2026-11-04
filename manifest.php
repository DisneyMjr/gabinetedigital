<?php
header('Content-Type: application/json');
require_once 'config.php';
// O config.php carrega saas.php e define TENANT_ID

// --- 0. SEGURANÇA SAAS ---
if (!defined('TENANT_ID')) {
    // Se não identificar o cliente, encerra para não gerar manifesto inválido
    http_response_code(404);
    exit;
}

// --- CAMINHO DA IMAGEM ---
// Por enquanto mantemos o ícone padrão. 
// Futuramente, você pode criar um campo 'icone_pwa' no banco para cada cliente subir o seu.
$icone_url = "./img/pwaicon.png"; 

// Valores Padrão (Fallback)
$nome_vereador = "Gabinete Digital";
$primeiro_nome = "Gabinete";
$cor_tema = "#0d6efd"; // Azul padrão do Bootstrap

try {
    // --- 1. BUSCA DADOS DO TENANT ATUAL ---
    // Antes: WHERE id = 1
    // Agora: WHERE tenant_id = ?
    $stmt = $pdo->prepare("SELECT nome_vereador, cor_primaria FROM configuracoes WHERE tenant_id = ?");
    $stmt->execute([TENANT_ID]);
    $conf = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($conf) {
        if (!empty($conf['nome_vereador'])) {
            $nome_vereador = $conf['nome_vereador'];
            $primeiro_nome = explode(' ', trim($nome_vereador))[0];
        }
        // Pega a cor principal configurada no painel para pintar a barra do navegador do celular
        if (!empty($conf['cor_primaria'])) {
            $cor_tema = $conf['cor_primaria'];
        }
    }
} catch (Exception $e) { }

// JSON DINÂMICO
$manifest = [
    "name" => $nome_vereador . " - Digital",
    "short_name" => $primeiro_nome . " App",
    "start_url" => "./gabinete_online.php", // Página que abre ao clicar no ícone
    "display" => "standalone",
    "background_color" => "#f4f6f9",
    "theme_color" => $cor_tema, // AQUI A MÁGICA: A cor muda conforme o cliente!
    "orientation" => "portrait",
    "scope" => "./",
    "icons" => [
        [
            "src" => $icone_url,
            "sizes" => "192x192",
            "type" => "image/png"
        ],
        [
            "src" => $icone_url,
            "sizes" => "512x512",
            "type" => "image/png"
        ]
    ]
];

echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>