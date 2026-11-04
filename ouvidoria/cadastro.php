<?php
// cadastro.php - CORRIGIDO (SEM COLUNA PLANO)
require 'security.php';
start_secure_session();
require 'conexao.php';

// Função para gerar Slug amigável
function gerarSlug($string) {
    $string = strtolower(trim($string));
    $string = preg_replace('/[^a-z0-9-]/', '-', $string);
    $string = preg_replace('/-+/', '-', $string);
    return trim($string, '-');
}

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome_empresa = trim($_POST['empresa'] ?? '');
    $nome_usuario = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';

    if (empty($nome_empresa) || empty($nome_usuario) || empty($email) || empty($senha)) {
        $erro = "Preencha todos os campos.";
    } else {
        try {
            $pdo->beginTransaction();

            // 1. GERAR SLUG ÚNICO
            $baseSlug = gerarSlug($nome_empresa);
            $randomCode = substr(md5(uniqid()), 0, 4); 
            $slugFinal = $baseSlug . '-' . $randomCode;

            // 2. CRIA A EMPRESA (SEM A COLUNA PLANO)
            // Define Ativo = 1 e Validade = Hoje + 3 Dias
            $sqlTenant = "INSERT INTO tenants (nome_empresa, slug, ativo, data_expiracao) 
                          VALUES (?, ?, 1, DATE_ADD(NOW(), INTERVAL 3 DAY))";
            $stmt = $pdo->prepare($sqlTenant);
            $stmt->execute([$nome_empresa, $slugFinal]);
            
            $tenant_id = $pdo->lastInsertId(); 

            // 3. CRIA O USUÁRIO ADMIN
            $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
            $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
            $campos = ['tenant_id','nome','email','senha_hash'];
            $valores = [$tenant_id,$nome_usuario,$email,$senha_hash];
            $marcas = ['?','?','?','?'];
            if (in_array('perfil', $cols, true)) { $campos[]='perfil'; $valores[]='admin'; $marcas[]='?'; }
            if (in_array('nivel_acesso', $cols, true)) { $campos[]='nivel_acesso'; $valores[]='admin'; $marcas[]='?'; }
            if (in_array('setor_id', $cols, true)) { $campos[]='setor_id'; $valores[]=null; $marcas[]='?'; }
            $sqlUser = 'INSERT INTO users (' . implode(',', $campos) . ') VALUES (' . implode(',', $marcas) . ')';
            $stmtUser = $pdo->prepare($sqlUser);
            $stmtUser->execute($valores);
            $novo_user_id = (int)$pdo->lastInsertId();

            $pdo->commit();

            // 4. LOGIN AUTOMÁTICO
            session_regenerate_id(true);
            $_SESSION['user_id'] = $novo_user_id;
            $_SESSION['tenant_id'] = $tenant_id;
            $_SESSION['user_nome'] = $nome_usuario;
            $_SESSION['user_perfil'] = 'admin';
            $_SESSION['nivel_acesso'] = 'admin';
            $_SESSION['setor_id'] = null;

            // Redireciona para o Painel
            header("Location: admin.php");
            exit;

        } catch (PDOException $e) {
            $pdo->rollBack();
            if ($e->getCode() == '23000') {
                $erro = "E-mail ou Empresa já cadastrados.";
            } else {
                $erro = "Erro no sistema: " . $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Teste Grátis | Ouvidoria SaaS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; display: flex; align-items: center; justify-content: center; height: 100vh; }
        .card-cadastro { width: 100%; max-width: 450px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.08); border-radius: 12px; }
    </style>
</head>
<body>

    <div class="card card-cadastro p-4 bg-white">
        <div class="text-center mb-4">
            <h3 class="fw-bold text-primary">🚀 Comece Agora</h3>
            <p class="text-muted small">Teste todo o sistema por 3 dias grátis.<br>Sem compromisso.</p>
        </div>

        <?php if($erro): ?>
            <div class="alert alert-danger text-center border-0 bg-danger-subtle text-danger">
                <?php echo $erro; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="mb-3">
                <label class="form-label fw-bold small text-secondary">NOME DA EMPRESA</label>
                <input type="text" name="empresa" class="form-control bg-light" placeholder="Ex: Minha Loja Ltda" required>
            </div>

            <hr class="my-4 border-light">

            <div class="mb-3">
                <label class="form-label fw-bold small text-secondary">SEU NOME</label>
                <input type="text" name="nome" class="form-control" placeholder="Ex: Maria Souza" required>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold small text-secondary">E-MAIL DE LOGIN</label>
                <input type="email" name="email" class="form-control" placeholder="seu@email.com" required>
            </div>

            <div class="mb-4">
                <label class="form-label fw-bold small text-secondary">SENHA</label>
                <input type="password" name="senha" class="form-control" placeholder="Crie uma senha segura" required>
            </div>

            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary btn-lg fw-bold">
                    CRIAR CONTA GRÁTIS
                </button>
            </div>

            <div class="text-center mt-3">
                <small class="text-muted">Já tem uma conta? <a href="login.php" class="text-decoration-none fw-bold">Entrar</a></small>
            </div>
        </form>
    </div>

</body>
</html>