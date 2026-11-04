<?php

// admin_atendimentos.php

// Kanban de Relacionamento e Nutrição de Leads (Eleitores)



ini_set('display_errors', 0);

ini_set('display_startup_errors', 0);

error_reporting(E_ALL);



if (session_status() === PHP_SESSION_NONE) { session_start(); }



require 'config.php';

require 'protecao.php';



if (!defined('TENANT_ID')) die("Acesso Restrito.");



// =========================================================================

// REGRA DE VISIBILIDADE CRÍTICA: MASTER/ADMIN VÊ TUDO, ATENDENTE VÊ AS SUAS

// =========================================================================

$meu_id = $_SESSION['id'] ?? $_SESSION['admin_id'] ?? 0;



$eh_master_ou_admin = false;

if (isset($_SESSION['nivel']) && in_array(strtolower($_SESSION['nivel']), ['admin', 'master'])) {

    $eh_master_ou_admin = true;

}

if (function_exists('tem_acesso') && tem_acesso('master')) {

    $eh_master_ou_admin = true;

}



// =========================================================================

// AUTO-MIGRATION (Cria as colunas necessárias na tabela de eleitores)

// =========================================================================

try {

    // Adiciona as colunas para o fluxo de atendimento, ignorando se já existirem

    $pdo->exec("ALTER TABLE eleitores ADD COLUMN atendente_id INT DEFAULT NULL");

    $pdo->exec("ALTER TABLE eleitores ADD COLUMN fase_funil VARCHAR(50) DEFAULT 'entrada'");

    $pdo->exec("ALTER TABLE eleitores ADD COLUMN proximo_contato DATE DEFAULT NULL");

} catch (Exception $e) {

    // O erro será disparado se as colunas já existirem, o que é esperado e seguro ignorar.

}



// =========================================================================

// REQUISIÇÕES AJAX (Arrastar e Soltar / Agendar Data)

// =========================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_atendimento'])) {

    header('Content-Type: application/json');

    

    try {

        $eleitor_id = (int)$_POST['eleitor_id'];

        

        // Verifica se a atendente tem permissão para mover este card

        if (!$eh_master_ou_admin) {

            $check = $pdo->prepare("SELECT atendente_id FROM eleitores WHERE id = ? AND tenant_id = ?");

            $check->execute([$eleitor_id, TENANT_ID]);

            if ($check->fetchColumn() != $meu_id) {

                echo json_encode(['sucesso' => false, 'erro' => 'Sem permissão para mover este contato.']);

                exit;

            }

        }



        if ($_POST['acao'] === 'mover_funil') {

            $nova_fase = $_POST['nova_fase'];

            $stmt = $pdo->prepare("UPDATE eleitores SET fase_funil = ? WHERE id = ? AND tenant_id = ?");

            $stmt->execute([$nova_fase, $eleitor_id, TENANT_ID]);

            echo json_encode(['sucesso' => true]);

            exit;

        }



        if ($_POST['acao'] === 'agendar_contato') {

            $nova_data = $_POST['nova_data']; // Formato YYYY-MM-DD

            $stmt = $pdo->prepare("UPDATE eleitores SET proximo_contato = ?, fase_funil = 'nutricao' WHERE id = ? AND tenant_id = ?");

            $stmt->execute([$nova_data, $eleitor_id, TENANT_ID]);

            echo json_encode(['sucesso' => true]);

            exit;

        }

        

        // Delegação de lead para atendente (Apenas Master/Admin)

        if ($_POST['acao'] === 'delegar_lead' && $eh_master_ou_admin) {

            $novo_atendente = (int)$_POST['atendente_id'];

            $stmt = $pdo->prepare("UPDATE eleitores SET atendente_id = ?, fase_funil = 'entrada' WHERE id = ? AND tenant_id = ?");

            $stmt->execute([$novo_atendente, $eleitor_id, TENANT_ID]);

            echo json_encode(['sucesso' => true]);

            exit;

        }



    } catch (Exception $e) {

        echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]);

        exit;

    }

}



// =========================================================================

// BUSCA E DISTRIBUIÇÃO DOS LEADS

// =========================================================================



$filtro_atendente = $_GET['atendente_id'] ?? '';



$sql_where = "WHERE e.tenant_id = ? AND e.atendente_id IS NOT NULL";

