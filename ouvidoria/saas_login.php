<?php
// saas_login.php
session_start();
require 'conexao.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'];
    $senha = $_POST['senha'];

    $stmt = $pdo->prepare("SELECT * FROM super_admins WHERE email = ?");
    $stmt->execute([$email]);
    $admin = $stmt->fetch();

    if ($admin && password_verify($senha, $admin['senha_hash'])) {
        $_SESSION['saas_admin_id'] = $admin['id'];
        header("Location: painel.php");
        exit;
    } else {
        $erro = "Acesso negado.";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Login Master | SaaS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #1a1a2e; color: white; display: flex; align-items: center; justify-content: center; height: 100vh; }
        .card { background: #16213e; border: 1px solid #0f3460; }
    </style>
</head>
<body>
    <div class="card p-5 shadow-lg" style="width: 400px;">
        <h3 class="text-center mb-4">🔐 Área Restrita</h3>
        <?php if(isset($erro)) echo "<div class='alert alert-danger'>$erro</div>"; ?>
        <form method="POST">
            <div class="mb-3">
                <label>E-mail Master</label>
                <input type="email" name="email" class="form-control" required>
            </div>
            <div class="mb-4">
                <label>Senha</label>
                <input type="password" name="senha" class="form-control" required>
            </div>
            <button class="btn btn-primary w-100">Entrar no Painel</button>
        </form>
    </div>
</body>
</html>