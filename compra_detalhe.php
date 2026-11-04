<?php
session_start();
require 'config.php';
require 'protecao.php';
require 'compras_lib.php';

if (!compras_pode('ver')) die('Acesso negado.');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$id) die('Compra inválida.');

$erro='';

try { $compra = compras_buscar($pdo,$id); } catch(Exception $e){ die($e->getMessage()); }

if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        $acao = $_POST['acao'] ?? '';
        $antes = $compra['status'];

        if ($acao==='aprovar_nivel') {
            $nivel = (int)($_POST['nivel'] ?? 0);
            $obs = trim($_POST['observacao_aprovacao'] ?? '');
            compras_aprovar_nivel($pdo, $id, $nivel, $obs ?: null);
        }
        elseif ($acao==='recusar_nivel') {
            $nivel = (int)($_POST['nivel'] ?? 0);
            $motivo=trim($_POST['motivo_recusa']??'');
            if(!$motivo) throw new Exception('Informe o motivo da recusa.');
            compras_recusar_nivel($pdo, $id, $nivel, $motivo);
        }
        elseif ($acao==='registrar_compra') {
            if (!compras_pode('comprar')) throw new Exception('Sem permissão para registrar compra.');

            $fornecedorNome=trim($_POST['fornecedor_nome']??'');
            $doc=trim($_POST['fornecedor_documento']??'');
            $numeroNota=trim($_POST['numero_nota']??'');
            $valorBruto=str_replace(['R$','.',' '],'',$_POST['valor_final']??'');
            $valor=(float)str_replace(',','.',$valorBruto);
            $data=$_POST['data_compra'] ?: date('Y-m-d');
            $iaJson=trim($_POST['ia_dados_json']??'');

            if(!$fornecedorNome || $valor<=0) throw new Exception('Informe fornecedor e valor final.');

            $st=$pdo->prepare("SELECT id FROM compras_fornecedores WHERE tenant_id=? AND nome=? LIMIT 1");
            $st->execute([TENANT_ID,$fornecedorNome]);
            $fid=(int)$st->fetchColumn();

            if(!$fid){
                $pdo->prepare("INSERT INTO compras_fornecedores (tenant_id,nome,documento) VALUES (?,?,?)")
                    ->execute([TENANT_ID,$fornecedorNome,$doc]);
                $fid=(int)$pdo->lastInsertId();
            } elseif ($doc !== '') {
                $pdo->prepare("UPDATE compras_fornecedores SET documento=? WHERE id=? AND tenant_id=?")
                    ->execute([$doc,$fid,TENANT_ID]);
            }

            $nota=compras_upload('arquivo_nota','nota');
            $comp=compras_upload('arquivo_comprovante','comp');

            $sql="UPDATE compras SET fornecedor_id=?, numero_nota=?, comprador_id=?, valor_final=?, data_compra=?, status='comprada'";
            $params=[$fid,$numeroNota,compras_usuario_id(),$valor,$data];

            if($nota){$sql.=", arquivo_nota=?";$params[]=$nota;}
            if($comp){$sql.=", arquivo_comprovante=?";$params[]=$comp;}
            if($iaJson!==''){$sql.=", ia_dados_json=?, ia_lida_em=NOW()";$params[]=$iaJson;}

            $sql.=" WHERE id=? AND tenant_id=?";
            array_push($params,$id,TENANT_ID);
            $pdo->prepare($sql)->execute($params);

            compras_registrar_historico($pdo,$id,'compra_registrada',$antes,'comprada','Fornecedor: '.$fornecedorNome);

            $cfg=compras_config($pdo);
            if(!empty($cfg['gerar_financeiro_automatico'])){
                compras_criar_lancamento_financeiro($pdo,$id);
            }
        }
        elseif ($acao==='gerar_financeiro') {
            if (!compras_pode('pagar') && !compras_pode('comprar')) throw new Exception('Sem permissão.');
            compras_criar_lancamento_financeiro($pdo,$id);
        }
        elseif ($acao==='marcar_pago') {
            if (!compras_pode('pagar')) throw new Exception('Sem permissão para pagamento.');
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE compras SET status='paga', data_pagamento=CURDATE() WHERE id=? AND tenant_id=?")->execute([$id,TENANT_ID]);

            $novo = compras_buscar($pdo,$id);
            if(!empty($novo['prestacao_contas_id'])){
                $pdo->prepare("UPDATE prestacao_contas SET status='Pago' WHERE id=? AND tenant_id=?")
                    ->execute([$novo['prestacao_contas_id'],TENANT_ID]);
            }

            compras_registrar_historico($pdo,$id,'pagamento',$antes,'paga');
            $pdo->commit();
        }
        elseif ($acao==='concluir') {
            if (!compras_pode('editar')) throw new Exception('Sem permissão.');
            $pdo->prepare("UPDATE compras SET status='concluida' WHERE id=? AND tenant_id=?")
                ->execute([$id,TENANT_ID]);
            compras_registrar_historico($pdo,$id,'conclusao',$antes,'concluida');
        }
        else {
            throw new Exception('Ação inválida.');
        }

        header("Location: compra_detalhe.php?id=".$id."&ok=1");
        exit;
    } catch(Throwable $e) {
        if($pdo->inTransaction()) $pdo->rollBack();
        $erro=$e->getMessage();
    }
}

