<?php
require 'conexao.php';
header('Content-Type: text/html; charset=UTF-8');

$queries = [
    "ALTER TABLE tenants ADD COLUMN IF NOT EXISTS mp_subscription_id VARCHAR(120) NULL",
    "ALTER TABLE tenants ADD COLUMN IF NOT EXISTS mp_subscription_status VARCHAR(30) NULL",
    "ALTER TABLE tenants ADD COLUMN IF NOT EXISTS mp_payer_email VARCHAR(190) NULL",
    "ALTER TABLE tenants ADD COLUMN IF NOT EXISTS mp_next_payment_date DATETIME NULL",
    "ALTER TABLE tenants ADD COLUMN IF NOT EXISTS mp_subscription_updated_at DATETIME NULL",
    "ALTER TABLE tenants ADD INDEX IF NOT EXISTS idx_tenant_mp_subscription (mp_subscription_id)",
    "ALTER TABLE payments ADD COLUMN IF NOT EXISTS gateway VARCHAR(30) NULL",
    "ALTER TABLE payments ADD COLUMN IF NOT EXISTS gateway_payment_id VARCHAR(120) NULL",
    "ALTER TABLE payments ADD COLUMN IF NOT EXISTS gateway_status VARCHAR(30) NULL",
    "ALTER TABLE payments ADD COLUMN IF NOT EXISTS subscription_id VARCHAR(120) NULL",
    "ALTER TABLE payments ADD COLUMN IF NOT EXISTS paid_at DATETIME NULL",
    "ALTER TABLE payments ADD UNIQUE INDEX IF NOT EXISTS uq_gateway_payment (gateway, gateway_payment_id)",
    "CREATE TABLE IF NOT EXISTS subscription_events (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NULL,
        gateway VARCHAR(30) NOT NULL DEFAULT 'mercadopago',
        event_type VARCHAR(80) NULL,
        resource_id VARCHAR(150) NULL,
        payload LONGTEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_subscription_events_tenant (tenant_id),
        INDEX idx_subscription_events_resource (resource_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
];

echo '<h2>Mercado Pago Recorrente</h2>';
foreach ($queries as $sql) {
    try {
        $pdo->exec($sql);
        echo '<div style="color:#087a45;margin:6px 0">OK: ' . htmlspecialchars(substr(preg_replace('/\\s+/', ' ', $sql), 0, 120)) . '...</div>';
    } catch (Throwable $e) {
        echo '<div style="color:#b42318;margin:6px 0">Aviso: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}
echo '<hr><strong>Finalizado.</strong> Apague migracao_v3_mercadopago.php após executar.';
