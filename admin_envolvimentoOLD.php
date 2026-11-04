<?php
session_start();
require 'config.php';
require 'protecao.php';

if (!defined('TENANT_ID') || !tem_acesso('campanha')) {
    header("Location: admin_painel.php"); exit;
}

// --- AÇÕES (MARCAR/DESMARCAR) ---
if (isset($_GET['acao']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    // Toggle Adesivo
    if ($_GET['acao'] == 'toggle_adesivo') {
        $pdo->prepare("UPDATE eleitores SET adesivado = NOT adesivado WHERE id = ? AND tenant_id = ?")->execute([$id, TENANT_ID]);
    }
    // Toggle Voto Certo
    if ($_GET['acao'] == 'toggle_voto') {
        $pdo->prepare("UPDATE eleitores SET voto_certo = NOT voto_certo WHERE id = ? AND tenant_id = ?")->execute([$id, TENANT_ID]);
    }
    // Salvar Doação
    if ($_GET['acao'] == 'salvar_doacao' && isset($_POST['valor'])) {
        $valor = str_replace(',', '.', preg_replace('/[^0-9,]/', '', $_POST['valor']));
        $pdo->prepare("UPDATE eleitores SET doacao_valor = ? WHERE id = ? AND tenant_id = ?")->execute([$valor, $id, TENANT_ID]);
    }
    
    // Redireciona para limpar URL
    header("Location: admin_envolvimento.php"); exit;
}

// --- 1. DADOS DA CAMPANHA (ELEIÇÃO E FOTO) ---
// Busca data da eleição
$stmtCampanha = $pdo->prepare("SELECT data_eleicao FROM campanha_config WHERE tenant_id = ?");
$stmtCampanha->execute([TENANT_ID]);
$campanha = $stmtCampanha->fetch(PDO::FETCH_ASSOC);

$data_eleicao = $campanha['data_eleicao'] ?? date('Y-10-01', strtotime('+2 years')); // Data padrão se vazio

// Cálculo dias
$hoje = new DateTime();
$eleicao = new DateTime($data_eleicao);
$intervalo = $hoje->diff($eleicao);
$dias_restantes = ($eleicao > $hoje) ? $intervalo->days : 0;
$ano_eleicao = $eleicao->format('Y');

// Busca foto do candidato
$stmtConfig = $pdo->prepare("SELECT foto_hero FROM configuracoes WHERE tenant_id = ?");
$stmtConfig->execute([TENANT_ID]);
$configSite = $stmtConfig->fetch(PDO::FETCH_ASSOC);
$foto_candidato = !empty($configSite['foto_hero']) ? $configSite['foto_hero'] : 'https://ui-avatars.com/api/?name=Candidato&background=0D6EFD&color=fff&size=200';


// --- ESTATÍSTICAS (CARDS DO TOPO) ---
$stats = $pdo->prepare("
    SELECT 
        COUNT(*) as total_base,
        SUM(adesivado) as total_adesivos,
        SUM(voto_certo) as total_votos,
        SUM(doacao_valor) as total_financeiro
    FROM eleitores 
    WHERE tenant_id = ?
");
$stats->execute([TENANT_ID]);
$res = $stats->fetch(PDO::FETCH_ASSOC);

$total_adesivos = $res['total_adesivos'] ?? 0;
$total_votos = $res['total_votos'] ?? 0;
$total_base = $res['total_base'] ?? 0;
$total_financeiro = $res['total_financeiro'] ?? 0;

// --- LISTAGEM DE ELEITORES ---
$busca = $_GET['busca'] ?? '';
$sql = "SELECT * FROM eleitores WHERE tenant_id = ?";
$params = [TENANT_ID];

if (!empty($busca)) {
    $sql .= " AND (nome LIKE ? OR whatsapp LIKE ?)";
    $params[] = "%$busca%";
    $params[] = "%$busca%";
}

$sql .= " ORDER BY nome ASC LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$eleitores = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8"><title>Gestão de Campanha</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f8f9fc; font-family: 'Inter', sans-serif; }
        .card-stat { border: none; border-radius: 15px; transition: transform 0.2s; }
        .card-stat:hover { transform: translateY(-5px); }
        .icon-box { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; }
        .table-hover tbody tr:hover { background-color: #f1f3f9; }
        .btn-action { width: 35px; height: 35px; padding: 0; line-height: 35px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; transition: 0.2s; }
        .btn-action.active { transform: scale(1.1); box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        
        .bg-adesivo-on { background-color: #0dcaf0; color: white; border: none; }
        .bg-adesivo-off { background-color: #e9ecef; color: #ccc; border: 1px solid #dee2e6; }
        .bg-voto-on { background-color: #198754; color: white; border: none; }
        .bg-voto-off { background-color: #e9ecef; color: #ccc; border: 1px solid #dee2e6; }

        /* HERO SECTION ESTILO ELEIÇÃO */
        .hero-section { padding: 20px 0; }
        .hero-highlight { 
            background-color: #0d6efd; 
            color: white; 
            padding: 5px 20px; 
            border-radius: 50px; 
            font-weight: 700;
            display: inline-block;
            font-size: 1.2rem;
            margin-bottom: 10px;
        }
        .hero-title { font-size: 1.8rem; font-weight: 500; color: #555; line-height: 1.2; }
        .countdown-number { 
            font-size: 4.5rem; 
            font-weight: 900; 
            color: #0d6efd; 
            line-height: 1; 
            letter-spacing: -2px;
        }
        .countdown-label { 
            font-size: 2rem; 
            font-weight: 800; 
            color: #212529; 
            text-transform: uppercase; 
        }
        .profile-container {
            display: flex;
            justify-content: flex-end; /* Alinha à direita em desktop */
            align-items: center;
        }
        .profile-img { 
            width: 160px; 
            height: 160px; 
            object-fit: cover; /* Impede distorção/cortar errado */
            border-radius: 50%; 
            border: 6px solid #fff; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.15); 
        }

        @media (max-width: 768px) {
            .profile-container { justify-content: center; margin-top: 20px; }
            .hero-title { text-align: center; }
            .countdown-wrapper { justify-content: center; }
        }
    </style>
</head>
<body class="p-3 p-md-4">

<div class="container-fluid" style="max-width: 1200px;">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold text-dark mb-0">Envolvimento</h4>
        </div>
        <a href="admin_painel.php" class="btn btn-outline-secondary rounded-pill px-4"><i class="fas fa-arrow-left"></i> Voltar</a>
    </div>

    <div class="row align-items-center mb-5 hero-section">
        <div class="col-md-8">
            <div class="hero-title">
                As <span class="hero-highlight">eleições <?= $ano_eleicao ?></span><br>
                começam em
            </div>
            <div class="d-flex align-items-baseline gap-3 mt-1 countdown-wrapper">
                <span class="countdown-number"><?= $dias_restantes ?></span>
                <span class="countdown-label">DIAS</span>
            </div>
        </div>

        <div class="col-md-4 profile-container">
            <img src="<?= htmlspecialchars($foto_candidato) ?>" class="profile-img" alt="Foto do Candidato">
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card card-stat bg-white h-100 shadow-sm p-3">
                <div class="d-flex align-items-center">
                    <div class="icon-box bg-info bg-opacity-10 text-info me-3"><i class="fas fa-sticky-note"></i></div>
                    <div>
                        <small class="text-muted fw-bold text-uppercase">Gestão</small>
                        <h5 class="fw-bold mb-0">Adesivos: <?= $total_adesivos ?></h5>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card card-stat bg-white h-100 shadow-sm p-3">
                <div class="d-flex align-items-center">
                    <div class="icon-box bg-warning bg-opacity-10 text-warning me-3"><i class="fas fa-share-alt"></i></div>
                    <div>
                        <small class="text-muted fw-bold text-uppercase">Gestão</small>
                        <h5 class="fw-bold mb-0">Envolvidos: <?= $total_base ?></h5>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card card-stat bg-white h-100 shadow-sm p-3">
                <div class="d-flex align-items-center">
                    <div class="icon-box bg-purple-light text-purple me-3" style="background:#f3e5f5; color:#9c27b0;"><i class="fas fa-check-circle"></i></div>
                    <div>
                        <small class="text-muted fw-bold text-uppercase">Sucesso</small>
                        <h5 class="fw-bold mb-0"><?= $total_votos ?> Votos Certos</h5>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card card-stat bg-white h-100 shadow-sm p-3">
                <div class="d-flex align-items-center">
                    <div class="icon-box bg-success bg-opacity-10 text-success me-3"><i class="fas fa-money-bill-wave"></i></div>
                    <div>
                        <small class="text-muted fw-bold text-uppercase">Financeiro</small>
                        <h5 class="fw-bold mb-0">R$ <?= number_format($total_financeiro, 2, ',', '.') ?></h5>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-3">
            <h6 class="fw-bold m-0"><i class="fas fa-list me-2"></i> Base de Eleitores</h6>
            
            <form class="d-flex gap-2" style="max-width: 400px; width: 100%;">
                <input type="text" name="busca" class="form-control form-control-sm rounded-pill" placeholder="Buscar por nome ou whats..." value="<?= htmlspecialchars($busca) ?>">
                <button class="btn btn-primary btn-sm rounded-pill px-3"><i class="fas fa-search"></i></button>
            </form>
        </div>
        
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light small text-uppercase text-secondary">
                    <tr>
                        <th class="ps-4">Eleitor</th>
                        <th>WhatsApp</th>
                        <th class="text-center">Adesivo</th>
                        <th class="text-center">Voto Certo</th>
                        <th class="text-end pe-4">Doação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($eleitores) > 0): ?>
                        <?php foreach($eleitores as $e): 
                            $cls_adesivo = $e['adesivado'] ? 'bg-adesivo-on active' : 'bg-adesivo-off';
                            $cls_voto = $e['voto_certo'] ? 'bg-voto-on active' : 'bg-voto-off';
                            $valor_doacao = $e['doacao_valor'] > 0 ? 'R$ '.number_format($e['doacao_valor'], 2, ',', '.') : '+ R$';
                            $cls_doacao = $e['doacao_valor'] > 0 ? 'btn-success btn-sm' : 'btn-outline-secondary btn-sm border-0';
                        ?>
                        <tr>
                            <td class="ps-4">
                                <div class="fw-bold text-dark"><?= $e['nome'] ?></div>
                                <div class="small text-muted"><?= $e['bairro'] ?></div>
                            </td>
                            <td>
                                <a href="https://wa.me/55<?= preg_replace('/[^0-9]/', '', $e['whatsapp']) ?>" target="_blank" class="text-decoration-none text-success fw-bold small">
                                    <i class="fab fa-whatsapp"></i> <?= $e['whatsapp'] ?>
                                </a>
                            </td>
                            
                            <td class="text-center">
                                <a href="?acao=toggle_adesivo&id=<?= $e['id'] ?>" class="btn-action <?= $cls_adesivo ?>" title="Marcar/Desmarcar Adesivo">
                                    <i class="fas fa-sticky-note"></i>
                                </a>
                            </td>

                            <td class="text-center">
                                <a href="?acao=toggle_voto&id=<?= $e['id'] ?>" class="btn-action <?= $cls_voto ?>" title="Confirmar Voto">
                                    <i class="fas fa-check"></i>
                                </a>
                            </td>

                            <td class="text-end pe-4">
                                <button class="btn <?= $cls_doacao ?> rounded-pill fw-bold" onclick="lancarDoacao(<?= $e['id'] ?>, '<?= $e['nome'] ?>', <?= $e['doacao_valor'] ?>)">
                                    <?= $valor_doacao ?>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted">Nenhum eleitor encontrado. Cadastre ou filtre novamente.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalDoacao" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <form method="POST" class="modal-content shadow">
            <div class="modal-header bg-success text-white">
                <h6 class="modal-title fw-bold">Doação de Campanha</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-center mb-3">Quanto <strong><span id="nome_doador"></span></strong> doou?</p>
                <div class="input-group mb-3">
                    <span class="input-group-text">R$</span>
                    <input type="text" name="valor" id="valor_doacao" class="form-control fw-bold text-center" placeholder="0,00" required>
                </div>
            </div>
            <div class="modal-footer justify-content-center border-0 pt-0">
                <button class="btn btn-success w-100 rounded-pill fw-bold">Salvar Valor</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js"></script>
<script>
    $(document).ready(function(){
        $('#valor_doacao').mask('#.##0,00', {reverse: true});
    });

    function lancarDoacao(id, nome, valorAtual) {
        document.getElementById('nome_doador').innerText = nome;
        document.getElementById('valor_doacao').value = valorAtual > 0 ? valorAtual.toFixed(2).replace('.', ',') : '';
        document.querySelector('#modalDoacao form').action = `?acao=salvar_doacao&id=${id}`;
        new bootstrap.Modal(document.getElementById('modalDoacao')).show();
    }
</script>

</body>
</html>