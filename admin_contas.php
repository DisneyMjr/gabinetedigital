<?php
// ATIVAR EXIBIÇÃO DE ERROS (Para debug)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'config.php';

// --- 0. SEGURANÇA SAAS ---
if (!defined('TENANT_ID')) {
    die("Erro Crítico: Tenant não identificado.");
}

if (function_exists('tem_acesso') && !tem_acesso('financeiro') && !tem_acesso('master')) { 
    die("Acesso Negado."); 
}

$msg = "";

// --- 1. PROCESSAR IMPORTAÇÃO OFX ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['arquivo_ofx'])) {
    try {
        $file = $_FILES['arquivo_ofx']['tmp_name'];
        if (!file_exists($file)) throw new Exception("Arquivo não enviado.");

        $content = file_get_contents($file);
        
        // Localiza o início do XML do OFX
        $parts = explode("<OFX>", $content);
        if (count($parts) < 2) throw new Exception("Formato OFX inválido.");
        
        $xmlContent = "<OFX>" . $parts[1];
        
        // Limpeza de tags OFX (fechando tags como <TRNAMT>-10.50 que não possuem </TRNAMT>)
        $xmlContent = preg_replace('/<(\w+)>([^\n\r<]+)/', '<$1>$2</$1>', $xmlContent);
        
        // Desativa erros de libxml para lidar com tags mal formadas
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent);
        
        if (!$xml) throw new Exception("Erro ao processar estrutura do arquivo.");

        $transacoes = $xml->BANKMSGSRSV1->STMTTRNRS->STMTRS->BANKTRANLIST->STMTTRN;
        $importados = 0;
        $duplicados = 0;

        foreach ($transacoes as $trn) {
            $fitid = (string)$trn->FITID; // ID Único da transação no banco
            $data_raw = substr((string)$trn->DTPOSTED, 0, 8);
            $data_formatada = substr($data_raw, 0, 4) . '-' . substr($data_raw, 4, 2) . '-' . substr($data_raw, 6, 2);
            $valor = (float)$trn->TRNAMT;
            $descricao = (string)($trn->MEMO ?: $trn->TRNTYPE);
            $tipo = ($valor < 0) ? 'Despesa' : 'Receita';
            $valor_abs = abs($valor);

            // Verifica se já existe para este tenant (evita duplicidade)
            $check = $pdo->prepare("SELECT id FROM transparencia WHERE tenant_id = ? AND fitid = ?");
            $check->execute([TENANT_ID, $fitid]);

            if ($check->rowCount() == 0) {
                $sql = "INSERT INTO transparencia (tenant_id, titulo, categoria, valor, data_despesa, descricao, fitid) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)";
                $pdo->prepare($sql)->execute([
                    TENANT_ID, 
                    $descricao, 
                    'Importado (Banco)', 
                    $valor_abs, 
                    $data_formatada, 
                    "Importação Automática OFX - ID: $fitid",
                    $fitid
                ]);
                $importados++;
            } else {
                $duplicados++;
            }
        }
        $msg = "<div class='alert alert-success'>Importação concluída! <b>$importados</b> novos itens, <b>$duplicados</b> já existiam.</div>";
    } catch (Exception $e) {
        $msg = "<div class='alert alert-danger'>Erro OFX: " . $e->getMessage() . "</div>";
    }
}

