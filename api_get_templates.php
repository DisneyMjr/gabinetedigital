<?php
require 'config.php'; // Já traz o TENANT_ID e o $site_config
require 'funcoes_templates.php';

header('Content-Type: application/json');

try {
    // Busca templates ativos no Master
    $stmt = $pdo->query("SELECT * FROM master_templates WHERE status = 1");
    $modelos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $resultado = [];
    foreach ($modelos as $m) {
        $resultado[] = [
            'id' => $m['id'],
            'nome' => $m['nome'],
            'preview' => $m['preview_url'],
            // Enviamos o HTML já com os dados do cliente injetados!
            'html' => processarTemplateSaaS($m['html_bruto'], $site_config)
        ];
    }

    echo json_encode($resultado);

} catch (Exception $e) {
    echo json_encode(['erro' => $e->getMessage()]);
}