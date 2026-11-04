<?php
require 'config.php';   
require 'protecao.php'; 

// --- 0. SEGURANÇA ---
if (!defined('TENANT_ID')) { die("Erro Crítico: Tenant não identificado."); }
if (!tem_acesso('ouvidoria') && !tem_acesso('master')) { die("Acesso Negado."); }

// Pega o nome do usuário logado na sessão
$usuario_atual = $_SESSION['admin_nome'] ?? 'Sistema';

$msg = "";

// ==========================================================
// ATUALIZAÇÃO AUTOMÁTICA DO DB PARA MÚLTIPLOS ANEXOS
// ==========================================================
try {
    // Garante que a coluna suporte uma quantidade gigante de texto (JSON arrays)
    $pdo->exec("ALTER TABLE solicitacoes MODIFY anexo_resposta TEXT");
} catch (Exception $e) { }

// --- MENSAGENS DE FEEDBACK ---
if(isset($_GET['msg'])) {
    if($_GET['msg'] == 'anexo_excluido') $msg = "<div class='alert alert-warning shadow-sm rounded-3 mb-4'><i class='fas fa-trash me-2'></i> Anexo removido com sucesso!</div>";
}

// --- FUNÇÃO ZAP ---
function gerarZapLink($telefone, $nome, $protocolo, $assunto, $status) {
    $num = preg_replace("/[^0-9]/", "", $telefone);
    if(strlen($num) < 10) return "#"; 
    if(strlen($num) <= 11) $num = "55" . $num;
    $primeiro_nome = explode(' ', trim($nome))[0];
    
    if ($status === 'Concluído') {
        $frase_status = "Temos uma ótima notícia: sua solicitação foi concluída! ✅";
    } elseif ($status === 'Concluído Pendente') {
        $frase_status = "Seu pedido já foi encaminhado ao órgão responsável e estamos cobrando a execução. ⏳";
    } elseif ($status === 'Em Análise') {
        $frase_status = "Já estamos analisando o seu caso e buscando a melhor solução. 🔍";
    } else {
        $frase_status = "Recebemos o seu pedido e ele já está na nossa fila de atendimento. 👍";
    }

    $texto = "Olá, *$primeiro_nome*! 🏛️\nSobre sua solicitação: *$assunto* (Prot: *$protocolo*).\n$frase_status";
    return "https://wa.me/" . $num . "?text=" . urlencode($texto);
}

// --- 1. PROCESSAR AÇÕES ---

