<?php
session_start();
require 'config.php';

// =========================================================
// CONFIGURAÇÕES DO PHPMAILER
// =========================================================
require 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// =========================================================

// --- BUSCA TOKEN NO .ENV ---
$mp_access_token = getenv('MP_ACCESS_TOKEN') ?: ($_ENV['MP_ACCESS_TOKEN'] ?? '');
if (empty($mp_access_token) && file_exists(__DIR__ . '/.env')) {
    $env_vars = parse_ini_file(__DIR__ . '/.env');
    $mp_access_token = $env_vars['MP_ACCESS_TOKEN'] ?? '';
}

// --- CAPTURA PARCEIRO (AFILIADO) ---
if (isset($_GET['ref']) && !empty($_GET['ref'])) {
    $stmtA = $pdo->prepare("SELECT id, nome FROM parceiros WHERE codigo_ref = ? AND ativo = 1");
    $stmtA->execute([$_GET['ref']]);
    $parceiro = $stmtA->fetch(PDO::FETCH_ASSOC);
    if ($parceiro) {
        $_SESSION['ref_afiliado_id'] = $parceiro['id'];
        $_SESSION['ref_afiliado_nome'] = $parceiro['nome'];
    }
}
$afiliado_id = $_SESSION['ref_afiliado_id'] ?? null;

// --- VERIFICA SE O CLIENTE JÁ ESTÁ LOGADO (UPGRADE DE TESTE) ---
$usuario_logado = isset($_SESSION['tenant_id']);
if ($usuario_logado) {
    $tenant_id = $_SESSION['tenant_id'];
    $stmtT = $pdo->prepare("SELECT nome, email FROM usuarios WHERE tenant_id = ? AND nivel = 'admin' LIMIT 1");
    $stmtT->execute([$tenant_id]);
    $dados_user = $stmtT->fetch(PDO::FETCH_ASSOC);
}

// ==========================================================
// --- BUSCA O PLANO DINAMICAMENTE PELA URL ---
// Ex: assinar.php?plano=premium-anual
// ==========================================================
$slug_plano = $_GET['plano'] ?? 'premium'; // Se não vier nada na URL, pega o premium mensal por padrão

$stmtP = $pdo->prepare("SELECT * FROM planos WHERE slug = ? LIMIT 1");
$stmtP->execute([$slug_plano]);
$plano = $stmtP->fetch(PDO::FETCH_ASSOC);

if (!$plano) die("Erro Crítico: O plano selecionado não foi encontrado no banco de dados.");

// --- CONFIGURA FREQUÊNCIA E TEXTOS DA TELA BASEADO NOS DIAS DO PLANO ---
$freq_mp = 1;
$freq_type_mp = 'months';
$texto_badge = 'Assinatura Mensal';
$texto_preco = '/ mês';

// Se o plano tiver 360 dias ou mais, configura como Anual
if (isset($plano['dias_validade']) && $plano['dias_validade'] >= 360) {
    $freq_type_mp = 'years';
    $texto_badge = 'Assinatura Anual';
    $texto_preco = '/ ano';
}

