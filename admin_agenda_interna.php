<?php
// admin_agenda_interna.php
// Módulo de Agenda Interna da Equipe (Colaborativo)

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require 'config.php';
require 'protecao.php';

if (!defined('TENANT_ID')) die("Acesso Restrito.");

// Verifica permissão genérica para ENTRAR na página
if (function_exists('tem_acesso') && !tem_acesso('agenda') && !tem_acesso('master') && !tem_acesso('admin')) {
    die("Acesso Negado.");
}

// =========================================================================
// REGRA DE VISIBILIDADE: ADMIN/MASTER VÊ TUDO / EQUIPE VÊ APENAS O SEU
// =========================================================================
$meu_id = $_SESSION['id'] ?? $_SESSION['admin_id'] ?? 0;

$eh_admin = false;
$nivel_usuario = isset($_SESSION['nivel']) ? strtolower($_SESSION['nivel']) : '';

if ($nivel_usuario === 'master' || $nivel_usuario === 'admin') {
    $eh_admin = true;
}
if (function_exists('tem_acesso') && (tem_acesso('master') || tem_acesso('admin'))) {
    $eh_admin = true;
}

// =========================================================================
// AUTO-MIGRATION (Cria a tabela se não existir)
// =========================================================================
try {
    $pdo->query("CREATE TABLE IF NOT EXISTS agenda_interna (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        usuario_id INT NOT NULL,
        criador_id INT NOT NULL,
        titulo VARCHAR(255) NOT NULL,
        descricao TEXT,
        data_evento DATE NOT NULL,
        hora_evento TIME NULL,
        status VARCHAR(20) DEFAULT 'Pendente',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (tenant_id),
        INDEX (usuario_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) {}

$msg = "";

// =========================================================================
// PROCESSA AÇÕES (SALVAR, CONCLUIR, DELETAR)
// =========================================================================

// SALVAR OU EDITAR
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'salvar') {
    try {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : 0;
        $titulo = trim($_POST['titulo']);
        $descricao = trim($_POST['descricao']);
        $data_evento = $_POST['data_evento'];
        $hora_evento = !empty($_POST['hora_evento']) ? $_POST['hora_evento'] : null;
        
        // Bloqueio: Só o admin/master pode atribuir a outra pessoa. Usuário comum sempre atribui a si mesmo.
        $usuario_id = $meu_id; 
        if ($eh_admin && !empty($_POST['usuario_id'])) {
            $usuario_id = (int)$_POST['usuario_id'];
        }
        
        if (empty($titulo) || empty($data_evento)) {
            throw new Exception("Título e Data são obrigatórios.");
        }

        if ($id > 0) {
            // Trava de segurança para impedir edições maliciosas
            if (!$eh_admin) {
                $stmtCheck = $pdo->prepare("SELECT usuario_id, criador_id FROM agenda_interna WHERE id = ? AND tenant_id = ?");
                $stmtCheck->execute([$id, TENANT_ID]);
                $chk = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                // Só edita se for o dono da tarefa ou quem criou ela
                if ($chk['usuario_id'] != $meu_id && $chk['criador_id'] != $meu_id) {
                    throw new Exception("Acesso negado para alterar este compromisso.");
                }
            }

            $stmt = $pdo->prepare("UPDATE agenda_interna SET titulo=?, descricao=?, data_evento=?, hora_evento=?, usuario_id=? WHERE id=? AND tenant_id=?");
            $stmt->execute([$titulo, $descricao, $data_evento, $hora_evento, $usuario_id, $id, TENANT_ID]);
            $msg = "<div class='alert alert-success shadow-sm'><i class='fas fa-check-circle'></i> Compromisso atualizado!</div>";
        } else {
            $stmt = $pdo->prepare("INSERT INTO agenda_interna (tenant_id, criador_id, usuario_id, titulo, descricao, data_evento, hora_evento, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Pendente')");
            $stmt->execute([TENANT_ID, $meu_id, $usuario_id, $titulo, $descricao, $data_evento, $hora_evento]);
            $msg = "<div class='alert alert-success shadow-sm'><i class='fas fa-check-circle'></i> Compromisso agendado com sucesso!</div>";
        }
    } catch (Exception $e) {
        $msg = "<div class='alert alert-danger shadow-sm'><i class='fas fa-exclamation-triangle'></i> Erro: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// CONCLUIR
if (isset($_GET['concluir'])) {
    $id = (int)$_GET['concluir'];
    try {
        if (!$eh_admin) {
            $stmtCheck = $pdo->prepare("SELECT usuario_id, criador_id FROM agenda_interna WHERE id = ? AND tenant_id = ?");
            $stmtCheck->execute([$id, TENANT_ID]);
            $chk = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            if ($chk['usuario_id'] != $meu_id && $chk['criador_id'] != $meu_id) die("Acesso Negado.");
        }
        $pdo->prepare("UPDATE agenda_interna SET status = 'Concluído' WHERE id = ? AND tenant_id = ?")->execute([$id, TENANT_ID]);
        header("Location: admin_agenda_interna.php?msg=concluido");
        exit;
    } catch (Exception $e) {}
}

// DELETAR
if (isset($_GET['deletar'])) {
    $id = (int)$_GET['deletar'];
    try {
        if (!$eh_admin) {
            $stmtCheck = $pdo->prepare("SELECT usuario_id, criador_id FROM agenda_interna WHERE id = ? AND tenant_id = ?");
            $stmtCheck->execute([$id, TENANT_ID]);
            $chk = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            if ($chk['usuario_id'] != $meu_id && $chk['criador_id'] != $meu_id) die("Acesso Negado.");
        }
        $pdo->prepare("DELETE FROM agenda_interna WHERE id = ? AND tenant_id = ?")->execute([$id, TENANT_ID]);
        header("Location: admin_agenda_interna.php?msg=deletado");
        exit;
    } catch (Exception $e) {}
}

if (isset($_GET['msg']) && $_GET['msg'] == 'concluido') $msg = "<div class='alert alert-success shadow-sm'><i class='fas fa-check-circle'></i> Compromisso marcado como concluído!</div>";
if (isset($_GET['msg']) && $_GET['msg'] == 'deletado') $msg = "<div class='alert alert-success shadow-sm'><i class='fas fa-trash'></i> Compromisso apagado!</div>";

// =========================================================================
// BUSCAS E FILTROS DE VISIBILIDADE
// =========================================================================

$equipe = [];
if ($eh_admin) {
    try {
        $stmtEq = $pdo->prepare("SELECT id, nome FROM usuarios WHERE tenant_id = ? ORDER BY nome ASC");
        $stmtEq->execute([TENANT_ID]);
        $equipe = $stmtEq->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// Filtros
$filtro_status = $_GET['status'] ?? 'Pendente';
$filtro_usuario = $_GET['usuario'] ?? '';

$where = "a.tenant_id = ?";
$params = [TENANT_ID];

if ($filtro_status !== 'Todos') {
    $where .= " AND a.status = ?";
    $params[] = $filtro_status;
}

// BLINDAGEM NÍVEL 1: Na Query do Banco de Dados
if (!$eh_admin) {
    // Se for usuário comum, vê apenas o que é DELE
    $where .= " AND (a.usuario_id = ? OR a.criador_id = ?)";
    $params[] = $meu_id;
    $params[] = $meu_id;
} else {
    // Admin pode filtrar por outros membros
    if ($filtro_usuario !== '') {
        $where .= " AND a.usuario_id = ?";
        $params[] = (int)$filtro_usuario;
    }
}

try {
    $sql = "SELECT a.*, u.nome as nome_responsavel, c.nome as nome_criador 
            FROM agenda_interna a 
            LEFT JOIN usuarios u ON a.usuario_id = u.id 
            LEFT JOIN usuarios c ON a.criador_id = c.id 
            WHERE $where 
            ORDER BY a.data_evento ASC, a.hora_evento ASC LIMIT 100";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Contadores Rápidos de Alerta (Apenas as tarefas que a pessoa logada TEM QUE FAZER)
    $sql_cnt = "SELECT COUNT(*) FROM agenda_interna WHERE tenant_id = ? AND data_evento = CURDATE() AND status = 'Pendente'";
    $params_cnt = [TENANT_ID];
    
    if (!$eh_admin) {
        $sql_cnt .= " AND usuario_id = ?";
        $params_cnt[] = $meu_id;
    }
    
    $stmtCnt = $pdo->prepare($sql_cnt);
    $stmtCnt->execute($params_cnt);
    $hoje = $stmtCnt->fetchColumn();

} catch (Exception $e) {
    $eventos = [];
    $hoje = 0;
}

function formataDataBR($data) {
    return date('d/m/Y', strtotime($data));
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Agenda Interna</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f3f4f6; font-family: 'Inter', sans-serif; }
        .card-evento { border: none; border-left: 5px solid #0d6efd; border-radius: 12px; transition: 0.3s; }
        .card-evento:hover { transform: translateX(5px); box-shadow: 0 5px 15px rgba(0,0,0,0.08); }
        .card-evento.concluido { border-left-color: #198754; opacity: 0.7; }
        .card-evento.atrasado { border-left-color: #dc3545; }
        .data-box { background: #e9ecef; border-radius: 8px; padding: 10px; text-align: center; min-width: 80px; }
    </style>
</head>
<body class="p-4">

<div class="container bg-white p-4 rounded-4 shadow-sm" style="max-width: 1000px;">
    
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 gap-3">
        <div>
            <h3 class="text-primary fw-bold mb-0"><i class="fas fa-calendar-alt me-2"></i> Agenda da Equipe</h3>
            <small class="text-muted">Controle interno colaborativo de reuniões, visitas e compromissos.</small>
        </div>
        <div class="d-flex gap-2">
            <a href="admin_painel.php" class="btn btn-outline-secondary btn-sm rounded-pill fw-bold px-3"><i class="fas fa-arrow-left me-1"></i> Voltar</a>
            <button class="btn btn-primary btn-sm fw-bold px-3 rounded-pill shadow-sm" onclick="abrirModal()">
                <i class="fas fa-plus me-1"></i> Novo Compromisso
            </button>
        </div>
    </div>

    <?= $msg ?>

    <?php if($hoje > 0): ?>
    <div class="alert alert-warning border-0 shadow-sm fw-bold d-flex align-items-center mb-4">
        <i class="fas fa-bell fa-2x me-3"></i> 
        <div>Atenção: Você tem <?= $hoje ?> compromisso(s) pendentes agendados para <b>HOJE</b>.</div>
    </div>
    <?php endif; ?>

    <form method="GET" class="row g-2 mb-4 bg-light p-3 rounded-3 border">
        <div class="col-md-4">
            <select name="status" class="form-select form-select-sm fw-bold text-secondary">
                <option value="Todos" <?= $filtro_status == 'Todos' ? 'selected' : '' ?>>📋 Todos os Status</option>
                <option value="Pendente" <?= $filtro_status == 'Pendente' ? 'selected' : '' ?>>⏳ Apenas Pendentes</option>
                <option value="Concluído" <?= $filtro_status == 'Concluído' ? 'selected' : '' ?>>✅ Apenas Concluídos</option>
            </select>
        </div>
        
        <?php if($eh_admin): ?>
        <div class="col-md-5">
            <select name="usuario" class="form-select form-select-sm">
                <option value="">-- Filtrar por Responsável --</option>
                <?php foreach($equipe as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= $filtro_usuario == $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['nome']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php else: ?>
            <div class="col-md-5 d-none d-md-block"></div>
        <?php endif; ?>

        <div class="col-md-3">
            <button type="submit" class="btn btn-dark btn-sm w-100 fw-bold"><i class="fas fa-filter me-1"></i> Filtrar</button>
        </div>
    </form>

    <div class="row g-3">
        <?php foreach($eventos as $e): 
            
            // BLINDAGEM NÍVEL 2: Redundância lógica em PHP
            if (!$eh_admin && $e['usuario_id'] != $meu_id && $e['criador_id'] != $meu_id) continue;

            $hoje_date = date('Y-m-d');
            $is_atrasado = ($e['status'] == 'Pendente' && $e['data_evento'] < $hoje_date);
            $is_concluido = ($e['status'] == 'Concluído');
            
            $classe_card = "";
            if ($is_concluido) $classe_card = "concluido";
            elseif ($is_atrasado) $classe_card = "atrasado";

            $dia = date('d', strtotime($e['data_evento']));
            $mes = date('M', strtotime($e['data_evento']));
        ?>
        <div class="col-12">
            <div class="card card-evento shadow-sm <?= $classe_card ?>">
                <div class="card-body p-3 d-flex align-items-center gap-3">
                    
                    <div class="data-box <?= $is_atrasado ? 'bg-danger text-white' : ($is_concluido ? 'bg-success text-white' : 'bg-primary text-white') ?>">
                        <h4 class="fw-bold m-0"><?= $dia ?></h4>
                        <small class="text-uppercase" style="font-size: 0.7rem;"><?= $mes ?></small>
                    </div>

                    <div class="flex-grow-1">
                        <div class="d-flex justify-content-between align-items-start mb-1">
                            <h5 class="fw-bold text-dark m-0 <?= $is_concluido ? 'text-decoration-line-through' : '' ?>"><?= htmlspecialchars($e['titulo']) ?></h5>
                            <?php if($is_atrasado): ?>
                                <span class="badge bg-danger rounded-pill">Atrasado</span>
                            <?php elseif($is_concluido): ?>
                                <span class="badge bg-success rounded-pill">Concluído</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark rounded-pill">Pendente</span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="text-muted small mb-2">
                            <i class="far fa-clock me-1"></i> <?= !empty($e['hora_evento']) ? date('H:i', strtotime($e['hora_evento'])) : 'Horário livre' ?> 
                            
                            <?php if($eh_admin): ?>
                                <span class="mx-2">|</span> 
                                <i class="fas fa-user-tie me-1"></i> Para: <strong class="text-primary"><?= htmlspecialchars($e['nome_responsavel']) ?></strong>
                            <?php endif; ?>
                            
                            <?php if($e['criador_id'] != $e['usuario_id']): ?>
                                <span class="ms-2 fst-italic" style="font-size: 0.7rem;">(Marcado por <?= htmlspecialchars($e['nome_criador']) ?>)</span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if(!empty($e['descricao'])): ?>
                            <p class="mb-0 text-secondary small fst-italic border-start border-2 ps-2">"<?= htmlspecialchars($e['descricao']) ?>"</p>
                        <?php endif; ?>
                    </div>

                    <div class="d-flex flex-column gap-2 border-start ps-3">
                        <?php if(!$is_concluido): ?>
                            <a href="?concluir=<?= $e['id'] ?>" class="btn btn-sm btn-success fw-bold" title="Marcar como Concluído"><i class="fas fa-check"></i></a>
                            <button onclick='editar(<?= json_encode($e) ?>)' class="btn btn-sm btn-outline-primary" title="Editar"><i class="fas fa-pen"></i></button>
                        <?php endif; ?>
                        <a href="?deletar=<?= $e['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Deseja realmente apagar este evento?')" title="Apagar"><i class="fas fa-trash"></i></a>
                    </div>

                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if(empty($eventos)): ?>
            <div class="col-12 text-center py-5 text-muted bg-light rounded-3 border">
                <i class="far fa-calendar-times fa-3x mb-3 opacity-50"></i>
                <h5>Nenhum compromisso encontrado.</h5>
                <p class="small">Aproveite para organizar a semana clicando em "Novo Compromisso".</p>
            </div>
        <?php endif; ?>
    </div>

</div>

<div class="modal fade" id="modalAgenda" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-primary text-white border-0 rounded-top-4">
                <h5 class="modal-title fw-bold"><i class="fas fa-calendar-plus me-2"></i> Dados do Compromisso</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <form method="POST" id="formAgenda">
                <div class="modal-body p-4 bg-light">
                    <input type="hidden" name="acao" value="salvar">
                    <input type="hidden" name="id" id="id_evento">
                    
                    <?php if(!$eh_admin): ?>
                        <input type="hidden" name="usuario_id" id="usuario_id" value="<?= $meu_id ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label fw-bold small">Título / Assunto *</label>
                        <input type="text" name="titulo" id="titulo" class="form-control" placeholder="Ex: Reunião com Lideranças do Bairro X" required>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold small">Data *</label>
                            <input type="date" name="data_evento" id="data_evento" class="form-control" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold small">Hora (Opcional)</label>
                            <input type="time" name="hora_evento" id="hora_evento" class="form-control">
                        </div>
                    </div>

                    <?php if($eh_admin): ?>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-primary">Atribuir a quem? *</label>
                        <select name="usuario_id" id="usuario_id" class="form-select" required>
                            <option value="">Selecione o responsável</option>
                            <?php foreach($equipe as $u): ?>
                                <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="mb-2">
                        <label class="form-label fw-bold small">Detalhes / Endereço</label>
                        <textarea name="descricao" id="descricao" class="form-control" rows="3" placeholder="Insira pautas da reunião, endereço da visita, etc..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light rounded-bottom-4">
                    <button type="button" class="btn btn-secondary fw-bold rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary fw-bold rounded-pill px-4 shadow-sm">Salvar Agenda</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const modalAgenda = new bootstrap.Modal(document.getElementById('modalAgenda'));

    function abrirModal() {
        document.getElementById('formAgenda').reset();
        document.getElementById('id_evento').value = '';
        
        const hoje = new Date().toISOString().split('T')[0];
        document.getElementById('data_evento').value = hoje;
        
        const idLogado = '<?= $meu_id ?>';
        const selectResp = document.getElementById('usuario_id');
        // Apenas aplica o valor se for o SELECT (Visão Admin)
        if(selectResp && selectResp.tagName === 'SELECT') {
            selectResp.value = idLogado;
        }

        modalAgenda.show();
    }

    function editar(dados) {
        document.getElementById('id_evento').value = dados.id;
        document.getElementById('titulo').value = dados.titulo;
        document.getElementById('data_evento').value = dados.data_evento;
        document.getElementById('hora_evento').value = dados.hora_evento || '';
        
        const selectResp = document.getElementById('usuario_id');
        if (selectResp && selectResp.tagName === 'SELECT') {
            selectResp.value = dados.usuario_id;
        }
        
        document.getElementById('descricao').value = dados.descricao || '';
        modalAgenda.show();
    }
</script>

</body>
</html>