<?php
require 'config.php';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Pagamento Confirmado!</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f4f7f6; display: flex; align-items: center; justify-content: center; height: 100vh; }
        .success-card { max-width: 500px; text-align: center; background: white; padding: 40px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
    <div class="success-card">
        <div class="text-success mb-4"><i class="fas fa-check-circle fa-5x"></i></div>
        <h2 class="fw-bold">Pagamento Recebido!</h2>
        <p class="text-muted">Obrigado por assinar o <b>Gabinete Digital</b>. Sua conta já foi liberada e você já pode acessar seu painel.</p>
        <a href="admin_login.php" class="btn btn-primary btn-lg rounded-pill px-5 fw-bold mt-3">ENTRAR NO SISTEMA</a>
    </div>
</body>
</html>