<?php
// pagamento.php - TELA DE RENOVAÇÃO COM PIX AUTOMÁTICO
session_start();
require 'conexao.php';

// Verifica se está logado
if (!isset($_SESSION['tenant_id'])) {
    header("Location: login.php");
    exit;
}

$tenant_id = $_SESSION['tenant_id'];

// 1. Busca dados da empresa
$stmt = $pdo->prepare("SELECT data_expiracao, nome_empresa FROM tenants WHERE id = ?");
$stmt->execute([$tenant_id]);
$empresa = $stmt->fetch();

if (!$empresa) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// 2. Busca o VALOR ATUAL da mensalidade
$stmtConfig = $pdo->prepare("SELECT valor FROM system_config WHERE chave = 'valor_mensalidade'");
$stmtConfig->execute();
$valor_cobrado = $stmtConfig->fetchColumn();

// Valor padrão se não configurado
if (!$valor_cobrado) {
    $valor_cobrado = '97.00';
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Renovar Acesso | Ouvidoria PRO</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #eef2f7; font-family: 'Segoe UI', sans-serif; }
        .lock-icon { font-size: 70px; color: #dc3545; }
        .card-pay { border: none; box-shadow: 0 15px 35px rgba(0,0,0,0.1); border-radius: 20px; overflow: hidden; }
        .bg-gradient-header { background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%); }
    </style>
</head>
<body class="d-flex align-items-center min-vh-100 py-5">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                
                <div class="card card-pay">
                    <div class="card-body p-0">
                        
                        <div class="text-center p-5 bg-gradient-header border-bottom">
                            <div class="lock-icon mb-3"><i class="bi bi-shield-lock-fill"></i></div>
                            <h3 class="fw-bold text-dark mb-1">Acesso Temporariamente Suspenso</h3>
                            <p class="text-muted small mb-0">
                                Olá, <strong><?php echo htmlspecialchars($empresa['nome_empresa']); ?></strong>. Sua assinatura expirou.
                            </p>
                        </div>

                        <div class="p-4 p-md-5">
                            
                            <div class="alert alert-primary d-flex align-items-center justify-content-between p-3 mb-4 shadow-sm border-0 bg-primary bg-opacity-10 text-primary">
                                <div>
                                    <small class="d-block fw-bold text-uppercase" style="font-size: 0.7rem;">Valor da Renovação</small>
                                    <span class="fs-2 fw-bold">R$ <?php echo number_format($valor_cobrado, 2, ',', '.'); ?></span>
                                </div>
                                <i class="bi bi-check-circle-fill fs-3"></i>
                            </div>

                            <div id="area-pix" class="text-center mb-4 d-none animate__animated animate__fadeIn">
                                <div class="p-3 border rounded bg-white shadow-sm">
                                    <p class="fw-bold text-dark mb-2"><i class="bi bi-qr-code-scan text-primary"></i> Escaneie no App do Banco</p>
                                    
                                    <img id="img-qr" src="" alt="QR Code Pix" width="200" class="img-fluid mb-3 p-1 border">
                                    
                                    <p class="small text-muted mb-1 fw-bold">Ou use o Copia e Cola:</p>
                                    <div class="input-group mb-2">
                                        <input type="text" id="input-copia-cola" class="form-control text-center form-control-sm bg-light" readonly>
                                        <button class="btn btn-outline-primary btn-sm" onclick="copiarPix()"><i class="bi bi-clipboard"></i></button>
                                    </div>
                                    
                                    <div class="spinner-border spinner-border-sm text-primary mt-2" role="status"></div>
                                    <small class="text-primary align-middle ms-1 blink-text">Aguardando pagamento automático...</small>
                                </div>
                            </div>

                            <div id="btn-area" class="d-grid">
                                <button onclick="gerarPix()" class="btn btn-success btn-lg fw-bold shadow-sm py-3" id="btn-pagar">
                                    <i class="bi bi-qr-code"></i> PAGAR VIA PIX AGORA
                                </button>
                                <p class="text-center text-muted small mt-2 mb-0">Liberação imediata após o pagamento.</p>
                            </div>

                            <div class="text-center mt-4 pt-3 border-top">
                                <a href="logout.php" class="text-decoration-none text-muted small">
                                    <i class="bi bi-box-arrow-left"></i> Sair / Trocar Conta
                                </a>
                            </div>

                        </div>
                    </div>
                </div>
                
                <p class="text-center text-muted mt-4 small opacity-75">
                    Sistema de Ouvidoria PRO &copy; <?php echo date('Y'); ?>
                </p>
            </div>
        </div>
    </div>

    <script>
    // Função para chamar a API e gerar o QR Code
    function gerarPix() {
        const btn = document.getElementById('btn-pagar');
        const btnArea = document.getElementById('btn-area');
        const areaPix = document.getElementById('area-pix');
        
        // Efeito de carregamento
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Gerando Pix...';
        btn.disabled = true;

        fetch('api_pagamento.php')
            .then(response => response.json())
            .then(data => {
                if(data.erro) {
                    alert("Atenção: " + data.erro);
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Tentar Novamente';
                } else {
                    // Sucesso: Esconde botão e mostra QR Code
                    btnArea.classList.add('d-none');
                    areaPix.classList.remove('d-none');
                    
                    document.getElementById('img-qr').src = 'data:image/png;base64,' + data.qr_code_base64;
                    document.getElementById('input-copia-cola').value = data.copia_cola;

                    // Inicia o "Polling" (Verificação automática)
                    iniciarVerificacao();
                }
            })
            .catch(err => {
                console.error(err);
                alert("Erro de conexão com o servidor.");
                btn.disabled = false;
                btn.innerHTML = 'Tentar Novamente';
            });
    }

    // Copiar código
    function copiarPix() {
        const input = document.getElementById("input-copia-cola");
        input.select();
        navigator.clipboard.writeText(input.value);
        alert("Código Pix copiado! Cole no seu banco.");
    }

    // Fica perguntando ao servidor a cada 5 segundos se o pagamento caiu
    function iniciarVerificacao() {
        setInterval(() => {
            // Tenta recarregar a página. 
            // Se o Webhook tiver liberado, o arquivo verificar_assinatura.php
            // vai detectar que a data mudou e vai redirecionar para o admin.php
            location.reload(); 
        }, 5000); // 5 segundos
    }
    </script>

    <style>
        .blink-text { animation: blinker 1.5s linear infinite; }
        @keyframes blinker { 50% { opacity: 0.5; } }
    </style>
</body>
</html>