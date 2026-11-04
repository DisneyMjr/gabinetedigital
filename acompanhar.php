<?php
require_once 'config.php';
// O config.php carrega o saas.php e define TENANT_ID

// --- 1. CONFIGURAÇÕES VISUAIS ---
try {
    $stmt_conf = $pdo->prepare("SELECT * FROM configuracoes WHERE tenant_id = ?");
    $stmt_conf->execute([TENANT_ID]);
    $conf = $stmt_conf->fetch(PDO::FETCH_ASSOC);

    $nome_vereador = $conf['nome_vereador'] ?? 'Gabinete Digital';
    $cor_primaria = $conf['cor_primaria'] ?? '#0d6efd';

} catch (Exception $e) { 
    $nome_vereador = "Gabinete Digital";
    $cor_primaria = '#0d6efd';
}

$resultado = null;
$erro = null;
$msg_sucesso = null;

// --- 2. PROCESSAR AVALIAÇÃO (SE O USUÁRIO ENVIOU) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'avaliar') {
    $nota = (int)$_POST['nota'];
    $comentario = $_POST['comentario'];
    $id_solicitacao = (int)$_POST['id_solicitacao'];

    if ($nota >= 1 && $nota <= 5) {
        $stmtAv = $pdo->prepare("UPDATE solicitacoes SET avaliacao_nota = ?, avaliacao_comentario = ? WHERE id = ? AND tenant_id = ?");
        $stmtAv->execute([$nota, $comentario, $id_solicitacao, TENANT_ID]);
        $msg_sucesso = "Obrigado! Sua avaliação foi registrada.";
    }
}

