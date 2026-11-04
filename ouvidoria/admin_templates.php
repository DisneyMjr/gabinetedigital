<?php
// admin_templates.php - GESTÃO DE MENSAGENS E PARECERES PADRÃO
session_start();
require 'conexao.php';
require 'verificar_assinatura.php';

$tenant_id = $_SESSION['tenant_id'];
$user_nome = $_SESSION['user_nome'];
$mensagem_status = '';

// Verificar se o filtro é para Parecer ou Mensagem
$tipo_atual = (isset($_GET['tipo']) && $_GET['tipo'] == 'parecer') ? 'parecer' : 'mensagem';
$titulo_pagina = ($tipo_atual == 'parecer') ? 'Pareceres Padrão' : 'Mensagens Padrão';

// --- 1. SALVAR NOVO TEMPLATE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_template'])) {
    $titulo = trim($_POST['titulo']);
    $conteudo = trim($_POST['conteudo']);
    $tipo = $_POST['tipo'];

    $sql = "INSERT INTO templates (tenant_id, titulo, conteudo, tipo) VALUES (?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    if ($stmt->execute([$tenant_id, $titulo, $conteudo, $tipo])) {
        $mensagem_status = "<div class='alert alert-success fw-bold'><i class='bi bi-check-circle'></i> Modelo salvo com sucesso!</div>";
    }
}

// --- 2. ELIMINAR TEMPLATE ---
if (isset($_GET['delete'])) {
    $id_del = (int)$_GET['delete'];
    $stmtDel = $pdo->prepare("DELETE FROM templates WHERE id = ? AND tenant_id = ?");
    $stmtDel->execute([$id_del, $tenant_id]);
    header("Location: admin_templates.php?tipo=$tipo_atual");
    exit;
}

// --- BUSCAR TEMPLATES ---
$stmt = $pdo->prepare("SELECT * FROM templates WHERE tenant_id = ? AND tipo = ? ORDER BY titulo ASC");
$stmt->execute([$tenant_id, $tipo_atual]);
$templates = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title><?php echo $titulo_pagina; ?> | Ouvvi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', sans-serif; }
        .wrapper { display: flex; min-height: 100vh; }
        #sidebar { min-width: 250px; max-width: 250px; background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%); color: #fff; }
        #sidebar ul li a { padding: 12px 20px; display: block; color: rgba(255,255,255,0.8); text-decoration: none; }
        #sidebar ul li a.active { color: #fff; background: rgba(255,255,255,0.05); border-left: 4px solid #0d6efd; }
        #content { width: 100%; }
        .top-navbar { background: #fff; padding: 15px 30px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-custom { border: none; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
    </style>
</head>
<body>
    <div class="wrapper">
        <nav id="sidebar">
            <div class="p-4"><h4 class="fw-bold"><i class="bi bi-shield-check text-primary"></i> Ouvvi</h4></div>
            <ul class="list-unstyled">
                <li><a href="admin.php"><i class="bi bi-arrow-left"></i> Voltar ao Painel</a></li>
                <li class="mt-4 px-4 small text-uppercase text-muted">Padronização</li>
                <li><a href="admin_templates.php?tipo=mensagem" <?php if($tipo_atual == 'mensagem') echo 'class="active"'; ?>><i class="bi bi-chat-left-quote"></i> Mensagens Padrão</a></li>
                <li><a href="admin_templates.php?tipo=parecer" <?php if($tipo_atual == 'parecer') echo 'class="active"'; ?>><i class="bi bi-journal-check"></i> Pareceres Padrão</a></li>
            </ul>
        </nav>

        <div id="content">
            <div class="top-navbar d-flex justify-content-between align-items-center">
                <h5 class="m-0 fw-bold"><?php echo $titulo_pagina; ?></h5>
                <span class="small text-muted"><?php echo htmlspecialchars($user_nome); ?></span>
            </div>

            <div class="container-fluid p-4">
                <div class="row">
                    <div class="col-md-5">
                        <div class="card card-custom p-4 mb-4">
                            <h6 class="fw-bold mb-3">Criar Novo Modelo</h6>
                            <?php echo $mensagem_status; ?>
                            <form method="POST">
                                <input type="hidden" name="save_template" value="1">
                                <input type="hidden" name="tipo" value="<?php echo $tipo_atual; ?>">
                                
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">Título do Modelo (ex: Agradecimento)</label>
                                    <input type="text" name="titulo" class="form-control" placeholder="Para identificação interna" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">Texto do Modelo</label>
                                    <textarea name="conteudo" class="form-control" rows="8" placeholder="Olá, recebemos sua manifestação..." required></textarea>
                                    <div class="form-text mt-2 small">Dica: Use um texto genérico que possa ser adaptado depois.</div>
                                </div>
                                <button type="submit" class="btn btn-primary w-100 fw-bold">Salvar Modelo</button>
                            </form>
                        </div>
                    </div>

                    <div class="col-md-7">
                        <div class="card card-custom p-4">
                            <h6 class="fw-bold mb-3">Modelos Cadastrados</h6>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Título</th>
                                            <th>Conteúdo (Prévia)</th>
                                            <th>Ação</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(count($templates) == 0): ?>
                                            <tr><td colspan="3" class="text-center text-muted">Nenhum modelo cadastrado.</td></tr>
                                        <?php endif; ?>
                                        <?php foreach($templates as $t): ?>
                                        <tr>
                                            <td class="fw-bold"><?php echo htmlspecialchars($t['titulo']); ?></td>
                                            <td class="small text-muted"><?php echo mb_strimwidth(htmlspecialchars($t['conteudo']), 0, 50, "..."); ?></td>
                                            <td>
                                                <a href="admin_templates.php?tipo=<?php echo $tipo_atual; ?>&delete=<?php echo $t['id']; ?>" class="text-danger" onclick="return confirm('Excluir este modelo?')"><i class="bi bi-trash"></i></a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>