$params = [TENANT_ID];



if (!$eh_master_ou_admin) {

    // Atendentes veem apenas os leads delegados a elas

    $sql_where .= " AND e.atendente_id = ?";

    $params[] = $meu_id;

} else {

    if ($filtro_atendente !== '') {

        $sql_where .= " AND e.atendente_id = ?";

        $params[] = (int)$filtro_atendente;

    }

}



$sql = "SELECT e.id, e.nome, e.whatsapp, e.fase_funil, e.proximo_contato, u.nome as nome_atendente 

        FROM eleitores e 

        LEFT JOIN usuarios u ON e.atendente_id = u.id 

        $sql_where 

        ORDER BY e.proximo_contato ASC, e.nome ASC";



$stmt = $pdo->prepare($sql);

$stmt->execute($params);

$leads_bd = $stmt->fetchAll(PDO::FETCH_ASSOC);



// Estrutura do Kanban

$funil = [

    'entrada'    => [], // Novos leads delegados

    'hoje'       => [], // Contatos para fazer hoje

    'aguardando' => [], // Mensagem enviada, aguardando resposta

    'nutricao'   => []  // Aquecimento agendado para o futuro

];



$hoje_date = date('Y-m-d');



foreach ($leads_bd as $lead) {

    $fase = $lead['fase_funil'] ?: 'entrada';

    

    // Automação visual: Se estiver em nutrição e a data for hoje ou atrasada, força visualmente para "Hoje"

    if ($fase === 'nutricao' && $lead['proximo_contato'] && $lead['proximo_contato'] <= $hoje_date) {

        $fase = 'hoje';

    }

    

    if (isset($funil[$fase])) {

        $funil[$fase][] = $lead;

    } else {

        $funil['entrada'][] = $lead;

    }

}



// Puxa lista de equipe para delegação/filtros (Apenas Admin/Master)

$equipe = [];

