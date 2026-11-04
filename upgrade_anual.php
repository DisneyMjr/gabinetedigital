<?php
// upgrade_anual.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';
require_once 'protecao.php';
if (file_exists('gateway.php')) require_once 'gateway.php';

// Proteção extra
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['tenant_id'])) {
    die("Acesso inválido.");
}

$tenant_id = (int)$_POST['tenant_id'];

try {
    // 1. Pega os dados do cliente (Tenant) e do administrador dono da conta
    $stmt = $pdo->prepare("SELECT t.nome, t.slug, t.email_contato, u.email as user_email 
                           FROM tenants t 
                           LEFT JOIN usuarios u ON u.tenant_id = t.id AND u.nome = 'Admin'
                           WHERE t.id = ?");
    $stmt->execute([$tenant_id]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cliente) throw new Exception("Cliente não encontrado.");

    $nome_cliente = $cliente['nome'];
    $email_cliente = $cliente['user_email'] ?: $cliente['email_contato'];
    $slug = $cliente['slug'];

    // ==========================================
    // 2. CONFIGURE O VALOR DO SEU PLANO ANUAL AQUI
    // ==========================================
    // Exemplo: Se o Premium é R$ 197/mês (2364/ano). Com 20% OFF fica R$ 157,60/mês (ou 1891,20/ano).
    // Como a recorrência que criamos no Mercado Pago cobra POR MÊS, você pode passar o valor da parcela mensal com desconto.
    $valor_mensal_com_desconto = 157.60; 
    $nome_do_plano = "Plano Anual (20% OFF)";

    // 3. Gera a cobrança recorrente no Mercado Pago
    if (function_exists('gerarCobrancaSaaS')) {
        $assinatura = gerarCobrancaSaaS($nome_cliente, $email_cliente, $valor_mensal_com_desconto, $nome_do_plano, $slug, $tenant_id, 'anual-promo');
        
        if (isset($assinatura['erro'])) {
            throw new Exception("Erro ao gerar pagamento: " . $assinatura['erro']);
        }

        // 4. Salva o ID da nova transação para o Webhook reconhecer
        $txid = $assinatura['assinatura_id'] ?? '';
        $pdo->prepare("UPDATE tenants SET txid_pagamento = ?, plano = 'anual-promo', status = 'pendente_pagamento' WHERE id = ?")->execute([$txid, $tenant_id]);

        // 5. Redireciona o cliente para o checkout do Mercado Pago
        header("Location: " . $assinatura['checkout_link']);
        exit;
    } else {
        throw new Exception("Gateway de pagamento inativo.");
    }

} catch (Exception $e) {
    die("<div style='font-family:sans-serif; text-align:center; margin-top:50px;'>
            <h2 style='color:red;'>Ops! Ocorreu um erro.</h2>
            <p>" . $e->getMessage() . "</p>
            <a href='admin_painel.php'>Voltar ao Painel</a>
         </div>");
}