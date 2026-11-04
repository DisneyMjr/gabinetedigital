<?php
require 'config.php';
require 'protecao.php';
require 'campanha_lib.php';

if (!defined('TENANT_ID')) die('Tenant não identificado.');

$tenantId = (int)TENANT_ID;
$msg = '';

/* =========================================================
   HELPERS
========================================================= */
function lead_h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function lead_tel($v) {
    return preg_replace('/\D+/', '', (string)$v);
}
function lead_whatsapp_url($telefone, $texto = '') {
    $tel = lead_tel($telefone);
    if ($tel === '') return '#';
    if (strlen($tel) <= 11) $tel = '55' . $tel;
    return 'https://wa.me/' . $tel . ($texto !== '' ? '?text=' . rawurlencode($texto) : '');
}

/* =========================================================
   GARANTE CAMPOS EXTRAS (sem quebrar instalações antigas)
========================================================= */
$cols = [];
try {
    $q = $pdo->query("SHOW COLUMNS FROM campanha_leads");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $c) $cols[$c['Field']] = true;
} catch (Throwable $e) {}

$temStatus = isset($cols['status_atendimento']);
$temObs = isset($cols['observacoes_atendimento']);
$temUltimo = isset($cols['ultimo_contato_em']);
$temProximo = isset($cols['proximo_contato_em']);
$temConsent = isset($cols['consentimento_whatsapp']);
$temOptout = isset($cols['optout_whatsapp']);

/* =========================================================
   AÇÕES
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $acao = $_POST['acao'] ?? '';
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) throw new Exception('Lead inválido.');

        $check = $pdo->prepare("SELECT id FROM campanha_leads WHERE id=? AND tenant_id=? LIMIT 1");
        $check->execute([$id, $tenantId]);
        if (!$check->fetchColumn()) throw new Exception('Lead não encontrado.');

        if ($acao === 'salvar_atendimento') {
            if (!$temStatus) throw new Exception('Execute primeiro o SQL de atualização do módulo.');

            $status = trim($_POST['status_atendimento'] ?? 'novo');
            $permitidos = ['novo','contatado','respondeu','interessado','material_enviado','retornar','concluido','nao_contatar'];
            if (!in_array($status, $permitidos, true)) $status = 'novo';

            $obs = trim($_POST['observacoes_atendimento'] ?? '');
            $proximo = trim($_POST['proximo_contato_em'] ?? '');
            $proximo = $proximo !== '' ? str_replace('T', ' ', $proximo) . ':00' : null;

            $sql = "UPDATE campanha_leads SET status_atendimento=?";
            $vals = [$status];

            if ($temObs) { $sql .= ", observacoes_atendimento=?"; $vals[] = $obs; }
            if ($temProximo) { $sql .= ", proximo_contato_em=?"; $vals[] = $proximo; }
            if ($temUltimo && isset($_POST['registrar_contato'])) { $sql .= ", ultimo_contato_em=NOW()"; }
            if ($temOptout && $status === 'nao_contatar') { $sql .= ", optout_whatsapp=1"; }

            $sql .= " WHERE id=? AND tenant_id=?";
            $vals[] = $id;
            $vals[] = $tenantId;

            $pdo->prepare($sql)->execute($vals);
            $msg = "<div class='alert alert-success'>Atendimento atualizado.</div>";
        }

        if ($acao === 'registrar_whatsapp' && $temUltimo) {
            $pdo->prepare("UPDATE campanha_leads SET ultimo_contato_em=NOW(), status_atendimento=IF(status_atendimento='novo','contatado',status_atendimento) WHERE id=? AND tenant_id=?")
                ->execute([$id, $tenantId]);
        }

    } catch (Throwable $e) {
        $msg = "<div class='alert alert-danger'>" . lead_h($e->getMessage()) . "</div>";
    }
}

/* =========================================================
   FILTROS
========================================================= */
$busca = trim($_GET['q'] ?? '');
$statusFiltro = trim($_GET['status'] ?? '');
$interesseFiltro = trim($_GET['interesse'] ?? '');

$where = ["l.tenant_id=?"];
$params = [$tenantId];

