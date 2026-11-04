<?php
declare(strict_types=1);

function cpiic_now_utc(): string {
  return gmdate('Y-m-d H:i:s');
}

function cpiic_ip(): string {
  return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function cpiic_ua(): string {
  return substr($_SERVER['HTTP_USER_AGENT'] ?? '-', 0, 255);
}

/**
 * AES-256-CBC com IV aleatório por registro.
 * Requer uma chave 32 bytes (ideal via env var).
 */
function cpiic_encrypt(string $plaintext, string $key32, string &$iv_out): string {
  if (strlen($key32) < 32) throw new RuntimeException('CPIIC_KEY inválida (>=32 bytes).');
  $iv = random_bytes(16);
  $iv_out = $iv;
  $cipher = openssl_encrypt($plaintext, 'AES-256-CBC', substr($key32,0,32), OPENSSL_RAW_DATA, $iv);
  if ($cipher === false) throw new RuntimeException('Falha ao criptografar.');
  return base64_encode($cipher);
}

function cpiic_decrypt(string $cipher_b64, string $key32, string $iv): string {
  $cipher = base64_decode($cipher_b64, true);
  if ($cipher === false) throw new RuntimeException('Cipher inválido.');
  $plain = openssl_decrypt($cipher, 'AES-256-CBC', substr($key32,0,32), OPENSSL_RAW_DATA, $iv);
  if ($plain === false) throw new RuntimeException('Falha ao descriptografar.');
  return $plain;
}

function cpiic_sha256(string $data): string {
  return hash('sha256', $data);
}

/**
 * Audit log imutável (não edite/dele-te; só insere).
 */
function cpiic_audit(PDO $pdo, int $tenant_id, ?int $user_id, string $action, ?string $table, $record_id, ?string $before_hash, ?string $after_hash, array $meta = []): void {
  $stmt = $pdo->prepare("
    INSERT INTO audit_logs
    (tenant_id, user_id, action, table_name, record_id, before_hash, after_hash, meta_json, ip, user_agent, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
    cpiic_ip(),
    cpiic_ua(),
    cpiic_now_utc()
  ]);
}

function cpiic_access(PDO $pdo, int $tenant_id, ?int $user_id, string $event): void {
  $stmt = $pdo->prepare("
    INSERT INTO access_logs (tenant_id, user_id, event, ip, user_agent, created_at)
    VALUES (?, ?, ?, ?, ?, ?)
  ");
  $stmt->execute([$tenant_id, $user_id, $event, cpiic_ip(), cpiic_ua(), cpiic_now_utc()]);
}