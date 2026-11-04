<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

try {
    require 'config.php';
    require 'protecao.php';
    require 'compras_lib.php';
    require 'compras_ia.php';

    if (!defined('TENANT_ID')) throw new Exception('Tenant não identificado.');
    if (!compras_pode('comprar')) throw new Exception('Sem permissão para ler nota.');

    $cfg = compras_config($pdo);
    if (empty($cfg['ia_leitura_nota'])) {
        throw new Exception('Leitura de nota por IA está desativada.');
    }

    if (empty($_FILES['foto_nota']) || $_FILES['foto_nota']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Envie uma foto da nota.');
    }

    if ((int)$_FILES['foto_nota']['size'] > 12 * 1024 * 1024) {
        throw new Exception('A imagem deve ter no máximo 12 MB.');
    }

    $tmp = $_FILES['foto_nota']['tmp_name'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmp);

    if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
        throw new Exception('Formato não suportado para IA. Use JPG, PNG ou WEBP.');
    }

    $bytes = file_get_contents($tmp);
    if ($bytes === false) throw new Exception('Não foi possível ler a imagem.');

    $dados = compras_ia_ler_nota_binaria($bytes, $mime);

    echo json_encode([
        'sucesso' => true,
        'dados' => $dados
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'sucesso' => false,
        'erro' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}