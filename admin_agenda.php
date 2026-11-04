<?php
// ATIVAR EXIBIÇÃO DE ERROS (Para descobrir o problema se persistir)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'config.php';
require 'protecao.php';

// --- 0. SEGURANÇA SAAS ---
if (!defined('TENANT_ID')) {
    die("Erro Crítico: Tenant não identificado no config.php.");
}

// Verifica Permissão
if (!tem_acesso('agenda') && !tem_acesso('master')) { die("Acesso Negado."); }

$msg = "";
$edit_id = "";
$edit_dados = [];

// --- 1. CARREGAR DADOS PARA EDIÇÃO ---
if (isset($_GET['editar'])) {
    $id_edit = (int)$_GET['editar'];
    $stmt = $pdo->prepare("SELECT * FROM agenda WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$id_edit, TENANT_ID]);
    $edit_dados = $stmt->fetch(PDO::FETCH_ASSOC);
    if($edit_dados) { $edit_id = $id_edit; }
}

// --- 2. SALVAR ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'salvar') {
    $id = $_POST['id'];
    $titulo = $_POST['titulo'];
    $data = $_POST['data'];
    $hora = $_POST['hora'];
    $local = $_POST['local'];
    $descricao = $_POST['descricao'];

    try {
        if (!empty($id)) {
            // ATUALIZAR
            $sql = "UPDATE agenda SET titulo=?, data_evento=?, hora_evento=?, local=?, descricao=? WHERE id=? AND tenant_id=?";
            $stmt = $pdo->prepare($sql);
            if ($stmt->execute([$titulo, $data, $hora, $local, $descricao, $id, TENANT_ID])) {
                $msg = "<div class='alert alert-success'>Evento atualizado! <a href='admin_agenda.php'>Limpar</a></div>";
                $edit_id = ""; $edit_dados = [];
            }
        } else {
            // CADASTRAR
            $sql = "INSERT INTO agenda (tenant_id, titulo, data_evento, hora_evento, local, descricao) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            if ($stmt->execute([TENANT_ID, $titulo, $data, $hora, $local, $descricao])) {
                $msg = "<div class='alert alert-success'>Evento agendado!</div>";
            }
        }
    } catch (PDOException $e) {
        $msg = "<div class='alert alert-danger'>Erro ao salvar: " . $e->getMessage() . "</div>";
    }
}

