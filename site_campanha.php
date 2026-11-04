<?php
require 'config.php';require 'campanha_lib.php';
if (file_exists(__DIR__ . '/campanha_premium_guard.php')) { require 'campanha_premium_guard.php'; }
$slug=trim($_GET['slug']??'');$host=strtolower(preg_replace('/:\d+$/','',$_SERVER['HTTP_HOST']??''));
if($slug!==''){$s=$pdo->prepare("SELECT * FROM campanha_sites WHERE slug=? AND ativo=1 LIMIT 1");$s->execute([$slug]);}
else{$s=$pdo->prepare("SELECT * FROM campanha_sites WHERE LOWER(dominio_personalizado)=? AND ativo=1 LIMIT 1");$s->execute([$host]);}
$cfg=$s->fetch(PDO::FETCH_ASSOC);if(!$cfg){http_response_code(404);exit('Campanha não encontrada.');}
$tenant=(int)$cfg['tenant_id'];
if (function_exists('campanha_exigir_premium')) { campanha_exigir_premium($pdo,$tenant); }
$ref=trim($_GET['ref']??$_GET['lider']??'');
$s=$pdo->prepare("SELECT * FROM campanha_pautas WHERE tenant_id=? AND ativo=1 ORDER BY ordem,id");$s->execute([$tenant]);$pautas=$s->fetchAll(PDO::FETCH_ASSOC);
$s=$pdo->prepare("SELECT * FROM campanha_realizacoes WHERE tenant_id=? AND ativo=1 ORDER BY ordem,id");$s->execute([$tenant]);$real=$s->fetchAll(PDO::FETCH_ASSOC);
$s=$pdo->prepare("SELECT * FROM campanha_projetos_leis WHERE tenant_id=? AND ativo=1 ORDER BY ordem,ano DESC,id DESC");$s->execute([$tenant]);$projetos=$s->fetchAll(PDO::FETCH_ASSOC);
$s=$pdo->prepare("SELECT * FROM campanha_materiais WHERE tenant_id=? AND ativo=1 ORDER BY id DESC LIMIT 30");$s->execute([$tenant]);$materiais=$s->fetchAll(PDO::FETCH_ASSOC);
$p=$cfg['cor_primaria']?:'#5B21B6';
$s2=$cfg['cor_secundaria']?:'#FFFFFF';
$a=$cfg['cor_destaque']?:'#F59E0B';

