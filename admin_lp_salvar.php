<?php
require 'config.php';   // 1º O Cérebro (Define quem é o cliente)
require 'protecao.php'; // 2º O Porteiro (Verifica se pode entrar

// --- 0. SEGURANÇA SAAS ---
if (!defined('TENANT_ID')) {
    die(json_encode(['sucesso' => false, 'erro' => 'Tenant não identificado']));
}

// Recebe o JSON do Javascript
$dados = json_decode(file_get_contents('php://input'), true);

if ($dados) {
    $id = $dados['id'];
    $titulo = $dados['titulo'];
    // Cria um slug automático (ex: Campanha Saúde -> campanha-saude)
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $titulo)));
    $html = $dados['html'];
    $css = $dados['css'];

    if ($id) {
        // ATUALIZAR (COM TRAVA DE TENANT)
        // Adicionamos "AND tenant_id = ?" para garantir que a página pertence ao usuário logado
        $sql = "UPDATE landing_pages SET titulo=?, slug=?, html_content=?, css_content=? WHERE id=? AND tenant_id=?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$titulo, $slug, $html, $css, $id, TENANT_ID]);
        echo json_encode(['sucesso' => true]);
    } else {
        // CRIAR NOVA (COM TENANT_ID)
        // Adicionamos a coluna tenant_id e o valor TENANT_ID
        $sql = "INSERT INTO landing_pages (tenant_id, titulo, slug, html_content, css_content) VALUES (?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([TENANT_ID, $titulo, $slug, $html, $css]);
        echo json_encode(['sucesso' => true, 'novo_id' => $pdo->lastInsertId()]);
    }
} else {
    echo json_encode(['sucesso' => false]);
}
?>