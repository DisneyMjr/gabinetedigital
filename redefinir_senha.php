<?php
// redefinir_senha.php
session_start();
require 'config.php';

$msg = "";
// Pega o token da URL (ex: redefinir_senha.php?token=xyz...)
$token = isset($_GET['token']) ? trim($_GET['token']) : '';

// --- 1. VALIDAÇÃO INICIAL DO TOKEN ---
$token_valido = false;
$email_usuario = null;

if (!empty($token)) {
    // Busca o token na tabela de resets
    $stmt = $pdo->prepare("SELECT email, expires_at FROM password_resets WHERE token = ? LIMIT 1");
    $stmt->execute([$token]);
    $reset_request = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($reset_request) {
        // Verifica se expirou (data de expiração < agora)
        if (strtotime($reset_request['expires_at']) < time()) {
            $msg = "<div class='alert alert-danger text-center'>
                        <i class='fas fa-clock mb-2'></i><br>
                        Este link expirou.<br>
                        <a href='esqueci_senha.php' class='alert-link'>Solicite um novo aqui</a>.
                    </div>";
        } else {
            // Token existe e é válido!
            $token_valido = true;
            $email_usuario = $reset_request['email'];
        }
    } else {
        $msg = "<div class='alert alert-danger text-center'>
                    Link inválido ou já utilizado.<br>
                    <a href='esqueci_senha.php' class='alert-link'>Solicite um novo aqui</a>.
                </div>";
    }
} else {
    $msg = "<div class='alert alert-warning text-center'>Token não fornecido. Use o link do seu e-mail.</div>";
}

// --- 2. PROCESSAMENTO DA NOVA SENHA ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $token_valido) {
    $nova_senha = $_POST['senha'];
    $confirmar = $_POST['confirmar'];

    if (strlen($nova_senha) < 6) {
        $msg = "<div class='alert alert-warning'>A senha deve ter pelo menos 6 caracteres.</div>";
    } elseif ($nova_senha !== $confirmar) {
        $msg = "<div class='alert alert-danger'>As senhas não coincidem.</div>";
    } else {
        // Tudo certo, vamos atualizar
        try {
            $pdo->beginTransaction();

            // 1. Criptografa a nova senha
            $hash = password_hash($nova_senha, PASSWORD_DEFAULT);

            // 2. Atualiza na tabela de USUARIOS
            // ATENÇÃO: Se sua tabela for 'admins', mude 'usuarios' para 'admins' aqui
            $stmtUpdate = $pdo->prepare("UPDATE usuarios SET senha = ? WHERE email = ?");
            $stmtUpdate->execute([$hash, $email_usuario]);

            // 3. Deleta o token para não ser usado de novo
            $pdo->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$email_usuario]);

            $pdo->commit();

            // Sucesso! Esconde o formulário
            $token_valido = false; 
            $msg = "<div class='alert alert-success text-center'>
                        <i class='fas fa-check-circle fa-2x mb-3'></i><br>
                        <strong>Senha alterada com sucesso!</strong><br><br>
                        <a href='login.php' class='btn btn-success w-100 fw-bold'>FAZER LOGIN AGORA</a>
                    </div>";

        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = "<div class='alert alert-danger'>Erro ao atualizar senha: " . $e->getMessage() . "</div>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Nova Senha</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f0f2f5; height: 100vh; display: flex; align-items: center; justify-content: center; font-family: 'Montserrat', sans-serif; }
        .card-auth { max-width: 400px; width: 100%; border: none; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
    </style>
</head>
<body>

    <div class="card card-auth p-4 bg-white">
        <div class="text-center mb-4">
            <h4 class="fw-bold text-primary">Redefinir Senha</h4>
            <?php if ($token_valido): ?>
                <p class="text-muted small">Crie uma nova senha segura para sua conta.</p>
            <?php endif; ?>
        </div>

        <?= $msg ?>

        <?php if ($token_valido): ?>
        <form method="POST">
            <div class="mb-3">
                <label class="form-label small fw-bold text-muted">NOVA SENHA</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0"><i class="fas fa-lock text-muted"></i></span>
                    <input type="password" name="senha" class="form-control border-start-0 ps-0" required minlength="6" placeholder="Mínimo 6 caracteres">
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label small fw-bold text-muted">CONFIRMAR NOVA SENHA</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0"><i class="fas fa-lock text-muted"></i></span>
                    <input type="password" name="confirmar" class="form-control border-start-0 ps-0" required minlength="6" placeholder="Repita a senha">
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100 fw-bold py-2">SALVAR NOVA SENHA</button>
        </form>
        <?php endif; ?>
        
        <?php if (!$token_valido && strpos($msg, 'alert-success') === false): ?>
             <div class="text-center mt-3">
                <a href="login.php" class="btn btn-outline-secondary btn-sm">Voltar ao Login</a>
             </div>
        <?php endif; ?>
    </div>

</body>
</html>