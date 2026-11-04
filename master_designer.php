<?php
/**
 * master_designer.php (MASTER)
 * Cadastro de templates/arts para o "ArtesClub"
 * - Cria tabela artes_club automaticamente
 * - Adiciona arte: titulo, categoria, tags, thumb_url, canva_url, licenca, formato, edicao, legenda, descricao, ativo
 * - Lista + busca + edição + ativar/desativar + excluir
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/config.php';

// --- SEGURANÇA MASTER ---
if (!isset($_SESSION['permissoes']) || !in_array('master', (array)$_SESSION['permissoes'])) {
    header("Location: login.php"); exit;
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function norm($s){ return trim((string)$s); }

// ==========================================================
// 0) AUTO-DB: cria tabela se não existir
// ==========================================================
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS artes_club (
            id INT AUTO_INCREMENT PRIMARY KEY,
            titulo VARCHAR(160) NOT NULL,
            categoria VARCHAR(80) DEFAULT NULL,
            tags TEXT DEFAULT NULL,
            thumb_url TEXT NOT NULL,
            canva_url TEXT NOT NULL,
            formato VARCHAR(30) DEFAULT 'ARTE',
            edicao VARCHAR(30) DEFAULT 'Canva',
            licenca VARCHAR(30) DEFAULT 'Premium',
            legenda_sugerida TEXT DEFAULT NULL,
            descricao TEXT DEFAULT NULL,
            ativo TINYINT(1) DEFAULT 1,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Índices úteis (se não existirem)
    // (Em alguns hosts, tentar criar índice duplicado dá erro: por isso try/catch)
    try { $pdo->exec("CREATE INDEX idx_artesclub_cat ON artes_club (categoria);"); } catch(Exception $e){}
    try { $pdo->exec("CREATE INDEX idx_artesclub_ativo ON artes_club (ativo);"); } catch(Exception $e){}
} catch(Exception $e){
    die("Erro ao preparar banco: " . h($e->getMessage()));
}

// ==========================================================
// 1) AÇÕES (CRUD)
// ==========================================================
$msg = "";

// CREATE
if (isset($_POST['acao']) && $_POST['acao'] === 'criar') {
    $titulo   = norm($_POST['titulo'] ?? '');
    $categoria= norm($_POST['categoria'] ?? '');
    $tags     = norm($_POST['tags'] ?? '');
    $thumb    = norm($_POST['thumb_url'] ?? '');
    $canva    = norm($_POST['canva_url'] ?? '');
    $licenca  = norm($_POST['licenca'] ?? 'Premium');
    $formato  = norm($_POST['formato'] ?? 'ARTE');
    $edicao   = norm($_POST['edicao'] ?? 'Canva');
    $legenda  = (string)($_POST['legenda_sugerida'] ?? '');
    $desc     = (string)($_POST['descricao'] ?? '');
    $ativo    = isset($_POST['ativo']) ? 1 : 0;

    if ($titulo === '' || $thumb === '' || $canva === '') {
        $msg = "<div class='alert alert-warning'>Preencha <b>Título</b>, <b>Thumb URL</b> e <b>Link Canva</b>.</div>";
    } else {
        $st = $pdo->prepare("INSERT INTO artes_club (titulo,categoria,tags,thumb_url,canva_url,licenca,formato,edicao,legenda_sugerida,descricao,ativo) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $st->execute([$titulo, $categoria ?: null, $tags ?: null, $thumb, $canva, $licenca ?: 'Premium', $formato ?: 'ARTE', $edicao ?: 'Canva', $legenda ?: null, $desc ?: null, $ativo]);
        $msg = "<div class='alert alert-success'>Template cadastrado!</div>";
    }
}

// UPDATE
if (isset($_POST['acao']) && $_POST['acao'] === 'editar') {
    $id       = (int)($_POST['id'] ?? 0);
    $titulo   = norm($_POST['titulo'] ?? '');
    $categoria= norm($_POST['categoria'] ?? '');
    $tags     = norm($_POST['tags'] ?? '');
    $thumb    = norm($_POST['thumb_url'] ?? '');
    $canva    = norm($_POST['canva_url'] ?? '');
    $licenca  = norm($_POST['licenca'] ?? 'Premium');
    $formato  = norm($_POST['formato'] ?? 'ARTE');
    $edicao   = norm($_POST['edicao'] ?? 'Canva');
    $legenda  = (string)($_POST['legenda_sugerida'] ?? '');
    $desc     = (string)($_POST['descricao'] ?? '');
    $ativo    = isset($_POST['ativo']) ? 1 : 0;

    if ($id <= 0 || $titulo === '' || $thumb === '' || $canva === '') {
        $msg = "<div class='alert alert-warning'>Dados inválidos para editar.</div>";
    } else {
        $st = $pdo->prepare("UPDATE artes_club SET titulo=?, categoria=?, tags=?, thumb_url=?, canva_url=?, licenca=?, formato=?, edicao=?, legenda_sugerida=?, descricao=?, ativo=? WHERE id=?");
        $st->execute([$titulo, $categoria ?: null, $tags ?: null, $thumb, $canva, $licenca ?: 'Premium', $formato ?: 'ARTE', $edicao ?: 'Canva', $legenda ?: null, $desc ?: null, $ativo, $id]);
        $msg = "<div class='alert alert-success'>Template atualizado!</div>";
    }
}

// TOGGLE ATIVO
if (isset($_GET['acao']) && $_GET['acao'] === 'toggle' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $cur = $pdo->prepare("SELECT ativo FROM artes_club WHERE id=?");
    $cur->execute([$id]);
    $ativo = (int)$cur->fetchColumn();
    $novo = $ativo ? 0 : 1;
    $pdo->prepare("UPDATE artes_club SET ativo=? WHERE id=?")->execute([$novo, $id]);
    header("Location: master_designer.php"); exit;
}

// DELETE
if (isset($_GET['acao']) && $_GET['acao'] === 'del' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $pdo->prepare("DELETE FROM artes_club WHERE id=?")->execute([$id]);
    header("Location: master_designer.php"); exit;
}

// ==========================================================
// 2) LISTAGEM
// ==========================================================
$q = norm($_GET['q'] ?? '');
$cat = norm($_GET['cat'] ?? '');
$where = "1=1";
$params = [];

if ($cat !== '') { $where .= " AND categoria = ?"; $params[] = $cat; }
if ($q !== '') {
    $where .= " AND (titulo LIKE ? OR categoria LIKE ? OR tags LIKE ? OR descricao LIKE ?)";
    $like = "%$q%";
    array_push($params, $like, $like, $like, $like);
}

$categorias = [];
try {
    $categorias = $pdo->query("SELECT DISTINCT categoria FROM artes_club ORDER BY categoria ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch(Exception $e){ $categorias=[]; }

$itens = [];
try {
    $st = $pdo->prepare("SELECT * FROM artes_club WHERE $where ORDER BY id DESC");
    $st->execute($params);
    $itens = $st->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e){
    $itens = [];
}
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>MASTER • Modelos (ArtesClub)</title>

  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

  <style>
    body{ background:#f3f4f6; font-family:Inter,system-ui; }
    .cardx{ border:0; border-radius:18px; box-shadow:0 12px 30px rgba(0,0,0,.08); }
    .thumb{ width:92px; height:58px; border-radius:12px; object-fit:cover; border:1px solid rgba(0,0,0,.08); background:#e5e7eb; }
    .badge-soft{ background:rgba(79,70,229,.10); color:#4f46e5; border:1px solid rgba(79,70,229,.25); }
    .mono{ font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace; font-size:.82rem;}
  </style>
</head>
<body>

<div class="container py-4">

  <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
    <div>
      <div class="fw-black" style="font-weight:900;font-size:1.35rem;">
        <i class="fa-solid fa-palette me-2 text-primary"></i> Modelos (ArtesClub)
      </div>
      <div class="text-muted">Cadastre links do Canva + thumbnail para aparecer no artesclub.php</div>
    </div>
    <div class="d-flex gap-2">
      <a href="master_painel.php" class="btn btn-light border fw-bold"><i class="fa-solid fa-arrow-left me-2"></i> Voltar</a>
      <button class="btn btn-primary fw-bold" onclick="openCreate()"><i class="fa-solid fa-plus me-2"></i> Novo Template</button>
    </div>
  </div>

  <?= $msg ?>

  <div class="cardx bg-white p-3 mb-3">
    <form class="row g-2 align-items-end" method="GET">
      <div class="col-md-5">
        <label class="small fw-bold text-muted">Busca</label>
        <input type="text" name="q" class="form-control" value="<?= h($q) ?>" placeholder="Ex: educação, saúde, segurança...">
      </div>
      <div class="col-md-4">
        <label class="small fw-bold text-muted">Categoria</label>
        <select name="cat" class="form-select">
          <option value="">Todas</option>
          <?php foreach($categorias as $c): ?>
            <option value="<?= h($c) ?>" <?= ($cat===$c?'selected':'') ?>><?= h($c) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3 d-flex gap-2">
        <button class="btn btn-dark fw-bold w-100"><i class="fa-solid fa-magnifying-glass me-2"></i> Filtrar</button>
        <a class="btn btn-light border fw-bold" href="master_designer.php">Limpar</a>
      </div>
    </form>
  </div>

  <div class="cardx bg-white overflow-hidden">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th class="ps-3">Thumb</th>
            <th>Título</th>
            <th>Categoria</th>
            <th>Licença</th>
            <th>Status</th>
            <th class="text-end pe-3">Ações</th>
          </tr>
        </thead>
        <tbody>
        <?php if(empty($itens)): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">Nenhum template cadastrado.</td></tr>
        <?php else: foreach($itens as $t): ?>
          <tr>
            <td class="ps-3">
              <img class="thumb" src="<?= h($t['thumb_url']) ?>" alt="">
            </td>
            <td>
              <div class="fw-bold"><?= h($t['titulo']) ?></div>
              <div class="text-muted small mono text-truncate" style="max-width:520px;">
                Canva: <?= h($t['canva_url']) ?>
              </div>
            </td>
            <td><span class="badge badge-soft"><?= h($t['categoria'] ?: '—') ?></span></td>
            <td><?= h($t['licenca'] ?: 'Premium') ?></td>
            <td>
              <?= ((int)$t['ativo']===1)
                  ? '<span class="badge bg-success">Ativo</span>'
                  : '<span class="badge bg-secondary">Inativo</span>'; ?>
            </td>
            <td class="text-end pe-3">
              <button class="btn btn-sm btn-light border" onclick='openEdit(<?= json_encode($t) ?>)'>
                <i class="fa-solid fa-pen"></i>
              </button>
              <a class="btn btn-sm btn-light border" href="master_designer.php?acao=toggle&id=<?= (int)$t['id'] ?>"
                 title="Ativar/Inativar">
                <i class="fa-solid fa-toggle-on"></i>
              </a>
              <a class="btn btn-sm btn-light border text-danger"
                 href="master_designer.php?acao=del&id=<?= (int)$t['id'] ?>"
                 onclick="return confirm('Excluir este template?');">
                <i class="fa-solid fa-trash"></i>
              </a>
              <a class="btn btn-sm btn-primary"
                 href="<?= h($t['canva_url']) ?>" target="_blank" title="Abrir no Canva">
                <i class="fa-brands fa-canva"></i>
              </a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- MODAL CRIAR/EDITAR -->
<div class="modal fade" id="modalTpl" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <form method="POST" class="modal-content border-0 shadow" style="border-radius:18px;">
      <div class="modal-header border-0 bg-light">
        <h5 class="modal-title fw-black" style="font-weight:900;" id="modalTitle">Novo Template</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body p-4">
        <input type="hidden" name="acao" id="acao" value="criar">
        <input type="hidden" name="id" id="id" value="">

        <div class="row g-3">
          <div class="col-md-7">
            <label class="small fw-bold text-muted">Título *</label>
            <input type="text" name="titulo" id="titulo" class="form-control" required>
          </div>
          <div class="col-md-5">
            <label class="small fw-bold text-muted">Categoria</label>
            <input type="text" name="categoria" id="categoria" class="form-control" placeholder="Ex: Prefeitura / Vereador / Educação">
          </div>

          <div class="col-md-12">
            <label class="small fw-bold text-muted">Tags (separadas por vírgula)</label>
            <input type="text" name="tags" id="tags" class="form-control" placeholder="educação pública, escolas, compromisso">
          </div>

          <div class="col-md-6">
            <label class="small fw-bold text-muted">Thumb URL *</label>
            <input type="url" name="thumb_url" id="thumb_url" class="form-control" required placeholder="https://.../thumb.jpg">
            <div class="text-muted small mt-1">Use imagem pública (ou um upload seu com link).</div>
          </div>
          <div class="col-md-6">
            <label class="small fw-bold text-muted">Link Canva *</label>
            <input type="url" name="canva_url" id="canva_url" class="form-control" required placeholder="https://www.canva.com/design/...">
            <div class="text-muted small mt-1">Coloque o link do template/editável do Canva.</div>
          </div>

          <div class="col-md-4">
            <label class="small fw-bold text-muted">Licença</label>
            <select name="licenca" id="licenca" class="form-select">
              <option value="Premium">Premium</option>
              <option value="Free">Free</option>
              <option value="Interno">Interno</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="small fw-bold text-muted">Formato</label>
            <select name="formato" id="formato" class="form-select">
              <option value="ARTE">ARTE</option>
              <option value="STORY">STORY</option>
              <option value="CARROSSEL">CARROSSEL</option>
              <option value="VIDEO">VIDEO</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="small fw-bold text-muted">Edição</label>
            <select name="edicao" id="edicao" class="form-select">
              <option value="Canva">Canva</option>
              <option value="Designer Express">Designer Express</option>
            </select>
          </div>

          <div class="col-md-12">
            <label class="small fw-bold text-muted">Descrição</label>
            <textarea name="descricao" id="descricao" class="form-control" rows="2" placeholder="Resumo da arte..."></textarea>
          </div>

          <div class="col-md-12">
            <label class="small fw-bold text-muted">Legenda sugerida</label>
            <textarea name="legenda_sugerida" id="legenda_sugerida" class="form-control" rows="5" placeholder="Texto pronto para copiar..."></textarea>
          </div>

          <div class="col-md-12">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="ativo" id="ativo" checked>
              <label class="form-check-label fw-bold" for="ativo">Ativo (aparece no design express)</label>
            </div>
          </div>

          <div class="col-md-12">
            <div class="d-flex gap-2 align-items-center">
              <img id="previewImg" src="" alt="" style="width:140px;height:88px;object-fit:cover;border-radius:14px;border:1px solid rgba(0,0,0,.08);display:none;">
              <div class="text-muted small">Preview aparece quando você colar o Thumb URL.</div>
            </div>
          </div>

        </div>
      </div>

      <div class="modal-footer border-0 bg-light">
        <button type="button" class="btn btn-light border fw-bold" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary fw-bold px-4"><i class="fa-solid fa-floppy-disk me-2"></i> Salvar</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const modalEl = document.getElementById('modalTpl');
  const modal = new bootstrap.Modal(modalEl);

  function clearForm(){
    document.getElementById('acao').value = 'criar';
    document.getElementById('id').value = '';
    document.getElementById('modalTitle').innerText = 'Novo Template';
    ['titulo','categoria','tags','thumb_url','canva_url','descricao','legenda_sugerida'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('licenca').value = 'Premium';
    document.getElementById('formato').value = 'ARTE';
    document.getElementById('edicao').value = 'Canva';
    document.getElementById('ativo').checked = true;
    updatePreview('');
  }

  function updatePreview(url){
    const img = document.getElementById('previewImg');
    if(!url){
      img.style.display = 'none';
      img.src = '';
      return;
    }
    img.style.display = 'inline-block';
    img.src = url;
  }

  function openCreate(){
    clearForm();
    modal.show();
  }

  function openEdit(t){
    document.getElementById('acao').value = 'editar';
    document.getElementById('id').value = t.id || '';
    document.getElementById('modalTitle').innerText = 'Editar Template';

    document.getElementById('titulo').value = t.titulo || '';
    document.getElementById('categoria').value = t.categoria || '';
    document.getElementById('tags').value = t.tags || '';
    document.getElementById('thumb_url').value = t.thumb_url || '';
    document.getElementById('canva_url').value = t.canva_url || '';
    document.getElementById('licenca').value = t.licenca || 'Premium';
    document.getElementById('formato').value = t.formato || 'ARTE';
    document.getElementById('edicao').value = t.edicao || 'Canva';
    document.getElementById('descricao').value = t.descricao || '';
    document.getElementById('legenda_sugerida').value = t.legenda_sugerida || '';
    document.getElementById('ativo').checked = (parseInt(t.ativo||0) === 1);

    updatePreview(t.thumb_url || '');
    modal.show();
  }

  document.getElementById('thumb_url').addEventListener('input', (e)=> updatePreview(e.target.value.trim()));
</script>

</body>
</html>
