<?php
/**
 * admin_lp_editor.php  (EDITOR TIPO "MINI-ELEMENTOR" - CRIAR DO ZERO OU EDITAR EXISTENTE)
 *
 * ✅ Se abrir SEM ?id=..., ele cria uma landing nova e abre no editor.
 * ✅ Se abrir COM ?id=..., carrega e edita.
 * ✅ Monta landing com blocos (Hero, Texto, Imagem, CTA, FAQ).
 * ✅ Upload de imagem via AJAX (action=upload).
 *
 * IMPORTANTE: este arquivo salva no MESMO formato do seu fluxo antigo:
 * - landing_pages.html_content
 * - landing_pages.css_content
 * e também salva landing_pages.dados_json (layout).
 *
 * Se seu banco não tiver html_content/css_content, ele cai automaticamente para html/css (compat).
 */

require __DIR__ . '/config.php';
require __DIR__ . '/protecao.php';

if (!defined('TENANT_ID')) {
  http_response_code(403);
  die("Acesso negado.");
}

function json_response(array $data, int $code = 200): void {
  if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
  }
  if (ob_get_length()) ob_clean();
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function slugify(string $text): string {
  $text = trim($text);
  $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text);
  $text = preg_replace('~[^\\pL\\d]+~u', '-', $text);
  $text = trim($text, '-');
  $text = strtolower($text);
  $text = preg_replace('~[^-a-z0-9]+~', '', $text);
  return $text ?: 'landing';
}

function ensure_unique_slug(PDO $pdo, string $slug, int $tenantId, ?int $excludeId = null): string {
  $base = $slug ?: 'landing';
  $try = $base;
  $i = 2;
  while (true) {
    if ($excludeId) {
      $st = $pdo->prepare("SELECT COUNT(*) FROM landing_pages WHERE tenant_id=? AND slug=? AND id<>?");
      $st->execute([$tenantId, $try, $excludeId]);
    } else {
      $st = $pdo->prepare("SELECT COUNT(*) FROM landing_pages WHERE tenant_id=? AND slug=?");
      $st->execute([$tenantId, $try]);
    }
    if ((int)$st->fetchColumn() === 0) return $try;
    $try = $base . '-' . $i;
    $i++;
  }
}

function default_layout(): array {
  return [
    'version' => 1,
    'theme' => [
      'primary' => '#7c3aed',
      'bg' => '#ffffff',
      'text' => '#111827',
      'muted' => '#6b7280'
    ],
    'sections' => []
  ];
}

function esc($s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Detecta colunas reais da tabela landing_pages (compatibilidade)
 */
function landing_columns(PDO $pdo): array {
  static $cols = null;
  if ($cols !== null) return $cols;

  $cols = [];
  $st = $pdo->query("SHOW COLUMNS FROM landing_pages");
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $cols[$r['Field']] = true;
  }
  return $cols;
}

function col_exists(PDO $pdo, string $col): bool {
  $cols = landing_columns($pdo);
  return isset($cols[$col]);
}

/**
 * Renderiza o layout JSON em HTML final
 */