$compra=compras_buscar($pdo,$id);
$aprovacoes=compras_aprovacoes($pdo,$id);
$cfg=compras_config($pdo);

$st=$pdo->prepare("SELECT h.*,u.nome usuario_nome
                   FROM compras_historico h
                   LEFT JOIN usuarios u ON u.id=h.usuario_id AND u.tenant_id=h.tenant_id
                   WHERE h.tenant_id=? AND h.compra_id=?
                   ORDER BY h.id DESC");
$st->execute([TENANT_ID,$id]);
$hist=$st->fetchAll(PDO::FETCH_ASSOC);

$nivelAtual=(int)($compra['nivel_aprovacao_atual']??1);
$aprovacaoAtual = null;
foreach ($aprovacoes as $ap) {
    if ((int)$ap['nivel'] === $nivelAtual) { $aprovacaoAtual = $ap; break; }
}
$uidAtual = compras_usuario_id();
$podeDecidirAtual = $compra['status']==='em_aprovacao' && (
    in_array(compras_nivel(), ['master','admin'], true)
    || compras_pode('aprovar_n'.$nivelAtual)
    || (!empty($aprovacaoAtual['aprovador_previsto_id']) && (int)$aprovacaoAtual['aprovador_previsto_id']===(int)$uidAtual)
);

$podeRegistrarCompra = $compra['status']==='aprovada' && (
    in_array(compras_nivel(), ['master','admin'], true)
    || compras_pode('comprar')
    || (!empty($compra['responsavel_compra_id']) && (int)$compra['responsavel_compra_id']===(int)$uidAtual)
);
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Compra #<?= $id ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
body{background:#f5f7fb}.box{border:0;border-radius:20px}.timeline{border-left:3px solid #e5e7eb;padding-left:20px}.timeline>div{position:relative;margin-bottom:18px}.timeline>div:before{content:"";position:absolute;width:11px;height:11px;border-radius:50%;background:#0d6efd;left:-27px;top:6px}
.approval-step{border:1px solid #e5e7eb;border-radius:16px;padding:16px;height:100%}.approval-step.current{border:2px solid #0d6efd;background:#f0f6ff}.approval-step.done{border-color:#badbcc;background:#f3fff7}.approval-step.rejected{border-color:#f1aeb5;background:#fff5f5}
.camera-box{border:2px dashed #b7c5d8;border-radius:18px;padding:18px;background:#f8fbff}.camera-preview{max-height:260px;max-width:100%;border-radius:14px;display:none}
</style>
</head>
<body>
<div class="container py-4" style="max-width:1180px">
<div class="d-flex justify-content-between align-items-center mb-3">
<a href="admin_compras.php" class="btn btn-outline-secondary rounded-pill">← Compras</a>
<?php if(compras_pode('editar')): ?><a href="compra_form.php?id=<?= $id ?>" class="btn btn-outline-primary rounded-pill">Editar</a><?php endif; ?>
</div>

<?php if($erro): ?><div class="alert alert-danger"><?= compras_h($erro) ?></div><?php endif; ?>
<?php if(isset($_GET['ok'])): ?><div class="alert alert-success">Operação realizada com sucesso.</div><?php endif; ?>

<div class="row g-4">
<div class="col-lg-8">
<div class="card box shadow-sm mb-4"><div class="card-body p-4">
<div class="d-flex flex-wrap justify-content-between gap-3">
<div><small class="text-muted fw-bold">COMPRA #<?= $id ?></small><h2 class="fw-bold"><?= compras_h($compra['titulo']) ?></h2></div>
<div><span class="badge bg-<?= compras_status_class($compra['status']) ?> fs-6 rounded-pill"><?= compras_status_label($compra['status']) ?></span></div>
</div>
<hr>
<div class="row g-3">
<div class="col-md-6"><small class="text-muted">Solicitante</small><div class="fw-bold"><?= compras_h($compra['solicitante_nome']?:'-') ?></div></div>
<div class="col-md-3"><small class="text-muted">Valor estimado</small><div class="fw-bold"><?= compras_moeda($compra['valor_estimado']) ?></div></div>
<div class="col-md-3"><small class="text-muted">Valor final</small><div class="fw-bold text-primary"><?= $compra['valor_final']!==null?compras_moeda($compra['valor_final']):'-' ?></div></div>
<div class="col-md-6"><small class="text-muted">Categoria</small><div><?= compras_h($compra['categoria']?:'-') ?></div></div>
<div class="col-md-6"><small class="text-muted">Centro de custo</small><div><?= compras_h($compra['centro_custo']?:'-') ?></div></div>
</div>
<?php if($compra['descricao']): ?><hr><h6 class="fw-bold">Descrição</h6><p><?= nl2br(compras_h($compra['descricao'])) ?></p><?php endif; ?>
<?php if($compra['justificativa']): ?><h6 class="fw-bold">Justificativa</h6><p><?= nl2br(compras_h($compra['justificativa'])) ?></p><?php endif; ?>
<?php if($compra['motivo_recusa']): ?><div class="alert alert-danger"><b>Motivo da recusa:</b> <?= compras_h($compra['motivo_recusa']) ?></div><?php endif; ?>
</div></div>

<?php if($aprovacoes): ?>
<div class="card box shadow-sm mb-4"><div class="card-body p-4">
<div class="d-flex justify-content-between align-items-center mb-3">
<h5 class="fw-bold mb-0"><i class="fas fa-layer-group text-primary me-2"></i>Aprovação por níveis</h5>
<small class="text-muted"><?= count($aprovacoes) ?> nível(is) exigido(s)</small>
</div>
<div class="row g-3">
<?php foreach($aprovacoes as $a):
    $class = $a['status']==='aprovada'?'done':($a['status']==='recusada'?'rejected':((int)$a['nivel']===$nivelAtual && $compra['status']==='em_aprovacao'?'current':''));
?>
<div class="col-md-4">
<div class="approval-step <?= $class ?>">
<div class="d-flex justify-content-between align-items-start">
<div>
<small class="text-muted fw-bold">NÍVEL <?= (int)$a['nivel'] ?></small>
<div class="fw-bold"><?= compras_h(compras_nome_nivel($pdo,(int)$a['nivel'])) ?></div>
</div>
<?php if($a['status']==='aprovada'): ?><i class="fas fa-check-circle text-success fa-lg"></i>
<?php elseif($a['status']==='recusada'): ?><i class="fas fa-times-circle text-danger fa-lg"></i>
<?php else: ?><i class="fas fa-clock text-warning fa-lg"></i><?php endif; ?>
</div>
<div class="mt-3 small">
<?= ucfirst($a['status']) ?>
<?php if($a['usuario_nome']): ?><br><b><?= compras_h($a['usuario_nome']) ?></b><?php endif; ?>
<?php if($a['decidido_em']): ?><br><?= date('d/m/Y H:i',strtotime($a['decidido_em'])) ?><?php endif; ?>
</div>
</div>
</div>
<?php endforeach; ?>
</div>

<?php if($podeDecidirAtual): ?>
<hr>
<div class="d-flex flex-wrap gap-2">
<form method="post" class="d-flex gap-2 flex-grow-1">
<input type="hidden" name="id" value="<?= $id ?>">
<input type="hidden" name="acao" value="aprovar_nivel">
<input type="hidden" name="nivel" value="<?= $nivelAtual ?>">
<input type="text" name="observacao_aprovacao" class="form-control" placeholder="Observação opcional">
<button class="btn btn-success rounded-pill px-4 text-nowrap"><i class="fas fa-check me-1"></i>Aprovar nível <?= $nivelAtual ?></button>
</form>
<button class="btn btn-outline-danger rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#modalRecusar">Recusar</button>
</div>
<?php endif; ?>
</div></div>
<?php endif; ?>

<div class="card box shadow-sm"><div class="card-body p-4">
<h5 class="fw-bold mb-3">Ações</h5>
<div class="d-flex flex-wrap gap-2">
<?php if($podeRegistrarCompra): ?>
<button class="btn btn-primary rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#modalCompra"><i class="fas fa-cart-shopping me-1"></i>Registrar compra</button>
<?php endif; ?>

<?php if($compra['status']==='comprada' && empty($compra['prestacao_contas_id'])): ?>
<form method="post"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="gerar_financeiro"><button class="btn btn-info rounded-pill px-4">Gerar lançamento financeiro</button></form>
<?php endif; ?>

<?php if(in_array($compra['status'],['comprada','aguardando_pagamento'],true) && compras_pode('pagar')): ?>
<form method="post"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="marcar_pago"><button class="btn btn-success rounded-pill px-4">Marcar como paga</button></form>
<?php endif; ?>

<?php if($compra['status']==='paga' && compras_pode('editar')): ?>
<form method="post"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="concluir"><button class="btn btn-dark rounded-pill px-4">Concluir compra</button></form>
<?php endif; ?>
</div>
</div></div>
</div>

<div class="col-lg-4">
<div class="card box shadow-sm mb-4"><div class="card-body p-4">
<h5 class="fw-bold">Fornecedor e documentos</h5><hr>
<p><small class="text-muted">Responsável pela compra</small><br><b><?= compras_h($compra['responsavel_compra_nome']?:'-') ?></b></p>
<p><small class="text-muted">Fornecedor</small><br><b><?= compras_h($compra['fornecedor_nome']?:'-') ?></b></p>
<?php if($compra['fornecedor_documento']): ?><p><small class="text-muted">CPF/CNPJ</small><br><?= compras_h($compra['fornecedor_documento']) ?></p><?php endif; ?>
<?php if($compra['numero_nota']): ?><p><small class="text-muted">Nota/Documento nº</small><br><?= compras_h($compra['numero_nota']) ?></p><?php endif; ?>
<div class="d-grid gap-2">
<?php foreach(['arquivo_orcamento'=>'Orçamento','arquivo_nota'=>'Nota/Recibo','arquivo_comprovante'=>'Comprovante'] as $campo=>$nome): if(!empty($compra[$campo])): ?>
<a class="btn btn-outline-primary rounded-pill" target="_blank" href="<?= compras_h($compra[$campo]) ?>"><i class="fas fa-paperclip me-1"></i><?= $nome ?></a>
<?php endif; endforeach; ?>
<?php if($compra['prestacao_contas_id']): ?><a href="admin_prestacao_contas.php" class="btn btn-outline-success rounded-pill">Prestação de Contas #<?= (int)$compra['prestacao_contas_id'] ?></a><?php endif; ?>
</div>
</div></div>

<div class="card box shadow-sm"><div class="card-body p-4">
<h5 class="fw-bold mb-3">Histórico</h5>
<div class="timeline">
<?php foreach($hist as $h): ?><div><b><?= compras_h(ucfirst(str_replace('_',' ',$h['acao']))) ?></b><br><small class="text-muted"><?= date('d/m/Y H:i',strtotime($h['criado_em'])) ?><?= $h['usuario_nome']?' · '.compras_h($h['usuario_nome']):'' ?></small><?php if($h['observacao']): ?><div class="small mt-1"><?= compras_h($h['observacao']) ?></div><?php endif; ?></div><?php endforeach; ?>
</div>
</div></div>
</div>
</div>
</div>

<div class="modal fade" id="modalRecusar">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post">
<div class="modal-header"><h5 class="modal-title">Recusar aprovação</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<input type="hidden" name="id" value="<?= $id ?>">
<input type="hidden" name="acao" value="recusar_nivel">
<input type="hidden" name="nivel" value="<?= $nivelAtual ?>">
<label class="form-label fw-bold">Motivo *</label>
<textarea name="motivo_recusa" class="form-control" required></textarea>
</div>
<div class="modal-footer"><button class="btn btn-danger">Confirmar recusa</button></div>
</form></div></div>
</div>

<div class="modal fade" id="modalCompra">
<div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content"><form method="post" enctype="multipart/form-data" id="formRegistrarCompra">
<div class="modal-header"><h5 class="modal-title"><i class="fas fa-receipt text-primary me-2"></i>Registrar compra</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<input type="hidden" name="id" value="<?= $id ?>">
<input type="hidden" name="acao" value="registrar_compra">
<input type="hidden" name="ia_dados_json" id="ia_dados_json">

<?php if(!empty($cfg['ia_leitura_nota'])): ?>
<div class="camera-box mb-4">
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
<div>
<h6 class="fw-bold mb-1"><i class="fas fa-wand-magic-sparkles text-primary me-1"></i>Ler nota automaticamente</h6>
<div class="small text-muted">Fotografe a nota. A IA preenche fornecedor, CNPJ/CPF, data, número e valor. Confira antes de salvar.</div>
</div>
<div class="d-flex flex-wrap gap-2">
<button type="button" class="btn btn-primary rounded-pill" onclick="abrirCamera()"><i class="fas fa-camera me-1"></i>Tirar foto da nota</button>
<button type="button" class="btn btn-outline-primary rounded-pill" onclick="document.getElementById('arquivo_nota').click()"><i class="fas fa-image me-1"></i>Escolher imagem/PDF</button>
</div>
</div>

<input type="file" id="camera_nota" accept="image/*" capture="environment" class="d-none">
<div id="ia_status" class="small mt-3"></div>
<img id="camera_preview" class="camera-preview mt-3" alt="Prévia da nota">
</div>
<?php endif; ?>

<div class="row g-3">
<div class="col-md-7"><label class="form-label fw-bold">Fornecedor *</label><input id="fornecedor_nome" name="fornecedor_nome" class="form-control" required></div>
<div class="col-md-5"><label class="form-label fw-bold">CPF/CNPJ</label><input id="fornecedor_documento" name="fornecedor_documento" class="form-control"></div>
<div class="col-md-4"><label class="form-label fw-bold">Número da nota</label><input id="numero_nota" name="numero_nota" class="form-control"></div>
<div class="col-md-4"><label class="form-label fw-bold">Valor final *</label><input id="valor_final" name="valor_final" class="form-control" required value="<?= number_format((float)$compra['valor_estimado'],2,',','.') ?>"></div>
<div class="col-md-4"><label class="form-label fw-bold">Data da compra</label><input id="data_compra" type="date" name="data_compra" class="form-control" value="<?= date('Y-m-d') ?>"></div>

<div class="col-12">
<label class="form-label fw-bold">Nota / recibo</label>
<input type="file" name="arquivo_nota" id="arquivo_nota" class="form-control" accept=".pdf,image/jpeg,image/png,image/webp">
<small class="text-muted">Ao escolher uma imagem, a leitura por IA é feita automaticamente.</small>
</div>

<div class="col-12"><label class="form-label fw-bold">Comprovante de pagamento</label><input type="file" name="arquivo_comprovante" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp"></div>
</div>

<div id="ia_resultado" class="alert alert-info mt-3 d-none"></div>
</div>
<div class="modal-footer"><button class="btn btn-primary rounded-pill px-4">Salvar compra</button></div>
</form></div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const cameraInput = document.getElementById('camera_nota');
const notaInput = document.getElementById('arquivo_nota');

function abrirCamera() {
    if (cameraInput) cameraInput.click();
}

if (cameraInput) {
    cameraInput.addEventListener('change', async function () {
        if (!this.files || !this.files[0]) return;
        const file = this.files[0];

        // Copia a foto capturada para o input que será salvo como nota.
        try {
            const dt = new DataTransfer();
            dt.items.add(file);
            notaInput.files = dt.files;
        } catch(e) {}

        mostrarPreview(file);
        await lerNotaIA(file);
    });
}

if (notaInput) {
    notaInput.addEventListener('change', async function () {
        if (!this.files || !this.files[0]) return;
        const file = this.files[0];
        if (file.type.startsWith('image/')) {
            mostrarPreview(file);
            await lerNotaIA(file);
        } else {
            setIaStatus('PDF selecionado. Ele será anexado, mas a leitura automática desta versão funciona com foto/imagem.', 'text-muted');
        }
    });
}

function mostrarPreview(file) {
    if (!file.type.startsWith('image/')) return;
    const img = document.getElementById('camera_preview');
    img.src = URL.createObjectURL(file);
    img.style.display = 'block';
}

function setIaStatus(texto, classe='text-primary') {
    const el = document.getElementById('ia_status');
    if (!el) return;
    el.className = 'small mt-3 ' + classe;
    el.innerHTML = texto;
}

async function lerNotaIA(file) {
    setIaStatus('<i class="fas fa-spinner fa-spin me-1"></i>Lendo a nota com IA...', 'text-primary');

    const fd = new FormData();
    fd.append('foto_nota', file);

    try {
        const resp = await fetch('api_compras_ler_nota.php', {method:'POST', body:fd});
        const json = await resp.json();

        if (!json.sucesso) throw new Error(json.erro || 'Falha na leitura.');

        const d = json.dados || {};
        if (d.fornecedor) document.getElementById('fornecedor_nome').value = d.fornecedor;
        if (d.documento) document.getElementById('fornecedor_documento').value = d.documento;
        if (d.numero_nota) document.getElementById('numero_nota').value = d.numero_nota;
        if (d.valor_total && Number(d.valor_total) > 0) {
            document.getElementById('valor_final').value = Number(d.valor_total).toLocaleString('pt-BR',{minimumFractionDigits:2, maximumFractionDigits:2});
        }
        if (d.data && /^\d{4}-\d{2}-\d{2}$/.test(d.data)) {
            document.getElementById('data_compra').value = d.data;
        }

        document.getElementById('ia_dados_json').value = JSON.stringify(d);

        const itens = Array.isArray(d.itens) && d.itens.length
            ? '<br><small>' + d.itens.slice(0,5).map(i => '• ' + (i.descricao || '')).join('<br>') + '</small>'
            : '';

        const box = document.getElementById('ia_resultado');
        box.classList.remove('d-none');
        box.innerHTML = '<b>Dados lidos.</b> Confiança: ' + (d.confianca || '-') +
            (d.descricao ? '<br>' + escapeHtml(d.descricao) : '') + itens;

        setIaStatus('<i class="fas fa-check-circle me-1"></i>Leitura concluída. Confira os campos antes de salvar.', 'text-success');
    } catch (e) {
        setIaStatus('<i class="fas fa-exclamation-triangle me-1"></i>' + escapeHtml(e.message), 'text-danger');
    }
}

function escapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
}
</script>
</body>
</html>
