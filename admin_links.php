<?php
require 'config.php';
require 'protecao.php';

if (!defined('TENANT_ID')) die("Erro de acesso.");

// 1. SALVAR NOVO LINK
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['btn_salvar'])) {
    // Limpa o código para aceitar apenas letras, números e hífens
    $codigo = strtolower(preg_replace('/[^a-z0-9-]/', '', $_POST['codigo']));
    $url = $_POST['url_destino'];

    // Verifica se já existe esse slug nas landing pages para evitar conflito (Opcional, mas recomendado)
    $checkLP = $pdo->prepare("SELECT id FROM landing_pages WHERE slug = ? AND tenant_id = ?");
    $checkLP->execute([$codigo, TENANT_ID]);
    
    if ($checkLP->rowCount() > 0) {
        $erro = "Este código já está sendo usado por uma Landing Page.";
    } else {
        $stmt = $pdo->prepare("INSERT INTO links_curtos (tenant_id, codigo, url_destino) VALUES (?, ?, ?)");
        $stmt->execute([TENANT_ID, $codigo, $url]);
        header("Location: admin_links.php?sucesso=1");
        exit;
    }
}

// 2. EXCLUIR LINK
if (isset($_GET['excluir'])) {
    $stmt = $pdo->prepare("DELETE FROM links_curtos WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$_GET['excluir'], TENANT_ID]);
    header("Location: admin_links.php?excluido=1");
    exit;
}

// 3. BUSCAR LINKS EXISTENTES
$stmt = $pdo->prepare("SELECT * FROM links_curtos WHERE tenant_id = ? ORDER BY criado_em DESC");
$stmt->execute([TENANT_ID]);
$links = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Domínio base para exibição (Agora sem o /r/)
$protocolo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$url_base = $protocolo . $_SERVER['HTTP_HOST'] . "/go/";
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Gerenciador de Links - Gabinete Digital</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f3f4f6; font-family: 'Inter', sans-serif; }
        .card { border: none; border-radius: 15px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
        .table thead { background: #f8fafc; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.5px; }
        .link-curto { color: #4f46e5; font-weight: 700; text-decoration: none; }
        .cliques-badge { background: #eef2ff; color: #4f46e5; font-weight: 800; padding: 5px 12px; border-radius: 20px; font-size: 0.85rem; }
        .copy-btn { padding: 2px 8px; font-size: 0.8rem; }
    </style>
</head>
<body class="py-5">

<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold m-0 text-dark">Encurtador de Links</h3>
            <p class="text-muted small">Crie links diretos e monitore os cliques dos eleitores.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="admin_painel.php" class="btn btn-light border rounded-pill px-4">Voltar</a>
            <button class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#modalNovoLink">
                <i class="fas fa-plus me-2"></i> NOVO LINK
            </button>
        </div>
    </div>

    <?php if(isset($_GET['sucesso'])): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-4 mb-4"><i class="fas fa-check-circle me-2"></i> Link criado com sucesso!</div>
    <?php endif; ?>
    
    <?php if(isset($erro)): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 mb-4"><i class="fas fa-exclamation-circle me-2"></i> <?= $erro ?></div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4 py-3">URL Curta (Direta)</th>
                        <th>Destino</th>
                        <th class="text-center">Cliques</th>
                        <th class="text-end pe-4">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($links as $l): ?>
                    <tr>
                        <td class="ps-4">
                            <span class="link-curto"><?= $url_base . $l['codigo'] ?></span>
                            <button class="btn btn-light btn-sm ms-2 copy-btn" onclick="copyToClipboard('<?= $url_base . $l['codigo'] ?>')" title="Copiar Link">
                                <i class="far fa-copy text-muted"></i>
                            </button>
                        </td>
                        <td>
                            <div class="text-muted small text-truncate" style="max-width: 250px;" title="<?= $l['url_destino'] ?>">
                                <i class="fas fa-external-link-alt me-1 opacity-50"></i> <?= $l['url_destino'] ?>
                            </div>
                        </td>
                        <td class="text-center">
                            <span class="cliques-badge"><i class="fas fa-chart-line me-1"></i> <?= $l['cliques'] ?></span>
                        </td>
                        <td class="text-end pe-4">
                            <a href="https://wa.me/?text=<?= urlencode("Confira: " . $url_base . $l['codigo']) ?>" target="_blank" class="btn btn-sm btn-outline-success border-0 me-1" title="Enviar no WhatsApp">
                                <i class="fab fa-whatsapp"></i>
                            </a>
                            <a href="?excluir=<?= $l['id'] ?>" class="btn btn-sm btn-outline-danger border-0" onclick="return confirm('Excluir este link permanentemente?')" title="Excluir">
                                <i class="fas fa-trash"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($links)): ?>
                        <tr>
                            <td colspan="4" class="text-center py-5">
                                <i class="fas fa-link fa-3x text-light mb-3"></i>
                                <p class="text-muted">Nenhum link encurtado. Comece criando um novo!</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalNovoLink" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content border-0 shadow-lg rounded-4" method="POST">
            <div class="modal-header border-0 pb-0">
                <h5 class="fw-bold m-0">Novo Redirecionamento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                
                <div class="mb-4">
                    <label class="form-label small fw-bold text-muted">URL DE DESTINO (Link Completo)</label>
                    <input type="url" name="url_destino" class="form-control form-control-lg bg-light border-0" placeholder="https://exemplo.com/pagina-longa" required>
                </div>

                <div class="mb-2">
                    <label class="form-label small fw-bold text-muted">CÓDIGO DO LINK (Apelido)</label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text bg-white border-end-0 text-muted small" style="font-size: 0.8rem;"><?= $url_base ?></span>
                        <input type="text" name="codigo" class="form-control bg-white border-start-0 ps-0" placeholder="ex: whatsapp" required>
                    </div>
                    <div class="form-text mt-2">O link final será: <strong><?= $url_base ?>seu-apelido</strong></div>
                </div>

            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" name="btn_salvar" class="btn btn-primary rounded-pill px-4 fw-bold">GERAR LINK</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        // Alerta flutuante simples
        const toast = document.createElement('div');
        toast.innerHTML = 'Copiado!';
        toast.style = "position:fixed; bottom:20px; left:50%; transform:translateX(-50%); background:#333; color:#white; padding:8px 20px; border-radius:50px; z-index:9999; font-size:14px;";
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 2000);
    });
}
</script>
</body>
</html>