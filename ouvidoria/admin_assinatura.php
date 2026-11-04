<?php
session_start();
require 'mercadopago.php';

$tenantId = (int)($_SESSION['tenant_id'] ?? 0);
if (!$tenantId) { header('Location: login.php'); exit; }

$stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
$stmt->execute([$tenantId]);
$empresa = $stmt->fetch();
if (!$empresa) { session_destroy(); header('Location: login.php'); exit; }

// Atualiza o status diretamente no MP ao abrir a página.
if (!empty($empresa['mp_subscription_id'])) {
    $res = mp_api($pdo, 'GET', '/preapproval/' . rawurlencode($empresa['mp_subscription_id']));
    if ($res['ok']) {
        mp_update_tenant_subscription($pdo, $tenantId, $res['data']);
        $stmt->execute([$tenantId]);
        $empresa = $stmt->fetch();
    }
}
$configs = mp_configs($pdo);
$nomeSistema = $configs['nome_sistema'] ?? 'Ouvidoria SaaS';
$valor = (float)($configs['valor_mensalidade'] ?? 97);
$status = $empresa['mp_subscription_status'] ?? null;
$label = mp_subscription_status_label($status);
$badge = $status === 'authorized' ? 'success' : ($status === 'pending' ? 'warning' : ($status === 'paused' ? 'secondary' : 'danger'));
$next = !empty($empresa['mp_next_payment_date']) ? date('d/m/Y', strtotime($empresa['mp_next_payment_date'])) : null;
?>
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Minha assinatura | <?= htmlspecialchars($nomeSistema) ?></title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"><style>body{background:#f5f7fb}.shell{max-width:980px}.hero{background:linear-gradient(135deg,#111827,#263b60);color:white;border-radius:24px}.price{font-size:3rem;font-weight:800}.card{border-radius:20px}.feature i{width:28px}.status-dot{width:10px;height:10px;border-radius:50%;display:inline-block}</style></head>
<body><main class="container shell py-4 py-md-5"><div class="d-flex justify-content-between align-items-center mb-4"><div><a href="admin.php" class="text-decoration-none small">← Voltar ao painel</a><h2 class="fw-bold mt-2 mb-0">Minha assinatura</h2></div><span class="badge bg-<?= $badge ?> fs-6 px-3 py-2"><?= htmlspecialchars($label) ?></span></div>
<?php if(isset($_GET['ok'])): ?><div class="alert alert-success">Assinatura atualizada.</div><?php endif; ?>
<?php if(isset($_GET['erro'])): ?><div class="alert alert-danger">Não foi possível atualizar a assinatura: <?= htmlspecialchars($_GET['erro']) ?></div><?php endif; ?>
<div class="hero p-4 p-md-5 shadow-sm mb-4"><div class="row align-items-center"><div class="col-md-7"><span class="badge bg-light text-dark mb-3">PLANO MENSAL</span><h1 class="fw-bold"><?= htmlspecialchars($nomeSistema) ?></h1><p class="opacity-75 mb-0">Cobrança automática mensal processada pelo Mercado Pago.</p></div><div class="col-md-5 text-md-end mt-4 mt-md-0"><div class="price">R$ <?= number_format($valor,2,',','.') ?></div><div class="opacity-75">por mês</div></div></div></div>
<div class="row g-4"><div class="col-lg-7"><div class="card border-0 shadow-sm h-100"><div class="card-body p-4"><h5 class="fw-bold mb-4">O que está incluído</h5><div class="feature mb-3"><i class="bi bi-check-circle-fill text-success"></i> Manifestações e protocolos</div><div class="feature mb-3"><i class="bi bi-check-circle-fill text-success"></i> LAI / e-SIC</div><div class="feature mb-3"><i class="bi bi-check-circle-fill text-success"></i> Relatórios e indicadores</div><div class="feature mb-3"><i class="bi bi-check-circle-fill text-success"></i> Usuários e setores</div><div class="feature"><i class="bi bi-check-circle-fill text-success"></i> Atualizações da plataforma</div></div></div></div>
<div class="col-lg-5"><div class="card border-0 shadow-sm"><div class="card-body p-4"><h5 class="fw-bold">Status da cobrança</h5><p class="text-secondary small">A cobrança recorrente é gerenciada pelo Mercado Pago.</p><?php if($next): ?><div class="bg-light rounded-3 p-3 mb-3"><small class="text-secondary d-block">Próxima cobrança</small><strong><?= htmlspecialchars($next) ?></strong></div><?php endif; ?>
<?php if($status === 'authorized'): ?><button class="btn btn-success w-100 mb-2" disabled><i class="bi bi-check2-circle"></i> Assinatura ativa</button><form method="post" action="assinatura_acao.php" class="mb-2"><input type="hidden" name="acao" value="pause"><button class="btn btn-outline-secondary w-100">Pausar assinatura</button></form><form method="post" action="assinatura_acao.php" onsubmit="return confirm('Deseja realmente cancelar a assinatura recorrente?')"><input type="hidden" name="acao" value="cancel"><button class="btn btn-link text-danger w-100">Cancelar assinatura</button></form>
<?php elseif($status === 'paused'): ?><form method="post" action="assinatura_acao.php"><input type="hidden" name="acao" value="resume"><button class="btn btn-primary w-100 mb-2">Reativar assinatura</button></form><form method="post" action="assinatura_acao.php"><input type="hidden" name="acao" value="cancel"><button class="btn btn-link text-danger w-100">Cancelar assinatura</button></form>
<?php else: ?><button id="btnAssinar" class="btn btn-primary btn-lg w-100 fw-bold"><i class="bi bi-credit-card"></i> Assinar com Mercado Pago</button><div id="msg" class="small text-secondary mt-3 text-center">Você será direcionado ao ambiente seguro do Mercado Pago.</div><?php endif; ?>
</div></div></div></div></main>
<script><?php if(!in_array($status,['authorized','paused'],true)): ?>document.getElementById('btnAssinar')?.addEventListener('click',async function(){const b=this,m=document.getElementById('msg');b.disabled=true;b.innerHTML='<span class="spinner-border spinner-border-sm"></span> Preparando assinatura';try{const r=await fetch('api_assinatura.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'}});const j=await r.json();if(j.status==='authorized'){location.reload();return;}if(j.checkout_url){location.href=j.checkout_url;return;}throw new Error(j.erro||'Não foi possível abrir o checkout.');}catch(e){m.textContent=e.message;m.className='small text-danger mt-3 text-center';b.disabled=false;b.innerHTML='<i class="bi bi-credit-card"></i> Assinar com Mercado Pago';}});<?php endif; ?></script></body></html>
