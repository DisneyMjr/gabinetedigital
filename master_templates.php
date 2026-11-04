<?php
// ==========================================================
// MASTER TEMPLATES - GERENCIADOR DE MODELOS DE SITE
// ==========================================================
ini_set('display_errors', 1);
error_reporting(E_ALL);
require 'config.php'; 

// --- VERIFICAÇÃO DE SEGURANÇA (Se necessário) ---
// if (!isset($_SESSION['permissoes']) || !in_array('master', $_SESSION['permissoes'])) { die('Acesso negado'); }

$mensagem = "";
$dados_editar = [];
$aba_ativa = 'lista'; 

// --- 1. AÇÕES DE BANCO DE DADOS ---

// EXCLUIR
if (isset($_GET['excluir'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM master_templates WHERE id = ?");
        $stmt->execute([$_GET['excluir']]);
        header("Location: master_templates.php?sucesso=excluido");
        exit;
    } catch (Exception $e) {
        $mensagem = "<div class='alert alert-danger'>Erro ao excluir: " . $e->getMessage() . "</div>";
    }
}

// CARREGAR DADOS PARA EDIÇÃO
if (isset($_GET['editar'])) {
    $stmt = $pdo->prepare("SELECT * FROM master_templates WHERE id = ?");
    $stmt->execute([$_GET['editar']]);
    $dados_editar = $stmt->fetch(PDO::FETCH_ASSOC);
    $aba_ativa = 'form'; 
}

// SALVAR (Novo ou Edição)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['salvar'])) {
    try {
        $id = $_POST['id'] ?? '';
        $nome = $_POST['nome'];
        $categoria = $_POST['categoria'];
        $preview = $_POST['preview']; // Mantive o campo caso queira usar imagem no futuro
        $html = $_POST['html'];

        if (!empty($id)) {
            // UPDATE
            $stmt = $pdo->prepare("UPDATE master_templates SET nome=?, categoria=?, preview_url=?, html_bruto=? WHERE id=?");
            $stmt->execute([$nome, $categoria, $preview, $html, $id]);
            header("Location: master_templates.php?sucesso=editado");
        } else {
            // INSERT
            $stmt = $pdo->prepare("INSERT INTO master_templates (nome, categoria, preview_url, html_bruto) VALUES (?, ?, ?, ?)");
            $stmt->execute([$nome, $categoria, $preview, $html]);
            header("Location: master_templates.php?sucesso=salvo");
        }
        exit;
    } catch (Exception $e) {
        $mensagem = "<div class='alert alert-danger'>Erro ao salvar: " . $e->getMessage() . "</div>";
    }
}

// FEEDBACK VISUAL
if (isset($_GET['sucesso'])) {
    if ($_GET['sucesso'] == 'salvo') $mensagem = "<div class='alert alert-success'>✅ Template cadastrado com sucesso!</div>";
    if ($_GET['sucesso'] == 'editado') $mensagem = "<div class='alert alert-primary'>✏️ Template atualizado com sucesso!</div>";
    if ($_GET['sucesso'] == 'excluido') $mensagem = "<div class='alert alert-secondary'>🗑️ Template removido.</div>";
}