if ($busca !== '') {
    $where[] = "(l.nome LIKE ? OR l.whatsapp LIKE ? OR l.email LIKE ? OR l.cidade LIKE ? OR l.bairro LIKE ?)";
    $like = '%' . $busca . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
if ($statusFiltro !== '' && $temStatus) {
    $where[] = "l.status_atendimento=?";
    $params[] = $statusFiltro;
}
if ($interesseFiltro !== '') {
    $where[] = "l.interesse=?";
    $params[] = $interesseFiltro;
}

$selectExtras = [];
if ($temStatus) $selectExtras[] = "l.status_atendimento";
if ($temObs) $selectExtras[] = "l.observacoes_atendimento";
if ($temUltimo) $selectExtras[] = "l.ultimo_contato_em";
if ($temProximo) $selectExtras[] = "l.proximo_contato_em";
if ($temConsent) $selectExtras[] = "l.consentimento_whatsapp";
if ($temOptout) $selectExtras[] = "l.optout_whatsapp";

$sql = "SELECT l.id,l.nome,l.whatsapp,l.email,l.cidade,l.bairro,l.interesse,l.criado_em,l.ref_eleitor_id,
        e.nome AS nome_ref,
        (SELECT COUNT(*) FROM campanha_downloads d WHERE d.tenant_id=l.tenant_id AND d.lead_id=l.id) AS total_downloads" . ($selectExtras ? ',' . implode(',', $selectExtras) : '') . "
        FROM campanha_leads l
        LEFT JOIN eleitores e ON e.id=l.ref_eleitor_id AND e.tenant_id=l.tenant_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY l.id DESC LIMIT 1000";

$s = $pdo->prepare($sql);
$s->execute($params);
$lista = $s->fetchAll(PDO::FETCH_ASSOC);

$interesses = $pdo->prepare("SELECT DISTINCT interesse FROM campanha_leads WHERE tenant_id=? AND interesse<>'' ORDER BY interesse");
$interesses->execute([$tenantId]);
$interesses = $interesses->fetchAll(PDO::FETCH_COLUMN);

$cfg = campanha_config($pdo, $tenantId);
$nomeCampanha = $cfg['nome_candidato'] ?? 'nossa campanha';

$stats = ['total'=>count($lista),'novo'=>0,'retornar'=>0,'concluido'=>0];
if ($temStatus) {
    foreach ($lista as $r) {
        $st = $r['status_atendimento'] ?? 'novo';
        if (isset($stats[$st])) $stats[$st]++;
    }
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Leads & Mobilização</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
body{background:#f5f7fb;color:#172033}
.topbar{background:linear-gradient(135deg,#111827,#243047);color:#fff;border-radius:24px;padding:26px}
.stat{background:#fff;border:0;border-radius:18px;box-shadow:0 8px 30px rgba(15,23,42,.06)}
.table-card{background:#fff;border-radius:22px;box-shadow:0 8px 30px rgba(15,23,42,.06);overflow:hidden}
.avatar{width:42px;height:42px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#eef2ff;font-weight:800}
.btn-wa{background:#25D366;color:#fff;border:0}.btn-wa:hover{background:#1fb85a;color:#fff}
.badge-status{font-size:.72rem;padding:.45rem .65rem}
td{vertical-align:middle}
.contact-link{text-decoration:none;color:inherit}
.small-muted{font-size:.78rem;color:#7b8496}
</style>
</head>
<body>
<div class="container-fluid px-3 px-lg-5 py-4">
<div class="mx-auto" style="max-width:1500px">

<div class="topbar mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
        <div class="small text-uppercase opacity-75 fw-bold">Campanha Digital</div>
        <h2 class="mb-1 fw-bold">Leads & Mobilização</h2>
        <div class="opacity-75">Central de relacionamento, retornos e materiais.</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="admin_campanha_materiais.php" class="btn btn-light"><i class="fa-solid fa-folder-open me-1"></i> Materiais</a>
        <a href="admin_campanha.php" class="btn btn-outline-light">Voltar</a>
    </div>
</div>

<?= $msg ?>

<?php if(!$temStatus): ?>
<div class="alert alert-warning">
    <strong>Atualização necessária:</strong> execute o arquivo <code>ATUALIZAR_LEADS_CRM.sql</code> no phpMyAdmin para habilitar status, observações e retornos.
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="stat p-3"><div class="small text-muted">Leads exibidos</div><div class="fs-3 fw-bold"><?= (int)$stats['total'] ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="stat p-3"><div class="small text-muted">Novos</div><div class="fs-3 fw-bold"><?= (int)$stats['novo'] ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="stat p-3"><div class="small text-muted">Retornos</div><div class="fs-3 fw-bold"><?= (int)$stats['retornar'] ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="stat p-3"><div class="small text-muted">Concluídos</div><div class="fs-3 fw-bold"><?= (int)$stats['concluido'] ?></div></div></div>
</div>

<div class="card border-0 shadow-sm rounded-4 mb-4">
<div class="card-body p-3">
<form method="get" class="row g-2 align-items-end">
    <div class="col-lg-5">
        <label class="form-label small fw-bold">Buscar</label>
        <input name="q" value="<?= lead_h($busca) ?>" class="form-control" placeholder="Nome, WhatsApp, e-mail, cidade ou bairro">
    </div>
    <div class="col-lg-3">
        <label class="form-label small fw-bold">Status</label>
        <select name="status" class="form-select">
            <option value="">Todos</option>
            <?php foreach(['novo'=>'Novo','contatado'=>'Contatado','respondeu'=>'Respondeu','interessado'=>'Interessado','material_enviado'=>'Material enviado','retornar'=>'Retornar','concluido'=>'Concluído','nao_contatar'=>'Não contatar'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= $statusFiltro===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-lg-2">
        <label class="form-label small fw-bold">Interesse</label>
        <select name="interesse" class="form-select">
            <option value="">Todos</option>
            <?php foreach($interesses as $i): ?><option value="<?= lead_h($i) ?>" <?= $interesseFiltro===$i?'selected':'' ?>><?= lead_h($i) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-lg-2 d-grid">
        <button class="btn btn-dark"><i class="fa-solid fa-filter me-1"></i> Filtrar</button>
    </div>
</form>
</div>
</div>

<div class="table-card">
<div class="table-responsive">
<table class="table table-hover align-middle mb-0">
<thead class="table-light">
<tr>
    <th class="ps-4">Lead</th>
    <th>Contato</th>
    <th>Interesse</th>
    <th>Origem</th>
    <th>Downloads</th>
    <th>Status</th>
    <th>Último / próximo</th>
    <th class="text-end pe-4">Ações</th>
</tr>
</thead>
<tbody>
<?php if(!$lista): ?>
<tr><td colspan="8" class="text-center py-5 text-muted">Nenhum lead encontrado.</td></tr>
<?php endif; ?>

<?php foreach($lista as $r):
    $tel = lead_tel($r['whatsapp'] ?? '');
    $optout = $temOptout ? (int)($r['optout_whatsapp'] ?? 0) : 0;
    $status = $temStatus ? ($r['status_atendimento'] ?: 'novo') : 'novo';
    $textoBase = "Olá, " . $r['nome'] . "! Aqui é da equipe de " . $nomeCampanha . ". Você deixou seu contato em nosso site. Posso te ajudar com alguma informação?";
    $wa = lead_whatsapp_url($r['whatsapp'] ?? '', $textoBase);
?>
<tr>
<td class="ps-4">
    <div class="d-flex align-items-center gap-2">
        <div class="avatar"><?= lead_h(mb_strtoupper(mb_substr($r['nome'],0,1))) ?></div>
        <div>
            <strong><?= lead_h($r['nome']) ?></strong>
            <div class="small-muted"><?= lead_h(trim(($r['cidade']??'').' · '.($r['bairro']??''),' ·')) ?></div>
            <div class="small-muted"><?= date('d/m/Y H:i',strtotime($r['criado_em'])) ?></div>
        </div>
    </div>
</td>
<td>
    <?php if($tel): ?><div><i class="fa-brands fa-whatsapp text-success me-1"></i><?= lead_h($r['whatsapp']) ?></div><?php endif; ?>
    <?php if(!empty($r['email'])): ?><div class="small-muted"><?= lead_h($r['email']) ?></div><?php endif; ?>
</td>
<td><span class="badge text-bg-light border"><?= lead_h($r['interesse']) ?></span></td>
<td><?= lead_h($r['nome_ref'] ?: 'Direto') ?></td>
<td><?php if((int)($r['total_downloads']??0)>0): ?><span class="badge bg-dark"><?= (int)$r['total_downloads'] ?> arquivo(s)</span><?php else: ?><span class="text-muted small">—</span><?php endif; ?></td>
<td>
<?php
$classes=['novo'=>'secondary','contatado'=>'primary','respondeu'=>'info','interessado'=>'warning','material_enviado'=>'success','retornar'=>'danger','concluido'=>'success','nao_contatar'=>'dark'];
$labels=['novo'=>'Novo','contatado'=>'Contatado','respondeu'=>'Respondeu','interessado'=>'Interessado','material_enviado'=>'Material enviado','retornar'=>'Retornar','concluido'=>'Concluído','nao_contatar'=>'Não contatar'];
?>
<span class="badge rounded-pill text-bg-<?= $classes[$status]??'secondary' ?> badge-status"><?= $labels[$status]??lead_h($status) ?></span>
<?php if($optout): ?><div class="small text-danger mt-1"><i class="fa-solid fa-ban"></i> WhatsApp bloqueado</div><?php endif; ?>
</td>
<td>
    <?php if($temUltimo && !empty($r['ultimo_contato_em'])): ?><div class="small">Último: <?= date('d/m H:i',strtotime($r['ultimo_contato_em'])) ?></div><?php endif; ?>
    <?php if($temProximo && !empty($r['proximo_contato_em'])): ?><div class="small text-danger fw-bold">Retorno: <?= date('d/m H:i',strtotime($r['proximo_contato_em'])) ?></div><?php endif; ?>
</td>
<td class="text-end pe-4">
    <div class="d-flex justify-content-end gap-1 flex-wrap">
        <?php if($tel && !$optout): ?>
        <a href="<?= lead_h($wa) ?>" target="_blank" class="btn btn-wa btn-sm" onclick="registrarWhatsApp(<?= (int)$r['id'] ?>)">
            <i class="fa-brands fa-whatsapp"></i> Chamar
        </a>
        <?php endif; ?>

        <button type="button" class="btn btn-outline-primary btn-sm"
            onclick='abrirLead(<?= json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?>)'>
            <i class="fa-solid fa-user-pen"></i> Atender
        </button>

        <?php if($tel && !$optout): ?>
        <button type="button" class="btn btn-outline-success btn-sm"
            onclick='abrirMateriais(<?= json_encode(["id"=>$r["id"],"nome"=>$r["nome"],"whatsapp"=>$r["whatsapp"]], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?>)'>
            <i class="fa-solid fa-paper-plane"></i> Material
        </button>
        <?php endif; ?>
    </div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>

</div>
</div>

<!-- MODAL ATENDIMENTO -->
<div class="modal fade" id="modalLead" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-centered">
<div class="modal-content border-0 rounded-4">
<form method="post">
<input type="hidden" name="acao" value="salvar_atendimento">
<input type="hidden" name="id" id="lead_id">
<div class="modal-header border-0">
    <div><h5 class="modal-title fw-bold" id="lead_nome">Lead</h5><div class="small text-muted" id="lead_contato"></div></div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label fw-bold">Status do atendimento</label>
            <select name="status_atendimento" id="lead_status" class="form-select">
                <option value="novo">Novo</option>
                <option value="contatado">Contatado</option>
                <option value="respondeu">Respondeu</option>
                <option value="interessado">Interessado</option>
                <option value="material_enviado">Material enviado</option>
                <option value="retornar">Retornar</option>
                <option value="concluido">Concluído</option>
                <option value="nao_contatar">Não contatar</option>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label fw-bold">Próximo contato</label>
            <input type="datetime-local" name="proximo_contato_em" id="lead_proximo" class="form-control">
        </div>
        <div class="col-12">
            <label class="form-label fw-bold">Observações do atendimento</label>
            <textarea name="observacoes_atendimento" id="lead_obs" rows="5" class="form-control" placeholder="Registre o que foi conversado, pedido ou combinado."></textarea>
        </div>
        <div class="col-12">
            <label class="form-check">
                <input type="checkbox" name="registrar_contato" value="1" class="form-check-input">
                <span class="form-check-label">Registrar contato realizado agora</span>
            </label>
        </div>
    </div>
</div>
<div class="modal-footer border-0">
    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
    <button class="btn btn-primary px-4">Salvar atendimento</button>
</div>
</form>
</div>
</div>
</div>

<!-- MODAL MATERIAL -->
<div class="modal fade" id="modalMaterial" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content border-0 rounded-4">
<div class="modal-header border-0">
    <div><h5 class="modal-title fw-bold">Enviar material</h5><div class="small text-muted" id="material_nome"></div></div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
    <div class="alert alert-info small">
        Escolha uma mensagem e abra o WhatsApp. Para arquivos/imagens, use o botão <strong>Materiais</strong> para acessar a biblioteca e anexar o arquivo no WhatsApp.
    </div>

    <label class="form-label fw-bold">Mensagem</label>
    <textarea id="material_texto" rows="7" class="form-control"></textarea>

    <div class="d-grid gap-2 mt-3">
        <button type="button" class="btn btn-wa btn-lg" onclick="enviarMaterialWhatsApp()">
            <i class="fa-brands fa-whatsapp me-1"></i> Abrir WhatsApp com mensagem
        </button>
        <a href="admin_campanha_materiais.php" class="btn btn-outline-secondary">
            <i class="fa-solid fa-folder-open me-1"></i> Abrir biblioteca de materiais
        </a>
    </div>
</div>
</div>
</div>
</div>

<form method="post" id="formRegistroWa" class="d-none">
<input type="hidden" name="acao" value="registrar_whatsapp">
<input type="hidden" name="id" id="wa_registro_id">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
let modalLead = new bootstrap.Modal(document.getElementById('modalLead'));
let modalMaterial = new bootstrap.Modal(document.getElementById('modalMaterial'));
let materialLead = null;

function abrirLead(r){
    document.getElementById('lead_id').value = r.id || '';
    document.getElementById('lead_nome').textContent = r.nome || 'Lead';
    document.getElementById('lead_contato').textContent = [r.whatsapp||'',r.email||''].filter(Boolean).join(' · ');
    document.getElementById('lead_status').value = r.status_atendimento || 'novo';
    document.getElementById('lead_obs').value = r.observacoes_atendimento || '';

    let p = r.proximo_contato_em || '';
    if(p) p = p.replace(' ','T').substring(0,16);
    document.getElementById('lead_proximo').value = p;

    modalLead.show();
}

function abrirMateriais(r){
    materialLead = r;
    document.getElementById('material_nome').textContent = r.nome || '';
    document.getElementById('material_texto').value =
        'Olá, '+(r.nome||'')+'! Conforme nosso contato, estou te enviando o material solicitado. Se precisar de alguma informação, estou à disposição.';
    modalMaterial.show();
}

function normalizarTel(t){
    let n=(t||'').replace(/\D/g,'');
    if(n.length<=11) n='55'+n;
    return n;
}

function enviarMaterialWhatsApp(){
    if(!materialLead) return;
    let tel=normalizarTel(materialLead.whatsapp);
    if(!tel){ alert('Este lead não possui WhatsApp.'); return; }
    let texto=document.getElementById('material_texto').value || '';
    registrarWhatsApp(materialLead.id);
    window.open('https://wa.me/'+tel+'?text='+encodeURIComponent(texto),'_blank');
}

function registrarWhatsApp(id){
    if(!id) return;
    document.getElementById('wa_registro_id').value=id;

    let fd=new FormData(document.getElementById('formRegistroWa'));
    fetch(window.location.href,{
        method:'POST',
        body:fd,
        credentials:'same-origin'
    }).catch(()=>{});
}
</script>
</body>
</html>
