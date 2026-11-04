<?php
require 'config.php';

// 1. CAPTURA DOS DADOS DA URL
$tenant_id = filter_input(INPUT_GET, 'tenant_id', FILTER_SANITIZE_NUMBER_INT);
$plano_slug = filter_input(INPUT_GET, 'plano', FILTER_SANITIZE_STRING);

if (!$tenant_id || !$plano_slug) {
    die("<h1>Erro de Checkout</h1><p>Dados de identificação ausentes. Volte à página inicial e tente novamente.</p>");
}

// 2. BUSCA O CLIENTE E O PLANO NO BANCO DE DADOS
try {
    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
    $stmt->execute([$tenant_id]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM planos WHERE slug = ?");
    $stmt->execute([$plano_slug]);
    $plano = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tenant || !$plano) {
        die("<h1>Erro</h1><p>Cliente ou Plano não encontrados no sistema.</p>");
    }

    $valor_formatado = number_format($plano['preco'], 2, '.', ''); // Formato exigido pela API (ex: 197.00)

} catch (PDOException $e) {
    die("Erro no banco de dados: " . $e->getMessage());
}

// ====================================================
// 3. CONFIGURAÇÕES DA API EFÍ (GERENCIANET)
// ====================================================
$client_id = "Client_Id_0620faa3bb7d5a7c88473ac169fa26d844984b54";
$client_secret = "Client_Secret_a4b0c48ff87d0fffe312a6a3f4f7c3bd41fab4a7";

// O caminho absoluto para o seu certificado .pem (OBRIGATÓRIO PARA PIX NA EFÍ)
$caminho_certificado = __DIR__ . '/producao-657093-GabineteDigital.p12'; 

$url_base = "https://pix.api.efipay.com.br"; // URL de Produção

$qr_code_base64 = "";
$pix_copia_cola = "";
$erro_api = "";

