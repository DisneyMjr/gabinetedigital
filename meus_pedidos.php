<?php
require 'config.php';

// Se não tiver tenant definido, redireciona
if (!defined('TENANT_ID')) { header("Location: index.php"); exit; }

$historico = [];
$buscou = false;
$identificacao = "";
$debug_msg = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['identificacao'])) {
    $buscou = true;
    $identificacao = trim($_POST['identificacao']);
    
    // LIMPEZA E PREPARAÇÃO INTELIGENTE
    $termo_limpo = preg_replace('/[^0-9]/', '', $identificacao); // Só números
    $termo_original = $identificacao; 

    try {
        $params = [':tenant' => TENANT_ID];
        $condicoes = [];

        // 1. Busca por Email ou Protocolo
        $condicoes[] = "email LIKE :email";
        $params[':email'] = "%$termo_original%";

        $condicoes[] = "protocolo LIKE :proto";
        $params[':proto'] = "%$termo_original%";

        // 2. BUSCA DE TELEFONE INTELIGENTE (A MÁGICA ESTÁ AQUI)
        if (strlen($termo_limpo) >= 8) {
            // Pega apenas os últimos 8 dígitos (ignora DDD e 9º dígito)
            $ultimos_8 = substr($termo_limpo, -8);
            
            $condicoes[] = "whatsapp LIKE :zap_curto";
            $params[':zap_curto'] = "%$ultimos_8"; // Busca qualquer coisa terminada nesses 8 numeros
        }

        // Monta a SQL
        $sql_where = implode(' OR ', $condicoes);
        $sql = "SELECT * FROM solicitacoes 
                WHERE tenant_id = :tenant 
                AND ($sql_where) 
                ORDER BY data_abertura DESC"; 

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $historico = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // SE NÃO ACHAR NADA, MOSTRA O MOTIVO TÉCNICO
        if (count($historico) == 0) {
            $debug_msg = "
            <b>Diagnóstico de Falha:</b><br>
            Tenant ID: " . TENANT_ID . "<br>
            Tentou buscar WhatsApp terminando em: <b>" . ($ultimos_8 ?? 'Não detectado') . "</b><br>
            Tentou E-mail/Protocolo: <b>$termo_original</b>
            ";
        }

    } catch (Exception $e) {
        $debug_msg = "Erro SQL: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meus Pedidos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
    <style>
        body { background-color: #f8fafc; font-family: 'Segoe UI', sans-serif; }
        .hero-mini { background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%); color: white; padding: 50px 0; margin-bottom: 30px; }
        .timeline { border-left: 3px solid #e9ecef; margin-left: 20px; padding-left: 30px; position: relative; }
        .timeline-item { position: relative; margin-bottom: 40px; }
        .timeline-dot { width: 16px; height: 16px; background: #fff; border: 3px solid #0d6efd; border-radius: 50%; position: absolute; left: -39px; top: 5px; }
        .card-pedido { border: none; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); transition: 0.3s; background: white; }
        .card-pedido:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,0,0,0.1); }
        .status-badge { font-size: 0.75rem; font-weight: bold; text-transform: uppercase; padding: 5px 12px; border-radius: 20px; }
    </style>
</head>
<body>

    <div class="hero-mini text-center shadow-sm">
        <div class="container">
            <h3 class="fw-bold mb-0"><i class="fas fa-search-location me-2"></i> Rastrear Solicitação</h3>
        </div>
    </div>

    <div class="container" style="max-width: 700px;">
        
        <div class="card border-0 shadow p-4 mb-5" style="margin-top: -60px; border-radius: 15px;">
            <form method="POST">
                <label class="fw-bold text-muted small mb-2 text-uppercase">Digite seu WhatsApp, E-mail ou Protocolo</label>
                <div class="input-group input-group-lg">
                    <input type="text" name="identificacao" class="form-control bg-light" placeholder="Ex: 37999998888" value="<?= htmlspecialchars($identificacao ?? '') ?>" required>
                    <button class="btn btn-primary fw-bold px-4" type="submit">BUSCAR</button>
                </div>
            </form>
        </div>

        <?php if (!empty($debug_msg)): ?>
            <div class="alert alert-danger shadow-sm mb-4">
                <i class="fas fa-bug me-2"></i> <?= $debug_msg ?>
            </div>
        <?php endif; ?>

        <?php if ($buscou): ?>
            <?php if (count($historico) > 0): ?>
                
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <h5 class="fw-bold text-secondary mb-0">Histórico</h5>
                    <span class="badge bg-primary rounded-pill"><?= count($historico) ?> encontrado(s)</span>
                </div>
                
                <div class="timeline">
                    <?php foreach($historico as $h): 
                        $status = trim($h['status'] ?? 'Pendente');
                        $classeBadge = 'bg-secondary';
                        if (stripos($status, 'Pendente') !== false) $classeBadge = 'bg-warning text-dark';
                        if (stripos($status, 'Analise') !== false || stripos($status, 'Análise') !== false) $classeBadge = 'bg-info text-dark';
                        if (stripos($status, 'Concluido') !== false || stripos($status, 'Concluído') !== false) $classeBadge = 'bg-success';
                        if (stripos($status, 'Recusado') !== false) $classeBadge = 'bg-danger';
                        
                        $dataExibicao = !empty($h['data_abertura']) ? date('d/m/Y \à\s H:i', strtotime($h['data_abertura'])) : 'Data não informada';
                    ?>
                    <div class="timeline-item animate__animated animate__fadeInUp">
                        <div class="timeline-dot"></div>
                        <div class="card card-pedido">
                            <div class="card-body p-4">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div>
                                        <div class="small text-muted mb-1">PROTOCOLO: <strong class="text-dark"><?= $h['protocolo'] ?? $h['id'] ?></strong></div>
                                        <h5 class="fw-bold text-primary mb-0"><?= htmlspecialchars($h['tipo'] ?? 'Solicitação') ?></h5>
                                    </div>
                                    <span class="status-badge <?= $classeBadge ?>"><?= $status ?></span>
                                </div>
                                
                                <p class="text-secondary mb-3"><?= nl2br(htmlspecialchars($h['descricao'] ?? '')) ?></p>
                                
                                <div class="d-flex justify-content-between align-items-center border-top pt-3">
                                    <small class="text-muted fw-bold"><i class="far fa-calendar-alt me-1"></i> <?= $dataExibicao ?></small>
                                </div>
                                <?php if(!empty($h['resposta_admin'])): ?>
                                    <div class="alert alert-success mt-3 mb-0 border-0 bg-success bg-opacity-10">
                                        <strong class="text-success d-block mb-1">Resposta do Gabinete:</strong>
                                        <span class="text-dark"><?= nl2br(htmlspecialchars($h['resposta_admin'] ?? '')) ?></span>
                                        <?php if(!empty($h['data_resposta'])): ?>
                                            <div class="mt-1 small text-muted"><?= date('d/m/Y', strtotime($h['data_resposta'])) ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>
                <div class="text-center py-5">
                    <h5 class="text-muted fw-bold">Nenhum pedido encontrado.</h5>
                    <p class="text-muted small">Tente usar o número do Protocolo ou E-mail.</p>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="text-center mt-5 mb-5">
            <a href="index.php" class="btn btn-link text-decoration-none text-muted fw-bold"><i class="fas fa-arrow-left me-2"></i> Voltar</a>
        </div>

    </div>
</body>
</html>