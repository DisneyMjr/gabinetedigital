<?php
session_start();
require 'config.php';
require 'protecao.php';

if (empty($_SESSION['tenant_id'])) { http_response_code(403); exit('Tenant inválido'); }

$stmt = $pdo->prepare("SELECT * FROM configuracoes WHERE tenant_id = ?");
$stmt->execute([$_SESSION['tenant_id']]);
$conf = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$foto_v = (!empty($conf['foto_hero']) && file_exists($conf['foto_hero'])) ? $conf['foto_hero'] : 'assets/img/default-avatar.png';
$nome_v = $conf['nome_vereador'] ?? 'Seu Nome';
$cargo_v = $conf['hero_cargo'] ?? 'Vereador';

$fundos = [
  'assets/fundos/cidade.jpg',
  'assets/fundos/bandeira.jpg',
  'assets/fundos/abstract1.jpg',
  'assets/fundos/abstract2.jpg',
];
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Admin Designer</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <script src="https://cdn.jsdelivr.net/npm/fabric@5.3.0/dist/fabric.min.js"></script>

  <style>
    :root { --side: 390px; }
    body { height:100vh; overflow:hidden; background:#eef1f5; }
    .wrap{display:flex;height:100vh;}
    .side{
      width:var(--side); background:#fff; border-right:1px solid #e5e7eb;
      overflow:auto; padding:16px;
    }
    .main{
      flex:1; display:flex; align-items:center; justify-content:center;
      background: linear-gradient(180deg,#dde3ea,#cfd8e3);
      position:relative;
    }
    .stage{
      background:#fff; border-radius:18px;
      box-shadow:0 20px 60px rgba(0,0,0,.18);
      padding:12px;
      position:relative;
    }
    canvas{ border-radius:12px; }

    .form-label{ font-size:.78rem; letter-spacing:.04em; font-weight:800; color:#334155; }
    .small-muted{ font-size:.82rem; color:#64748b; }
    .thumb{ height:60px; border-radius:10px; background-size:cover; background-position:center;
      border:2px solid transparent; cursor:pointer; }
    .thumb.active{ border-color:#0d6efd; box-shadow:0 0 0 4px rgba(13,110,253,.15); }

    .layer-item{
      border:1px solid #e5e7eb; border-radius:10px; padding:10px;
      display:flex; align-items:center; justify-content:space-between;
      cursor:pointer; gap:10px;
    }
    .layer-item:hover{ border-color:#0d6efd; }
    .layer-item.active{ border-color:#0d6efd; background:#eff6ff; }

    .kbd{
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace;
      font-size:.75rem; background:#f1f5f9; border:1px solid #e2e8f0; padding:2px 6px; border-radius:6px;
    }
    .hr{ height:1px; background:#e5e7eb; margin:12px 0; }

    /* Toolbar flutuante */
    .floatbar{
      position:absolute; z-index:50;
      background:#0b1220; color:#fff;
      border-radius:10px; padding:6px;
      display:none; gap:6px; align-items:center;
      box-shadow:0 8px 24px rgba(0,0,0,.25);
      transform: translate(-50%, -120%);
      user-select:none;
    }
    .floatbar button{
      border:0; background:transparent; color:#fff;
      width:34px; height:34px; border-radius:8px;
    }
    .floatbar button:hover{ background:rgba(255,255,255,.12); }

    .hint{ font-size:.8rem; color:#64748b; }
  </style>
</head>

<body>
<div class="wrap">
  <aside class="side">
    <div class="d-flex align-items-center justify-content-between mb-2">
      <div class="fw-bold"><i class="fa-solid fa-pen-ruler text-primary me-2"></i>Admin Designer</div>
      <div class="small-muted">540×540</div>
    </div>

    <div class="small-muted mb-3">
      <div>
        <span class="kbd">Del</span> apagar ·
        <span class="kbd">Ctrl</span>+<span class="kbd">Z</span> undo ·
        <span class="kbd">Ctrl</span>+<span class="kbd">Y</span> redo ·
        <span class="kbd">Ctrl</span>+<span class="kbd">D</span> duplicar
      </div>
      <div>
        <span class="kbd">Ctrl</span>+<span class="kbd">G</span> agrupar ·
        <span class="kbd">Ctrl</span>+<span class="kbd">Shift</span>+<span class="kbd">G</span> desagrupar
      </div>
      <div>
        Pan: segure <span class="kbd">Espaço</span> e arraste · Zoom: <span class="kbd">Ctrl</span> + scroll
      </div>
      <div class="hint">
        Crop: duplo clique na imagem → ajuste → <span class="kbd">Enter</span> aplica / <span class="kbd">Esc</span> cancela
      </div>
      <div class="hint">
        Frames: duplo clique no frame → arraste a imagem dentro → <span class="kbd">Enter</span> aplica / <span class="kbd">Esc</span> cancela
      </div>
    </div>

    <div class="hr"></div>

    <div class="mb-2 d-flex align-items-center justify-content-between">
      <div class="form-label mb-0">TEMPLATES</div>
      <button class="btn btn-sm btn-outline-secondary" onclick="applyTemplate('birthday')">Aplicar padrão</button>
    </div>
    <div id="templates" class="row g-2"></div>
    <div class="small-muted mt-2">Clique em um template para aplicar (substitui o canvas atual).</div>

    <div class="hr"></div>

    <div class="mb-2 d-flex align-items-center justify-content-between">
      <div class="form-label mb-0">MOLDURAS (FRAMES)</div>
      <div class="small-muted">duplo clique = editar</div>
    </div>
    <div id="framesLib" class="row g-2"></div>

    <div class="row g-2 mt-2">
      <div class="col-12">
        <label class="btn btn-outline-secondary w-100 mb-0">
          <i class="fa-solid fa-image me-2"></i>Inserir foto no frame selecionado
          <input id="fileToFrame" type="file" accept="image/*" hidden>
        </label>
      </div>
    </div>

    <div class="small-muted mt-2">
      1) Clique numa moldura para criar no canvas.<br>
      2) Selecione o frame e clique “Inserir foto…”<br>
      3) Duplo clique no frame para arrastar a foto por dentro.
    </div>

    <div class="hr"></div>

    <div class="row g-2 mb-2">
      <div class="col-6">
        <button class="btn btn-outline-primary w-100" onclick="addText()"><i class="fa-solid fa-font me-2"></i>Texto</button>
      </div>
      <div class="col-6">
        <label class="btn btn-outline-primary w-100 mb-0">
          <i class="fa-solid fa-image me-2"></i>Imagem
          <input id="fileImg" type="file" accept="image/*" hidden>
        </label>
      </div>
      <div class="col-6">
        <button class="btn btn-outline-secondary w-100" onclick="addRect()"><i class="fa-regular fa-square me-2"></i>Retângulo</button>
      </div>
      <div class="col-6">
        <button class="btn btn-outline-secondary w-100" onclick="addCircle()"><i class="fa-regular fa-circle me-2"></i>Círculo</button>
      </div>
    </div>

    <div class="row g-2 mb-3">
      <div class="col-6">
        <button class="btn btn-outline-secondary w-100" onclick="groupSel()"><i class="fa-solid fa-object-group me-2"></i>Agrupar</button>
      </div>
      <div class="col-6">
        <button class="btn btn-outline-secondary w-100" onclick="ungroupSel()"><i class="fa-solid fa-object-ungroup me-2"></i>Desagrupar</button>
      </div>
    </div>

    <div class="hr"></div>

    <div class="mb-3">
      <div class="d-flex justify-content-between align-items-end mb-2">
        <label class="form-label mb-0">FUNDO</label>
        <button class="btn btn-sm btn-outline-secondary" onclick="setBgColor('#ffffff')">Branco</button>
      </div>

      <div class="row g-2 mb-2">
        <?php foreach($fundos as $i => $f): ?>
          <div class="col-6">
            <div class="thumb <?= $i===0?'active':'' ?>"
              style="background-image:url('<?= htmlspecialchars($f) ?>')"
              onclick="setBgImage(this,'<?= htmlspecialchars($f) ?>')"></div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="row g-2 mb-2">
        <div class="col-4"><button class="btn btn-sm btn-outline-primary w-100" onclick="setBgColor('#0d6efd')">Azul</button></div>
        <div class="col-4"><button class="btn btn-sm btn-outline-primary w-100" onclick="setBgColor('#6610f2')">Roxo</button></div>
        <div class="col-4"><button class="btn btn-sm btn-outline-primary w-100" onclick="setBgColor('#16a34a')">Verde</button></div>
      </div>

      <div class="row g-2">
        <div class="col-6">
          <button class="btn btn-sm btn-outline-secondary w-100" onclick="addOverlay()"><i class="fa-solid fa-layer-group me-2"></i>Overlay</button>
        </div>
        <div class="col-6">
          <button class="btn btn-sm btn-outline-secondary w-100" onclick="removeOverlay()"><i class="fa-solid fa-xmark me-2"></i>Remover</button>
        </div>
      </div>
      <div class="small-muted mt-2">Overlay é um retângulo transparente por cima do fundo.</div>
    </div>

    <div class="hr"></div>

    <div class="mb-2 d-flex align-items-center justify-content-between">
      <div class="form-label mb-0">CAMADAS</div>
      <div class="d-flex gap-2">
        <button class="btn btn-sm btn-outline-secondary" onclick="bringFront()" title="Frente"><i class="fa-solid fa-layer-group"></i> +</button>
        <button class="btn btn-sm btn-outline-secondary" onclick="sendBack()" title="Trás"><i class="fa-solid fa-layer-group"></i> -</button>
      </div>
    </div>
    <div id="layers" class="d-grid gap-2 mb-3"></div>

    <div class="hr"></div>

    <div class="mb-2 d-flex align-items-center justify-content-between">
      <div class="form-label mb-0">EDITAR ITEM</div>
      <div class="d-flex gap-2">
        <button class="btn btn-sm btn-outline-secondary" onclick="align('left')"><i class="fa-solid fa-align-left"></i></button>
        <button class="btn btn-sm btn-outline-secondary" onclick="align('center')"><i class="fa-solid fa-align-center"></i></button>
        <button class="btn btn-sm btn-outline-secondary" onclick="align('right')"><i class="fa-solid fa-align-right"></i></button>
      </div>
    </div>

    <div id="noSel" class="small-muted mb-3">Selecione um elemento para editar.</div>

    <div id="panel" style="display:none;">
      <div class="mb-2">
        <label class="form-label">COR (Fill)</label>
        <input type="color" id="pColor" class="form-control form-control-color" onchange="applyFill(this.value)">
      </div>

      <div class="row g-2 mb-2">
        <div class="col-6">
          <label class="form-label">BORDA</label>
          <input type="color" id="pStroke" class="form-control form-control-color" onchange="applyStroke(this.value)">
        </div>
        <div class="col-6">
          <label class="form-label">ESP. BORDA</label>
          <input type="number" id="pStrokeW" class="form-control" value="0" min="0" max="40" onchange="applyStrokeW(this.value)">
        </div>
      </div>

      <div class="mb-2">
        <label class="form-label">OPACIDADE</label>
        <input type="range" id="pOpacity" class="form-range" min="0" max="100" value="100" oninput="applyOpacity(this.value)">
      </div>

      <div class="mb-2">
        <label class="form-label">ROTAÇÃO</label>
        <input type="range" id="pRotate" class="form-range" min="-180" max="180" value="0" oninput="applyRotate(this.value)">
      </div>

      <div id="textPanel" style="display:none;">
        <div class="mb-2">
          <label class="form-label">FONTE</label>
          <select id="pFont" class="form-select" onchange="applyFont(this.value)">
            <option value="Inter">Inter</option>
            <option value="Arial">Arial</option>
            <option value="Montserrat">Montserrat</option>
            <option value="Poppins">Poppins</option>
            <option value="Times New Roman">Times New Roman</option>
          </select>
        </div>

        <div class="mb-2">
          <label class="form-label">TAMANHO</label>
          <input type="range" id="pFontSize" class="form-range" min="10" max="140" value="36" oninput="applyFontSize(this.value)">
        </div>

        <div class="mb-2 d-flex gap-2">
          <button class="btn btn-sm btn-outline-secondary w-100" onclick="toggleBold()"><b>B</b></button>
          <button class="btn btn-sm btn-outline-secondary w-100" onclick="toggleItalic()"><i>I</i></button>
          <button class="btn btn-sm btn-outline-secondary w-100" onclick="toggleUnderline()"><u>U</u></button>
        </div>

        <div class="mb-2">
          <label class="form-label">ESPAÇAMENTO LETRAS</label>
          <input type="range" id="pCharSpace" class="form-range" min="-200" max="800" value="0" oninput="applyCharSpace(this.value)">
        </div>

        <div class="mb-2">
          <label class="form-label">ALTURA LINHA</label>
          <input type="range" id="pLineHeight" class="form-range" min="0.8" max="3" step="0.1" value="1.2" oninput="applyLineHeight(this.value)">
        </div>
      </div>

      <div id="imagePanel" style="display:none;">
        <div class="mb-2 form-label">FILTROS (Imagem)</div>
        <div class="d-grid gap-2">
          <button class="btn btn-sm btn-outline-secondary" onclick="imgFilter('none')">Sem filtro</button>
          <button class="btn btn-sm btn-outline-secondary" onclick="imgFilter('grayscale')">P&B</button>
          <button class="btn btn-sm btn-outline-secondary" onclick="imgFilter('sepia')">Sépia</button>
        </div>
        <div class="row g-2 mt-2">
          <div class="col-6">
            <label class="form-label">BRILHO</label>
            <input type="range" class="form-range" min="-1" max="1" step="0.05" value="0" oninput="imgAdjust('brightness', this.value)">
          </div>
          <div class="col-6">
            <label class="form-label">CONTRASTE</label>
            <input type="range" class="form-range" min="-1" max="1" step="0.05" value="0" oninput="imgAdjust('contrast', this.value)">
          </div>
        </div>
      </div>

      <div class="row g-2 mb-2 mt-2">
        <div class="col-6">
          <button class="btn btn-outline-secondary w-100" onclick="duplicateActive()"><i class="fa-regular fa-clone me-2"></i>Duplicar</button>
        </div>
        <div class="col-6">
          <button class="btn btn-outline-danger w-100" onclick="removeActive()"><i class="fa-regular fa-trash-can me-2"></i>Apagar</button>
        </div>
      </div>

      <div class="row g-2">
        <div class="col-6">
          <button class="btn btn-outline-secondary w-100" onclick="lockToggle()"><i class="fa-solid fa-lock me-2"></i>Bloquear</button>
        </div>
        <div class="col-6">
          <button class="btn btn-outline-secondary w-100" onclick="flipX()"><i class="fa-solid fa-right-left me-2"></i>Espelhar</button>
        </div>
      </div>
    </div>

    <div class="hr"></div>

    <div class="mb-2 form-label">UNDO / REDO</div>
    <div class="row g-2 mb-3">
      <div class="col-6"><button class="btn btn-outline-secondary w-100" onclick="undo()"><i class="fa-solid fa-rotate-left me-2"></i>Undo</button></div>
      <div class="col-6"><button class="btn btn-outline-secondary w-100" onclick="redo()"><i class="fa-solid fa-rotate-right me-2"></i>Redo</button></div>
    </div>

    <div class="mb-2 form-label">EXPORTAR</div>
    <div class="row g-2">
      <div class="col-6"><button class="btn btn-primary w-100" onclick="exportPNG(2)"><i class="fa-solid fa-download me-2"></i>PNG 2x</button></div>
      <div class="col-6"><button class="btn btn-outline-primary w-100" onclick="exportPNG(3)">PNG 3x</button></div>
    </div>

    <a href="admin_painel.php" class="btn btn-link w-100 mt-3 text-muted small text-decoration-none">Sair do Editor</a>
  </aside>

  <main class="main">
    <div class="stage" id="stage">
      <div class="floatbar" id="floatbar">
        <button title="Frente" onclick="bringFront()"><i class="fa-solid fa-arrow-up"></i></button>
        <button title="Trás" onclick="sendBack()"><i class="fa-solid fa-arrow-down"></i></button>
        <button title="Duplicar" onclick="duplicateActive()"><i class="fa-regular fa-clone"></i></button>
        <button title="Bloquear" onclick="lockToggle()"><i class="fa-solid fa-lock"></i></button>
        <button title="Apagar" onclick="removeActive()"><i class="fa-regular fa-trash-can"></i></button>
      </div>
      <canvas id="c" width="540" height="540"></canvas>
    </div>
  </main>
</div>

<script>
  // ============================================================
  // Canvas base
  // ============================================================
  const CANVAS_W = 540, CANVAS_H = 540;
  const canvas = new fabric.Canvas('c', { preserveObjectStacking:true, selection:true });
  const stage = document.getElementById('stage');
  const floatbar = document.getElementById('floatbar');

  // ============================================================
  // Zoom + Pan (Space + drag)
  // ============================================================
  let isPanning = false;
  let lastPosX = 0, lastPosY = 0;
  let spaceDown = false;

  document.addEventListener('keydown', (e)=>{ if (e.code === 'Space') spaceDown = true; });
  document.addEventListener('keyup', (e)=>{ if (e.code === 'Space') spaceDown = false; });

  canvas.on('mouse:down', (opt) => {
    if (!spaceDown) return;
    isPanning = true;
    canvas.discardActiveObject();
    canvas.renderAll();
    const e = opt.e;
    lastPosX = e.clientX;
    lastPosY = e.clientY;
  });

  canvas.on('mouse:move', (opt) => {
    if (!isPanning) return;
    const e = opt.e;
    const vpt = canvas.viewportTransform;
    vpt[4] += e.clientX - lastPosX;
    vpt[5] += e.clientY - lastPosY;
    canvas.requestRenderAll();
    lastPosX = e.clientX;
    lastPosY = e.clientY;
  });

  canvas.on('mouse:up', () => { isPanning = false; });

  // Zoom (Ctrl+Scroll)
  canvas.on('mouse:wheel', function(opt) {
    const e = opt.e;
    if (!(e.ctrlKey || e.metaKey)) return;
    let zoom = canvas.getZoom();
    zoom *= 0.999 ** e.deltaY;
    zoom = Math.min(4, Math.max(0.3, zoom));
    canvas.zoomToPoint({ x: e.offsetX, y: e.offsetY }, zoom);
    e.preventDefault(); e.stopPropagation();
  });

  // ============================================================
  // Undo/Redo real
  // ============================================================
  let hist = [];
  let histIndex = -1;
  let histLock = false;
  const HIST_MAX = 60;

  function pushHistory() {
    if (histLock) return;
    const json = JSON.stringify(canvas.toJSON(['id','cropX','cropY','isFrame','frameType','_innerId','_borderId']));
    hist = hist.slice(0, histIndex + 1);
    hist.push(json);
    if (hist.length > HIST_MAX) hist.shift();
    histIndex = hist.length - 1;
  }
  function loadHistory(json) {
    histLock = true;
    canvas.loadFromJSON(JSON.parse(json), () => {
      canvas.renderAll();
      histLock = false;
      refreshPanel();
      renderLayers();
      updateFloatbar();
    });
  }
  function undo(){ if (histIndex<=0) return; histIndex--; loadHistory(hist[histIndex]); }
  function redo(){ if (histIndex>=hist.length-1) return; histIndex++; loadHistory(hist[histIndex]); }

  let histTimer=null;
  function scheduleHistory(){ clearTimeout(histTimer); histTimer=setTimeout(pushHistory, 180); }

  canvas.on('object:added', scheduleHistory);
  canvas.on('object:modified', scheduleHistory);
  canvas.on('object:removed', scheduleHistory);

  // ============================================================
  // Guides / Snap
  // ============================================================
  const SNAP_DIST = 8;
  function near(a,b,dist=SNAP_DIST){ return Math.abs(a-b) <= dist; }

  let guideV=null, guideH=null;
  function clearGuides(){
    if (guideV) { canvas.remove(guideV); guideV=null; }
    if (guideH) { canvas.remove(guideH); guideH=null; }
  }
  function showGuideV(x){
    if (!guideV){
      guideV = new fabric.Line([x,0,x,CANVAS_H], { stroke:'#0d6efd', strokeWidth:2, selectable:false, evented:false, excludeFromExport:true });
      canvas.add(guideV);
      guideV.bringToFront();
    } else {
      guideV.set({ x1:x, x2:x });
      guideV.bringToFront();
    }
  }
  function showGuideH(y){
    if (!guideH){
      guideH = new fabric.Line([0,y,CANVAS_W,y], { stroke:'#0d6efd', strokeWidth:2, selectable:false, evented:false, excludeFromExport:true });
      canvas.add(guideH);
      guideH.bringToFront();
    } else {
      guideH.set({ y1:y, y2:y });
      guideH.bringToFront();
    }
  }

  canvas.on('object:moving', (e) => {
    const o = e.target; if (!o) return;
    if (frameEditMode) return; // não snapa enquanto edita imagem dentro do frame

    clearGuides();

    const br = o.getBoundingRect(true, true);
    const objCx = br.left + br.width/2;
    const objCy = br.top + br.height/2;

    if (near(objCx, CANVAS_W/2)) { o.left += ((CANVAS_W/2) - objCx); showGuideV(CANVAS_W/2); }
    if (near(objCy, CANVAS_H/2)) { o.top  += ((CANVAS_H/2) - objCy); showGuideH(CANVAS_H/2); }

    const pad = 20;
    if (near(br.left, pad)) o.left += (pad - br.left);
    if (near(br.top, pad)) o.top += (pad - br.top);
    if (near(br.left + br.width, CANVAS_W - pad)) o.left += ((CANVAS_W - pad) - (br.left + br.width));
    if (near(br.top + br.height, CANVAS_H - pad)) o.top += ((CANVAS_H - pad) - (br.top + br.height));

    const br2 = o.getBoundingRect(true, true);
    const my = { left: br2.left, right: br2.left+br2.width, top: br2.top, bottom: br2.top+br2.height, cx: br2.left+br2.width/2, cy: br2.top+br2.height/2 };
    const others = canvas.getObjects().filter(x => x !== o && x.evented !== false && !x.excludeFromExport);

    for (const x of others) {
      const r = x.getBoundingRect(true, true);
      const t = { left:r.left, right:r.left+r.width, top:r.top, bottom:r.top+r.height, cx:r.left+r.width/2, cy:r.top+r.height/2 };

      if (near(my.cx, t.cx)) { o.left += (t.cx - my.cx); showGuideV(t.cx); }
      if (near(my.cy, t.cy)) { o.top  += (t.cy - my.cy); showGuideH(t.cy); }
    }
  });

  canvas.on('mouse:up', () => { setTimeout(clearGuides, 120); });

  // ============================================================
  // Fundo
  // ============================================================
  function setBgColor(color) {
    canvas.setBackgroundImage(null, canvas.renderAll.bind(canvas));
    canvas.setBackgroundColor(color, canvas.renderAll.bind(canvas));
    document.querySelectorAll('.thumb').forEach(t => t.classList.remove('active'));
    scheduleHistory();
  }

  function setBgImage(el, url) {
    if (el) {
      document.querySelectorAll('.thumb').forEach(t => t.classList.remove('active'));
      el.classList.add('active');
    }
    canvas.setBackgroundColor('#ffffff', canvas.renderAll.bind(canvas));
    fabric.Image.fromURL(url, (img) => {
      const scale = Math.max(CANVAS_W / img.width, CANVAS_H / img.height);
      img.set({ left:0, top:0, originX:'left', originY:'top', selectable:false, evented:false });
      img.scale(scale);
      canvas.setBackgroundImage(img, canvas.renderAll.bind(canvas));
      scheduleHistory();
    }, { crossOrigin:'anonymous' });
  }

  // Overlay
  let overlayId = null;
  function addOverlay() {
    if (overlayId) return;
    const o = new fabric.Rect({ left:0, top:0, width:CANVAS_W, height:CANVAS_H, fill:'rgba(0,0,0,0.25)' });
    o.id = 'overlay';
    overlayId = o.id;
    canvas.add(o);
    canvas.sendToBack(o);
    renderLayers();
    canvas.setActiveObject(o);
    scheduleHistory();
  }
  function removeOverlay() {
    const obj = canvas.getObjects().find(x => x.id === 'overlay');
    if (!obj) return;
    canvas.remove(obj);
    overlayId = null;
    renderLayers();
    scheduleHistory();
  }

  // ============================================================
  // Objetos iniciais (editáveis)
  // ============================================================
  function addFooterMacro(){
    const footer = new fabric.Rect({ left:0, top:CANVAS_H-92, width:CANVAS_W, height:92, fill:'#1a2b49' }); footer.id='footer';
    const avatarCircle = new fabric.Circle({ left:40, top:CANVAS_H-92-32, radius:40, fill:'#fff', stroke:'#fff', strokeWidth:4 }); avatarCircle.id='avatarCircle';
    const nameText = new fabric.Textbox("<?= addslashes($nome_v) ?>", { left:115, top:CANVAS_H-70, width:390, fontSize:22, fill:'#fff', fontWeight:'800' }); nameText.id='nome';
    const roleText = new fabric.Textbox("<?= addslashes($cargo_v) ?>", { left:115, top:CANVAS_H-44, width:390, fontSize:12, fill:'#fff' }); roleText.id='cargo';

    canvas.add(footer, avatarCircle, nameText, roleText);

    fabric.Image.fromURL("<?= addslashes($foto_v) ?>", (img) => {
      const s = 80 / Math.min(img.width, img.height);
      img.set({ left:40, top:CANVAS_H-92-32, scaleX:s, scaleY:s });
      img.id='avatar';
      img.clipPath = new fabric.Circle({ radius:40, originX:'center', originY:'center', left:40, top:40 });
      canvas.add(img);
      canvas.bringToFront(img);
      canvas.renderAll();
      renderLayers();
    }, { crossOrigin:'anonymous' });
  }

  // inicia base
  setBgImage(document.querySelector('.thumb.active'), '<?= htmlspecialchars($fundos[0] ?? '') ?>');
  addFooterMacro();

  const t1 = new fabric.Textbox("Anna Maria", { left:60, top:120, width:420, fontSize:40, fontWeight:'800', fill:'#1a2b49', textAlign:'center' }); t1.id='titulo1';
  const t2 = new fabric.Textbox("PARABÉNS,\nFELIZ ANIVERSÁRIO!", { left:60, top:180, width:420, fontSize:44, fontWeight:'900', fill:'#0d47a1', textAlign:'center' }); t2.id='titulo2';
  const t3 = new fabric.Textbox("Que Deus abençoe e proteja você e sua família!", { left:70, top:300, width:400, fontSize:18, fontWeight:'600', fill:'#334155', textAlign:'center' }); t3.id='msg';
  canvas.add(t1,t2,t3);
  canvas.setActiveObject(t2);

  canvas.renderAll();
  pushHistory();

  // ============================================================
  // Adicionar elementos
  // ============================================================
  function addText(){
    const tb = new fabric.Textbox('Seu texto', { left:80, top:80, width:380, fontSize:36, fill:'#111827', fontFamily:'Inter', fontWeight:'800', textAlign:'center' });
    tb.id = 'text_' + Date.now();
    canvas.add(tb); canvas.setActiveObject(tb);
    canvas.renderAll(); renderLayers(); refreshPanel(); scheduleHistory(); updateFloatbar();
  }
  function addRect(){
    const r = new fabric.Rect({ left:120, top:140, width:300, height:160, fill:'#0d6efd', rx:18, ry:18 });
    r.id = 'rect_' + Date.now();
    canvas.add(r); canvas.setActiveObject(r);
    canvas.renderAll(); renderLayers(); refreshPanel(); scheduleHistory(); updateFloatbar();
  }
  function addCircle(){
    const c = new fabric.Circle({ left:180, top:160, radius:90, fill:'#6610f2' });
    c.id = 'circle_' + Date.now();
    canvas.add(c); canvas.setActiveObject(c);
    canvas.renderAll(); renderLayers(); refreshPanel(); scheduleHistory(); updateFloatbar();
  }

  // Upload imagem normal
  document.getElementById('fileImg').addEventListener('change', (ev) => {
    const file = ev.target.files?.[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => {
      fabric.Image.fromURL(reader.result, (img) => {
        img.set({ left:120, top:120 });
        const maxW = 360, maxH = 280;
        const scale = Math.min(maxW / img.width, maxH / img.height, 1);
        img.scale(scale);
        img.id = 'img_' + Date.now();
        canvas.add(img);
        canvas.setActiveObject(img);
        canvas.renderAll();
        renderLayers();
        refreshPanel();
        scheduleHistory();
        updateFloatbar();
      }, { crossOrigin:'anonymous' });
    };
    reader.readAsDataURL(file);
    ev.target.value = '';
  });

  // ============================================================
  // Layers
  // ============================================================
  const layersEl = document.getElementById('layers');
  function getLabel(o){
    if (o.isFrame) return "Frame";
    const t = o.type;
    if (t === 'textbox' || t === 'text') return 'Texto';
    if (t === 'image') return 'Imagem';
    if (t === 'rect') return 'Retângulo';
    if (t === 'circle') return 'Círculo';
    if (t === 'group') return 'Grupo';
    return t;
  }
  function renderLayers(){
    if (!layersEl) return;
    layersEl.innerHTML = '';
    const objs = canvas.getObjects().slice().reverse().filter(o => !o.excludeFromExport);
    const active = canvas.getActiveObject();

    objs.forEach((o) => {
      const row = document.createElement('div');
      row.className = 'layer-item' + (o === active ? ' active' : '');
      row.onclick = () => { if(frameEditMode) return; canvas.setActiveObject(o); canvas.requestRenderAll(); refreshPanel(); renderLayers(); updateFloatbar(); };

      const left = document.createElement('div');
      left.style.display='flex'; left.style.alignItems='center'; left.style.gap='10px';

      const icon = document.createElement('div');
      icon.innerHTML = o.isFrame ? '<i class="fa-regular fa-square"></i>'
                 : (o.type === 'image') ? '<i class="fa-regular fa-image"></i>'
                 : (o.type === 'textbox') ? '<i class="fa-solid fa-font"></i>'
                 : '<i class="fa-regular fa-square"></i>';
      icon.style.width='18px';

      const title = document.createElement('div');
      title.innerHTML = `<div class="fw-semibold" style="font-size:.9rem">${getLabel(o)}</div>
                         <div class="small-muted" style="line-height:1">${o.id || ''}</div>`;
      left.appendChild(icon); left.appendChild(title);

      const right = document.createElement('div');
      right.style.display='flex'; right.style.gap='8px';

      const up = document.createElement('button');
      up.className='btn btn-sm btn-outline-secondary';
      up.innerHTML = '<i class="fa-solid fa-arrow-up"></i>';
      up.onclick = (e) => { e.stopPropagation(); canvas.bringForward(o); canvas.renderAll(); renderLayers(); scheduleHistory(); updateFloatbar(); };

      const down = document.createElement('button');
      down.className='btn btn-sm btn-outline-secondary';
      down.innerHTML = '<i class="fa-solid fa-arrow-down"></i>';
      down.onclick = (e) => { e.stopPropagation(); canvas.sendBackwards(o); canvas.renderAll(); renderLayers(); scheduleHistory(); updateFloatbar(); };

      right.appendChild(up); right.appendChild(down);

      row.appendChild(left); row.appendChild(right);
      layersEl.appendChild(row);
    });
  }

  // ============================================================
  // Seleção -> painel + floatbar
  // ============================================================
  const panel = document.getElementById('panel');
  const noSel = document.getElementById('noSel');
  const textPanel = document.getElementById('textPanel');
  const imagePanel = document.getElementById('imagePanel');

  function safeHex(c, fallback="#111111"){
    if (typeof c !== 'string') return fallback;
    if (c.startsWith('#') && (c.length===7 || c.length===4)) return c.length===4 ? ('#'+c[1]+c[1]+c[2]+c[2]+c[3]+c[3]) : c;
    return fallback;
  }

  function refreshPanel(){
    const o = canvas.getActiveObject();
    if (!o) {
      panel.style.display='none';
      noSel.style.display='block';
      renderLayers();
      return;
    }
    panel.style.display='block';
    noSel.style.display='none';

    // fill/stroke
    document.getElementById('pColor').value = safeHex(o.fill, '#111111');
    document.getElementById('pStroke').value = safeHex(o.stroke, '#000000');
    document.getElementById('pStrokeW').value = o.strokeWidth || 0;

    document.getElementById('pOpacity').value = Math.round((o.opacity ?? 1) * 100);
    document.getElementById('pRotate').value = Math.round(o.angle ?? 0);

    const isText = (o.type === 'textbox' || o.type === 'text');
    const isImg  = (o.type === 'image'); // frames são group

    textPanel.style.display = isText ? 'block' : 'none';
    imagePanel.style.display = isImg ? 'block' : 'none';

    if (isText) {
      document.getElementById('pFont').value = o.fontFamily || 'Inter';
      document.getElementById('pFontSize').value = o.fontSize || 36;
      document.getElementById('pCharSpace').value = o.charSpacing || 0;
      document.getElementById('pLineHeight').value = o.lineHeight || 1.2;
    }

    renderLayers();
  }

  function updateFloatbar(){
    const o = canvas.getActiveObject();
    if (!o || o.type === 'activeSelection') { floatbar.style.display = 'none'; return; }
    const br = o.getBoundingRect(true, true);

    const stageRect = stage.getBoundingClientRect();
    const canvasEl = canvas.getElement();
    const canvasRect = canvasEl.getBoundingClientRect();

    const x = (canvasRect.left - stageRect.left) + br.left + br.width/2;
    const y = (canvasRect.top - stageRect.top) + br.top;

    floatbar.style.left = `${x}px`;
    floatbar.style.top  = `${y}px`;
    floatbar.style.display = frameEditMode ? 'none' : 'flex';
  }

  canvas.on('selection:created', ()=>{ refreshPanel(); updateFloatbar(); });
  canvas.on('selection:updated', ()=>{ refreshPanel(); updateFloatbar(); });
  canvas.on('selection:cleared', ()=>{ refreshPanel(); floatbar.style.display='none'; });
  canvas.on('object:modified', ()=>{ updateFloatbar(); });
  canvas.on('object:moving', ()=>{ updateFloatbar(); });
  canvas.on('object:scaling', ()=>{ updateFloatbar(); });
  canvas.on('object:rotating', ()=>{ updateFloatbar(); });

  // ============================================================
  // Edição geral
  // ============================================================
  function applyFill(v){ const o=canvas.getActiveObject(); if(!o) return; o.set('fill', v); canvas.renderAll(); scheduleHistory(); }
  function applyStroke(v){ const o=canvas.getActiveObject(); if(!o) return; o.set('stroke', v); canvas.renderAll(); scheduleHistory(); }
  function applyStrokeW(v){ const o=canvas.getActiveObject(); if(!o) return; o.set('strokeWidth', parseInt(v,10)||0); canvas.renderAll(); scheduleHistory(); }
  function applyOpacity(v){ const o=canvas.getActiveObject(); if(!o) return; o.set('opacity', Math.max(0, Math.min(1, v/100))); canvas.renderAll(); scheduleHistory(); }
  function applyRotate(v){ const o=canvas.getActiveObject(); if(!o) return; o.rotate(parseInt(v,10)); canvas.renderAll(); scheduleHistory(); }

  // Texto
  function applyFont(v){ const o=canvas.getActiveObject(); if(!o||!(o.type==='textbox'||o.type==='text')) return; o.set('fontFamily', v); canvas.renderAll(); scheduleHistory(); }
  function applyFontSize(v){ const o=canvas.getActiveObject(); if(!o||!(o.type==='textbox'||o.type==='text')) return; o.set('fontSize', parseInt(v,10)); canvas.renderAll(); scheduleHistory(); }
  function toggleBold(){ const o=canvas.getActiveObject(); if(!o||!(o.type==='textbox'||o.type==='text')) return;
    const fw=(o.fontWeight||'').toString(); o.set('fontWeight', (fw==='bold'||parseInt(fw,10)>=700)?'400':'800'); canvas.renderAll(); refreshPanel(); scheduleHistory(); }
  function toggleItalic(){ const o=canvas.getActiveObject(); if(!o||!(o.type==='textbox'||o.type==='text')) return;
    o.set('fontStyle', o.fontStyle==='italic'?'normal':'italic'); canvas.renderAll(); refreshPanel(); scheduleHistory(); }
  function toggleUnderline(){ const o=canvas.getActiveObject(); if(!o||!(o.type==='textbox'||o.type==='text')) return;
    o.set('underline', !o.underline); canvas.renderAll(); refreshPanel(); scheduleHistory(); }
  function applyCharSpace(v){ const o=canvas.getActiveObject(); if(!o||!(o.type==='textbox'||o.type==='text')) return;
    o.set('charSpacing', parseInt(v,10)); canvas.renderAll(); scheduleHistory(); }
  function applyLineHeight(v){ const o=canvas.getActiveObject(); if(!o||!(o.type==='textbox'||o.type==='text')) return;
    o.set('lineHeight', parseFloat(v)); canvas.renderAll(); scheduleHistory(); }

  // Imagem filtros
  function imgFilter(kind){
    const o=canvas.getActiveObject(); if(!o||o.type!=='image') return;
    o.filters=[];
    if(kind==='grayscale') o.filters.push(new fabric.Image.filters.Grayscale());
    if(kind==='sepia') o.filters.push(new fabric.Image.filters.Sepia());
    o.applyFilters(); canvas.renderAll(); scheduleHistory();
  }
  function imgAdjust(kind, val){
    const o=canvas.getActiveObject(); if(!o||o.type!=='image') return;
    const v=parseFloat(val);
    o.filters=o.filters||[];
    o.filters = o.filters.filter(f => f && f.type !== (kind==='brightness'?'Brightness':'Contrast'));
    if(kind==='brightness') o.filters.push(new fabric.Image.filters.Brightness({ brightness:v }));
    if(kind==='contrast') o.filters.push(new fabric.Image.filters.Contrast({ contrast:v }));
    o.applyFilters(); canvas.renderAll(); scheduleHistory();
  }

  // ============================================================
  // Ações gerais
  // ============================================================
  function removeActive(){
    const o=canvas.getActiveObject(); if(!o) return;
    if (frameEditMode) return;
    canvas.remove(o); canvas.discardActiveObject(); canvas.renderAll();
    renderLayers(); refreshPanel(); scheduleHistory(); updateFloatbar();
  }
  function duplicateActive(){
    const o=canvas.getActiveObject(); if(!o) return;
    if (frameEditMode) return;
    o.clone((cl)=>{ cl.set({ left:(o.left||0)+16, top:(o.top||0)+16 }); cl.id=(o.id?o.id+'_copy':'copy_'+Date.now());
      canvas.add(cl); canvas.setActiveObject(cl); canvas.renderAll();
      renderLayers(); refreshPanel(); scheduleHistory(); updateFloatbar();
    });
  }
  function bringFront(){ const o=canvas.getActiveObject(); if(!o) return; if(frameEditMode) return; canvas.bringForward(o); canvas.renderAll(); renderLayers(); scheduleHistory(); updateFloatbar(); }
  function sendBack(){ const o=canvas.getActiveObject(); if(!o) return; if(frameEditMode) return; canvas.sendBackwards(o); canvas.renderAll(); renderLayers(); scheduleHistory(); updateFloatbar(); }
  function lockToggle(){
    const o=canvas.getActiveObject(); if(!o) return;
    if (frameEditMode) return;
    const locked = o.lockMovementX && o.lockMovementY;
    o.set({ lockMovementX:!locked, lockMovementY:!locked, lockScalingX:!locked, lockScalingY:!locked, lockRotation:!locked });
    canvas.renderAll(); scheduleHistory();
  }
  function flipX(){ const o=canvas.getActiveObject(); if(!o) return; if(frameEditMode) return; o.set('flipX', !o.flipX); canvas.renderAll(); scheduleHistory(); }

  function align(where){
    const o=canvas.getActiveObject(); if(!o) return; if(frameEditMode) return;
    if(where==='left') o.set('left', 20);
    if(where==='center') o.set('left', (CANVAS_W - o.getScaledWidth())/2);
    if(where==='right') o.set('left', CANVAS_W - o.getScaledWidth() - 20);
    canvas.renderAll(); scheduleHistory(); updateFloatbar();
  }

  function groupSel(){
    const sel=canvas.getActiveObject();
    if(!sel||sel.type!=='activeSelection') return;
    if(frameEditMode) return;
    sel.toGroup(); canvas.renderAll(); renderLayers(); scheduleHistory(); updateFloatbar();
  }
  function ungroupSel(){
    const sel=canvas.getActiveObject();
    if(!sel||sel.type!=='group') return;
    if(frameEditMode) return;
    sel.toActiveSelection(); canvas.renderAll(); renderLayers(); scheduleHistory(); updateFloatbar();
  }

  // ============================================================
  // Export
  // ============================================================
  function exportPNG(mult=2){
    floatbar.style.display='none';
    canvas.discardActiveObject();
    clearGuides();
    canvas.renderAll();
    const dataUrl = canvas.toDataURL({ format:'png', multiplier:mult, enableRetinaScaling:true });
    const a=document.createElement('a'); a.href=dataUrl; a.download=`arte_${mult}x.png`; a.click();
  }

  // ============================================================
  // Crop (duplo clique) — imagem normal
  // ============================================================
  let cropMode=false, cropTarget=null, cropBox=null;

  canvas.on('mouse:dblclick', (opt) => {
    const t = opt.target;
    if (!t) return;

    // Se for frame, tratamos no bloco de frames (abaixo)
    if (t.isFrame) return;

    if (t.type === 'image') {
      enterCrop(t);
    }
  });

  function enterCrop(img){
    if(frameEditMode) return;
    cancelCrop();
    cropMode=true; cropTarget=img;
    const br = img.getBoundingRect(true, true);
    cropBox = new fabric.Rect({
      left: br.left, top: br.top, width: br.width, height: br.height,
      fill: 'rgba(13,110,253,0.08)',
      stroke: '#0d6efd',
      strokeWidth: 2,
      strokeDashArray: [8,6],
      hasRotatingPoint: false,
      transparentCorners:false,
      cornerColor:'#0d6efd'
    });
    cropBox.excludeFromExport = true;
    canvas.add(cropBox);
    canvas.setActiveObject(cropBox);
    updateFloatbar();
    canvas.renderAll();
  }

  function cancelCrop(){
    cropMode=false; cropTarget=null;
    if(cropBox){ canvas.remove(cropBox); cropBox=null; canvas.renderAll(); }
  }

  function applyCrop(){
    if(!cropMode||!cropTarget||!cropBox) return;
    const img=cropTarget;
    const cb=cropBox.getBoundingRect(true, true);

    const inv = fabric.util.invertTransform(img.calcTransformMatrix());
    const p1 = fabric.util.transformPoint(new fabric.Point(cb.left, cb.top), inv);
    const p2 = fabric.util.transformPoint(new fabric.Point(cb.left+cb.width, cb.top+cb.height), inv);

    const srcW = img._originalElement ? img._originalElement.width : img.width;
    const srcH = img._originalElement ? img._originalElement.height : img.height;

    const ox = Math.max(0, Math.min(srcW, p1.x));
    const oy = Math.max(0, Math.min(srcH, p1.y));
    const ow = Math.max(1, Math.min(srcW - ox, (p2.x - p1.x)));
    const oh = Math.max(1, Math.min(srcH - oy, (p2.y - p1.y)));

    img.set({ cropX: ox, cropY: oy, width: ow, height: oh });

    const scaleX = cb.width / ow;
    const scaleY = cb.height / oh;

    img.set({ left: cb.left, top: cb.top, scaleX, scaleY });

    cancelCrop();
    canvas.setActiveObject(img);
    canvas.renderAll();
    scheduleHistory();
    updateFloatbar();
  }

  // ============================================================
  // Templates
  // ============================================================
  const TEMPLATES = [
    {
      id: "birthday",
      name: "Aniversário (padrão)",
      thumb: "assets/fundos/abstract1.jpg",
      bg: { type: "image", value: "assets/fundos/cidade.jpg" },
      objects: [
        { type:"textbox", id:"titulo1", text:"Anna Maria", left:60, top:120, width:420, fontSize:40, fontWeight:"800", fill:"#1a2b49", textAlign:"center" },
        { type:"textbox", id:"titulo2", text:"PARABÉNS,\nFELIZ ANIVERSÁRIO!", left:60, top:180, width:420, fontSize:44, fontWeight:"900", fill:"#0d47a1", textAlign:"center" },
        { type:"textbox", id:"msg", text:"Que Deus abençoe e proteja você e sua família!", left:70, top:300, width:400, fontSize:18, fontWeight:"600", fill:"#334155", textAlign:"center" },
        { type:"footer" }
      ]
    },
    {
      id: "goodmorning",
      name: "Bom dia",
      thumb: "assets/fundos/abstract2.jpg",
      bg: { type:"color", value:"#ffffff" },
      objects: [
        { type:"rect", id:"bgBlock", left:40, top:60, width:460, height:220, fill:"#0d6efd", rx:26, ry:26, opacity:0.95 },
        { type:"textbox", id:"tMain", text:"BOM DIA!", left:60, top:95, width:420, fontSize:68, fontWeight:"900", fill:"#ffffff", textAlign:"center" },
        { type:"textbox", id:"tSub", text:"Que seu dia seja abençoado 🙌", left:60, top:175, width:420, fontSize:22, fontWeight:"700", fill:"#ffffff", textAlign:"center" },
        { type:"footer" }
      ]
    },
    {
      id: "notice",
      name: "Comunicado",
      thumb: "assets/fundos/bandeira.jpg",
      bg: { type:"image", value:"assets/fundos/bandeira.jpg" },
      objects: [
        { type:"overlay", rgba:"rgba(0,0,0,0.35)" },
        { type:"textbox", id:"aviso", text:"COMUNICADO", left:60, top:90, width:420, fontSize:56, fontWeight:"900", fill:"#ffffff", textAlign:"center" },
        { type:"textbox", id:"corpo", text:"Informe importante para a população.\nColoque aqui a mensagem.", left:70, top:185, width:400, fontSize:20, fontWeight:"600", fill:"#ffffff", textAlign:"center", lineHeight:1.3 },
        { type:"footer" }
      ]
    }
  ];

  function renderTemplatesPanel(){
    const el = document.getElementById('templates');
    if (!el) return;
    el.innerHTML = "";
    TEMPLATES.forEach(t => {
      const col = document.createElement('div');
      col.className = "col-6";
      col.style.cursor = "pointer";
      col.innerHTML = `
        <div class="thumb" style="height:84px;background-image:url('${t.thumb}');"></div>
        <div class="small-muted mt-1" style="font-weight:700">${t.name}</div>
      `;
      col.onclick = () => applyTemplate(t.id);
      el.appendChild(col);
    });
  }

  function applyTemplate(templateId){
    const tpl = TEMPLATES.find(t => t.id === templateId);
    if (!tpl) return alert("Template não encontrado.");
    if (!confirm("Aplicar template vai substituir o canvas atual. Continuar?")) return;
    if (frameEditMode) exitFrameEdit(true);
    cancelCrop();

    canvas.clear();
    overlayId = null;

    if (tpl.bg?.type === "color") setBgColor(tpl.bg.value);
    else if (tpl.bg?.type === "image") setBgImage(null, tpl.bg.value);
    else setBgColor("#ffffff");

    tpl.objects.forEach(spec => {
      if (spec.type === "textbox") {
        const tb = new fabric.Textbox(spec.text || "Texto", {
          left: spec.left ?? 80,
          top: spec.top ?? 80,
          width: spec.width ?? 380,
          fontSize: spec.fontSize ?? 36,
          fill: spec.fill ?? "#111827",
          fontFamily: spec.fontFamily ?? "Inter",
          fontWeight: spec.fontWeight ?? "800",
          textAlign: spec.textAlign ?? "left",
          opacity: spec.opacity ?? 1,
          lineHeight: spec.lineHeight ?? 1.2
        });
        tb.id = spec.id || ("text_" + Date.now());
        canvas.add(tb);
      }

      if (spec.type === "rect") {
        const r = new fabric.Rect({
          left: spec.left ?? 120,
          top: spec.top ?? 140,
          width: spec.width ?? 300,
          height: spec.height ?? 160,
          fill: spec.fill ?? "#0d6efd",
          opacity: spec.opacity ?? 1,
          rx: spec.rx ?? 0,
          ry: spec.ry ?? 0
        });
        r.id = spec.id || ("rect_" + Date.now());
        canvas.add(r);
      }

      if (spec.type === "circle") {
        const c = new fabric.Circle({
          left: spec.left ?? 180,
          top: spec.top ?? 160,
          radius: spec.radius ?? 90,
          fill: spec.fill ?? "#6610f2",
          opacity: spec.opacity ?? 1
        });
        c.id = spec.id || ("circle_" + Date.now());
        canvas.add(c);
      }

      if (spec.type === "overlay") {
        const o = new fabric.Rect({ left:0, top:0, width:CANVAS_W, height:CANVAS_H, fill: spec.rgba ?? "rgba(0,0,0,0.25)" });
        o.id = "overlay";
        overlayId = "overlay";
        canvas.add(o);
        canvas.sendToBack(o);
      }

      if (spec.type === "footer") addFooterMacro();
    });

    const firstText = canvas.getObjects().find(o => o.type === "textbox");
    if (firstText) canvas.setActiveObject(firstText);

    canvas.renderAll();
    renderLayers();
    refreshPanel();
    pushHistory();
    updateFloatbar();
  }

  // ============================================================
  // FRAMES / Molduras estilo Canva real
  // ============================================================
  const SHAPE_PATHS = {
    star: "M50 5 L61 38 L95 38 L67 57 L78 90 L50 70 L22 90 L33 57 L5 38 L39 38 Z",
    heart: "M50 88 C20 70 6 52 10 35 C14 20 30 18 38 26 C45 33 50 40 50 40 C50 40 55 33 62 26 C70 18 86 20 90 35 C94 52 80 70 50 88 Z"
  };

  const FRAMES_LIBRARY = [
    { id:"circle",  name:"Círculo", thumb:"circle" },
    { id:"rounded", name:"Rounded", thumb:"rounded" },
    { id:"star",    name:"Estrela", thumb:"star" },
    { id:"heart",   name:"Coração", thumb:"heart" }
  ];

  function uid(prefix="id"){ return `${prefix}_${Date.now()}_${Math.floor(Math.random()*9999)}`; }

  function renderFramesLibrary(){
    const el = document.getElementById('framesLib');
    if (!el) return;
    el.innerHTML = "";

    FRAMES_LIBRARY.forEach(f => {
      const col = document.createElement('div');
      col.className = "col-6";
      col.style.cursor = "pointer";

      let thumbHTML = "";
      if (f.thumb === "circle") thumbHTML = `<div style="height:84px;border:2px dashed #cbd5e1;border-radius:999px;background:#f8fafc;"></div>`;
      if (f.thumb === "rounded") thumbHTML = `<div style="height:84px;border:2px dashed #cbd5e1;border-radius:18px;background:#f8fafc;"></div>`;
      if (f.thumb === "star") thumbHTML = `<div style="height:84px;border:2px dashed #cbd5e1;border-radius:12px;background:#f8fafc;display:flex;align-items:center;justify-content:center;font-size:26px;">★</div>`;
      if (f.thumb === "heart") thumbHTML = `<div style="height:84px;border:2px dashed #cbd5e1;border-radius:12px;background:#f8fafc;display:flex;align-items:center;justify-content:center;font-size:26px;">❤</div>`;

      col.innerHTML = `${thumbHTML}<div class="small-muted mt-1" style="font-weight:700">${f.name}</div>`;
      col.onclick = () => createFrame(f.id);
      el.appendChild(col);
    });
  }

  function makeClipShape(type, w, h){
    if (type === "circle") {
      const r = Math.min(w,h)/2;
      return new fabric.Circle({ radius:r, originX:'center', originY:'center' });
    }
    if (type === "rounded") {
      const rx = Math.min(w,h) * 0.18;
      return new fabric.Rect({ width:w, height:h, rx, ry:rx, originX:'center', originY:'center' });
    }
    if (type === "star") {
      const p = new fabric.Path(SHAPE_PATHS.star, { originX:'center', originY:'center' });
      const scale = Math.min(w,h)/100;
      p.scale(scale);
      return p;
    }
    if (type === "heart") {
      const p = new fabric.Path(SHAPE_PATHS.heart, { originX:'center', originY:'center' });
      const scale = Math.min(w,h)/100;
      p.scale(scale);
      return p;
    }
    return new fabric.Rect({ width:w, height:h, originX:'center', originY:'center' });
  }

  function cloneShapeForBorder(shape){
    if (shape.type === "circle") {
      return new fabric.Circle({ radius: shape.radius, originX: shape.originX, originY: shape.originY });
    }
    if (shape.type === "rect") {
      return new fabric.Rect({ width: shape.width, height: shape.height, rx: shape.rx||0, ry: shape.ry||0, originX: shape.originX, originY: shape.originY });
    }
    if (shape.type === "path") {
      const p = new fabric.Path(shape.path, { originX:shape.originX, originY:shape.originY });
      p.scaleX = shape.scaleX; p.scaleY = shape.scaleY;
      return p;
    }
    return new fabric.Rect({ width:200, height:200, originX:'center', originY:'center' });
  }

  function createFrame(type){
    if (frameEditMode) exitFrameEdit(true);
    cancelCrop();

    const frameId = uid("frame");
    const baseW = 320, baseH = 320;

    const clip = makeClipShape(type, baseW, baseH);

    const placeholder = new fabric.Rect({ width: baseW, height: baseH, fill: "#e2e8f0", originX:'center', originY:'center' });
    const hint = new fabric.Textbox("Solte/insira\numa imagem", {
      width: baseW-40, fontSize: 22, fill: "#64748b", textAlign: "center",
      fontWeight: "800", originX:'center', originY:'center'
    });

    const innerGroup = new fabric.Group([placeholder, hint], { originX:'center', originY:'center' });
    innerGroup.id = uid("inner");
    innerGroup.clipPath = clip;
    innerGroup.clipPath.absolutePositioned = false;

    const border = cloneShapeForBorder(clip);
    border.set({
      fill: "rgba(0,0,0,0)",
      stroke: "#94a3b8",
      strokeWidth: 3,
      strokeDashArray: [7,6],
      selectable: false,
      evented: false
    });
    border.id = uid("frameBorder");

    const frameGroup = new fabric.Group([innerGroup, border], {
      left: 110, top: 110, originX:'left', originY:'top'
    });

    frameGroup.id = frameId;
    frameGroup.isFrame = true;
    frameGroup.frameType = type;
    frameGroup._innerId = innerGroup.id;
    frameGroup._borderId = border.id;

    canvas.add(frameGroup);
    canvas.setActiveObject(frameGroup);
    canvas.requestRenderAll();

    renderLayers();
    refreshPanel();
    scheduleHistory();
    updateFloatbar();
  }

  function getFrameInner(frameGroup){
    if (!frameGroup || !frameGroup.isFrame) return null;
    return frameGroup._objects?.find(o => o.id === frameGroup._innerId) || null;
  }
  function getFrameBorder(frameGroup){
    if (!frameGroup || !frameGroup.isFrame) return null;
    return frameGroup._objects?.find(o => o.id === frameGroup._borderId) || null;
  }

  function getClipSize(clip){
    if (!clip) return { w: 200, h: 200 };
    if (clip.type === "circle") { const d = (clip.radius||100)*2; return { w:d, h:d }; }
    if (clip.type === "rect") { return { w: clip.width||200, h: clip.height||200 }; }
    const br = clip.getBoundingRect?.() || { width:200, height:200 };
    return { w: br.width||200, h: br.height||200 };
  }
  function coverScale(srcW, srcH, boxW, boxH){
    return Math.max(boxW/srcW, boxH/srcH);
  }

  const fileToFrame = document.getElementById('fileToFrame');
  if (fileToFrame) {
    fileToFrame.addEventListener('change', (ev) => {
      const f = ev.target.files?.[0];
      if (!f) return;
      const active = canvas.getActiveObject();
      if (!active || !active.isFrame) {
        alert("Selecione um frame (moldura) antes de inserir a imagem.");
        ev.target.value = '';
        return;
      }
      const reader = new FileReader();
      reader.onload = () => { setImageIntoFrame(active, reader.result); };
      reader.readAsDataURL(f);
      ev.target.value = '';
    });
  }

  function setImageIntoFrame(frameGroup, dataUrl){
    const inner = getFrameInner(frameGroup);
    if (!inner) return;

    // reaproveita o clip do conteúdo atual
    const clip = inner.clipPath;

    fabric.Image.fromURL(dataUrl, (img) => {
      img.set({ originX:'center', originY:'center' });

      const clipSize = getClipSize(clip);
      const cover = coverScale(img.width, img.height, clipSize.w, clipSize.h);
      img.scale(cover);
      img.left = 0; img.top = 0;

      img.clipPath = clip;
      img.clipPath.absolutePositioned = false;

      const border = getFrameBorder(frameGroup);

      // substitui inner -> vira image
      frameGroup._objects = [];
      img.id = uid("frameImg");
      frameGroup.addWithUpdate(img);
      if (border) frameGroup.addWithUpdate(border);

      frameGroup._innerId = img.id;

      canvas.requestRenderAll();
      scheduleHistory();
      renderLayers();
      refreshPanel();
      updateFloatbar();
    }, { crossOrigin:'anonymous' });
  }

  // --- Modo editar conteúdo do frame (duplo clique) ---
  let frameEditMode = false;
  let frameEditTarget = null;
  let frameEditInner = null;
  let frameEditInnerStart = null;

  function constrainInnerToClip(){
    if (!frameEditMode || !frameEditTarget || !frameEditInner) return;
    const inner = frameEditInner;
    const clip = inner.clipPath;
    if (!clip) return;

    const clipSize = getClipSize(clip);
    const iw = inner.getScaledWidth();
    const ih = inner.getScaledHeight();

    const maxX = Math.max(0, (iw - clipSize.w) / 2);
    const maxY = Math.max(0, (ih - clipSize.h) / 2);

    inner.left = Math.max(-maxX, Math.min(maxX, inner.left));
    inner.top  = Math.max(-maxY, Math.min(maxY, inner.top));
  }

  function enterFrameEdit(frameGroup){
    const inner = getFrameInner(frameGroup);
    if (!inner) return;

    frameEditMode = true;
    frameEditTarget = frameGroup;
    frameEditInner = inner;

    frameEditInnerStart = { left: inner.left, top: inner.top, scaleX: inner.scaleX, scaleY: inner.scaleY, angle: inner.angle };

    frameGroup.selectable = false;
    frameGroup.evented = false;

    inner.selectable = true;
    inner.evented = true;
    inner.lockScalingX = true;
    inner.lockScalingY = true;
    inner.lockRotation = true;

    const border = getFrameBorder(frameGroup);
    if (border) border.set({ stroke:"#0d6efd", strokeDashArray:null });

    inner.on('moving', constrainInnerToClip);

    canvas.setActiveObject(inner);
    canvas.requestRenderAll();
    updateFloatbar();
  }

  function exitFrameEdit(apply=true){
    if (!frameEditMode) return;

    const frameGroup = frameEditTarget;
    const inner = frameEditInner;

    if (!apply && inner && frameEditInnerStart) inner.set(frameEditInnerStart);

    if (frameGroup) {
      frameGroup.selectable = true;
      frameGroup.evented = true;
      const border = getFrameBorder(frameGroup);
      if (border) border.set({ stroke:"#94a3b8", strokeDashArray:[7,6] });
    }

    if (inner) {
      inner.off('moving', constrainInnerToClip);
      inner.selectable = false;
      inner.evented = false;
      inner.lockScalingX = false;
      inner.lockScalingY = false;
      inner.lockRotation = false;
    }

    if (frameGroup) canvas.setActiveObject(frameGroup);

    frameEditMode = false;
    frameEditTarget = null;
    frameEditInner = null;
    frameEditInnerStart = null;

    canvas.requestRenderAll();
    scheduleHistory();
    renderLayers();
    refreshPanel();
    updateFloatbar();
  }

  // Duplo clique: se clicar num frame, entra edição
  canvas.on('mouse:dblclick', (opt) => {
    const t = opt.target;
    if (!t) return;
    if (t.isFrame) {
      enterFrameEdit(t);
      return;
    }
    const active = canvas.getActiveObject();
    if (active && active.isFrame) enterFrameEdit(active);
  });

  // ============================================================
  // Clipboard simples
  // ============================================================
  let clipboard = null;
  function copy(){
    const o=canvas.getActiveObject();
    if(!o || o.type === 'activeSelection') return;
    o.clone((cl)=>{ clipboard = cl; });
  }
  function paste(){
    if(!clipboard) return;
    clipboard.clone((cl)=>{
      cl.set({ left:(cl.left||0)+18, top:(cl.top||0)+18 });
      cl.id = (cl.id ? cl.id + '_copy' : 'copy_' + Date.now());
      canvas.add(cl);
      canvas.setActiveObject(cl);
      canvas.renderAll();
      renderLayers();
      refreshPanel();
      scheduleHistory();
      updateFloatbar();
    });
  }

  // ============================================================
  // Teclado (Canva)
  // ============================================================
  document.addEventListener('keydown', (e) => {
    const key = e.key.toLowerCase();
    const ctrl = e.ctrlKey || e.metaKey;
    const shift = e.shiftKey;

    const tag = (document.activeElement?.tagName || '').toLowerCase();
    if (tag === 'input' || tag === 'textarea' || tag === 'select') return;

    // Frame edit
    if (frameEditMode) {
      if (e.key === 'Enter') { e.preventDefault(); exitFrameEdit(true); return; }
      if (e.key === 'Escape') { e.preventDefault(); exitFrameEdit(false); return; }
    }

    // Crop
    if (cropMode) {
      if (e.key === 'Escape') { e.preventDefault(); cancelCrop(); return; }
      if (e.key === 'Enter') { e.preventDefault(); applyCrop(); return; }
    }

    if (e.key === 'Delete') { e.preventDefault(); return removeActive(); }

    if (ctrl && key === 'z') { e.preventDefault(); return undo(); }
    if (ctrl && key === 'y') { e.preventDefault(); return redo(); }

    if (ctrl && key === 'd') { e.preventDefault(); return duplicateActive(); }
    if (ctrl && key === 'g' && !shift) { e.preventDefault(); return groupSel(); }
    if (ctrl && key === 'g' && shift) { e.preventDefault(); return ungroupSel(); }

    if (ctrl && key === 'c') { e.preventDefault(); return copy(); }
    if (ctrl && key === 'v') { e.preventDefault(); return paste(); }

    const o = canvas.getActiveObject();
    if (!o || o.type === 'activeSelection') return;

    const step = shift ? 10 : 1;
    if (e.key === 'ArrowLeft')  { e.preventDefault(); o.left -= step; canvas.renderAll(); scheduleHistory(); updateFloatbar(); }
    if (e.key === 'ArrowRight') { e.preventDefault(); o.left += step; canvas.renderAll(); scheduleHistory(); updateFloatbar(); }
    if (e.key === 'ArrowUp')    { e.preventDefault(); o.top  -= step; canvas.renderAll(); scheduleHistory(); updateFloatbar(); }
    if (e.key === 'ArrowDown')  { e.preventDefault(); o.top  += step; canvas.renderAll(); scheduleHistory(); updateFloatbar(); }
  });

  // ============================================================
  // Inicial
  // ============================================================
  renderTemplatesPanel();
  renderFramesLibrary();
  setTimeout(()=>{ refreshPanel(); renderLayers(); updateFloatbar(); }, 300);
</script>
</body>
</html>
