<?php
// LIGA A EXIBIÇÃO DE ERROS PARA NUNCA MAIS DAR TELA BRANCA 500
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'config.php';
require 'protecao.php';

// --- SEGURANÇA ---
if (!defined('TENANT_ID')) die("Erro: Tenant não identificado.");
if (!tem_acesso('enquetes') && !tem_acesso('master')) die("Acesso Negado.");

$msg = "";

// --- 0. ATUALIZAÇÃO AUTOMÁTICA DO BANCO E ESTRUTURA ---
try {
    // 1. Corrige o erro 1265: Força a coluna status a aceitar 'Ativa'/'Inativa' sem truncar
    $pdo->query("ALTER TABLE enquetes MODIFY status VARCHAR(20) DEFAULT 'Inativa'");

    // 2. Garante que a coluna de data de validade exista
    $colunasEnq = $pdo->query("DESCRIBE enquetes")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('data_fim', $colunasEnq)) {
        $pdo->query("ALTER TABLE enquetes ADD COLUMN data_fim DATE NULL AFTER status");
    }
} catch (Exception $e) { }

try {
    // Descobre a estrutura real da tabela de opções para não dar crash
    $qCols = $pdo->query("DESCRIBE enquetes_opcoes");
    $colsTabela = $qCols->fetchAll(PDO::FETCH_COLUMN);
    $colRelacao = in_array('id_enquete', $colsTabela, true) ? 'id_enquete' : 'enquete_id';
    $temColTenant = in_array('tenant_id', $colsTabela, true);
    $temIdEnquete = in_array('id_enquete', $colsTabela, true);
    $temEnqueteId = in_array('enquete_id', $colsTabela, true);
    
    // Garante que a coluna de contagem de votos exista
    if (!in_array('votos', $colsTabela)) {
        $pdo->query("ALTER TABLE enquetes_opcoes ADD COLUMN votos INT DEFAULT 0");
    }
} catch (Exception $e) { 
    $msg = "<div class='alert alert-warning'>Aviso: Não foi possível verificar a estrutura da tabela de opções.</div>";
}

// --- 1. SALVAR OU EDITAR A PERGUNTA ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_enquete') {
    $titulo = trim($_POST['titulo'] ?? '');
    $status = $_POST['status'] ?? 'Inativa';
    $data_fim = !empty($_POST['data_fim']) ? $_POST['data_fim'] : null;

    if ($titulo === '') {
        $msg = "<div class='alert alert-danger'>Informe o título da enquete.</div>";
    } else {
        try {
            if ($status === 'Ativa') {
                $pdo->prepare("UPDATE enquetes SET status = 'Inativa' WHERE tenant_id = ?")->execute([TENANT_ID]);
            }

            if (empty($_POST['id'])) {
                $stmt = $pdo->prepare("INSERT INTO enquetes (tenant_id, titulo, status, data_fim) VALUES (?, ?, ?, ?)");
                $stmt->execute([TENANT_ID, $titulo, $status, $data_fim]);
                $novoId = (int)$pdo->lastInsertId();
                header("Location: admin_enquetes.php?editar=" . $novoId . "&sucesso=1");
                exit;
            } else {
                $idEdit = (int)$_POST['id'];
                $stmt = $pdo->prepare("UPDATE enquetes SET titulo = ?, status = ?, data_fim = ? WHERE id = ? AND tenant_id = ?");
                $stmt->execute([$titulo, $status, $data_fim, $idEdit, TENANT_ID]);
                $msg = "<div class='alert alert-success'>Enquete atualizada!</div>";
            }
        } catch (Exception $e) {
            $msg = "<div class='alert alert-danger'>Erro ao salvar: " . $e->getMessage() . "</div>";
        }
    }
}

