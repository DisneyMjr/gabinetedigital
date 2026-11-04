<?php
require_once 'config.php';
// O config.php chama o saas.php, que define a constante TENANT_ID
require_once 'protecao.php';

// --- 0. SEGURANÇA SAAS ---
if (!defined('TENANT_ID')) {
    die("Erro Crítico: Tenant não identificado.");
}

// Permissão
if (!tem_acesso('admin') && !tem_acesso('eleitores')) { die("Acesso Negado."); }

// --- AJAX: MARCAR COMO ENVIADO E SUMIR DA LISTA ---
if (isset($_GET['acao']) && $_GET['acao'] == 'marcar_enviado' && isset($_GET['id'])) {
    $ano_atual = date('Y');
    $id_eleitor = (int)$_GET['id'];
    // Grava no banco que este eleitor já recebeu a mensagem neste ano
    $pdo->prepare("UPDATE eleitores SET ano_niver_enviado = ? WHERE id = ? AND tenant_id = ?")
        ->execute([$ano_atual, $id_eleitor, TENANT_ID]);
    exit; // Encerra a execução pois é uma requisição invisível do JavaScript
}

// --- 1. CONFIGURAÇÕES DO CLIENTE (Nome do Vereador) ---
try {
    $stmt_conf = $pdo->prepare("SELECT nome_vereador FROM configuracoes WHERE tenant_id = ?");
    $stmt_conf->execute([TENANT_ID]);
    $conf = $stmt_conf->fetch(PDO::FETCH_ASSOC);
    $nome_politico = $conf['nome_vereador'] ?? 'Seu Vereador';
} catch (Exception $e) {
    $nome_politico = 'Seu Vereador';
}

// Data de Hoje
$hoje_dia = date('d');
$hoje_mes = date('m');
$ano_atual = date('Y');
$meses_ptbr = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
$nome_mes = $meses_ptbr[(int)$hoje_mes];

// --- 2. BUSCA TODOS OS ANIVERSARIANTES DO MÊS (QUE AINDA NÃO RECEBERAM MSG ESTE ANO) ---
$sql = "SELECT * FROM eleitores 
        WHERE tenant_id = ? 
        AND MONTH(nascimento) = ? 
        AND (ano_niver_enviado IS NULL OR ano_niver_enviado != ?)
        ORDER BY DAY(nascimento) ASC, nome ASC";
        
$stmt = $pdo->prepare($sql);
$stmt->execute([TENANT_ID, $hoje_mes, $ano_atual]);
$lista_mes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- 3. SEPARA HOJE DO RESTANTE DO MÊS ---
$aniversariantes_hoje = [];
$aniversariantes_outros = [];

foreach($lista_mes as $row) {
    $dia_niver = date('d', strtotime($row['nascimento']));
    if ($dia_niver == $hoje_dia) {
        $aniversariantes_hoje[] = $row;
    } else {
        $aniversariantes_outros[] = $row;
    }
}