// 1.1 EXCLUIR UM ANEXO ESPECÍFICO
if (isset($_GET['acao']) && $_GET['acao'] == 'excluir_anexo' && isset($_GET['id']) && isset($_GET['file'])) {
    try {
        $id_anexo = (int)$_GET['id'];
        $arquivo_remover = urldecode($_GET['file']);

        // Busca o campo atual
        $stmt = $pdo->prepare("SELECT anexo_resposta FROM solicitacoes WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$id_anexo, TENANT_ID]);
        $arquivo_bd = $stmt->fetchColumn();

        $lista_anexos = [];
        if (!empty($arquivo_bd)) {
            $decoded = json_decode($arquivo_bd, true);
            $lista_anexos = is_array($decoded) ? $decoded : [$arquivo_bd];
        }

        // Procura e remove o arquivo da array
        $index = array_search($arquivo_remover, $lista_anexos);
        if ($index !== false) {
            unset($lista_anexos[$index]);
            if (file_exists($arquivo_remover)) { @unlink($arquivo_remover); }
        }

        // Reorganiza a array e codifica
        $json_final = !empty($lista_anexos) ? json_encode(array_values($lista_anexos)) : NULL;

        $pdo->prepare("UPDATE solicitacoes SET anexo_resposta = ?, atualizado_por = ? WHERE id = ? AND tenant_id = ?")
            ->execute([$json_final, $usuario_atual, $id_anexo, TENANT_ID]);

        header("Location: admin_solicitacoes.php?msg=anexo_excluido"); exit;
    } catch (Exception $e) { $msg = "<div class='alert alert-danger'>Erro: " . $e->getMessage() . "</div>"; }
}

// 1.2 RESPONDER / EDITAR (COM MÚLTIPLOS UPLOADS)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'responder') {
    try {
        $id = (int)$_POST['id'];
        $resposta = $_POST['resposta_admin'];
        $status = $_POST['status'];
        $data_hoje = date('Y-m-d H:i:s');
        
        // 1. Busca os anexos que já existem no banco
        $stmt_old = $pdo->prepare("SELECT anexo_resposta FROM solicitacoes WHERE id = ? AND tenant_id = ?");
        $stmt_old->execute([$id, TENANT_ID]);
        $old_data = $stmt_old->fetchColumn();
        
        $lista_anexos_finais = [];
        if (!empty($old_data)) {
            $decoded = json_decode($old_data, true);
            $lista_anexos_finais = is_array($decoded) ? $decoded : [$old_data]; // Suporte para sistema antigo
        }

        // 2. Processa os NOVOS arquivos anexados
        if (isset($_FILES['arquivos_oficio']) && !empty($_FILES['arquivos_oficio']['name'][0])) {
            $total_files = count($_FILES['arquivos_oficio']['name']);
            $pasta = "uploads/oficios/";
            if (!is_dir($pasta)) mkdir($pasta, 0777, true);

            for ($i = 0; $i < $total_files; $i++) {
                if ($_FILES['arquivos_oficio']['error'][$i] == 0) {
                    $extensao = strtolower(pathinfo($_FILES['arquivos_oficio']['name'][$i], PATHINFO_EXTENSION));
                    $permitidos = ['pdf', 'jpg', 'jpeg', 'png'];
                    
                    if (in_array($extensao, $permitidos)) {
                        $novo_nome = "oficio_" . $id . "_" . TENANT_ID . "_" . uniqid() . "_" . $i . "." . $extensao;
                        $destino = $pasta . $novo_nome;
                        
                        if (move_uploaded_file($_FILES['arquivos_oficio']['tmp_name'][$i], $destino)) {
                            // Adiciona o novo arquivo na lista
                            $lista_anexos_finais[] = $destino;
                        }
                    }
                }
            }
        }

        // 3. Atualiza o Banco de Dados
        $json_salvar = !empty($lista_anexos_finais) ? json_encode(array_values($lista_anexos_finais)) : NULL;

        $sql = "UPDATE solicitacoes SET resposta_admin = ?, status = ?, data_resposta = ?, atualizado_por = ?, anexo_resposta = ? WHERE id = ? AND tenant_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$resposta, $status, $data_hoje, $usuario_atual, $json_salvar, $id, TENANT_ID]);

        if(function_exists('registrar_log')) registrar_log($pdo, 'Editou Solicitação', "ID: $id | User: $usuario_atual");
        $msg = "<div class='alert alert-success shadow-sm rounded-3 mb-4'><i class='fas fa-check-circle me-2'></i> Atualizado por <b>$usuario_atual</b>!</div>";

    } catch (PDOException $e) {
        $msg = "<div class='alert alert-danger mb-4'>Erro: " . $e->getMessage() . "</div>";
    }
}

// 1.4 DELETAR
if (isset($_GET['deletar'])) {
    try {
        $id_del = (int)$_GET['deletar'];
        
        // Puxa e limpa os arquivos primeiro
        $stmt_del = $pdo->prepare("SELECT anexo_resposta FROM solicitacoes WHERE id = ? AND tenant_id = ?");
        $stmt_del->execute([$id_del, TENANT_ID]);
        $arq_del = $stmt_del->fetchColumn();
        if(!empty($arq_del)) {
            $dec = json_decode($arq_del, true);
            $lista_del = is_array($dec) ? $dec : [$arq_del];
            foreach($lista_del as $f) { if(file_exists($f)) @unlink($f); }
        }

        $pdo->prepare("DELETE FROM solicitacoes WHERE id = ? AND tenant_id = ?")->execute([$id_del, TENANT_ID]);
        header("Location: admin_solicitacoes.php"); exit;
    } catch (Exception $e) { }
}

// --- 2. FILTROS ---
$where = "tenant_id = ?"; 
$params = [TENANT_ID]; 

if (!empty($_GET['filtro_status'])) { $where .= " AND status = ?"; $params[] = $_GET['filtro_status']; }
if (!empty($_GET['data_inicio'])) { $where .= " AND DATE(data_abertura) >= ?"; $params[] = $_GET['data_inicio']; }
if (!empty($_GET['data_fim'])) { $where .= " AND DATE(data_abertura) <= ?"; $params[] = $_GET['data_fim']; }