function render_layout_to_html(array $layout, array $meta = []): string {
  $theme = $layout['theme'] ?? [];
  $primary = $theme['primary'] ?? '#7c3aed';
  $bg      = $theme['bg'] ?? '#ffffff';
  $text    = $theme['text'] ?? '#111827';
  $muted   = $theme['muted'] ?? '#6b7280';

  $title = $meta['titulo'] ?? 'Landing Page';

  $sectionsHtml = '';
  foreach (($layout['sections'] ?? []) as $sec) {
    $type = $sec['type'] ?? 'text';
    $p = $sec['props'] ?? [];

    if ($type === 'hero') {
      $headline = esc($p['headline'] ?? 'Seu título aqui');
      $sub = esc($p['subheadline'] ?? 'Uma frase curta explicando a proposta.');
      $btnText = esc($p['buttonText'] ?? 'Falar no WhatsApp');
      $btnUrl = esc($p['buttonUrl'] ?? '#');
      $img = esc($p['imageUrl'] ?? '');
      $imgHtml = $img ? "<img class=\"hero-img\" src=\"$img\" alt=\"\">" : "";

      $sectionsHtml .= <<<HTML
<section class="sec hero">
  <div class="wrap hero-grid">
    <div class="hero-copy">
      <h1>$headline</h1>
      <p class="muted">$sub</p>
      <div class="hero-actions">
        <a class="btn" href="$btnUrl" target="_blank" rel="noopener">$btnText</a>
      </div>
    </div>
    <div class="hero-media">$imgHtml</div>
  </div>
</section>
HTML;
    }
    elseif ($type === 'text') {
      $title2 = esc($p['title'] ?? 'Título da seção');
      $body = esc($p['body'] ?? "Escreva seu texto aqui...");
      $sectionsHtml .= <<<HTML
<section class="sec">
  <div class="wrap">
    <h2>$title2</h2>
    <p class="muted" style="white-space:pre-wrap">$body</p>
  </div>
</section>
HTML;
    }
    elseif ($type === 'image') {
      $caption = esc($p['caption'] ?? '');
      $url = esc($p['url'] ?? '');
      $img = $url ? "<img class=\"img\" src=\"$url\" alt=\"\">" : "<div class=\"placeholder\">Sem imagem</div>";
      $cap = $caption ? "<div class=\"muted\" style=\"margin-top:10px\">$caption</div>" : "";
      $sectionsHtml .= <<<HTML
<section class="sec">
  <div class="wrap center">
    $img
    $cap
  </div>
</section>
HTML;
    }
    elseif ($type === 'cta') {
      $title2 = esc($p['title'] ?? 'Chamada para ação');
      $desc = esc($p['desc'] ?? 'Explique rapidamente o que a pessoa ganha ao clicar.');
      $btnText = esc($p['buttonText'] ?? 'Quero agora');
      $btnUrl = esc($p['buttonUrl'] ?? '#');
      $sectionsHtml .= <<<HTML
<section class="sec cta">
  <div class="wrap cta-box">
    <div>
      <h2>$title2</h2>
      <p class="muted">$desc</p>
    </div>
    <div class="cta-actions">
      <a class="btn" href="$btnUrl" target="_blank" rel="noopener">$btnText</a>
    </div>
  </div>
</section>
HTML;
    }
    elseif ($type === 'faq') {
      $title2 = esc($p['title'] ?? 'Perguntas frequentes');
      $items = $p['items'] ?? [
        ['q'=>'Pergunta 1', 'a'=>'Resposta 1'],
        ['q'=>'Pergunta 2', 'a'=>'Resposta 2'],
      ];
      $faq = '';
      foreach ($items as $it) {
        $q = esc($it['q'] ?? '');
        $a = esc($it['a'] ?? '');
        $faq .= "<details class=\"faq\"><summary>$q</summary><div class=\"muted\" style=\"white-space:pre-wrap\">$a</div></details>";
      }
      $sectionsHtml .= <<<HTML
<section class="sec">
  <div class="wrap">
    <h2>$title2</h2>
    <div class="faq-list">$faq</div>
  </div>
</section>
HTML;
    }
  }

  $year = date('Y');

  return <<<HTML
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>{$title}</title>
  <style>
    :root{
      --primary: {$primary};
      --bg: {$bg};
      --text: {$text};
      --muted: {$muted};
      --border: #e5e7eb;
      --card: #ffffff;
      --shadow: 0 10px 30px rgba(0,0,0,.08);
      --radius: 18px;
      --wrap: 1080px;
    }
    *{box-sizing:border-box}
    body{margin:0;font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial;background:var(--bg);color:var(--text)}
    a{color:inherit}
    .wrap{max-width:var(--wrap);margin:0 auto;padding:0 18px}
    .sec{padding:64px 0}
    .sec.hero{padding:72px 0;background:linear-gradient(180deg, rgba(124,58,237,.10), rgba(124,58,237,0) 60%)}
    h1{font-size:42px;line-height:1.05;margin:0 0 12px}
    h2{font-size:28px;margin:0 0 10px}
    p{margin:0}
    .muted{color:var(--muted)}
    .btn{
      display:inline-flex;align-items:center;justify-content:center;
      background:var(--primary);color:white;text-decoration:none;
      padding:12px 18px;border-radius:999px;font-weight:700;
      box-shadow:0 12px 28px rgba(124,58,237,.25);
    }
    .hero-grid{display:grid;grid-template-columns:1.2fr .8fr;gap:28px;align-items:center}
    .hero-img{width:100%;max-height:420px;object-fit:cover;border-radius:var(--radius);box-shadow:var(--shadow);border:1px solid var(--border)}
    .center{text-align:center}
    .img{width:min(880px,100%);border-radius:var(--radius);border:1px solid var(--border);box-shadow:var(--shadow)}
    .placeholder{width:min(880px,100%);height:340px;border-radius:var(--radius);border:1px dashed var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted)}
    .cta-box{
      background:var(--card);border:1px solid var(--border);border-radius:var(--radius);
      padding:22px;display:flex;gap:18px;align-items:center;justify-content:space-between;
      box-shadow:var(--shadow)
    }
    .faq-list{display:grid;gap:10px;margin-top:14px}
    .faq{
      background:var(--card);border:1px solid var(--border);border-radius:14px;padding:12px 14px;
      box-shadow: 0 10px 20px rgba(0,0,0,.04);
    }
    summary{cursor:pointer;font-weight:800}
    footer{padding:28px 0;border-top:1px solid var(--border);color:var(--muted)}
    @media (max-width: 920px){
      .hero-grid{grid-template-columns:1fr}
      h1{font-size:34px}
      .sec{padding:48px 0}
      .cta-box{flex-direction:column;align-items:flex-start}
    }
  </style>
</head>
<body>
  {$sectionsHtml}
  <footer>
    <div class="wrap">© {$year}</div>
  </footer>
</body>
</html>
HTML;
}

/**
 * Insere landing (compat com colunas do seu sistema)
 */
function insert_landing(PDO $pdo, int $tenantId, string $titulo, string $slug, string $html, string $css, string $dadosJson): int {
  $cols = landing_columns($pdo);

  // Preferência: html_content/css_content
  if (isset($cols['html_content'])) {
    $sql = "INSERT INTO landing_pages (tenant_id, titulo, slug, html_content, css_content, dados_json) VALUES (?,?,?,?,?,?)";
    $st = $pdo->prepare($sql);
    $st->execute([$tenantId, $titulo, $slug, $html, $css, $dadosJson]);
    return (int)$pdo->lastInsertId();
  }

  // Fallback: html/css
  if (isset($cols['html'])) {
    $sql = "INSERT INTO landing_pages (tenant_id, titulo, slug, html, dados_json) VALUES (?,?,?,?,?)";
    $st = $pdo->prepare($sql);
    $st->execute([$tenantId, $titulo, $slug, $html, $dadosJson]);
    return (int)$pdo->lastInsertId();
  }

  // Último fallback: html_content sem dados_json (não recomendado)
  $sql = "INSERT INTO landing_pages (tenant_id, titulo, slug, dados_json) VALUES (?,?,?,?)";
  $st = $pdo->prepare($sql);
  $st->execute([$tenantId, $titulo, $slug, $dadosJson]);
  return (int)$pdo->lastInsertId();
}

/**
 * Atualiza landing (compat)
 */