// --- 2. ADICIONAR OPÇÃO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'add_opcao') {
    $id_enquete = (int)($_POST['id_enquete'] ?? 0);
    $texto = trim($_POST['texto_opcao'] ?? '');

    if ($id_enquete <= 0 || $texto === '') {
        $msg = "<div class='alert alert-danger'>Informe a opção e selecione uma enquete válida.</div>";
    } else {
        try {
            $campos = []; $vals = []; $binds = [];

            if ($temIdEnquete) { $campos[] = "id_enquete"; $vals[] = "?"; $binds[] = $id_enquete; }
            if ($temEnqueteId) { $campos[] = "enquete_id"; $vals[] = "?"; $binds[] = $id_enquete; }
            if ($temColTenant) { $campos[] = "tenant_id";  $vals[] = "?"; $binds[] = TENANT_ID; }

            $campos[] = "texto"; $vals[] = "?"; $binds[] = $texto;
            $campos[] = "votos"; $vals[] = "0";

            $sql = "INSERT INTO enquetes_opcoes (" . implode(", ", $campos) . ") VALUES (" . implode(", ", $vals) . ")";
            $pdo->prepare($sql)->execute($binds);

            header("Location: admin_enquetes.php?editar=$id_enquete&sucesso=1");
            exit;
        } catch (Exception $e) {
            $msg = "<div class='alert alert-danger'>Erro ao salvar opção: " . $e->getMessage() . "</div>";
        }
    }
}

// --- 3. EXCLUIR OPÇÃO ---
if (isset($_GET['excluir_opcao'])) {
    try {
        $idOpc = (int)$_GET['excluir_opcao'];
        $idRet = (int)($_GET['id_enquete'] ?? 0);

        // Descobre o nome real da coluna na tabela de votos
        $colsVotoTab = $pdo->query("DESCRIBE enquetes_votos")->fetchAll(PDO::FETCH_COLUMN);
        $colOpcaoVoto = in_array('opcao_id', $colsVotoTab) ? 'opcao_id' : 'id_opcao';

        $pdo->prepare("DELETE FROM enquetes_votos WHERE $colOpcaoVoto = ? AND tenant_id = ?")->execute([$idOpc, TENANT_ID]);
        
        if ($temColTenant) {
            $pdo->prepare("DELETE FROM enquetes_opcoes WHERE id = ? AND tenant_id = ?")->execute([$idOpc, TENANT_ID]);
        } else {
            $pdo->prepare("DELETE FROM enquetes_opcoes WHERE id = ?")->execute([$idOpc]);
        }
        header("Location: admin_enquetes.php?editar=" . $idRet);
        exit;
    } catch (Exception $e) {
        $msg = "<div class='alert alert-danger'>Erro ao excluir: " . $e->getMessage() . "</div>";
    }
}

// --- 4. EXCLUIR ENQUETE ---
if (isset($_GET['excluir_enquete'])) {
    try {
        $idDel = (int)$_GET['excluir_enquete'];
        $colVoto = in_array('id_enquete', $colsTabela, true) ? 'id_enquete' : 'enquete_id';
        $pdo->prepare("DELETE FROM enquetes_votos WHERE $colVoto = ? AND tenant_id = ?")->execute([$idDel, TENANT_ID]);

        if ($temColTenant) {
            $pdo->prepare("DELETE FROM enquetes_opcoes WHERE $colRelacao = ? AND tenant_id = ?")->execute([$idDel, TENANT_ID]);
        } else {
            $pdo->prepare("DELETE FROM enquetes_opcoes WHERE $colRelacao = ?")->execute([$idDel]);
        }
        $pdo->prepare("DELETE FROM enquetes WHERE id = ? AND tenant_id = ?")->execute([$idDel, TENANT_ID]);
        header("Location: admin_enquetes.php");
        exit;
    } catch (Exception $e) {
        $msg = "<div class='alert alert-danger'>Erro ao excluir enquete: " . $e->getMessage() . "</div>";
    }
}

// --- 5. LISTAGEM PRINCIPAL ---
try {
    $enquetes = $pdo->prepare("SELECT * FROM enquetes WHERE tenant_id = ? ORDER BY id DESC");
    $enquetes->execute([TENANT_ID]);
    $lista = $enquetes->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $lista = [];
    $msg = "<div class='alert alert-danger'>Erro ao listar enquetes: " . $e->getMessage() . "</div>";
}

