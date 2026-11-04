<?php
require 'config.php';
require 'protecao.php';

if (file_exists('funcoes_templates.php')) {
    require 'funcoes_templates.php';
}

if (!defined('TENANT_ID')) {
    die("Erro de identificação.");
}

// ======================================================
// FUNÇÕES AUXILIARES
// ======================================================

function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function coluna_existe(PDO $pdo, string $tabela, string $coluna): bool {
    static $cache = [];

    $key = $tabela . '_' . $coluna;

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM {$tabela} LIKE ?");
        $stmt->execute([$coluna]);
        $cache[$key] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        return $cache[$key];
    } catch (Exception $e) {
        return false;
    }
}

function pagina_tem_editor_blocos(array $pagina): bool {
    if (empty($pagina['dados_json'])) {
        return false;
    }

    $json = json_decode($pagina['dados_json'], true);

    return is_array($json)
        && isset($json['version'])
        && isset($json['sections'])
        && is_array($json['sections']);
}

// ======================================================
// 1. AÇÕES
// ======================================================

if (isset($_GET['excluir'])) {
    $idExcluir = (int)$_GET['excluir'];

    $stmt = $pdo->prepare("DELETE FROM landing_pages WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$idExcluir, TENANT_ID]);

    header("Location: admin_paginas.php?sucesso=1");
    exit;
}

if (isset($_GET['set_home'])) {
    $idHome = (int)$_GET['set_home'];

    $pdo->prepare("UPDATE landing_pages SET is_home = 0 WHERE tenant_id = ?")
        ->execute([TENANT_ID]);

    $pdo->prepare("UPDATE landing_pages SET is_home = 1 WHERE id = ? AND tenant_id = ?")
        ->execute([$idHome, TENANT_ID]);

    header("Location: admin_paginas.php?sucesso=1");
    exit;
}

// ======================================================
// 2. DADOS DO CLIENTE / TAGS DINÂMICAS
// ======================================================

$stmt_conf = $pdo->prepare("SELECT * FROM configuracoes WHERE tenant_id = ?");
$stmt_conf->execute([TENANT_ID]);
$conf_cliente = $stmt_conf->fetch(PDO::FETCH_ASSOC);

if (!$conf_cliente) {
    $conf_cliente = [];
}

$whatsapp_limpo = preg_replace('/[^0-9]/', '', $conf_cliente['whatsapp_oficial'] ?? '');

$whatsapp_link = !empty($whatsapp_limpo)
    ? 'https://wa.me/55' . $whatsapp_limpo
    : '#';

$dados_cliente = [
    '{{NOME_VEREADOR}}'  => $conf_cliente['nome_vereador'] ?? 'Seu Nome Aqui',
    '{{SLUG}}'           => '#',
    '{{CARGO}}'          => $conf_cliente['hero_cargo'] ?? 'Vereador',
    '{{WHATSAPP_LINK}}'  => $whatsapp_link,
    '{{COR_PRIMARIA}}'   => $conf_cliente['cor_primaria'] ?? '#0d6efd',
    '{{COR_SECUNDARIA}}' => $conf_cliente['cor_secundaria'] ?? '#ffc107',
    '{{FOTO_CANDIDATO}}' => $conf_cliente['foto_hero'] ?? 'https://via.placeholder.com/600x600?text=Sua+Foto',
    '{{LOGO}}'           => $conf_cliente['logo_site'] ?? 'https://via.placeholder.com/150x50?text=LOGO',

    '{{TITULO_HERO}}'    => 'Trabalho e Compromisso',
    '{{TEXTO_HERO}}'     => 'Vamos juntos construir uma cidade melhor.',
    '{{TXT_BOTAO}}'      => 'Fale Comigo'
];

// ======================================================
// 3. BUSCAS
// ======================================================

$paginas = $pdo->prepare("SELECT * FROM landing_pages WHERE tenant_id = ? ORDER BY criado_em DESC");
$paginas->execute([TENANT_ID]);
$lista_paginas = $paginas->fetchAll(PDO::FETCH_ASSOC);

$stmt_master = $pdo->prepare("SELECT * FROM master_templates WHERE status = 1 ORDER BY id DESC");
$stmt_master->execute();
$modelos_master = $stmt_master->fetchAll(PDO::FETCH_ASSOC);

// Detecta campos de HTML disponíveis
$tem_html = coluna_existe($pdo, 'landing_pages', 'html');
$tem_html_content = coluna_existe($pdo, 'landing_pages', 'html_content');
$tem_conteudo_html = coluna_existe($pdo, 'landing_pages', 'conteudo_html');

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Gerenciador de Sites</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        body {
            background-color: #f8fafc;
            font-family: 'Inter', Arial, sans-serif;
        }

        .card {
            border: none;
            border-radius: 16px;
            overflow: hidden;
        }

        .template-card {
            transition: 0.3s;
            border: 2px solid transparent;
            background: white;
            position: relative;
        }

        .template-card:hover {
            transform: translateY(-5px);
            border-color: #0d6efd;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .preview-box {
            height: 220px;
            width: 100%;
            overflow: hidden;
            background: #f1f1f1;
            border-bottom: 1px solid #eee;
            position: relative;
        }

        .preview-overlay {
            position: absolute;
            inset: 0;
            z-index: 10;
            background: rgba(0,0,0,0.04);
            cursor: pointer;
        }

        .preview-iframe-mini {
            width: 133.33%;
            height: 133.33%;
            border: none;
            transform: scale(0.75);
            transform-origin: 0 0;
            pointer-events: none;
            background: white;
        }

        .modal-fullscreen .modal-body {
            padding: 0;
            overflow: hidden;
            background: #333;
        }

        .iframe-full {
            width: 100%;
            height: 100%;
            border: none;
            background: #fff;
        }

        .card-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }

        .badge-home {
            background: linear-gradient(135deg, #16a34a, #22c55e);
        }

        .page-title {
            max-width: 360px;
        }

        .empty-box {
            border: 2px dashed #d1d5db;
            border-radius: 16px;
            padding: 28px;
            background: #fff;
        }

        .btn-icon {
            width: 34px;
            height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
        }

        .tag-mini {
            font-size: 11px;
            border-radius: 999px;
            padding: 4px 8px;
            background: #f1f5f9;
            color: #475569;
            font-weight: 700;
        }
    </style>
</head>

<body class="py-5">

<div class="container">

    <div class="d-flex justify-content-between align-items-center mb-5 gap-3 flex-wrap">
        <div>
            <h2 class="fw-bold m-0 text-primary">MEUS SITES</h2>
            <p class="text-muted small mb-0">
                Gerencie suas páginas ou escolha um modelo novo.
            </p>
        </div>

        <div class="d-flex gap-2">
            <a href="admin_painel.php" class="btn btn-outline-secondary rounded-pill px-3">
                <i class="fas fa-arrow-left me-1"></i> Voltar
            </a>

            <a href="admin_lp_editor.php" class="btn btn-primary fw-bold rounded-pill px-4 shadow">
                <i class="fas fa-plus me-2"></i> PÁGINA EM BRANCO
            </a>
        </div>
    </div>

    <?php if (isset($_GET['sucesso'])): ?>
        <div class="alert alert-success shadow-sm border-0 mb-4">
            <i class="fas fa-check-circle me-2"></i> Operação realizada com sucesso!
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['erro'])): ?>
        <div class="alert alert-danger shadow-sm border-0 mb-4">
            <i class="fas fa-triangle-exclamation me-2"></i>
            Ocorreu um erro: <?= h($_GET['erro']) ?>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm mb-5">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light text-secondary small text-uppercase">
                    <tr>
                        <th class="ps-4">Título</th>
                        <th>Tipo</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Ações</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (count($lista_paginas) > 0): ?>
                    <?php foreach ($lista_paginas as $p): ?>
                        <?php
                            $slug = $p['slug'] ?? '';
                            $url_visualizar = '/' . ltrim($slug, '/');

                            $eh_editor_blocos = pagina_tem_editor_blocos($p);

                            if ($eh_editor_blocos) {
                                $tipo_pagina = 'Editor de blocos';
                                $tipo_badge = 'bg-primary';
                            } else {
                                $tipo_pagina = 'Modelo pronto';
                                $tipo_badge = 'bg-dark';
                            }
                        ?>
                        <tr>
                            <td class="ps-4">
                                <span class="fw-bold text-dark page-title d-inline-block text-truncate">
                                    <?= h($p['titulo'] ?? 'Sem título') ?>
                                </span>
                                <br>
                                <small class="text-muted">
                                    <?= !empty($p['criado_em']) ? date('d/m/Y', strtotime($p['criado_em'])) : 'Sem data' ?>
                                    <?php if (!empty($slug)): ?>
                                        · /<?= h($slug) ?>
                                    <?php endif; ?>
                                </small>
                            </td>

                            <td>
                                <span class="badge <?= $tipo_badge ?>">
                                    <?= h($tipo_pagina) ?>
                                </span>
                            </td>

                            <td>
                                <?php if (!empty($p['is_home'])): ?>
                                    <span class="badge badge-home">HOME ATIVA</span>
                                <?php else: ?>
                                    <span class="badge bg-light text-dark border">Secundária</span>
                                <?php endif; ?>
                            </td>

                            <td class="text-end pe-4">
                                <a href="<?= h($url_visualizar) ?>"
                                   target="_blank"
                                   class="btn btn-sm btn-outline-primary btn-icon"
                                   title="Visualizar página">
                                    <i class="fas fa-eye"></i>
                                </a>

                                <?php if ($eh_editor_blocos): ?>
                                    <a href="admin_lp_editor.php?id=<?= (int)$p['id'] ?>"
                                       class="btn btn-sm btn-dark btn-icon"
                                       title="Editar no editor de blocos">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                <?php else: ?>
                                    <button type="button"
                                            class="btn btn-sm btn-secondary btn-icon"
                                            title="Este modelo é HTML pronto. Não abre no editor de blocos."
                                            onclick="alert('Este é um modelo pronto em HTML. Ele não deve abrir no editor de blocos. Para alterar, edite o template master ou crie um editor HTML simples.')">
                                        <i class="fas fa-code"></i>
                                    </button>
                                <?php endif; ?>

                                <?php if (empty($p['is_home'])): ?>
                                    <a href="?set_home=<?= (int)$p['id'] ?>"
                                       class="btn btn-sm btn-outline-success btn-icon"
                                       title="Definir como Home">
                                        <i class="fas fa-home"></i>
                                    </a>
                                <?php endif; ?>

                                <a href="?excluir=<?= (int)$p['id'] ?>"
                                   onclick="return confirm('Excluir esta página?')"
                                   class="btn btn-sm btn-outline-danger btn-icon"
                                   title="Excluir">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" class="text-center py-4 text-muted">
                            Nenhuma página criada.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>

            </table>
        </div>
    </div>

    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
        <h4 class="fw-bold mb-0 border-start border-4 border-primary ps-3">
            MODELOS DISPONÍVEIS
        </h4>

        <div class="d-flex gap-2 flex-wrap">
            <span class="tag-mini">{{NOME_VEREADOR}}</span>
            <span class="tag-mini">{{CARGO}}</span>
            <span class="tag-mini">{{WHATSAPP_LINK}}</span>
            <span class="tag-mini">{{FOTO_CANDIDATO}}</span>
            <span class="tag-mini">{{LOGO}}</span>
        </div>
    </div>

    <?php if (count($modelos_master) === 0): ?>
        <div class="empty-box text-center text-muted">
            <i class="fas fa-layer-group fa-2x mb-3"></i>
            <h5 class="fw-bold text-dark">Nenhum modelo cadastrado</h5>
            <p class="mb-0">Cadastre modelos na tabela <strong>master_templates</strong>.</p>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <?php foreach ($modelos_master as $tpl): ?>
            <?php
                $html_bruto = $tpl['html_bruto'] ?? '';

                $html_personalizado = str_replace(
                    array_keys($dados_cliente),
                    array_values($dados_cliente),
                    $html_bruto
                );

                $tpl_id = (int)$tpl['id'];
                $tpl_nome = $tpl['nome'] ?? 'Modelo';
                $tpl_categoria = $tpl['categoria'] ?? 'Geral';
            ?>

            <div class="col-md-6 col-lg-4">
                <div class="card template-card shadow-sm h-100">

                    <textarea id="html_tpl_<?= $tpl_id ?>" style="display:none;"><?= h($html_personalizado) ?></textarea>

                    <div class="preview-box" onclick="abrirModalPreview('<?= $tpl_id ?>', '<?= h($tpl_nome) ?>')">
                        <div class="preview-overlay"></div>
                        <iframe
                            class="preview-iframe-mini"
                            srcdoc="<?= h($html_personalizado) ?>"
                            loading="lazy">
                        </iframe>
                    </div>

                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center mb-2 gap-2">
                            <h6 class="fw-bold text-dark mb-0 text-truncate">
                                <?= h($tpl_nome) ?>
                            </h6>
                            <span class="badge bg-secondary small">
                                <?= h($tpl_categoria) ?>
                            </span>
                        </div>

                        <div class="card-actions">
                            <button class="btn btn-outline-primary w-50 btn-sm fw-bold"
                                    onclick="abrirModalPreview('<?= $tpl_id ?>', '<?= h($tpl_nome) ?>')">
                                <i class="fas fa-eye me-1"></i> Visualizar
                            </button>

                            <button class="btn btn-primary w-50 btn-sm fw-bold"
                                    onclick="usarModelo('<?= $tpl_id ?>', '<?= h($tpl_nome) ?>')">
                                <i class="fas fa-magic me-1"></i> Usar
                            </button>
                        </div>
                    </div>

                </div>
            </div>
        <?php endforeach; ?>
    </div>

