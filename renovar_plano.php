<?php
session_start();
require 'config.php';

// Só acessa se tiver vindo do Login expirado
if (!isset($_SESSION['renovacao_email']) || !isset($_SESSION['tenant_id'])) {
    header("Location: login.php");
    exit;
}

$tenant_id = $_SESSION['tenant_id'];
$email_cliente = $_SESSION['renovacao_email'];
$nome_cliente = $_SESSION['renovacao_nome'];

// --- PROCESSAR ESCOLHA DO PLANO (GERAR PIX) ---
$pix_dados = null;
if (isset($_GET['escolher_plano'])) {
    $id_plano = (int)$_GET['escolher_plano'];
    
    // Busca dados do plano escolhido
    $stmt = $pdo->prepare("SELECT * FROM planos WHERE id = ?");
    $stmt->execute([$id_plano]);
    $plano = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($plano && defined('MP_ACCESS_TOKEN')) {
        
        $valor = (float)$plano['preco'];
        $dias = $plano['dias_validade'];
        
        // Dados para o Mercado Pago
        $dados_pix = [
            "transaction_amount" => $valor,
            "description" => "Renovação " . $plano['nome'] . " ($dias dias)",
            "payment_method_id" => "pix",
            "payer" => [
                "email" => $email_cliente,
                "first_name" => explode(' ', $nome_cliente)[0]
            ],
            // IMPORTANTE: No metadata colocamos os dias para saber quanto liberar depois
            "metadata" => [
                "tenant_id" => $tenant_id,
                "dias_liberacao" => $dias,
                "plano_novo" => $plano['slug']
            ]
        ];

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => "https://api.mercadopago.com/v1/payments",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($dados_pix),
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer " . MP_ACCESS_TOKEN,
                "Content-Type: application/json",
                "X-Idempotency-Key: " . uniqid()
            ],
        ]);

        $resposta = json_decode(curl_exec($curl), true);
        curl_close($curl);

        if (isset($resposta['point_of_interaction'])) {
            $pix_dados = [
                'img' => $resposta['point_of_interaction']['transaction_data']['qr_code_base64'],
                'code' => $resposta['point_of_interaction']['transaction_data']['qr_code'],
                'plano' => $plano['nome'],
                'valor' => $valor
            ];
        }
    }
}

// Busca todos os planos ativos
$planos = $pdo->query("SELECT * FROM planos WHERE ativo = 1 ORDER BY preco ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Renovar Assinatura</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        .card-plano { transition: 0.3s; cursor: pointer; border: 2px solid transparent; }
        .card-plano:hover { border-color: #0d6efd; transform: translateY(-5px); }
        .card-selected { border-color: #198754; background-color: #f8fff9; }
    </style>
</head>
<body class="py-5">

<div class="container">
    <div class="text-center mb-5">
        <h2 class="fw-bold text-danger"><i class="fas fa-clock me-2"></i>Seu acesso expirou</h2>
        <p class="text-muted">Escolha um plano abaixo para liberar seu acesso imediatamente.</p>
    </div>

    <?php if($pix_dados): ?>
        <div class="card shadow-lg mx-auto border-0 text-center p-4 mb-5 animate__animated animate__fadeIn" style="max-width: 500px;">
            <div class="mb-3"><span class="badge bg-success px-3 py-2">AGUARDANDO PAGAMENTO</span></div>
            <h4>Plano <?= htmlspecialchars($pix_dados['plano']) ?></h4>
            <h1 class="text-primary fw-bold mb-3">R$ <?= number_format($pix_dados['valor'], 2, ',', '.') ?></h1>
            
            <p class="small text-muted">Escaneie o QR Code abaixo com o App do seu banco:</p>
            <img src="data:image/png;base64,<?= $pix_dados['img'] ?>" class="img-fluid border rounded p-2 mb-3" style="max-width: 250px;">
            
            <div class="input-group mb-2">
                <input type="text" class="form-control text-center" value="<?= $pix_dados['code'] ?>" id="pixCode" readonly>
                <button class="btn btn-outline-secondary" onclick="copiar()"><i class="far fa-copy"></i></button>
            </div>
            <small class="text-muted d-block mb-3">O sistema libera seu acesso automaticamente após o pagamento.</small>
            
            <a href="login.php" class="btn btn-success fw-bold w-100 rounded-pill py-2">
                <i class="fas fa-check me-2"></i> JÁ PAGUEI, TENTAR ENTRAR
            </a>
            <a href="renovar_plano.php" class="btn btn-link text-muted mt-2 btn-sm">Escolher outro plano</a>
        </div>
    <?php else: ?>

        <div class="row justify-content-center g-4">
            <?php foreach($planos as $p): 
                $beneficios = explode("\n", $p['beneficios']);
            ?>
            <div class="col-md-4">
                <div class="card card-plano h-100 shadow-sm border-0 rounded-4">
                    <div class="card-body p-4 text-center d-flex flex-column">
                        <h5 class="fw-bold text-uppercase text-secondary"><?= $p['nome'] ?></h5>
                        <h1 class="my-3 fw-bold text-dark">
                            R$ <?= number_format($p['preco'], 2, ',', '') ?>
                        </h1>
                        <span class="badge bg-light text-dark border mb-4">
                            Validade: <?= $p['dias_validade'] ?> dias
                        </span>
                        
                        <ul class="list-unstyled text-start small text-muted mb-4 flex-grow-1 ps-3">
                            <?php foreach($beneficios as $b): ?>
                                <li class="mb-2"><i class="fas fa-check text-success me-2"></i> <?= trim($b) ?></li>
                            <?php endforeach; ?>
                        </ul>

                        <a href="?escolher_plano=<?= $p['id'] ?>" class="btn btn-outline-primary rounded-pill fw-bold w-100">
                            SELECIONAR ESTE
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <div class="text-center mt-5">
            <a href="login.php" class="text-decoration-none text-muted"><i class="fas fa-sign-out-alt me-1"></i> Sair / Trocar Conta</a>
        </div>

    <?php endif; ?>
</div>

<script>
    function copiar() {
        var copyText = document.getElementById("pixCode");
        copyText.select();
        navigator.clipboard.writeText(copyText.value);
        alert("Código PIX copiado!");
    }
</script>
</body>
</html>