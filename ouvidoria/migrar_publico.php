<?php
require 'conexao.php';
function exec_sql(PDO $pdo, string $sql) {
    try { $pdo->exec($sql); echo "<div style='font-family:Arial;margin:6px 0;color:#0a7'>OK</div><pre style='background:#f7f7f7;padding:10px;border-radius:8px;'>".htmlspecialchars($sql)."</pre>"; }
    catch (Exception $e) { echo "<div style='font-family:Arial;margin:6px 0;color:#b00'>ERRO/IGNORADO</div><pre style='background:#fff3f3;padding:10px;border-radius:8px;border-radius:8px;border:1px solid #f1b;'>".htmlspecialchars($sql)."</pre><div style='font-family:Arial;color:#b00'>".htmlspecialchars($e->getMessage())."</div><hr>"; }
}
echo "<h2 style='font-family:Arial'>Migração Ouvidoria Público + LAI</h2>";
exec_sql($pdo, "ALTER TABLE reports 
    ADD COLUMN IF NOT EXISTS is_anonimo TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS iv VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS tipo_manifestacao VARCHAR(30) NULL,
    ADD COLUMN IF NOT EXISTS prazo_resposta DATETIME NULL,
    ADD COLUMN IF NOT EXISTS modulo VARCHAR(20) NOT NULL DEFAULT 'OUVIDORIA',
    ADD COLUMN IF NOT EXISTS nome VARCHAR(150) NULL,
    ADD COLUMN IF NOT EXISTS email VARCHAR(190) NULL,
    ADD COLUMN IF NOT EXISTS telefone VARCHAR(60) NULL
");
exec_sql($pdo, "CREATE TABLE IF NOT EXISTS lai_pedidos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    protocolo VARCHAR(40) NOT NULL,
    nome_solicitante VARCHAR(150) NOT NULL,
    email_solicitante VARCHAR(190) NOT NULL,
    cpf_cnpj VARCHAR(30) NULL,
    pedido_cipher LONGTEXT NOT NULL,
    pedido_iv VARCHAR(255) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'Recebido',
    resposta_publica TEXT NULL,
    prorrogado TINYINT(1) NOT NULL DEFAULT 0,
    justificativa_prorrogacao TEXT NULL,
    prazo_resposta DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    INDEX (tenant_id),
    UNIQUE KEY (protocolo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
exec_sql($pdo, "CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    user_id INT NULL,
    acao VARCHAR(60) NOT NULL,
    tabela VARCHAR(60) NULL,
    registro_id INT NULL,
    ip VARCHAR(60) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (tenant_id),
    INDEX (user_id),
    INDEX (tabela),
    INDEX (registro_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "<hr><b>Concluído.</b> Apague/renomeie este arquivo por segurança.";
?>