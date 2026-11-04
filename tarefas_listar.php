<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/protecao.php';

if (!defined('TENANT_ID')) {
    die('Erro de identificação do cliente.');
}

$meu_id = (int)($_SESSION['id'] ?? $_SESSION['admin_id'] ?? 0);
$nivel = strtolower((string)($_SESSION['nivel'] ?? ''));

$eh_master = in_array($nivel, ['admin', 'master'], true);

if (function_exists('tem_acesso') && tem_acesso('master')) {
    $eh_master = true;
}

$filtro_usuario = isset($_GET['usuario_id'])
    ? (int)$_GET['usuario_id']
    : 0;

$params = [(int)TENANT_ID];

$sql_where = "WHERE t.tenant_id = ?";

if (!$eh_master) {
    $sql_where .= "
        AND EXISTS (
            SELECT 1
            FROM tarefas_responsaveis tr2
            WHERE tr2.tarefa_id = t.id
              AND tr2.usuario_id = ?
        )
    ";

    $params[] = $meu_id;
} elseif ($filtro_usuario > 0) {
    $sql_where .= "
        AND EXISTS (
            SELECT 1
            FROM tarefas_responsaveis tr3
            WHERE tr3.tarefa_id = t.id
              AND tr3.usuario_id = ?
        )
    ";

    $params[] = $filtro_usuario;
}

$sql = "
    SELECT
        t.*,
        GROUP_CONCAT(
            DISTINCT u.nome
            ORDER BY u.nome
            SEPARATOR ', '
        ) AS responsavel_nome,
        GROUP_CONCAT(
            DISTINCT u.id
            ORDER BY u.nome
            SEPARATOR ','
        ) AS responsaveis_ids
    FROM tarefas t
    LEFT JOIN tarefas_responsaveis tr
        ON tr.tarefa_id = t.id
    LEFT JOIN usuarios u
        ON u.id = tr.usuario_id
    $sql_where
    GROUP BY t.id
    ORDER BY
        CASE t.status
            WHEN 'pendente' THEN 1
            WHEN 'em_andamento' THEN 2
            WHEN 'concluido' THEN 3
            ELSE 4
        END,
        t.prazo ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$todas_tarefas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tarefas_kanban = [
    'pendente' => [],
    'em_andamento' => [],
    'concluido' => []
];

foreach ($todas_tarefas as $t) {
    $status = $t['status'] ?? 'pendente';

    if (isset($tarefas_kanban[$status])) {
        $tarefas_kanban[$status][] = $t;
    }
}

function getCorPrioridade($prioridade)
{
    switch ($prioridade) {
        case 'alta':
            return 'danger';
        case 'media':
            return 'warning';
        case 'baixa':
            return 'info';
        default:
            return 'secondary';
    }
}

function iniciaisResponsaveis($nomes)
{
    $nomes = trim((string)$nomes);

    if ($nomes === '') {
        return 'EQ';
    }

    $primeiro = trim(explode(',', $nomes)[0]);
    $partes = preg_split('/\s+/', $primeiro);

    if (count($partes) >= 2) {
        return strtoupper(
            mb_substr($partes[0], 0, 1, 'UTF-8') .
            mb_substr($partes[count($partes) - 1], 0, 1, 'UTF-8')
        );
    }

    return strtoupper(mb_substr($primeiro, 0, 2, 'UTF-8'));
}

$usuariosFiltro = [];

