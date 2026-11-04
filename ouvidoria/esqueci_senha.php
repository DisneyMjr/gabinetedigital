<?php
require 'security.php';
start_secure_session();
require 'conexao.php';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim($_POST['email']);
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        $token = bin2hex(random_bytes(32));
        $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires_at = DATE_ADD(NOW(), INTERVAL 60 MINUTE) WHERE id = ?")->execute([$token, $user['id']]);
        
        $protocolo = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
        $link = "{$protocolo}://{$_SERVER['HTTP_HOST']}" . dirname($_SERVER['PHP_SELF']) . "/resetar_senha.php?token={$token}";
        
        $assunto = "Recuperacao de Senha - Ouvvi";
        $html = "<p>Você solicitou a recuperação de senha.</p><p>Clique no link para criar uma nova: <a href='{$link}'>Redefinir Senha</a></p>";
        $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: no-reply@{$_SERVER['HTTP_HOST']}\r\n";
        
        @mail($email, $assunto, $html, $headers);
    }
    // Sempre dizemos que enviamos para evitar que descubram quais e-mails existem no sistema (Segurança)
    $msg = "<div class='alert alert-success fw-bold text-center'>Se o e-mail estiver cadastrado, você receberá um link de recuperação em instantes.</div>";
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <title>Recuperar Senha | Ouvvi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center" style="height: 100vh;">
    <div class="card p-5 border-0 shadow-lg" style="width: 100%; max-width: 400px; border-radius: 20px;">
        <h4 class="fw-bold text-primary mb-3 text-center">Recuperar Senha</h4>
        <?= $msg ?>
        <form method="POST">
            <?= csrf_field() ?>
            <div class="mb-4">
                <label class="text-muted small fw-bold">E-mail cadastrado</label>
                <input type="email" name="email" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary w-100 fw-bold rounded-pill">Enviar Link de Recuperação</button>
        </form>
        <div class="text-center mt-3"><a href="login.php" class="text-decoration-none small text-muted">Voltar ao Login</a></div>
    </div>
</body>
</html>
