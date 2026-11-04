<?php
/**
 * admin_designer.php (TENANT)
 * Catálogo de artes com Modal de Detalhes
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/protecao.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// --- Tenant
$tenant_id = defined('TENANT_ID') ? TENANT_ID : ($_SESSION['tenant_id'] ?? null);
if (!$tenant_id) { http_response_code(403); die("Acesso negado."); }

// --- Permissões
if (function_exists('tem_acesso')) {
    if (!tem_acesso('designer') && !tem_acesso('templates') && !tem_acesso('master')) {
        http_response_code(403); die("Acesso negado.");
    }
}

// Helpers
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function norm($s) { return trim((string)$s); }

$tenant_nome = $_SESSION['tenant_nome'] ?? 'Artes prontas';

// Filtros
$q = norm($_GET['q'] ?? '');
$categoria = norm($_GET['cat'] ?? '');
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 16;
$offset = ($page - 1) * $perPage;

// Categorias
$categorias = [];
try {
    $categorias = $pdo->query("SELECT DISTINCT categoria FROM artes_club WHERE ativo=1 ORDER BY categoria ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) { $categorias = []; }

// Query Principal
$where = "ativo = 1";
$params = [];

if ($categoria !== '') {
    $where .= " AND categoria = ?";
    $params[] = $categoria;
}

if ($q !== '') {
    $where .= " AND (titulo LIKE ? OR categoria LIKE ? OR tags LIKE ? OR descricao LIKE ?)";
    $like = "%" . $q . "%";
    array_push($params, $like, $like, $like, $like);
}

$total = 0;
$artes = [];

try {
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM artes_club WHERE $where");
    $stmtCount->execute($params);
    $total = (int)$stmtCount->fetchColumn();

    $stmt = $pdo->prepare("SELECT * FROM artes_club WHERE $where ORDER BY id DESC LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    $artes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { }

$pages = max(1, (int)ceil($total / $perPage));

// Relacionadas (Rodapé)
$relacionadas = [];
try {
    $stmtRel = $pdo->prepare("SELECT * FROM artes_club WHERE ativo=1 ORDER BY RAND() LIMIT 10");
    $stmtRel->execute();
    $relacionadas = $stmtRel->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { }

?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Design Express | <?= h($tenant_nome) ?></title>

  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

  <style>
    :root{
      --bg: #f3f4f6;
      --card: #ffffff;
      --text: #111827;
      --muted: #6b7280;
      --primary: #4f46e5;
      --primary2:#6366f1;
      --shadow: 0 12px 30px rgba(0,0,0,.08);
      --r: 18px;
    }
    body{ background: var(--bg); font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial; color: var(--text); }
    
    /* TOPBAR */
    .topbar{
      background: rgba(255,255,255,.85); backdrop-filter: blur(10px);
      border-bottom: 1px solid rgba(17,24,39,.06);
      position: sticky; top:0; z-index: 50;
    }
    .brand-badge{
      width:40px;height:40px;border-radius:14px;
      background: linear-gradient(135deg, var(--primary2), var(--primary));
      display:flex;align-items:center;justify-content:center;color:#fff;
      box-shadow: 0 10px 20px rgba(79,70,229,.25);
    }
    .search{
      border-radius: 999px; border:1px solid rgba(17,24,39,.08); background:#fff;
      box-shadow: 0 6px 18px rgba(0,0,0,.05);
    }
    .search input{ border:0; outline:0; box-shadow:none; }
    .search .btn{ border-radius: 999px; background: var(--primary); border-color: var(--primary); font-weight: 800; }
    
    /* CHIPS */
    .chip{
      border:1px solid rgba(17,24,39,.08); background:#fff; border-radius: 999px;
      padding: 8px 12px; font-weight: 700; color: #374151; text-decoration:none;
      display:inline-flex; gap:8px; align-items:center; transition:0.2s;
    }
    .chip:hover{ background:#f9fafb; }
    .chip.active{
      background: rgba(79,70,229,.10); border-color: rgba(79,70,229,.25); color: var(--primary);
    }

    /* CARDS */
    .card-art{
      border:0; border-radius: var(--r); box-shadow: var(--shadow);
      overflow:hidden; background: var(--card);
      transition: transform .18s ease, box-shadow .18s ease;
    }
    .card-art:hover{ transform: translateY(-4px); box-shadow: 0 18px 45px rgba(0,0,0,.12); }
    
    .thumb{
      height: 210px; background: #e5e7eb; position: relative; cursor: pointer; overflow: hidden;
    }
    .thumb img{ width:100%; height:100%; object-fit: cover; display:block; transition: 0.3s; }
    .card-art:hover .thumb img { transform: scale(1.05); }

    .pill{
      position:absolute; left:12px; top:12px;
      background: rgba(255,255,255,.92); border:1px solid rgba(17,24,39,.08);
      padding:4px 10px; border-radius: 999px; font-weight: 800; font-size: .7rem;
    }
    .pill2{
      position:absolute; right:12px; top:12px;
      background: rgba(17,24,39,.85); color:#fff;
      padding:4px 10px; border-radius: 999px; font-weight: 800; font-size: .7rem;
    }
    
    /* TAGS */
    .tag{
      border:1px solid rgba(17,24,39,.08); border-radius: 999px;
      padding: 4px 10px; font-size: .75rem; color: #374151; background: #f9fafb; display:inline-block;
    }

    /* BUTTONS */
    .btn-open{
      background: linear-gradient(135deg, var(--primary2), var(--primary));
      border:0; font-weight: 900; border-radius: 12px; transition: 0.2s;
    }
    .btn-open:hover{ opacity: 0.9; box-shadow: 0 5px 15px rgba(79,70,229,.3); }
    
    .btn-soft{
      background:#fff; border:1px solid rgba(17,24,39,.10); border-radius: 12px; font-weight: 800;
    }
    .btn-soft:hover{ background: #f9fafb; border-color: #d1d5db; }

    /* FOOTER */
    .footer{
      color: var(--muted); padding: 38px 0;
      border-top: 1px solid rgba(17,24,39,.06); margin-top: 40px;
    }
    .mini{
      width: 160px; border-radius: 14px; overflow:hidden;
      border:1px solid rgba(17,24,39,.06); background:#fff; box-shadow: 0 10px 25px rgba(0,0,0,.06);
    }
    .mini img{ width:100%; height:100px; object-fit: cover; display:block; }
    .mini .t{ font-size:.82rem; font-weight:900; padding:10px; }

    /* --- ESTILOS DO MODAL --- */
    .modal-art .modal-content { border-radius: 20px; border: none; overflow: hidden; }
    .modal-img-col { background: #f3f4f6; display: flex; align-items: center; justify-content: center; min-height: 400px; }
    .modal-img-col img { max-width: 100%; max-height: 80vh; object-fit: contain; box-shadow: 0 10px 40px rgba(0,0,0,0.1); }
    
    .caption-box {
        background: #f8f9fa; border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px;
        margin-top: 20px; font-size: 0.9rem; color: #4b5563; position: relative;
    }
    .caption-title { font-weight: 800; color: #111827; margin-bottom: 10px; display: block; }
    
    .meta-row { display: flex; justify-content: space-between; font-size: 0.85rem; padding: 8px 0; border-bottom: 1px solid #f3f4f6; }
    .meta-label { color: #6b7280; }
    .meta-value { font-weight: 600; color: #111827; }
  </style>
</head>
<body>

<div class="topbar">
  <div class="container py-3">
    <div class="d-flex flex-column flex-md-row gap-3 align-items-md-center justify-content-between">
      <div class="d-flex align-items-center gap-3">
        <div class="brand-badge"><i class="fa-solid fa-wand-magic-sparkles"></i></div>
        <div>
          <div class="fw-black" style="font-weight:900;font-size:1.05rem;">Design Express</div>
          <div class="text-muted small"><?= h($tenant_nome) ?></div>
        </div>
      </div>

      <form class="flex-grow-1" method="GET" style="max-width:720px;">
        <div class="search input-group p-1">
          <span class="input-group-text bg-transparent border-0 ps-3">
            <i class="fa-solid fa-magnifying-glass text-muted"></i>
          </span>
          <input type="text" class="form-control" name="q" value="<?= h($q) ?>"
                 placeholder="Buscar conteúdo (ex: educação, saúde, segurança...)">
          <?php if($categoria !== ''): ?>
            <input type="hidden" name="cat" value="<?= h($categoria) ?>">
          <?php endif; ?>
          <button class="btn btn-primary px-4" type="submit">Buscar</button>
        </div>
      </form>

      <div class="d-flex gap-2">
        <a href="admin_painel.php" class="btn btn-soft px-3">
          <i class="fa-solid fa-house me-2"></i> Painel
        </a>
      </div>
    </div>

    <div class="mt-3 d-flex flex-wrap gap-2">
      <?php
        $base = "artesclub.php";
        $linkAll = $base . ($q !== '' ? ("?q=" . urlencode($q)) : "");
      ?>
      <a class="chip <?= $categoria===''?'active':'' ?>" href="<?= h($linkAll) ?>">
        <i class="fa-solid fa-layer-group"></i> Todas
      </a>
      <?php foreach($categorias as $cat): 
        $url = $base . "?cat=" . urlencode($cat);
        if ($q !== '') $url .= "&q=" . urlencode($q);
      ?>
        <a class="chip <?= ($categoria===$cat)?'active':'' ?>" href="<?= h($url) ?>">
          <i class="fa-solid fa-tag"></i> <?= h($cat) ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="container py-4">

  <div class="d-flex flex-column flex-md-row align-items-md-end justify-content-between gap-2 mb-3">
    <div>
      <div class="fw-black" style="font-weight:900;font-size:1.35rem;">Conteúdo pronto pra postar</div>
      <div class="text-muted">
        <?= $total ?> resultados<?= ($q!==''? " para <b>“".h($q)."”</b>": "") ?><?= ($categoria!==''? " em <b>".h($categoria)."</b>": "") ?>.
      </div>
    </div>

    <?php if($q!=='' || $categoria!==''): ?>
      <a class="btn btn-soft" href="artesclub.php">
        <i class="fa-solid fa-rotate-left me-2"></i> Limpar filtros
      </a>
    <?php endif; ?>
  </div>

  <?php if(empty($artes)): ?>
    <div class="card border-0 shadow-sm" style="border-radius:18px;">
      <div class="card-body p-4">
        <div class="fw-bold mb-1">Nada encontrado</div>
        <div class="text-muted small">Tente buscar outro termo ou selecione outra categoria.</div>
      </div>
    </div>
  <?php else: ?>
    <div class="row g-4">
      <?php foreach($artes as $a): ?>
        <?php
          // Prepara os dados para o JS
          $jsonArt = htmlspecialchars(json_encode($a), ENT_QUOTES, 'UTF-8');
          
          $tags = [];
          if (!empty($a['tags'])) {
            $tags = array_slice(array_filter(array_map('trim', explode(',', $a['tags']))), 0, 4);
          }
          $thumb = $a['thumb_url'] ?: '';
          $licenca = $a['licenca'] ?: 'Premium';
        ?>
        <div class="col-lg-3 col-md-4 col-sm-6">
          <div class="card card-art h-100">
            <div class="thumb" onclick='abrirModal(<?= $jsonArt ?>)'>
              <img src="<?= h($thumb) ?>" alt="<?= h($a['titulo']) ?>">
              <div class="pill"><?= h($a['categoria'] ?: 'Geral') ?></div>
              <div class="pill2"><?= h($licenca) ?></div>
            </div>

            <div class="p-3 d-flex flex-column" style="gap:10px;">
              <div>
                <div class="fw-black" style="font-weight:900; line-height:1.15; cursor:pointer;" onclick='abrirModal(<?= $jsonArt ?>)'>
                  <?= h($a['titulo']) ?>
                </div>
              </div>

              <?php if(!empty($tags)): ?>
                <div class="d-flex flex-wrap gap-1">
                  <?php foreach($tags as $t): ?>
                    <span class="tag">#<?= h($t) ?></span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

              <div class="d-grid gap-2 mt-auto">
                <button class="btn btn-open text-white py-2" onclick='abrirModal(<?= $jsonArt ?>)'>
                   Ver detalhes
                </button>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if($pages > 1): ?>
      <nav class="mt-4">
        <ul class="pagination justify-content-center">
          <?php
            $mk = function($p) use ($q, $categoria) {
              $params = [];
              if ($q !== '') $params['q'] = $q;
              if ($categoria !== '') $params['cat'] = $categoria;
              $params['p'] = $p;
              return 'artesclub.php?' . http_build_query($params);
            };
          ?>
          <li class="page-item <?= $page<=1?'disabled':'' ?>">
            <a class="page-link" href="<?= h($mk(max(1,$page-1))) ?>">Anterior</a>
          </li>
          <?php
            $start = max(1, $page - 2);
            $end = min($pages, $page + 2);
            for ($i=$start; $i<=$end; $i++):
          ?>
            <li class="page-item <?= $i===$page?'active':'' ?>">
              <a class="page-link" href="<?= h($mk($i)) ?>"><?= $i ?></a>
            </li>
          <?php endfor; ?>
          <li class="page-item <?= $page>=$pages?'disabled':'' ?>">
            <a class="page-link" href="<?= h($mk(min($pages,$page+1))) ?>">Próxima</a>
          </li>
        </ul>
      </nav>
    <?php endif; ?>

  <?php endif; ?>

  <?php if(!empty($relacionadas)): ?>
    <div class="mt-5">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="fw-black" style="font-weight:900;font-size:1.1rem;">Artes relacionadas</div>
        <a class="text-decoration-none fw-bold" href="artesclub.php">Ver todas <i class="fa-solid fa-arrow-right ms-1"></i></a>
      </div>

      <div class="d-flex gap-3 overflow-auto pb-2">
        <?php foreach($relacionadas as $r): ?>
          <div class="mini">
            <img src="<?= h($r['thumb_url']) ?>" alt="">
            <div class="t"><?= h(mb_strimwidth($r['titulo'], 0, 34, '...')) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

</div>

<div class="footer">
  <div class="container d-flex flex-column flex-md-row gap-2 justify-content-between align-items-md-center">
    <div class="small">© <?= date('Y') ?> — Designer Express • Catálogo de modelos</div>
    <div class="small">Gabinete Digital.</div>
  </div>
</div>

<div class="modal fade modal-art" id="artModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-body p-0">
        <button type="button" class="btn-close position-absolute top-0 end-0 m-3 z-3 bg-white p-2 shadow-sm rounded-circle" data-bs-dismiss="modal" aria-label="Close"></button>
        
        <div class="row g-0">
          <div class="col-lg-7 modal-img-col">
            <img src="" id="md-img" alt="Preview">
          </div>
          
          <div class="col-lg-5 p-4 d-flex flex-column h-100" style="max-height: 90vh; overflow-y: auto;">
            
            <div class="text-muted small mb-1" id="md-date">Publicado em: </div>
            <h2 class="fw-black mb-3" style="font-weight:900;" id="md-title">Titulo da Arte</h2>
            
            <div class="d-flex flex-wrap gap-2 mb-3" id="md-tags">
              </div>

            <div class="mb-4">
               <div class="meta-row"><span class="meta-label">Categoria:</span> <span class="meta-value" id="md-cat"></span></div>
               <div class="meta-row"><span class="meta-label">Formato:</span> <span class="meta-value">POST/STORY</span></div>
               <div class="meta-row"><span class="meta-label">Licença:</span> <span class="meta-value" id="md-licenca"></span></div>
            </div>

            <a href="#" target="_blank" id="md-btn-canva" class="btn btn-open text-white w-100 py-3 mb-3 fs-5">
               Abrir no Canva <i class="fa-solid fa-arrow-up-right-from-square ms-2"></i>
            </a>

            <div class="caption-box">
               <span class="caption-title">Legenda sugerida</span>
               <div id="md-caption-text" style="white-space: pre-line; margin-bottom:15px;"></div>
               <button class="btn btn-primary btn-sm fw-bold" onclick="copiarLegendaModal()">
                 <i class="fa-regular fa-copy me-1"></i> Copiar legenda
               </button>
            </div>

          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Objeto Modal do Bootstrap
let artModal;

document.addEventListener('DOMContentLoaded', () => {
    artModal = new bootstrap.Modal(document.getElementById('artModal'));
});

// Dados da arte atual no modal
let currentLegenda = '';

function abrirModal(data) {
    // Preencher Imagem
    document.getElementById('md-img').src = data.thumb_url || '';
    
    // Preencher Textos
    document.getElementById('md-title').innerText = data.titulo || 'Sem título';
    document.getElementById('md-cat').innerText = data.categoria || 'Geral';
    document.getElementById('md-licenca').innerText = data.licenca || 'Premium';
    
    // Data (se tiver created_at, usa, senão usa data atual)
    let dataPub = data.created_at ? new Date(data.created_at).toLocaleDateString('pt-BR') : new Date().toLocaleDateString('pt-BR');
    document.getElementById('md-date').innerText = 'Publicado em: ' + dataPub;

    // Botão Canva
    const btnCanva = document.getElementById('md-btn-canva');
    btnCanva.href = data.canva_url || '#';

    // Tags
    const tagContainer = document.getElementById('md-tags');
    tagContainer.innerHTML = '';
    if(data.tags){
        const tags = data.tags.split(',');
        tags.forEach(t => {
            const span = document.createElement('span');
            span.className = 'tag';
            span.innerText = '#' + t.trim();
            tagContainer.appendChild(span);
        });
    }

    // Legenda
    const captionText = data.legenda_sugerida || 'Nenhuma legenda disponível.';
    document.getElementById('md-caption-text').innerText = captionText;
    currentLegenda = captionText; // Guarda para o botão de copiar

    // Abrir
    artModal.show();
}

async function copiarLegendaModal(){
  const txt = currentLegenda;
  if(!txt) return;
  
  try{
    await navigator.clipboard.writeText(txt);
    toast('Legenda copiada!');
  }catch(e){
    const ta = document.createElement('textarea');
    ta.value = txt;
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    ta.remove();
    toast('Legenda copiada!');
  }
}

function toast(msg){
  const el = document.createElement('div');
  el.style.position='fixed';
  el.style.left='50%';
  el.style.bottom='22px';
  el.style.transform='translateX(-50%)';
  el.style.background='rgba(17,24,39,.92)';
  el.style.color='#fff';
  el.style.padding='10px 14px';
  el.style.borderRadius='12px';
  el.style.fontWeight='800';
  el.style.zIndex='9999';
  el.style.boxShadow='0 14px 40px rgba(0,0,0,.25)';
  el.innerText = msg;
  document.body.appendChild(el);
  setTimeout(()=>{ el.remove(); }, 1600);
}
</script>
</body>
</html>