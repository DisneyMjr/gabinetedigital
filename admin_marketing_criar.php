<?php
require 'config.php';
require 'protecao.php';

if (!tem_acesso('marketing')) { header("Location: admin_painel.php"); exit; }

$id = $_GET['id'] ?? '';
$assunto = "";
// Template base estilo Mailchimp (HTML Profissional)
$corpo = '
<div style="background-color: #f4f4f4; padding: 20px; font-family: sans-serif;">
    <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
        <div style="padding: 20px; text-align: center; background-color: #ffffff;">
            <img src="'.$site_config['logo_site'].'" style="max-height: 50px;">
        </div>
        <div style="padding: 20px;">
            <h2 style="color: #333;">Olá, {{nome}}!</h2>
            <p style="color: #666; font-size: 16px; line-height: 1.6;">
                Escreva sua mensagem principal aqui. Use este espaço para contar as novidades do mandato ou fazer convites.
            </p>
            <div style="text-align: center; margin: 30px 0;">
                <a href="#" style="background-color: #0d6efd; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; font-weight: bold;">CLIQUE AQUI PARA SABER MAIS</a>
            </div>
        </div>
        <div style="padding: 20px; background-color: #f8f9fa; text-align: center; font-size: 12px; color: #999;">
            Você recebeu este e-mail porque faz parte da nossa base de contatos.<br>
            <strong>Gabinete Digital - '.$site_config['nome_vereador'].'</strong>
        </div>
    </div>
</div>';

if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM campanhas WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$id, TENANT_ID]);
    $campanha = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($campanha) {
        $assunto = $campanha['assunto'];
        $corpo = $campanha['corpo'];
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $assunto = $_POST['assunto'];
    $corpo = $_POST['corpo'];

    if ($id) {
        $stmt = $pdo->prepare("UPDATE campanhas SET assunto = ?, corpo = ? WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$assunto, $corpo, $id, TENANT_ID]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO campanhas (tenant_id, assunto, corpo, status, criado_em) VALUES (?, ?, ?, 'rascunho', NOW())");
        $stmt->execute([TENANT_ID, $assunto, $corpo]);
    }
    header("Location: admin_marketing.php"); exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Editor Estilo Mailchimp</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f0f2f5; }
        .editor-container { background: white; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
        .tag-btn { cursor: pointer; transition: 0.2s; border: 1px dashed #dee2e6; background: #fff; padding: 5px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; color: #666; }
        .tag-btn:hover { background: #e7f1ff; color: #0d6efd; border-color: #0d6efd; }
        .note-editor.note-frame { border: 1px solid #eee !important; }
        .note-editable { background: #f4f4f4; } /* Simula fundo do e-mail */
    </style>
</head>
<body>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="editor-container p-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h4 class="fw-bold m-0"><i class="fas fa-paper-plane text-primary me-2"></i> Designer de Campanha</h4>
                    <a href="admin_marketing.php" class="btn btn-light btn-sm">Voltar</a>
                </div>

                <form method="POST">
                    <div class="mb-4">
                        <label class="small fw-bold text-muted text-uppercase mb-2">Assunto Irresistível</label>
                        <input type="text" name="assunto" class="form-control form-control-lg border-0 bg-light" value="<?= htmlspecialchars($assunto) ?>" required placeholder="Ex: {{nome}}, tenho uma novidade para você!">
                    </div>

                    <div class="mb-2 d-flex align-items-center gap-2">
                        <span class="small fw-bold text-muted">INSERIR DADO DO ELEITOR:</span>
                        <span class="tag-btn" onclick="addTag('{{nome}}')"><i class="fas fa-user me-1"></i> Nome</span>
                        <span class="tag-btn" onclick="addTag('{{cidade}}')"><i class="fas fa-map-marker-alt me-1"></i> Cidade</span>
                        <span class="tag-btn" onclick="addTag('{{email}}')"><i class="fas fa-envelope me-1"></i> E-mail</span>
                    </div>

                    <div class="mb-4">
                        <textarea id="summernote" name="corpo"><?= $corpo ?></textarea>
                    </div>

                    <div class="alert alert-light border-0 small text-muted">
                        <i class="fas fa-info-circle me-1"></i> <strong>Dica Mailchimp:</strong> Evite usar imagens muito pesadas e sempre teste o link do seu botão.
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <button type="submit" class="btn btn-primary px-5 fw-bold rounded-pill">SALVAR CAMPANHA</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>
<script>
    $(document).ready(function() {
        $('#summernote').summernote({
            placeholder: 'Desenhe seu e-mail...',
            tabsize: 2,
            height: 500,
            lang: 'pt-BR',
            toolbar: [
                ['style', ['style']],
                ['font', ['bold', 'underline', 'clear', 'fontsize']],
                ['color', ['color']],
                ['para', ['ul', 'ol', 'paragraph']],
                ['insert', ['link', 'picture', 'video']],
                ['view', ['fullscreen', 'codeview', 'help']]
            ]
        });
    });

    function addTag(tag) {
        $('#summernote').summernote('editor.insertText', tag);
    }
</script>
</body>
</html>