// --- 2. BUSCAR LISTA ---
$lista = $pdo->query("SELECT * FROM master_templates ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Tags disponíveis para substituição
$tags = [
    '{{NOME_VEREADOR}}' => 'Nome do Político',
    '{{SLUG}}' => 'Slug do Cliente',
    '{{CARGO}}' => 'Cargo (Vereador/Prefeito)',
    '{{WHATSAPP_LINK}}' => 'Link do WhatsApp',
    '{{FOTO_CANDIDATO}}'=> 'Foto Principal',
    '{{COR_PRIMARIA}}' => 'Cor Principal (Hex)',
    '{{LOGO}}' => 'Logo do Site'
];
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Templates</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f0f2f5; font-family: sans-serif; }
        
        /* Estilo do Card */
        .card-tpl { 
            transition: 0.3s; 
            border: none; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.05); 
            overflow: hidden; 
            height: 100%;
        }
        .card-tpl:hover { 
            transform: translateY(-5px); 
            box-shadow: 0 10px 20px rgba(0,0,0,0.1); 
        }
        
        /* Área do Iframe (Prévia) */
        .preview-container {
            position: relative;
            height: 280px; /* Altura fixa da visualização */
            overflow: hidden;
            border-bottom: 1px solid #eee;
            background: #fff;
        }
        
        .preview-iframe {
            width: 100%;
            height: 100%;
            border: none;
            /* Impede interação do mouse (para que o clique vá para o card, não pro site dentro) */
            pointer-events: none; 
            /* Se quiser dar um zoom out para caber mais coisa: */
            /* transform: scale(0.8); transform-origin: 0 0; width: 125%; height: 125%; */
        }

        /* Editor de Código */
        .code-area { 
            font-family: 'Consolas', 'Monaco', monospace; 
            font-size: 12px; 
            background-color: #2d2d2d; 
            color: #ccc; 
        }
        
        .nav-tabs .nav-link.active { border-top: 3px solid #0d6efd; font-weight: bold; }
    </style>
</head>
<body class="p-4">

<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3><i class="fas fa-layer-group text-primary"></i> Gerenciar Templates</h3>
        <a href="master_painel.php" class="btn btn-secondary">Voltar ao Painel</a>
    </div>

    <?= $mensagem ?>

    <ul class="nav nav-tabs mb-4" id="myTab" role="tablist">
        <li class="nav-item">
            <button class="nav-link <?= $aba_ativa == 'lista' ? 'active' : '' ?>" id="home-tab" data-bs-toggle="tab" data-bs-target="#tab-lista" type="button">
                <i class="fas fa-list"></i> Meus Modelos (<?= count($lista) ?>)
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link <?= $aba_ativa == 'form' ? 'active' : '' ?>" id="profile-tab" data-bs-toggle="tab" data-bs-target="#tab-form" type="button">
                <i class="fas fa-plus-circle"></i> <?= !empty($dados_editar) ? 'Editando Modelo' : 'Novo Modelo' ?>
            </button>
        </li>
    </ul>

    <div class="tab-content">
        
        <div class="tab-pane fade <?= $aba_ativa == 'lista' ? 'show active' : '' ?>" id="tab-lista">
            <div class="row g-4">
                <?php foreach($lista as $tpl): 
                    // 1. Prepara o HTML para exibição (Troca tags por dados falsos)
                    $html_preview = $tpl['html_bruto'];
                    $dados_falsos = [
                        '{{NOME_VEREADOR}}' => 'Vereador Modelo',
                        '{{SLUG}}' => '#',
                        '{{CARGO}}' => 'Vereador',
                        '{{COR_PRIMARIA}}' => '#0d6efd',
                        '{{COR_SECUNDARIA}}' => '#ffc107',
                        '{{FOTO_CANDIDATO}}' => 'https://placehold.co/400x400/0d6efd/ffffff?text=FOTO',
                        '{{LOGO}}' => 'https://placehold.co/150x50/333/fff?text=LOGO'
                    ];
                    // Troca as tags pelas infos fakes
                    $html_preview = str_replace(array_keys($dados_falsos), array_values($dados_falsos), $html_preview);
                ?>
                <div class="col-md-4">
                    <div class="card card-tpl">
                        
                        <div class="preview-container">
                            <iframe 
                                class="preview-iframe" 
                                srcdoc="<?= htmlspecialchars($html_preview, ENT_QUOTES) ?>" 
                                loading="lazy"
                                title="Prévia do Template">
                            </iframe>
                        </div>
                        
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h5 class="fw-bold m-0 text-truncate" title="<?= htmlspecialchars($tpl['nome']) ?>"><?= htmlspecialchars($tpl['nome']) ?></h5>
                                <span class="badge bg-secondary"><?= htmlspecialchars($tpl['categoria']) ?></span>
                            </div>
                            
                            <div class="d-grid gap-2 mt-3">
                                <a href="?editar=<?= $tpl['id'] ?>" class="btn btn-primary btn-sm"><i class="fas fa-edit"></i> Editar Código</a>
                                <a href="?excluir=<?= $tpl['id'] ?>" onclick="return confirm('Tem certeza que deseja excluir este modelo?')" class="btn btn-outline-danger btn-sm"><i class="fas fa-trash"></i> Excluir</a>
                            </div>
                        </div>
                        <div class="card-footer bg-light small text-muted d-flex justify-content-between">
                            <span>ID: <?= $tpl['id'] ?></span>
                            <span title="Renderizado dinamicamente"><i class="fas fa-code text-primary"></i> HTML Live</span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php if(empty($lista)): ?>
                    <div class="col-12 text-center py-5">
                        <i class="fas fa-laptop-code fa-3x text-muted mb-3"></i>
                        <h4 class="text-muted">Nenhum template encontrado.</h4>
                        <p>Vá na aba "Novo Modelo" para criar o primeiro design.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="tab-pane fade <?= $aba_ativa == 'form' ? 'show active' : '' ?>" id="tab-form">
            <div class="row">
                <div class="col-lg-8">
                    <div class="card shadow-sm p-4 border-0">
                        <form method="POST">
                            <input type="hidden" name="id" value="<?= $dados_editar['id'] ?? '' ?>">

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="fw-bold">Nome do Modelo</label>
                                    <input type="text" name="nome" class="form-control" placeholder="Ex: Moderno Azul" required value="<?= $dados_editar['nome'] ?? '' ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="fw-bold">Categoria</label>
                                    <select name="categoria" class="form-select">
                                        <?php 
                                            $cat_atual = $dados_editar['categoria'] ?? ''; 
                                            $cats = ['Geral', 'Eleitoral', 'Minimalista', 'Institucional', 'Dark'];
                                            foreach($cats as $c) {
                                                $sel = ($cat_atual == $c) ? 'selected' : '';
                                                echo "<option value='$c' $sel>$c</option>";
                                            }
                                        ?>
                                    </select>
                                </div>
                                
                                <div class="col-12">
                                    <label class="fw-bold text-muted small">URL Imagem Backup (Opcional)</label>
                                    <input type="text" name="preview" class="form-control form-control-sm" placeholder="Caso o iframe falhe..." value="<?= $dados_editar['preview_url'] ?? '' ?>">
                                </div>

                                <div class="col-12">
                                    <label class="fw-bold d-flex justify-content-between">
                                        <span>Código HTML (Completo)</span>
                                        <small class="text-muted">Cole aqui o HTML inteiro do site</small>
                                    </label>
                                    <textarea name="html" class="form-control code-area" rows="20" required placeholder="<html>...</html>"><?= htmlspecialchars($dados_editar['html_bruto'] ?? '') ?></textarea>
                                </div>

                                <div class="col-12 mt-4 d-flex gap-2">
                                    <?php if(!empty($dados_editar)): ?>
                                        <a href="master_templates.php" class="btn btn-secondary px-4">Cancelar</a>
                                    <?php endif; ?>
                                    <button type="submit" name="salvar" class="btn btn-success fw-bold px-5">
                                        <i class="fas fa-save me-2"></i> SALVAR MODELO
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <div class="card bg-dark text-white p-4 border-0">
                        <h6 class="mb-3 fw-bold text-warning"><i class="fas fa-code me-2"></i> Tags Dinâmicas</h6>
                        <p class="small text-white-50">Use estas palavras-chave no seu HTML. O sistema vai substituí-las automaticamente pelos dados do cliente.</p>
                        
                        <ul class="list-group list-group-flush small rounded">
                            <?php foreach($tags as $k => $v): ?>
                                <li class="list-group-item bg-transparent text-white border-secondary d-flex justify-content-between align-items-center px-0">
                                    <code class="text-info fs-6"><?= $k ?></code> 
                                    <span class="text-end text-light"><?= $v ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        
                        <div class="mt-4 p-3 bg-secondary bg-opacity-25 rounded small border border-secondary">
                            <strong>Dica:</strong> Para usar a cor do cliente no CSS (style), faça assim:<br>
                            <code>style="background-color: {{COR_PRIMARIA}};"</code>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>