<?php
/**
 * assinatura_mp_lib.php
 * Mercado Pago recorrente para cPanel/PHP puro.
 * - Cria assinatura recorrente via /preapproval
 * - Salva ID da assinatura em tenants.txid_pagamento
 * - Processa webhooks payment, subscription_preapproval e subscription_authorized_payment
 */

if (!function_exists('mp_assinatura_log')) {
    function mp_assinatura_log($msg) {
        $dir = __DIR__ . '/_logs';
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        $linha = '[' . date('Y-m-d H:i:s') . '] ' . (is_scalar($msg) ? (string)$msg : print_r($msg, true)) . PHP_EOL;
        @file_put_contents($dir . '/mercadopago_assinaturas.log', $linha, FILE_APPEND);
    }
}

if (!function_exists('mp_site_url')) {
    function mp_site_url($path = '') {
        $host = $_SERVER['HTTP_HOST'] ?? 'meugabinetedigital.com.br';
        $base = 'https://' . $host;
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('mp_token_seguro')) {
    function mp_token_seguro($pdo) {
        if (defined('MP_ACCESS_TOKEN') && trim((string)MP_ACCESS_TOKEN) !== '') return trim((string)MP_ACCESS_TOKEN);
        $token = trim((string)(getenv('MP_ACCESS_TOKEN') ?: ($_ENV['MP_ACCESS_TOKEN'] ?? '')));
        if ($token !== '') return $token;
        try {
            $cfg = $pdo->query("SELECT * FROM config_pagamentos WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
            $token = trim((string)($cfg['mp_access_token'] ?? ''));
            if ($token !== '') return $token;
        } catch (Throwable $e) {}
        throw new RuntimeException('Access Token Mercado Pago não encontrado. Configure MP_ACCESS_TOKEN no .env ou em config_pagamentos.');
    }
}

if (!function_exists('mp_api_request')) {
    function mp_api_request($method, $url, $token, $body = null) {
        $headers = ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];
        if ($method === 'POST' || $method === 'PUT') {
            $headers[] = 'X-Idempotency-Key: ' . bin2hex(random_bytes(16));
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code'=>$code, 'json'=>json_decode((string)$raw, true) ?: [], 'raw'=>$raw, 'error'=>$err];
    }
}

if (!function_exists('mp_api_get')) {
    function mp_api_get($url, $token) { return mp_api_request('GET', $url, $token); }
}
if (!function_exists('mp_api_post')) {
    function mp_api_post($url, $token, $body) { return mp_api_request('POST', $url, $token, $body); }
}

if (!function_exists('mp_column_exists')) {
    function mp_column_exists($pdo, $table, $column) {
        try { $st=$pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?"); $st->execute([$column]); return (bool)$st->fetch(PDO::FETCH_ASSOC); }
        catch(Throwable $e) { return false; }
    }
}

if (!function_exists('mp_table_exists')) {
    function mp_table_exists($pdo, $table) {
        try { $st=$pdo->prepare("SHOW TABLES LIKE ?"); $st->execute([$table]); return (bool)$st->fetchColumn(); }
        catch(Throwable $e) { return false; }
    }
}

if (!function_exists('mp_find_tenant_id')) {
    function mp_find_tenant_id($pdo, $payload) {
        $external = (string)($payload['external_reference'] ?? '');
        if ($external && preg_match('/TENANT[_\-](\d+)/i', $external, $m)) return (int)$m[1];
        $meta = $payload['metadata'] ?? [];
        if (is_array($meta) && !empty($meta['tenant_id'])) return (int)$meta['tenant_id'];
        $preapproval_id = (string)($payload['preapproval_id'] ?? $payload['subscription_id'] ?? $payload['id'] ?? '');
        if ($preapproval_id !== '' && mp_column_exists($pdo, 'tenants', 'txid_pagamento')) {
            $stmt = $pdo->prepare("SELECT id FROM tenants WHERE txid_pagamento = ? LIMIT 1");
            $stmt->execute([$preapproval_id]);
            $id = (int)$stmt->fetchColumn();
            if ($id > 0) return $id;
        }
        return 0;
    }
}

if (!function_exists('mp_proxima_data')) {
    function mp_proxima_data($dados, $diasPadrao = 31) {
        foreach (['next_payment_date','date_next_payment','period_end','current_period_end'] as $k) {
            if (!empty($dados[$k]) && strtotime((string)$dados[$k]) !== false) return date('Y-m-d H:i:s', strtotime((string)$dados[$k]));
        }
        return date('Y-m-d H:i:s', strtotime('+' . (int)$diasPadrao . ' days'));
    }
}

if (!function_exists('mp_ativar_tenant')) {
    function mp_ativar_tenant($pdo, $tenant_id, $vencimento, $assinaturaId = null, $plano = null) {
        if ((int)$tenant_id <= 0) return;
        $sets = ["status = 'ativo'", "data_vencimento = ?"];
        $params = [$vencimento];
        if (mp_column_exists($pdo, 'tenants', 'ativo')) $sets[] = "ativo = 1";
        if ($assinaturaId !== null && mp_column_exists($pdo, 'tenants', 'txid_pagamento')) { $sets[] = "txid_pagamento = ?"; $params[] = $assinaturaId; }
        if ($plano !== null && $plano !== '' && mp_column_exists($pdo, 'tenants', 'plano')) { $sets[] = "plano = ?"; $params[] = $plano; }
        $params[] = (int)$tenant_id;
        $pdo->prepare("UPDATE tenants SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        mp_assinatura_log("Tenant {$tenant_id} ativo até {$vencimento}");
    }
}

if (!function_exists('mp_suspender_tenant')) {
    function mp_suspender_tenant($pdo, $tenant_id, $motivo = '') {
        if ((int)$tenant_id <= 0) return;
        $sets = ["status = 'bloqueado'"];
        if (mp_column_exists($pdo, 'tenants', 'ativo')) $sets[] = "ativo = 0";
        $pdo->prepare("UPDATE tenants SET " . implode(', ', $sets) . " WHERE id = ?")->execute([(int)$tenant_id]);
        mp_assinatura_log("Tenant {$tenant_id} bloqueado. {$motivo}");
    }
}

if (!function_exists('mp_registrar_fatura')) {
    function mp_registrar_fatura($pdo, $tenant_id, $dados, $status = 'pago') {
        if ((int)$tenant_id <= 0 || !mp_table_exists($pdo, 'faturas')) return;
        try {
            $valor = (float)($dados['transaction_amount'] ?? $dados['amount'] ?? $dados['total_paid_amount'] ?? 0);
            $paymentId = (string)($dados['payment_id'] ?? $dados['id'] ?? '');
            if (mp_column_exists($pdo, 'faturas', 'txid') && $paymentId !== '') {
                $st=$pdo->prepare("SELECT id FROM faturas WHERE txid=? LIMIT 1"); $st->execute([$paymentId]); if ($st->fetchColumn()) return;
            }
            $cols=['tenant_id','valor','status','data_vencimento'];
            $vals=[(int)$tenant_id,$valor,$status,mp_proxima_data($dados)];
            if (mp_column_exists($pdo,'faturas','data_pagamento')) { $cols[]='data_pagamento'; $vals[]=date('Y-m-d H:i:s'); }
            if (mp_column_exists($pdo,'faturas','descricao')) { $cols[]='descricao'; $vals[]='Assinatura Mercado Pago'; }
            if (mp_column_exists($pdo,'faturas','txid')) { $cols[]='txid'; $vals[]=$paymentId; }
            $ph=implode(',', array_fill(0,count($cols),'?'));
            $pdo->prepare("INSERT INTO faturas (`" . implode('`,`',$cols) . "`) VALUES ($ph)")->execute($vals);
        } catch(Throwable $e) { mp_assinatura_log('Não registrou fatura: '.$e->getMessage()); }
    }
}

if (!function_exists('mp_criar_assinatura_recorrente')) {
    function mp_criar_assinatura_recorrente($pdo, $tenant, $plano) {
        $token = mp_token_seguro($pdo);
        $tenantId = (int)$tenant['id'];
        $slug = (string)$plano['slug'];
        $valor = (float)$plano['preco'];
        if ($tenantId <= 0 || $valor <= 0) throw new RuntimeException('Tenant ou valor inválido.');

        $hostPrincipal = defined('DOMINIO_PRINCIPAL') ? DOMINIO_PRINCIPAL : 'meugabinetedigital.com.br';
        $backUrl = 'https://' . ($tenant['slug'] ?? '') . '.' . $hostPrincipal . '/renovar_conta.php?mp_retorno=1';
        $notificationUrl = 'https://' . $hostPrincipal . '/webhook_mp.php';
        $email = trim((string)($tenant['email_contato'] ?? ''));
        if ($email === '') throw new RuntimeException('E-mail do tenant vazio. Cadastre email_contato no tenant.');

        $body = [
            'reason' => 'Assinatura Meu Gabinete Digital - ' . ((string)($plano['nome'] ?? $slug)),
            'external_reference' => 'TENANT_' . $tenantId . '_PLANO_' . $slug,
            'payer_email' => $email,
            'back_url' => $backUrl,
            'notification_url' => $notificationUrl,
            'status' => 'pending',
            'auto_recurring' => [
                'frequency' => 1,
                'frequency_type' => 'months',
                'transaction_amount' => $valor,
                'currency_id' => 'BRL'
            ],
            'metadata' => [
                'tenant_id' => $tenantId,
                'plano_novo' => $slug,
                'dias_liberacao' => 31
            ]
        ];

        $res = mp_api_post('https://api.mercadopago.com/preapproval', $token, $body);
        if (!in_array($res['code'], [200,201], true) || empty($res['json']['id'])) {
            mp_assinatura_log('ERRO CRIAR ASSINATURA: HTTP '.$res['code'].' RAW: '.substr((string)$res['raw'],0,1200));
            throw new RuntimeException('Mercado Pago não criou a assinatura. Veja _logs/mercadopago_assinaturas.log');
        }

        $sub = $res['json'];
        $assinaturaId = (string)$sub['id'];
        $initPoint = (string)($sub['init_point'] ?? $sub['sandbox_init_point'] ?? '');
        $sets = ['plano = ?', 'txid_pagamento = ?'];
        $params = [$slug, $assinaturaId];
        if (mp_column_exists($pdo, 'tenants', 'mp_init_point')) { $sets[]='mp_init_point = ?'; $params[]=$initPoint; }
        $params[] = $tenantId;
        $pdo->prepare('UPDATE tenants SET '.implode(', ',$sets).' WHERE id = ?')->execute($params);
        mp_assinatura_log("Assinatura criada tenant={$tenantId} plano={$slug} preapproval={$assinaturaId}");
        return ['id'=>$assinaturaId, 'init_point'=>$initPoint, 'raw'=>$sub];
    }
}

if (!function_exists('mp_processar_preapproval')) {
    function mp_processar_preapproval($pdo, $preapprovalId, $token) {
        $r = mp_api_get('https://api.mercadopago.com/preapproval/' . urlencode($preapprovalId), $token);
        if ($r['code'] !== 200) {
            $r = mp_api_get('https://api.mercadopago.com/preapproval/search?id=' . urlencode($preapprovalId), $token);
            if ($r['code'] === 200 && isset($r['json']['results'][0])) $r['json'] = $r['json']['results'][0];
        }
        if ($r['code'] !== 200 || empty($r['json'])) { mp_assinatura_log("Falha preapproval {$preapprovalId}. HTTP {$r['code']} RAW: ".substr((string)$r['raw'],0,600)); return ['ok'=>false]; }
        $sub = $r['json'];
        $tenant_id = mp_find_tenant_id($pdo, $sub);
        $status = strtolower((string)($sub['status'] ?? ''));
        if ($tenant_id <= 0) { mp_assinatura_log("Preapproval {$preapprovalId} sem tenant."); return ['ok'=>false,'tenant_id'=>0,'status'=>$status]; }
        if (in_array($status, ['authorized','active'], true)) mp_ativar_tenant($pdo, $tenant_id, mp_proxima_data($sub), (string)($sub['id'] ?? $preapprovalId));
        elseif (in_array($status, ['cancelled','paused','finished'], true)) mp_suspender_tenant($pdo, $tenant_id, "status MP: {$status}");
        return ['ok'=>true,'tenant_id'=>$tenant_id,'status'=>$status,'data'=>$sub];
    }
}

if (!function_exists('mp_processar_authorized_payment')) {
    function mp_processar_authorized_payment($pdo, $authorizedPaymentId, $token) {
        $r = mp_api_get('https://api.mercadopago.com/authorized_payments/' . urlencode($authorizedPaymentId), $token);
        if ($r['code'] !== 200 || empty($r['json'])) { mp_assinatura_log("Falha authorized_payment {$authorizedPaymentId}. HTTP {$r['code']} RAW: ".substr((string)$r['raw'],0,600)); return ['ok'=>false]; }
        $ap = $r['json'];
        $status = strtolower((string)($ap['status'] ?? $ap['payment_status'] ?? ''));
        $preapprovalId = (string)($ap['preapproval_id'] ?? $ap['subscription_id'] ?? ($ap['preapproval']['id'] ?? ''));
        $tenant_id = mp_find_tenant_id($pdo, $ap);
        if ($tenant_id <= 0 && $preapprovalId !== '') { $st=$pdo->prepare('SELECT id FROM tenants WHERE txid_pagamento = ? LIMIT 1'); $st->execute([$preapprovalId]); $tenant_id=(int)$st->fetchColumn(); }
        if ($tenant_id <= 0 && $preapprovalId !== '') { $sub=mp_processar_preapproval($pdo,$preapprovalId,$token); $tenant_id=(int)($sub['tenant_id'] ?? 0); }
        if ($tenant_id <= 0) { mp_assinatura_log("Authorized payment {$authorizedPaymentId} sem tenant. preapproval={$preapprovalId}"); return ['ok'=>false,'status'=>$status]; }
        if (in_array($status, ['approved','authorized','processed','paid'], true)) {
            $venc = mp_proxima_data($ap, 31);
            if (strtotime($venc) < strtotime('+25 days')) $venc = date('Y-m-d H:i:s', strtotime('+31 days'));
            mp_ativar_tenant($pdo, $tenant_id, $venc, $preapprovalId ?: null);
            mp_registrar_fatura($pdo, $tenant_id, $ap, 'pago');
        } else { mp_assinatura_log("Authorized payment {$authorizedPaymentId} tenant={$tenant_id} status={$status}; não renovou."); }
        return ['ok'=>true,'tenant_id'=>$tenant_id,'status'=>$status,'data'=>$ap];
    }
}