// --- 3. EXCLUIR ---
if (isset($_GET['deletar'])) {
    $id_del = (int)$_GET['deletar'];
    $stmt = $pdo->prepare("DELETE FROM agenda WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$id_del, TENANT_ID]);
    header("Location: admin_agenda.php");
    exit;
}

// --- LISTAGEM ---
try {
    // Futuros
    $sql_futuro = "SELECT * FROM agenda WHERE tenant_id = ? AND data_evento >= CURDATE() ORDER BY data_evento ASC, hora_evento ASC";
    $stmt_futuro = $pdo->prepare($sql_futuro);
    $stmt_futuro->execute([TENANT_ID]);

    // Passados
    $sql_passado = "SELECT * FROM agenda WHERE tenant_id = ? AND data_evento < CURDATE() ORDER BY data_evento DESC LIMIT 10";
    $stmt_passado = $pdo->prepare($sql_passado);
    $stmt_passado->execute([TENANT_ID]);
} catch (Exception $e) {
    die("Erro ao buscar agenda: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8"><title>Agenda Oficial</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-light p-4">

<div class="container bg-white p-4 rounded shadow-sm">
    <div class="d-flex justify-content-between mb-4 align-items-center">
        <div>
            <h3 class="text-danger fw-bold mb-0"><i class="fas fa-calendar-alt"></i> Agenda Oficial</h3>
            <small class="text-muted">Compromissos, sessões e visitas.</small>
        </div>
        <a href="admin_painel.php" class="btn btn-secondary btn-sm">Voltar</a>
    </div>

    <?= $msg ?>

    <div class="row">
        <div class="col-md-4 mb-4">
            <div class="card card-body bg-light border-0 sticky-top" style="top: 20px; z-index: 1;">
                <h5 class="fw-bold mb-3"><?= $edit_id ? 'Editar Evento' : 'Novo Compromisso' ?></h5>
                <form method="POST">
                    <input type="hidden" name="acao" value="salvar">
                    <input type="hidden" name="id" value="<?= $edit_id ?>">

                    <div class="mb-2">
                        <label class="fw-bold small">Título do Evento</label>
                        <input type="text" name="titulo" class="form-control" required placeholder="Ex: Sessão na Câmara" value="<?= htmlspecialchars($edit_dados['titulo'] ?? '') ?>">
                    </div>

                    <div class="row mb-2">
                        <div class="col-6">
                            <label class="fw-bold small">Data</label>
                            <input type="date" name="data" class="form-control" required value="<?= $edit_dados['data_evento'] ?? '' ?>">
                        </div>
                        <div class="col-6">
                            <label class="fw-bold small">Hora</label>
                            <input type="time" name="hora" class="form-control" required value="<?= $edit_dados['hora_evento'] ?? '' ?>">
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="fw-bold small">Local</label>
                        <input type="text" name="local" class="form-control" placeholder="Ex: Plenário / Bairro X" value="<?= htmlspecialchars($edit_dados['local'] ?? '') ?>">
                    </div>

                    <div class="mb-3">
                        <label class="fw-bold small">Descrição (Opcional)</label>
                        <textarea name="descricao" class="form-control" rows="2"><?= htmlspecialchars($edit_dados['descricao'] ?? '') ?></textarea>
                    </div>

                    <button class="btn <?= $edit_id ? 'btn-warning' : 'btn-danger' ?> w-100 fw-bold">
                        <?= $edit_id ? 'Salvar Alterações' : 'Agendar' ?>
                    </button>
                    
                    <?php if($edit_id): ?>
                        <a href="admin_agenda.php" class="btn btn-outline-secondary w-100 mt-2 btn-sm">Cancelar</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="col-md-8">
            
            <h6 class="fw-bold text-success mb-3"><i class="fas fa-clock"></i> Próximos Compromissos</h6>
            <div class="list-group mb-4 shadow-sm">
                <?php if($stmt_futuro->rowCount() == 0): ?>
                    <div class="list-group-item text-muted text-center py-3">Nenhum evento futuro agendado.</div>
                <?php endif; ?>

                <?php while($evt = $stmt_futuro->fetch(PDO::FETCH_ASSOC)): 
                    $data_fmt = date('d/m/Y', strtotime($evt['data_evento']));
                    $hora_fmt = date('H:i', strtotime($evt['hora_evento']));
                ?>
                <div class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-danger"><?= $data_fmt ?></span>
                            <span class="badge bg-light text-dark border"><?= $hora_fmt ?></span>
                            <strong class="text-dark"><?= $evt['titulo'] ?></strong>
                        </div>
                        <small class="text-muted d-block mt-1">
                            <i class="fas fa-map-marker-alt"></i> <?= $evt['local'] ?> 
                            <?php if($evt['descricao']) echo " - " . $evt['descricao']; ?>
                        </small>
                    </div>
                    <div class="btn-group">
                        <a href="?editar=<?= $evt['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i></a>
                        <a href="?deletar=<?= $evt['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Excluir?')"><i class="fas fa-trash"></i></a>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>

            <h6 class="fw-bold text-secondary mb-3"><i class="fas fa-history"></i> Histórico Recente</h6>
            <div class="table-responsive">
                <table class="table table-sm table-bordered text-muted small">
                    <thead class="table-light"><tr><th>Data</th><th>Evento</th><th>Local</th></tr></thead>
                    <tbody>
                        <?php while($hist = $stmt_passado->fetch(PDO::FETCH_ASSOC)): ?>
                        <tr>
                            <td><?= date('d/m/y', strtotime($hist['data_evento'])) ?></td>
                            <td><?= $hist['titulo'] ?></td>
                            <td><?= $hist['local'] ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</div>
</body>
</html>