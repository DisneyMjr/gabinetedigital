<?php
/**
 * mp_helpers.php
 * Helpers seguros para Mercado Pago/assinaturas.
 * Compatível com PHP 7.4+ e cPanel.
 */

if (!function_exists('app_is_https')) {
    function app_is_https(): bool {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') return true;
        if (!empty($_SERVER['HTTP_CF_VISITOR']) && strpos((string)$_SERVER['HTTP_CF_VISITOR'], 'https') !== false) return true;
        return false;
    }
}

if (!function_exists('app_base_url')) {
    function app_base_url(): string {
        if (defined('BASE_URL') && BASE_URL) return rtrim((string)BASE_URL, '/');
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scheme = app_is_https() ? 'https' : 'https'; // força HTTPS em produção/cPanel
        return $scheme . '://' . preg_replace('/[^a-zA-Z0-9\.\-\:]/', '', $host);
    }
}

if (!function_exists('mp_safe_log')) {
    function mp_safe_log(string $contexto, $mensagem = null): void {
        $dir = __DIR__ . '/_logs';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $file = $dir . '/mercadopago_' . date('Y-m-d') . '.log';
        $linha = '[' . date('Y-m-d H:i:s') . '] [' . $contexto . '] ';
        if (is_array($mensagem) || is_object($mensagem)) {
            $linha .= json_encode($mensagem, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $linha .= (string)$mensagem;
        }
        @file_put_contents($file, $linha . PHP_EOL, FILE_APPEND);
    }
}

if (!function_exists('mp_api_request')) {
    function mp_api_request(string $method, string $url, string $token, ?array $payload = null): array {
        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: MeuGabineteDigital/assinaturas'
        ];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode((string)$raw, true);
        return [
            'ok' => $err === '' && $code >= 200 && $code < 300,
            'code' => $code,
            'json' => is_array($json) ? $json : [],
            'raw' => $raw,
            'error' => $err,
        ];
    }
}

if (!function_exists('mp_extract_id_from_resource')) {
    function mp_extract_id_from_resource($resource): string {
        $resource = trim((string)$resource);
        if ($resource === '') return '';
        $parts = explode('/', $resource);
        return trim((string)end($parts));
    }
}

if (!function_exists('mp_tenant_id_from_external_reference')) {
    function mp_tenant_id_from_external_reference(?string $external): int {
        if (!$external) return 0;
        return preg_match('/TENANT_(\d+)/i', $external, $m) ? (int)$m[1] : 0;
    }
}

if (!function_exists('mp_update_tenant_subscription')) {
    function mp_update_tenant_subscription(PDO $pdo, int $tenant_id, string $status_mp, ?string $assinatura_id = null, ?string $next_payment_date = null): bool {
        if ($tenant_id <= 0) return false;

        $status_mp = strtolower(trim($status_mp));

        // Só pagamento realmente concluído pode ativar ou renovar a conta.
        if (in_array($status_mp, ['approved', 'processed', 'paid'], true)) {
            $vencimento = $next_payment_date && strtotime($next_payment_date) !== false
                ? date('Y-m-d H:i:s', strtotime($next_payment_date))
                : date('Y-m-d H:i:s', strtotime('+31 days'));

            $sql = "UPDATE tenants
                    SET status='ativo', ativo=1, data_vencimento=?,
                        txid_pagamento=COALESCE(NULLIF(?, ''), txid_pagamento)
                    WHERE id=?";
            $pdo->prepare($sql)->execute([$vencimento, (string)$assinatura_id, $tenant_id]);
            return true;
        }

        // Assinatura autorizada, ativa ou pendente não prova pagamento.
        // Apenas sincroniza o ID e preserva o acesso atual.
        if (in_array($status_mp, ['authorized', 'active', 'pending', 'pending_authorized_payment', 'scheduled'], true)) {
            $sql = "UPDATE tenants
                    SET txid_pagamento=COALESCE(NULLIF(?, ''), txid_pagamento)
                    WHERE id=?";
            $pdo->prepare($sql)->execute([(string)$assinatura_id, $tenant_id]);
            return true;
        }

        if (in_array($status_mp, ['cancelled', 'paused', 'rejected', 'finished'], true)) {
            $stmt = $pdo->prepare("SELECT data_vencimento FROM tenants WHERE id=? LIMIT 1");
            $stmt->execute([$tenant_id]);
            $vencimentoAtual = (string)$stmt->fetchColumn();

            // Só bloqueia se o acesso já estiver vencido. Uma tentativa recusada
            // não derruba um período que já foi pago anteriormente.
            if ($vencimentoAtual === '' || strtotime($vencimentoAtual) <= time()) {
                $sql = "UPDATE tenants
                        SET status='bloqueado', ativo=0,
                            txid_pagamento=COALESCE(NULLIF(?, ''), txid_pagamento)
                        WHERE id=?";
                $pdo->prepare($sql)->execute([(string)$assinatura_id, $tenant_id]);
            }
            return true;
        }

        return false;
    }
}
