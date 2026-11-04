<?php
require 'config.php';
require 'campanha_lib.php';

function pd_json($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    exit('Documento não encontrado.');
}

$s = $pdo->prepare("SELECT p.*, s.ativo AS site_ativo
                    FROM campanha_projetos_leis p
                    INNER JOIN campanha_sites s ON s.tenant_id=p.tenant_id
                    WHERE p.id=? AND p.ativo=1 AND s.ativo=1
                    LIMIT 1");
$s->execute([$id]);
$doc = $s->fetch(PDO::FETCH_ASSOC);
if (!$doc || empty($doc['arquivo_pdf'])) {
    http_response_code(404);
    exit('Documento não encontrado.');
}

$tenant = (int)$doc['tenant_id'];
$leadId = null;

if ((int)($doc['exigir_cadastro_download'] ?? 0) === 1) {
    $cookieName = 'campanha_visitante_' . $tenant;
    $token = trim((string)($_COOKIE[$cookieName] ?? ''));
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        pd_json(['ok'=>false,'cadastro_necessario'=>true,'mensagem'=>'Cadastre-se para liberar este documento.'], 403);
    }

    $q = $pdo->prepare("SELECT id FROM campanha_leads WHERE tenant_id=? AND token_visitante=? LIMIT 1");
    $q->execute([$tenant, $token]);
    $leadId = (int)$q->fetchColumn();
    if ($leadId <= 0) {
        pd_json(['ok'=>false,'cadastro_necessario'=>true,'mensagem'=>'Seu cadastro precisa ser confirmado novamente.'], 403);
    }
}

if (isset($_GET['check'])) {
    pd_json(['ok'=>true,'cadastro_necessario'=>false]);
}

$rel = ltrim((string)$doc['arquivo_pdf'], '/');
if (strpos($rel, 'uploads/campanha/projetos/') !== 0) {
    http_response_code(403);
    exit('Arquivo inválido.');
}

$base = realpath(__DIR__ . '/uploads/campanha/projetos');
$arquivo = realpath(__DIR__ . '/' . $rel);
if (!$base || !$arquivo || strpos($arquivo, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($arquivo)) {
    http_response_code(404);
    exit('Arquivo não encontrado no servidor.');
}

try {
    $pdo->prepare("INSERT INTO campanha_downloads (tenant_id,lead_id,projeto_id,arquivo,ip,user_agent) VALUES (?,?,?,?,?,?)")
        ->execute([
            $tenant,
            $leadId ?: null,
            $id,
            $rel,
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500)
        ]);
} catch (Throwable $e) {
    // O download não deve falhar apenas porque o histórico não pôde ser gravado.
}

$numero = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)($doc['numero'] ?? ''));
$ano = (int)($doc['ano'] ?? 0);
$nome = 'documento';
if ($numero !== '') $nome .= '-' . trim($numero, '-');
if ($ano > 0) $nome .= '-' . $ano;
$nome .= '.pdf';

while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $nome . '"');
header('Content-Length: ' . filesize($arquivo));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, no-cache, must-revalidate');
readfile($arquivo);
exit;