if ($eh_master) {
    $eq = $pdo->prepare("
        SELECT id, nome
        FROM usuarios
        WHERE tenant_id = ?
        ORDER BY nome ASC
    ");
    $eq->execute([(int)TENANT_ID]);
    $usuariosFiltro = $eq->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Quadro de Demandas</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">

    <style>
        body {
            background-color: #f3f4f6;
            font-family: 'Inter', sans-serif;
        }

        .kanban-board {
            display: flex;
            align-items: flex-start;
            gap: 20px;
            overflow-x: auto;
            padding-bottom: 20px;
            min-height: calc(100vh - 120px);
        }

        .kanban-column {
            background-color: #ebecf0;
            border-radius: 12px;
            width: 340px;
            min-width: 340px;
            max-height: calc(100vh - 140px);
            display: flex;
            flex-direction: column;
            box-shadow: 0 1px 3px rgba(0,0,0,.05);
        }

        .kanban-header {
            padding: 14px 15px;
            font-weight: 700;
            color: #172b4d;
            font-size: .95rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .kanban-tasks {
            padding: 0 10px 10px;
            overflow-y: auto;
            flex-grow: 1;
            min-height: 80px;
        }

        .kanban-card {
            background-color: #fff;
            border-radius: 9px;
            padding: 13px;
            margin-bottom: 10px;
            box-shadow: 0 1px 2px rgba(9,30,66,.25);
            cursor: grab;
            border-left: 4px solid transparent;
            transition: transform .1s ease, box-shadow .1s ease;
        }

        .kanban-card:active {
            cursor: grabbing;
        }

        .kanban-card:hover {
            background-color: #f8f9fa;
        }

        .sortable-ghost {
            opacity: .4;
            background-color: #e2e4e6;
        }

        .card-title {
            font-weight: 600;
            font-size: .92rem;
            color: #172b4d;
            margin-bottom: 8px;
        }

        .card-title-link:hover {
            color: #0d6efd !important;
            text-decoration: underline;
        }

        .card-meta {
            font-size: .75rem;
            color: #5e6c84;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 10px;
            border-top: 1px solid #f1f1f1;
            padding-top: 8px;
        }

        .badge-prioridade {
            font-size: .65rem;
            padding: 3px 6px;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .avatar-sm {
            width: 28px;
            height: 28px;
            background: #0d6efd;
            color: #fff;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: .68rem;
            font-weight: 800;
            flex-shrink: 0;
        }

        .responsaveis-texto {
            font-size: .72rem;
            line-height: 1.3;
            color: #6c757d;
            max-width: 240px;
        }
    </style>
</head>

<body class="p-3 p-md-4">

<div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 gap-3">
    <div>
        <h4 class="fw-bold text-dark m-0">
            <i class="fas fa-tasks text-primary me-2"></i>
            Quadro de Demandas
        </h4>
    </div>

    <div class="d-flex flex-wrap gap-2 align-items-center">
        <?php if ($eh_master): ?>
            <form method="GET" class="m-0">
                <select
                    name="usuario_id"
                    class="form-select fw-bold border-primary shadow-sm"
                    onchange="this.form.submit()"
                    style="border-radius: 50px;"
                >
                    <option value="">👥 Equipe Toda</option>

                    <?php foreach ($usuariosFiltro as $u): ?>
                        <option
                            value="<?= (int)$u['id'] ?>"
                            <?= $filtro_usuario === (int)$u['id'] ? 'selected' : '' ?>
                        >
                            👤 <?= htmlspecialchars($u['nome'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>

        <a
            href="admin_painel.php"
            class="btn btn-outline-secondary fw-bold rounded-pill shadow-sm"
            title="Painel"
        >
            <i class="fas fa-home"></i>
        </a>

        <?php if ($eh_master): ?>
            <a
                href="tarefas_criar.php"
                class="btn btn-primary fw-bold rounded-pill shadow-sm"
            >
                <i class="fas fa-plus me-1"></i>
                Nova Demanda
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="kanban-board">

    <?php
    $colunas = [
        'pendente' => [
            'titulo' => 'Pendente',
            'icone' => 'fas fa-circle',
            'cor' => 'secondary'
        ],
        'em_andamento' => [
            'titulo' => 'Em Execução',
            'icone' => 'fas fa-spinner',
            'cor' => 'primary'
        ],
        'concluido' => [
            'titulo' => 'Concluído',
            'icone' => 'fas fa-check-circle',
            'cor' => 'success'
        ]
    ];
    ?>

    <?php foreach ($colunas as $statusColuna => $dadosColuna): ?>
        <div class="kanban-column">
            <div class="kanban-header">
                <span>
                    <i class="<?= $dadosColuna['icone'] ?> text-<?= $dadosColuna['cor'] ?> small me-1"></i>
                    <?= $dadosColuna['titulo'] ?>
                </span>

                <span class="badge bg-<?= $dadosColuna['cor'] ?> rounded-pill">
                    <?= count($tarefas_kanban[$statusColuna]) ?>
                </span>
            </div>

            <div
                class="kanban-tasks"
                id="col-<?= $statusColuna ?>"
                data-status="<?= $statusColuna ?>"
            >
                <?php foreach ($tarefas_kanban[$statusColuna] as $t): ?>
                    <?php
                    $cor_pri = getCorPrioridade($t['prioridade']);

                    $prazoTimestamp = !empty($t['prazo'])
                        ? strtotime($t['prazo'])
                        : false;

                    $atrasado = (
                        $statusColuna !== 'concluido' &&
                        $prazoTimestamp &&
                        $prazoTimestamp < time()
                    ) ? 'text-danger fw-bold' : '';

                    $responsaveisNome = trim((string)($t['responsavel_nome'] ?? ''));
                    $tituloResponsaveis = $responsaveisNome !== ''
                        ? $responsaveisNome
                        : 'Sem responsável';

                    $iniciais = iniciaisResponsaveis($responsaveisNome);
                    ?>

                    <div
                        class="kanban-card <?= $statusColuna === 'concluido' ? 'border-success' : 'border-' . $cor_pri ?>"
                        data-id="<?= (int)$t['id'] ?>"
                        data-titulo="<?= htmlspecialchars($t['titulo'], ENT_QUOTES, 'UTF-8') ?>"
                        data-descricao="<?= htmlspecialchars($t['descricao'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                        data-prazo="<?= $prazoTimestamp ? date('Y-m-d\TH:i', $prazoTimestamp) : '' ?>"
                        data-prioridade="<?= htmlspecialchars($t['prioridade'], ENT_QUOTES, 'UTF-8') ?>"
                    >
                        <div class="d-flex justify-content-between align-items-start mb-2 gap-2">
                            <?php if ($statusColuna === 'concluido'): ?>
                                <span class="badge bg-success badge-prioridade">
                                    <i class="fas fa-check"></i>
                                    Finalizado
                                </span>
                            <?php else: ?>
                                <span class="badge bg-<?= $cor_pri ?> badge-prioridade">
                                    <?= htmlspecialchars($t['prioridade'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            <?php endif; ?>

                            <div
                                class="avatar-sm <?= $statusColuna === 'concluido' ? 'bg-success' : '' ?>"
                                title="Responsáveis: <?= htmlspecialchars($tituloResponsaveis, ENT_QUOTES, 'UTF-8') ?>"
                            >
                                <?= $statusColuna === 'concluido'
                                    ? '<i class="fas fa-check"></i>'
                                    : htmlspecialchars($iniciais, ENT_QUOTES, 'UTF-8')
                                ?>
                            </div>
                        </div>

                        <div
                            class="card-title card-title-link <?= $statusColuna === 'concluido' ? 'text-muted text-decoration-line-through' : 'text-dark' ?>"
                            style="cursor:pointer;"
                            onclick="abrirModalEdicao(this.parentElement)"
                        >
                            <?= htmlspecialchars($t['titulo'], ENT_QUOTES, 'UTF-8') ?>
                        </div>

                        <div class="responsaveis-texto mb-2">
                            <i class="fas fa-users me-1"></i>
                            <?= htmlspecialchars($tituloResponsaveis, ENT_QUOTES, 'UTF-8') ?>
                        </div>

                        <div class="card-meta">
                            <?php if ($prazoTimestamp): ?>
                                <span class="<?= $statusColuna === 'concluido' ? 'text-success' : $atrasado ?>">
                                    <i class="<?= $statusColuna === 'concluido' ? 'fas fa-calendar-check' : 'far fa-clock' ?>"></i>
                                    <?= date('d/m H:i', $prazoTimestamp) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">Sem prazo</span>
                            <?php endif; ?>

                            <?php if (!empty($t['descricao'])): ?>
                                <span class="text-muted" title="Possui detalhes">
                                    <i class="fas fa-align-left"></i>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>

</div>

<div class="modal fade" id="modalEditarTarefa" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-bottom-0 bg-light">
                <h5 class="modal-title fw-bold text-dark">
                    <i class="fas fa-edit text-primary me-2"></i>
                    Detalhes da Demanda
                </h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Close"
                ></button>
            </div>

            <form action="ajax_salvar_edicao.php" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="tarefa_id" id="edit_tarefa_id">

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted text-uppercase">
                            O que precisa ser feito?
                        </label>

                        <input
                            type="text"
                            name="titulo"
                            id="edit_titulo"
                            class="form-control fw-bold text-dark"
                            <?= $eh_master ? '' : 'readonly' ?>
                            required
                        >
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted text-uppercase">
                            Instruções / Detalhes
                        </label>

                        <textarea
                            name="descricao"
                            id="edit_descricao"
                            class="form-control"
                            rows="5"
                            <?= $eh_master ? '' : 'readonly' ?>
                            placeholder="Nenhum detalhe informado..."
                        ></textarea>
                    </div>

                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted text-uppercase">
                                Prazo Máximo
                            </label>

                            <input
                                type="datetime-local"
                                name="prazo"
                                id="edit_prazo"
                                class="form-control"
                                <?= $eh_master ? '' : 'readonly' ?>
                                required
                            >
                        </div>

                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted text-uppercase">
                                Prioridade
                            </label>

                            <select
                                name="prioridade"
                                id="edit_prioridade"
                                class="form-select"
                                <?= $eh_master ? '' : 'disabled' ?>
                            >
                                <option value="baixa">🔵 Baixa</option>
                                <option value="media">🟡 Média</option>
                                <option value="alta">🔴 Alta</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="modal-footer border-top-0 bg-light">
                    <button
                        type="button"
                        class="btn btn-outline-secondary rounded-pill fw-bold px-4"
                        data-bs-dismiss="modal"
                    >
                        Fechar
                    </button>

                    <?php if ($eh_master): ?>
                        <button
                            type="submit"
                            class="btn btn-primary rounded-pill fw-bold px-4"
                        >
                            <i class="fas fa-save me-2"></i>
                            Salvar Alterações
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const colunas = [
        document.getElementById('col-pendente'),
        document.getElementById('col-em_andamento'),
        document.getElementById('col-concluido')
    ];

    colunas.forEach(col => {
        new Sortable(col, {
            group: 'kanban',
            animation: 150,
            ghostClass: 'sortable-ghost',

            onEnd: function(evt) {
                const itemEl = evt.item;
                const idTarefa = itemEl.getAttribute('data-id');
                const novoStatus = evt.to.getAttribute('data-status');

                if (evt.from === evt.to) {
                    return;
                }

                atualizarStatusBanco(idTarefa, novoStatus);
            }
        });
    });
});

function atualizarStatusBanco(id, status) {
    const params = new URLSearchParams();

    params.append('tarefa_id', id);
    params.append('novo_status', status);

    fetch('ajax_atualizar_tarefa.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: params.toString()
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Resposta inválida do servidor.');
        }

        return response.json();
    })
    .then(data => {
        if (!data.sucesso) {
            alert('Atenção: ' + (data.erro || 'Não foi possível atualizar.'));
            window.location.reload();
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        alert('Falha ao comunicar com o servidor.');
        window.location.reload();
    });
}

function abrirModalEdicao(cardElement) {
    document.getElementById('edit_tarefa_id').value =
        cardElement.getAttribute('data-id') || '';

    document.getElementById('edit_titulo').value =
        cardElement.getAttribute('data-titulo') || '';

    document.getElementById('edit_descricao').value =
        cardElement.getAttribute('data-descricao') || '';

    document.getElementById('edit_prazo').value =
        cardElement.getAttribute('data-prazo') || '';

    const prioridade = document.getElementById('edit_prioridade');

    if (prioridade) {
        prioridade.value =
            cardElement.getAttribute('data-prioridade') || 'media';
    }

    const modal = new bootstrap.Modal(
        document.getElementById('modalEditarTarefa')
    );

    modal.show();
}
</script>

</body>
</html>