function update_landing(PDO $pdo, int $tenantId, int $id, string $titulo, string $slug, string $html, string $css, string $dadosJson): void {
  $cols = landing_columns($pdo);

  if (isset($cols['html_content'])) {
    $sql = "UPDATE landing_pages SET titulo=?, slug=?, html_content=?, css_content=?, dados_json=? WHERE id=? AND tenant_id=?";
    $st = $pdo->prepare($sql);
    $st->execute([$titulo, $slug, $html, $css, $dadosJson, $id, $tenantId]);
    return;
  }

  if (isset($cols['html'])) {
    $sql = "UPDATE landing_pages SET titulo=?, slug=?, html=?, dados_json=? WHERE id=? AND tenant_id=?";
    $st = $pdo->prepare($sql);
    $st->execute([$titulo, $slug, $html, $dadosJson, $id, $tenantId]);
    return;
  }

  $sql = "UPDATE landing_pages SET titulo=?, slug=?, dados_json=? WHERE id=? AND tenant_id=?";
  $st = $pdo->prepare($sql);
  $st->execute([$titulo, $slug, $dadosJson, $id, $tenantId]);
}

/**
 * Carrega landing (compat)
 */
function load_landing(PDO $pdo, int $tenantId, int $id): array {
  $cols = landing_columns($pdo);

  if (isset($cols['html_content'])) {
    $st = $pdo->prepare("SELECT id,titulo,slug,html_content AS html,dados_json FROM landing_pages WHERE id=? AND tenant_id=?");
    $st->execute([$id, $tenantId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: [];
  }

  $st = $pdo->prepare("SELECT id,titulo,slug,html,dados_json FROM landing_pages WHERE id=? AND tenant_id=?");
  $st->execute([$id, $tenantId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: [];
}


// ==========================
// API (AJAX) - POST action
// ==========================
try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'upload') {
      if (!isset($_FILES['arquivo_upload'])) json_response(['ok'=>false,'msg'=>'Arquivo não enviado'], 400);

      $f = $_FILES['arquivo_upload'];
      if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) json_response(['ok'=>false,'msg'=>'Falha no upload (erro PHP).'], 400);

      $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
      $permit = ['jpg','jpeg','png','gif','webp'];
      if (!in_array($ext, $permit, true)) json_response(['ok'=>false,'msg'=>'Apenas JPG, PNG, GIF ou WEBP.'], 400);

      $info = @getimagesize($f['tmp_name']);
      if ($info === false) json_response(['ok'=>false,'msg'=>'Arquivo inválido (não é imagem).'], 400);

      $dirFs = __DIR__ . '/uploads/lp_imagens/';
      $dirUrl = 'uploads/lp_imagens/';
      if (!is_dir($dirFs) && !mkdir($dirFs, 0777, true)) json_response(['ok'=>false,'msg'=>'Não criou pasta uploads.'], 500);

      $name = uniqid('img_', true) . '.' . $ext;
      $destFs = $dirFs . $name;
      $destUrl = $dirUrl . $name;

      if (!move_uploaded_file($f['tmp_name'], $destFs)) json_response(['ok'=>false,'msg'=>'Erro ao salvar arquivo.'], 500);
      json_response(['ok'=>true,'url'=>$destUrl]);
    }

    if ($action === 'create') {
      $titulo = trim($_POST['titulo'] ?? 'Nova Landing');
      $slug = ensure_unique_slug($pdo, slugify($titulo), TENANT_ID, null);

      $layout = default_layout();
      $layoutJson = json_encode($layout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

      $html = render_layout_to_html($layout, ['titulo'=>$titulo]);
      $css = ''; // se quiser separar CSS, pode colocar aqui

      $newId = insert_landing($pdo, TENANT_ID, $titulo, $slug, $html, $css, $layoutJson);

      json_response(['ok'=>true,'id'=>$newId,'slug'=>$slug,'titulo'=>$titulo,'layout'=>$layout]);
    }

    if ($action === 'load') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) json_response(['ok'=>false,'msg'=>'ID inválido.'], 400);

      $row = load_landing($pdo, TENANT_ID, $id);
      if (!$row) json_response(['ok'=>false,'msg'=>'Página não encontrada.'], 404);

      $layout = json_decode($row['dados_json'] ?: '{}', true);
      if (!is_array($layout) || empty($layout['version'])) $layout = default_layout();

      json_response(['ok'=>true,'page'=>$row,'layout'=>$layout]);
    }

    if ($action === 'save') {
      if (empty($_POST)) json_response(['ok'=>false,'msg'=>'POST vazio. Possível post_max_size no servidor.'], 400);

      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) json_response(['ok'=>false,'msg'=>'ID inválido.'], 400);

      $titulo = trim($_POST['titulo'] ?? '');
      $slug = trim($_POST['slug'] ?? '');
      $layoutJson = (string)($_POST['layout_json'] ?? '');

      if ($titulo === '') json_response(['ok'=>false,'msg'=>'Título obrigatório.'], 400);
      if ($slug === '') $slug = slugify($titulo);
      $slug = ensure_unique_slug($pdo, slugify($slug), TENANT_ID, $id);

      $layout = json_decode($layoutJson, true);
      if (!is_array($layout) || empty($layout['version'])) json_response(['ok'=>false,'msg'=>'layout_json inválido.'], 400);

      $html = render_layout_to_html($layout, ['titulo'=>$titulo]);
      $css = '';

      update_landing($pdo, TENANT_ID, $id, $titulo, $slug, $html, $css, json_encode($layout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

      json_response(['ok'=>true,'slug'=>$slug]);
    }

    json_response(['ok'=>false,'msg'=>'Ação inválida.'], 400);
  }
} catch (Throwable $e) {
  error_log("LP Editor erro: " . $e->getMessage());
  json_response(['ok'=>false,'msg'=>'Erro interno. Verifique os logs.'], 500);
}


