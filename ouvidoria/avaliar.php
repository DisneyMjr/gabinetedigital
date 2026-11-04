<?php
// avaliar.php - PESQUISA DE SATISFAÇÃO DO CIDADÃO
require 'conexao.php';

$protocolo = $_GET['protocolo'] ?? '';
$mensagem = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $report_id = (int)$_POST['report_id'];
    $tenant_id = (int)$_POST['tenant_id'];
    $nota = (int)$_POST['nota'];
    $comentario = trim($_POST['comentario']);

    $sql = "INSERT INTO feedbacks (tenant_id, report_id, nota, comentario) VALUES (?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    if ($stmt->execute([$tenant_id, $report_id, $nota, $comentario])) {
        $mensagem = "<div class='alert alert-success fw-bold'>Obrigado! Sua avaliação ajuda a melhorar nossos serviços públicos.</div>";
    }
}

// Busca o relato para garantir que existe e está concluído
$stmt = $pdo->prepare("SELECT id, tenant_id FROM reports WHERE protocolo_visualizacao = ? AND status = 'concluido'");
$stmt->execute([$protocolo]);
$relato = $stmt->fetch();
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Avaliar Atendimento | Ouvvi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f0f2f5; }
        .star-rating { font-size: 2rem; color: #ffc107; cursor: pointer; }
        .card-eval { border-radius: 15px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-6 text-center">
                <h2 class="fw-bold mb-4">Avaliação de Atendimento</h2>
                <?php if ($mensagem): echo $mensagem; else: ?>
                    <?php if ($relato): ?>
                        <div class="card card-eval p-4 bg-white text-start">
                            <form method="POST">
                                <input type="hidden" name="report_id" value="<?php echo $relato['id']; ?>">
                                <input type="hidden" name="tenant_id" value="<?php echo $relato['tenant_id']; ?>">
                                
                                <label class="fw-bold mb-2">Como você avalia a resposta recebida?</label>
                                <div class="mb-3 text-center">
                                    <select name="nota" class="form-select fw-bold text-center" required>
                                        <option value="5">⭐⭐⭐⭐⭐ Excelente</option>
                                        <option value="4">⭐⭐⭐⭐ Bom</option>
                                        <option value="3">⭐⭐⭐ Regular</option>
                                        <option value="2">⭐⭐ Insatisfatório</option>
                                        <option value="1">⭐ Muito Ruim</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="fw-bold mb-2">Comentário adicional (opcional):</label>
                                    <textarea name="comentario" class="form-control" rows="3"></textarea>
                                </div>

                                <button type="submit" class="btn btn-primary w-100 fw-bold py-2">Enviar Avaliação</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning">Protocolo não encontrado ou ainda em análise.</div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>