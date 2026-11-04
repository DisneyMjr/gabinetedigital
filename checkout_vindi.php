<?php
session_start();
require 'config.php';
require 'vindi_config.php';

// 1. SEGURANÇA BÁSICA
if (!defined('TENANT_ID') || !isset($_SESSION['id'])) {
    header("Location: login.php");
    exit;
}

// 2. BUSCA OS DADOS DO CLIENTE VENCIDO
$stmt = $pdo->prepare("SELECT nome, email_contato, plano FROM tenants WHERE id = ?");
$stmt->execute([TENANT_ID]);
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tenant) die("Cliente não encontrado.");

// 3. DEFINE O VALOR E GERA O PIX NA VINDI
$slug_plano = $tenant['plano'] ?? 'basico';
$vindi_id = $VINDI_PLANOS[$slug_plano] ?? 583273; // Puxa do vindi_config.php

// Pega o preço do banco só para mostrar na tela
$stmt_preco = $pdo->prepare("SELECT preco FROM planos WHERE slug = ?");
$stmt_preco->execute([$slug_plano]);
$valorPlano = $stmt_preco->fetchColumn() ?? 97.00;

// GERA O PIX
$pix = criarAssinaturaVindi($tenant['nome'], $tenant['email_contato'], $vindi_id);

if (isset($pix['erro'])) {
    die("Erro ao conectar com a Vindi: " . json_encode($pix['detalhes']));
}

// 4. SALVA O TXID (FATURA) PARA O RADAR PROCURAR
$txid = $pix['fatura_id'];
$pdo->prepare("UPDATE tenants SET txid_pagamento = ? WHERE id = ?")->execute([$txid, TENANT_ID]);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Acesso Expirado - Gabinete Digital</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-900 text-white flex items-center justify-center min-h-screen">

    <div class="bg-slate-800 p-8 rounded-3xl shadow-2xl max-w-sm w-full text-center border border-slate-700">
        <div class="w-16 h-16 bg-red-500 rounded-full flex items-center justify-center mx-auto mb-4 text-2xl shadow-lg shadow-red-500/30">
            <i class="fas fa-lock"></i>
        </div>
        <h2 class="text-2xl font-black mb-2">Acesso Expirado</h2>
        <p class="text-slate-400 text-sm mb-6">Seu período de utilização terminou. Pague a mensalidade para liberar seu Gabinete instantaneamente.</p>
        
        <p class="text-green-400 font-black text-4xl mb-6">R$ <?= number_format($valorPlano, 2, ',', '.') ?></p>
        
        <div class="bg-white p-3 rounded-2xl inline-block mb-6 shadow-inner border-4 border-slate-700">
            <img src="<?= $pix['imagem_base64'] ?>" alt="QR Code Vindi" class="w-48 h-48">
        </div>

        <button onclick="copiarPix()" class="w-full bg-slate-700 hover:bg-slate-600 p-4 rounded-xl flex items-center justify-center gap-3 transition mb-4">
            <i class="fas fa-copy"></i>
            <span class="font-bold text-sm">Copiar Código Pix</span>
        </button>
        <input type="hidden" id="pix_string" value="<?= $pix['copia_cola'] ?>">

        <div id="status_pagamento" class="mt-4 flex flex-col items-center gap-2 h-16 justify-center">
            <p class="text-[10px] text-slate-400 uppercase tracking-widest font-bold">Aguardando Pagamento...</p>
            <div class="flex gap-1.5">
                <span class="w-2 h-2 bg-green-500 rounded-full animate-bounce"></span>
                <span class="w-2 h-2 bg-green-500 rounded-full animate-bounce" style="animation-delay: 0.2s"></span>
                <span class="w-2 h-2 bg-green-500 rounded-full animate-bounce" style="animation-delay: 0.4s"></span>
            </div>
        </div>
        
        <div class="mt-6 border-t border-slate-700 pt-4">
            <a href="logout.php" class="text-slate-500 text-xs hover:text-white transition">Sair do sistema</a>
        </div>
    </div>

    <script>
    function copiarPix() {
        navigator.clipboard.writeText(document.getElementById("pix_string").value);
        alert("Código Pix copiado! Cole no app do seu banco.");
    }

    // O RADAR DA VINDI - 5 em 5 segundos
    let txid = "<?= $txid ?>";
    let intervalo = setInterval(() => {
        fetch('radar_pix.php?txid=' + txid)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'CONCLUIDA') {
                    clearInterval(intervalo);
                    document.getElementById('status_pagamento').innerHTML = `
                        <div class="text-green-400 font-black flex flex-col items-center animate-pulse">
                            <i class="fas fa-check-circle text-4xl mb-2"></i>
                            <span class="uppercase tracking-widest text-xs">PAGAMENTO APROVADO!</span>
                        </div>
                    `;
                    // Manda o cara pro painel principal destrancado
                    setTimeout(() => { window.location.href = 'admin_painel.php'; }, 2000);
                }
            });
    }, 5000);
    </script>
</body>
</html>