// ==========================================================
// PROCESSAMENTO DO CHECKOUT
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'assinar') {
    
    // 1. FLUXO PARA CLIENTE NOVO
    if (!$usuario_logado) {
        $nome = trim($_POST['nome']);
        $email = trim($_POST['email']);
        $whatsapp = trim($_POST['whatsapp']);
        $senha = password_hash($_POST['senha'], PASSWORD_DEFAULT);
        
        $tenant_slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $nome)) . rand(100, 999);

        try {
            $pdo->beginTransaction();
            
            // Salva o SLUG do plano real que ele escolheu
            $stmt = $pdo->prepare("INSERT INTO tenants (nome, slug, plano, status, parceiro_id, valor_pago) VALUES (?, ?, ?, 'aguardando_pagamento', ?, ?)");
            $stmt->execute([$nome, $tenant_slug, $plano['slug'], $afiliado_id, $plano['preco']]);
            $tenant_id = $pdo->lastInsertId();
            
            $pdo->prepare("INSERT INTO usuarios (tenant_id, nome, email, senha, nivel) VALUES (?, ?, ?, ?, 'admin')")->execute([$tenant_id, $nome, $email, $senha]);
            $pdo->prepare("INSERT INTO configuracoes (tenant_id, whatsapp_oficial) VALUES (?, ?)")->execute([$tenant_id, $whatsapp]);
            
            $pdo->commit();

            // 📧 ENVIO DE EMAIL DE BOAS-VINDAS (PHPMAILER)
            $mail = new PHPMailer(true);
            try {
                // -> CONFIGURE OS SEUS DADOS SMTP AQUI <-
                $mail->isSMTP();
                $mail->Host       = 'mail.SEUDOMINIO.com.br'; 
                $mail->SMTPAuth   = true;
                $mail->Username   = 'contato@SEUDOMINIO.com.br'; 
                $mail->Password   = 'SUA_SENHA_AQUI'; 
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                $mail->Port       = 465;

                $mail->setFrom('contato@SEUDOMINIO.com.br', 'Gabinete Digital');
                $mail->addAddress($email, $nome);
                $mail->addBCC('seuemailpessoal@gmail.com');

                $mail->CharSet = 'UTF-8';
                $mail->isHTML(true);
                $mail->Subject = 'Bem-vindo ao Meu Gabinete Digital! 🚀';
                $mail->Body    = "
                    <div style='font-family: Arial, sans-serif; color: #333; line-height: 1.6; max-width: 600px; margin: 0 auto; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden;'>
                        <div style='background-color: #4f46e5; padding: 20px; text-align: center;'>
                            <h2 style='color: #ffffff; margin: 0;'>Gabinete Digital</h2>
                        </div>
                        <div style='padding: 30px;'>
                            <h3 style='color: #111827; margin-top: 0;'>Olá, {$nome}!</h3>
                            <p>A sua conta foi criada com sucesso no nosso sistema.</p>
                            <p>Estamos a aguardar a confirmação do pagamento para libertar o seu acesso imediato a todas as ferramentas do plano ".htmlspecialchars($plano['nome']).".</p>
                            
                            <div style='background-color: #f3f4f6; padding: 20px; border-radius: 8px; margin: 25px 0;'>
                                <p style='margin: 0 0 10px 0;'><strong>Os seus dados de acesso:</strong></p>
                                <ul style='margin: 0; padding-left: 20px;'>
                                    <li style='margin-bottom: 5px;'><b>Painel:</b> <a href='https://".$_SERVER['HTTP_HOST']."/login.php'>Acessar Login</a></li>
                                    <li><b>E-mail (Login):</b> {$email}</li>
                                </ul>
                            </div>
                            
                            <p style='font-size: 0.85em; color: #6b7280; text-align: center; margin-top: 30px;'>
                                Se tiver alguma dúvida, basta responder a este e-mail.
                            </p>
                        </div>
                    </div>
                ";
                $mail->send();
            } catch (Exception $e) { }

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $erro = "Erro ao criar a conta no banco de dados.";
        }
    } else {
        // 2. FLUXO PARA CLIENTE EXISTENTE (UPGRADE)
        $nome = $dados_user['nome'];
        $email = $dados_user['email'];
        
        // Atualiza a intenção de plano no banco antes de ir pro MP
        $pdo->prepare("UPDATE tenants SET plano = ? WHERE id = ?")->execute([$plano['slug'], $tenant_id]);
    }

    // 🚀 INTEGRAÇÃO MERCADO PAGO: GERA A ASSINATURA E REDIRECIONA
    if (!isset($erro) && $plano['preco'] > 0 && !empty($mp_access_token)) {
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://api.mercadopago.com/preapproval_plan',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                "reason" => "Assinatura " . $plano['nome'] . " - " . $nome,
                "auto_recurring" => [
                    "frequency" => $freq_mp,
                    "frequency_type" => $freq_type_mp, // Passa a ser 'months' ou 'years' dinamicamente
                    "transaction_amount" => (float)$plano['preco'],
                    "currency_id" => "BRL"
                ],
                "back_url" => "https://" . $_SERVER['HTTP_HOST'] . "/admin_painel.php",
                "external_reference" => "TENANT_" . $tenant_id
            ]),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $mp_access_token,
                'Content-Type: application/json'
            ],
        ));
        
        $response = curl_exec($curl);
        $plan_data = json_decode($response, true);
        curl_close($curl);

        if (isset($plan_data['init_point'])) {
            header("Location: " . $plan_data['init_point']);
            exit;
        } else {
            $erro = "Erro no Mercado Pago: " . ($plan_data['message'] ?? 'Verifique o Token no .env');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Assinar <?= htmlspecialchars($plano['nome']) ?> | Gabinete Digital</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f8fafc; font-family: 'Inter', sans-serif; }
        .card-checkout { border: none; border-radius: 20px; box-shadow: 0 20px 50px rgba(0,0,0,0.05); }
        .btn-checkout { background: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%); color: white; border: none; padding: 18px; border-radius: 50px; font-weight: 700; font-size: 1.1rem; width: 100%; transition: 0.3s; }
        .btn-checkout:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(79, 70, 229, 0.3); color: white; }
        .feature-item { margin-bottom: 1rem; font-size: 0.95rem; color: rgba(255,255,255,0.8); }
        .feature-icon { background: rgba(255,255,255,0.1); border-radius: 50%; padding: 6px; width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; margin-right: 10px; color: #fff; }
    </style>
</head>
<body class="py-5 d-flex align-items-center min-vh-100">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-lg-10 col-xl-9">
            <div class="card card-checkout overflow-hidden">
                <div class="row g-0">
                    
                    <div class="col-md-5 text-white p-5 d-flex flex-column justify-content-between" style="background: #111827;">
                        <div>
                            <span class="badge bg-primary text-uppercase px-3 py-2 rounded-pill mb-4 border border-primary border-opacity-50"><?= $texto_badge ?></span>
                            
                            <h3 class="fw-bold mb-3"><?= htmlspecialchars($plano['nome']) ?></h3>
                            <div class="d-flex align-items-baseline mb-4">
                                <span class="fs-4 me-1">R$</span>
                                <span class="display-4 fw-bold"><?= number_format($plano['preco'], 2, ',', '.') ?></span>
                                <span class="text-white-50 ms-2"><?= $texto_preco ?></span>
                            </div>
                            
                            <div class="feature-item"><span class="feature-icon"><i class="fas fa-check"></i></span> CRM de Eleitores Ilimitado</div>
                            <div class="feature-item"><span class="feature-icon"><i class="fas fa-check"></i></span> Redator IA de Ofícios</div>
                            <div class="feature-item"><span class="feature-icon"><i class="fas fa-check"></i></span> Gestão de Lideranças</div>
                            <div class="feature-item"><span class="feature-icon"><i class="fas fa-check"></i></span> Controle de Projetos</div>
                        </div>
                        
                        <div class="mt-5 small text-white-50 d-flex align-items-center">
                            <i class="fas fa-shield-alt fa-2x me-3 text-success"></i>
                            <span>Pagamento 100% seguro processado pelo <b>Mercado Pago</b>.</span>
                        </div>
                    </div>
                    
                    <div class="col-md-7 p-5 bg-white">
                        
                        <?php if($usuario_logado): ?>
                            <div class="text-center mt-4">
                                <div class="bg-light rounded-circle d-inline-flex align-items-center justify-content-center mb-4" style="width: 80px; height: 80px;">
                                    <i class="fas fa-crown fa-3x text-warning"></i>
                                </div>
                                <h4 class="fw-bold mb-2">Quase lá, <?= explode(' ', $dados_user['nome'])[0] ?>!</h4>
                                <p class="text-muted mb-5">Você já está autenticado no sistema. Clique no botão abaixo para concluir a sua assinatura de forma segura.</p>
                                
                                <?php if(isset($erro)): ?> <div class="alert alert-danger text-start"><?= $erro ?></div> <?php endif; ?>
                                
                                <form method="POST">
                                    <input type="hidden" name="acao" value="assinar">
                                    <button type="submit" class="btn btn-checkout shadow-lg">
                                        IR PARA O PAGAMENTO <i class="fas fa-arrow-right ms-2"></i>
                                    </button>
                                </form>
                            </div>
                        <?php else: ?>
                            <h4 class="fw-bold mb-1">Crie a sua conta</h4>
                            <p class="text-muted small mb-4">Preencha os dados para ativar o seu Gabinete Digital.</p>
                            
                            <?php if(isset($erro)): ?> <div class="alert alert-danger"><?= $erro ?></div> <?php endif; ?>
                            
                            <form method="POST">
                                <input type="hidden" name="acao" value="assinar">
                                
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">NOME DO POLÍTICO OU GABINETE *</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0"><i class="fas fa-user text-muted"></i></span>
                                        <input type="text" name="nome" class="form-control bg-light border-start-0 py-2" required placeholder="Ex: Vereador João Silva">
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">WHATSAPP *</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0"><i class="fab fa-whatsapp text-muted"></i></span>
                                        <input type="text" name="whatsapp" class="form-control bg-light border-start-0 py-2" required placeholder="(00) 00000-0000">
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">E-MAIL (O SEU LOGIN) *</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0"><i class="fas fa-envelope text-muted"></i></span>
                                        <input type="email" name="email" class="form-control bg-light border-start-0 py-2" required placeholder="seu@email.com">
                                    </div>
                                </div>
                                
                                <div class="mb-4">
                                    <label class="form-label small fw-bold text-muted">CRIE UMA SENHA *</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0"><i class="fas fa-lock text-muted"></i></span>
                                        <input type="password" name="senha" class="form-control bg-light border-start-0 py-2" required placeholder="Mínimo 6 caracteres">
                                    </div>
                                </div>
                                
                                <button type="submit" class="btn btn-checkout mt-2">
                                    IR PARA O PAGAMENTO <i class="fas fa-lock ms-2"></i>
                                </button>
                                
                                <div class="text-center mt-4">
                                    <a href="login.php" class="text-decoration-none small text-muted fw-bold">Já tem uma conta? Faça Login.</a>
                                </div>
                            </form>
                        <?php endif; ?>
                        
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>