// ====================================================
// 4. AUTENTICAÇÃO E GERAÇÃO DO PIX (cURL)
// ====================================================
if (file_exists($caminho_certificado)) {
    // Passo 4.1: Pegar o Token de Acesso (Bearer Token)
    $credenciais = base64_encode($client_id . ':' . $client_secret);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url_base . "/oauth/token");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSLCERT, $caminho_certificado);
    curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'P12');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Basic $credenciais",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['grant_type' => 'client_credentials']));
    
    $resposta_auth = curl_exec($ch);
    $info_auth = curl_getinfo($ch);
    
    if ($info_auth['http_code'] == 200) {
        $auth_data = json_decode($resposta_auth, true);
        $access_token = $auth_data['access_token'];

        // Passo 4.2: Criar a Cobrança Pix
        $txid = "GD" . md5(uniqid(rand(), true)); // ID único para a transação
        $txid = substr($txid, 0, 35); // A Efí exige limite de 35 caracteres
        
        $payload_pix = [
            "calendario" => ["expiracao" => 3600], // Expira em 1 hora
            "valor" => ["original" => $valor_formatado],
            "chave" => "27007d53-ca61-44f8-b662-1e9e0acb683d", // Deve ser a chave Pix cadastrada na Efí
            "infoAdicionais" => [
                ["nome" => "Servico", "valor" => "Assinatura Gabinete Digital"],
                ["nome" => "Plano", "valor" => $plano['nome']]
            ]
        ];

        curl_setopt($ch, CURLOPT_URL, $url_base . "/v2/cob/" . $txid);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $access_token",
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload_pix));
        
        $resposta_cob = curl_exec($ch);
        $cob_data = json_decode($resposta_cob, true);

        // Passo 4.3: Gerar o QR Code a partir do Loc ID
        if (isset($cob_data['loc']['id'])) {
            $loc_id = $cob_data['loc']['id'];
            
            curl_setopt($ch, CURLOPT_URL, $url_base . "/v2/loc/" . $loc_id . "/qrcode");
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
            
            $resposta_qr = curl_exec($ch);
            $qr_data = json_decode($resposta_qr, true);
            
            $qr_code_base64 = $qr_data['imagemQrcode'];
            $pix_copia_cola = $qr_data['qrcode'];

            // Salva o TXID no banco de dados para o Webhook poder identificar depois
            $pdo->prepare("UPDATE tenants SET id_assinatura = ? WHERE id = ?")->execute([$txid, $tenant_id]);
        } else {
            $erro_api = "Erro ao gerar a cobrança: " . ($cob_data['mensagem'] ?? 'Desconhecido');
        }
    } else {
        $erro_api = "Falha na autenticação com a Efí. Verifique as chaves.";
    }
    curl_close($ch);
} else {
    $erro_api = "Certificado .pem não encontrado no diretório especificado.";
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout | Gabinete Digital</title>
    
    <link rel="icon" href="assets/img/favicon.svg" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;500;700;800&family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --primary: #6366f1;
            --dark-bg: #0f172a;
            --text-main: #1e293b;
            --text-muted: #64748b;
        }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; color: var(--text-main); }
        h1, h2, h3 { font-family: 'Outfit', sans-serif; }
        
        .checkout-container { max-width: 900px; margin: 50px auto; padding: 0 20px; }
        .card-custom { background: white; border-radius: 20px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.05); overflow: hidden; }
        
        .resumo-plano { background: var(--dark-bg); color: white; padding: 40px; height: 100%; display: flex; flex-direction: column; justify-content: center; }
        .preco-destaque { font-size: 3rem; font-weight: 800; line-height: 1; margin: 20px 0; color: #10b981; }
        
        .area-pagamento { padding: 40px; }
        .qr-code-box { background: #f1f5f9; padding: 20px; border-radius: 15px; display: inline-block; border: 2px dashed #cbd5e1; margin-bottom: 20px; }
        .qr-code-box img { width: 200px; height: 200px; }
        
        .btn-copiar { background: var(--primary); color: white; border: none; padding: 12px 25px; border-radius: 50px; font-weight: 700; transition: 0.3s; width: 100%; }
        .btn-copiar:hover { background: #4f46e5; transform: translateY(-2px); color: white; }
    </style>
</head>
<body>

<div class="checkout-container">
    <div class="text-center mb-4">
        <h2 class="fw-bold"><i class="fas fa-layer-group text-primary me-2"></i>Gabinete Digital</h2>
        <p class="text-muted">Finalize sua assinatura para liberar o painel.</p>
    </div>

    <div class="card-custom">
        <div class="row g-0">
            <div class="col-md-5">
                <div class="resumo-plano">
                    <span class="badge bg-primary bg-opacity-25 text-light rounded-pill px-3 py-2 mb-3" style="width: fit-content;">PLANO ESCOLHIDO</span>
                    <h3 class="fw-bold mb-0"><?= htmlspecialchars($plano['nome']) ?></h3>
                    
                    <div class="preco-destaque">
                        R$ <?= number_format($plano['preco'], 2, ',', '.') ?>
                    </div>
                    
                    <hr class="border-secondary opacity-25">
                    
                    <ul class="list-unstyled mb-0 text-white-50 small">
                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i> Liberação imediata após o pagamento</li>
                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i> Fatura recorrente automática</li>
                        <li><i class="fas fa-check text-success me-2"></i> Suporte técnico prioritário</li>
                    </ul>
                </div>
            </div>

            <div class="col-md-7 text-center area-pagamento">
                <?php if(!empty($erro_api)): ?>
                    <div class="alert alert-danger mb-4 text-start">
                        <i class="fas fa-exclamation-triangle me-2"></i> <?= $erro_api ?>
                    </div>
                    <p class="text-muted">Por favor, entre em contato com o suporte ou verifique as configurações do sistema.</p>
                <?php elseif(!empty($qr_code_base64)): ?>
                    <h4 class="fw-bold mb-3">Pague via Pix Automático</h4>
                    <p class="text-muted small mb-4">Abra o app do seu banco, escolha a opção Pix e aponte a câmera para o QR Code abaixo.</p>
                    
                    <div class="qr-code-box">
                        <img src="<?= $qr_code_base64 ?>" alt="QR Code Pix">
                    </div>
                    
                    <div class="mt-2 mb-4">
                        <p class="text-muted small fw-bold mb-2">Ou utilize a opção Pix Copia e Cola:</p>
                        <input type="text" id="pixCopiaCola" class="form-control text-center bg-light text-muted" value="<?= $pix_copia_cola ?>" readonly>
                        <button onclick="copiarPix()" class="btn-copiar mt-3"><i class="far fa-copy me-2"></i>Copiar Código Pix</button>
                    </div>

                    <div class="alert alert-success border-0 bg-success bg-opacity-10 text-success small fw-bold d-flex align-items-center justify-content-center">
                        <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                        Aguardando a confirmação do pagamento...
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    function copiarPix() {
        var copyText = document.getElementById("pixCopiaCola");
        copyText.select();
        copyText.setSelectionRange(0, 99999);
        navigator.clipboard.writeText(copyText.value);
        
        var btn = document.querySelector('.btn-copiar');
        btn.innerHTML = '<i class="fas fa-check me-2"></i>Código Copiado!';
        btn.classList.replace('btn-primary', 'btn-success');
        
        setTimeout(function() {
            btn.innerHTML = '<i class="far fa-copy me-2"></i>Copiar Código Pix';
            btn.classList.replace('btn-success', 'btn-primary');
        }, 3000);
    }
</script>

</body>
</html>