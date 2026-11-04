<?php
if (!isset($pdo) || !($pdo instanceof PDO)) {
    return;
}

$host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
$host = preg_replace('/:\d+$/', '', $host);
$host = preg_replace('/^www\./i', '', $host);

if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
    return;
}

try {
    $sql = "
        SELECT id, tenant_id, slug, dominio_personalizado, ativo
        FROM campanha_sites
        WHERE ativo = 1
          AND (
              LOWER(TRIM(BOTH '/' FROM
                  REPLACE(
                      REPLACE(
                          REPLACE(dominio_personalizado, 'https://', ''),
                      'http://', ''),
                  'www.', '')
              )) = ?
          )
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$host]);
    $campanhaDominio = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$campanhaDominio) {
        return;
    }

    $_GET['slug'] = (string)$campanhaDominio['slug'];

    if (!defined('CAMPANHA_DOMINIO_ROTEADA')) {
        define('CAMPANHA_DOMINIO_ROTEADA', true);
    }

    require __DIR__ . '/site_campanha.php';
    exit;

} catch (Throwable $e) {
    return;
}
