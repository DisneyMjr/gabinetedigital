<?php
declare(strict_types=1);

require 'conexao.php';

/**
 * CPIIC-ready (nível B) - handler de Ouvidoria
 * - Criptografia AES-256-CBC com IV aleatório por registro
 * - Hash SHA-256 do texto original (integridade)
 * - Anonimato (quando existir no form)
 * - Insert adaptativo: não quebra se alguma coluna não existir
 * - Audit log (se tabela audit_logs existir)
 */

function ip_addr(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }
function user_agent(): string { return substr($_SERVER['HTTP_USER_AGENT'] ?? '-', 0, 255); }
function sha256(string $s): string { return hash('sha256', $s); }

function encrypt_aes256(string $plaintext, string $key32, string &$iv_bin_out): string {
    $key = substr($key32, 0, 32);
    if (strlen($key) < 32) {
        throw new RuntimeException('CHAVE_SECRETA precisa ter pelo menos 32 caracteres.');
    }
    $iv = random_bytes(16);
    $iv_bin_out = $iv;

    $cipher_raw = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($cipher_raw === false) throw new RuntimeException('Falha ao criptografar.');
    return base64_encode($cipher_raw);
}

function table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM INFORMATION_SCHEMA.TABLES 
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function get_columns(PDO $pdo, string $table): array {
    $stmt = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);
    $cols = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $cols[$r['COLUMN_NAME']] = true;
    }
    return $cols;
}

function audit(PDO $pdo, int $tenant_id, ?int $user_id, string $action, ?string $table, $record_id, ?string $before_hash, ?string $after_hash, array $meta = []): void {
    if (!table_exists($pdo, 'audit_logs')) return;

    $stmt = $pdo->prepare("
        INSERT INTO audit_logs
        (tenant_id, user_id, action, table_name, record_id, before_hash, after_hash, meta_json, ip, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $tenant_id,
        $user_id,
        $action,
        $table,
        $record_id !== null ? (string)$record_id : null,
        $before_hash,
        $after_hash,
        !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ip_addr(),
        user_agent()
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}

// 1) Entrada
$tenant_id    = (int)($_POST['tenant_id'] ?? 0);
$setor_id_raw = $_POST['setor_id'] ?? null;
$setor_id     = ($setor_id_raw === null || $setor_id_raw === '' || (int)$setor_id_raw === 0) ? null : (int)$setor_id_raw;

$tipo_assunto = trim((string)($_POST['tipo_assunto'] ?? 'outro'));
$relato       = trim((string)($_POST['relato_bruto'] ?? ''));

if ($tenant_id <= 0 || $relato === '') {
    // tenta voltar para empresa/slug se existir no POST
    $slug = trim((string)($_POST['slug'] ?? ''));
    $redir = $slug !== '' ? "index.php?empresa=" . urlencode($slug) : "index.php";
    header("Location: {$redir}&status=erro");
    exit;
}

// 2) Anonimato (se não existe no form, fica 0)
$is_anonimo = (int)(($_POST['is_anonimo'] ?? 0) ? 1 : 0);

$ident_nome  = null;
$ident_email = null;
$ident_tel   = null;

if ($is_anonimo === 0) {
    $ident_nome  = isset($_POST['ident_nome'])  ? trim((string)$_POST['ident_nome'])  : null;
    $ident_email = isset($_POST['ident_email']) ? trim((string)$_POST['ident_email']) : null;
    $ident_tel   = isset($_POST['ident_tel'])   ? trim((string)$_POST['ident_tel'])   : null;
}

// 3) Integridade + Criptografia
$content_hash = sha256($relato);

$iv_bin = '';
$texto_criptografado = encrypt_aes256($relato, CHAVE_SECRETA, $iv_bin);

// 4) Protocolo
$protocolo = strtoupper(substr(bin2hex(random_bytes(8)), 0, 10));

// 5) Salvar no banco (adaptativo)
try {
    $pdo->beginTransaction();

    // Campos legados (mantém compatibilidade)
    $resumo_ia    = ucfirst($tipo_assunto);
    $categoria_ia = ucfirst($tipo_assunto);
    $urgencia_ia  = 3;
    $sugestao     = 'Triagem recomendada.';

    if (!table_exists($pdo, 'reports')) {
        throw new RuntimeException("Tabela 'reports' não encontrada.");
    }

    $cols = get_columns($pdo, 'reports');

    // Detecta nome da coluna de setor no banco
    $setor_col = null;
    if (isset($cols['setor_id'])) $setor_col = 'setor_id';
    elseif (isset($cols['sector_id'])) $setor_col = 'sector_id';

    // Monta insert apenas com colunas existentes
    $insertCols = [];
    $params = [];

    $add = function(string $col, $val) use (&$insertCols, &$params, $cols) {
        if (isset($cols[$col])) {
            $insertCols[] = $col;
            $params[] = $val;
        }
    };

    $add('tenant_id', $tenant_id);

    if ($setor_col !== null) {
        $add($setor_col, $setor_id);
    }

    $add('protocolo_visualizacao', $protocolo);
    $add('resumo_ia', $resumo_ia);
    $add('categoria_ia', $categoria_ia);
    $add('urgencia_ia', $urgencia_ia);
    $add('sugestao_acao', $sugestao);

    // Texto criptografado legado
    $add('texto_original_criptografado', $texto_criptografado);

    // IV legado (muitas vezes NOT NULL)
    $add('iv', $iv_bin);

    // Campos CPIIC novos (se existirem)
    $add('crypto_iv', $iv_bin);
    $add('content_hash', $content_hash);
    $add('is_anonimo', $is_anonimo);
    $add('ident_nome', $is_anonimo ? null : $ident_nome);
    $add('ident_email', $is_anonimo ? null : $ident_email);
    $add('ident_tel', $is_anonimo ? null : $ident_tel);

    // Validação mínima: precisa ao menos tenant_id + texto criptografado
    if (count($insertCols) < 2) {
        throw new RuntimeException("Não foi possível montar INSERT: colunas insuficientes em reports.");
    }

    $placeholders = implode(',', array_fill(0, count($insertCols), '?'));
    $columnsSql = implode(',', $insertCols);

    $sql = "INSERT INTO reports ($columnsSql) VALUES ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $report_id = (int)$pdo->lastInsertId();

    // CPIIC audit (se tabela existir)
    audit($pdo, $tenant_id, null, 'REPORT_CREATED', 'reports', $report_id, null, $content_hash, [
        'tipo_assunto' => $tipo_assunto,
        'setor_id' => $setor_id,
        'setor_col' => $setor_col,
        'is_anonimo' => $is_anonimo
    ]);

    $pdo->commit();

    // 6) Redirecionar de volta para o portal do tenant
    $slugPost = trim((string)($_POST['slug'] ?? ''));
    if ($slugPost !== '') {
        header("Location: index.php?empresa=" . urlencode($slugPost) . "&status=sucesso&protocolo=" . urlencode($protocolo));
        exit;
    }

    // fallback: tenta descobrir slug por tenant
    if (table_exists($pdo, 'tenants')) {
        $stmtSlug = $pdo->prepare("SELECT slug FROM tenants WHERE id = ? LIMIT 1");
        $stmtSlug->execute([$tenant_id]);
        $slug = (string)$stmtSlug->fetchColumn();
        if ($slug !== '') {
            header("Location: index.php?empresa=" . urlencode($slug) . "&status=sucesso&protocolo=" . urlencode($protocolo));
            exit;
        }
    }

    header("Location: index.php?status=sucesso&protocolo=" . urlencode($protocolo));
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo "Erro ao registrar manifestação: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
}