<?php
// ver_logs.php - Painel para ver o que o Mercado Pago está mandando
$log_file = 'webhook_log.txt';

// Botão para limpar
if (isset($_POST['limpar'])) {
    file_put_contents($log_file, "Log limpo em " . date('d/m/Y H:i:s') . PHP_EOL);
    header("Location: ver_logs.php");
    exit;
}

$conteudo = file_exists($log_file) ? file_get_contents($log_file) : "Nenhum registro ainda.";
// Inverte para mostrar o mais recente no topo
$linhas = array_reverse(explode(PHP_EOL, $conteudo));
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Espião de Webhook</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <meta http-equiv="refresh" content="10"> </head>
<body class="bg-dark text-white p-4">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3>📡 Radar Mercado Pago</h3>
            <form method="POST">
                <button type="submit" name="limpar" class="btn btn-danger btn-sm">Limpar Logs</button>
                <a href="webhook.php" target="_blank" class="btn btn-secondary btn-sm">Testar Acesso Manual</a>
            </form>
        </div>

        <div class="card bg-secondary text-white">
            <div class="card-body font-monospace" style="max-height: 500px; overflow-y: auto;">
                <?php foreach($linhas as $linha): ?>
                    <?php if(trim($linha)): ?>
                        <div style="border-bottom: 1px solid #555; padding: 5px;">
                            <?php 
                                // Colore as mensagens para facilitar leitura
                                if(strpos($linha, 'SUCESSO')) echo "<span class='text-warning'>$linha</span>";
                                elseif(strpos($linha, 'ERRO')) echo "<span class='text-danger fw-bold'>$linha</span>";
                                elseif(strpos($linha, 'Notificação')) echo "<span class='text-info'>$linha</span>";
                                else echo $linha;
                            ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <p class="mt-2 text-muted small">Esta página atualiza sozinha a cada 10 segundos.</p>
    </div>
</body>
</html>
