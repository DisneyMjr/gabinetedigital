<?php
// acompanhar.php - ÁREA PÚBLICA DE CONSULTA (COM BOTÃO DE AVALIAÇÃO)
require 'conexao.php';

$relato = null;
$erro = '';
$resposta_exibir = '';

if (isset($_GET['protocolo']) && trim($_GET['protocolo']) !== '') {
    $protocolo = strtoupper(trim($_GET['protocolo']));
    
    // 1. Tenta buscar no módulo de Ouvidoria
    $sql = "SELECT r.id, r.status, 
                   r.resposta_publica, 
                   r.created_at, 
                   t.nome_empresa,
                   'OUVIDORIA' as modulo
            FROM reports r 
            JOIN tenants t ON r.tenant_id = t.id 
            WHERE r.protocolo_visualizacao = ?";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$protocolo]);
    $relato = $stmt->fetch();

    // 2. Se não achou na Ouvidoria, tenta na LAI
    if (!$relato) {
        $sql2 = "SELECT l.id, l.status, 
                        l.resposta_cipher, 
                        l.resposta_iv,
                        l.created_at, 
                        l.prazo_resposta, 
                        'LAI' as modulo, 
                        t.nome_empresa
                 FROM lai_pedidos l 
                 JOIN tenants t ON l.tenant_id = t.id
                 WHERE l.protocolo = ?";
        $stmt2 = $pdo->prepare($sql2);
        $stmt2->execute([$protocolo]);
        $relato = $stmt2->fetch();
    }

    if (!$relato) {
        $erro = "Protocolo não encontrado. Verifique o código digitado.";
    } else {
        // 3. Prepara a resposta para exibição
        if ($relato['modulo'] === 'OUVIDORIA') {
            $resposta_exibir = $relato['resposta_publica'] ?? '';
        } else if ($relato['modulo'] === 'LAI') {
            if (!empty($relato['resposta_cipher']) && !empty($relato['resposta_iv'])) {
                $resposta_exibir = decrypt_payload($relato['resposta_cipher'], $relato['resposta_iv']);
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consultar Protocolo | Ouvvi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background: #f8f9fa; display: flex; align-items: center; justify-content: center; min-height: 100vh; font-family: 'Segoe UI', sans-serif;}
        .card-busca { max-width: 600px; width: 100%; border: none; border-radius: 20px; box-shadow: 0 15px 35px rgba(0,0,0,0.1); transition: 0.3s; }
        .res-box { transition: 0.3s; border-left: 5px solid transparent; }
        .res-box.concluido { border-left-color: #198754; background-color: #f8fff9 !important; }
    </style>
</head>
<body>
    <div class="card card-busca p-4 p-md-5 bg-white m-3">
        <h4 class="text-center mb-4 fw-bold text-primary"><i class="bi bi-search"></i> Acompanhar Manifestação</h4>

        <form class="mb-4" method="GET">
            <div class="input-group input-group-lg">
                <input type="text" name="protocolo" class="form-control text-uppercase fw-bold text-center border-primary" placeholder="CÓDIGO DO PROTOCOLO" value="<?php echo htmlspecialchars($_GET['protocolo'] ?? ''); ?>" required>
                <button type="submit" class="btn btn-primary px-4 fw-bold">Buscar</button>
            </div>
        </form>

        <?php if($erro): ?>
            <div class="alert alert-danger text-center fw-semibold rounded-pill"><i class="bi bi-exclamation-triangle"></i> <?php echo $erro; ?></div>
        <?php endif; ?>

        <?php if($relato): 
            $status_limpo = strtolower($relato['status']);
            $is_concluido = ($status_limpo == 'concluido' || $status_limpo == 'respondido');
        ?>
            <div class="border rounded-4 p-4 bg-light position-relative shadow-sm <?php echo $is_concluido ? 'border-success' : ''; ?>">
                
                <span class="position-absolute top-0 end-0 badge bg-dark opacity-75 m-3">
                    <?php echo $relato['modulo']; ?>
                </span>

                <div class="mb-3">
                    <div class="text-muted small fw-bold"><i class="bi bi-building"></i> <?php echo htmlspecialchars($relato['nome_empresa']); ?></div>
                    <div class="text-muted small"><i class="bi bi-calendar3"></i> Registrado em <?php echo date('d/m/Y', strtotime($relato['created_at'])); ?></div>
                </div>

                <div class="mb-4">
                    <label class="small fw-bold text-uppercase text-muted d-block mb-1">Status da Manifestação:</label>
                    <?php if($status_limpo == 'novo' || $status_limpo == 'recebido'): ?>
                        <span class="badge bg-primary fs-6 px-3 py-2 rounded-pill"><i class="bi bi-clock-history"></i> Recebido</span>
                    <?php elseif(strpos($status_limpo, 'analise') !== false): ?>
                        <span class="badge bg-warning text-dark fs-6 px-3 py-2 rounded-pill"><i class="bi bi-hourglass-split"></i> Em Análise</span>
                    <?php else: ?>
                        <span class="badge bg-success fs-6 px-3 py-2 rounded-pill"><i class="bi bi-check-all"></i> Concluído / Respondido</span>
                    <?php endif; ?>
                </div>

                <h6 class="fw-bold text-dark mb-2"><i class="bi bi-chat-left-dots-fill text-primary"></i> Resposta da Instituição:</h6>
                
                <div class="p-3 bg-white rounded-3 border res-box <?php echo $is_concluido ? 'concluido' : ''; ?> shadow-sm">
                    <?php if($resposta_exibir !== ''): ?>
                        <p class="mb-0 text-dark" style="line-height: 1.6;"><?php echo nl2br(htmlspecialchars($resposta_exibir)); ?></p>
                    <?php else: ?>
                        <p class="text-muted fst-italic mb-0 small"><i class="bi bi-info-circle"></i> Sua manifestação está sendo processada. Assim que o setor responsável emitir o parecer, a resposta aparecerá aqui.</p>
                    <?php endif; ?>
                </div>

                <?php if($is_concluido && $relato['modulo'] === 'OUVIDORIA'): ?>
                    <div class="mt-4 p-3 bg-primary bg-opacity-10 rounded-3 border border-primary border-opacity-25 text-center">
                        <p class="small fw-bold text-primary mb-2">Sua opinião é muito importante para nós!</p>
                        <a href="avaliar.php?protocolo=<?php echo $protocolo; ?>" class="btn btn-primary btn-sm fw-bold rounded-pill px-4 shadow-sm">
                            <i class="bi bi-star-fill text-warning"></i> Avaliar Atendimento
                        </a>
                    </div>
                <?php endif; ?>
                
                <?php if($relato['modulo'] === 'LAI' && isset($relato['prazo_resposta'])): ?>
                    <div class="mt-3 text-end">
                        <small class="text-muted">Prazo máximo para resposta: <strong class="text-danger"><?php echo date('d/m/Y', strtotime($relato['prazo_resposta'])); ?></strong></small>
                    </div>
                <?php endif; ?>

            </div>
        <?php endif; ?>
        
        <div class="text-center mt-4">
            <a href="index.php" class="text-decoration-none text-muted small fw-bold"><i class="bi bi-house-door"></i> Ir para Página Inicial</a>
        </div>
    </div>
</body>
</html>