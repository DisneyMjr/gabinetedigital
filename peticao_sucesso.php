<?php
// ATENÇÃO: Se o seu 'config.php' exige a existência do subdomínio para gerar o TENANT_ID 
// e der erro na página neutra, substitua o 'require config.php' pela conexão PDO manual abaixo.
require 'config.php';

$hash = $_GET['c'] ?? '';

if (empty($hash)) {
    die("<div style='font-family: sans-serif; text-align: center; padding: 50px;'>
            <h2>Página Indisponível</h2>
            <p>Código de campanha inválido ou não informado.</p>
         </div>");
}

// 1. Busca a petição pelo HASH para descobrir qual é o Tenant (cliente)
$stmt = $pdo->prepare("SELECT tenant_id, titulo FROM peticoes WHERE hash_url = ?");
$stmt->execute([$hash]);
$peticao = $stmt->fetch(PDO::FETCH_ASSOC);

// Fallback para campanhas antigas (migração)
if (!$peticao) {
    $stmt = $pdo->prepare("SELECT tenant_id, titulo FROM peticoes WHERE MD5(CONCAT('migracao_', id)) = ?");
    $stmt->execute([$hash]);
    $peticao = $stmt->fetch(PDO::FETCH_ASSOC);
}

$titulo_aba = "Assinatura Confirmada";
$site_config = [];

if ($peticao) {
    $titulo_aba = "Sucesso - " . $peticao['titulo'];
    
    // 2. Busca as configurações (Pixels, Instagram) usando o tenant_id descoberto
    $stmt_seo = $pdo->prepare("SELECT scripts_head, instagram FROM configuracoes WHERE tenant_id = ?");
    $stmt_seo->execute([$peticao['tenant_id']]);
    $site_config = $stmt_seo->fetch(PDO::FETCH_ASSOC);
} else {
    die("<div style='font-family: sans-serif; text-align: center; padding: 50px;'>
            <h2>Página Indisponível</h2>
            <p>Esta campanha não existe ou foi encerrada.</p>
         </div>");
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($titulo_aba) ?></title>

    <!-- CARREGA OS PIXELS GLOBAIS DO SISTEMA -->
    <?= $site_config['scripts_head'] ?? '' ?>
    
    <!-- DICA: SE VOCÊ TIVER UM EVENTO DE LEAD ESPECÍFICO (EX: META PIXEL), PODE COLOCAR AQUI -->
    <script>
        // Exemplo: fbq('track', 'Lead');
    </script>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
        .card-form { border-radius: 20px; border: none; }
    </style>
</head>
<body>

    <div class="container mt-5 text-center">
        <div class="card card-form p-5 mx-auto shadow" style="max-width: 500px; margin-top: 50px;">
            <i class="fas fa-check-circle text-success fa-5x mb-3"></i>
            <h2 class="fw-bold text-success mb-3">Assinatura Confirmada!</h2>
            <p class="text-muted fs-5 mb-4">Muito obrigado por apoiar esta causa. Sua voz é muito importante.</p>
            
            <?php if (!empty($site_config['instagram'])): ?>
                <a href="<?= htmlspecialchars($site_config['instagram']) ?>" target="_blank" class="btn btn-primary btn-lg rounded-pill fw-bold shadow-sm w-100" style="background: linear-gradient(45deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%); border: none;">
                    <i class="fab fa-instagram me-2"></i> Siga nosso Instagram
                </a>
            <?php endif; ?>
            
            <!-- O botão 'Voltar' foi removido propositalmente para não jogar o eleitor na home do seu SaaS -->
        </div>
    </div>

</body>
</html>