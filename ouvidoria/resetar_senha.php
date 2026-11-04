<?php
require 'security.php';
start_secure_session();
require 'conexao.php';

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$erro = '';
$sucesso = '';

if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    $erro = 'Link de recuperação inválido.';
}

$stmt = null;
$user = null;
if (!$erro) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_expires_at >= NOW() LIMIT 1");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    if (!$user) $erro = 'Este link expirou ou já foi utilizado.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$erro) {
    verify_csrf();
    $senha = $_POST['senha'] ?? '';
    $confirmacao = $_POST['confirmar_senha'] ?? '';
    if (strlen($senha) < 8) {
        $erro = 'A nova senha precisa ter pelo menos 8 caracteres.';
    } elseif ($senha !== $confirmacao) {
        $erro = 'As senhas não conferem.';
    } else {
        $hash = password_hash($senha, PASSWORD_DEFAULT);
        $upd = $pdo->prepare("UPDATE users SET senha_hash = ?, reset_token = NULL, reset_expires_at = NULL WHERE id = ?");
        $upd->execute([$hash, $user['id']]);
        $sucesso = 'Senha atualizada com sucesso. Você já pode entrar no sistema.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Redefinir senha</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center min-vh-100">
<div class="card border-0 shadow p-4" style="max-width:430px;width:100%;border-radius:20px">
<h3 class="fw-bold mb-2">Nova senha</h3>
<p class="text-muted">Crie uma nova senha para acessar sua conta.</p>
<?php if ($erro): ?><div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alert alert-success"><?= htmlspecialchars($sucesso) ?></div><a class="btn btn-primary w-100" href="login.php">Ir para o login</a><?php elseif (!$erro): ?>
<form method="post">
<?= csrf_field() ?>
<input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
<div class="mb-3"><label class="form-label">Nova senha</label><input class="form-control" type="password" name="senha" minlength="8" required></div>
<div class="mb-3"><label class="form-label">Confirmar senha</label><input class="form-control" type="password" name="confirmar_senha" minlength="8" required></div>
<button class="btn btn-primary w-100 fw-bold">Salvar nova senha</button>
</form>
<?php else: ?><a class="btn btn-outline-primary w-100" href="esqueci_senha.php">Solicitar novo link</a><?php endif; ?>
</div>
</body>
</html>