// --- 2. EXCLUIR DESPESA ---
if (isset($_GET['deletar'])) {
    $id = (int)$_GET['deletar'];
    $stmt = $pdo->prepare("SELECT arquivo FROM transparencia WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$id, TENANT_ID]);
    $arquivo = $stmt->fetchColumn();
    
    if ($arquivo && file_exists($arquivo)) unlink($arquivo);

    $pdo->prepare("DELETE FROM transparencia WHERE id = ? AND tenant_id = ?")->execute([$id, TENANT_ID]);
    header("Location: admin_contas.php");
    exit;
}

// --- 3. CADASTRAR NOVA DESPESA (MANUAL) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao_manual'])) {
    $titulo = $_POST['titulo'];
    $categoria = $_POST['categoria'];
    $data = $_POST['data_despesa'];
    $desc = $_POST['descricao'];
    $valor = str_replace(',', '.', str_replace('.', '', $_POST['valor'])); 
    
    $caminho_arquivo = null;
    if (!empty($_FILES['arquivo']['name'])) {
        $pasta = "img/transparencia/" . TENANT_ID . "/";
        if (!is_dir($pasta)) mkdir($pasta, 0777, true);
        $ext = strtolower(pathinfo($_FILES['arquivo']['name'], PATHINFO_EXTENSION));
        $novo_nome = "doc_" . uniqid() . "." . $ext;
        if (move_uploaded_file($_FILES['arquivo']['tmp_name'], $pasta . $novo_nome)) $caminho_arquivo = $pasta . $novo_nome;
    }

    $sql = "INSERT INTO transparencia (tenant_id, titulo, categoria, valor, data_despesa, descricao, arquivo) VALUES (?, ?, ?, ?, ?, ?, ?)";
    if ($pdo->prepare($sql)->execute([TENANT_ID, $titulo, $categoria, $valor, $data, $desc, $caminho_arquivo])) {
        $msg = "<div class='alert alert-success'>Lançamento salvo com sucesso!</div>";
    }
}

$stmt_lista = $pdo->prepare("SELECT * FROM transparencia WHERE tenant_id = ? ORDER BY data_despesa DESC");
$stmt_lista->execute([TENANT_ID]);
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Gestão Financeira</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-light p-4">

<div class="container-fluid">
    <div class="d-flex justify-content-between mb-4 align-items-center">
        <h3 class="text-primary fw-bold mb-0"><i class="fas fa-file-invoice-dollar"></i> Gestão Financeira</h3>
        <div>
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalOFX">
                <i class="fas fa-file-import"></i> Importar OFX
            </button>
            <a href="admin_painel.php" class="btn btn-secondary">Voltar</a>
        </div>
    </div>

    <?= $msg ?>

    <div class="row">
        <div class="col-md-4">
            <div class="card shadow-sm border-0 mb-4 sticky-top" style="top: 20px;">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Novo Lançamento</h5>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="acao_manual" value="1">
                        <div class="mb-3">
                            <label class="fw-bold">Título / Fornecedor</label>
                            <input type="text" name="titulo" class="form-control" required>
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="fw-bold">Data</label>
                                <input type="date" name="data_despesa" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-6 mb-3">
                                <label class="fw-bold">Valor (R$)</label>
                                <input type="text" name="valor" class="form-control dinheiro" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="fw-bold">Categoria</label>
                            <select name="categoria" class="form-select">
                                <option>Combustível</option>
                                <option>Material de Escritório</option>
                                <option>Divulgação</option>
                                <option>Alimentação</option>
                                <option>Outros</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="fw-bold">Comprovante</label>
                            <input type="file" name="arquivo" class="form-control">
                        </div>
                        <button type="submit" class="btn btn-primary w-100 fw-bold">SALVAR</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card shadow-sm border-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Data</th>
                                <th>Título</th>
                                <th>Valor</th>
                                <th>Origem</th>
                                <th>Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($row = $stmt_lista->fetch(PDO::FETCH_ASSOC)): ?>
                            <tr>
                                <td><?= date('d/m/Y', strtotime($row['data_despesa'])) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($row['titulo']) ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($row['categoria']) ?></small>
                                </td>
                                <td class="fw-bold <?= $row['fitid'] ? 'text-primary' : 'text-danger' ?>">
                                    R$ <?= number_format($row['valor'], 2, ',', '.') ?>
                                </td>
                                <td>
                                    <?= $row['fitid'] ? '<span class="badge bg-info">Bancário</span>' : '<span class="badge bg-warning">Manual</span>' ?>
                                </td>
                                <td>
                                    <a href="?deletar=<?= $row['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Apagar?');"><i class="fas fa-trash"></i></a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalOFX" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" enctype="multipart/form-data" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Importar Extrato Bancário</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">Selecione o arquivo .ofx exportado do seu Internet Banking.</p>
                <input type="file" name="arquivo_ofx" class="form-control" accept=".ofx" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-success">Iniciar Importação</button>
            </div>
        </form>
    </div>
</div>



<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    $(document).ready(function(){
        $('.dinheiro').mask('#.##0,00', {reverse: true});
    });
</script>

</body>
</html>
