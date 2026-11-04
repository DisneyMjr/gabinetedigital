<?php
require 'config.php'; // Puxa o TENANT_ID e $site_config

// Busca todos os templates do master
$stmt = $pdo->query("SELECT * FROM templates_lp");
$templates_master = $stmt->fetchAll(PDO::FETCH_ASSOC);

$templates_processados = [];

foreach ($templates_master as $tpl) {
    $html = $tpl['html_bruto'];

    // Lógica de substituição: Troca as tags pelos dados reais do config.php
    $html = str_replace('{{NOME_VEREADOR}}', $site_config['nome_vereador'] ?? 'Nome do Candidato', $html);
    $html = str_replace('{{HERO_CARGO}}', $site_config['hero_cargo'] ?? 'Vereador', $html);
    $html = str_replace('{{WHATSAPP}}', preg_replace('/[^0-9]/', '', $site_config['whatsapp_oficial'] ?? ''), $html);
    $html = str_replace('{{FOTO_HERO}}', $site_config['foto_hero'] ?? 'img/default.jpg', $html);

    $templates_processados[] = [
        'id' => $tpl['id'],
        'nome' => $tpl['nome'],
        'html' => $html,
        'preview' => $tpl['preview_url']
    ];
}

header('Content-Type: application/json');
echo json_encode($templates_processados);