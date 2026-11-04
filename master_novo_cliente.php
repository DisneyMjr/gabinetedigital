<?php
session_start();
require 'config.php';

// --- 1. SEGURANÇA ---
if (!isset($_SESSION['permissoes']) || !in_array('master', $_SESSION['permissoes'])) {
    header("Location: login.php"); exit;
}

$msg = "";
// Pega o domínio para exibir no formulário
$dominio_atual = $_SERVER['HTTP_HOST']; 

// --- 2. BUSCAR PLANOS DO BANCO ---
// Ajustado para sua tabela: usa 'ativo' e ordena por 'ordem'
try {
    $stmtPlanos = $pdo->query("SELECT * FROM planos WHERE ativo = 1 ORDER BY ordem ASC");
    $lista_planos = $stmtPlanos->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $lista_planos = [];
}

// --- 3. SALVAR NOVO CLIENTE (CORRIGIDO) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $pdo->beginTransaction(); 

        $nome = trim($_POST['nome']);
        $email = trim($_POST['email']);
        $senha = trim($_POST['senha']);
        $plano_id = $_POST['plano_id'];
        
        // Limpa o slug
        $slug = strtolower(trim($_POST['slug']));
        $slug = preg_replace('/[^a-z0-9-]/', '', $slug);

        // Validações
        $stmtCheck = $pdo->prepare("SELECT id FROM tenants WHERE slug = ?");
        $stmtCheck->execute([$slug]);
        if ($stmtCheck->fetch()) {
            throw new Exception("Este subdomínio (slug) já está em uso.");
        }

        $stmtEmail = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
        $stmtEmail->execute([$email]);
        if ($stmtEmail->fetch()) {
            throw new Exception("Este e-mail já está cadastrado.");
        }

        // Busca Plano
        $stmtP = $pdo->prepare("SELECT nome, slug, dias_validade FROM planos WHERE id = ?");
        $stmtP->execute([$plano_id]);
        $plano_dados = $stmtP->fetch(PDO::FETCH_ASSOC);

        if (!$plano_dados) throw new Exception("Plano não encontrado.");

        $dias = (int)$plano_dados['dias_validade'];
        $plano_slug = $plano_dados['slug'];
        $data_vencimento = date('Y-m-d', strtotime("+$dias days"));

        // A. CRIA O TENANT
        // Ajuste: Inserimos '1' na coluna 'ativo' (que vi na sua imagem) e 'ativo' na coluna 'status'
        $sqlTenant = "INSERT INTO tenants (nome, slug, plano, ativo, status, data_vencimento, criado_em) VALUES (?, ?, ?, 1, 'ativo', ?, NOW())";
        $stmt = $pdo->prepare($sqlTenant);
        $stmt->execute([$nome, $slug, $plano_slug, $data_vencimento]);
        $tenant_id = $pdo->lastInsertId();

        // B. CRIA CONFIGURAÇÃO
        // Verifica se a tabela configuracoes existe e cria o registro
        $sqlConfig = "INSERT INTO configuracoes (tenant_id, nome_vereador, mapa_modo) VALUES (?, ?, 'estado')";
        $pdo->prepare($sqlConfig)->execute([$tenant_id, $nome]);

        // C. CRIA USUÁRIO ADMIN
        $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
        // Agora que criamos a coluna no Passo 1, isso vai funcionar
        $sqlUser = "INSERT INTO usuarios (tenant_id, nome, email, senha, nivel, status) VALUES (?, ?, ?, ?, 'admin', 'ativo')";
        $pdo->prepare($sqlUser)->execute([$tenant_id, $nome, $email, $senha_hash]);

        $pdo->commit(); 
        $msg = "<div class='alert alert-success'>
                    <i class='fas fa-check-circle'></i> Cliente <b>$nome</b> criado com sucesso!<br>
                    <small>Acesse: $slug.$dominio_atual</small>
                </div>";

    } catch (Exception $e) {
        $pdo->rollBack();
        $msg = "<div class='alert alert-danger'>❌ Erro: " . $e->getMessage() . "</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Novo Cliente SaaS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-light d-flex align-items-center justify-content-center vh-100">

    <div class="card shadow p-4" style="width: 500px;">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold text-primary m-0"><i class="fas fa-rocket"></i> Novo Gabinete</h4>
            <a href="master_painel.php" class="btn btn-sm btn-outline-secondary">Voltar</a>
        </div>
        
        <?= $msg ?>
        
        <form method="POST">
            <h6 class="text-muted border-bottom pb-2">Contrato</h6>
            
            <div class="mb-3">
                <label class="fw-bold small">Nome do Cliente</label>
                <input type="text" name="nome" class="form-control" placeholder="Ex: Vereador João" required>
            </div>

            <div class="mb-3">
                <label class="fw-bold small">Plano</label>
                <select name="plano_id" class="form-select" required>
                    <option value="">Selecione...</option>
                    <?php foreach($lista_planos as $p): ?>
                        <option value="<?= $p['id'] ?>">
                            <?= htmlspecialchars($p['nome']) ?> - R$ <?= number_format($p['preco'], 2, ',', '.') ?> (<?= $p['dias_validade'] ?> dias)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="fw-bold small">Subdomínio (Slug)</label>
                <div class="input-group">
                    <input type="text" name="slug" class="form-control" placeholder="joao" required pattern="[a-z0-9-]+" title="Sem espaços ou acentos">
                    <span class="input-group-text bg-white text-muted small">.<?= $dominio_atual ?></span>
                </div>
            </div>

            <h6 class="text-muted border-bottom pb-2 mt-4">Acesso Admin</h6>
            <div class="mb-3">
                <label class="fw-bold small">E-mail</label>
                <input type="email" name="email" class="form-control" placeholder="admin@cliente.com" required>
            </div>
            <div class="mb-3">
                <label class="fw-bold small">Senha Provisória</label>
                <input type="text" name="senha" class="form-control" value="123456" required>
            </div>

            <div class="d-grid mt-4">
                <button type="submit" class="btn btn-primary fw-bold py-2">CRIAR GABINETE</button>
            </div>
        </form>
    </div>

</body>
</html>