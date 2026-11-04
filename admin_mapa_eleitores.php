<?php
require 'config.php';
require 'protecao.php';

// =======================================================
// AUTO-MIGRATION (cria tabelas se não existirem)
// =======================================================
try {
    $pdo->query("CREATE TABLE IF NOT EXISTS setores_mapa (
      id INT AUTO_INCREMENT PRIMARY KEY,
      tenant_id INT NOT NULL,
      nome VARCHAR(120) NOT NULL,
      cor VARCHAR(20) DEFAULT '#0d6efd',
      meta_votos INT DEFAULT 0,
      geojson LONGTEXT NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL,
      INDEX (tenant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->query("CREATE TABLE IF NOT EXISTS setores_mapa_logs (
      id INT AUTO_INCREMENT PRIMARY KEY,
      tenant_id INT NOT NULL,
      setor_id INT NULL,
      acao VARCHAR(50) NOT NULL,
      detalhes VARCHAR(255) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX (tenant_id),
      INDEX (setor_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    // se falhar, não derruba o sistema
}

if (!defined('TENANT_ID')) die("Erro de permissão.");
if (function_exists('tem_acesso') && !tem_acesso('eleitores') && !tem_acesso('master')) die("Acesso Negado.");

// =======================================================
// HELPERS
// =======================================================
function j($arr) { return json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
function clean_int($v) { return (is_numeric($v) ? (int)$v : 0); }
function clean_str($v) { return trim((string)$v); }

function log_setor($pdo, $tenant_id, $setor_id, $acao, $detalhes = null) {
    try {
        $pdo->prepare("INSERT INTO setores_mapa_logs (tenant_id, setor_id, acao, detalhes) VALUES (?,?,?,?)")
            ->execute([(int)$tenant_id, $setor_id ? (int)$setor_id : null, $acao, $detalhes]);
    } catch(Exception $e) {}
}

// =======================================================
// 0) ENDPOINTS AJAX (no mesmo arquivo) - ULTRA
// =======================================================
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action) {
    header('Content-Type: application/json; charset=utf-8');

    // --- SEGURANÇA BÁSICA ---
    if (!isset($pdo)) { http_response_code(500); echo j(['ok'=>false,'msg'=>'PDO não disponível']); exit; }

    // 0.1) LISTAR SETORES
    if ($action === 'listar_setores') {
        try {
            $st = $pdo->prepare("SELECT id, nome, cor, meta_votos, geojson FROM setores_mapa WHERE tenant_id = ? ORDER BY id DESC");
            $st->execute([TENANT_ID]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            // devolve geojson já decodificado para o front
            foreach ($rows as &$r) {
                $r['geojson'] = json_decode($r['geojson'], true);
            }
            echo j(['ok'=>true,'setores'=>$rows]);
            exit;
        } catch(Exception $e) {
            http_response_code(500);
            echo j(['ok'=>false,'msg'=>$e->getMessage()]);
            exit;
        }
    }

    // 0.2) SALVAR/CRIAR SETOR
    if ($action === 'salvar_setor') {
        $id   = clean_int($_POST['id'] ?? 0);
        $nome = clean_str($_POST['nome'] ?? '');
        $cor  = clean_str($_POST['cor'] ?? '#0d6efd');
        $meta = clean_int($_POST['meta_votos'] ?? 0);
        $geo  = $_POST['geojson'] ?? '';

        if ($nome === '' || $geo === '') {
            http_response_code(400);
            echo j(['ok'=>false,'msg'=>'Nome e GeoJSON são obrigatórios']);
            exit;
        }

        // valida JSON
        $decoded = json_decode($geo, true);
        if (!$decoded || !is_array($decoded)) {
            http_response_code(400);
            echo j(['ok'=>false,'msg'=>'GeoJSON inválido']);
            exit;
        }

        try {
            if ($id > 0) {
                $st = $pdo->prepare("UPDATE setores_mapa SET nome=?, cor=?, meta_votos=?, geojson=?, updated_at=NOW() WHERE id=? AND tenant_id=?");
                $st->execute([$nome, $cor, $meta, $geo, $id, TENANT_ID]);
                log_setor($pdo, TENANT_ID, $id, 'update', "Atualizou setor: $nome");
                echo j(['ok'=>true,'id'=>$id]);
                exit;
            } else {
                $st = $pdo->prepare("INSERT INTO setores_mapa (tenant_id, nome, cor, meta_votos, geojson) VALUES (?,?,?,?,?)");
                $st->execute([TENANT_ID, $nome, $cor, $meta, $geo]);
                $newId = (int)$pdo->lastInsertId();
                log_setor($pdo, TENANT_ID, $newId, 'create', "Criou setor: $nome");
                echo j(['ok'=>true,'id'=>$newId]);
                exit;
            }
        } catch(Exception $e) {
            http_response_code(500);
            echo j(['ok'=>false,'msg'=>$e->getMessage()]);
            exit;
        }
    }

    // 0.3) EXCLUIR SETOR
    if ($action === 'excluir_setor') {
        $id = clean_int($_POST['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo j(['ok'=>false,'msg'=>'ID inválido']); exit; }

        try {
            $pdo->prepare("DELETE FROM setores_mapa WHERE id=? AND tenant_id=?")->execute([$id, TENANT_ID]);
            log_setor($pdo, TENANT_ID, $id, 'delete', "Excluiu setor #$id");
            echo j(['ok'=>true]);
            exit;
        } catch(Exception $e) {
            http_response_code(500);
            echo j(['ok'=>false,'msg'=>$e->getMessage()]);
            exit;
        }
    }

    // 0.4) CONTAR ELEITORES DENTRO DE CADA SETOR (para painel)
    if ($action === 'listar_pontos') {
        // filtros
        $bairro       = clean_str($_GET['bairro'] ?? '');
        $status       = clean_str($_GET['status'] ?? '');
        $lider        = clean_str($_GET['lider'] ?? '');
        $tag          = clean_str($_GET['tag'] ?? '');
        $peticao_tag  = clean_str($_GET['peticao_tag'] ?? ''); 

        $where = "e.tenant_id = ?
                  AND e.latitude IS NOT NULL AND e.latitude != ''
                  AND e.longitude IS NOT NULL AND e.longitude != ''";
        $params = [TENANT_ID];

        if ($bairro !== '') { $where .= " AND e.bairro = ?"; $params[] = $bairro; }
        if ($status !== '' && ctype_digit($status)) { $where .= " AND e.status_voto = ?"; $params[] = (int)$status; }
        if ($lider !== '' && ctype_digit($lider)) { $where .= " AND e.responsavel_id = ?"; $params[] = (int)$lider; }
        if ($tag !== '') { $where .= " AND e.tags LIKE ?"; $params[] = '%'.$tag.'%'; }
        if ($peticao_tag !== '') { $where .= " AND e.tags LIKE ?"; $params[] = '%'.$peticao_tag.'%'; }

        try {
            $sql = "SELECT
                        e.id, e.nome, e.bairro, e.whatsapp, e.status_voto,
                        e.latitude, e.longitude, e.tags, e.responsavel_id,
                        l.nome AS nome_lider
                    FROM eleitores e
                    LEFT JOIN eleitores l ON l.id = e.responsavel_id AND l.tenant_id = e.tenant_id
                    WHERE $where
                    LIMIT 7000";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $pontos = $st->fetchAll(PDO::FETCH_ASSOC);
            echo j(['ok'=>true,'pontos'=>$pontos]);
            exit;
        } catch(Exception $e) {
            http_response_code(500);
            echo j(['ok'=>false,'msg'=>$e->getMessage()]);
            exit;
        }
    }

    http_response_code(404);
    echo j(['ok'=>false,'msg'=>'Ação não encontrada']);
    exit;
}

// =======================================================
// 1) DADOS INICIAIS (centro mapa, líderes, configs, causas)
// =======================================================

// Lógica corrigida para carregar as coordenadas das configurações
$lat_final = -15.7801;
$lng_final = -47.9292;
$zoom_final = 13; 

try {
    $stmtConf = $pdo->prepare("SELECT * FROM configuracoes WHERE tenant_id = ?");
    $stmtConf->execute([TENANT_ID]);
    $conf = $stmtConf->fetch(PDO::FETCH_ASSOC);

    if ($conf) {
        $uf = '';
        // 1. Tenta definir pelo estado primeiro
        if (!empty($conf['mapa_estado'])) {
            $estados_coord = [
                'AC' => [-8.77, -70.55], 'AL' => [-9.71, -35.73], 'AM' => [-3.07, -61.66],
                'AP' => [1.41, -51.77],  'BA' => [-12.96, -38.51], 'CE' => [-5.20, -39.53],
                'DF' => [-15.83, -47.86], 'ES' => [-19.19, -40.34], 'GO' => [-15.98, -49.86],
                'MA' => [-5.42, -45.44], 'MG' => [-18.10, -44.38], 'MS' => [-20.51, -54.54],
                'MT' => [-12.64, -55.42], 'PA' => [-3.79, -52.48],  'PB' => [-7.28, -36.72],
                'PE' => [-8.38, -37.86], 'PI' => [-7.69, -42.08],  'PR' => [-24.89, -51.55],
                'RJ' => [-22.84, -43.15], 'RN' => [-5.81, -36.59],  'RO' => [-10.83, -63.34],
                'RR' => [1.99, -61.33],  'RS' => [-30.17, -53.50], 'SC' => [-27.45, -50.95],
                'SE' => [-10.57, -37.45], 'SP' => [-23.55, -46.64], 'TO' => [-10.25, -48.25]
            ];
            $uf = strtoupper(trim($conf['mapa_estado']));
            if(isset($estados_coord[$uf])) {
                $lat_final = $estados_coord[$uf][0];
                $lng_final = $estados_coord[$uf][1];
                $zoom_final = ($uf === 'DF') ? 10 : 7; // Ajuste de zoom específico para o DF (pois é menor)
            }
        }

        // 2. Sobrescreve com a cidade (MAS SÓ SE NÃO FOR O DEFAULT DE SP)
        if (!empty($conf['mapa_lat']) && !empty($conf['mapa_lng'])) {
            $valLat = (float) str_replace(',', '.', $conf['mapa_lat']);
            $valLng = (float) str_replace(',', '.', $conf['mapa_lng']);
            
            // TRAVA: O sistema injeta -23.55 por padrão quando cria a conta. Temos que ignorar isso se o cara escolheu um estado.
            $is_sp_preso = (round($valLat, 2) == -23.55 && round($valLng, 2) == -46.63);
            
            // Verifica se no select (mapa_tipo) ele escolheu "estado" em vez de cidade
            $is_modo_estado = (isset($conf['mapa_tipo']) && $conf['mapa_tipo'] === 'estado');
            
            if ($valLat != 0 && $valLng != 0 && !$is_sp_preso && !$is_modo_estado) {
                $lat_final = $valLat;
                $lng_final = $valLng;
                $zoom_final = 14; 
            }
        }
    }
} catch (Exception $e) {}


$stmt_lideres = $pdo->prepare("SELECT id, nome FROM eleitores WHERE tenant_id = ? AND is_lider = 1 ORDER BY nome ASC");
$stmt_lideres->execute([TENANT_ID]);
$lista_lideres = $stmt_lideres->fetchAll(PDO::FETCH_ASSOC);

// Busca a lista de petições ativas do cliente para popular o filtro do mapa
$stmt_peticoes = $pdo->prepare("SELECT titulo, tag_destino FROM peticoes WHERE tenant_id = ? ORDER BY id DESC");
$stmt_peticoes->execute([TENANT_ID]);
$lista_peticoes = $stmt_peticoes->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Geo Votos ULTRA (Setores + Metas + Heat + Cluster)</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster/dist/MarkerCluster.css" />
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster/dist/MarkerCluster.Default.css" />
<link rel="stylesheet" href="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css" />

<style>
    body, html { height: 100%; margin: 0; overflow: hidden; background: #f5f6f8; }
    #map { height: 100%; width: 100%; }

    .panel {
        position: absolute; top: 12px; left: 12px; z-index: 1000;
        width: 390px; max-width: calc(100vw - 24px);
        background: #fff; border-radius: 16px; box-shadow: 0 12px 30px rgba(0,0,0,.12);
        overflow: hidden;
    }
    .panel .head {
        padding: 14px 16px; background: #0d6efd; color: #fff;
        display: flex; align-items: center; justify-content: space-between;
    }
    .panel .body { padding: 14px 16px; max-height: calc(100vh - 110px); overflow: auto; }

    .pill {
        display:inline-flex; align-items:center; gap:8px;
        padding: 8px 10px; border-radius: 999px; background: rgba(255,255,255,.15);
        font-weight: 800; font-size: 12px;
    }

    .tools {
        position:absolute; right:12px; top:12px; z-index: 1000;
        display:flex; gap: 8px; flex-wrap: wrap;
    }
    .btn-tool {
        background:#fff; border: 0; border-radius: 14px; padding: 10px 12px;
        box-shadow: 0 10px 22px rgba(0,0,0,.12);
        font-weight: 900; font-size: 12px;
    }
    .btn-tool:hover { transform: translateY(-1px); }

    .legend {
        display:grid; gap: 6px;
        background:#f8f9fa; border: 1px solid #eee; border-radius: 14px; padding: 10px;
    }
    .legend-item { display:flex; align-items:center; gap: 10px; font-size: 13px; }
    .dot { width: 12px; height: 12px; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 0 2px rgba(0,0,0,.3); }
    .d1{ background:#ffc107; } .d2{ background:#0dcaf0; } .d3{ background:#198754; } .d4 { background:#dc3545;} 

    .setor-card {
        border: 1px solid #eee;
        border-radius: 14px;
        padding: 10px;
        background: #fff;
    }
    .mini { font-size: 12px; }
    .kpi {
        background: #0b1220;
        color: #fff;
        border-radius: 14px;
        padding: 10px;
        display:flex; justify-content: space-between; align-items: center;
    }
    .kpi b { font-size: 18px; }
    
    .leaflet-top, .leaflet-bottom { z-index: 2000 !important; }

    @media print {
        .panel, .tools { display:none !important; }
        #map { height: 100vh !important; }
    }
</style>
</head>

<body>
<div id="map"></div>

<!-- Painel -->
<div class="panel">
    <div class="head">
        <div>
            <div class="fw-bold">🗺️ Geo Votos ULTRA</div>
            <small style="opacity:.85">Setores + Metas + Heatmap + Cluster</small>
        </div>
        <div class="pill">Visíveis: <span id="kpiVisible">0</span></div>
    </div>

    <div class="body">
        <div class="kpi mb-3">
            <div>
                <div class="mini" style="opacity:.8">Total carregado</div>
                <b id="kpiTotal">0</b>
            </div>
            <div class="text-end">
                <div class="mini" style="opacity:.8">No mapa (viewport)</div>
                <b id="kpiView">0</b>
            </div>
        </div>

        <form id="formFiltros" class="row g-2 mb-3">
            <div class="col-12">
                <label class="mini fw-bold text-danger">Abaixo-Assinados (Mapear Causa)</label>
                <select name="peticao_tag" class="form-select form-select-sm border-danger">
                    <option value="">-- Todas as origens --</option>
                    <?php foreach($lista_peticoes as $p): ?>
                        <option value="<?= htmlspecialchars($p['tag_destino']) ?>"><?= htmlspecialchars($p['titulo']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12">
                <label class="mini fw-bold mt-1">Bairro</label>
                <input type="text" name="bairro" class="form-control form-control-sm" placeholder="Ex: Centro">
            </div>

            <div class="col-6">
                <label class="mini fw-bold">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    <option value="1">Indeciso</option>
                    <option value="2">Provável</option>
                    <option value="3">Voto certo</option>
                </select>
            </div>

            <div class="col-6">
                <label class="mini fw-bold">Líder</label>
                <select name="lider" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    <?php foreach($lista_lideres as $l): ?>
                        <option value="<?= (int)$l['id'] ?>"><?= htmlspecialchars($l['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12">
                <label class="mini fw-bold">Tag (busca livre)</label>
                <input type="text" name="tag" class="form-control form-control-sm" placeholder="igreja, saúde, professor...">
            </div>

            <div class="col-12">
                <label class="mini fw-bold">Modo de Visualização</label>
                <select id="modo" class="form-select form-select-sm border-primary fw-bold">
                    <option value="markers">Marcadores (Cluster)</option>
                    <option value="heat_status">Heatmap (por Status de Voto)</option>
                    <option value="heat_lider">Heatmap (por Líder/Indicação)</option>
                    <option value="heat_peticao" class="text-danger">🔥 Heatmap (Apoio a Causas / Petição)</option>
                </select>
            </div>

            <div class="col-12 d-grid gap-2 mt-2">
                <button class="btn btn-primary btn-sm fw-bold" type="submit"><i class="fas fa-filter me-1"></i> Aplicar Filtros</button>
                <button class="btn btn-outline-secondary btn-sm" type="button" onclick="limparFiltros()">Limpar</button>
                <a class="btn btn-link btn-sm text-muted text-decoration-none" href="admin_painel.php">← Voltar ao Painel</a>
            </div>
        </form>

        <div class="legend mb-3">
            <div class="fw-bold mini">Legenda dos Pinos</div>
            <div class="legend-item"><span class="dot d1"></span> Indeciso / Frio</div>
            <div class="legend-item"><span class="dot d2"></span> Apoio provável / Morno</div>
            <div class="legend-item"><span class="dot d3"></span> Voto certo / Quente</div>
            <div class="legend-item"><span class="dot d4"></span> <b>Apoio via Abaixo-Assinado</b></div>
        </div>

        <div class="d-flex align-items-center justify-content-between mb-2">
            <div class="fw-bold">📌 Setores (Áreas)</div>
            <button class="btn btn-sm btn-dark fw-bold" type="button" onclick="modoDesenho()">✏️ Desenhar setor</button>
        </div>

        <div id="listaSetores" class="d-grid gap-2"></div>

        <div class="mt-3 small text-muted">
            Dica: desenhe um polígono no mapa → salve com nome, cor e meta.
        </div>
    </div>
</div>

<!-- Tools -->
<div class="tools">
    <button class="btn-tool" onclick="centralizar()">🎯 Centralizar</button>
    <button class="btn-tool" onclick="minhaLocalizacao()">📍 Minha localização</button>
    <button class="btn-tool" onclick="exportarPDF()">🖨️ PDF</button>
    <button class="btn-tool" onclick="toggleSetores()">🧩 Setores</button>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet.markercluster/dist/leaflet.markercluster.js"></script>
<script src="https://unpkg.com/leaflet.heat/dist/leaflet-heat.js"></script>

<script src="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js"></script>
<script src="https://unpkg.com/@turf/turf@6/turf.min.js"></script>

<script>
// USANDO AS VARIÁVEIS CALULADAS NO PHP CORRETAMENTE
const centro = [<?= json_encode($lat_final) ?>, <?= json_encode($lng_final) ?>];
const map = L.map('map', { preferCanvas: true }).setView(centro, <?= json_encode($zoom_final) ?>);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
  attribution: '© OpenStreetMap'
}).addTo(map);

// =====================
// ESTADO GLOBAL
// =====================
let pontos = [];
let setores = [];
let camadaSetores = L.featureGroup().addTo(map);
let setoresVisiveis = true;

const cluster = L.markerClusterGroup({
  chunkedLoading: true,
  spiderfyOnMaxZoom: true,
  showCoverageOnHover: false,
});

let heat = null;

// draw
const drawnItems = new L.FeatureGroup();
map.addLayer(drawnItems);

const drawControl = new L.Control.Draw({
  position: 'topright', // <<< AQUI
  edit: { featureGroup: drawnItems, edit: true, remove: true },
  draw: {
    polygon: true,
    rectangle: true,
    circle: false,
    marker: false,
    circlemarker: false,
    polyline: false
  }
});
map.addControl(drawControl);

let drawerPolygon = new L.Draw.Polygon(map, drawControl.options.draw.polygon);
let drawerRect    = new L.Draw.Rectangle(map, drawControl.options.draw.rectangle);

function iniciarDesenho(tipo = 'polygon') {
  try { drawerPolygon.disable(); } catch(e){}
  try { drawerRect.disable(); } catch(e){}

  if (tipo === 'rect') drawerRect.enable();
  else drawerPolygon.enable();
}

// =====================
// HELPERS
// =====================
function corStatus(status){
  if (status == 3) return '#198754';
  if (status == 2) return '#0dcaf0';
  return '#ffc107';
}
function pesoHeatStatus(status){
  if (status == 3) return 0.95;
  if (status == 2) return 0.65;
  return 0.35;
}
function limparZap(z){ return (z || '').toString().replace(/\D/g,''); }

function toast(msg){
  alert(msg);
}

// =====================
// API (mesmo arquivo)
// =====================
async function api(action, data = {}, method = 'POST') {
  let opts = { method, headers: {} };
  let url = `admin_mapa_eleitores.php?action=${encodeURIComponent(action)}`;

  if (method === 'POST') {
    const fd = new FormData();
    for (const k in data) fd.append(k, data[k]);
    opts.body = fd;
  } else {
    const qs = new URLSearchParams(data).toString();
    url += '&' + qs;
  }

  const res = await fetch(url, opts);
  const json = await res.json().catch(()=>({ok:false,msg:'Resposta inválida'}));
  if (!res.ok) throw new Error(json.msg || 'Erro');
  return json;
}

// =====================
// CARREGAR DADOS
// =====================
async function carregarTudo() {
  await Promise.all([
    carregarPontos(),
    carregarSetores()
  ]);
  renderCamadas();
  renderSetoresUI();
  atualizarKPIs();
}

async function carregarPontos() {
  const form = document.getElementById('formFiltros');
  const data = Object.fromEntries(new FormData(form).entries());
  const resp = await api('listar_pontos', data, 'GET');
  pontos = resp.pontos || [];
  document.getElementById('kpiTotal').innerText = pontos.length;
}

async function carregarSetores() {
  const resp = await api('listar_setores', {}, 'GET');
  setores = resp.setores || [];
}

// =====================
// RENDER MAPA
// =====================
function limparCamadas(){
  try { map.removeLayer(cluster); } catch(e){}
  if (heat) { try { map.removeLayer(heat); } catch(e){} heat = null; }
  cluster.clearLayers();
}

function renderMarkers() {
  pontos.forEach(p => {
    if (!p.latitude || !p.longitude) return;
    const lat = parseFloat(p.latitude), lng = parseFloat(p.longitude);
    const isPeticao = String(p.tags || '').includes('Abaixo-Assinado'); 

    const marker = L.circleMarker([lat, lng], {
      radius: isPeticao ? 8 : 7,
      fillColor: isPeticao ? '#dc3545' : corStatus(p.status_voto),
      color: '#fff',
      weight: 2,
      fillOpacity: 0.95
    });

    const zap = limparZap(p.whatsapp);
    const btnZap = (zap.length >= 10)
      ? `<a href="https://wa.me/55${zap}" target="_blank" class="btn btn-success btn-sm mt-2 w-100">WhatsApp</a>`
      : `<div class="text-muted small mt-2">Sem WhatsApp</div>`;

    const btnRota = `<a href="https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}" target="_blank" class="btn btn-outline-dark btn-sm mt-2 w-100">📍 Rota</a>`;
    const lider = p.nome_lider
      ? `<div class="small text-primary fw-bold mt-2">👤 Líder: ${p.nome_lider}</div>`
      : `<div class="small text-muted mt-2">👤 Sem líder</div>`;

    const badgePeticao = isPeticao ? `<div class="bg-danger text-white text-center rounded py-1 mb-2 fw-bold" style="font-size:0.75rem;"><i class="fas fa-file-signature"></i> Captado via Abaixo-Assinado</div>` : '';
    
    let formatTags = String(p.tags || '').replace(/Abaixo-Assinado:/g, '').trim();
    const tagsInfo = formatTags 
      ? `<div class="mt-2"><span class="badge bg-light text-dark border">🏷️ ${formatTags.substring(0,120)}</span></div>`
      : '';

    marker.bindPopup(`
      <div style="min-width:220px">
        ${badgePeticao}
        <div class="fw-bold" style="font-size: 1.05rem;">${escapeHtml(p.nome)}</div>
        <div class="text-muted small">${escapeHtml(p.bairro) || ''}</div>
        ${lider}
        ${tagsInfo}
        ${btnZap}
        ${btnRota}
      </div>
    `);

    cluster.addLayer(marker);
  });

  cluster.addTo(map);
}

function renderHeatStatus(){
  const heatPoints = [];
  pontos.forEach(p => {
    if(!p.latitude || !p.longitude) return;
    heatPoints.push([parseFloat(p.latitude), parseFloat(p.longitude), pesoHeatStatus(p.status_voto)]);
  });
  heat = L.heatLayer(heatPoints, { radius: 22, blur: 18, maxZoom: 17 }).addTo(map);
}

function renderHeatLider(){
  const heatPoints = [];
  pontos.forEach(p => {
    if(!p.latitude || !p.longitude) return;
    const w = (p.responsavel_id && parseInt(p.responsavel_id) > 0) ? 0.75 : 0.35;
    heatPoints.push([parseFloat(p.latitude), parseFloat(p.longitude), w]);
  });
  heat = L.heatLayer(heatPoints, { radius: 24, blur: 18, maxZoom: 17 }).addTo(map);
}

function renderHeatPeticao(){
  const heatPoints = [];
  pontos.forEach(p => {
    if(!p.latitude || !p.longitude) return;
    if(String(p.tags || '').includes('Abaixo-Assinado')) {
        heatPoints.push([parseFloat(p.latitude), parseFloat(p.longitude), 1.0]);
    }
  });
  heat = L.heatLayer(heatPoints, { radius: 25, blur: 15, maxZoom: 17 }).addTo(map);
}

function renderSetoresCamada(){
  camadaSetores.clearLayers();
  drawnItems.clearLayers();

  setores.forEach(s => {
    const geo = s.geojson;
    if(!geo) return;

    const layer = L.geoJSON(geo, {
      style: {
        color: s.cor || '#0d6efd',
        weight: 2,
        fillOpacity: 0.12
      }
    });

    layer.eachLayer(l => {
      l.bindPopup(`
        <div style="min-width:220px">
          <div class="fw-bold">📌 ${escapeHtml(s.nome)}</div>
          <div class="small text-muted">Meta: <b>${s.meta_votos || 0}</b></div>
          <div class="small mt-2">Clique em "Editar" no painel para mudar.</div>
        </div>
      `);
    });

    camadaSetores.addLayer(layer);
  });

  if (setoresVisiveis) camadaSetores.addTo(map);
}

function renderCamadas(){
  limparCamadas();

  const modo = document.getElementById('modo').value;
  if (modo === 'heat_status') renderHeatStatus();
  else if (modo === 'heat_lider') renderHeatLider();
  else if (modo === 'heat_peticao') renderHeatPeticao(); 
  else renderMarkers();

  renderSetoresCamada();
  atualizarKPIs();
}

// =====================
// SETORES - CONTAGEM POR ÁREA (TURF)
// =====================
function contarDentroSetor(setorGeojson){
  try{
    const poly = setorGeojson;
    if(!poly) return {total:0, s1:0, s2:0, s3:0};

    let total=0, s1=0, s2=0, s3=0;
    pontos.forEach(p => {
      if(!p.latitude || !p.longitude) return;
      const pt = turf.point([parseFloat(p.longitude), parseFloat(p.latitude)]);
      let inside = false;

      if (poly.type === 'FeatureCollection') {
        for (const f of poly.features) {
          if (turf.booleanPointInPolygon(pt, f)) { inside = true; break; }
        }
      } else if (poly.type === 'Feature') {
        inside = turf.booleanPointInPolygon(pt, poly);
      } else {
        inside = turf.booleanPointInPolygon(pt, turf.feature(poly));
      }

      if(inside){
        total++;
        if(parseInt(p.status_voto)==3) s3++;
        else if(parseInt(p.status_voto)==2) s2++;
        else s1++;
      }
    });
    return {total, s1, s2, s3};
  } catch(e){
    return {total:0, s1:0, s2:0, s3:0};
  }
}

// =====================
// UI SETORES
// =====================
function renderSetoresUI(){
  const box = document.getElementById('listaSetores');
  box.innerHTML = '';

  if(!setores.length){
    box.innerHTML = `<div class="text-muted small">Nenhum setor salvo ainda. Clique em “Desenhar setor”.</div>`;
    return;
  }

  setores.forEach(s => {
    const c = contarDentroSetor(s.geojson);
    const meta = parseInt(s.meta_votos||0);
    const pct = (meta>0) ? Math.min(100, Math.round((c.s3/meta)*100)) : 0;

    const html = document.createElement('div');
    html.className = 'setor-card';

    html.innerHTML = `
      <div class="d-flex justify-content-between align-items-start gap-2">
        <div>
          <div class="fw-bold" style="display:flex;align-items:center;gap:8px;">
            <span style="width:10px;height:10px;border-radius:999px;background:${escapeHtml(s.cor||'#0d6efd')};display:inline-block"></span>
            ${escapeHtml(s.nome)}
          </div>
          <div class="small text-muted">Meta: <b>${meta}</b> • Dentro: <b>${c.total}</b></div>
        </div>
        <div class="d-flex gap-1">
          <button class="btn btn-sm btn-outline-dark" onclick="zoomSetor(${s.id})">Ver</button>
          <button class="btn btn-sm btn-outline-primary" onclick="editarSetor(${s.id})">Editar</button>
          <button class="btn btn-sm btn-outline-danger" onclick="excluirSetor(${s.id})">X</button>
        </div>
      </div>

      <div class="mt-2">
        <div class="small text-muted mb-1">Votos certos dentro do setor: <b>${c.s3}</b></div>
        <div class="progress" style="height: 10px;">
          <div class="progress-bar" style="width:${pct}%;"></div>
        </div>
      </div>

      <div class="d-flex gap-2 mt-2 small">
        <span class="badge bg-light text-dark border">🟠 ${c.s1}</span>
        <span class="badge bg-light text-dark border">🔵 ${c.s2}</span>
        <span class="badge bg-light text-dark border">🟢 ${c.s3}</span>
      </div>
    `;
    box.appendChild(html);
  });
}

function getSetorById(id){
  return setores.find(s => parseInt(s.id) === parseInt(id));
}

function zoomSetor(id){
  const s = getSetorById(id);
  if(!s || !s.geojson) return;
  const layer = L.geoJSON(s.geojson);
  map.fitBounds(layer.getBounds(), { padding: [30,30] });
}

// =====================
// DESENHO + SALVAR SETOR
// =====================
function modoDesenho(){
  const tipo = prompt("Digite: 1 = Polígono | 2 = Retângulo", "1");
  if (tipo === "2") iniciarDesenho('rect');
  else iniciarDesenho('polygon');
}

map.on(L.Draw.Event.CREATED, async function (event) {
  const layer = event.layer;
  drawnItems.addLayer(layer);

  const geo = layer.toGeoJSON();
  const fc = { type: "FeatureCollection", features: [geo] };

  const nome = prompt("Nome do Setor (ex: Setor A - Centro):", "Novo Setor");
  if (!nome) { drawnItems.removeLayer(layer); return; }

  const cor = prompt("Cor HEX do Setor (ex: #0d6efd):", "#0d6efd") || "#0d6efd";
  const meta = parseInt(prompt("Meta de votos certos (número):", "200") || "0");

  try {
    await api('salvar_setor', {
      id: 0,
      nome: nome,
      cor: cor,
      meta_votos: isNaN(meta) ? 0 : meta,
      geojson: JSON.stringify(fc)
    });

    toast("✅ Setor salvo!");
    await carregarSetores();
    renderSetoresCamada();
    renderSetoresUI();
  } catch(e) {
    toast("Erro ao salvar setor: " + e.message);
  } finally {
    drawnItems.removeLayer(layer);
  }
});

// =====================
// EDITAR SETOR (nome/cor/meta)
// =====================
async function editarSetor(id){
  const s = getSetorById(id);
  if(!s) return;

  const nome = prompt("Nome do Setor:", s.nome || '');
  if(!nome) return;

  const cor = prompt("Cor HEX:", s.cor || '#0d6efd') || (s.cor || '#0d6efd');
  const meta = parseInt(prompt("Meta de votos certos:", String(s.meta_votos || 0)) || "0");

  try {
    await api('salvar_setor', {
      id: s.id,
      nome: nome,
      cor: cor,
      meta_votos: isNaN(meta) ? 0 : meta,
      geojson: JSON.stringify(s.geojson)
    });
    toast("✅ Atualizado!");
    await carregarSetores();
    renderSetoresCamada();
    renderSetoresUI();
  } catch(e) {
    toast("Erro: " + e.message);
  }
}

async function excluirSetor(id){
  if(!confirm("Excluir este setor?")) return;
  try {
    await api('excluir_setor', { id: id });
    toast("✅ Excluído!");
    await carregarSetores();
    renderSetoresCamada();
    renderSetoresUI();
  } catch(e) {
    toast("Erro: " + e.message);
  }
}

// =====================
// TOOLS / AÇÕES
// =====================
function centralizar(){ map.setView(centro, 13); }

function minhaLocalizacao(){
  if(!navigator.geolocation) return toast("GPS não suportado no navegador.");
  navigator.geolocation.getCurrentPosition(pos => {
    const lat = pos.coords.latitude;
    const lng = pos.coords.longitude;
    const mk = L.marker([lat, lng]).addTo(map);
    mk.bindPopup("📍 Você está aqui").openPopup();
    map.setView([lat, lng], 16);
  }, () => toast("Não foi possível obter GPS. Permita no navegador."), { enableHighAccuracy:true, timeout:12000 });
}

function exportarPDF(){ window.print(); }

function toggleSetores(){
  setoresVisiveis = !setoresVisiveis;
  if (setoresVisiveis) camadaSetores.addTo(map);
  else map.removeLayer(camadaSetores);
}

function limparFiltros(){
  document.getElementById('formFiltros').reset();
  document.getElementById('modo').value = 'markers';
  aplicarFiltros();
}

async function aplicarFiltros(){
  await carregarPontos();
  renderCamadas();
  renderSetoresUI();
}

// =====================
// KPIs
// =====================
function atualizarKPIs(){
  const b = map.getBounds();
  let vis = 0;
  for(const p of pontos){
    if(!p.latitude || !p.longitude) continue;
    const lat = parseFloat(p.latitude), lng = parseFloat(p.longitude);
    if(b.contains([lat,lng])) vis++;
  }
  document.getElementById('kpiVisible').innerText = vis;
  document.getElementById('kpiView').innerText = vis;
}

map.on('moveend zoomend', atualizarKPIs);

// FUNÇÃO ESCAPEHTML SEGURA PARA LIMPAR STRINGS COM ASPAS
function escapeHtml(str) {
  const map = { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' };
  return String(str ?? '').replace(/[&<>"']/g, m => map[m]);
}

// =====================
// EVENTOS UI
// =====================
document.getElementById('formFiltros').addEventListener('submit', (e)=>{
  e.preventDefault();
  aplicarFiltros();
});

document.getElementById('modo').addEventListener('change', ()=>{
  renderCamadas();
});

// GO!
carregarTudo().catch(e => toast("Erro ao carregar: " + e.message));
</script>
</body>
</html>