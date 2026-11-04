<?php
require 'conexao.php';

// Lógica de Cadastro
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = $_POST['nome'];
    $plano = $_POST['plano'];
    
    $sql = "INSERT INTO tenants (nome_empresa, plano) VALUES (?, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$nome, $plano]);
    $sucesso = true;
}

// Listar Empresas Existentes
$empresas = $pdo->query("SELECT * FROM tenants ORDER BY id DESC")->fetchAll();
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Super Admin | Ouvidoria SaaS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light p-5">
    <div class="container">
        <h1>Painel do Dono do SaaS</h1>
        <p>Cadastre aqui as empresas que contrataram seu sistema.</p>
        
        <div class="card p-4 mb-5">
            <h4>Nova Empresa Cliente</h4>
            <form method="POST">
                <div class="row">
                    <div class="col-md-6">
                        <input type="text" name="nome" class="form-control" placeholder="Nome da Empresa (ex: Padaria Tech)" required>
                    </div>
                    <div class="col-md-4">
                        <select name="plano" class="form-select">
                            <option value="free">Teste Grátis</option>
                            <option value="pro">Plano Pro</option>
                            <option value="enterprise">Enterprise</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-success w-100">Cadastrar</button>
                    </div>
                </div>
            </form>
        </div>

        <h4>Empresas Ativas</h4>
        <table class="table table-white table-bordered">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Empresa</th>
                    <th>Plano</th>
                    <th>Links do Sistema (Entregáveis)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($empresas as $emp): ?>
                <tr>
                    <td><?php echo $emp['id']; ?></td>
                    <td><?php echo $emp['nome_empresa']; ?></td>
                    <td><span class="badge bg-secondary"><?php echo $emp['plano']; ?></span></td>
                    <td>
                        <small class="d-block">Link para Colaboradores:</small>
                        <a href="index.php?id=<?php echo $emp['id']; ?>" target="_blank">
                            localhost:8000/index.php?id=<?php echo $emp['id']; ?>
                        </a>
                        
                        <small class="d-block mt-2">Link para o RH (Admin):</small>
                        <a href="admin.php?id=<?php echo $emp['id']; ?>" target="_blank" class="text-danger">
                             localhost:8000/admin.php?id=<?php echo $emp['id']; ?>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>