// --- 3. BUSCAR DADOS DO PROTOCOLO ---
if (isset($_GET['protocolo']) || isset($_POST['protocolo_hidden'])) {
    $prot = trim($_GET['protocolo'] ?? $_POST['protocolo_hidden']);
    
    $sql = "SELECT * FROM solicitacoes WHERE protocolo = ? AND tenant_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$prot, TENANT_ID]);
    $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if(!$resultado) {
        $erro = "Protocolo não encontrado neste gabinete.";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="<?= $cor_primaria ?>">
    <title>Acompanhar - <?= $nome_vereador ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root { --cor-primaria: <?= $cor_primaria ?>; }
        
        body { background: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
        
        .text-primary { color: var(--cor-primaria) !important; }
        .btn-primary { background-color: var(--cor-primaria); border-color: var(--cor-primaria); }
        .btn-primary:hover { opacity: 0.9; }
        
        .timeline { border-left: 3px solid #dee2e6; margin-left: 20px; padding-left: 30px; position: relative; }
        .timeline-item { margin-bottom: 30px; position: relative; }
        .timeline-item::before {
            content: ''; position: absolute; left: -39px; top: 0; width: 15px; height: 15px;
            border-radius: 50%; background: #dee2e6; border: 3px solid white; box-shadow: 0 0 0 2px #dee2e6;
        }
        .timeline-item.active::before { background: #28a745; box-shadow: 0 0 0 2px #28a745; }
        
        .card-status { border: none; border-radius: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.05); }
        .bg-resposta { background-color: #f1f3f5; border-left: 4px solid var(--cor-primaria); }

        /* --- ESTRELAS DE AVALIAÇÃO --- */
        .rating { display: flex; flex-direction: row-reverse; justify-content: flex-end; }
        .rating input { display: none; }
        .rating label { font-size: 2rem; color: #ddd; cursor: pointer; padding: 0 5px; transition: color 0.2s; }
        .rating label:hover, .rating label:hover ~ label, .rating input:checked ~ label { color: #ffc107; }
        
        /* Estrelas estáticas (resultado) */
        .static-stars i { color: #ffc107; margin-right: 2px; }
        .static-stars i.text-muted { color: #e9ecef; }
    </style>
</head>
<body>

    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="text-center mb-5">
                    <h2 class="fw-bold text-primary"><i class="fas fa-search-location"></i> Consultar Demanda</h2>
                    <p class="text-muted">Gabinete <strong><?= $nome_vereador ?></strong></p>
                </div>

                <div class="card card-status p-4 mb-4">
                    <form method="GET">
                        <div class="input-group input-group-lg">
                            <input type="text" name="protocolo" class="form-control" placeholder="Ex: 20260128-1234" value="<?= $prot ?? '' ?>" required>
                            <button class="btn btn-primary fw-bold" type="submit">BUSCAR</button>
                        </div>
                    </form>
                </div>

                <?php if($erro): ?>
                    <div class="alert alert-danger rounded-3 text-center"><?= $erro ?></div>
                <?php endif; ?>

                <?php if($msg_sucesso): ?>
                    <div class="alert alert-success rounded-3 text-center fw-bold animate__animated animate__pulse">
                        <i class="fas fa-heart text-danger me-2"></i> <?= $msg_sucesso ?>
                    </div>
                <?php endif; ?>

                <?php if($resultado): ?>
                    <div class="card card-status p-4 animate__animated animate__fadeIn">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <h4 class="fw-bold mb-0"><?= $resultado['tipo'] ?></h4>
                            <span class="badge bg-secondary"><?= $resultado['protocolo'] ?></span>
                        </div>
                        
                        <p class="text-muted mb-4"><i class="fas fa-map-marker-alt text-danger"></i> <?= $resultado['bairro'] ?>, <?= $resultado['rua'] ?></p>
                        
                        <div class="timeline">
                            
                            <div class="timeline-item active">
                                <h6 class="fw-bold text-success">Solicitação Recebida</h6>
                                <p class="small text-muted mb-0">Em <?= date('d/m/Y H:i', strtotime($resultado['data_abertura'] ?? $resultado['criado_em'] ?? 'now')) ?></p>
                                <p class="small">Registrada no sistema.</p>
                            </div>

                            <div class="timeline-item <?= ($resultado['status'] == 'Em Análise' || $resultado['status'] == 'Concluído') ? 'active' : '' ?>">
                                <h6 class="fw-bold <?= ($resultado['status'] == 'Em Análise' || $resultado['status'] == 'Concluído') ? 'text-success' : 'text-muted' ?>">Em Análise</h6>
                                <p class="small">Estamos verificando sua demanda.</p>
                                
                                <?php if($resultado['status'] == 'Em Análise' && !empty($resultado['resposta_admin'])): ?>
                                    <div class="p-3 rounded bg-resposta mt-2">
                                        <small class="text-uppercase fw-bold text-primary mb-1 d-block">Nota:</small>
                                        <span class="text-dark d-block mb-2"><?= nl2br($resultado['resposta_admin']) ?></span>
                                        <?php if(!empty($resultado['anexo_resposta'])): ?>
                                            <a href="<?= $resultado['anexo_resposta'] ?>" target="_blank" class="btn btn-sm btn-white border w-100 fw-bold"><i class="fas fa-paperclip"></i> Ver Anexo</a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="timeline-item <?= ($resultado['status'] == 'Concluído') ? 'active' : '' ?>">
                                <h6 class="fw-bold <?= ($resultado['status'] == 'Concluído') ? 'text-success' : 'text-muted' ?>">Concluído</h6>
                                <p class="small">Processo finalizado.</p>

                                <?php if($resultado['status'] == 'Concluído'): ?>
                                    
                                    <div class="p-3 rounded bg-success-subtle border border-success mt-2 mb-3">
                                        <small class="text-uppercase fw-bold text-success mb-1 d-block">Resposta Final:</small>
                                        <span class="text-dark d-block mb-2"><?= !empty($resultado['resposta_admin']) ? nl2br($resultado['resposta_admin']) : 'Solicitação atendida.' ?></span>
                                        <?php if(!empty($resultado['anexo_resposta'])): ?>
                                            <a href="<?= $resultado['anexo_resposta'] ?>" target="_blank" class="btn btn-sm btn-light border w-100 fw-bold"><i class="fas fa-file-pdf text-danger"></i> Baixar Documento</a>
                                        <?php endif; ?>
                                    </div>

                                    <div class="border-top pt-3">
                                        <?php if(empty($resultado['avaliacao_nota'])): ?>
                                            <h6 class="fw-bold text-center mb-2">Como foi nosso atendimento?</h6>
                                            <form method="POST">
                                                <input type="hidden" name="acao" value="avaliar">
                                                <input type="hidden" name="protocolo_hidden" value="<?= $prot ?>">
                                                <input type="hidden" name="id_solicitacao" value="<?= $resultado['id'] ?>">

                                                <div class="rating justify-content-center mb-3">
                                                    <input type="radio" name="nota" id="star5" value="5" required><label for="star5" title="Excelente"><i class="fas fa-star"></i></label>
                                                    <input type="radio" name="nota" id="star4" value="4"><label for="star4" title="Muito Bom"><i class="fas fa-star"></i></label>
                                                    <input type="radio" name="nota" id="star3" value="3"><label for="star3" title="Bom"><i class="fas fa-star"></i></label>
                                                    <input type="radio" name="nota" id="star2" value="2"><label for="star2" title="Ruim"><i class="fas fa-star"></i></label>
                                                    <input type="radio" name="nota" id="star1" value="1"><label for="star1" title="Péssimo"><i class="fas fa-star"></i></label>
                                                </div>

                                                <div class="mb-2">
                                                    <textarea name="comentario" class="form-control form-control-sm" rows="2" placeholder="Deixe um comentário (opcional)..."></textarea>
                                                </div>
                                                <button type="submit" class="btn btn-warning btn-sm w-100 fw-bold text-dark">ENVIAR AVALIAÇÃO</button>
                                            </form>

                                        <?php else: ?>
                                            <div class="text-center bg-light p-3 rounded">
                                                <small class="text-muted d-block text-uppercase fw-bold mb-1">Sua Avaliação</small>
                                                <div class="static-stars fs-4">
                                                    <?php for($i=1; $i<=5; $i++): ?>
                                                        <i class="fas fa-star <?= ($i <= $resultado['avaliacao_nota']) ? '' : 'text-muted' ?>"></i>
                                                    <?php endfor; ?>
                                                </div>
                                                <?php if(!empty($resultado['avaliacao_comentario'])): ?>
                                                    <p class="small text-muted fst-italic mt-2 mb-0">"<?= $resultado['avaliacao_comentario'] ?>"</p>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="mt-4 text-center">
                            <a href="gabinete_online.php" class="btn btn-outline-secondary btn-sm rounded-pill">Voltar ao Início</a>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
</body>
</html>