// ==========================
// GET: abrir editor
// ==========================
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Editor de Landing</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    :root{--border:#e7e7e7;--bg:#f6f7fb;--card:#fff;--text:#111827;--muted:#6b7280;--shadow:0 10px 30px rgba(0,0,0,.06);--radius:18px;}
    body{height:100vh;overflow:hidden;background:var(--bg);color:var(--text)}
    .topbar{height:64px;background:#fff;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 16px;}
    .workspace{height:calc(100vh - 64px);display:grid;grid-template-columns:320px 1fr 360px;gap:12px;padding:12px}
    .panel{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;display:flex;flex-direction:column}
    .panel-h{padding:12px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
    .panel-b{padding:12px 14px;overflow:auto}
    .pill{border:1px solid var(--border);border-radius:999px;padding:6px 10px;font-size:12px;color:var(--muted)}
    .btn-soft{background:#f3f4f6;border:1px solid var(--border)}
    .canvas{background:#fff;border-radius:var(--radius);border:1px solid var(--border);box-shadow:var(--shadow);overflow:hidden;display:flex;flex-direction:column}
    .canvas-toolbar{padding:10px 12px;border-bottom:1px solid var(--border);background:#fff;display:flex;gap:8px;align-items:center;justify-content:space-between}
    .iframe-wrap{flex:1;background:#f3f4f6;display:flex;align-items:center;justify-content:center;padding:12px}
    iframe{background:#fff;border:1px solid #eee;border-radius:14px;width:100%;height:100%}
    iframe.mobile{width:390px;height:800px;border-radius:26px;border:10px solid #111;box-shadow:0 14px 44px rgba(0,0,0,.18)}
    .block{border:1px solid var(--border);border-radius:14px;padding:10px 12px;background:#fff;display:flex;gap:10px;align-items:center;cursor:grab}
    .block + .block{margin-top:10px}
    .block i{width:26px;height:26px;border-radius:10px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;color:#444}
    .muted{color:var(--muted);font-size:12px}
    .sec-item{border:1px solid var(--border);border-radius:14px;padding:10px 12px;background:#fff;display:flex;align-items:center;justify-content:space-between;gap:10px}
    .sec-item + .sec-item{margin-top:10px}
    .sec-item.active{outline:2px solid rgba(124,58,237,.35);border-color:rgba(124,58,237,.25)}
    .sec-left{display:flex;gap:10px;align-items:center}
    .sec-handle{cursor:grab}
    .tiny{font-size:11px;color:var(--muted)}
    .prop-label{font-size:12px;font-weight:800;color:#525252;margin-bottom:6px}
    .unsaved{display:none}
    .unsaved.show{display:inline-flex}
  </style>
</head>
<body>

<div class="topbar">
  <div class="d-flex align-items-center gap-2">
    <a href="admin_paginas.php" class="btn btn-light btn-sm border rounded-pill">
      <i class="fa-solid fa-arrow-left me-1"></i> Voltar
    </a>
    <div>
      <div class="d-flex align-items-center gap-2">
        <input class="form-control form-control-sm fw-bold" id="pageTitle" style="width:260px" placeholder="Título da landing">
        <span class="badge text-bg-warning rounded-pill unsaved" id="unsavedBadge">
          <i class="fa-solid fa-circle-exclamation me-1"></i> Não salvo
        </span>
      </div>
      <input class="form-control form-control-sm mt-1" id="pageSlug" style="width:260px" placeholder="slug-ex: minha-landing">
    </div>
  </div>

  <div class="d-flex align-items-center gap-2">
    <div class="btn-group btn-group-sm">
      <button class="btn btn-outline-dark active" id="btnDesktop"><i class="fa-solid fa-desktop"></i></button>
      <button class="btn btn-outline-dark" id="btnMobile"><i class="fa-solid fa-mobile-screen"></i></button>
    </div>
    <button class="btn btn-soft btn-sm rounded-pill" id="btnPreview">
      <i class="fa-solid fa-rotate me-1"></i> Atualizar
    </button>
    <button class="btn btn-success btn-sm fw-bold rounded-pill px-3" id="btnSave">
      <i class="fa-solid fa-floppy-disk me-1"></i> Salvar
    </button>
  </div>
</div>

<div class="workspace">

  <div class="panel">
    <div class="panel-h"><div class="fw-bold">Blocos</div><span class="pill">Arraste</span></div>
    <div class="panel-b" id="palette">
      <div class="block" draggable="true" data-type="hero"><i class="fa-solid fa-star"></i><div><div class="fw-bold">Hero</div><div class="muted">Título + botão + imagem</div></div></div>
      <div class="block" draggable="true" data-type="text"><i class="fa-solid fa-align-left"></i><div><div class="fw-bold">Texto</div><div class="muted">Título + parágrafo</div></div></div>
      <div class="block" draggable="true" data-type="image"><i class="fa-solid fa-image"></i><div><div class="fw-bold">Imagem</div><div class="muted">Imagem + legenda</div></div></div>
      <div class="block" draggable="true" data-type="cta"><i class="fa-solid fa-bolt"></i><div><div class="fw-bold">CTA</div><div class="muted">Chamada + botão</div></div></div>
      <div class="block" draggable="true" data-type="faq"><i class="fa-solid fa-circle-question"></i><div><div class="fw-bold">FAQ</div><div class="muted">Perguntas e respostas</div></div></div>

      <hr>
      <div class="fw-bold mb-2">Tema</div>

      <div class="mb-2">
        <div class="prop-label">Cor primária</div>
        <div class="input-group input-group-sm">
          <input type="color" class="form-control form-control-color" id="themePrimary" value="#7c3aed">
          <input class="form-control" id="themePrimaryText" value="#7c3aed">
        </div>
      </div>

      <div class="mb-2">
        <div class="prop-label">Fundo</div>
        <div class="input-group input-group-sm">
          <input type="color" class="form-control form-control-color" id="themeBg" value="#ffffff">
          <input class="form-control" id="themeBgText" value="#ffffff">
        </div>
      </div>

      <div class="mb-2">
        <div class="prop-label">Texto</div>
        <div class="input-group input-group-sm">
          <input type="color" class="form-control form-control-color" id="themeText" value="#111827">
          <input class="form-control" id="themeTextText" value="#111827">
        </div>
      </div>

      <div class="mb-2">
        <div class="prop-label">Texto secundário</div>
        <div class="input-group input-group-sm">
          <input type="color" class="form-control form-control-color" id="themeMuted" value="#6b7280">
          <input class="form-control" id="themeMutedText" value="#6b7280">
        </div>
      </div>

    </div>
  </div>

  <div class="canvas">
    <div class="canvas-toolbar">
      <div class="d-flex align-items-center gap-2">
        <span class="pill" id="statusText">Pronto</span>
        <span class="tiny" id="pageInfo"></span>
      </div>
      <div class="tiny text-muted">Arraste blocos • Reordene na lista • Salvar atualiza html_content</div>
    </div>
    <div class="iframe-wrap">
      <iframe id="previewFrame"></iframe>
    </div>
  </div>

  <div class="panel">
    <div class="panel-h">
      <div class="fw-bold">Seções</div>
      <button class="btn btn-sm btn-soft rounded-pill" id="btnClear"><i class="fa-solid fa-trash me-1"></i> Limpar</button>
    </div>
    <div class="panel-b">
      <div id="sectionsList" class="mb-3"></div>
      <hr>
      <div class="fw-bold mb-2">Propriedades</div>
      <div id="propsPanel" class="tiny text-muted">Selecione uma seção para editar.</div>
    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const previewFrame = document.getElementById('previewFrame');
  const sectionsList = document.getElementById('sectionsList');
  const propsPanel = document.getElementById('propsPanel');
  const statusText = document.getElementById('statusText');
  const unsavedBadge = document.getElementById('unsavedBadge');

  let pageId = <?= (int)$id ?>; // 0 => criar do zero
  let layout = null;
  let selectedIndex = -1;
  let dirty = false;
  let previewTimer = null;
  let dragSectionIndex = null;

  const DEFAULTS = {
    hero: () => ({ type:'hero', props:{ headline:'Título forte que chama atenção', subheadline:'Explique rapidamente o benefício principal em 1 frase.', buttonText:'Falar no WhatsApp', buttonUrl:'#', imageUrl:'' } }),
    text: () => ({ type:'text', props:{ title:'Título da seção', body:'Escreva seu texto aqui...' } }),
    image: () => ({ type:'image', props:{ url:'', caption:'' } }),
    cta: () => ({ type:'cta', props:{ title:'Pronto para dar o próximo passo?', desc:'Um texto curto para convencer a pessoa a clicar.', buttonText:'Quero agora', buttonUrl:'#' } }),
    faq: () => ({ type:'faq', props:{ title:'Perguntas frequentes', items:[{q:'Pergunta 1',a:'Resposta 1'},{q:'Pergunta 2',a:'Resposta 2'}] } })
  };

  function setDirty(v){ dirty = v; unsavedBadge.classList.toggle('show', dirty); }

  async function api(action, fd){
    fd = fd || new FormData();
    fd.append('action', action);
    const res = await fetch('admin_lp_editor.php', { method:'POST', body: fd });
    const text = await res.text();
    let data=null; try{ data=JSON.parse(text); }catch{ data=null; }
    if(!res.ok || !data || data.ok!==true){
      const msg = (data && data.msg) ? data.msg : text.slice(0,200);
      throw new Error(msg || 'Erro');
    }
    return data;
  }

  function makeDefaultLayout(){
    return { version:1, theme:{primary:'#7c3aed',bg:'#ffffff',text:'#111827',muted:'#6b7280'}, sections:[] };
  }

  function escapeHtml(str){
    return (str||'').replace(/[&<>"']/g, (m)=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m]));
  }

  function renderLocal(layout, title){
    const t = layout.theme || {};
    const primary = t.primary || '#7c3aed';
    const bg = t.bg || '#fff';
    const text = t.text || '#111827';
    const muted = t.muted || '#6b7280';

    const secs = layout.sections || [];
    let secHtml = '';

    for (const sec of secs){
      const p = sec.props || {};
      if (sec.type==='hero'){
        const img = p.imageUrl ? `<img class="hero-img" src="${escapeHtml(p.imageUrl)}" alt="">` : '';
        secHtml += `
<section class="sec hero">
  <div class="wrap hero-grid">
    <div>
      <h1>${escapeHtml(p.headline||'')}</h1>
      <p class="muted">${escapeHtml(p.subheadline||'')}</p>
      <div style="margin-top:14px">
        <a class="btn" href="${escapeHtml(p.buttonUrl||'#')}" target="_blank" rel="noopener">${escapeHtml(p.buttonText||'')}</a>
      </div>
    </div>
    <div>${img}</div>
  </div>
</section>`;
      }
      if (sec.type==='text'){
        secHtml += `
<section class="sec">
  <div class="wrap">
    <h2>${escapeHtml(p.title||'')}</h2>
    <p class="muted" style="white-space:pre-wrap">${escapeHtml(p.body||'')}</p>
  </div>
</section>`;
      }
      if (sec.type==='image'){
        const img = p.url ? `<img class="img" src="${escapeHtml(p.url)}" alt="">` : `<div class="placeholder">Sem imagem</div>`;
        const cap = p.caption ? `<div class="muted" style="margin-top:10px">${escapeHtml(p.caption)}</div>` : '';
        secHtml += `
<section class="sec">
  <div class="wrap center">
    ${img}
    ${cap}
  </div>
</section>`;
      }
      if (sec.type==='cta'){
        secHtml += `
<section class="sec cta">
  <div class="wrap cta-box">
    <div>
      <h2>${escapeHtml(p.title||'')}</h2>
      <p class="muted">${escapeHtml(p.desc||'')}</p>
    </div>
    <div>
      <a class="btn" href="${escapeHtml(p.buttonUrl||'#')}" target="_blank" rel="noopener">${escapeHtml(p.buttonText||'')}</a>
    </div>
  </div>
</section>`;
      }
      if (sec.type==='faq'){
        const items = p.items || [];
        let faqs = '';
        for (const it of items){
          faqs += `<details class="faq"><summary>${escapeHtml(it.q||'')}</summary><div class="muted" style="white-space:pre-wrap">${escapeHtml(it.a||'')}</div></details>`;
        }
        secHtml += `
<section class="sec">
  <div class="wrap">
    <h2>${escapeHtml(p.title||'Perguntas frequentes')}</h2>
    <div class="faq-list">${faqs}</div>
  </div>
</section>`;
      }
    }

    const year = new Date().getFullYear();
    return `<!doctype html>
<html lang="pt-br"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>${escapeHtml(title)}</title>
<style>
:root{--primary:${primary};--bg:${bg};--text:${text};--muted:${muted};--border:#e5e7eb;--shadow:0 10px 30px rgba(0,0,0,.08);--radius:18px;--wrap:1080px}
*{box-sizing:border-box}
body{margin:0;font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial;background:var(--bg);color:var(--text)}
.wrap{max-width:var(--wrap);margin:0 auto;padding:0 18px}
.sec{padding:64px 0}
.sec.hero{padding:72px 0;background:linear-gradient(180deg, rgba(124,58,237,.10), rgba(124,58,237,0) 60%)}
h1{font-size:42px;line-height:1.05;margin:0 0 12px}
h2{font-size:28px;margin:0 0 10px}
p{margin:0}
.muted{color:var(--muted)}
.btn{display:inline-flex;align-items:center;justify-content:center;background:var(--primary);color:#fff;text-decoration:none;padding:12px 18px;border-radius:999px;font-weight:800;box-shadow:0 12px 28px rgba(124,58,237,.25)}
.hero-grid{display:grid;grid-template-columns:1.2fr .8fr;gap:28px;align-items:center}
.hero-img{width:100%;max-height:420px;object-fit:cover;border-radius:var(--radius);box-shadow:var(--shadow);border:1px solid var(--border)}
.center{text-align:center}
.img{width:min(880px,100%);border-radius:var(--radius);border:1px solid var(--border);box-shadow:var(--shadow)}
.placeholder{width:min(880px,100%);height:340px;border-radius:var(--radius);border:1px dashed var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted)}
.cta-box{background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:22px;display:flex;gap:18px;align-items:center;justify-content:space-between;box-shadow:var(--shadow)}
.faq-list{display:grid;gap:10px;margin-top:14px}
.faq{background:#fff;border:1px solid var(--border);border-radius:14px;padding:12px 14px;box-shadow:0 10px 20px rgba(0,0,0,.04)}
summary{cursor:pointer;font-weight:900}
footer{padding:28px 0;border-top:1px solid var(--border);color:var(--muted)}
@media (max-width:920px){.hero-grid{grid-template-columns:1fr}h1{font-size:34px}.sec{padding:48px 0}.cta-box{flex-direction:column;align-items:flex-start}}
</style></head>
<body>${secHtml}<footer><div class="wrap">© ${year}</div></footer></body></html>`;
  }

  function schedulePreview(){
    if (previewTimer) clearTimeout(previewTimer);
    previewTimer = setTimeout(() => {
      const title = document.getElementById('pageTitle').value || 'Landing';
      previewFrame.srcdoc = renderLocal(layout, title);
    }, 120);
  }

  function labelForSection(sec){
    return ({hero:'Hero',text:'Texto',image:'Imagem',cta:'CTA',faq:'FAQ'}[sec.type] || sec.type);
  }

  function refreshUI(){
    renderSectionsList();
    renderProps();
    schedulePreview();
  }

  // Palette drag
  document.querySelectorAll('#palette .block').forEach(el => {
    el.addEventListener('dragstart', (e) => e.dataTransfer.setData('text/plain', el.dataset.type));
  });

  sectionsList.addEventListener('dragover', (e)=>e.preventDefault());
  sectionsList.addEventListener('drop', (e)=>{
    e.preventDefault();
    const type = e.dataTransfer.getData('text/plain');
    if (!type || !DEFAULTS[type]) return;
    layout.sections.push(DEFAULTS[type]());
    selectedIndex = layout.sections.length - 1;
    setDirty(true);
    refreshUI();
  });

  function renderSectionsList(){
    sectionsList.innerHTML = '';
    const secs = layout.sections || [];
    if (!secs.length){
      sectionsList.innerHTML = `<div class="tiny text-muted">Sem seções. Arraste um bloco da esquerda para começar.</div>`;
      return;
    }
    secs.forEach((sec, idx) => {
      const div = document.createElement('div');
      div.className = 'sec-item' + (idx === selectedIndex ? ' active' : '');
      div.draggable = true;
      div.dataset.idx = idx;

      div.innerHTML = `
        <div class="sec-left">
          <i class="fa-solid fa-grip-vertical sec-handle text-muted"></i>
          <div>
            <div class="fw-bold" style="font-size:13px">${labelForSection(sec)}</div>
            <div class="tiny">${sec.type}</div>
          </div>
        </div>
        <div class="d-flex gap-1">
          <button class="btn btn-sm btn-soft rounded-pill" data-act="up"><i class="fa-solid fa-arrow-up"></i></button>
          <button class="btn btn-sm btn-soft rounded-pill" data-act="down"><i class="fa-solid fa-arrow-down"></i></button>
          <button class="btn btn-sm btn-soft rounded-pill" data-act="del"><i class="fa-solid fa-trash"></i></button>
        </div>
      `;

      div.addEventListener('click', (e)=>{
        if (e.target.closest('button')) return;
        selectedIndex = idx;
        refreshUI();
      });

      div.querySelectorAll('button').forEach(btn=>{
        btn.addEventListener('click', (e)=>{
          e.stopPropagation();
          const act = btn.dataset.act;
          if (act==='del'){ layout.sections.splice(idx,1); if(selectedIndex===idx) selectedIndex=-1; if(selectedIndex>idx) selectedIndex--; setDirty(true); refreshUI(); }
          if (act==='up' && idx>0){ const it=layout.sections.splice(idx,1)[0]; layout.sections.splice(idx-1,0,it); selectedIndex = (selectedIndex===idx? idx-1 : selectedIndex); setDirty(true); refreshUI(); }
          if (act==='down' && idx<layout.sections.length-1){ const it=layout.sections.splice(idx,1)[0]; layout.sections.splice(idx+1,0,it); selectedIndex = (selectedIndex===idx? idx+1 : selectedIndex); setDirty(true); refreshUI(); }
        });
      });

      div.addEventListener('dragstart', (e)=>{ dragSectionIndex = idx; e.dataTransfer.setData('text/plain','__MOVE__'); });
      div.addEventListener('dragover', (e)=>e.preventDefault());
      div.addEventListener('drop', (e)=>{
        e.preventDefault();
        if (e.dataTransfer.getData('text/plain') !== '__MOVE__') return;
        const from = dragSectionIndex;
        const to = idx;
        if (from===null || from===to) return;
        const it = layout.sections.splice(from,1)[0];
        layout.sections.splice(to,0,it);
        if (selectedIndex===from) selectedIndex=to;
        setDirty(true); refreshUI();
      });

      sectionsList.appendChild(div);
    });
  }

  function inputRow(label, id, value, placeholder=''){
    return `
      <div class="mb-2">
        <div class="prop-label">${label}</div>
        <input class="form-control form-control-sm" id="${id}" value="${escapeHtml(value ?? '')}" placeholder="${escapeHtml(placeholder)}">
      </div>`;
  }
  function textareaRow(label, id, value, placeholder=''){
    return `
      <div class="mb-2">
        <div class="prop-label">${label}</div>
        <textarea class="form-control form-control-sm" rows="4" id="${id}" placeholder="${escapeHtml(placeholder)}">${escapeHtml(value ?? '')}</textarea>
      </div>`;
  }
  function bind(id, setter){
    const el = document.getElementById(id);
    if(!el) return;
    el.addEventListener('input', ()=>{
      setter(el.value);
      setDirty(true);
      schedulePreview();
    });
  }

  async function uploadImage(file){
    const fd = new FormData();
    fd.append('arquivo_upload', file);
    const data = await api('upload', fd);
    return data.url;
  }

  function renderProps(){
    const secs = layout.sections || [];
    if (selectedIndex < 0 || !secs[selectedIndex]){
      propsPanel.innerHTML = `<div class="tiny text-muted">Selecione uma seção para editar.</div>`;
      return;
    }
    const sec = secs[selectedIndex];
    const p = sec.props || {};

    if (sec.type==='hero'){
      propsPanel.innerHTML = `
        ${inputRow('Título', 'p_headline', p.headline, '...')}
        ${textareaRow('Subtítulo', 'p_subheadline', p.subheadline, '...')}
        ${inputRow('Texto do botão', 'p_btnText', p.buttonText, 'Falar no WhatsApp')}
        ${inputRow('Link do botão', 'p_btnUrl', p.buttonUrl, 'https://wa.me/...')}
        <div class="mb-2">
          <div class="prop-label">Imagem (URL ou Upload)</div>
          <div class="input-group input-group-sm">
            <input class="form-control" id="p_imgUrl" value="${escapeHtml(p.imageUrl||'')}" placeholder="https://...">
            <label class="btn btn-outline-secondary">
              <i class="fa-solid fa-upload"></i>
              <input type="file" hidden id="p_imgFile" accept="image/*">
            </label>
          </div>
        </div>`;
      bind('p_headline', v=>p.headline=v);
      bind('p_subheadline', v=>p.subheadline=v);
      bind('p_btnText', v=>p.buttonText=v);
      bind('p_btnUrl', v=>p.buttonUrl=v);
      document.getElementById('p_imgUrl').addEventListener('input', ()=>{ p.imageUrl = document.getElementById('p_imgUrl').value.trim(); setDirty(true); schedulePreview(); });
      document.getElementById('p_imgFile').addEventListener('change', async ()=>{
        const f = document.getElementById('p_imgFile').files?.[0];
        if(!f) return;
        const url = await uploadImage(f);
        p.imageUrl = url;
        document.getElementById('p_imgUrl').value = url;
        setDirty(true); schedulePreview();
        document.getElementById('p_imgFile').value='';
      });
      return;
    }

    if (sec.type==='text'){
      propsPanel.innerHTML = `${inputRow('Título','p_title',p.title,'...')}${textareaRow('Texto','p_body',p.body,'...')}`;
      bind('p_title', v=>p.title=v);
      bind('p_body', v=>p.body=v);
      return;
    }

    if (sec.type==='image'){
      propsPanel.innerHTML = `
        <div class="mb-2">
          <div class="prop-label">Imagem (URL ou Upload)</div>
          <div class="input-group input-group-sm">
            <input class="form-control" id="p_imgOnlyUrl" value="${escapeHtml(p.url||'')}" placeholder="https://...">
            <label class="btn btn-outline-secondary">
              <i class="fa-solid fa-upload"></i>
              <input type="file" hidden id="p_imgOnlyFile" accept="image/*">
            </label>
          </div>
        </div>
        ${inputRow('Legenda','p_caption',p.caption,'opcional')}`;
      document.getElementById('p_imgOnlyUrl').addEventListener('input', ()=>{ p.url=document.getElementById('p_imgOnlyUrl').value.trim(); setDirty(true); schedulePreview(); });
      bind('p_caption', v=>p.caption=v);
      document.getElementById('p_imgOnlyFile').addEventListener('change', async ()=>{
        const f = document.getElementById('p_imgOnlyFile').files?.[0];
        if(!f) return;
        const url = await uploadImage(f);
        p.url = url;
        document.getElementById('p_imgOnlyUrl').value = url;
        setDirty(true); schedulePreview();
        document.getElementById('p_imgOnlyFile').value='';
      });
      return;
    }

    if (sec.type==='cta'){
      propsPanel.innerHTML = `
        ${inputRow('Título','p_ctaTitle',p.title,'...')}
        ${textareaRow('Descrição','p_ctaDesc',p.desc,'...')}
        ${inputRow('Texto do botão','p_ctaBtnText',p.buttonText,'Quero agora')}
        ${inputRow('Link do botão','p_ctaBtnUrl',p.buttonUrl,'https://...')}`;
      bind('p_ctaTitle', v=>p.title=v);
      bind('p_ctaDesc', v=>p.desc=v);
      bind('p_ctaBtnText', v=>p.buttonText=v);
      bind('p_ctaBtnUrl', v=>p.buttonUrl=v);
      return;
    }

    if (sec.type==='faq'){
      const items = p.items || [];
      let itemsHtml = '';
      items.forEach((it,i)=>{
        itemsHtml += `
          <div class="border rounded-3 p-2 mb-2">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <div class="fw-bold" style="font-size:12px">Item ${i+1}</div>
              <button class="btn btn-sm btn-soft rounded-pill" data-del="${i}"><i class="fa-solid fa-trash"></i></button>
            </div>
            ${inputRow('Pergunta',`faq_q_${i}`,it.q||'','...')}
            ${textareaRow('Resposta',`faq_a_${i}`,it.a||'','...')}
          </div>`;
      });

      propsPanel.innerHTML = `
        ${inputRow('Título','p_faqTitle',p.title,'Perguntas frequentes')}
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="prop-label mb-0">Itens</div>
          <button class="btn btn-sm btn-soft rounded-pill" id="faqAdd"><i class="fa-solid fa-plus me-1"></i>Adicionar</button>
        </div>
        ${itemsHtml || `<div class="tiny text-muted">Sem itens ainda.</div>`}`;

      bind('p_faqTitle', v=>p.title=v);

      document.getElementById('faqAdd').addEventListener('click', ()=>{
        p.items = p.items || [];
        p.items.push({q:'Nova pergunta', a:'Nova resposta'});
        setDirty(true);
        refreshUI();
      });

      items.forEach((it,i)=>{
        bind(`faq_q_${i}`, v=>it.q=v);
        bind(`faq_a_${i}`, v=>it.a=v);
      });

      propsPanel.querySelectorAll('[data-del]').forEach(btn=>{
        btn.addEventListener('click', ()=>{
          const i = parseInt(btn.getAttribute('data-del'),10);
          p.items.splice(i,1);
          setDirty(true);
          refreshUI();
        });
      });
      return;
    }

    propsPanel.innerHTML = `<div class="tiny text-muted">Sem editor para esse tipo.</div>`;
  }

  document.getElementById('btnClear').addEventListener('click', ()=>{
    if(!confirm('Limpar todas as seções?')) return;
    layout.sections = [];
    selectedIndex = -1;
    setDirty(true);
    refreshUI();
  });

  document.getElementById('btnPreview').addEventListener('click', schedulePreview);

  // Device mode
  document.getElementById('btnDesktop').addEventListener('click', (e)=>{
    e.preventDefault();
    previewFrame.classList.remove('mobile');
    document.getElementById('btnDesktop').classList.add('active');
    document.getElementById('btnMobile').classList.remove('active');
  });
  document.getElementById('btnMobile').addEventListener('click', (e)=>{
    e.preventDefault();
    previewFrame.classList.add('mobile');
    document.getElementById('btnMobile').classList.add('active');
    document.getElementById('btnDesktop').classList.remove('active');
  });

  // Theme binds
  function wireThemePair(idColor, idText, key){
    const a = document.getElementById(idColor);
    const b = document.getElementById(idText);
    a.addEventListener('input', ()=>{ b.value=a.value; layout.theme[key]=a.value; setDirty(true); schedulePreview(); });
    b.addEventListener('input', ()=>{ layout.theme[key]=b.value.trim(); setDirty(true); schedulePreview(); });
  }

  async function init(){
    statusText.textContent = 'Carregando...';

    if (!pageId){
      const fd = new FormData();
      fd.append('titulo', 'Nova Landing');
      const data = await api('create', fd);
      pageId = data.id;
      layout = data.layout;
      document.getElementById('pageTitle').value = data.titulo;
      document.getElementById('pageSlug').value = data.slug;
      document.getElementById('pageInfo').textContent = `ID #${pageId}`;
      setDirty(false);
      statusText.textContent = 'Pronto';
      refreshUI();
      return;
    }

    const fd = new FormData();
    fd.append('id', pageId);
    const data = await api('load', fd);
    layout = data.layout || makeDefaultLayout();
    document.getElementById('pageTitle').value = data.page.titulo || '';
    document.getElementById('pageSlug').value = data.page.slug || '';
    document.getElementById('pageInfo').textContent = `ID #${pageId}`;
    setDirty(false);
    statusText.textContent = 'Pronto';
    refreshUI();
  }

  // Theme initial
  layout = makeDefaultLayout();
  wireThemePair('themePrimary','themePrimaryText','primary');
  wireThemePair('themeBg','themeBgText','bg');
  wireThemePair('themeText','themeTextText','text');
  wireThemePair('themeMuted','themeMutedText','muted');

  document.getElementById('pageTitle').addEventListener('input', ()=>setDirty(true));
  document.getElementById('pageSlug').addEventListener('input', ()=>setDirty(true));

  // Save
  document.getElementById('btnSave').addEventListener('click', async ()=>{
    const title = document.getElementById('pageTitle').value.trim();
    let slug = document.getElementById('pageSlug').value.trim();
    if (!title){ alert('Digite um título'); return; }
    if (!slug) slug = title.toLowerCase().replace(/\s+/g,'-');

    const btn = document.getElementById('btnSave');
    const old = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin me-1"></i> Salvando...`;
    statusText.textContent = 'Salvando...';

    try{
      const fd = new FormData();
      fd.append('id', pageId);
      fd.append('titulo', title);
      fd.append('slug', slug);
      fd.append('layout_json', JSON.stringify(layout));
      const data = await api('save', fd);
      document.getElementById('pageSlug').value = data.slug || slug;
      setDirty(false);
      statusText.textContent = 'Pronto';
    }catch(e){
      alert('Erro ao salvar: ' + (e.message || ''));
      statusText.textContent = 'Erro';
    }finally{
      btn.disabled = false;
      btn.innerHTML = old;
    }
  });

  window.addEventListener('beforeunload', (e)=>{
    if(!dirty) return;
    e.preventDefault();
    e.returnValue = '';
  });

  init();
</script>
</body>
</html>