</div>

<div class="modal fade" id="modalPreview" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content bg-light">

            <div class="modal-header border-bottom py-2 bg-white">
                <div>
                    <h5 class="modal-title fs-6 fw-bold" id="modalTitulo">
                        Visualizando Modelo
                    </h5>
                    <small class="text-muted">
                        Este é um exemplo com seus dados atuais.
                    </small>
                </div>

                <div class="d-flex align-items-center gap-3">
                    <button type="button"
                            class="btn btn-secondary btn-sm px-4"
                            data-bs-dismiss="modal">
                        Fechar
                    </button>

                    <button type="button"
                            class="btn btn-success btn-sm fw-bold px-4 shadow"
                            id="btnUsarModal">
                        <i class="fas fa-check me-2"></i> QUERO ESTE MODELO
                    </button>
                </div>
            </div>

            <div class="modal-body">
                <iframe id="iframeFull" class="iframe-full"></iframe>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
function abrirModalPreview(id, nome) {
    var campo = document.getElementById('html_tpl_' + id);

    if (!campo) {
        alert('HTML do modelo não encontrado.');
        return;
    }

    var htmlContent = campo.value;

    document.getElementById('modalTitulo').innerText = nome;

    var iframe = document.getElementById('iframeFull');
    iframe.srcdoc = htmlContent;

    document.getElementById('btnUsarModal').onclick = function() {
        usarModelo(id, nome);
    };

    var myModal = new bootstrap.Modal(document.getElementById('modalPreview'));
    myModal.show();
}

function usarModelo(id, nome) {
    if (confirm('Aplicar o modelo "' + nome + '"?\n\nIsso criará uma nova página inicial com este design.')) {
        window.location.href = 'processa_template.php?template_id=' + encodeURIComponent(id);
    }
}
</script>

</body>
</html>