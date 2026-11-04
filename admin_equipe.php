<?php
require 'config.php';   
require 'protecao.php'; 

// --- 0. SEGURANÇA ---
if (!defined('TENANT_ID')) die("Erro Crítico.");
if (!tem_acesso('equipe') && !tem_acesso('master')) die("Acesso Negado.");

$msg = "";
$id_logado = $_SESSION['id'] ?? 0;

// --- 1. BUSCAR DADOS DO TITULAR (DA TABELA TENANTS) ---
// Isso resolve o problema de "não puxar o tenant"
$stmt_owner = $pdo->prepare("SELECT nome, email_contato, plano, data_vencimento FROM tenants WHERE id = ?");
$stmt_owner->execute([TENANT_ID]);
$owner = $stmt_owner->fetch(PDO::FETCH_ASSOC);

// --- 2. LÓGICA DE USUÁRIOS (EQUIPE) ---
$edit_id = ""; $edit_nome = ""; $edit_email = ""; $edit_perms = []; $texto_botao = "Cadastrar Usuário";

// Carregar edição
if (isset($_GET['editar'])) {
    $id_edit = (int)$_GET['editar'];
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$id_edit, TENANT_ID]);
    $user_edit = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user_edit) {
        $edit_id = $user_edit['id'];
        $edit_nome = $user_edit['nome'];
        $edit_email = $user_edit['email'];
        $edit_perms = json_decode($user_edit['permissoes'] ?? '[]', true);
        $texto_botao = "Salvar Alterações";
    }
}

