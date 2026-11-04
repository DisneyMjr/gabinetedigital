<?php
// login.php - VERSÃO FINAL COM BLOQUEIO DE EMPRESA
require 'security.php';
start_secure_session();
require 'conexao.php';

$erro = '';
$tentativas = $_SESSION['login_tentativas'] ?? ['count' => 0, 'first' => time()];
if ((time() - ($tentativas['first'] ?? time())) > 900) { $tentativas = ['count' => 0, 'first' => time()]; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($tentativas['count'] ?? 0) >= 8) {
        $erro = 'Muitas tentativas. Aguarde alguns minutos e tente novamente.';
    } else {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $senha = $_POST['senha'] ?? '';

    if (empty($email) || empty($senha)) {
        $erro = "Preencha todos os campos.";
    } else {
        // SQL QUE VERIFICA O USUÁRIO E O STATUS DA EMPRESA AO MESMO TEMPO
        $sql = "SELECT users.*, tenants.ativo as empresa_ativa 
                FROM users 
                JOIN tenants ON users.tenant_id = tenants.id 
                WHERE users.email = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // 1. Verifica se o usuário existe e a senha bate
        if ($user && password_verify($senha, $user['senha_hash'])) {
            
            // 2. VERIFICA SE A EMPRESA ESTÁ BLOQUEADA
            if ($user['empresa_ativa'] == 0) {
                $erro = "🚫 O acesso da sua empresa está suspenso. Contate o suporte.";
            } else {
                // 3. SE TUDO OK, FAZ O LOGIN
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['tenant_id'] = $user['tenant_id'];
                $_SESSION['user_nome'] = $user['nome'];
                $nivel = $user['nivel_acesso'] ?? $user['perfil'] ?? 'operador';
                $_SESSION['user_perfil'] = $nivel;
                $_SESSION['nivel_acesso'] = $nivel;
                $_SESSION['setor_id'] = $user['setor_id'] ?? null;
                $_SESSION['login_tentativas'] = ['count' => 0, 'first' => time()];

                // Redireciona para o painel
                header("Location: admin.php");
                exit;
            }

        } else {
            $tentativas['count'] = ($tentativas['count'] ?? 0) + 1;
            $_SESSION['login_tentativas'] = $tentativas;
            $erro = "E-mail ou senha incorretos.";
        }
    }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Login | Ouvidoria SaaS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; display: flex; align-items: center; justify-content: center; height: 100vh; }
        .card-login { width: 100%; max-width: 400px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.08); border-radius: 12px; }
    </style>
</head>
<body>

    <div class="card card-login p-4 bg-white">
        <div class="text-center mb-4">
            <h3 class="fw-bold text-primary">Acesso Restrito</h3>
            <p class="text-muted small">Entre para gerenciar sua ouvidoria.</p>
        </div>

        <?php if($erro): ?>
            <div class="alert alert-danger text-center border-0 bg-danger-subtle text-danger">
                <?php echo $erro; ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label class="form-label fw-bold small text-secondary">E-MAIL</label>
                <input type="email" name="email" class="form-control" placeholder="seu@email.com" required>
            </div>

            <div class="mb-4">
                <label class="form-label fw-bold small text-secondary">SENHA</label>
                <input type="password" name="senha" class="form-control" placeholder="********" required>
            </div>

            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary fw-bold">ENTRAR</button>
            </div>

            <div class="text-center mt-4 border-top pt-3">
                <small class="text-muted">Ainda não tem conta?</small><br>
                <a href="cadastro.php" class="text-decoration-none fw-bold">Criar conta grátis</a>
            </div>
        </form>
    </div>

</body>
</html>