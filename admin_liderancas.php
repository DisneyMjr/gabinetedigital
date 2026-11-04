<?php
session_start();
require 'config.php';
require 'protecao.php';

if (!defined('TENANT_ID')) die("Erro crítico.");

// --- 1. PROCESSAR FORMULÁRIO (ADICIONAR/EDITAR LÍDER) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao'])) {
    if ($_POST['acao'] == 'salvar_lider') {
        $nome = trim($_POST['nome']);
        $whatsapp = $_POST['whatsapp'];
        $meta = (int)$_POST['meta'];
        $bairro = $_POST['bairro'];
        
        // Insere como um eleitor, mas marcado como Líder (is_lider = 1)
        $stmt = $pdo->prepare("INSERT INTO eleitores (tenant_id, nome, whatsapp, bairro, is_lider, meta_lider, status_voto) VALUES (?, ?, ?, ?, 1, ?, 3)"); // Status 3 = Já conquistado (o líder é voto certo)
        $stmt->execute([TENANT_ID, $nome, $whatsapp, $bairro, $meta]);
        header("Location: admin_liderancas.php"); exit;
    }
}

// --- 2. BUSCAR LIDERANÇAS E SEUS DESEMPENHOS ---
// Essa query busca o líder e CONTA quantos eleitores estão vinculados a ele (responsavel_id)
$sql = "
    SELECT 
        l.id, l.nome, l.whatsapp, l.bairro, l.meta_lider,
        (SELECT COUNT(*) FROM eleitores e WHERE e.responsavel_id = l.id AND e.tenant_id = l.tenant_id) as votos_conquistados
    FROM eleitores l
    WHERE l.tenant_id = ? AND l.is_lider = 1
    ORDER BY votos_conquistados DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute([TENANT_ID]);
