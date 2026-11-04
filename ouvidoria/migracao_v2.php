<?php
require 'conexao.php';
header('Content-Type: text/html; charset=UTF-8');

$queries = [
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS nivel_acesso VARCHAR(30) NOT NULL DEFAULT 'operador'",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS setor_id INT NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_token VARCHAR(128) NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_expires_at DATETIME NULL",
    "UPDATE users SET nivel_acesso = COALESCE(NULLIF(nivel_acesso,''), perfil, 'operador')",
    "ALTER TABLE reports ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL",
    "ALTER TABLE reports ADD COLUMN IF NOT EXISTS responsavel_id INT NULL",
    "ALTER TABLE reports ADD COLUMN IF NOT EXISTS observacao_interna TEXT NULL",
    "ALTER TABLE reports ADD INDEX IF NOT EXISTS idx_reports_status (tenant_id, status)",
    "CREATE TABLE IF NOT EXISTS audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        user_id INT NULL,
        acao VARCHAR(80) NOT NULL,
        tabela VARCHAR(60) NULL,
        registro_id INT NULL,
        ip VARCHAR(60) NULL,
        user_agent VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_audit_tenant (tenant_id),
        INDEX idx_audit_user (user_id),
        INDEX idx_audit_registro (tabela, registro_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS report_comments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        report_id INT NOT NULL,
        user_id INT NULL,
        comentario TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_comment_report (tenant_id, report_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
];

echo '<h2>Atualização V2</h2>';
foreach ($queries as $sql) {
    try {
        $pdo->exec($sql);
        echo '<div style="color:#087a45;margin:6px 0">OK: ' . htmlspecialchars(substr($sql, 0, 100)) . '...</div>';
    } catch (Throwable $e) {
        echo '<div style="color:#b42318;margin:6px 0">Aviso: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}
echo '<hr><strong>Finalizado.</strong> Apague migracao_v2.php após executar.';
