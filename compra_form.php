<?php
session_start();
require 'config.php';
require 'protecao.php';
require 'compras_lib.php';

if (!compras_pode('criar')) die('Acesso negado.');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$compra = null;
if ($id) {
    $compra = compras_buscar($pdo, $id);
    if (!compras_pode('editar') && (int)$compra['solicitante_id'] !== (int)compras_usuario_id()) {
        die('Acesso negado.');
    }
}

$erro = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        $titulo = trim($_POST['titulo'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '');
        $justificativa = trim($_POST['justificativa'] ?? '');
        $categoria = trim($_POST['categoria'] ?? '');
        $centro = trim($_POST['centro_custo'] ?? '');
        $quantidade = (float)str_replace(',', '.', $_POST['quantidade'] ?? '1');
        $valor = str_replace(['R$','.',' '], '', $_POST['valor_estimado'] ?? '');
        $valor = (float)str_replace(',', '.', $valor);
        $data = $_POST['data_necessidade'] ?: null;

        if ($titulo==='' || $valor<=0) throw new Exception('Informe o que será comprado e o valor estimado.');

        $orcamento = compras_upload('arquivo_orcamento','orc');

        if ($id) {
            $sql = "UPDATE compras SET titulo=?, descricao=?, justificativa=?, categoria=?, centro_custo=?, quantidade=?, valor_estimado=?, data_necessidade=?";
            $params = [$titulo,$descricao,$justificativa,$categoria,$centro,$quantidade,$valor,$data];
            if ($orcamento) { $sql .= ", arquivo_orcamento=?"; $params[]=$orcamento; }
            $sql .= " WHERE id=? AND tenant_id=?";
            array_push($params,$id,TENANT_ID);
            $pdo->prepare($sql)->execute($params);
            compras_registrar_historico($pdo,$id,'edicao',null,null,'Solicitação atualizada.');
        } else {
            $cfg = compras_config($pdo);
            $status = !empty($cfg['exigir_aprovacao']) ? 'em_aprovacao' : 'aprovada';
            $st=$pdo->prepare("INSERT INTO compras
                (tenant_id,solicitante_id,titulo,descricao,justificativa,categoria,centro_custo,quantidade,valor_estimado,status,data_necessidade,arquivo_orcamento)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $st->execute([TENANT_ID,compras_usuario_id(),$titulo,$descricao,$justificativa,$categoria,$centro,$quantidade,$valor,$status,$data,$orcamento]);
            $id=(int)$pdo->lastInsertId();

            if (!empty($cfg['exigir_aprovacao'])) {
                $total = compras_inicializar_aprovacoes($pdo, $id, $valor);
                compras_registrar_historico($pdo,$id,'criacao',null,'em_aprovacao','Solicitação criada com '.$total.' nível(is) de aprovação.');
            } else {
                compras_registrar_historico($pdo,$id,'criacao',null,'aprovada','Solicitação criada sem necessidade de aprovação.');
            }
        }
        header("Location: compra_detalhe.php?id=".$id);
        exit;
    } catch(Exception $e) {$erro=$e->getMessage();}
}
?>
<!doctype html><html lang="pt-br"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $id?'Editar':'Nova' ?> compra</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="bg-light">
<div class="container py-4" style="max-width:900px">
<a href="<?= $id?'compra_detalhe.php?id='.$id:'admin_compras.php' ?>" class="btn btn-sm btn-outline-secondary rounded-pill mb-3">← Voltar</a>
<div class="card border-0 shadow-sm rounded-4">
<div class="card-body p-4 p-lg-5">
<h3 class="fw-bold mb-4"><?= $id?'Editar solicitação':'Nova solicitação de compra' ?></h3>
<?php if($erro): ?><div class="alert alert-danger"><?= compras_h($erro) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="id" value="<?= $id ?>">
<div class="mb-3"><label class="form-label fw-bold">O que precisa comprar? *</label><input name="titulo" class="form-control form-control-lg" required value="<?= compras_h($compra['titulo']??'') ?>"></div>
<div class="mb-3"><label class="form-label fw-bold">Descrição</label><textarea name="descricao" class="form-control" rows="3"><?= compras_h($compra['descricao']??'') ?></textarea></div>
<div class="mb-3"><label class="form-label fw-bold">Justificativa</label><textarea name="justificativa" class="form-control" rows="3"><?= compras_h($compra['justificativa']??'') ?></textarea></div>
<div class="row g-3">
<div class="col-md-6"><label class="form-label fw-bold">Categoria</label><input name="categoria" class="form-control" value="<?= compras_h($compra['categoria']??'') ?>" placeholder="Ex.: material, evento, gráfica"></div>
<div class="col-md-6"><label class="form-label fw-bold">Centro de custo</label><input name="centro_custo" class="form-control" value="<?= compras_h($compra['centro_custo']??'') ?>"></div>
<div class="col-md-4"><label class="form-label fw-bold">Quantidade</label><input name="quantidade" class="form-control" value="<?= compras_h($compra['quantidade']??'1') ?>"></div>
<div class="col-md-4"><label class="form-label fw-bold">Valor estimado *</label><input name="valor_estimado" class="form-control" required value="<?= isset($compra['valor_estimado'])?number_format((float)$compra['valor_estimado'],2,',','.') : '' ?>" placeholder="0,00"></div>
<div class="col-md-4"><label class="form-label fw-bold">Necessário até</label><input type="date" name="data_necessidade" class="form-control" value="<?= compras_h($compra['data_necessidade']??'') ?>"></div>
<div class="col-12"><label class="form-label fw-bold">Orçamento / proposta</label><input type="file" name="arquivo_orcamento" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp"></div>
</div>
<button class="btn btn-primary btn-lg rounded-pill px-5 mt-4"><?= $id?'Salvar alterações':'Enviar solicitação' ?></button>
</form>
</div></div></div></body></html>