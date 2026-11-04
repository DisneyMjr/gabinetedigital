<?php
// Liga o caçador de erros para não dar tela branca (Erro 500)
ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'config.php';
require 'protecao.php';

// Tenta verificar pelo tem_acesso ou por uma variável de sessão comum do Master
$eh_master = false;
if (function_exists('tem_acesso') && tem_acesso('master')) { $eh_master = true; }
if (isset($_SESSION['permissoes']) && is_array($_SESSION['permissoes']) && in_array('master', $_SESSION['permissoes'])) { $eh_master = true; }
if (isset($_SESSION['tipo_acesso']) && $_SESSION['tipo_acesso'] == 'master') { $eh_master = true; }

if (!$eh_master) {
    die("Acesso Negado. Sua conta não tem privilégios de Master.");
}

$msg = "";
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $ativo = $_POST['gateway_ativo'] ?? 'efi';

        // TRATAMENTO ANTI-ERRO 500: Se o campo vier vazio, transforma em NULL para o banco aceitar
        $v_basico  = !empty($_POST['vindi_plano_basico']) ? (int)$_POST['vindi_plano_basico'] : null;
        $v_premium = !empty($_POST['vindi_plano_premium']) ? (int)$_POST['vindi_plano_premium'] : null;
        $v_prof    = !empty($_POST['vindi_plano_profissional']) ? (int)$_POST['vindi_plano_profissional'] : null;

        $mp_plano_basico = !empty($_POST['mp_plano_basico']) ? trim($_POST['mp_plano_basico']) : null;
        $mp_plano_premium = !empty($_POST['mp_plano_premium']) ? trim($_POST['mp_plano_premium']) : null;
        $mp_plano_prof = !empty($_POST['mp_plano_profissional']) ? trim($_POST['mp_plano_profissional']) : null;

        $sql = "UPDATE config_pagamentos SET
                gateway_ativo = ?,
                vindi_api_key = ?, vindi_plano_basico = ?, vindi_plano_premium = ?, vindi_plano_profissional = ?,
                efi_client_id = ?, efi_client_secret = ?, efi_nome_certificado = ?, efi_chave_pix = ?,
                mp_access_token = ?, mp_webhook_secret = ?, mp_plano_basico = ?, mp_plano_premium = ?, mp_plano_profissional = ?
                WHERE id = 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $ativo,
            $_POST['vindi_api_key'] ?? null, $v_basico, $v_premium, $v_prof,
            $_POST['efi_client_id'] ?? null, $_POST['efi_client_secret'] ?? null, $_POST['efi_nome_certificado'] ?? null, $_POST['efi_chave_pix'] ?? null,
            $_POST['mp_access_token'] ?? null, $_POST['mp_webhook_secret'] ?? null,
            $mp_plano_basico, $mp_plano_premium, $mp_plano_prof
        ]);

        $msg = "<div class='alert alert-success fw-bold'><i class='fas fa-check-circle me-2'></i> Gateways atualizados! O sistema agora está a usar: " . strtoupper($ativo) . "</div>";

    } catch (PDOException $e) {
        $msg = "<div class='alert alert-danger fw-bold'><i class='fas fa-bug me-2'></i> Erro ao salvar: " . $e->getMessage() . "</div>";
    }
}

