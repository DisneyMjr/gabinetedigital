<?php
session_start();
require 'config.php';
require 'protecao.php';

if (empty($_SESSION['tenant_id'])) { http_response_code(403); exit('Tenant inválido'); }

$design_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// fundos locais
$fundos = [
  'assets/fundos/cidade.jpg',
  'assets/fundos/bandeira.jpg',
  'assets/fundos/abstract1.jpg',
  'assets/fundos/abstract2.jpg',
];

// templates embutidos (rápido e prático)
// você pode migrar isso pra tabela design_templates depois
$templates = [
  [
    'tema' => 'Aniversário',
    'titulo' => 'Aniversário Azul',
    'json' => '{"version":"5.3.0","objects":[{"type":"textbox","left":60,"top":120,"width":420,"text":"NOME","fontSize":44,"fontWeight":"800","fill":"#1a2b49","textAlign":"center"},{"type":"textbox","left":60,"top":190,"width":420,"text":"PARABÉNS!\\nFELIZ ANIVERSÁRIO!","fontSize":46,"fontWeight":"900","fill":"#0d47a1","textAlign":"center"},{"type":"textbox","left":70,"top":310,"width":400,"text":"Mensagem aqui...","fontSize":18,"fontWeight":"600","fill":"#334155","textAlign":"center"},{"type":"rect","left":0,"top":448,"width":540,"height":92,"fill":"#1a2b49"}] }'
  ],
  [
    'tema' => 'Convite',
    'titulo' => 'Convite Clean',
    'json' => '{"version":"5.3.0","objects":[{"type":"rect","left":0,"top":0,"width":540,"height":540,"fill":"#ffffff"},{"type":"textbox","left":60,"top":110,"width":420,"text":"CONVITE","fontSize":54,"fontWeight":"900","fill":"#111827","textAlign":"center"},{"type":"textbox","left":70,"top":200,"width":400,"text":"Evento tal, dia tal, local tal.","fontSize":20,"fontWeight":"600","fill":"#334155","textAlign":"center"},{"type":"rect","left":80,"top":420,"width":380,"height":56,"fill":"#0d6efd","rx":14,"ry":14},{"type":"textbox","left":80,"top":432,"width":380,"text":"CONFIRME NO WHATSAPP","fontSize":18,"fontWeight":"800","fill":"#ffffff","textAlign":"center"}] }'
  ],
];
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Editor</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <script src="https://cdn.jsdelivr.net/npm/fabric@5.3.0/dist/fabric.min.js"></script>

  <style>
    :root { --side: 390px; }
    body { height:100vh; overflow:hidden; background:#eef1f5; }
    .wrap{display:flex;height:100vh;}
    .side{ width:var(--side); background:#fff; border-right:1px solid #e5e7eb; overflow:auto; padding:14px; }
    .main{ flex:1; display:flex; align-items:center; justify-content:center; background:linear-gradient(180deg,#dde3ea,#cfd8e3); }
    .stage{ background:#fff; border-radius:18px; box-shadow:0 20px 60px rgba(0,0,0,.18); padding:12px; }
    canvas{ border-radius:12px; }
    .form-label{ font-size:.78rem; letter-spacing:.04em; font-weight:800; color:#334155; }
    .small-muted{ font-size:.82rem; color:#64748b; }
    .thumb{ height:56px; border-radius:10px; background-size:cover; background-position:center; border:2px solid transparent; cursor:pointer; }
    .thumb.active{ border-color:#0d6efd; box-shadow:0 0 0 4px rgba(13,110,253,.15); }
    .hr{ height:1px; background:#e5e7eb; margin:12px 0; }
    .template-item{ border:1px solid #e5e7eb; border-radius:12px; padding:10px; cursor:pointer; }
    .template-item:hover{ border-color:#0d6efd; background:#eff6ff; }
  </style>
</head>
<body>
<div class="wrap">
  <aside class="side">
    <div class="d-flex align-items-center justify-content-between">
      <div class="fw-bold"><i class="fa-solid fa-swatchbook text-primary me-2"></i>Editor</div>
      <a class="btn btn-sm btn-outline-secondary" href="admin_artes.php">Voltar</a>
    </div>

    <div class="row g-2 mt-2">
      <div class="col-7">
        <input id="title" class="form-control" placeholder="Título da arte" />
      </div>
      <div class="col-5">
        <button class="btn btn-primary w-100" onclick="saveToDB()"><i class="fa-regular fa-floppy-disk me-2"></i>Salvar</button>
      </div>
    </div>

    <div class="row g-2 mt-2">
      <div class="col-6">
        <button class="btn btn-outline-primary w-100" onclick="addText()"><i class="fa-solid fa-font me-2"></i>Texto</button>
      </div>
      <div class="col-6">
        <label class="btn btn-outline-primary w-100 mb-0">
          <i class="fa-solid fa-image me-2"></i>Imagem
          <input id="fileImg" type="file" accept="image/*" hidden>
        </label>
      </div>
    </div>

    <div class="row g-2 mt-2">
      <div class="col-6"><button class="btn btn-outline-secondary w-100" onclick="addRect()">Retângulo</button></div>
      <div class="col-6"><button class="btn btn-outline-secondary w-100" onclick="addCircle()">Círculo</button></div>
    </div>

    <div class="hr"></div>

    <div class="d-flex justify-content-between align-items-end mb-2">
      <label class="form-label mb-0">FUNDO</label>
      <button class="btn btn-sm btn-outline-secondary" onclick="setBgColor('#ffffff')">Branco</button>
    </div>
    <div class="row g-2 mb-2">
      <?php foreach($fundos as $i=>$f): ?>
        <div class="col-6">
          <div class="thumb <?= $i===0?'active':'' ?>" style="background-image:url('<?= htmlspecialchars($f) ?>')" onclick="setBgImage(this,'<?= htmlspecialchars($f) ?>')"></div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="hr"></div>

    <div class="d-flex align-items-center justify-content-between mb-2">
      <div class="form-label mb-0">RECORTE (IMAGEM)</div>
      <div class="small-muted">duplo clique na imagem</div>
    </div>
    <div class="row g-2 mb-2">
      <div class="col-6"><button class="btn btn-outline-secondary w-100" onclick="applyCrop()">Aplicar</button></div>
      <div class="col-6"><button class="btn btn-outline-secondary w-100" onclick="cancelCrop()">Cancelar</button></div>
    </div>
    <div class="small-muted">No modo recorte aparece um retângulo: arraste/redimensione e aplique.</div>

    <div class="hr"></div>

    <div class="form-label mb-2">TEMPLATES (por tema)</div>
    <div class="d-grid gap-2">
      <?php foreach($templates as $t): ?>
        <div class="template-item" onclick="applyTemplate(<?= htmlspecialchars(json_encode($t['json'])) ?>, <?= htmlspecialchars(json_encode($t['titulo'])) ?>)">
          <div class="fw-semibold"><?= htmlspecialchars($t['titulo']) ?></div>
          <div class="small-muted"><?= htmlspecialchars($t['tema']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="hr"></div>

    <div class="row g-2">
      <div class="col-6"><button class="btn btn-outline-primary w-100" onclick="exportPNG(2)">Export PNG 2x</button></div>
      <div class="col-6"><button class="btn btn-outline-primary w-100" onclick="exportPNG(3)">PNG 3x</button></div>
    </div>

    <input type="hidden" id="designId" value="<?= (int)$design_id ?>">
  </aside>

  <main class="main">
    <div class="stage"><canvas id="c" width="540" height="540"></canvas></div>
  </main>
</div>

<script>
  const canvas = new fabric.Canvas('c', { preserveObjectStacking:true });
  const DESIGN_ID = parseInt(document.getElementById('designId').value || '0', 10);

  // ---------------- BG
  function setBgColor(color){
    canvas.setBackgroundImage(null, canvas.renderAll.bind(canvas));
    canvas.setBackgroundColor(color, canvas.renderAll.bind(canvas));
    document.querySelectorAll('.thumb').forEach(t => t.classList.remove('active'));
  }
  function setBgImage(el, url){
    if (el) {
      document.querySelectorAll('.thumb').forEach(t => t.classList.remove('active'));
      el.classList.add('active');
    }
    canvas.setBackgroundColor('#ffffff', canvas.renderAll.bind(canvas));
    fabric.Image.fromURL(url, (img) => {
      const scale = Math.max(540/img.width, 540/img.height);
      img.set({ left:0, top:0, originX:'left', originY:'top', selectable:false, evented:false });
      img.scale(scale);
      canvas.setBackgroundImage(img, canvas.renderAll.bind(canvas));
    }, { crossOrigin:'anonymous' });
  }
  setBgImage(document.querySelector('.thumb.active'), document.querySelector('.thumb.active') ? getBgUrl(document.querySelector('.thumb.active')) : '');
  function getBgUrl(el){
    const m = (el.getAttribute('style')||'').match(/url\('?(.*?)'?\)/i);
    return m ? m[1] : '';
  }

  // ---------------- add objects
  function addText(){
    const tb = new fabric.Textbox('Seu texto', { left:80, top:90, width:380, fontSize:36, fontWeight:'800', fill:'#111827', textAlign:'center' });
    canvas.add(tb); canvas.setActiveObject(tb); canvas.renderAll();
  }
  function addRect(){
    const r = new fabric.Rect({ left:120, top:150, width:300, height:160, fill:'#0d6efd', rx:18, ry:18 });
    canvas.add(r); canvas.setActiveObject(r); canvas.renderAll();
  }
  function addCircle(){
    const c = new fabric.Circle({ left:180, top:170, radius:90, fill:'#6610f2' });
    canvas.add(c); canvas.setActiveObject(c); canvas.renderAll();
  }

  // upload image
  document.getElementById('fileImg').addEventListener('change', (ev) => {
    const file = ev.target.files?.[0]; if (!file) return;
    const reader = new FileReader();
    reader.onload = () => {
      fabric.Image.fromURL(reader.result, (img) => {
        img.set({ left:120, top:120 });
        const maxW=360, maxH=280;
        const scale = Math.min(maxW/img.width, maxH/img.height, 1);
        img.scale(scale);
        canvas.add(img);
        canvas.setActiveObject(img);
        canvas.renderAll();
      }, { crossOrigin:'anonymous' });
    };
    reader.readAsDataURL(file);
    ev.target.value = '';
  });

  // ---------------- TEMPLATE
  function applyTemplate(jsonStr, title){
    const json = JSON.parse(jsonStr);

    // limpa objetos (não mexe no background)
    canvas.getObjects().forEach(o => canvas.remove(o));

    canvas.loadFromJSON(json, () => {
      canvas.renderAll();
      document.getElementById('title').value = title;
    });
  }

  // ---------------- CROP (double click image)
  let cropMode = false;
  let cropTarget = null;
  let cropBox = null;

  function isImage(o){ return o && o.type === 'image'; }

  canvas.on('mouse:dblclick', (opt) => {
    const t = opt.target;
    if (!isImage(t)) return;
    enterCrop(t);
  });

  function enterCrop(img){
    cancelCrop();
    cropMode = true;
    cropTarget = img;

    // trava seleção geral e foca no cropBox
    canvas.discardActiveObject();

    // cria um retângulo no mesmo tamanho visível da imagem (em coords do canvas)
    const br = img.getBoundingRect(true, true);
    cropBox = new fabric.Rect({
      left: br.left, top: br.top,
      width: br.width, height: br.height,
      fill: 'rgba(13,110,253,0.08)',
      stroke: '#0d6efd',
      strokeWidth: 2,
      strokeDashArray: [8,6],
      hasRotatingPoint: false
    });

    // cropBox deve ficar acima
    canvas.add(cropBox);
    canvas.setActiveObject(cropBox);
    canvas.renderAll();
  }

  function cancelCrop(){
    cropMode = false;
    cropTarget = null;
    if (cropBox) {
      canvas.remove(cropBox);
      cropBox = null;
      canvas.renderAll();
    }
  }

  function applyCrop(){
    if (!cropMode || !cropTarget || !cropBox) return;

    // converte cropBox (canvas coords) -> espaço da imagem
    // 1) pega transformação invertida da imagem
    const img = cropTarget;

    // bounding rect do cropBox em coords do canvas
    const cb = cropBox.getBoundingRect(true, true);

    // matriz inversa da imagem
    const inv = fabric.util.invertTransform(img.calcTransformMatrix());

    // pontos do cropBox (top-left e bottom-right) transformados para espaço da imagem
    const p1 = fabric.util.transformPoint(new fabric.Point(cb.left, cb.top), inv);
    const p2 = fabric.util.transformPoint(new fabric.Point(cb.left + cb.width, cb.top + cb.height), inv);

    // clamp dentro da imagem original
    const ox = Math.max(0, Math.min(img._originalElement ? img._originalElement.width : img.width, p1.x));
    const oy = Math.max(0, Math.min(img._originalElement ? img._originalElement.height : img.height, p1.y));
    const ow = Math.max(1, Math.min((img._originalElement ? img._originalElement.width : img.width) - ox, (p2.x - p1.x)));
    const oh = Math.max(1, Math.min((img._originalElement ? img._originalElement.height : img.height) - oy, (p2.y - p1.y)));

    // aplica crop (Fabric usa cropX/cropY/width/height)
    img.set({
      cropX: ox,
      cropY: oy,
      width: ow,
      height: oh
    });

    // mantém o tamanho visual: reescala baseado no tamanho antes do crop
    // (usa a largura/altura visível do cropBox como referência)
    const scaleX = cb.width / ow;
    const scaleY = cb.height / oh;

    img.set({
      scaleX,
      scaleY,
      left: cb.left,
      top: cb.top
    });

    cancelCrop();
    canvas.setActiveObject(img);
    canvas.renderAll();
  }

  // ---------------- SAVE DB (json + thumb + opcional imagem final)
  async function saveToDB(){
    const thumb = canvas.toDataURL({ format:'png', multiplier: 0.5 });
    const image = canvas.toDataURL({ format:'png', multiplier: 2 });
    const json  = JSON.stringify(canvas.toJSON(['id','cropX','cropY','width','height']));
    
    const body = new URLSearchParams();
    body.set('design_id', designId);
    body.set('title', title);
    body.set('json', json);
    body.set('thumb_base64', thumb);
    body.set('image_base64', image);
    
    const r = await fetch('save_design.php', {
      method:'POST',
      headers: { 'Content-Type':'application/x-www-form-urlencoded' },
      body
    }).then(r => r.json());


    if (r.ok) {
      document.getElementById('designId').value = r.design_id;
      history.replaceState(null,'', 'designer_editor.php?id=' + r.design_id);
      alert('Salvo!');
    } else {
      alert('Erro ao salvar: ' + (r.err || ''));
    }
  }

  // ---------------- LOAD design by id
  async function loadDesign(id){
    const r = await fetch('load_design.php?id=' + id).then(r=>r.json());
    if (!r.ok) return;
    document.getElementById('title').value = r.titulo || '';
    const json = JSON.parse(r.json);
    canvas.loadFromJSON(json, () => canvas.renderAll());
  }

  if (DESIGN_ID > 0) loadDesign(DESIGN_ID);
  else document.getElementById('title').value = 'Nova arte';

  // export
  function exportPNG(mult=2){
    canvas.discardActiveObject();
    canvas.renderAll();
    const dataUrl = canvas.toDataURL({ format:'png', multiplier: mult });
    const a = document.createElement('a');
    a.href = dataUrl;
    a.download = `arte_${mult}x.png`;
    a.click();
  }
</script>
</body>
</html>
