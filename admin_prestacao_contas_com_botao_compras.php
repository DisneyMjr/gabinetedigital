<?php
// admin_prestacao_contas.php
// Módulo de Gestão Financeira com Upload de Comprovantes (PDF) e Extratos (OFX)

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require 'config.php';
require 'protecao.php';

// Fallback de escape HTML caso o helper h() não exista em config.php/protecao.php
if (!function_exists('h')) {
    function h($valor) {
        return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
    }
}

if (!defined('TENANT_ID')) die("Acesso Restrito.");

if (function_exists('tem_acesso') && !tem_acesso('financeiro') && !tem_acesso('master')) {
    die("Acesso Negado.");
}

// Cria a pasta de uploads se não existir
$diretorio_uploads = __DIR__ . '/uploads/financeiro/';
if (!is_dir($diretorio_uploads)) {
    @mkdir($diretorio_uploads, 0755, true);
}

// =========================================================================
// PROCESSA AÇÕES (SALVAR COM PDF, OFX, CONCLUIR, DELETAR)
// =========================================================================
$msg = "";

// 1. SALVAR LANÇAMENTO MANUAL (COM UPLOAD DE PDF/IMAGEM)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'salvar') {
    try {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : 0;
        $tipo = $_POST['tipo'];
        $natureza = $_POST['natureza'] ?? 'Variável';
        $descricao = trim($_POST['descricao']);
        $data_vencimento = $_POST['data_vencimento'];
        $status = $_POST['status'];
        $obs = trim($_POST['observacoes'] ?? '');

        $valor_bruto = str_replace(['R$', '.', ' '], '', $_POST['valor']);
        $valor_sql = str_replace(',', '.', $valor_bruto);

        if (empty($descricao) || empty($valor_sql) || empty($data_vencimento)) {
            throw new Exception("Preencha os campos obrigatórios.");
        }

        // Lida com o Upload do Comprovante (PDF, JPG, PNG)
        $nome_arquivo_salvo = null;
        if (isset($_FILES['comprovante']) && $_FILES['comprovante']['error'] == 0) {
            $extensao = strtolower(pathinfo($_FILES['comprovante']['name'], PATHINFO_EXTENSION));
            $permitidas = ['pdf', 'jpg', 'jpeg', 'png'];
            if (!in_array($extensao, $permitidas)) {
                throw new Exception("Envie apenas arquivos PDF ou Imagens (JPG, PNG).");
            }
            // Gera um nome único para não dar conflito
            $nome_arquivo_salvo = TENANT_ID . "_comp_" . time() . "_" . rand(100,999) . "." . $extensao;
            move_uploaded_file($_FILES['comprovante']['tmp_name'], $diretorio_uploads . $nome_arquivo_salvo);
        }

        if ($id > 0) {
            if ($nome_arquivo_salvo) {
                // Atualiza com novo arquivo
                $stmt = $pdo->prepare("UPDATE prestacao_contas SET tipo=?, natureza=?, descricao=?, valor=?, data_vencimento=?, status=?, observacoes=?, arquivo_comprovante=? WHERE id=? AND tenant_id=?");
                $stmt->execute([$tipo, $natureza, $descricao, $valor_sql, $data_vencimento, $status, $obs, $nome_arquivo_salvo, $id, TENANT_ID]);
            } else {
                // Atualiza sem mexer no arquivo atual
                $stmt = $pdo->prepare("UPDATE prestacao_contas SET tipo=?, natureza=?, descricao=?, valor=?, data_vencimento=?, status=?, observacoes=? WHERE id=? AND tenant_id=?");
                $stmt->execute([$tipo, $natureza, $descricao, $valor_sql, $data_vencimento, $status, $obs, $id, TENANT_ID]);
            }
            $msg = "<div class='alert alert-success shadow-sm'><i class='fas fa-check-circle'></i> Lançamento atualizado!</div>";
        } else {
            $stmt = $pdo->prepare("INSERT INTO prestacao_contas (tenant_id, tipo, natureza, descricao, valor, data_vencimento, status, observacoes, arquivo_comprovante) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([TENANT_ID, $tipo, $natureza, $descricao, $valor_sql, $data_vencimento, $status, $obs, $nome_arquivo_salvo]);
            $msg = "<div class='alert alert-success shadow-sm'><i class='fas fa-check-circle'></i> Lançamento registrado com sucesso!</div>";
        }
    } catch (Exception $e) {
        $msg = "<div class='alert alert-danger shadow-sm'><i class='fas fa-exclamation-triangle'></i> Erro: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// 2. IMPORTAR ARQUIVO OFX (EXTRATO BANCÁRIO)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'importar_ofx') {
    if (isset($_FILES['arquivo_ofx']) && $_FILES['arquivo_ofx']['error'] == 0) {
        try {
            $conteudo_ofx = file_get_contents($_FILES['arquivo_ofx']['tmp_name']);
            
            // Um parser simples para extrair os blocos STMTTRN do padrão OFX
            preg_match_all('/<STMTTRN>(.*?)<\/STMTTRN>/s', $conteudo_ofx, $transacoes);
            
            $inseridos = 0;
            $pdo->beginTransaction();
            $stmtInsertOFX = $pdo->prepare("INSERT INTO prestacao_contas (tenant_id, tipo, natureza, descricao, valor, data_vencimento, status, observacoes) VALUES (?, ?, 'Variável', ?, ?, ?, 'Pago', 'Importado via OFX')");

            foreach($transacoes[1] as $trn) {
                preg_match('/<TRNAMT>([^<]+)/', $trn, $amt);
                preg_match('/<DTPOSTED>([^<]+)/', $trn, $date);
                preg_match('/<MEMO>([^<]+)/', $trn, $memo);
                
                if (isset($amt[1]) && isset($date[1])) {
                    $valor_ofx = (float)trim($amt[1]);
                    // Se for positivo é Receita, se for negativo é Despesa
                    $tipo_ofx = ($valor_ofx >= 0) ? 'Receita' : 'Despesa';
                    $valor_ofx = abs($valor_ofx); // Salva sempre positivo no banco
                    
                    // Formata a Data do OFX (YYYYMMDD) para (YYYY-MM-DD)
                    $data_ofx = substr(trim($date[1]), 0, 4) . '-' . substr(trim($date[1]), 4, 2) . '-' . substr(trim($date[1]), 6, 2);
                    $descricao_ofx = isset($memo[1]) ? trim($memo[1]) : 'Transação Bancária';

                    $stmtInsertOFX->execute([TENANT_ID, $tipo_ofx, $descricao_ofx, $valor_ofx, $data_ofx]);
                    $inseridos++;
                }
            }
            $pdo->commit();
            $msg = "<div class='alert alert-success shadow-sm'><i class='fas fa-sync-alt'></i> Sincronização concluída! $inseridos transações do OFX foram adicionadas ao painel.</div>";

        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = "<div class='alert alert-danger shadow-sm'><i class='fas fa-times'></i> Falha ao ler OFX: Formato de arquivo não suportado.</div>";
        }
    }
}

// 3. PAGAR / DELETAR
if (isset($_GET['pagar'])) {
    $id = (int)$_GET['pagar'];
    $pdo->prepare("UPDATE prestacao_contas SET status = 'Pago' WHERE id = ? AND tenant_id = ?")->execute([$id, TENANT_ID]);
    header("Location: admin_prestacao_contas.php?msg=pago"); exit;
}
if (isset($_GET['deletar'])) {
    $id = (int)$_GET['deletar'];
    // Se tiver arquivo, apaga do servidor também para não gastar espaço
    $stmtDel = $pdo->prepare("SELECT arquivo_comprovante FROM prestacao_contas WHERE id = ? AND tenant_id = ?");
    $stmtDel->execute([$id, TENANT_ID]);
    $arq = $stmtDel->fetchColumn();
    if ($arq && file_exists($diretorio_uploads . $arq)) { @unlink($diretorio_uploads . $arq); }

    $pdo->prepare("DELETE FROM prestacao_contas WHERE id = ? AND tenant_id = ?")->execute([$id, TENANT_ID]);
    header("Location: admin_prestacao_contas.php?msg=deletado"); exit;
}

if (isset($_GET['msg']) && $_GET['msg'] == 'pago') $msg = "<div class='alert alert-success shadow-sm'><i class='fas fa-check-double'></i> Marcado como Pago/Recebido!</div>";
if (isset($_GET['msg']) && $_GET['msg'] == 'deletado') $msg = "<div class='alert alert-success shadow-sm'><i class='fas fa-trash'></i> Lançamento apagado!</div>";

// =========================================================================
// MÉTRICAS DO PAINEL E LISTAGEM
// =========================================================================
$stmtMetricas = $pdo->prepare("SELECT tipo, natureza, status, valor FROM prestacao_contas WHERE tenant_id = ?");
$stmtMetricas->execute([TENANT_ID]);
$todas_transacoes = $stmtMetricas->fetchAll(PDO::FETCH_ASSOC);

$orcamento_total = 0; $despesas_pagas = 0; $despesas_pendentes = 0; $gastos_fixos = 0; $gastos_variaveis = 0;
foreach ($todas_transacoes as $t) {
    if ($t['tipo'] === 'Receita') { $orcamento_total += $t['valor']; } 
    elseif ($t['tipo'] === 'Despesa') {
        if ($t['status'] === 'Pago') {
            $despesas_pagas += $t['valor'];
            if ($t['natureza'] === 'Fixo') $gastos_fixos += $t['valor']; else $gastos_variaveis += $t['valor'];
        } else {
            $despesas_pendentes += $t['valor'];
        }
    }
}

$saldo_disponivel = $orcamento_total - $despesas_pagas;
$projecao_total = $despesas_pagas + $despesas_pendentes;
$saldo_projetado = $orcamento_total - $projecao_total;
$porcentagem_uso = ($orcamento_total > 0) ? ($projecao_total / $orcamento_total) * 100 : 0;

$cor_barra = 'bg-success';
if ($porcentagem_uso > 75) $cor_barra = 'bg-warning';
if ($porcentagem_uso > 90) $cor_barra = 'bg-danger';

function formataMoeda($valor) { return 'R$ ' . number_format($valor, 2, ',', '.'); }

$mes_filtro = $_GET['mes'] ?? date('Y-m');
$where_list = "tenant_id = ? AND DATE_FORMAT(data_vencimento, '%Y-%m') = ?";
$params_list = [TENANT_ID, $mes_filtro];

if (!empty($_GET['tipo']) && $_GET['tipo'] !== 'Todos') {
    $where_list .= " AND tipo = ?";
    $params_list[] = $_GET['tipo'];
}

$sqlLista = "SELECT * FROM prestacao_contas WHERE $where_list ORDER BY data_vencimento ASC";
$stmtLista = $pdo->prepare($sqlLista);
$stmtLista->execute($params_list);
$lancamentos = $stmtLista->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Prestação de Contas</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f3f4f6; font-family: 'Inter', sans-serif; }
        .card-stats { border: none; border-radius: 15px; transition: 0.3s; }
        .card-stats:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.1) !important; }
        .icon-box { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
        .receita-row { border-left: 4px solid #198754 !important; }
        .despesa-row { border-left: 4px solid #dc3545 !important; }
    </style>
</head>
<body class="p-4">

<div class="container bg-white p-4 rounded-4 shadow-sm" style="max-width: 1200px;">
    
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 gap-3">
        <div>
            <h3 class="text-primary fw-bold mb-0"><i class="fas fa-file-invoice-dollar me-2"></i> Prestação de Contas</h3>
            <small class="text-muted">Controle de Orçamento e Lançamento com Comprovantes.</small>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="admin_painel.php" class="btn btn-outline-secondary btn-sm rounded-pill fw-bold px-3"><i class="fas fa-arrow-left me-1"></i> Voltar</a>

            <!-- ACESSO RÁPIDO AO MÓDULO DE COMPRAS -->
            <a href="admin_compras.php" class="btn btn-success btn-sm rounded-pill fw-bold px-3 shadow-sm">
                <i class="fas fa-cart-shopping me-1"></i> Compras
            </a>
            
            <!-- NOVO: BOTÃO DE OFX AGORA USA DATA-BS-TOGGLE -->
            <button type="button" class="btn btn-dark btn-sm fw-bold px-3 rounded-pill shadow-sm" data-bs-toggle="modal" data-bs-target="#modalOFX">
                <i class="fas fa-sync-alt me-1"></i> Importar OFX
            </button>
            
            <!-- NOVO: BOTÃO DE NOVO LANÇAMENTO AGORA USA DATA-BS-TOGGLE NATIVO DO BOOTSTRAP -->
            <button type="button" class="btn btn-primary btn-sm fw-bold px-3 rounded-pill shadow-sm" data-bs-toggle="modal" data-bs-target="#modalFinanceiro" onclick="prepararNovo()">
                <i class="fas fa-plus me-1"></i> Lançamento Manual
            </button>
        </div>
    </div>

    <?= $msg ?>

    <!-- DASHBOARD FINANCEIRO -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card card-stats bg-light shadow-sm p-3 h-100 border-0">
                <div class="d-flex justify-content-between align-items-center">
                    <div><h6 class="text-muted small fw-bold text-uppercase mb-1">Orçamento Total</h6><h4 class="mb-0 fw-bold text-success"><?= formataMoeda($orcamento_total) ?></h4></div>
                    <div class="icon-box bg-success text-white bg-opacity-75"><i class="fas fa-wallet"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-stats bg-light shadow-sm p-3 h-100 border-0">
                <div class="d-flex justify-content-between align-items-center">
                    <div><h6 class="text-muted small fw-bold text-uppercase mb-1">Saldo Disponível</h6><h4 class="mb-0 fw-bold <?= $saldo_disponivel < 0 ? 'text-danger' : 'text-primary' ?>"><?= formataMoeda($saldo_disponivel) ?></h4></div>
                    <div class="icon-box bg-primary text-white bg-opacity-75"><i class="fas fa-piggy-bank"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-stats bg-light shadow-sm p-3 h-100 border-0">
                <div class="d-flex justify-content-between align-items-center">
                    <div><h6 class="text-muted small fw-bold text-uppercase mb-1">Gastos Pagos</h6><h4 class="mb-0 fw-bold text-danger"><?= formataMoeda($despesas_pagas) ?></h4></div>
                    <div class="icon-box bg-danger text-white bg-opacity-75"><i class="fas fa-receipt"></i></div>
                </div>
                <div class="mt-2 text-muted" style="font-size: 0.65rem;">Fixos: <b><?= formataMoeda($gastos_fixos) ?></b> | Var: <b><?= formataMoeda($gastos_variaveis) ?></b></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-stats bg-dark text-white shadow-sm p-3 h-100 border-0">
                <div class="d-flex justify-content-between align-items-center">
                    <div><h6 class="small fw-bold text-uppercase mb-1 text-white-50">Projeção (Com Pendentes)</h6><h4 class="mb-0 fw-bold"><?= formataMoeda($projecao_total) ?></h4></div>
                    <div class="icon-box bg-white text-dark bg-opacity-25"><i class="fas fa-chart-line"></i></div>
                </div>
                <div class="mt-2 text-white-50" style="font-size: 0.65rem;">Saldo Futuro: <b class="<?= $saldo_projetado < 0 ? 'text-danger' : 'text-success' ?>"><?= formataMoeda($saldo_projetado) ?></b></div>
            </div>
        </div>
    </div>

    <!-- BARRA DE COMPROMETIMENTO -->
    <div class="mb-4">
        <div class="d-flex justify-content-between small fw-bold mb-1 text-muted"><span>Comprometimento do Orçamento (Projeção)</span><span><?= number_format(min(100, $porcentagem_uso), 1, ',', '.') ?>%</span></div>
        <div class="progress rounded-pill bg-light border" style="height: 12px;"><div class="progress-bar progress-bar-striped progress-bar-animated <?= $cor_barra ?>" style="width: <?= min(100, $porcentagem_uso) ?>%"></div></div>
    </div>

    <hr class="text-muted opacity-25">

    <!-- FILTRO E LISTAGEM -->
    <form method="GET" class="row g-2 mb-3 align-items-end">
        <div class="col-md-3"><label class="small fw-bold text-muted">Mês de Referência</label><input type="month" name="mes" class="form-control form-control-sm fw-bold" value="<?= h($mes_filtro) ?>" onchange="this.form.submit()"></div>
        <div class="col-md-3">
            <label class="small fw-bold text-muted">Exibir Tipo</label>
            <select name="tipo" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="Todos" <?= (isset($_GET['tipo']) && $_GET['tipo'] == 'Todos') ? 'selected' : '' ?>>Receitas e Despesas</option>
                <option value="Receita" <?= (isset($_GET['tipo']) && $_GET['tipo'] == 'Receita') ? 'selected' : '' ?>>Apenas Receitas</option>
                <option value="Despesa" <?= (isset($_GET['tipo']) && $_GET['tipo'] == 'Despesa') ? 'selected' : '' ?>>Apenas Despesas</option>
            </select>
        </div>
        <div class="col-md-6 text-end">
             <button type="button" class="btn btn-outline-dark btn-sm fw-bold px-3" onclick="window.print()"><i class="fas fa-print"></i> Imprimir Relatório</button>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table table-hover align-middle bg-white border">
            <thead class="table-light small text-uppercase">
                <tr>
                    <th>Data</th>
                    <th>Descrição</th>
                    <th>Classificação</th>
                    <th>Anexo</th>
                    <th>Valor</th>
                    <th>Status</th>
                    <th class="text-end no-print">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($lancamentos as $l): 
                    $is_receita = ($l['tipo'] == 'Receita');
                    $cor_texto = $is_receita ? 'text-success' : 'text-danger';
                    $sinal = $is_receita ? '+' : '-';
                ?>
                <tr class="<?= $is_receita ? 'receita-row' : 'despesa-row' ?>">
                    <td class="small fw-bold"><?= date('d/m/Y', strtotime($l['data_vencimento'])) ?></td>
                    <td>
                        <strong class="text-dark d-block"><?= htmlspecialchars($l['descricao']) ?></strong>
                        <?php if(!empty($l['observacoes'])): ?><small class="text-muted fst-italic"><?= htmlspecialchars($l['observacoes']) ?></small><?php endif; ?>
                    </td>
                    <td>
                        <span class="badge bg-light text-dark border"><?= $l['tipo'] ?></span>
                        <?php if(!$is_receita): ?><span class="badge bg-secondary opacity-75"><?= $l['natureza'] ?></span><?php endif; ?>
                    </td>
                    <td>
                        <!-- MOSTRA O BOTÃO DO PDF SE TIVER COMPROVANTE -->
                        <?php if(!empty($l['arquivo_comprovante'])): ?>
                            <a href="uploads/financeiro/<?= $l['arquivo_comprovante'] ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2 fw-bold" title="Ver Comprovante"><i class="fas fa-paperclip"></i> Anexo</a>
                        <?php else: ?>
                            <span class="text-muted small">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="fw-bold <?= $cor_texto ?>"><?= $sinal ?> <?= formataMoeda($l['valor']) ?></td>
                    <td>
                        <?php if($l['status'] == 'Pago'): ?><span class="badge bg-success rounded-pill"><i class="fas fa-check"></i> Pago</span>
                        <?php else: ?><span class="badge bg-warning text-dark rounded-pill"><i class="fas fa-clock"></i> Pendente</span><?php endif; ?>
                    </td>
                    <td class="text-end no-print">
                        <?php if($l['status'] == 'Pendente'): ?>
                            <a href="?pagar=<?= $l['id'] ?>" class="btn btn-sm btn-success fw-bold py-0 px-2" title="Marcar como Pago"><i class="fas fa-check"></i></a>
                        <?php endif; ?>
                        
                        <!-- NOVO: BOTÃO DE EDITAR AGORA USA DATA-BS-TOGGLE NATIVO -->
                        <button type="button" onclick='editar(<?= json_encode($l, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)' class="btn btn-sm btn-outline-primary py-0 px-2" data-bs-toggle="modal" data-bs-target="#modalFinanceiro" title="Editar / Anexar PDF"><i class="fas fa-pen"></i></button>
                        
                        <a href="?deletar=<?= $l['id'] ?>" class="btn btn-sm btn-outline-danger py-0 px-2" onclick="return confirm('Apagar este lançamento?')" title="Excluir"><i class="fas fa-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($lancamentos)): ?><tr><td colspan="7" class="text-center py-4 text-muted">Nenhum lançamento encontrado neste mês.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL FORMULÁRIO (AGORA COM UPLOAD enctype multipart) -->
<div class="modal fade" id="modalFinanceiro" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-dark text-white border-0 rounded-top-4">
                <h5 class="modal-title fw-bold"><i class="fas fa-file-signature me-2"></i> Lançamento Financeiro</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <!-- ENCTYPE MULTIPART NECESSÁRIO PARA UPLOAD -->
            <form method="POST" id="formFinanceiro" enctype="multipart/form-data">
                <div class="modal-body p-4 bg-light">
                    <input type="hidden" name="acao" value="salvar">
                    <input type="hidden" name="id" id="id_lancamento">

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold small">Tipo de Movimento *</label>
                            <select name="tipo" id="tipo" class="form-select fw-bold" required onchange="ajustarNatureza()">
                                <option value="Despesa" class="text-danger">Saída (Despesa)</option>
                                <option value="Receita" class="text-success">Entrada (Receita/Orçamento)</option>
                            </select>
                        </div>
                        <div class="col-6" id="div_natureza">
                            <label class="form-label fw-bold small text-muted">Natureza da Despesa</label>
                            <select name="natureza" id="natureza" class="form-select">
                                <option value="Variável">Custo Variável (Ex: Evento, Campanha)</option>
                                <option value="Fixo">Custo Fixo (Ex: Aluguel, Sistema, Folha)</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small">Descrição / Beneficiário *</label>
                        <input type="text" name="descricao" id="descricao" class="form-control" placeholder="Ex: Gráfica, Fundo Partidário, Aluguel do Comitê..." required>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold small text-primary">Valor (R$) *</label>
                            <input type="text" name="valor" id="valor" class="form-control fw-bold" placeholder="0,00" required onkeyup="formatarMoeda(this)">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold small">Data (Vencimento) *</label>
                            <input type="date" name="data_vencimento" id="data_vencimento" class="form-control" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small">Situação do Pagamento *</label>
                        <select name="status" id="status" class="form-select" required>
                            <option value="Pendente">Pendente / A Pagar</option>
                            <option value="Pago">Pago / Recebido</option>
                        </select>
                    </div>

                    <div class="mb-3 p-3 bg-white border rounded">
                        <label class="form-label fw-bold small text-primary"><i class="fas fa-paperclip"></i> Anexar Comprovante (Nota/Recibo)</label>
                        <input type="file" name="comprovante" id="comprovante" class="form-control form-control-sm" accept=".pdf, image/jpeg, image/png">
                        <small class="text-muted" style="font-size: 0.7rem;">Deixe em branco se já houver arquivo anexado, a não ser que queira substituí-lo.</small>
                    </div>

                    <div class="mb-2">
                        <label class="form-label fw-bold small">Observações</label>
                        <textarea name="observacoes" id="observacoes" class="form-control" rows="2" placeholder="Detalhes adicionais..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light rounded-bottom-4">
                    <button type="button" class="btn btn-secondary fw-bold rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-dark fw-bold rounded-pill px-4 shadow-sm">Salvar Lançamento</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL IMPORTAÇÃO DE EXTRATO (OFX) -->
<div class="modal fade" id="modalOFX" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-primary text-white border-0 rounded-top-4">
                <h5 class="modal-title fw-bold"><i class="fas fa-university me-2"></i> Importar Extrato Bancário</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body p-4 bg-light">
                    <input type="hidden" name="acao" value="importar_ofx">
                    
                    <div class="alert alert-info small border-0">
                        <b>Como funciona?</b> Entre no aplicativo web do seu banco, vá em extrato, escolha o período e clique em <b>Exportar Extrato (Formato OFX ou Money)</b>. Envie o arquivo aqui para auto-preencher os lançamentos.
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-dark">Arquivo OFX do Banco *</label>
                        <input type="file" name="arquivo_ofx" class="form-control" accept=".ofx" required>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light rounded-bottom-4">
                    <button type="button" class="btn btn-secondary fw-bold rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary fw-bold rounded-pill px-4 shadow-sm">Processar OFX</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function formatarMoeda(i) {
        let v = i.value.replace(/\D/g,'');
        if (v === '') { i.value = ''; return; }
        v = (v/100).toFixed(2) + '';
        v = v.replace(".", ",");
        v = v.replace(/(\d)(\d{3})(\d{3}),/g, "$1.$2.$3,");
        v = v.replace(/(\d)(\d{3}),/g, "$1.$2,");
        i.value = v;
    }

    function ajustarNatureza() {
        const tipo = document.getElementById('tipo').value;
        const divNatureza = document.getElementById('div_natureza');
        divNatureza.style.display = (tipo === 'Receita') ? 'none' : 'block';
    }

    window.prepararNovo = function() {
        document.getElementById('formFinanceiro').reset();
        document.getElementById('id_lancamento').value = '';
        document.getElementById('comprovante').value = '';
        document.getElementById('data_vencimento').value = new Date().toISOString().split('T')[0];
        ajustarNatureza();
    };

    window.editar = function(dados) {
        document.getElementById('id_lancamento').value = dados.id;
        document.getElementById('tipo').value = dados.tipo;
        document.getElementById('natureza').value = dados.natureza;
        document.getElementById('descricao').value = dados.descricao;
        
        let valorF = parseFloat(dados.valor).toFixed(2).replace('.', ',');
        document.getElementById('valor').value = valorF;
        
        document.getElementById('data_vencimento').value = dados.data_vencimento;
        document.getElementById('status').value = dados.status;
        document.getElementById('observacoes').value = dados.observacoes || '';
        document.getElementById('comprovante').value = ''; 
        
        ajustarNatureza();
    };
</script>

<style type="text/css" media="print">
    @page { size: landscape; }
    body { background: white !important; }
    .no-print, .btn, .modal, form { display: none !important; }
    .container { max-width: 100% !important; box-shadow: none !important; }
    .card-stats { border: 1px solid #ddd !important; }
</style>

</body>
</html>