$cfg = $pdo->query("SELECT * FROM config_pagamentos WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Configurar Gateways</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-light p-4">

<div class="container max-w-4xl bg-white p-5 rounded shadow">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="text-primary fw-bold mb-0"><i class="fas fa-plug text-warning me-2"></i> Gestor de Pagamentos (SaaS)</h3>
        <a href="master_painel.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
    </div>

    <?= $msg ?>

    <div class="alert alert-info small">
        <strong>Importante:</strong> para Mercado Pago recorrente, configure o <em>Access Token</em> abaixo e a URL do webhook
        <code>/webhook_mp.php</code> no painel do Mercado Pago.
    </div>

    <form method="POST">
        <div class="card border-primary mb-4 shadow-sm">
            <div class="card-header bg-primary text-white fw-bold">GATEWAY ATIVO</div>
            <div class="card-body text-center">
                <select name="gateway_ativo" class="form-select form-select-lg w-75 mx-auto border-2 border-primary fw-bold text-center">
                    <option value="vindi" <?= ($cfg['gateway_ativo'] ?? '') == 'vindi' ? 'selected' : '' ?>>Usar VINDI (Assinaturas)</option>
                    <option value="efi" <?= ($cfg['gateway_ativo'] ?? '') == 'efi' ? 'selected' : '' ?>>Usar EFÍ (Pix Avulso/Skip mTLS)</option>
                    <option value="mercadopago" <?= ($cfg['gateway_ativo'] ?? '') == 'mercadopago' ? 'selected' : '' ?>>Usar MERCADO PAGO (Recorrência)</option>
                </select>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6 mb-3">
                <div class="card h-100 shadow-sm border-0 bg-light">
                    <div class="card-header fw-bold bg-dark text-white"><i class="fas fa-credit-card me-2"></i> Configurações Vindi</div>
                    <div class="card-body">
                        <label class="small fw-bold">Chave de API (Private Key)</label>
                        <input type="text" name="vindi_api_key" class="form-control mb-3 border-secondary" value="<?= htmlspecialchars($cfg['vindi_api_key'] ?? '') ?>">

                        <label class="small fw-bold">ID Plano Básico</label>
                        <input type="number" name="vindi_plano_basico" class="form-control mb-2" value="<?= $cfg['vindi_plano_basico'] ?? '' ?>">

                        <label class="small fw-bold">ID Plano Premium</label>
                        <input type="number" name="vindi_plano_premium" class="form-control mb-2" value="<?= $cfg['vindi_plano_premium'] ?? '' ?>">

                        <label class="small fw-bold">ID Plano Profissional</label>
                        <input type="number" name="vindi_plano_profissional" class="form-control mb-2" value="<?= $cfg['vindi_plano_profissional'] ?? '' ?>">
                    </div>
                </div>
            </div>

            <div class="col-md-6 mb-3">
                <div class="card h-100 shadow-sm border-0 bg-light">
                    <div class="card-header fw-bold text-dark" style="background-color: #f37021;"><i class="fas fa-bolt me-2"></i> Configurações Efí (Pix)</div>
                    <div class="card-body">
                        <label class="small fw-bold">Client ID</label>
                        <input type="text" name="efi_client_id" class="form-control mb-2 border-secondary" value="<?= htmlspecialchars($cfg['efi_client_id'] ?? '') ?>">

                        <label class="small fw-bold">Client Secret</label>
                        <input type="password" name="efi_client_secret" class="form-control mb-3 border-secondary" value="<?= htmlspecialchars($cfg['efi_client_secret'] ?? '') ?>">

                        <label class="small fw-bold">Nome do Arquivo .p12 (ex: producao_123.p12)</label>
                        <input type="text" name="efi_nome_certificado" class="form-control mb-2 border-secondary" value="<?= htmlspecialchars($cfg['efi_nome_certificado'] ?? '') ?>">

                        <label class="small fw-bold">Sua Chave Pix</label>
                        <input type="text" name="efi_chave_pix" class="form-control mb-2 border-secondary" value="<?= htmlspecialchars($cfg['efi_chave_pix'] ?? '') ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12 mb-3">
                <div class="card h-100 shadow-sm border-0 bg-light">
                    <div class="card-header fw-bold text-white" style="background:#009ee3;"><i class="fas fa-sync-alt me-2"></i> Configurações Mercado Pago (Recorrência)</div>
                    <div class="card-body">
                        <label class="small fw-bold">Access Token (produção)</label>
                        <input type="password" name="mp_access_token" class="form-control mb-3 border-secondary" value="<?= htmlspecialchars($cfg['mp_access_token'] ?? '') ?>">

                        <label class="small fw-bold">Webhook Secret (opcional)</label>
                        <input type="password" name="mp_webhook_secret" class="form-control mb-3 border-secondary" value="<?= htmlspecialchars($cfg['mp_webhook_secret'] ?? '') ?>">

                        <div class="row">
                            <div class="col-md-4">
                                <label class="small fw-bold">Plan ID Básico (opcional)</label>
                                <input type="text" name="mp_plano_basico" class="form-control mb-2" value="<?= htmlspecialchars($cfg['mp_plano_basico'] ?? '') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="small fw-bold">Plan ID Premium (opcional)</label>
                                <input type="text" name="mp_plano_premium" class="form-control mb-2" value="<?= htmlspecialchars($cfg['mp_plano_premium'] ?? '') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="small fw-bold">Plan ID Profissional (opcional)</label>
                                <input type="text" name="mp_plano_profissional" class="form-control mb-2" value="<?= htmlspecialchars($cfg['mp_plano_profissional'] ?? '') ?>">
                            </div>
                        </div>

                        <small class="text-muted">Se você não preencher os Plan IDs, o sistema cria a assinatura via <code>/preapproval</code> usando <code>auto_recurring</code>.</small>
                    </div>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-success btn-lg w-100 mt-4 fw-bold shadow"><i class="fas fa-save me-2"></i> Gravar Configurações</button>
    </form>
</div>

</body>
</html>
