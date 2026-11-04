<?php
require 'config.php';
require 'protecao.php';

if (!defined('TENANT_ID')) {
    die("Acesso negado.");
}

if (!isset($_GET['template_id']) || empty($_GET['template_id'])) {
    header("Location: admin_paginas.php?erro=template_invalido");
    exit;
}

$id_template = (int) $_GET['template_id'];

// 1. Busca o modelo master
$stmt = $pdo->prepare("SELECT * FROM master_templates WHERE id = ? AND status = 1 LIMIT 1");
$stmt->execute([$id_template]);
$modelo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$modelo) {
    die("Modelo não encontrado ou inativo.");
}

// 2. Busca configurações do cliente
$stmt_conf = $pdo->prepare("SELECT * FROM configuracoes WHERE tenant_id = ?");
$stmt_conf->execute([TENANT_ID]);
$conf = $stmt_conf->fetch(PDO::FETCH_ASSOC);

if (!$conf) {
    $conf = [];
}

// 3. Monta link do WhatsApp
$whatsapp_limpo = preg_replace('/[^0-9]/', '', $conf['whatsapp_oficial'] ?? '');

$whatsapp_link = !empty($whatsapp_limpo)
    ? 'https://wa.me/55' . $whatsapp_limpo
    : '#';

// 4. Monta tags dinâmicas
$dados_iniciais = [
    '{{NOME_VEREADOR}}'  => $conf['nome_vereador'] ?? 'Nome do Vereador',
    '{{SLUG}}'           => '#',
    '{{CARGO}}'          => $conf['hero_cargo'] ?? 'Vereador',
    '{{WHATSAPP_LINK}}'  => $whatsapp_link,
    '{{COR_PRIMARIA}}'   => $conf['cor_primaria'] ?? '#0d6efd',
    '{{COR_SECUNDARIA}}' => $conf['cor_secundaria'] ?? '#ffc107',
    '{{FOTO_CANDIDATO}}' => $conf['foto_hero'] ?? 'https://via.placeholder.com/600',
    '{{LOGO}}'           => $conf['logo_site'] ?? 'https://via.placeholder.com/150',

    '{{TITULO_HERO}}'    => 'Trabalho e Compromisso',
    '{{TEXTO_HERO}}'     => 'Vamos juntos construir uma cidade melhor.',
    '{{TXT_BOTAO}}'      => 'Fale Comigo'
];

// 5. Substitui tags no HTML bruto
$html_inicial = str_replace(
    array_keys($dados_iniciais),
    array_values($dados_iniciais),
    $modelo['html_bruto']
);

// 6. Gera slug seguro
function gerar_slug($texto) {
    $texto = iconv('UTF-8', 'ASCII//TRANSLIT', $texto);
    $texto = preg_replace('/[^A-Za-z0-9-]+/', '-', $texto);
    $texto = strtolower(trim($texto, '-'));
    return $texto ?: 'landing-page';
}

$slug_base = gerar_slug($modelo['nome']);
$slug_final = $slug_base;
$contador = 2;

while (true) {
    $check = $pdo->prepare("SELECT COUNT(*) FROM landing_pages WHERE tenant_id = ? AND slug = ?");
    $check->execute([TENANT_ID, $slug_final]);

    if ((int)$check->fetchColumn() === 0) {
        break;
    }

    $slug_final = $slug_base . '-' . $contador;
    $contador++;
}

// 7. Detecta colunas da tabela landing_pages
$cols = [];
$stmt_cols = $pdo->query("SHOW COLUMNS FROM landing_pages");

foreach ($stmt_cols->fetchAll(PDO::FETCH_ASSOC) as $col) {
    $cols[$col['Field']] = true;
}

// 8. Remove home anterior
$pdo->prepare("UPDATE landing_pages SET is_home = 0 WHERE tenant_id = ?")
    ->execute([TENANT_ID]);

// 9. Salva HTML pronto no campo correto
$json_dados = json_encode($dados_iniciais, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

try {
    if (isset($cols['html_content'])) {

        $sql = "INSERT INTO landing_pages 
            (tenant_id, titulo, slug, html_content, dados_json, id_template_origem, is_home, criado_em) 
            VALUES (?, ?, ?, ?, ?, ?, 1, NOW())";

        $stmt_insert = $pdo->prepare($sql);
        $stmt_insert->execute([
            TENANT_ID,
            $modelo['nome'],
            $slug_final,
            $html_inicial,
            $json_dados,
            $modelo['id']
        ]);

    } elseif (isset($cols['html'])) {

        $sql = "INSERT INTO landing_pages 
            (tenant_id, titulo, slug, html, dados_json, id_template_origem, is_home, criado_em) 
            VALUES (?, ?, ?, ?, ?, ?, 1, NOW())";

        $stmt_insert = $pdo->prepare($sql);
        $stmt_insert->execute([
            TENANT_ID,
            $modelo['nome'],
            $slug_final,
            $html_inicial,
            $json_dados,
            $modelo['id']
        ]);

    } elseif (isset($cols['conteudo_html'])) {

        $sql = "INSERT INTO landing_pages 
            (tenant_id, titulo, slug, conteudo_html, dados_json, id_template_origem, is_home, criado_em) 
            VALUES (?, ?, ?, ?, ?, ?, 1, NOW())";

        $stmt_insert = $pdo->prepare($sql);
        $stmt_insert->execute([
            TENANT_ID,
            $modelo['nome'],
            $slug_final,
            $html_inicial,
            $json_dados,
            $modelo['id']
        ]);

    } else {
        die("Erro: nenhuma coluna de HTML encontrada na tabela landing_pages.");
    }

} catch (Exception $e) {
    die("Erro ao salvar modelo: " . $e->getMessage());
}

// 10. Volta para lista de páginas
header("Location: admin_paginas.php?sucesso=1");
exit;
?>