// Salvar/Editar
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'salvar') {
    $nome  = trim($_POST['nome']);
    $email = trim($_POST['email']);
    $senha = $_POST['senha']; 
    $permissoes = isset($_POST['perms']) ? json_encode($_POST['perms']) : json_encode([]);
    $id_editar = $_POST['id_editar'] ?? '';

    // Verifica duplicidade no banco todo
    if ($id_editar) {
        $stmtCheck = $pdo->prepare("SELECT id FROM usuarios WHERE email = ? AND id != ?");
        $stmtCheck->execute([$email, $id_editar]);
    } else {
        $stmtCheck = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
        $stmtCheck->execute([$email]);
    }

    if ($stmtCheck->rowCount() > 0) {
        $msg = "<div class='alert alert-danger'>Erro: E-mail já em uso!</div>";
    } else {
        if (!empty($id_editar)) {
            // Atualizar
            if (!empty($senha)) {
                $hash = password_hash($senha, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE usuarios SET nome=?, email=?, senha=?, permissoes=? WHERE id=? AND tenant_id=?")->execute([$nome, $email, $hash, $permissoes, $id_editar, TENANT_ID]);
            } else {
                $pdo->prepare("UPDATE usuarios SET nome=?, email=?, permissoes=? WHERE id=? AND tenant_id=?")->execute([$nome, $email, $permissoes, $id_editar, TENANT_ID]);
            }
            header("Location: admin_equipe.php?msg=editado"); exit;
        } else {
            // Criar Novo
            if (empty($senha)) {
                $msg = "<div class='alert alert-warning'>Senha obrigatória.</div>";
            } else {
                $hash = password_hash($senha, PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO usuarios (tenant_id, nome, email, senha, nivel, permissoes, ativo) VALUES (?, ?, ?, ?, 'admin', ?, 1)")->execute([TENANT_ID, $nome, $email, $hash, $permissoes]);
                header("Location: admin_equipe.php?msg=sucesso"); exit;
            }
        }
    }
}

// Excluir
if (isset($_GET['deletar'])) {
    $pdo->prepare("DELETE FROM usuarios WHERE id = ? AND tenant_id = ?")->execute([(int)$_GET['deletar'], TENANT_ID]);
    header("Location: admin_equipe.php"); exit;
}

// Listar Equipe
$stmt_lista = $pdo->prepare("SELECT * FROM usuarios WHERE tenant_id = ? ORDER BY nome ASC");
$stmt_lista->execute([TENANT_ID]);

// Feedback URL
if (isset($_GET['msg']) && $_GET['msg'] == 'sucesso') $msg = "<div class='alert alert-success'>Usuário criado!</div>";
if (isset($_GET['msg']) && $_GET['msg'] == 'editado') $msg = "<div class='alert alert-success'>Atualizado!</div>";

// Módulos do Sistema (Atualizado)
$modulos_sistema = [
    'designer'        => '🎨 Designer Express',
    'oficios_ia'      => '🤖 Redator de Ofícios com IA',
    'campanha'        => '📢 Envolvimento',
    'campanha_digital' => '🌐 Campanha Digital',
    'mapa'            => '🗺️ Mapa Geo',
    'agenda_interna'  => '🗓️ Agenda da Equipe',
    'liderancas'      => '🤝 Lideranças',
    'noticias'        => '📰 Site/Notícias',
    'ouvidoria'       => '📣 Ouvidoria',
    'eleitores'       => '👥 CRM Eleitores',
    'atendimentos'    => '🎧 CRM Atendimento', 
    'aniversariantes' => '🎂 Aniversariantes',
    'agenda'          => '📅 Agenda',
    'projetos'        => '⚖️ Projetos Lei',
    'enquetes'        => '📊 Enquetes',
    'landing'         => '🚀 Landing Pages',
    'peticoes'        => '✍️ Abaixo-Assinados', 
    'templates'       => '🎨 Modelos',
    'push'            => '🔔 Notificações Push',
    'transparencia'   => '🧾 Prestação de Contas', // <-- Atualizado aqui (antiga Transparência)
    'qrcode'          => '📱 Placa QR',
    'links'           => '🔗 Encurtador',
    'relatorios'      => '📈 Relatórios',
    'financeiro'      => '💰 Financeiro',
    'cac_politico'    => '🎯 Custo por Voto',    
    'marketing'       => '📧 E-mail Mkt',
    'equipe'          => '🛡️ Equipe',
    'auditoria'       => '👁️ Auditoria',
    'config'          => '⚙️ Configurações',
    'tarefas'         => '✅ Tarefas e Demandas' 
];
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8"><title>Gerenciar Equipe</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-light p-4">

<div class="container bg-white p-4 rounded shadow-sm">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="text-primary fw-bold mb-0"><i class="fas fa-users-cog me-2"></i>Gestão de Acesso</h3>
        <a href="admin_painel.php" class="btn btn-outline-secondary btn-sm">Voltar</a>
    </div>

    <?= $msg ?>

    <div class="card mb-4 border-primary shadow-sm">
        <div class="card-body d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center">
                <div class="bg-primary text-white rounded-circle d-flex justify-content-center align-items-center me-3" style="width: 50px; height: 50px; font-size: 1.2rem;">
                    <i class="fas fa-crown"></i>
                </div>
                <div>
                    <h5 class="mb-0 fw-bold text-dark"><?= htmlspecialchars($owner['nome']) ?></h5>
                    <small class="text-muted"><?= htmlspecialchars($owner['email_contato']) ?></small>
                    <span class="badge bg-success ms-2">TITULAR</span>
                </div>
            </div>
            <div class="text-end d-none d-md-block">
                <span class="d-block small text-muted">Plano Atual</span>
                <span class="fw-bold text-uppercase text-primary"><?= $owner['plano'] ?></span>
            </div>
        </div>
    </div>

    <hr class="my-4">

    <div class="row">
        <div class="col-md-5 mb-4">
            <div class="card bg-light border-0 shadow-sm sticky-top" style="top: 20px;">
                <div class="card-body">
                    <h5 class="card-title fw-bold mb-3 text-secondary">
                        <?= $edit_id ? '<i class="fas fa-edit"></i> Editar Membro' : '<i class="fas fa-user-plus"></i> Novo Membro' ?>
                    </h5>
                    
                    <form method="POST">
                        <input type="hidden" name="acao" value="salvar">
                        <input type="hidden" name="id_editar" value="<?= $edit_id ?>">
                        
                        <div class="mb-2">
                            <label class="small fw-bold">Nome</label>
                            <input type="text" name="nome" class="form-control" value="<?= $edit_nome ?>" required>
                        </div>
                        <div class="mb-2">
                            <label class="small fw-bold">E-mail</label>
                            <input type="email" name="email" class="form-control" value="<?= $edit_email ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="small fw-bold">Senha</label>
                            <input type="password" name="senha" class="form-control" placeholder="******">
                        </div>

                        <hr>
                        <h6 class="fw-bold mb-2 small text-muted">Permissões</h6>
                        <div class="row g-2 px-1" style="max-height: 200px; overflow-y: auto;">
                            <?php foreach($modulos_sistema as $chave => $rotulo): ?>
                                <div class="col-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="perms[]" value="<?= $chave ?>" id="p_<?= $chave ?>" <?= in_array($chave, $edit_perms) ? 'checked' : '' ?>>
                                        <label class="form-check-label small" for="p_<?= $chave ?>"><?= $rotulo ?></label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <button type="submit" class="btn <?= $edit_id ? 'btn-success' : 'btn-dark' ?> w-100 fw-bold mt-3">
                            <?= $texto_botao ?>
                        </button>
                        <?php if($edit_id): ?>
                            <a href="admin_equipe.php" class="btn btn-link btn-sm w-100 mt-1 text-decoration-none text-muted">Cancelar</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-7">
            <h5 class="fw-bold mb-3 text-secondary">Membros da Equipe</h5>
            <div class="table-responsive">
                <table class="table table-hover align-middle border">
                    <thead class="table-light"><tr><th>Usuário</th><th>Acessos</th><th class="text-end">Ações</th></tr></thead>
                    <tbody>
                        <?php if($stmt_lista->rowCount() == 0): ?>
                            <tr><td colspan="3" class="text-center py-4 text-muted">Nenhum membro cadastrado na equipe.</td></tr>
                        <?php endif; ?>

                        <?php while ($user = $stmt_lista->fetch(PDO::FETCH_ASSOC)): 
                            $perms = json_decode($user['permissoes'] ?? '[]', true);
                        ?>
                        <tr class="<?= ($edit_id == $user['id']) ? 'table-warning' : '' ?>">
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="bg-secondary text-white rounded-circle d-flex justify-content-center align-items-center me-2" style="width: 35px; height: 35px; font-weight: bold; font-size: 0.8rem;">
                                        <?= strtoupper(substr($user['nome'], 0, 1)) ?>
                                    </div>
                                    <div style="line-height: 1.1;">
                                        <strong class="text-dark d-block"><?= $user['nome'] ?></strong>
                                        <small class="text-muted"><?= $user['email'] ?></small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php 
                                $qtd = count($perms);
                                if($qtd > 3) echo "<span class='badge bg-info'>$qtd módulos</span>";
                                elseif($qtd == 0) echo "<span class='badge bg-light text-dark border'>Sem acesso</span>";
                                else {
                                    foreach($perms as $p) echo "<span class='badge bg-light text-dark border me-1'>" . ($modulos_sistema[$p] ?? $p) . "</span> ";
                                }
                                ?>
                            </td>
                            <td class="text-end">
                                <a href="?editar=<?= $user['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i></a>
                                <a href="?deletar=<?= $user['id'] ?>" class="btn btn-sm btn-outline-danger ms-1" onclick="return confirm('Excluir membro da equipe?');"><i class="fas fa-trash-alt"></i></a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</body>
</html>