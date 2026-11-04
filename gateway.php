<?php
// gateway.php - Roteador de gateways do SaaS (Vindi / Efí / Mercado Pago)

if (!isset($pdo)) {
  throw new RuntimeException("gateway.php: PDO não disponível (inclua config.php antes).");
}

function get_config_pagamentos(PDO $pdo): array {
  $cfg = $pdo->query("SELECT * FROM config_pagamentos WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
  return $cfg ?: [];
}

function mp_access_token(PDO $pdo): string {
  $cfg = get_config_pagamentos($pdo);
  $token = trim((string)($cfg['mp_access_token'] ?? ''));
  if ($token === '') {
    throw new RuntimeException("Mercado Pago não configurado: informe o Access Token em admin_gateways.php.");
  }
  return $token;
}

/**
 * Retornos esperados:
 * - Pix (Efí/Vindi): ['imagem_base64'=>..., 'copia_cola'=>..., 'fatura_id'=>...]
 * - MercadoPago recorrente: ['checkout_link'=>..., 'assinatura_id'=>...]
 */
function gerarCobrancaSaaS(
  string $nome,
  string $email,
  float $valor,
  string $descricao_venda,
  string $slug_cliente,
  int $tenant_id = 0,
  string $slug_plano = ''
): array {
  global $pdo;

  $cfg = get_config_pagamentos($pdo);
  $ativo = $cfg['gateway_ativo'] ?? 'efi';

  if ($ativo === 'mercadopago') {
    if ((int)$tenant_id <= 0) {
      throw new RuntimeException("tenant_id inválido para Mercado Pago recorrente.");
    }

    require_once __DIR__ . '/mercadopago_recorrente.php';

    // Opcional: Plan ID por plano (mais tolerante)
    $plan_id = null;
    $sp = strtolower($slug_plano);

    if ($sp && !empty($cfg['mp_plano_basico']) && str_contains($sp, 'basico')) $plan_id = $cfg['mp_plano_basico'];
    if ($sp && !empty($cfg['mp_plano_premium']) && str_contains($sp, 'premium')) $plan_id = $cfg['mp_plano_premium'];
    if ($sp && !empty($cfg['mp_plano_profissional']) && str_contains($sp, 'profissional')) $plan_id = $cfg['mp_plano_profissional'];

    return criarAssinaturaSaaS($pdo, (int)$tenant_id, $nome, $email, $valor, $slug_plano ?: $descricao_venda, $plan_id);
  }

  if ($ativo === 'vindi') {
    if (function_exists('gerarCobrancaSaaS_Vindi')) {
      return gerarCobrancaSaaS_Vindi($nome, $email, $valor, $descricao_venda, $slug_cliente, $tenant_id, $slug_plano);
    }
    throw new RuntimeException("Gateway Vindi ativo, mas a função gerarCobrancaSaaS_Vindi não foi encontrada.");
  }

  if ($ativo === 'efi') {
    if (function_exists('gerarCobrancaSaaS_Efi')) {
      return gerarCobrancaSaaS_Efi($nome, $email, $valor, $descricao_venda, $slug_cliente, $tenant_id, $slug_plano);
    }
    throw new RuntimeException("Gateway Efí ativo, mas a função gerarCobrancaSaaS_Efi não foi encontrada.");
  }

  throw new RuntimeException("Gateway ativo inválido em config_pagamentos.gateway_ativo: " . $ativo);
}