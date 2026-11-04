<?php
// funcoes_templates.php

function automatizarTags($html_bruto, $site_config) {
    // MAPEAMENTO: O que está no HTML Master => O que está no banco do Cliente
    $mapeamento = [
        '{{NOME_VEREADOR}}' => $site_config['nome_vereador'] ?? 'Nome do Parlamentar',
        '{{CARGO}}'         => $site_config['hero_cargo'] ?? 'Vereador',
        '{{WHATSAPP}}'      => preg_replace('/[^0-9]/', '', $site_config['whatsapp_oficial'] ?? ''),
        '{{WHATSAPP_LINK}}' => 'https://wa.me/55' . preg_replace('/[^0-9]/', '', $site_config['whatsapp_oficial'] ?? ''),
        '{{FOTO_CANDIDATO}}'=> !empty($site_config['foto_hero']) ? $site_config['foto_hero'] : 'https://via.placeholder.com/600',
        '{{SLUG}}'          => defined('TENANT_SLUG') ? TENANT_SLUG : 'slug-padrao',
        '{{COR_PRIMARIA}}'  => $site_config['cor_primaria'] ?? '#ff6600',
        '{{COR_SECUNDARIA}}'=> $site_config['cor_secundaria'] ?? '#1a233a',
        '{{INSTAGRAM}}'     => $site_config['instagram'] ?? '',
        '{{BIOGRAFIA}}'     => $site_config['biografia'] ?? 'Breve trajetória política...',
        '{{LOGO}}'          => $site_config['logo_site'] ?? ''
    ];

    // Troca as chaves pelos valores reais
    return str_replace(array_keys($mapeamento), array_values($mapeamento), $html_bruto);
}