$lideres = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Totais gerais
$total_lideres = count($lideres);
$total_meta_lideres = array_sum(array_column($lideres, 'meta_lider'));
$total_conquistados_lideres = array_sum(array_column($lideres, 'votos_conquistados'));
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8"><title>Gestão de Lideranças</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Poppins', sans-serif; }
        .sidebar { width: 250px; position: fixed; height: 100vh; background: #fff; border-right: 1px solid #eee; z-index: 1000; }
        .main-content { margin-left: 250px; padding: 30px; }
        .card-lider { border: none; border-radius: 15px; background: white; box-shadow: 0 4px 15px rgba(0,0,0,0.03); transition: transform 0.2s; position: relative; overflow: hidden; }
        .card-lider:hover { transform: translateY(-5px); box-shadow: 0 8px 25px rgba(0,0,0,0.08); }
        .progress-thick { height: 12px; border-radius: 6px; background-color: #f0f2f5; }
        .badge-rank { position: absolute; top: 15px; right: 15px; background: #FFD700; color: #333; font-weight: bold; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        @media (max-width: 768px) { .sidebar { display: none; } .main-content { margin-left: 0; } }
    </style>
</head>
<body>

<div class="sidebar d-none d-md-block p-3">
    <h5 class="fw-bold text-primary mb-4 px-2"><i class="fas fa-bullseye me-2"></i> Campanha</h5>
    <ul class="nav flex-column gap-2">
        <li class="nav-item"><a href="admin_painel.php" class="nav-link text-secondary"><i class="fas fa-arrow-left me-2"></i> Voltar</a></li>
        <li class="nav-item"><a href="admin_envolvimento.php" class="nav-link text-secondary"><i class="fas fa-chart-line me-2"></i> Visão Geral</a></li>
        <li class="nav-item"><a href="#" class="nav-link active bg-primary text-white rounded-3"><i class="fas fa-user-tie me-2"></i> Lideranças</a></li>
    </ul>
</div>

<div class="main-content">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold mb-0">Meus Multiplicadores</h4>
            <small class="text-muted">Gestão do exército da campanha</small>
        </div>
        <button class="btn btn-primary rounded-pill fw-bold px-4" data-bs-toggle="modal" data-bs-target="#modalLider">
            <i class="fas fa-plus me-2"></i> Nova Liderança
        </button>
    </div>

    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm p-3 rounded-4 bg-primary text-white h-100">
                <small class="text-white-50 fw-bold text-uppercase">Total de Líderes</small>
                <div class="display-4 fw-bold"><?= $total_lideres ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm p-3 rounded-4 bg-white h-100">
                <small class="text-muted fw-bold text-uppercase">Potencial de Votos (Metas)</small>
                <div class="display-4 fw-bold text-dark"><?= $total_meta_lideres ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm p-3 rounded-4 bg-white h-100">
                <small class="text-muted fw-bold text-uppercase">Votos já Cadastrados</small>
                <div class="display-4 fw-bold text-success"><?= $total_conquistados_lideres ?></div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <?php 
        $rank = 1;
        foreach($lideres as $l): 
            $porcentagem = ($l['meta_lider'] > 0) ? round(($l['votos_conquistados'] / $l['meta_lider']) * 100) : 0;
            $cor_barra = ($porcentagem >= 100) ? 'bg-success' : (($porcentagem >= 50) ? 'bg-primary' : 'bg-warning');
            
            // Link do WhatsApp para cobrança
            $msg_zap = "Olá {$l['nome']}, vi aqui no sistema que você cadastrou {$l['votos_conquistados']} apoiadores. Sua meta é {$l['meta_lider']}. Vamos pra cima!";
            $link_zap = "https://wa.me/55" . preg_replace('/[^0-9]/', '', $l['whatsapp']) . "?text=" . urlencode($msg_zap);
        ?>
        <div class="col-md-6 col-xl-4">
            <div class="card-lider p-4 h-100">
                <?php if($rank <= 3): ?>
                    <div class="badge-rank shadow-sm"><?= $rank ?>º</div>
                <?php endif; ?>
                
                <div class="d-flex align-items-center mb-3">
                    <div class="rounded-circle bg-light d-flex align-items-center justify-content-center fw-bold text-primary fs-4" style="width: 50px; height: 50px;">
                        <?= strtoupper(substr($l['nome'], 0, 1)) ?>
                    </div>
                    <div class="ms-3">
                        <h6 class="fw-bold mb-0 text-dark"><?= $l['nome'] ?></h6>
                        <small class="text-muted"><i class="fas fa-map-marker-alt me-1"></i> <?= $l['bairro'] ?: 'Geral' ?></small>
                    </div>
                </div>

                <div class="d-flex justify-content-between text-muted small fw-bold mb-1">
                    <span>Progresso</span>
                    <span><?= $l['votos_conquistados'] ?> / <?= $l['meta_lider'] ?></span>
                </div>
                <div class="progress progress-thick mb-3">
                    <div class="progress-bar <?= $cor_barra ?>" style="width: <?= $porcentagem ?>%"><?= $porcentagem ?>%</div>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                   <a href="admin_eleitores.php?filtro_lider=<?= $l['id'] ?>" class="btn btn-sm btn-light border text-dark fw-bold w-100"><i class="fas fa-list me-2"></i> Ver Lista</a>
                   <a href="<?= $link_zap ?>" target="_blank" class="btn btn-sm btn-outline-success fw-bold w-100"><i class="fab fa-whatsapp me-2"></i> Cobrar</a>
                </div>
            </div>
        </div>
        <?php $rank++; endforeach; ?>

        <?php if(empty($lideres)): ?>
            <div class="col-12 text-center py-5">
                <i class="fas fa-users-slash fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">Nenhuma liderança cadastrada.</h5>
                <p class="text-muted">Cadastre seus multiplicadores para começar a gestão.</p>
            </div>
        <?php endif; ?>
    </div>

</div>

<div class="modal fade" id="modalLider" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold">Nova Liderança</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="acao" value="salvar_lider">
                <div class="mb-3">
                    <label class="fw-bold small">Nome Completo</label>
                    <input type="text" name="nome" class="form-control" required>
                </div>
                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="fw-bold small">WhatsApp</label>
                        <input type="text" name="whatsapp" class="form-control" placeholder="(00) 00000-0000">
                    </div>
                    <div class="col-6 mb-3">
                        <label class="fw-bold small">Meta de Votos</label>
                        <input type="number" name="meta" class="form-control" value="50">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="fw-bold small">Região / Bairro de Atuação</label>
                    <input type="text" name="bairro" class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary w-100 fw-bold">Cadastrar Líder</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>