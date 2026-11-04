<?php
require 'config.php';
require 'protecao.php';

// --- CORREÇÃO AUTOMÁTICA DO BANCO DE DADOS ---
try {
    // Muda a coluna titulo para TEXT para aceitar ementas gigantes
    $pdo->exec("ALTER TABLE projetos_lei MODIFY titulo TEXT");
} catch (Exception $e) { 
    // Ignora se já estiver como TEXT
}

if (!defined('TENANT_ID')) die("Acesso não autorizado.");

// 1. SALVAR / EDITAR
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'salvar') {
    $titulo = $_POST['titulo'];
    $tipo = $_POST['tipo'];
    $numero = $_POST['numero'];
    $ano = $_POST['ano'];
    $status = $_POST['status'];
    $resumo = $_POST['resumo'];
    
    // ==========================================
    // UPLOAD BLINDADO DE PDF
    // ==========================================
    $caminho_pdf = "";
    $erro_upload = "";

    // Verifica se enviou algum arquivo e se não houve erro de "arquivo não selecionado" (Erro 4)
    if (isset($_FILES['arquivo']) && $_FILES['arquivo']['error'] !== UPLOAD_ERR_NO_FILE) {
        
        if ($_FILES['arquivo']['error'] === UPLOAD_ERR_OK) {
            
            // Usa o caminho absoluto (__DIR__) para o PHP não se perder nas pastas
            $dir_absoluto = __DIR__ . "/img/tenants/" . TENANT_ID . "/docs/";
            $dir_relativo = "img/tenants/" . TENANT_ID . "/docs/"; // O que vai ser salvo no Banco
            
            // Tenta criar a pasta se não existir (com permissões seguras)
            if (!is_dir($dir_absoluto)) {
                if (!mkdir($dir_absoluto, 0755, true)) {
                    $erro_upload = "Erro de permissão: O sistema não conseguiu criar a pasta do cliente.";
                }
            }

            if (empty($erro_upload)) {
                $ext = strtolower(pathinfo($_FILES['arquivo']['name'], PATHINFO_EXTENSION));
                
                if ($ext == 'pdf') {
                    $novo_nome = "proj_" . time() . "_" . uniqid() . ".pdf";
                    $destino_final = $dir_absoluto . $novo_nome;

                    // Tenta mover o arquivo temporário para a pasta final
                    if (move_uploaded_file($_FILES['arquivo']['tmp_name'], $destino_final)) {
                        $caminho_pdf = $dir_relativo . $novo_nome; // Sucesso!
                    } else {
                        $erro_upload = "O servidor bloqueou a gravação do arquivo na pasta de destino.";
                    }
                } else {
                    $erro_upload = "Formato inválido. Apenas arquivos PDF são aceitos.";
                }
            }
        } else {
            // O PHP barrou o upload antes mesmo de chegar no nosso código. Vamos descobrir o motivo:
            $erros_php = [
                UPLOAD_ERR_INI_SIZE => "O PDF é maior que o limite permitido pelo seu servidor (php.ini).",
                UPLOAD_ERR_FORM_SIZE => "O arquivo é muito grande.",
                UPLOAD_ERR_PARTIAL => "O upload foi interrompido no meio do caminho (internet falhou).",
                UPLOAD_ERR_NO_TMP_DIR => "Erro no Servidor: Pasta temporária ausente.",
                UPLOAD_ERR_CANT_WRITE => "Erro no Servidor: Falha ao escrever no disco."
            ];
            $codigo_erro = $_FILES['arquivo']['error'];
            $erro_upload = $erros_php[$codigo_erro] ?? "Erro desconhecido no upload (Código: $codigo_erro).";
        }
    }

    // Se a variável de erro foi preenchida, trava tudo e mostra na tela!
    if (!empty($erro_upload)) {
        die("<div style='font-family: Arial; text-align: center; padding: 50px;'>
                <h2 style='color: red;'>Upload Falhou ❌</h2>
                <p><strong>Motivo:</strong> $erro_upload</p>
                <a href='admin_projetos.php' style='padding: 10px 20px; background: #0d6efd; color: white; text-decoration: none; border-radius: 5px;'>Voltar e tentar novamente</a>
             </div>");
    }
    // ==========================================

    $sql = "INSERT INTO projetos_lei (tenant_id, titulo, tipo, numero, ano, status, resumo, arquivo_pdf) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $pdo->prepare($sql)->execute([TENANT_ID, $titulo, $tipo, $numero, $ano, $status, $resumo, $caminho_pdf]);
    
    header("Location: admin_projetos.php?sucesso=1");
    exit;
}

// 2. EXCLUIR
if (isset($_GET['excluir'])) {
    $id = (int)$_GET['excluir'];
    // Remove o arquivo antes
    $stmt = $pdo->prepare("SELECT arquivo_pdf FROM projetos_lei WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$id, TENANT_ID]);
    $arq = $stmt->fetchColumn();
    if ($arq && file_exists($arq)) unlink($arq);

    $pdo->prepare("DELETE FROM projetos_lei WHERE id = ? AND tenant_id = ?")->execute([$id, TENANT_ID]);
    header("Location: admin_projetos.php?deletado=1");
    exit;
}

// 3. LISTAR
$lista = $pdo->prepare("SELECT * FROM projetos_lei WHERE tenant_id = ? ORDER BY ano DESC, id DESC");
$lista->execute([TENANT_ID]);
$projetos = $lista->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Projetos de Lei - Gabinete Digital</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f3f4f6; font-family: 'Segoe UI', sans-serif; }
        .badge-status { font-size: 0.8rem; padding: 5px 10px; border-radius: 20px; }
        .bg-tramitacao { background-color: #fff3cd; color: #856404; }
        .bg-aprovado { background-color: #d4edda; color: #155724; }
        .bg-arquivado { background-color: #f8d7da; color: #721c24; }
    </style>
</head>
<body class="p-4">

<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold m-0"><i class="fas fa-file-signature text-primary me-2"></i> Projetos Legislativos</h3>
            <p class="text-muted small">Gerencie suas leis, moções e requerimentos.</p>
        </div>
        <div>
            <a href="admin_painel.php" class="btn btn-outline-secondary rounded-pill me-2">Voltar</a>
            <button class="btn btn-primary rounded-pill fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#modalNovo">
                <i class="fas fa-plus me-1"></i> Novo Projeto
            </button>
        </div>
    </div>

    <?php if(isset($_GET['sucesso'])): ?>
        <div class="alert alert-success rounded-pill border-0 shadow-sm"><i class="fas fa-check-circle me-2"></i> Projeto cadastrado com sucesso!</div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-4">Tipo/Número</th>
                        <th>Título</th>
                        <th>Status</th>
                        <th>Arquivo</th>
                        <th class="text-end pe-4">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($projetos as $p): 
                        $statusClass = 'bg-light text-dark';
                        if($p['status'] == 'Aprovado') $statusClass = 'bg-aprovado';
                        if($p['status'] == 'Em Tramitação') $statusClass = 'bg-tramitacao';
                        if($p['status'] == 'Arquivado') $statusClass = 'bg-arquivado';
                    ?>
                    <tr>
                        <td class="ps-4">
                            <span class="fw-bold text-primary"><?= $p['tipo'] ?></span><br>
                            <small class="text-muted">Nº <?= $p['numero'] ?>/<?= $p['ano'] ?></small>
                        </td>
                        <td>
                            <span class="fw-bold d-block text-truncate" style="max-width: 350px;"><?= $p['titulo'] ?></span>
                            <small class="text-muted text-truncate d-block" style="max-width: 350px;"><?= $p['resumo'] ?></small>
                        </td>
                        <td><span class="badge badge-status <?= $statusClass ?>"><?= $p['status'] ?></span></td>
                        <td>
                            <?php if(!empty($p['arquivo_pdf'])): ?>
                                <a href="<?= $p['arquivo_pdf'] ?>" target="_blank" class="btn btn-sm btn-light border text-danger fw-bold"><i class="fas fa-file-pdf"></i> PDF</a>
                            <?php else: ?>
                                <span class="text-muted small">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-4">
                            <a href="?excluir=<?= $p['id'] ?>" onclick="return confirm('Tem certeza?')" class="btn btn-sm btn-outline-danger border-0"><i class="fas fa-trash"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($projetos)): ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted">Nenhum projeto cadastrado ainda.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalNovo" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form class="modal-content border-0 shadow-lg rounded-4" method="POST" enctype="multipart/form-data">
            <div class="modal-header border-0">
                <h5 class="fw-bold">Cadastrar Atuação Legislativa</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" name="acao" value="salvar">
                
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label fw-bold small text-muted">TIPO</label>
                        <select name="tipo" class="form-select">
                            <option>Projeto de Lei</option>
                            <option>Requerimento</option>
                            <option>Moção</option>
                            <option>Indicação</option>
                            <option>Emenda</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold small text-muted">NÚMERO</label>
                        <input type="text" name="numero" class="form-control" placeholder="ex: 123" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold small text-muted">ANO</label>
                        <input type="number" name="ano" class="form-control" value="<?= date('Y') ?>" required>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold small text-muted">TÍTULO / EMENTA</label>
                    <textarea name="titulo" class="form-control" rows="2" placeholder="Ex: Dispõe sobre a criação do programa..." required></textarea>
                    <small class="text-muted" style="font-size: 11px;">Pode digitar textos longos. O limite foi expandido.</small>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold small text-muted">RESUMO / DETALHES</label>
                    <textarea name="resumo" class="form-control" rows="3"></textarea>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold small text-muted">STATUS ATUAL</label>
                        <select name="status" class="form-select">
                            <option>Em Tramitação</option>
                            <option>Aprovado</option>
                            <option>Vetado</option>
                            <option>Arquivado</option>
                            <option>Sancionado</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small text-muted">DOCUMENTO (PDF)</label>
                        <input type="file" name="arquivo" class="form-control" accept="application/pdf">
                    </div>
                </div>

            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light rounded-pill" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">SALVAR PROJETO</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>