try {
    $sql = "SELECT * FROM solicitacoes WHERE $where ORDER BY id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $lista = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_encontrado = count($lista);
} catch (PDOException $e) { die("Erro no Banco: " . $e->getMessage()); }
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0"> <title>Ouvidoria</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f3f4f6; font-family: 'Segoe UI', sans-serif; }
        .btn-zap { background-color: #25D366; color: white; border: none; }
        .btn-zap:hover { background-color: #128C7E; color: white; }
        .bg-filter { background-color: #fff; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        @media (max-width: 768px) {
            .table-responsive thead { display: none; } 
            .table-responsive tr { display: block; background: #fff; margin-bottom: 15px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); border: 1px solid #eee; padding: 10px; }
            .table-responsive td { display: flex; justify-content: space-between; align-items: center; text-align: right; padding: 10px 5px; border-bottom: 1px solid #f0f0f0; font-size: 0.9rem; }
            .table-responsive td:last-child { border-bottom: none; justify-content: flex-end; gap: 5px; }
            .table-responsive td::before { content: attr(data-label); font-weight: bold; color: #666; text-transform: uppercase; font-size: 0.75rem; margin-right: 10px; text-align: left; }
            .modal { z-index: 99999 !important; } .modal-backdrop { z-index: 99990 !important; }
        }
    </style>
</head>
<body class="p-3 p-md-4">

<div class="container-fluid container-lg">
    
    <div class="d-flex flex-column flex-md-row justify-content-between mb-4 align-items-center gap-3">
        <div class="text-center text-md-start">
            <h3 class="text-primary fw-bold mb-0"><i class="fas fa-bullhorn"></i> Ouvidoria</h3>
            <small class="text-muted">Gerencie as demandas da população</small>
        </div>
        <div class="d-flex flex-wrap gap-2 justify-content-center">
            <a href="admin_kanban.php" class="btn btn-warning btn-sm fw-bold px-4 rounded-pill shadow-sm text-dark d-flex align-items-center" style="background: #ffc107; border: none;">
                <i class="fas fa-columns me-2"></i> Ver em Fluxo (Kanban)
            </a>
            <a href="admin_painel.php" class="btn btn-secondary btn-sm px-4 rounded-pill d-flex align-items-center">
                <i class="fas fa-arrow-left me-2"></i> Voltar ao Painel
            </a>
        </div>
    </div>

    <?= $msg ?>

    <div class="card mb-4 border-0 bg-filter">
        <div class="card-body p-3 p-md-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-12 col-md-3">
                    <label class="fw-bold small text-muted">Status</label>
                    <select name="filtro_status" class="form-select">
                        <option value="">Todos</option>
                        <option value="Pendente" <?= ($_GET['filtro_status']??'')=='Pendente'?'selected':'' ?>>Pendente</option>
                        <option value="Em Análise" <?= ($_GET['filtro_status']??'')=='Em Análise'?'selected':'' ?>>Em Análise</option>
                        <option value="Concluído Pendente" <?= ($_GET['filtro_status']??'')=='Concluído Pendente'?'selected':'' ?>>Concluído Pendente</option>
                        <option value="Concluído" <?= ($_GET['filtro_status']??'')=='Concluído'?'selected':'' ?>>Concluído</option>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="fw-bold small text-muted">De</label>
                    <input type="date" name="data_inicio" class="form-control" value="<?= $_GET['data_inicio']??'' ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="fw-bold small text-muted">Até</label>
                    <input type="date" name="data_fim" class="form-control" value="<?= $_GET['data_fim']??'' ?>">
                </div>
                <div class="col-12 col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100 fw-bold"><i class="fas fa-filter"></i> Filtrar</button>
                    <a href="admin_solicitacoes.php" class="btn btn-outline-secondary px-3"><i class="fas fa-times"></i></a>
                </div>
            </form>
        </div>
    </div>

    <div class="mb-3 text-muted small">
        <i class="fas fa-list me-1"></i> Encontrados: <strong><?= $total_encontrado ?></strong> registros.
    </div>

    <div class="table-responsive">
        <table class="table align-middle border-0">
            <thead class="table-light shadow-sm rounded">
                <tr>
                    <th class="ps-3 py-3 rounded-start">Status</th>
                    <th class="py-3">Protocolo</th>
                    <th class="py-3">Data</th>
                    <th class="py-3">Cidadão</th>
                    <th class="py-3">Demanda</th>
                    <th class="text-end pe-3 py-3 rounded-end">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if($total_encontrado > 0): ?>
                <?php foreach($lista as $row): 
                    $nome = $row['nome_cidadao'] ?? 'Desconhecido';
                    $zap = $row['whatsapp'] ?? '';
                    $tipo = $row['tipo'] ?? 'Geral';
                    $texto = $row['mensagem'] ?? '';
                    $prot = !empty($row['protocolo']) ? $row['protocolo'] : str_pad($row['id'], 5, '0', STR_PAD_LEFT);
                    
                    $st = $row['status'];
                    if ($st == 'Concluído') { $cor = 'success'; }
                    elseif ($st == 'Concluído Pendente') { $cor = 'info text-dark'; }
                    elseif ($st == 'Em Análise') { $cor = 'primary'; }
                    else { $cor = 'warning text-dark'; }

                    $linkZap = gerarZapLink($zap, $nome, $prot, $tipo, $st);
                    
                    $data_original = $row['data_abertura'] ?? null;
                    if ($data_original) {
                        $data_exibicao = date('d/m/y', strtotime($data_original));
                    } else {
                        $data_exibicao = "--/--/--";
                    }

                    $autor = !empty($row['criado_por']) ? $row['criado_por'] : 'Cidadão (Site/App)';
                    $editor = !empty($row['atualizado_por']) ? $row['atualizado_por'] : '-';

                    // Decodificar Anexos Existentes
                    $anexos_atuais = [];
                    if (!empty($row['anexo_resposta'])) {
                        $dec = json_decode($row['anexo_resposta'], true);
                        $anexos_atuais = is_array($dec) ? $dec : [$row['anexo_resposta']];
                    }
                ?>
                <tr class="bg-white shadow-sm rounded mb-2 border-bottom-0">
                    <td data-label="Status" class="ps-md-3">
                        <span class="badge bg-<?= $cor ?> rounded-pill px-3"><?= $st ?></span>
                    </td>
                    <td data-label="Protocolo">
                        <span class="fw-bold text-secondary">#<?= $prot ?></span>
                    </td>
                    <td data-label="Data">
                        <span class="text-muted"><?= $data_exibicao ?></span>
                    </td>
                    <td data-label="Cidadão">
                        <div class="d-flex flex-column align-items-end align-items-md-start">
                            <span class="fw-bold text-dark"><?= $nome ?></span>
                            <small class="text-muted"><i class="fab fa-whatsapp text-success"></i> <?= $zap ?></small>
                        </div>
                    </td>
                    <td data-label="Demanda">
                        <div class="d-flex flex-column align-items-end align-items-md-start">
                            <span class="text-primary fw-bold text-uppercase" style="font-size: 0.8rem;"><?= $tipo ?></span>
                            <span class="text-muted small text-truncate d-inline-block" style="max-width: 200px;"><?= $texto ?></span>
                            <?php if(!empty($anexos_atuais)): ?>
                                <span class="badge bg-light text-dark border mt-1"><i class="fas fa-paperclip"></i> <?= count($anexos_atuais) ?> Anexo(s)</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td data-label="Ações" class="pe-md-3 text-end">
                        <a href="<?= $linkZap ?>" target="_blank" class="btn btn-sm btn-zap rounded-circle shadow-sm" style="width: 32px; height: 32px; padding: 0; line-height: 32px;">
                            <i class="fab fa-whatsapp"></i>
                        </a>
                        <button type="button" class="btn btn-sm btn-primary rounded-circle shadow-sm ms-1" style="width: 32px; height: 32px; padding: 0; line-height: 32px;" data-bs-toggle="modal" data-bs-target="#modal-<?= $row['id'] ?>">
                            <i class="fas fa-pen"></i>
                        </button>
                    </td>
                </tr>

                <div class="modal fade" id="modal-<?= $row['id'] ?>" aria-hidden="true" tabindex="-1">
                    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                        <div class="modal-content border-0 shadow-lg">
                            <div class="modal-header bg-primary text-white">
                                <h5 class="modal-title fw-bold">Solicitação #<?= $prot ?></h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            
                            <form method="POST" enctype="multipart/form-data">
                                <div class="modal-body bg-light">
                                    <input type="hidden" name="acao" value="responder">
                                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                    
                                    <div class="card border-0 shadow-sm mb-3">
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-6 mb-2">
                                                    <label class="small text-muted fw-bold">NOME</label>
                                                    <div class="fw-bold"><?= $nome ?></div>
                                                </div>
                                                <div class="col-6 mb-2">
                                                    <label class="small text-muted fw-bold">LOCAL</label>
                                                    <div><?= $row['bairro'] ?? '-' ?></div>
                                                </div>
                                                <div class="col-12">
                                                    <label class="small text-muted fw-bold">RELATO</label>
                                                    <div class="p-2 bg-light border rounded fst-italic small">"<?= nl2br($texto) ?>"</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <?php if(!empty($row['foto'])): ?>
                                        <div class="mb-3 text-center">
                                            <img src="<?= $row['foto'] ?>" class="img-fluid rounded border shadow-sm" style="max-height: 150px;">
                                        </div>
                                    <?php endif; ?>

                                    <div class="card border-0 shadow-sm">
                                        <div class="card-header bg-white fw-bold text-primary"><i class="fas fa-user-edit"></i> Área do Gabinete</div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-5 mb-3">
                                                    <label class="fw-bold mb-1">Status</label>
                                                    <select name="status" class="form-select form-select-lg mb-3 bg-white" style="cursor: pointer;">
                                                        <option value="Pendente" <?= $st=='Pendente'?'selected':'' ?>>Pendente</option>
                                                        <option value="Em Análise" <?= $st=='Em Análise'?'selected':'' ?>>Em Análise</option>
                                                        <option value="Concluído Pendente" <?= $st=='Concluído Pendente'?'selected':'' ?>>Concluído Pendente</option>
                                                        <option value="Concluído" <?= $st=='Concluído'?'selected':'' ?>>Concluído</option>
                                                    </select>
                                                    
                                                    <label class="fw-bold small text-muted"><i class="fas fa-paperclip"></i> Enviar Arquivos (Ofícios/Fotos)</label>
                                                    <input type="file" name="arquivos_oficio[]" class="form-control form-control-sm border-primary" accept="application/pdf, image/*" multiple>
                                                    <small class="text-muted d-block mt-1" style="font-size: 0.7rem;">Pode selecionar vários arquivos ao mesmo tempo.</small>
                                                    
                                                    <?php if(!empty($anexos_atuais)): ?>
                                                        <div class="mt-3 bg-light p-2 rounded border">
                                                            <label class="fw-bold small text-muted mb-2 d-block"><i class="fas fa-folder-open"></i> Anexos Salvos</label>
                                                            <div class="d-flex flex-column gap-2">
                                                            <?php foreach($anexos_atuais as $anx): 
                                                                $nome_arq = basename($anx);
                                                            ?>
                                                                <div class="d-flex justify-content-between align-items-center bg-white border p-1 rounded shadow-sm">
                                                                    <span class="text-truncate small ms-1" style="max-width: 140px;" title="<?= htmlspecialchars($nome_arq) ?>"><?= htmlspecialchars($nome_arq) ?></span>
                                                                    <div class="d-flex gap-1 flex-shrink-0">
                                                                        <a href="<?= $anx ?>" target="_blank" class="btn btn-sm btn-outline-success py-0 px-2" title="Visualizar"><i class="fas fa-eye"></i></a>
                                                                        <a href="?acao=excluir_anexo&id=<?= $row['id'] ?>&file=<?= urlencode($anx) ?>" class="btn btn-sm btn-outline-danger py-0 px-2" title="Excluir" onclick="return confirm('Apagar este anexo permanentemente?')"><i class="fas fa-trash"></i></a>
                                                                    </div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="col-md-7">
                                                    <label class="fw-bold mb-1">Resposta / Solução</label>
                                                    <textarea name="resposta_admin" class="form-control" rows="8" placeholder="Descreva a solução..."><?= $row['resposta_admin'] ?? '' ?></textarea>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="card-footer bg-light py-1">
                                            <div class="d-flex justify-content-between small text-muted" style="font-size: 0.75rem;">
                                                <span><i class="fas fa-plus-circle"></i> Criado por: <strong><?= $autor ?></strong></span>
                                                <span><i class="fas fa-pen"></i> Atualizado por: <strong><?= $editor ?></strong></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer bg-white">
                                    <a href="imprimir_os.php?id=<?= $row['id'] ?>" target="_blank" class="btn btn-outline-dark btn-sm me-auto"><i class="fas fa-print"></i> Imprimir</a>
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                                    <button type="submit" class="btn btn-success fw-bold px-4">Salvar Alterações</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6" class="text-center py-5 text-muted">Nenhuma solicitação encontrada.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        var modais = document.querySelectorAll('.modal');
        modais.forEach(function(modal) { document.body.appendChild(modal); modal.removeAttribute('tabindex'); modal.removeAttribute('aria-hidden'); });
        document.querySelectorAll('.modal select, .modal input, .modal textarea').forEach(el => { el.addEventListener('touchstart', function(e) { e.stopPropagation(); }, {passive: true}); });
    });
</script>
</body>
</html>