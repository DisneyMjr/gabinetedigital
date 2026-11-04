<?php
require 'config.php';
require 'protecao.php';

if (!defined('TENANT_ID')) die("Erro: Gabinete não identificado.");
if (!tem_acesso('ouvidoria') && !tem_acesso('master')) die("Acesso Negado.");

// --- BUSCA DADOS ---
$sql = "SELECT * FROM solicitacoes WHERE tenant_id = ? ORDER BY data_abertura DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([TENANT_ID]);
$todas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ADICIONADO A NOVA COLUNA AQUI
$colunas = [
    'Pendente' => [], 
    'Em Análise' => [], 
    'Concluído Pendente' => [], 
    'Concluído' => []
];

foreach ($todas as $s) {
    $status = $s['status'] ?: 'Pendente';
    if (isset($colunas[$status])) $colunas[$status][] = $s;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8"><title>Fluxo de Solicitações</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f4f7f6; font-family: 'Inter', sans-serif; overflow: hidden; }
        .kanban-container { display: flex; gap: 1rem; padding: 20px; height: calc(100vh - 85px); align-items: flex-start; overflow-x: auto; }
        .kanban-column { background-color: #ebedef; min-width: 320px; border-radius: 12px; display: flex; flex-direction: column; max-height: 100%; border: 1px solid #dee2e6; }
        .kanban-header { padding: 15px; font-weight: 800; text-transform: uppercase; font-size: 0.8rem; display: flex; justify-content: space-between; align-items: center; }
        .kanban-items { padding: 10px; overflow-y: auto; flex-grow: 1; min-height: 200px; }
        .kanban-card { background: white; border-radius: 8px; padding: 15px; margin-bottom: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border-left: 5px solid #ddd; cursor: grab; position: relative; }
        .kanban-card:active { cursor: grabbing; }
        .btn-add-card { border: none; background: transparent; color: #666; font-size: 0.9rem; padding: 10px; text-align: left; width: 100%; border-radius: 0 0 12px 12px; transition: 0.2s; }
        .btn-add-card:hover { background: rgba(0,0,0,0.05); color: #000; }
        
        /* CORES DAS LATERAIS DOS CARDS */
        .border-Pendente { border-left-color: #ffc107 !important; }
        .border-Em-Análise { border-left-color: #0d6efd !important; }
        .border-Concluído-Pendente { border-left-color: #0dcaf0 !important; } /* Azul claro / Info */
        .border-Concluído { border-left-color: #198754 !important; }
        
        .ghost-card { opacity: 0.3; background: #cbd5e0 !important; border: 2px dashed #718096 !important; }
        
        /* Estilo da Etiqueta de Prazo */
        .badge-prazo { position: absolute; top: 12px; right: 15px; font-weight: 700; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
    </style>
</head>
<body>

<nav class="navbar navbar-light bg-white shadow-sm px-4">
    <div class="container-fluid py-2">
        <span class="navbar-brand fw-bold text-primary"><i class="fas fa-th-large me-2"></i> Fluxo de Demandas</span>
        <a href="admin_solicitacoes.php" class="btn btn-secondary btn-sm">Voltar</a>
    </div>
</nav>

<div class="kanban-container">
    <?php foreach($colunas as $status => $itens): ?>
    <div class="kanban-column">
        <div class="kanban-header">
            <span><?= $status ?></span>
            <span class="badge rounded-pill bg-white text-dark border"><?= count($itens) ?></span>
        </div>
        
        <div class="kanban-items" id="<?= str_replace(' ', '-', $status) ?>">
            <?php foreach($itens as $card): 
                
                // --- MOTOR INTELIGENTE DE PRAZOS ---
                $data_abertura = !empty($card['data_abertura']) ? new DateTime($card['data_abertura']) : new DateTime();
                $hoje = new DateTime();
                $dias_corridos = $data_abertura->diff($hoje)->days;

                if ($status == 'Concluído') {
                    $cor_prazo = 'bg-success';
                    $icone_prazo = 'fa-check-double';
                    $texto_prazo = "Concluído";
                } else {
                    if ($dias_corridos == 0) {
                        $cor_prazo = 'bg-info text-dark';
                        $icone_prazo = 'fa-clock';
                        $texto_prazo = "Hoje";
                    } elseif ($dias_corridos <= 3) {
                        $cor_prazo = 'bg-primary';
                        $icone_prazo = 'fa-clock';
                        $texto_prazo = $dias_corridos . " dias";
                    } elseif ($dias_corridos <= 7) {
                        $cor_prazo = 'bg-warning text-dark';
                        $icone_prazo = 'fa-exclamation-triangle';
                        $texto_prazo = $dias_corridos . " dias";
                    } else {
                        $cor_prazo = 'bg-danger';
                        $icone_prazo = 'fa-fire';
                        $texto_prazo = $dias_corridos . " dias";
                    }
                }
            ?>
            <div class="kanban-card border-<?= str_replace(' ', '-', $status) ?>" 
                 data-id="<?= $card['id'] ?>" 
                 onclick='abrirEditar(<?= json_encode($card) ?>)'>
                
                <span class="badge <?= $cor_prazo ?> rounded-pill badge-prazo" style="font-size: 0.70rem;">
                    <i class="fas <?= $icone_prazo ?> me-1"></i> <?= $texto_prazo ?>
                </span>

                <div class="small fw-bold text-muted mb-1 text-uppercase" style="font-size: 0.6rem;"><?= $card['tipo'] ?: 'Geral' ?></div>
                <div class="fw-bold mb-1 pe-5" style="font-size: 0.9rem;"><?= htmlspecialchars($card['nome_cidadao']) ?></div>
                <div class="small text-muted"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($card['bairro']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <button class="btn-add-card" onclick="abrirNovo('<?= $status ?>')">
            <i class="fas fa-plus me-1"></i> Adicionar demanda
        </button>
    </div>
    <?php endforeach; ?>
</div>

<div class="modal fade" id="modalCard" tabindex="-1">
    <div class="modal-dialog">
        <form id="formCard" method="POST" action="api_kanban_save.php">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="modalTitulo">Demanda</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" id="card_id">
                    <input type="hidden" name="status" id="card_status">
                    
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Nome do Cidadão</label>
                        <input type="text" name="nome_cidadao" id="card_nome" class="form-control" required>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label small fw-bold">WhatsApp</label>
                            <input type="text" name="whatsapp" id="card_whatsapp" class="form-control">
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label small fw-bold">Tipo</label>
                            <select name="tipo" id="card_tipo" class="form-select">
                                <option value="Saúde">Saúde</option>
                                <option value="Obras">Obras</option>
                                <option value="Educação">Educação</option>
                                <option value="Outros">Outros</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Bairro</label>
                        <input type="text" name="bairro" id="card_bairro" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Descrição do Problema</label>
                        <textarea name="descricao" id="card_desc" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-danger" id="btnExcluir" onclick="excluirCard()">
                        <i class="fas fa-trash"></i>
                    </button>
                    <div>
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Fechar</button>
                        <button type="submit" class="btn btn-primary">Salvar</button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

<script>
const modal = new bootstrap.Modal(document.getElementById('modalCard'));

function abrirNovo(status) {
    document.getElementById('formCard').reset();
    document.getElementById('card_id').value = '';
    document.getElementById('card_status').value = status;
    document.getElementById('modalTitulo').innerText = 'Nova Demanda em ' + status;
    document.getElementById('btnExcluir').style.display = 'none';
    modal.show();
}

function abrirEditar(dados) {
    document.getElementById('card_id').value = dados.id;
    document.getElementById('card_status').value = dados.status;
    document.getElementById('card_nome').value = dados.nome_cidadao;
    document.getElementById('card_whatsapp').value = dados.whatsapp;
    document.getElementById('card_tipo').value = dados.tipo;
    document.getElementById('card_bairro').value = dados.bairro;
    document.getElementById('card_desc').value = dados.descricao;
    document.getElementById('modalTitulo').innerText = 'Editar Demanda #' + dados.id;
    document.getElementById('btnExcluir').style.display = 'block';
    modal.show();
}

function excluirCard() {
    if(confirm('Deseja realmente apagar esta demanda?')) {
        const id = document.getElementById('card_id').value;
        fetch('api_kanban_save.php?excluir=' + id)
        .then(() => window.location.reload());
    }
}

// DRAG AND DROP
document.querySelectorAll('.kanban-items').forEach(column => {
    new Sortable(column, {
        group: 'kanban',
        animation: 150,
        ghostClass: 'ghost-card',
        onEnd: function (evt) {
            const id = evt.item.getAttribute('data-id');
            // Alterado de .replace para .replaceAll para pegar "Concluído Pendente" perfeitamente
            const newStatus = evt.to.id.replaceAll('-', ' '); 
            evt.item.className = `kanban-card border-${evt.to.id}`;
            
            // Corrige visualmente a etiqueta ao arrastar para Concluído
            const badge = evt.item.querySelector('.badge-prazo');
            if (newStatus === 'Concluído') {
                badge.className = 'badge bg-success rounded-pill badge-prazo';
                badge.innerHTML = '<i class="fas fa-check-double me-1"></i> Concluído';
            }
            
            fetch('api_kanban_update.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id, status: newStatus })
            }).then(() => {
                // Recarrega a página para atualizar os dias exatos e reordenar se necessário
                window.location.reload();
            });
        }
    });
});
</script>
</body>
</html>