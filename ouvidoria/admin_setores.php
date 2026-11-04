<?php
// admin_setores.php
session_start();
require 'conexao.php';
require 'verificar_assinatura.php'; 

$tenant_id = $_SESSION['tenant_id'];

// ADICIONAR SETOR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['novo_setor'])) {
    $nome = trim($_POST['novo_setor']);
    if (!empty($nome)) {
        $stmt = $pdo->prepare("INSERT INTO sectors (tenant_id, nome) VALUES (?, ?)");
        $stmt->execute([$tenant_id, $nome]);
    }
    header("Location: admin_setores.php");
    exit;
}

// EXCLUIR SETOR
if (isset($_GET['excluir'])) {
    $id = (int)$_GET['excluir'];
    $stmt = $pdo->prepare("DELETE FROM sectors WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$id, $tenant_id]);
    header("Location: admin_setores.php");
    exit;
}

// LISTAR SETORES
$stmt = $pdo->prepare("SELECT * FROM sectors WHERE tenant_id = ?");
$stmt->execute([$tenant_id]);
$setores = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Gerenciar Setores</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body class="bg-light">
    <div class="container mt-5" style="max-width: 600px;">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4>🏢 Setores da Empresa</h4>
            <a href="admin.php" class="btn btn-outline-secondary btn-sm">Voltar ao Admin</a>
        </div>

        <div class="card p-4 shadow-sm mb-4">
            <form method="POST" class="d-flex gap-2">
                <input type="text" name="novo_setor" class="form-control" placeholder="Ex: Financeiro, RH, Operacional..." required>
                <button class="btn btn-primary">Adicionar</button>
            </form>
        </div>

        <ul class="list-group shadow-sm">
            <?php foreach($setores as $s): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <?php echo htmlspecialchars($s['nome']); ?>
                    <a href="?excluir=<?php echo $s['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Tem certeza?')"><i class="bi bi-trash"></i></a>
                </li>
            <?php endforeach; ?>
            <?php if(empty($setores)): ?>
                <li class="list-group-item text-center text-muted">Nenhum setor cadastrado ainda.</li>
            <?php endif; ?>
        </ul>
    </div>
</body>
</html>