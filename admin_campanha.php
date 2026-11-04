<?php
require 'config.php';require 'protecao.php';require 'campanha_lib.php';
if(!defined('TENANT_ID'))die('Tenant não identificado.');
$cfg=campanha_config($pdo,(int)TENANT_ID);
function qtd($pdo,$sql){$s=$pdo->prepare($sql);$s->execute([TENANT_ID]);return (int)$s->fetchColumn();}
$stats=[
 'Leads'=>qtd($pdo,"SELECT COUNT(*) FROM campanha_leads WHERE tenant_id=?"),
 'Materiais'=>qtd($pdo,"SELECT COUNT(*) FROM campanha_materiais WHERE tenant_id=? AND ativo=1"),
 'Solicitações'=>qtd($pdo,"SELECT COUNT(*) FROM campanha_solicitacoes_materiais WHERE tenant_id=?"),
 'Downloads'=>qtd($pdo,"SELECT COALESCE(SUM(downloads),0) FROM campanha_materiais WHERE tenant_id=?")
];
?>
<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Campanha Digital</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"><style>body{background:#f4f6f9}.hero{background:linear-gradient(135deg,#5b21b6,#7c3aed);color:white}.x{border:0;border-radius:20px;box-shadow:0 8px 24px #0001}</style></head>
<body><div class="container py-4"><div class="hero rounded-4 p-4 mb-4"><div class="d-flex justify-content-between align-items-center flex-wrap gap-2"><div><small>MEU GABINETE DIGITAL</small><h2 class="fw-bold">Campanha Digital</h2><div>Site, mobilização, materiais e captação.</div></div><div><a href="admin_painel.php" class="btn btn-light rounded-pill">Painel</a><?php if($cfg):?> <a target="_blank" href="site_campanha.php?slug=<?= campanha_h($cfg['slug']) ?>" class="btn btn-warning rounded-pill fw-bold">Ver site</a><?php endif;?></div></div></div>
<div class="row g-3 mb-4"><?php foreach($stats as $k=>$v):?><div class="col-6 col-lg-3"><div class="card x p-3"><div class="small text-muted fw-bold"><?= campanha_h($k) ?></div><div class="fs-2 fw-bold"><?= $v ?></div></div></div><?php endforeach;?></div>
<div class="row g-3">
<?php foreach([
['admin_campanha_config.php','Configurar Site','Identidade, candidato, cores, fotos e redes.','fa-palette'],
['admin_campanha_pautas.php','Pautas & Realizações','Bandeiras, propostas e entregas.','fa-bullhorn'],
['admin_campanha_materiais.php','Materiais','Santinhos, logos, jingles e kit.','fa-folder-open'],
['admin_campanha_leads.php','Leads & Mobilização','Cadastros vindos do site.','fa-user-plus'],
['admin_campanha_solicitacoes.php','Solicitações','Controle de materiais físicos.','fa-box']
] as $c):?><div class="col-md-6 col-xl-4"><a href="<?= $c[0] ?>" class="text-decoration-none text-dark"><div class="card x p-4 h-100"><i class="fas <?= $c[3] ?> fs-2 text-primary mb-3"></i><h5 class="fw-bold"><?= $c[1] ?></h5><p class="text-muted"><?= $c[2] ?></p></div></a></div><?php endforeach;?></div></div></body></html>
