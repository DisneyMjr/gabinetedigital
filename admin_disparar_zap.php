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

// Conta Zap Válidos
$stmtE = $pdo->prepare("SELECT COUNT(*) FROM eleitores WHERE tenant_id = ? AND whatsapp IS NOT NULL AND whatsapp != ''");
$stmtE->execute([TENANT_ID]);
$total_zap = $stmtE->fetchColumn();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8"><title>Disparo WhatsApp</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-light d-flex align-items-center justify-content-center" style="height: 100vh;">

    <div class="card shadow-lg border-0 rounded-4 text-center p-5" style="width: 500px;">
        <div class="mb-4 text-success display-4"><i class="fab fa-whatsapp"></i></div>
        <h3 class="fw-bold mb-3">Disparo WhatsApp</h3>
        <p class="text-muted small"><?= htmlspecialchars($campanha['assunto']) ?></p>
        
        <div class="alert alert-warning small text-start">
            <i class="fas fa-exclamation-triangle"></i> <strong>Atenção:</strong> O envio é feito lentamente para evitar bloqueio do chip. Mantenha esta tela aberta.
        </div>

        <h5 class="fw-bold mt-4">Progresso: <span id="contador">0</span> / <?= $total_zap ?></h5>
        
        <div class="progress mb-3" style="height: 25px;">
            <div id="barra" class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width: 0%"></div>
        </div>
        
        <div id="status_msg" class="text-muted small">Conectando API...</div>
        
        <div id="botao_voltar" class="mt-4 d-none">
            <a href="admin_marketing.php" class="btn btn-outline-secondary fw-bold px-5">Voltar</a>
        </div>
    </div>

    <script>
        var total = <?= $total_zap ?>;
        var campanha_id = <?= $id ?>;
        var enviados = 0;
        var lote = 1; // WHATSAPP É 1 POR VEZ PARA SEGURANÇA!

        function enviarLote() {
            if (enviados >= total) {
                document.getElementById('status_msg').innerHTML = "<strong class='text-success'>Envio Concluído!</strong>";
                document.getElementById('barra').classList.remove('progress-bar-animated');
                document.getElementById('botao_voltar').classList.remove('d-none');
                return;
            }

            document.getElementById('status_msg').innerText = "Enviando para o próximo eleitor...";

            fetch('ajax_enviar_whatsapp.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'id=' + campanha_id + '&offset=' + enviados + '&limit=' + lote
            })
            .then(response => response.json())
            .then(data => {
                if(data.erro) {
                    document.getElementById('status_msg').innerHTML = "<span class='text-danger'>Erro na API: " + data.msg + "</span>";
                    // Não para, tenta o próximo após 5s
                    setTimeout(() => { enviados++; enviarLote(); }, 5000); 
                    return;
                }

                if(data.enviados_agora == 0 && data.msg == 'Fim da lista') {
                    enviados = total; // Força fim
                } else {
                    enviados += data.enviados_agora;
                }
                
                document.getElementById('contador').innerText = Math.min(enviados, total);
                let porcentagem = (enviados / total) * 100;
                document.getElementById('barra').style.width = porcentagem + "%";

                // Intervalo de segurança do Frontend também (3 a 10 segundos)
                let tempo_espera = Math.floor(Math.random() * 5000) + 3000;
                setTimeout(enviarLote, tempo_espera);
            })
            .catch(error => {
                console.error('Erro:', error);
                document.getElementById('status_msg').innerHTML = "<span class='text-danger'>Erro de rede. Tentando em 5s...</span>";
                setTimeout(enviarLote, 5000);
            });
        }

        if(total > 0) {
            enviarLote();
        } else {
            document.getElementById('status_msg').innerText = "Nenhum WhatsApp cadastrado.";
            document.getElementById('botao_voltar').classList.remove('d-none');
        }
    </script>
</body>
</html>