$cnpjFormatado = '';
if (!empty($cfg['cnpj_campanha'])) {
    $cnpjLimpo = preg_replace('/\D+/', '', (string)$cfg['cnpj_campanha']);
    if (strlen($cnpjLimpo) === 14) {
        $cnpjFormatado =
            substr($cnpjLimpo,0,2) . '.' .
            substr($cnpjLimpo,2,3) . '.' .
            substr($cnpjLimpo,5,3) . '/' .
            substr($cnpjLimpo,8,4) . '-' .
            substr($cnpjLimpo,12,2);
    } else {
        $cnpjFormatado = $cnpjLimpo;
    }
}
?>
<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= campanha_h($cfg['meta_title']?:$cfg['nome_candidato'].' - '.$cfg['cargo']) ?></title><meta name="description" content="<?= campanha_h($cfg['meta_description']?:$cfg['slogan']) ?>"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"><style>:root{--p:<?= campanha_h($p) ?>;--s:<?= campanha_h($s2) ?>;--a:<?= campanha_h($a) ?>}html{scroll-behavior:smooth}body{color:#111827}.navbar{background:#fffffff0;backdrop-filter:blur(12px)}
.hero{
    position:relative;
    min-height:88vh;
    display:flex;
    align-items:center;
    overflow:hidden;
    color:#fff;
    background:
        radial-gradient(circle at 72% 42%, rgba(255,255,255,.08) 0%, transparent 26%),
        radial-gradient(circle at 88% 85%, rgba(255,193,7,.08) 0%, transparent 22%),
        linear-gradient(110deg,var(--p) 0%,#0b1f2f 55%,#07131c 100%);
}

.hero::before{
    content:"";
    position:absolute;
    width:620px;
    height:620px;
    border-radius:50%;
    right:8%;
    top:50%;
    transform:translateY(-50%);
    background:linear-gradient(135deg,rgba(255,255,255,.08),rgba(255,255,255,.01));
    border:1px solid rgba(255,255,255,.08);
}

.number{
    display:block;
    font-size:clamp(5rem, 9vw, 9rem) !important;
    line-height:.82;
    font-weight:900 !important;
    letter-spacing:-5px;
    color:var(--a) !important;
    margin:25px 0 30px;
}

.hero::after{
    content:"";
    position:absolute;
    width:420px;
    height:420px;
    border-radius:50%;
    right:18%;
    bottom:-180px;
    background:rgba(255,193,7,.08);
    filter:blur(8px);
}

.hero-content{
    position:relative;
    z-index:3;
}

.hero-text{
    position:relative;
    z-index:5;
}

.hero-visual{
    position:relative;
    height:88vh;
    display:flex;
    align-items:flex-end;
    justify-content:center;
    z-index:2;
}

.hero-visual img{
    width:100%;
    max-width:620px;
    max-height:84vh;
    object-fit:contain;
    object-position:center bottom;
    filter:
        drop-shadow(0 25px 35px rgba(0,0,0,.35));
}

@media(max-width:991px){

    .hero{
        min-height:auto;
        padding-top:40px;
    }

    .hero-visual{
        height:auto;
        margin-top:20px;
    }

    .hero-visual img{
        max-height:520px;
        max-width:420px;
    }

    .hero::before{
        width:380px;
        height:380px;
        right:50%;
        transform:translate(50%,-50%);
    }
}

@media(max-width:768px){
    .number{
        font-size:clamp(4.5rem,22vw,7rem) !important;
        letter-spacing:-3px;
    }
}
.section{padding:80px 0}.light{background:#f8fafc}.x{border:0;border-radius:24px;box-shadow:0 10px 30px #0001}.pill{border-radius:999px}.icon{width:56px;height:56px;border-radius:18px;display:grid;place-items:center;background:#f3e8ff;color:var(--p)}.cta{background:linear-gradient(135deg,var(--p),#111827);color:#fff}.footer{background:#0f172a;color:#fff}.footer a{transition:.2s}.footer a:hover{opacity:.75;transform:translateY(-1px)}.hero .btn{transition:.2s}.hero .btn:hover{transform:translateY(-2px)}</style></head><body>
<nav class="navbar navbar-expand-lg sticky-top border-bottom"><div class="container"><a class="navbar-brand fw-bold" href="#inicio"><?php if($cfg['logo_campanha']):?><img src="<?= campanha_h($cfg['logo_campanha']) ?>" style="max-height:50px"><?php else:?><?= campanha_h($cfg['nome_candidato']) ?><?php endif;?></a><button class="navbar-toggler" data-bs-toggle="collapse" data-bs-target="#n"><span class="navbar-toggler-icon"></span></button><div id="n" class="collapse navbar-collapse"><div class="navbar-nav ms-auto"><a class="nav-link" href="#sobre">Quem sou</a><a class="nav-link" href="#pautas">Pautas</a><?php if($projetos):?><a class="nav-link" href="#projetos-leis">Projetos e Leis</a><?php endif;?><a class="nav-link" href="#materiais">Materiais</a><a class="btn btn-dark pill px-4 ms-lg-2" href="#participar">Quero fazer parte</a></div></div></div></nav>

<section id="inicio" class="hero">

    <div class="container hero-content">

        <div class="row align-items-center">

            <div class="col-lg-6 hero-text py-5">

                <div class="text-uppercase fw-bold opacity-75 mb-3">
                    <?= campanha_h($cfg['cargo']) ?>
                    <?= $cfg['cidade'] ? ' • '.campanha_h($cfg['cidade']).'/'.campanha_h($cfg['uf']) : '' ?>
                </div>

                <h1 class="display-2 fw-bold mb-3">
                    <?= campanha_h($cfg['nome_candidato']) ?>
                </h1>

                <?php if($cfg['numero_eleitoral']): ?>
                    <div class="number">
                        <?= campanha_h($cfg['numero_eleitoral']) ?>
                    </div>
                <?php endif; ?>

                <h2 class="fw-bold mb-3">
                    <?= campanha_h($cfg['slogan']) ?>
                </h2>

                <p class="lead opacity-75 mb-4">
                    <?= campanha_h($cfg['subtitulo']) ?>
                </p>

                <div class="d-flex flex-wrap gap-3">

                    <a href="#participar"
                       class="btn btn-warning btn-lg pill px-4 fw-bold shadow">
                        Quero fazer parte
                    </a>

                    <a href="#pautas"
                       class="btn btn-outline-light btn-lg pill px-4">
                        Propostas
                    </a>

                </div>

            </div>

            <div class="col-lg-6">

                <div class="hero-visual">

                    <?php if($cfg['foto_principal']): ?>

                        <img
                            src="<?= campanha_h($cfg['foto_principal']) ?>"
                            alt="<?= campanha_h($cfg['nome_candidato']) ?>"
                        >

                    <?php endif; ?>

                </div>

            </div>

        </div>

    </div>

</section>
<?php if($cfg['mostrar_biografia']):?><section id="sobre" class="section"><div class="container"><div class="row align-items-center g-5"><div class="col-lg-5"><?php if($cfg['foto_sobre']):?><img src="<?= campanha_h($cfg['foto_sobre']) ?>" class="img-fluid rounded-4 shadow"><?php endif;?></div><div class="col-lg-7"><small class="fw-bold" style="color:var(--p)">CONHEÇA</small><h2 class="display-5 fw-bold"><?= campanha_h($cfg['nome_candidato']) ?></h2><div class="fs-5 lh-lg"><?= nl2br(campanha_h($cfg['biografia'])) ?></div></div></div></div></section><?php endif;?>
<?php if($cfg['mostrar_pautas']&&$pautas):?><section id="pautas" class="section light"><div class="container"><h2 class="display-5 fw-bold text-center mb-5">Nossas pautas</h2><div class="row g-4"><?php foreach($pautas as $pauta):?><div class="col-md-6 col-lg-4"><div class="card x h-100 p-4"><div class="icon mb-3"><i class="fas <?= campanha_h($pauta['icone']?:'fa-check') ?>"></i></div><h4><?= campanha_h($pauta['titulo']) ?></h4><p class="text-muted"><?= campanha_h($pauta['descricao']) ?></p></div></div><?php endforeach;?></div></div></section><?php endif;?>
<?php if($projetos):?>
<section id="projetos-leis" class="section">
<div class="container">
<div class="text-center mb-5"><small class="fw-bold" style="color:var(--p)">ATUAÇÃO LEGISLATIVA</small><h2 class="display-5 fw-bold">Projetos e Leis</h2><p class="text-muted">Consulte propostas, leis e documentos disponíveis.</p></div>
<div class="row g-4">
<?php foreach($projetos as $pr):
$tipoLabels=['projeto_lei'=>'Projeto de Lei','lei'=>'Lei','projeto_resolucao'=>'Projeto de Resolução','resolucao'=>'Resolução','indicacao'=>'Indicação','requerimento'=>'Requerimento','outro'=>'Documento'];
$tipoLabel=$tipoLabels[$pr['tipo']]??'Documento'; ?>
<div class="col-md-6 col-lg-4"><div class="card x h-100 p-4">
<div class="d-flex justify-content-between align-items-start gap-2 mb-3"><span class="badge bg-light text-dark border"><?=campanha_h($tipoLabel)?></span><?php if(!empty($pr['situacao'])):?><span class="badge" style="background:var(--p)"><?=campanha_h($pr['situacao'])?></span><?php endif;?></div>
<h4 class="mb-2"><?php if(!empty($pr['numero'])):?><?=campanha_h($tipoLabel)?> <?=campanha_h($pr['numero'])?><?=!empty($pr['ano'])?'/'.(int)$pr['ano']:''?><?php else:?><?=campanha_h($pr['titulo'])?><?php endif;?></h4>
<?php if(!empty($pr['numero'])):?><div class="fw-bold mb-2"><?=campanha_h($pr['titulo'])?></div><?php endif;?>
<?php if(!empty($pr['descricao'])):?><p class="text-muted flex-grow-1"><?=nl2br(campanha_h($pr['descricao']))?></p><?php endif;?>
<div class="mt-auto d-grid gap-2">
<?php if(!empty($pr['arquivo_pdf'])):?><button type="button" class="btn btn-dark pill" onclick="iniciarDownloadProjeto(<?=(int)$pr['id']?>)"><i class="fa-solid fa-file-pdf me-1"></i> Baixar PDF</button><?php endif;?>
<?php if(!empty($pr['link_integra'])):?><a class="btn btn-outline-secondary pill" href="<?=campanha_h($pr['link_integra'])?>" target="_blank" rel="noopener">Ver link externo</a><?php endif;?>
</div></div></div>
<?php endforeach;?>
</div></div></section>
<?php endif;?>
<?php if($cfg['mostrar_realizacoes']&&$real):?><section class="section"><div class="container"><h2 class="display-5 fw-bold text-center mb-5">Realizações</h2><div class="row g-4"><?php foreach($real as $r):?><div class="col-md-6 col-lg-4"><div class="card x h-100 overflow-hidden"><?php if($r['imagem']):?><img src="<?= campanha_h($r['imagem']) ?>" style="height:200px;object-fit:cover"><?php endif;?><div class="p-4"><div class="fs-2 fw-bold" style="color:var(--p)"><?= campanha_h($r['numero_destaque']) ?></div><h4><?= campanha_h($r['titulo']) ?></h4><p class="text-muted"><?= campanha_h($r['descricao']) ?></p></div></div></div><?php endforeach;?></div></div></section><?php endif;?>
<?php if($cfg['mostrar_materiais']&&$materiais):?><section id="materiais" class="section light"><div class="container"><h2 class="display-5 fw-bold text-center mb-5">Materiais da campanha</h2><div class="row g-4"><?php foreach($materiais as $m):?><div class="col-md-6 col-lg-3"><div class="card x h-100 overflow-hidden"><?php if($m['imagem_capa']):?><img src="<?= campanha_h($m['imagem_capa']) ?>" style="height:180px;object-fit:cover"><?php endif;?><div class="p-3"><span class="badge bg-light text-dark border"><?= campanha_h($m['tipo']) ?></span><h5 class="mt-2"><?= campanha_h($m['titulo']) ?></h5><a href="campanha_download.php?id=<?= (int)$m['id'] ?>" class="btn btn-dark pill w-100">Baixar</a></div></div></div><?php endforeach;?></div></div></section><?php endif;?>
<?php if($cfg['mostrar_voluntario']):?><section id="participar" class="section cta"><div class="container"><div class="row justify-content-center"><div class="col-lg-8"><h2 class="display-5 fw-bold text-center">Faça parte do nosso time</h2><p class="lead text-center opacity-75">Entre para a mobilização.</p><form class="bg-white text-dark rounded-4 p-4 shadow" method="post" action="https://meugabinetedigital.com.br/campanha_form_submit" onsubmit="return enviar(this,'lead')">
<input type="hidden" name="tipo" value="lead"><input type="hidden" name="tenant_id" value="<?= $tenant ?>"><input type="hidden" name="ref" value="<?= campanha_h($ref) ?>"><div class="row g-3"><div class="col-md-6"><input name="nome" class="form-control form-control-lg" placeholder="Seu nome" required></div><div class="col-md-6"><input name="whatsapp" class="form-control form-control-lg" placeholder="WhatsApp"></div><div class="col-md-6"><input name="email" class="form-control" type="email" placeholder="E-mail"></div><div class="col-md-3"><input name="cidade" class="form-control" placeholder="Cidade"></div><div class="col-md-3"><input name="bairro" class="form-control" placeholder="Bairro"></div><div class="col-12"><select name="interesse" class="form-select"><option>Apoiador</option><option>Lideranca</option><option>Voluntario</option><option>Redes_Sociais</option><option>Eventos</option><option>Material</option></select></div><div class="col-12"><button class="btn btn-warning btn-lg pill w-100 fw-bold">Quero fazer parte</button></div></div><div class="form-msg mt-3"></div></form></div></div></div></section><?php endif;?>
<?php if($cfg['mostrar_material_fisico']):?><section class="section"><div class="container"><div class="row justify-content-center"><div class="col-lg-8"><h2 class="display-5 fw-bold text-center mb-4">Quero material de campanha</h2><form class="card x p-4" method="post" action="https://meugabinetedigital.com.br/campanha_form_submit" onsubmit="return enviar(this,'material')">
<input type="hidden" name="tipo" value="material"><input type="hidden" name="tenant_id" value="<?= $tenant ?>"><input type="hidden" name="ref" value="<?= campanha_h($ref) ?>"><div class="row g-3"><div class="col-md-6"><input name="nome" class="form-control" placeholder="Nome" required></div><div class="col-md-6"><input name="whatsapp" class="form-control" placeholder="WhatsApp"></div><div class="col-md-3"><input name="cep" class="form-control" placeholder="CEP"></div><div class="col-md-7"><input name="endereco" class="form-control" placeholder="Endereço"></div><div class="col-md-2"><input name="numero" class="form-control" placeholder="Nº"></div><div class="col-md-4"><input name="bairro" class="form-control" placeholder="Bairro"></div><div class="col-md-5"><input name="cidade" class="form-control" placeholder="Cidade"></div><div class="col-md-3"><input name="uf" class="form-control" maxlength="2" placeholder="UF"></div><div class="col-12"><?php foreach(['Santinhos','Adesivos','Bandeira','Perfurado','Outro'] as $it):?><label class="me-3"><input type="checkbox" name="itens[]" value="<?= $it ?>"> <?= $it ?></label><?php endforeach;?></div><div class="col-12"><textarea name="observacoes" class="form-control" placeholder="Observações"></textarea></div><div class="col-12"><button class="btn btn-dark btn-lg pill w-100">Solicitar material</button></div></div><div class="form-msg mt-3"></div></form></div></div></div></section><?php endif;?>

<div class="modal fade" id="modalProjetoCadastro" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 rounded-4">
<form id="formProjetoCadastro" onsubmit="return cadastrarParaDownload(event)">
<div class="modal-header border-0"><div><h5 class="modal-title fw-bold">Liberar download</h5><div class="small text-muted">Informe seus dados uma vez. Depois este navegador será reconhecido.</div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body pt-0"><input type="hidden" name="projeto_id" id="download_projeto_id"><input type="hidden" name="ref" value="<?=campanha_h($ref)?>">
<div class="mb-3"><label class="form-label">Nome *</label><input name="nome" class="form-control" required></div>
<div class="row g-3"><div class="col-md-6"><label class="form-label">WhatsApp</label><input name="whatsapp" class="form-control" placeholder="(37) 99999-9999"></div><div class="col-md-6"><label class="form-label">E-mail</label><input type="email" name="email" class="form-control"></div><div class="col-md-6"><label class="form-label">Cidade</label><input name="cidade" class="form-control"></div><div class="col-md-6"><label class="form-label">Bairro</label><input name="bairro" class="form-control"></div></div>
<div class="form-check mt-3"><input class="form-check-input" type="checkbox" name="consentimento_whatsapp" value="1" id="consentDownload"><label class="form-check-label" for="consentDownload">Autorizo contato pelo WhatsApp.</label></div>
<div id="downloadCadastroMsg" class="mt-3"></div></div>
<div class="modal-footer border-0"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button id="btnLiberarDownload" class="btn btn-dark pill px-4">Liberar PDF</button></div>
</form></div></div></div>
<footer class="footer py-5">
<div class="container text-center">

    <?php if(!empty($cfg['logo_campanha'])): ?>
        <div class="mb-3">
            <img src="<?= campanha_h($cfg['logo_campanha']) ?>"
                 alt="<?= campanha_h($cfg['nome_candidato']) ?>"
                 style="max-height:65px;max-width:220px;object-fit:contain;">
        </div>
    <?php endif; ?>

    <h4 class="fw-bold mb-1">
        <?= campanha_h($cfg['nome_candidato']) ?>
        <?= !empty($cfg['numero_eleitoral']) ? campanha_h($cfg['numero_eleitoral']) : '' ?>
    </h4>

    <?php if(!empty($cfg['partido'])): ?>
        <div class="small opacity-75 mb-2">
            <?= campanha_h($cfg['partido']) ?>
        </div>
    <?php endif; ?>

    <?php if($cnpjFormatado !== ''): ?>
        <div class="small opacity-75 mb-3">
            CNPJ: <?= campanha_h($cnpjFormatado) ?>
        </div>
    <?php endif; ?>

    <div class="fs-4 my-3">
        <?php foreach([
            'instagram'=>'instagram',
            'facebook'=>'facebook',
            'youtube'=>'youtube',
            'tiktok'=>'tiktok'
        ] as $k=>$i): if(!empty($cfg[$k])): ?>
            <a class="text-white mx-2"
               target="_blank"
               rel="noopener noreferrer"
               href="<?= campanha_h($cfg[$k]) ?>">
                <i class="fab fa-<?= $i ?>"></i>
            </a>
        <?php endif; endforeach; ?>
    </div>

    <div class="small opacity-50">
        &copy; <?= date('Y') ?> <?= campanha_h($cfg['nome_candidato']) ?>.
        Todos os direitos reservados.
    </div>

    <div class="small opacity-50 mt-1">
        Gerenciado pelo Meu Gabinete Digital.
    </div>
</div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script>
async function enviar(form,tipo){let b=form.querySelector('button');let m=form.querySelector('.form-msg');let old=b.innerHTML;b.disabled=true;b.innerHTML='Enviando...';try{let fd=new FormData(form);fd.set('tipo',tipo);let r=await fetch('https://meugabinetedigital.com.br/campanha_form_submit',{
    method:'POST',
    body:fd,
    credentials:'omit',
    headers:{
        'X-Requested-With':'XMLHttpRequest'
    }
});let j=await r.json();m.innerHTML='<div class="alert '+(j.ok?'alert-success':'alert-danger')+'">'+j.mensagem+'</div>';if(j.ok)form.reset();}catch(e){m.innerHTML='<div class="alert alert-danger">Falha ao enviar.</div>';}b.disabled=false;b.innerHTML=old;return false;}


let projetoDownloadModal=null;
function getProjetoDownloadModal(){
    if(!projetoDownloadModal) projetoDownloadModal=new bootstrap.Modal(document.getElementById('modalProjetoCadastro'));
    return projetoDownloadModal;
}
async function iniciarDownloadProjeto(id){
    try{
        let r=await fetch('/projeto_download?id='+encodeURIComponent(id)+'&check=1',{credentials:'same-origin',headers:{'Accept':'application/json'}});
        let j=await r.json();
        if(j.ok){ window.location.href='/projeto_download?id='+encodeURIComponent(id); return; }
        if(j.cadastro_necessario){ document.getElementById('download_projeto_id').value=id; document.getElementById('downloadCadastroMsg').innerHTML=''; getProjetoDownloadModal().show(); return; }
        alert(j.mensagem||'Não foi possível liberar o documento.');
    }catch(e){ alert('Não foi possível verificar o download.'); }
}
async function cadastrarParaDownload(ev){
    ev.preventDefault();
    let form=ev.target, btn=document.getElementById('btnLiberarDownload'), msg=document.getElementById('downloadCadastroMsg');
    let old=btn.innerHTML; btn.disabled=true; btn.innerHTML='Liberando...'; msg.innerHTML='';
    try{
        let r=await fetch('/campanha_download_cadastro',{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});
        let j=await r.json();
        if(!j.ok){msg.innerHTML='<div class="alert alert-danger">'+(j.mensagem||'Não foi possível realizar o cadastro.')+'</div>';return false;}
        msg.innerHTML='<div class="alert alert-success">'+j.mensagem+'</div>';
        setTimeout(function(){getProjetoDownloadModal().hide();window.location.href=j.download_url;},450);
    }catch(e){msg.innerHTML='<div class="alert alert-danger">Falha ao liberar o download.</div>';}
    finally{btn.disabled=false;btn.innerHTML=old;}
    return false;
}
</script>

<?php
// Popup de captação configurável
if (file_exists(__DIR__ . '/campanha_popup.php')) {
    require __DIR__ . '/campanha_popup.php';
}

// Jingle da campanha
if (file_exists(__DIR__ . '/campanha_jingle.php')) {
    require __DIR__ . '/campanha_jingle.php';
}
?>

</body></html>