$total_hoje = count($aniversariantes_hoje);
$total_outros = count($aniversariantes_outros);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Aniversariantes | Gabinete Digital</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f4f6f9; }
        .data-badge {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            color: #495057;
            font-weight: bold;
            padding: 10px;
            border-radius: 10px;
            text-align: center;
            min-width: 65px;
        }
        .data-badge span { display: block; font-size: 1.2rem; line-height: 1; color: #0d6efd; }
        .data-badge small { font-size: 0.7rem; text-transform: uppercase; }
        .btn-whats { background-color: #25D366; color: white; border: none; }
        .btn-whats:hover { background-color: #128C7E; color: white; }
        
        /* Transição suave para quando o card sumir da tela */
        .card-eleitor { transition: all 0.4s ease; }
    </style>
</head>
<body class="p-4">

<div class="container bg-white p-4 rounded shadow-sm border-0" style="max-width: 800px;">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="text-primary fw-bold mb-0"><i class="fas fa-birthday-cake text-warning"></i> Aniversariantes</h3>
            <small class="text-muted">Gestão de relacionamento de <b><?= $nome_mes ?></b></small>
        </div>
        <a href="admin_painel.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
    </div>

    <h5 class="fw-bold text-success border-bottom pb-2 mb-3"><i class="fas fa-star text-warning"></i> Hoje (<?= date('d/m') ?>)</h5>
    
    <?php if($total_hoje > 0): ?>
        <div class="alert alert-success fw-bold text-center border-0 shadow-sm" id="alerta-hoje">
            🎉 Temos <span id="contador-hoje"><?= $total_hoje ?></span> aniversariante(s) hoje! Não esqueça de enviar os parabéns.
        </div>

        <div class="list-group mb-5 shadow-sm" id="lista-hoje">
            <?php foreach($aniversariantes_hoje as $row): 
                $primeiro_nome = explode(' ', trim($row['nome']))[0];
                
                // MENSAGEM 1: TEXTO PADRÃO
                $msg_texto = "Olá, *$primeiro_nome*! 🎉\n\n";
                $msg_texto .= "Passando aqui para desejar um *Feliz Aniversário*! 🎂\n";
                $msg_texto .= "Muita saúde, paz e realizações no seu novo ano.\n\n";
                $msg_texto .= "Forte abraço do seu amigo,\n*$nome_politico*.";

                // MENSAGEM 2: COM ARTE DO DESIGNER EXPRESS
                $msg_arte = "Olá, *$primeiro_nome*! Fiz este cartão com muito carinho para você. Feliz Aniversário! 🎉🎂";

                $zap_limpo = preg_replace('/[^0-9]/', '', $row['whatsapp']);
                if(strlen($zap_limpo) <= 11 && !empty($zap_limpo)) $zap_limpo = "55".$zap_limpo;
                
                // Geração dos Links
                $link_texto = "https://wa.me/{$zap_limpo}?text=" . urlencode($msg_texto);
                $link_arte = "https://wa.me/{$zap_limpo}?text=" . urlencode($msg_arte);
            ?>
            <div class="list-group-item d-flex justify-content-between align-items-center p-3 border-success border-start border-4 card-eleitor" id="card-eleitor-<?= $row['id'] ?>">
                <div class="d-flex align-items-center">
                    <div class="bg-warning text-white rounded-circle d-flex justify-content-center align-items-center me-3 shadow-sm" style="width: 50px; height: 50px; font-size: 1.5rem;">
                        🎂
                    </div>
                    <div>
                        <h5 class="mb-0 fw-bold"><?= htmlspecialchars($row['nome']) ?></h5>
                        <small class="text-muted"><i class="fab fa-whatsapp text-success"></i> <?= htmlspecialchars($row['whatsapp']) ?> | <?= htmlspecialchars($row['bairro']) ?></small>
                    </div>
                </div>
                
                <?php if(!empty($row['whatsapp'])): ?>
                    <div class="dropdown">
                        <button class="btn btn-whats fw-bold shadow-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fab fa-whatsapp me-1"></i> Enviar
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                            <li>
                                <a class="dropdown-item fw-bold text-dark" href="#" onclick="registrarEnvio(<?= $row['id'] ?>, '<?= $link_texto ?>')">
                                    <i class="fas fa-comment-dots text-primary me-2"></i> Mensagem de Texto
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item fw-bold text-dark" href="#" onclick="copiarEEnviar('<?= $link_arte ?>', <?= $row['id'] ?>)">
                                    <i class="fas fa-image text-danger me-2"></i> Enviar com Arte (Designer)
                                </a>
                            </li>
                        </ul>
                    </div>
                <?php else: ?>
                    <span class="badge bg-secondary">Sem Número</span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="text-center py-4 mb-5 bg-light rounded text-muted">
            <i class="far fa-calendar-times fa-3x mb-2 text-secondary opacity-50"></i>
            <p class="mb-0 fw-bold">Ninguém pendente para hoje.</p>
        </div>
    <?php endif; ?>

    <h5 class="fw-bold text-secondary border-bottom pb-2 mb-3"><i class="fas fa-calendar-alt"></i> Outros dias de <?= $nome_mes ?> pendentes</h5>

    <?php if($total_outros > 0): ?>
        <div class="list-group shadow-sm">
            <?php foreach($aniversariantes_outros as $row): 
                $dia_niver = date('d', strtotime($row['nascimento']));
                $ja_passou = ($dia_niver < $hoje_dia);
                $opacidade = $ja_passou ? 'opacity-50' : ''; 
            ?>
            <div class="list-group-item d-flex justify-content-between align-items-center p-3 <?= $opacidade ?>">
                <div class="d-flex align-items-center">
                    <div class="data-badge me-3 <?= $ja_passou ? 'bg-light' : 'bg-white shadow-sm' ?>">
                        <span><?= $dia_niver ?></span>
                        <small><?= substr($nome_mes, 0, 3) ?></small>
                    </div>
                    
                    <div>
                        <h6 class="mb-0 fw-bold text-dark"><?= htmlspecialchars($row['nome']) ?></h6>
                        <small class="text-muted"><i class="fas fa-phone-alt"></i> <?= htmlspecialchars($row['whatsapp']) ?></small>
                    </div>
                </div>

                <div>
                    <?php if($ja_passou): ?>
                        <span class="badge bg-secondary text-white rounded-pill shadow-sm">Esqueceu de enviar</span>
                    <?php else: ?>
                        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary rounded-pill shadow-sm">Falta <?= ($dia_niver - $hoje_dia) ?> dia(s)</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="text-center py-4 bg-light rounded text-muted">
            <p class="mb-0">Não há outros aniversariantes pendentes neste mês.</p>
        </div>
    <?php endif; ?>

    <?php if($total_hoje == 0 && $total_outros == 0): ?>
        <div class="text-center mt-4">
            <a href="admin_eleitores.php" class="btn btn-outline-primary fw-bold shadow-sm"><i class="fas fa-user-plus me-2"></i> Cadastrar mais eleitores</a>
        </div>
    <?php endif; ?>

</div>

<div class="modal fade" id="modalArte" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-danger text-white border-0">
                <h5 class="modal-title fw-bold"><i class="fas fa-info-circle me-2"></i> Como enviar a Arte</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <p>O WhatsApp não permite que sites anexem imagens automaticamente por questões de segurança. Para enviar o cartão de aniversário:</p>
                <ol class="mb-4 text-muted fw-bold">
                    <li class="mb-2">Gere a imagem no <b>Designer Express</b> e baixe para o seu celular/computador.</li>
                    <li class="mb-2">Clique em <b>Continuar para o WhatsApp</b> (abaixo).</li>
                    <li class="mb-2">O texto já estará pronto. Basta clicar no ícone de "Clipe/Anexo" 📎 no WhatsApp, selecionar a foto e enviar!</li>
                </ol>
                <div class="d-grid">
                    <a id="btnContinuarZap" href="#" class="btn btn-success fw-bold py-2 shadow-sm">
                        <i class="fab fa-whatsapp me-2"></i> Continuar para o WhatsApp
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Função acionada ao clicar em Enviar (Mensagem de Texto)
    function registrarEnvio(idEleitor, linkZap) {
        // 1. Abre a tela do WhatsApp em nova aba
        window.open(linkZap, '_blank');
        
        // 2. Avisa o PHP escondido para atualizar o banco
        fetch('?acao=marcar_enviado&id=' + idEleitor)
            .then(response => {
                // 3. Some com o aniversariante da tela
                esconderCard(idEleitor);
            });
    }

    // Função acionada ao clicar em Enviar (Com Arte)
    function copiarEEnviar(linkZap, idEleitor) {
        // Prepara o botão do modal para gravar no banco quando for clicado
        let btn = document.getElementById('btnContinuarZap');
        btn.onclick = function(e) {
            e.preventDefault(); // Impede o link padrão
            window.open(linkZap, '_blank');
            
            fetch('?acao=marcar_enviado&id=' + idEleitor)
                .then(() => {
                    fecharModal();
                    esconderCard(idEleitor);
                });
        };
        
        var modal = new bootstrap.Modal(document.getElementById('modalArte'));
        modal.show();
    }
    
    // Função visual para sumir com o card
    function esconderCard(idEleitor) {
        let card = document.getElementById('card-eleitor-' + idEleitor);
        if(card) {
            // Efeito de sumir devagar
            card.style.opacity = '0';
            card.style.transform = 'translateX(50px)';
            
            setTimeout(() => {
                card.remove();
                
                // Atualiza o contador de aniversariantes da tela
                let contador = document.getElementById('contador-hoje');
                if(contador) {
                    let atual = parseInt(contador.innerText) - 1;
                    if(atual > 0) {
                        contador.innerText = atual;
                    } else {
                        // Se zerar, troca a mensagem
                        document.getElementById('alerta-hoje').style.display = 'none';
                        document.getElementById('lista-hoje').innerHTML = '<div class="text-center py-4 bg-light rounded text-success fw-bold"><i class="fas fa-check-circle fa-2x mb-2"></i><p class="mb-0">Todos os aniversariantes de hoje já foram contatados!</p></div>';
                    }
                }
            }, 400); // 400ms do tempo da animação
        }
    }

    function fecharModal() {
        var myModalEl = document.getElementById('modalArte');
        var modal = bootstrap.Modal.getInstance(myModalEl);
        if(modal) modal.hide();
    }
</script>

</body>
</html>