if ($eh_master_ou_admin) {

    try {

        $stmtEq = $pdo->prepare("SELECT id, nome FROM usuarios WHERE tenant_id = ? ORDER BY nome ASC");

        $stmtEq->execute([TENANT_ID]);

        $equipe = $stmtEq->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {}

}



function limparTelefone($zap) {

    return preg_replace('/[^0-9]/', '', (string)$zap);

}

?>

<!DOCTYPE html>

<html lang="pt-br">

<head>

    <meta charset="UTF-8">

    <title>CRM de Atendimento</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">

    

    <style>

        body { background-color: #f8fafc; font-family: 'Inter', sans-serif; }

        

        .kanban-board {

            display: flex;

            align-items: flex-start;

            gap: 20px;

            overflow-x: auto;

            padding-bottom: 20px;

            min-height: calc(100vh - 120px);

        }

        

        .kanban-column {

            background-color: #e2e8f0;

            border-radius: 12px;

            width: 320px;

            min-width: 320px;

            max-height: calc(100vh - 140px);

            display: flex;

            flex-direction: column;

            box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);

            border: 1px solid #cbd5e1;

        }

        

        .kanban-header {

            padding: 15px;

            font-weight: 800;

            color: #1e293b;

            font-size: 0.95rem;

            display: flex;

            justify-content: space-between;

            align-items: center;

            border-bottom: 2px solid rgba(0,0,0,0.05);

        }

        

        .kanban-leads {

            padding: 10px;

            overflow-y: auto;

            flex-grow: 1;

            min-height: 100px;

        }

        

        /* O Card do Eleitor */

        .lead-card {

            background-color: #ffffff;

            border-radius: 10px;

            padding: 15px;

            margin-bottom: 12px;

            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);

            cursor: grab;

            border-left: 4px solid transparent;

            transition: transform 0.1s ease;

        }

        

        .lead-card:active { cursor: grabbing; transform: scale(0.98); }

        .lead-card:hover { box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); }

        .sortable-ghost { opacity: 0.4; background-color: #f1f5f9; }

        

        .lead-name { font-weight: 700; font-size: 1rem; color: #0f172a; margin-bottom: 5px; }

        .lead-meta { font-size: 0.8rem; color: #64748b; display: flex; align-items: center; justify-content: space-between; margin-top: 12px; }

        

        .btn-zap { background: #25D366; color: white; border-radius: 50px; padding: 4px 10px; font-size: 0.75rem; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; transition: 0.2s;}

        .btn-zap:hover { background: #128C7E; color: white; }

        

        .btn-agenda { background: #f1f5f9; color: #3b82f6; border: 1px solid #e2e8f0; border-radius: 8px; padding: 4px 8px; font-size: 0.75rem; font-weight: 600; cursor: pointer; transition: 0.2s;}

        .btn-agenda:hover { background: #e0e7ff; }



        /* Cores das colunas */

        .col-entrada { border-top: 4px solid #64748b; }

        .col-hoje { border-top: 4px solid #ef4444; }

        .col-aguardando { border-top: 4px solid #f59e0b; }

        .col-nutricao { border-top: 4px solid #3b82f6; }

    </style>

</head>

<body class="p-3 p-md-4">



    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 gap-3">

        <div>

            <h4 class="fw-bold text-dark m-0"><i class="fas fa-headset text-primary me-2"></i> CRM de Atendimento</h4>

            <small class="text-muted">Acompanhamento e nutrição individual de contatos.</small>

        </div>

        

        <div class="d-flex flex-wrap gap-2 align-items-center">

            

            <!-- FILTRO DE EQUIPE (Apenas Admin/Master) -->

            <?php if($eh_master_ou_admin): ?>

            <form method="GET" class="m-0">

                <select name="atendente_id" class="form-select fw-bold border-primary shadow-sm" onchange="this.form.submit()" style="border-radius: 50px;">

                    <option value="">👥 Ver Toda a Equipe</option>

                    <?php foreach($equipe as $u): ?>

                        <option value="<?= $u['id'] ?>" <?= $filtro_atendente == $u['id'] ? 'selected' : '' ?>>👤 <?= htmlspecialchars($u['nome']) ?></option>

                    <?php endforeach; ?>

                </select>

            </form>

            <?php endif; ?>



            <a href="admin_painel.php" class="btn btn-outline-secondary fw-bold rounded-pill shadow-sm"><i class="fas fa-arrow-left me-1"></i> Voltar</a>

        </div>

    </div>



    <div class="kanban-board">

        

        <!-- COLUNA 1: CAIXA DE ENTRADA -->

        <div class="kanban-column col-entrada">

            <div class="kanban-header">

                <span>📥 Novos Leads</span>

                <span class="badge bg-secondary rounded-pill"><?= count($funil['entrada']) ?></span>

            </div>

            <div class="kanban-leads" id="col-entrada" data-fase="entrada">

                <?php foreach($funil['entrada'] as $l): $zap = limparTelefone($l['whatsapp']); ?>

                    <div class="lead-card" data-id="<?= $l['id'] ?>">

                        <div class="lead-name"><?= htmlspecialchars($l['nome']) ?></div>

                        

                        <?php if($eh_master_ou_admin): ?>

                            <div class="small text-muted mb-2 fst-italic"><i class="fas fa-user-circle"></i> <?= htmlspecialchars($l['nome_atendente'] ?? 'Sem atendente') ?></div>

                        <?php endif; ?>



                        <div class="lead-meta border-top pt-2 mt-2">

                            <?php if(!empty($zap)): ?>

                                <a href="https://wa.me/55<?= $zap ?>" target="_blank" class="btn-zap"><i class="fab fa-whatsapp"></i> Chamar</a>

                            <?php else: ?>

                                <span class="badge bg-light text-muted border">Sem Zap</span>

                            <?php endif; ?>

                            

                            <button class="btn-agenda" onclick="abrirAgendamento(<?= $l['id'] ?>, '<?= htmlspecialchars($l['nome']) ?>')"><i class="far fa-calendar-alt"></i></button>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        </div>



        <!-- COLUNA 2: CONTATAR HOJE -->

        <div class="kanban-column col-hoje">

            <div class="kanban-header">

                <span>🚨 Para Hoje</span>

                <span class="badge bg-danger rounded-pill"><?= count($funil['hoje']) ?></span>

            </div>

            <div class="kanban-leads" id="col-hoje" data-fase="hoje">

                <?php foreach($funil['hoje'] as $l): $zap = limparTelefone($l['whatsapp']); ?>

                    <div class="lead-card border-start border-danger" data-id="<?= $l['id'] ?>">

                        <div class="lead-name"><?= htmlspecialchars($l['nome']) ?></div>

                        

                        <?php if($eh_master_ou_admin): ?>

                            <div class="small text-muted mb-2 fst-italic"><i class="fas fa-user-circle"></i> <?= htmlspecialchars($l['nome_atendente'] ?? '') ?></div>

                        <?php endif; ?>



                        <div class="lead-meta border-top pt-2 mt-2">

                            <?php if(!empty($zap)): ?>

                                <a href="https://wa.me/55<?= $zap ?>" target="_blank" class="btn-zap"><i class="fab fa-whatsapp"></i> Retornar</a>

                            <?php endif; ?>

                            

                            <button class="btn-agenda text-danger border-danger" onclick="abrirAgendamento(<?= $l['id'] ?>, '<?= htmlspecialchars($l['nome']) ?>')">Reagendar</button>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        </div>



        <!-- COLUNA 3: AGUARDANDO RESPOSTA -->

        <div class="kanban-column col-aguardando">

            <div class="kanban-header">

                <span>⏳ Aguardando</span>

                <span class="badge bg-warning text-dark rounded-pill"><?= count($funil['aguardando']) ?></span>

            </div>

            <div class="kanban-leads" id="col-aguardando" data-fase="aguardando">

                <?php foreach($funil['aguardando'] as $l): ?>

                    <div class="lead-card border-start border-warning" data-id="<?= $l['id'] ?>">

                        <div class="lead-name"><?= htmlspecialchars($l['nome']) ?></div>

                        

                        <?php if($eh_master_ou_admin): ?>

                            <div class="small text-muted mb-2 fst-italic"><i class="fas fa-user-circle"></i> <?= htmlspecialchars($l['nome_atendente'] ?? '') ?></div>

                        <?php endif; ?>



                        <div class="lead-meta border-top pt-2 mt-2 justify-content-end">

                            <button class="btn-agenda" onclick="abrirAgendamento(<?= $l['id'] ?>, '<?= htmlspecialchars($l['nome']) ?>')"><i class="fas fa-forward"></i> Próximo Passo</button>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        </div>



        <!-- COLUNA 4: NUTRIÇÃO (AGENDADOS) -->

        <div class="kanban-column col-nutricao">

            <div class="kanban-header">

                <span>🌱 Em Nutrição</span>

                <span class="badge bg-primary rounded-pill"><?= count($funil['nutricao']) ?></span>

            </div>

            <div class="kanban-leads" id="col-nutricao" data-fase="nutricao">

                <?php foreach($funil['nutricao'] as $l): ?>

                    <div class="lead-card border-start border-primary opacity-75" data-id="<?= $l['id'] ?>">

                        <div class="lead-name"><?= htmlspecialchars($l['nome']) ?></div>

                        

                        <div class="d-flex justify-content-between align-items-center mt-1 mb-2">

                            <span class="badge bg-light text-primary border"><i class="far fa-clock"></i> <?= date('d/m/Y', strtotime($l['proximo_contato'])) ?></span>

                            <?php if($eh_master_ou_admin): ?>

                                <span class="small text-muted fst-italic"><i class="fas fa-user-circle"></i> <?= htmlspecialchars($l['nome_atendente'] ?? '') ?></span>

                            <?php endif; ?>

                        </div>



                        <div class="lead-meta border-top pt-2 mt-2 justify-content-end">

                            <button class="btn-agenda" onclick="abrirAgendamento(<?= $l['id'] ?>, '<?= htmlspecialchars($l['nome']) ?>')"><i class="fas fa-edit"></i> Alterar</button>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        </div>



    </div>



    <!-- MODAL AGENDAMENTO DE PRÓXIMO CONTATO -->

    <div class="modal fade" id="modalAgendamento" tabindex="-1">

        <div class="modal-dialog modal-dialog-centered">

            <div class="modal-content border-0 shadow-lg rounded-4">

                <div class="modal-header bg-primary text-white border-0 rounded-top-4">

                    <h5 class="modal-title fw-bold"><i class="far fa-calendar-check me-2"></i> Agendar Próximo Contato</h5>

                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>

                </div>

                

                <div class="modal-body p-4 bg-light">

                    <input type="hidden" id="modal_eleitor_id">

                    <p class="text-muted small mb-3">Defina quando você deve fazer o próximo contato com <strong id="modal_eleitor_nome" class="text-dark"></strong>.</p>

                    

                    <div class="row g-2 mb-4">

                        <div class="col-4">

                            <button class="btn btn-outline-primary w-100 fw-bold py-2" onclick="setarDias(1)">+ 1 Dia</button>

                        </div>

                        <div class="col-4">

                            <button class="btn btn-outline-primary w-100 fw-bold py-2" onclick="setarDias(3)">+ 3 Dias</button>

                        </div>

                        <div class="col-4">

                            <button class="btn btn-outline-primary w-100 fw-bold py-2" onclick="setarDias(21)">+ 21 Dias</button>

                        </div>

                    </div>



                    <div class="mb-3 border-top pt-3">

                        <label class="form-label fw-bold small text-muted">Ou escolha uma data específica:</label>

                        <input type="date" id="modal_data_especifica" class="form-control form-control-lg fw-bold text-center">

                    </div>

                </div>

                <div class="modal-footer border-0 bg-light rounded-bottom-4">

                    <button type="button" class="btn btn-secondary fw-bold rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>

                    <button type="button" class="btn btn-primary fw-bold rounded-pill px-4 shadow-sm" onclick="salvarAgendamento()">Salvar Agendamento</button>

                </div>

            </div>

        </div>

    </div>



    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>

    

    <script>

        document.addEventListener("DOMContentLoaded", function() {

            const colunas = [

                document.getElementById('col-entrada'),

                document.getElementById('col-hoje'),

                document.getElementById('col-aguardando'),

                document.getElementById('col-nutricao')

            ];



            colunas.forEach(col => {

                new Sortable(col, {

                    group: 'crm_leads',

                    animation: 150,

                    ghostClass: 'sortable-ghost',

                    onEnd: function (evt) {

                        const eleitorId = evt.item.getAttribute('data-id');

                        const novaFase = evt.to.getAttribute('data-fase');



                        if (evt.from === evt.to) return;

                        

                        atualizarFaseServidor(eleitorId, novaFase);

                    },

                });

            });

        });



        // 1. Atualizar mudança de coluna via AJAX

        function atualizarFaseServidor(eleitorId, fase) {

            const params = new URLSearchParams();

            params.append('ajax_atendimento', '1');

            params.append('acao', 'mover_funil');

            params.append('eleitor_id', eleitorId);

            params.append('nova_fase', fase);



            fetch(window.location.href, {

                method: 'POST',

                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },

                body: params.toString()

            })

            .then(res => res.json())

            .then(data => {

                if(!data.sucesso) {

                    alert("Atenção: " + data.erro);

                    window.location.reload(); 

                }

            }).catch(e => { alert("Erro de conexão."); window.location.reload(); });

        }



        // 2. Lógica do Modal de Agendamento

        const modalAgenda = new bootstrap.Modal(document.getElementById('modalAgendamento'));



        function abrirAgendamento(eleitorId, eleitorNome) {

            document.getElementById('modal_eleitor_id').value = eleitorId;

            document.getElementById('modal_eleitor_nome').innerText = eleitorNome;

            document.getElementById('modal_data_especifica').value = '';

            modalAgenda.show();

        }



        function setarDias(dias) {

            let data = new Date();

            data.setDate(data.getDate() + dias);

            document.getElementById('modal_data_especifica').value = data.toISOString().split('T')[0];

        }



        function salvarAgendamento() {

            const dataEscolhida = document.getElementById('modal_data_especifica').value;

            const eleitorId = document.getElementById('modal_eleitor_id').value;



            if (!dataEscolhida) { alert("Escolha uma data para o retorno."); return; }



            const params = new URLSearchParams();

            params.append('ajax_atendimento', '1');

            params.append('acao', 'agendar_contato');

            params.append('eleitor_id', eleitorId);

            params.append('nova_data', dataEscolhida);



            fetch(window.location.href, {

                method: 'POST',

                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },

                body: params.toString()

            })

            .then(res => res.json())

            .then(data => {

                if(data.sucesso) {

                    window.location.reload(); // Recarrega para mover o card para a coluna certa

                } else {

                    alert("Erro ao agendar: " + data.erro);

                }

            });

        }

    </script>

</body>

</html> 

