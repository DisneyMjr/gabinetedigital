<?php
// ranking.php (Fica no domínio neutro)
// Placar de Gamificação das Lideranças - Visão Pública para a Equipe

ini_set('display_errors', 0);

// Usa a conexão cega e segura
require 'db.php'; 

$hash_tenant = $_GET['t'] ?? '';

if (empty($hash_tenant)) {
    die("<div style='font-family: sans-serif; text-align: center; padding: 50px;'><h2>Acesso Inválido</h2><p>Link de ranking incompleto.</p></div>");
}

// Descobre qual é o tenant_id correto escondido no Hash e puxa os scripts globais (pixels)
$stmt = $pdo->prepare("SELECT tenant_id, scripts_head FROM configuracoes WHERE MD5(tenant_id) = ?");
$stmt->execute([$hash_tenant]);
$config = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$config) {
    die("<div style='font-family: sans-serif; text-align: center; padding: 50px;'><h2>Ranking não encontrado</h2><p>Não foi possível carregar os dados desta equipe.</p></div>");
}

$tenant_id_correto = $config['tenant_id'];

// Busca as lideranças e a contagem de indicações de cada uma
$sql_ranking = "SELECT l.nome, COUNT(e.id) as total_indicados
                FROM eleitores l
                LEFT JOIN eleitores e ON e.responsavel_id = l.id
                WHERE l.tenant_id = ? AND l.is_lider = 1
                GROUP BY l.id
                ORDER BY total_indicados DESC, l.nome ASC";

$stmt_ranking = $pdo->prepare($sql_ranking);
$stmt_ranking->execute([$tenant_id_correto]);
$ranking = $stmt_ranking->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ranking da Equipe</title>

    <?= $config['scripts_head'] ?? '' ?>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f4f6f9; font-family: 'Segoe UI', sans-serif; padding-bottom: 40px; }
        .hero-banner { background: linear-gradient(135deg, #FFD700 0%, #FDB931 100%); color: #333; padding: 30px 20px 50px; border-bottom-left-radius: 30px; border-bottom-right-radius: 30px; text-align: center; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .ranking-container { margin-top: -30px; max-width: 600px; margin-left: auto; margin-right: auto; padding: 0 15px; }
        .card-lider { border-radius: 15px; border: none; transition: transform 0.2s; margin-bottom: 15px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        .posicao-badge { width: 35px; height: 35px; display: flex; align-items: center; justify-content: center; border-radius: 50%; font-weight: bold; font-size: 1.1rem; }
        .pos-1 { background-color: #FFD700; color: #fff; text-shadow: 1px 1px 2px rgba(0,0,0,0.2); transform: scale(1.1); box-shadow: 0 0 10px rgba(255, 215, 0, 0.5); }
        .pos-2 { background-color: #C0C0C0; color: #fff; text-shadow: 1px 1px 2px rgba(0,0,0,0.2); }
        .pos-3 { background-color: #cd7f32; color: #fff; text-shadow: 1px 1px 2px rgba(0,0,0,0.2); }
        .pos-outros { background-color: #e9ecef; color: #6c757d; }
        .medal-badge { font-size: 0.75rem; padding: 4px 10px; border-radius: 20px; font-weight: bold; }
    </style>
</head>
<body>

    <div class="hero-banner">
        <h2 class="fw-bold mb-1"><i class="fas fa-trophy me-2"></i> Ranking da Equipe</h2>
        <p class="mb-0 fw-bold opacity-75">Acompanhe o desempenho das lideranças</p>
    </div>

    <div class="ranking-container">
        <?php 
        if (empty($ranking)): 
        ?>
            <div class="card card-lider p-5 text-center bg-white">
                <i class="fas fa-users-slash fa-3x text-muted mb-3 opacity-50"></i>
                <h5 class="text-muted fw-bold">Nenhuma liderança ativa no momento.</h5>
            </div>
        <?php 
        else: 
            foreach ($ranking as $posicao => $lider): 
                $total = (int)$lider['total_indicados'];
                $pos_num = $posicao + 1;
                
                // Lógica da Gamificação
                if ($total >= 1000) { $medalha = 'Diamante 💎'; $cor_badge = 'bg-info text-dark'; $next = 1000; $perc = 100; }
                elseif ($total >= 500) { $medalha = 'Platina 💿'; $cor_badge = 'bg-dark text-white'; $next = 1000; $perc = ($total/1000)*100; }
                elseif ($total >= 200) { $medalha = 'Ouro 🥇'; $cor_badge = 'bg-warning text-dark'; $next = 500; $perc = ($total/500)*100; }
                elseif ($total >= 100) { $medalha = 'Prata 🥈'; $cor_badge = 'bg-secondary text-white'; $next = 200; $perc = ($total/200)*100; }
                elseif ($total >= 50) { $medalha = 'Bronze 🥉'; $cor_badge = 'text-white'; $next = 100; $perc = ($total/100)*100; $extra_style = "background-color: #cd7f32;"; }
                else { $medalha = 'Iniciante 🎯'; $cor_badge = 'bg-light text-dark border'; $next = 50; $perc = ($total/50)*100; $extra_style = ""; }

                // Estilo da posição (1º, 2º, 3º ganham destaque)
                $classe_posicao = "pos-outros";
                if ($pos_num == 1) $classe_posicao = "pos-1";
                if ($pos_num == 2) $classe_posicao = "pos-2";
                if ($pos_num == 3) $classe_posicao = "pos-3";
        ?>
            <div class="card card-lider bg-white p-3">
                <div class="d-flex align-items-center">
                    
                    <!-- Posição -->
                    <div class="me-3">
                        <div class="posicao-badge <?= $classe_posicao ?>">
                            <?= $pos_num ?>º
                        </div>
                    </div>

                    <!-- Dados do Líder -->
                    <div class="flex-grow-1">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <h6 class="mb-0 fw-bold text-dark"><?= htmlspecialchars($lider['nome']) ?></h6>
                            <h5 class="mb-0 fw-bold text-success"><?= $total ?></h5>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center mt-2">
                            <span class="medal-badge <?= $cor_badge ?>" style="<?= $extra_style ?? '' ?>"><?= $medalha ?></span>
                            <small class="text-muted" style="font-size: 0.7rem;">Meta: <?= $next ?></small>
                        </div>
                        
                        <!-- Barra de Progresso -->
                        <div class="progress mt-1" style="height: 5px;">
                            <div class="progress-bar <?= $cor_badge ?>" style="width: <?= $perc ?>%; <?= $extra_style ?? '' ?>"></div>
                        </div>
                    </div>

                </div>
            </div>
        <?php 
            endforeach; 
        endif; 
        ?>

        <div class="text-center mt-4">
            <small class="text-muted"><i class="fas fa-lock me-1"></i> Apenas o total de indicações é exibido. Nomes de apoiadores são sigilosos.</small>
        </div>
    </div>

</body>
</html>