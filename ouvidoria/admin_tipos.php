<?php
session_start();
require 'conexao.php';
require 'verificar_assinatura.php';

$tenant_id = $_SESSION['tenant_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['novo_tipo'])) {
    $nome = trim($_POST['nome']);
    $stmt = $pdo->prepare("INSERT INTO manifestation_types (tenant_id, nome) VALUES (?, ?)");
    $stmt->execute([$tenant_id, $nome]);
    header("Location: admin_tipos.php");
    exit;
}

if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM manifestation_types WHERE id = ? AND tenant_id = ?");
    $stmt->execute([(int)$_GET['delete'], $tenant_id]);
    header("Location: admin_tipos.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM manifestation_types WHERE tenant_id = ? ORDER BY nome ASC");
$stmt->execute([$tenant_id]);
$tipos = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <title>Tipos de Manifestação | Ouvvi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body class="bg-light p-5">
    <div class="container" style="max-width: 600px;">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold"><i class="bi bi-tags"></i> Tipos de Manifestação</h4>
            <a href="admin.php" class="btn btn-outline-secondary btn-sm">Voltar</a>
        </div>
        
        <div class="card p-4 mb-4 shadow-sm border-0 rounded-4">
            <form method="POST" class="d-flex gap-2">
                <input type="hidden" name="novo_tipo" value="1">
                <input type="text" name="nome" class="form-control" placeholder="Ex: Denúncia Anônima" required>
                <button type="submit" class="btn btn-primary fw-bold">Adicionar</button>
            </form>
        </div>

        <div class="card p-4 shadow-sm border-0 rounded-4">
            <table class="table table-hover mb-0">
                <tbody>
                    <?php foreach($tipos as $t): ?>
                    <tr>
                        <td class="fw-bold align-middle"><?= htmlspecialchars($t['nome']) ?></td>
                        <td class="text-end"><a href="admin_tipos.php?delete=<?= $t['id'] ?>" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></a></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($tipos)) echo "<tr><td class='text-muted'>Nenhum tipo cadastrado.</td></tr>"; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
