<?php
require 'config.php';
require 'protecao.php';

if (!tem_acesso('marketing')) { header("Location: admin_painel.php"); exit; }

$id = $_GET['id'] ?? 0;

// Busca Campanha
$stmt = $pdo->prepare("SELECT * FROM campanhas WHERE id = ? AND tenant_id = ?");
$stmt->execute([$id, TENANT_ID]);
$campanha = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$campanha) die("Campanha não encontrada.");

// Conta quantos eleitores TÊM email
$stmtE = $pdo->prepare("SELECT COUNT(*) FROM eleitores WHERE tenant_id = ? AND email IS NOT NULL AND email != ''");
$stmtE->execute([TENANT_ID]);
$total_emails = $stmtE->fetchColumn();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8"><title>Disparando E-mails</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-light d-flex align-items-center justify-content-center" style="height: 100vh;">

    <div class="card shadow-lg border-0 rounded-4 text-center p-5" style="width: 500px;">
        <div class="mb-4 text-primary display-4"><i class="fas fa-rocket"></i></div>
        <h3 class="fw-bold mb-3">Enviando Campanha</h3>
        <p class="text-muted"><?= htmlspecialchars($campanha['assunto']) ?></p>
        
        <h5 class="fw-bold mt-4">Progresso: <span id="contador">0</span> / <?= $total_emails ?></h5>
        
        <div class="progress mb-3" style="height: 25px;">
            <div id="barra" class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width: 0%"></div>
        </div>
        
        <div id="status_msg" class="text-muted small">Preparando envio...</div>
        
        <div id="botao_voltar" class="mt-4 d-none">
            <a href="admin_marketing.php" class="btn btn-primary fw-bold px-5">Voltar para Campanhas</a>
        </div>
    </div>

    <script>
        var total = <?= $total_emails ?>;
        var campanha_id = <?= $id ?>;
        var enviados = 0;
        var lote = 5; // Envia 5 emails por vez (seguro para hospedagem compartilhada)

        function enviarLote() {
            if (enviados >= total) {
                document.getElementById('status_msg').innerHTML = "<strong class='text-success'>Envio Concluído com Sucesso!</strong>";
                document.getElementById('barra').classList.remove('progress-bar-animated');
                document.getElementById('botao_voltar').classList.remove('d-none');
                return;
            }

            document.getElementById('status_msg').innerText = "Enviando lote... Aguarde.";

            // Chama o PHP via AJAX
            fetch('ajax_enviar_email.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'id=' + campanha_id + '&offset=' + enviados + '&limit=' + lote
            })
            .then(response => response.json())
            .then(data => {
                if(data.erro) {
                    document.getElementById('status_msg').innerHTML = "<span class='text-danger'>Erro: " + data.msg + "</span>";
                    return;
                }

                enviados += data.enviados_agora;
                
                // Atualiza tela
                document.getElementById('contador').innerText = Math.min(enviados, total);
                let porcentagem = (enviados / total) * 100;
                document.getElementById('barra').style.width = porcentagem + "%";

                // Chama o próximo lote
                enviarLote();
            })
            .catch(error => {
                console.error('Erro:', error);
                document.getElementById('status_msg').innerHTML = "<span class='text-danger'>Erro de conexão. Tentando novamente em 3s...</span>";
                setTimeout(enviarLote, 3000);
            });
        }

        // Inicia o envio
        if(total > 0) {
            enviarLote();
        } else {
            document.getElementById('status_msg').innerText = "Nenhum eleitor com e-mail cadastrado.";
            document.getElementById('botao_voltar').classList.remove('d-none');
        }
    </script>
</body>
</html>