// --- 6. EDIÇÃO E RESULTADOS ---
$edit_titulo = "";
$edit_status = "Inativa";
$edit_data_fim = "";
$opcoes = [];
$total_votos_enquete = 0;

if (isset($_GET['editar'])) {
    try {
        $idEdit = (int)$_GET['editar'];
        $st = $pdo->prepare("SELECT * FROM enquetes WHERE id = ? AND tenant_id = ?");
        $st->execute([$idEdit, TENANT_ID]);
        $e = $st->fetch(PDO::FETCH_ASSOC);
        
        if ($e) {
            $edit_titulo = $e['titulo'];
            $edit_status = $e['status'];
            $edit_data_fim = $e['data_fim'] ?? '';
            
            $whereTenant = $temColTenant ? " AND tenant_id = ? " : "";
            $sqlOpts = "SELECT * FROM enquetes_opcoes WHERE $colRelacao = ? $whereTenant ORDER BY votos DESC, id ASC";
            $opts = $pdo->prepare($sqlOpts);
            if ($temColTenant) $opts->execute([$idEdit, TENANT_ID]); else $opts->execute([$idEdit]);
            
            $opcoes = $opts->fetchAll(PDO::FETCH_ASSOC);
            foreach($opcoes as $op) $total_votos_enquete += (int)$op['votos'];
        }
    } catch (Exception $e) {
        $msg = "<div class='alert alert-danger'>Erro ao carregar os dados da enquete: " . $e->getMessage() . "</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Gerenciar Enquetes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .card { border-radius: 12px; border: none; }
        .list-group-item { border-left: none; border-right: none; }
        .progress { height: 10px; border-radius: 10px; background-color: #e9ecef; }
    </style>
</head>
<body class="bg-light p-4">

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold text-primary"><i class="fas fa-poll me-2"></i> Enquetes</h3>
        <a href="admin_painel.php" class="btn btn-secondary shadow-sm">Voltar ao Painel</a>
    </div>

    <?= $msg ?>

    <div class="row">
        <div class="col-md-5">
            <div class="card card-body shadow-sm mb-4">
                <h5 class="fw-bold mb-3">1. Pergunta da Enquete</h5>
                <form method="POST">
                    <input type="hidden" name="acao" value="salvar_enquete">
                    <input type="hidden" name="id" value="<?= isset($_GET['editar']) ? (int)$_GET['editar'] : '' ?>">

                    <div class="mb-3">
                        <label class="small fw-bold">Título / Pergunta</label>
                        <input type="text" name="titulo" class="form-control" value="<?= htmlspecialchars($edit_titulo) ?>" required placeholder="Ex: Qual sua prioridade para o bairro?">
                    </div>
                    
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="small fw-bold">Status no Site</label>
                            <select name="status" class="form-select text-primary fw-bold">
                                <option value="Inativa" <?= $edit_status=='Inativa'?'selected':'' ?>>Inativa (Oculta)</option>
                                <option value="Ativa" <?= $edit_status=='Ativa'?'selected':'' ?>>Ativa (Publicada)</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="small fw-bold">Válida até (Opcional)</label>
                            <input type="date" name="data_fim" class="form-control" value="<?= $edit_data_fim ?>">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 fw-bold py-2">SALVAR PERGUNTA</button>

                    <?php if(isset($_GET['editar'])): ?>
                        <a href="admin_enquetes.php" class="btn btn-link w-100 text-muted small mt-2 text-decoration-none">Criar Nova Enquete</a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if(isset($_GET['editar'])): ?>
            <div class="card border-primary shadow-sm mb-4">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold m-0">2. Opções e Resultados</h5>
                    <span class="badge bg-light text-primary"><?= $total_votos_enquete ?> Votos</span>
                </div>
                
                <div class="card-body">
                    <form method="POST" class="mb-4">
                        <input type="hidden" name="acao" value="add_opcao">
                        <input type="hidden" name="id_enquete" value="<?= (int)$_GET['editar'] ?>">
                        <div class="input-group">
                            <input type="text" name="texto_opcao" class="form-control" placeholder="Adicionar nova opção (Ex: Saúde)" required>
                            <button class="btn btn-success" type="submit"><i class="fas fa-plus"></i></button>
                        </div>
                    </form>

                    <ul class="list-group list-group-flush">
                        <?php foreach($opcoes as $index => $o): 
                            $votos_opcao = (int)($o['votos'] ?? 0);
                            $porcentagem = ($total_votos_enquete > 0) ? round(($votos_opcao / $total_votos_enquete) * 100) : 0;
                            $bg_class = ($index === 0 && $votos_opcao > 0) ? 'bg-primary' : 'bg-secondary';
                        ?>
                        <li class="list-group-item px-0 py-3">
                            <div class="d-flex justify-content-between align-items-end mb-2">
                                <div>
                                    <span class="fw-bold text-dark"><?= htmlspecialchars($o['texto']) ?></span>
                                    <div class="text-muted small"><?= $votos_opcao ?> voto(s)</div>
                                </div>
                                <div class="text-end">
                                    <span class="fw-bold text-<?= $index === 0 && $votos_opcao > 0 ? 'primary' : 'muted' ?>"><?= $porcentagem ?>%</span>
                                    <a href="?excluir_opcao=<?= (int)$o['id'] ?>&id_enquete=<?= (int)$_GET['editar'] ?>" class="text-danger ms-3" title="Excluir Opção" onclick="return confirm('Excluir esta opção apagará os votos dela. Continuar?')"><i class="fas fa-trash"></i></a>
                                </div>
                            </div>
                            <div class="progress">
                                <div class="progress-bar <?= $bg_class ?>" role="progressbar" style="width: <?= $porcentagem ?>%;"></div>
                            </div>
                        </li>
                        <?php endforeach; ?>
                        <?php if(empty($opcoes)): ?><li class="list-group-item px-0 text-muted text-center py-4">Nenhuma opção cadastrada. Adicione acima.</li><?php endif; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="col-md-7">
            <div class="card shadow-sm">
                <div class="card-header bg-white py-3">
                    <h5 class="fw-bold text-secondary m-0">Minhas Enquetes</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">Enquete</th>
                                <th>Status / Validade</th>
                                <th class="text-end pe-4">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($lista as $enq): 
                                $hoje = date('Y-m-d');
                                $expirou = (!empty($enq['data_fim']) && $enq['data_fim'] < $hoje);
                            ?>
                            <tr class="<?= (isset($_GET['editar']) && $_GET['editar'] == $enq['id']) ? 'table-primary' : '' ?>">
                                <td class="ps-4 fw-bold">
                                    <?= htmlspecialchars($enq['titulo']) ?>
                                </td>
                                <td>
                                    <?php if($expirou): ?>
                                        <span class="badge rounded-pill bg-danger">Expirada</span>
                                    <?php elseif($enq['status'] == 'Ativa'): ?>
                                        <span class="badge rounded-pill bg-success">Ativa</span>
                                    <?php else: ?>
                                        <span class="badge rounded-pill bg-secondary">Inativa</span>
                                    <?php endif; ?>
                                    
                                    <?php if(!empty($enq['data_fim'])): ?>
                                        <div class="small text-muted mt-1"><i class="far fa-calendar-alt"></i> Até <?= date('d/m/y', strtotime($enq['data_fim'])) ?></div>
                                    <?php else: ?>
                                        <div class="small text-muted mt-1">Sem limite</div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <a href="?editar=<?= (int)$enq['id'] ?>" class="btn btn-sm btn-info text-white me-1">
                                        <i class="fas fa-chart-pie"></i> Gerenciar
                                    </a>
                                    <a href="?excluir_enquete=<?= (int)$enq['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Excluir esta enquete apagará todos os votos! Deseja continuar?')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>