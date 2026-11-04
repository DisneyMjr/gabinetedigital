<?php
// admin_painel.php
// --- CONFIGURAÇÕES INICIAIS ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require 'config.php';
require 'protecao.php';

// --- 0. SEGURANÇA SAAS ---
if (!defined('TENANT_ID')) die("Erro de identificação do cliente.");

// ====================================================================
// 🔄 FIX CRÍTICO: RECARREGAR PERMISSÕES EM TEMPO REAL
// ====================================================================
try {
    $stmtPlano = $pdo->prepare("
        SELECT p.modulos
        FROM tenants t
        JOIN planos p ON t.plano = p.slug
        WHERE t.id = ?
        LIMIT 1
    ");
    $stmtPlano->execute([TENANT_ID]);
    $jsonModulos = $stmtPlano->fetchColumn();

    $mods = json_decode($jsonModulos ?: '[]', true);
    if (!is_array($mods)) {
        $mods = [];
    }

    // Normalização de aliases
    if (in_array('landing', $mods, true) && !in_array('landing_pages', $mods, true)) {
        $mods[] = 'landing_pages';
    }
    if (in_array('landing_pages', $mods, true) && !in_array('landing', $mods, true)) {
        $mods[] = 'landing';
    }

    $_SESSION['modulos_plano'] = array_values(array_unique($mods));
} catch (Exception $e) {
    $_SESSION['modulos_plano'] = $_SESSION['modulos_plano'] ?? [];
}

// Converte os módulos do plano para letras minúsculas para não dar erro na comparação

$modsSessaoLower = array_map('strtolower', array_map('trim', $_SESSION['modulos_plano']));

// Plano atual: usado para liberar o módulo Campanha Digital somente no Premium.
$planoAtualSlug = '';
$isPlanoPremium = false;
try {
    $stmtPlanoAtual = $pdo->prepare("SELECT plano FROM tenants WHERE id = ? LIMIT 1");
    $stmtPlanoAtual->execute([TENANT_ID]);
    $planoAtualSlug = strtolower(trim((string)$stmtPlanoAtual->fetchColumn()));
    $isPlanoPremium = ($planoAtualSlug !== '' && strpos($planoAtualSlug, 'premium') !== false);
} catch (Exception $e) {
    $isPlanoPremium = false;
}


// Nome do sistema e usuário
$nome_sistema = $site_config['nome_vereador'] ?? 'Gabinete Digital';
$nome_user = explode(' ', $_SESSION['admin_nome'] ?? 'Admin')[0];

// Variáveis padrão para evitar erros
$total_pendentes = 0; $total_analise = 0; $total_concluidos = 0;
$total_noticias = 0; $total_base = 0; $totalAniversariantesHoje = 0;
$media_nota = "0.0"; $total_avaliacoes = 0; $ultimas_avaliacoes = [];
$json_calor = "[]";

// --- 1. ESTATÍSTICAS GERAIS ---
$total_pendentes = $total_analise = $total_concluidos = $total_noticias = $total_base = $totalAniversariantesHoje = 0;

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM solicitacoes WHERE status = 'Pendente' AND tenant_id = ?"); $stmt->execute([TENANT_ID]); $total_pendentes = $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM solicitacoes WHERE status = 'Em Análise' AND tenant_id = ?"); $stmt->execute([TENANT_ID]); $total_analise = $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM solicitacoes WHERE status = 'Concluído' AND tenant_id = ?"); $stmt->execute([TENANT_ID]); $total_concluidos = $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM noticias WHERE tenant_id = ?"); $stmt->execute([TENANT_ID]); $total_noticias = $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM eleitores WHERE tenant_id = ?"); $stmt->execute([TENANT_ID]); $total_base = $stmt->fetchColumn();
} catch (Exception $e) { }

// --- ANIVERSARIANTES (ISOLADO PARA NÃO QUEBRAR O RESTO) ---
try {
    $coluna_niver = 'data_nascimento'; 
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM eleitores WHERE MONTH({$coluna_niver}) = MONTH(NOW()) AND DAY({$coluna_niver}) = DAY(NOW()) AND tenant_id = ?"); 
    $stmt->execute([TENANT_ID]); 
    $totalAniversariantesHoje = $stmt->fetchColumn();
} catch (Exception $e) {
    $totalAniversariantesHoje = 0;
}

// --- AVALIAÇÕES (ISOLADO E BLINDADO) ---
$media_nota = "0.0"; 
$total_avaliacoes = 0; 
$ultimas_avaliacoes = [];

try {
    $sql_av = "
        SELECT AVG(CAST(avaliacao_nota AS DECIMAL(10,2))) as media, COUNT(*) as total 
        FROM solicitacoes 
        WHERE tenant_id = ? 
        AND avaliacao_nota IS NOT NULL 
        AND avaliacao_nota != '' 
        AND avaliacao_nota != '0'
    ";
    $stmt_av = $pdo->prepare($sql_av);
    $stmt_av->execute([TENANT_ID]);
    $dados_av = $stmt_av->fetch(PDO::FETCH_ASSOC);
    
    $media_nota = (!empty($dados_av['media'])) ? number_format((float)$dados_av['media'], 1, '.', '') : "0.0";
    $total_avaliacoes = (!empty($dados_av['total'])) ? (int)$dados_av['total'] : 0;

    $stmt_ultimas = $pdo->prepare("
        SELECT nome_cidadao, avaliacao_nota, avaliacao_comentario 
        FROM solicitacoes 
        WHERE tenant_id = ? 
        AND avaliacao_nota IS NOT NULL 
        AND avaliacao_nota != '' 
        AND avaliacao_nota != '0' 
        ORDER BY id DESC 
        LIMIT 3
    ");
    $stmt_ultimas->execute([TENANT_ID]);
    $ultimas_avaliacoes = $stmt_ultimas->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { }

// --- 2. MAPA DE CALOR ---
try {
    $pontos_calor = [];
    $stmt = $pdo->prepare("SELECT latitude, longitude FROM solicitacoes WHERE tenant_id = ? AND latitude != '' AND longitude != ''");
    $stmt->execute([TENANT_ID]);
    $raw_pontos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($raw_pontos as $p) {
        $lat = (float) str_replace(',', '.', $p['latitude'] ?? '');
        $lng = (float) str_replace(',', '.', $p['longitude'] ?? '');
        if ($lat != 0 && $lng != 0) $pontos_calor[] = [$lat, $lng, 0.6];
    }
    $json_calor = json_encode($pontos_calor);
} catch (Exception $e) { 
    $json_calor = "[]"; 
}

// --- 3. CENTRO DO MAPA (LEITURA INTELIGENTE DE ESTADOS) ---
$lat_final = -15.7801;
$lng_final = -47.9292;
$zoom_final = 13; 

try {
    $stmt_conf = $pdo->prepare("SELECT * FROM configuracoes WHERE tenant_id = ?");
    $stmt_conf->execute([TENANT_ID]);
    $centro_mapa = $stmt_conf->fetch(PDO::FETCH_ASSOC);

    if ($centro_mapa) {
        if (!empty($centro_mapa['mapa_estado'])) {
            $estados_coord = [
                'AC' => [-8.77, -70.55], 'AL' => [-9.71, -35.73], 'AM' => [-3.07, -61.66],
                'AP' => [1.41, -51.77],  'BA' => [-12.96, -38.51], 'CE' => [-5.20, -39.53],
                'DF' => [-15.83, -47.86], 'ES' => [-19.19, -40.34], 'GO' => [-15.98, -49.86],
                'MA' => [-5.42, -45.44], 'MG' => [-18.10, -44.38], 'MS' => [-20.51, -54.54],
                'MT' => [-12.64, -55.42], 'PA' => [-3.79, -52.48],  'PB' => [-7.28, -36.72],
                'PE' => [-8.38, -37.86], 'PI' => [-7.69, -42.08],  'PR' => [-24.89, -51.55],
                'RJ' => [-22.84, -43.15], 'RN' => [-5.81, -36.59],  'RO' => [-10.83, -63.34],
                'RR' => [1.99, -61.33],  'RS' => [-30.17, -53.50], 'SC' => [-27.45, -50.95],
                'SE' => [-10.57, -37.45], 'SP' => [-23.55, -46.64], 'TO' => [-10.25, -48.25]
            ];
            $uf = strtoupper(trim($centro_mapa['mapa_estado']));
            if(isset($estados_coord[$uf])) {
                $lat_final = $estados_coord[$uf][0];
                $lng_final = $estados_coord[$uf][1];
                $zoom_final = 7; 
            }
        }

        if (!empty($centro_mapa['mapa_lat']) && !empty($centro_mapa['mapa_lng'])) {
            $valLat = (float) str_replace(',', '.', $centro_mapa['mapa_lat']);
            $valLng = (float) str_replace(',', '.', $centro_mapa['mapa_lng']);
            
            $is_sp_preso = (round($valLat, 2) == -23.55 && round($valLng, 2) == -46.63);
            
            if ($valLat != 0 && !$is_sp_preso) {
                $lat_final = $valLat;
                $lng_final = $valLng;
                $zoom_final = 14; 
            }
        }
    }
} catch (Exception $e) {}

// --- 4. ESTATÍSTICAS DE ABAIXO-ASSINADOS ATIVOS ---
$peticoes_stats = [];
try {
    $stmt = $pdo->prepare("SELECT id, titulo, tag_destino FROM peticoes WHERE tenant_id = ? AND status = 1 ORDER BY id DESC LIMIT 3");
    $stmt->execute([TENANT_ID]);
    $peticoes_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach($peticoes_stats as $key => $p) {
        $tagLike = "%" . $p['tag_destino'] . "%";
        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM eleitores WHERE tenant_id = ? AND tags LIKE ?");
        $stmtCount->execute([TENANT_ID, $tagLike]);
        $peticoes_stats[$key]['assinaturas'] = $stmtCount->fetchColumn();
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Painel - <?= htmlspecialchars($nome_sistema) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>

    <style>
        body { background-color: #f3f4f6; font-family: 'Inter', sans-serif; }
        .navbar { background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .navbar-brand { font-weight: 800; color: #1a2b49; text-transform: uppercase; letter-spacing: -0.5px; }

        .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.02); display: flex; align-items: center; justify-content: space-between; transition: transform 0.2s; height: 100%; border-bottom: 3px solid transparent; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
        .stat-icon { width: 45px; height: 45px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; }
        .stat-value { font-size: 22px; font-weight: 800; color: #333; line-height: 1; }
        .stat-label { font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; }

        .card-modulo { border: none; border-radius: 16px; background: white; transition: all 0.3s ease; text-decoration: none; color: #444; height: 100%; display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 30px 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); border-bottom: 4px solid transparent; }
        .card-modulo:hover { transform: translateY(-5px); box-shadow: 0 15px 30px rgba(0,0,0,0.1); color: #000; }
        .card-modulo .icon-box { width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 24px; margin-bottom: 15px; transition: 0.3s; }
        .card-title-mod { font-weight: 700; font-size: 1rem; margin-bottom: 5px; text-align: center;}
        .card-desc-mod { font-size: 0.8rem; color: #888; text-align: center; }

        .bg-blue-light { background: #e7f1ff; color: #0d6efd; }
        .bg-yellow-light { background: #fff9db; color: #ffc107; }
        .bg-green-light { background: #d1e7dd; color: #198754; }
        .bg-cyan-light { background: #cff4fc; color: #0dcaf0; }
        .bg-gray-light { background: #e2e3e5; color: #6c757d; }
        .bg-purple-light { background: #e2d9f3; color: #6f42c1; }
        .bg-red-light { background: #ffebee; color: #dc3545; }

        .star-filled { color: #ffc107; }
        .star-empty { color: #e9ecef; }

        .btn-suporte { position: fixed; bottom: 30px; right: 30px; z-index: 9999; background-color: #25D366; color: white; border-radius: 50px; padding: 12px 25px; font-weight: bold; box-shadow: 0 4px 15px rgba(0,0,0,0.2); transition: transform 0.2s; text-decoration: none; display: flex; align-items: center; gap: 10px; }
        .btn-suporte:hover { transform: scale(1.05); color: white; background-color: #128C7E; }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg fixed-top py-3">
        <div class="container">
            <span class="navbar-brand"><i class="fas fa-user-tie text-primary me-2"></i> <?= htmlspecialchars($nome_sistema) ?></span>
            <div class="d-flex align-items-center gap-3">
                <span class="text-muted small d-none d-md-block">Olá, <strong><?= htmlspecialchars($nome_user) ?></strong></span>
                <a href="logout.php" class="btn btn-outline-danger btn-sm rounded-pill px-3 fw-bold"><i class="fas fa-sign-out-alt me-1"></i> Sair</a>
            </div>
        </div>
    </nav>

    <div class="container" style="margin-top: 110px; padding-bottom: 60px;">

        <div class="row g-3 mb-4">
            <div class="col-6 col-md-2">
                <div class="stat-card" style="border-color: #ffc107;">
                    <div><div class="stat-value text-warning"><?= $total_pendentes ?></div><div class="stat-label">Pendentes</div></div>
                    <div class="stat-icon bg-yellow-light"><i class="fas fa-clock"></i></div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="stat-card" style="border-color: #0d6efd;">
                    <div><div class="stat-value text-primary"><?= $total_analise ?></div><div class="stat-label">Análise</div></div>
                    <div class="stat-icon bg-blue-light"><i class="fas fa-spinner"></i></div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="stat-card" style="border-color: #198754;">
                    <div><div class="stat-value text-success"><?= $total_concluidos ?></div><div class="stat-label">Atendidos</div></div>
                    <div class="stat-icon bg-green-light"><i class="fas fa-check"></i></div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="stat-card" style="border-color: #0dcaf0;">
                    <div><div class="stat-value text-info"><?= $total_base ?></div><div class="stat-label">Eleitores</div></div>
                    <div class="stat-icon bg-cyan-light"><i class="fas fa-users"></i></div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="stat-card" style="border-color: #6c757d;">
                    <div><div class="stat-value text-secondary"><?= $total_noticias ?></div><div class="stat-label">Notícias</div></div>
                    <div class="stat-icon bg-gray-light"><i class="fas fa-newspaper"></i></div>
                </div>
            </div>
        </div>

        <?php if(!empty($peticoes_stats)): ?>
        <h6 class="fw-bold text-dark mb-3 ps-2 border-start border-4 border-danger">Desempenho de Captação (Abaixo-Assinados)</h6>
        <div class="row g-3 mb-4">
            <?php foreach($peticoes_stats as $p): ?>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100 rounded-4 position-relative overflow-hidden">
                    <div style="position: absolute; top: 0; left: 0; width: 4px; height: 100%; background: #dc3545;"></div>
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h6 class="fw-bold text-dark mb-0" style="display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;" title="<?= htmlspecialchars($p['titulo']) ?>"><?= htmlspecialchars($p['titulo']) ?></h6>
                            <div class="bg-danger bg-opacity-10 text-danger rounded p-2 ms-2">
                                <i class="fas fa-file-signature"></i>
                            </div>
                        </div>
                        <div class="mt-3">
                            <h2 class="display-5 fw-bold text-danger mb-0"><?= $p['assinaturas'] ?></h2>
                            <span class="text-muted small fw-bold text-uppercase">Assinaturas / Contatos Salvos</span>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="row mb-5 g-4">
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm rounded-4 overflow-hidden h-100">
                    <div class="card-header bg-white border-bottom-0 py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 fw-bold text-dark"><i class="fas fa-fire-alt text-danger me-2"></i> Mapa de Demandas</h6>
                        <span class="badge bg-light text-secondary border">Georreferenciamento</span>
                    </div>
                    <div class="card-body p-0">
                        <div id="mapaHeat" style="height: 350px; width: 100%;"></div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card border-0 shadow-sm rounded-4 h-100">
                    <div class="card-header bg-white border-bottom-0 py-3">
                        <h6 class="m-0 fw-bold text-dark"><i class="fas fa-star text-warning me-2"></i> Satisfação</h6>
                    </div>
                    <div class="card-body text-center pt-0">
                        <div class="display-3 fw-bold text-dark mb-0"><?= $media_nota ?></div>
                        <div class="mb-3 fs-5">
                            <?php
                                $nota_arredondada = round($media_nota);
                                for($i=1; $i<=5; $i++) {
                                    echo ($i <= $nota_arredondada) ? '<i class="fas fa-star star-filled"></i>' : '<i class="fas fa-star star-empty"></i>';
                                }
                            ?>
                        </div>
                        <p class="text-muted small mb-4">Baseado em <strong><?= $total_avaliacoes ?></strong> avaliações</p>
                        <hr>
                        <div class="text-start">
                            <small class="text-uppercase text-muted fw-bold mb-3 d-block" style="font-size: 0.7rem;">Últimos Feedbacks</small>
                            <?php if(count($ultimas_avaliacoes) > 0): ?>
                                <?php foreach($ultimas_avaliacoes as $av):
                                     $nome_cliente = !empty($av['nome_cidadao']) ? explode(' ', $av['nome_cidadao'])[0] : 'Anônimo';
                                ?>
                                    <div class="mb-3 border-bottom pb-2">
                                        <div class="d-flex justify-content-between">
                                            <strong class="small"><?= htmlspecialchars($nome_cliente) ?></strong>
                                            <small class="text-warning"><i class="fas fa-star"></i> <?= $av['avaliacao_nota'] ?></small>
                                        </div>
                                        <?php if(!empty($av['avaliacao_comentario'])): ?>
                                            <div class="small text-muted fst-italic text-truncate">"<?= htmlspecialchars($av['avaliacao_comentario']) ?>"</div>
                                        <?php else: ?>
                                            <div class="small text-muted fst-italic text-truncate">(Sem comentário)</div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center text-muted small py-3 bg-light rounded">Nenhuma avaliação ainda.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <h5 class="fw-bold text-dark mb-4 ps-2 border-start border-4 border-primary">Módulos do Sistema</h5>

        <div class="row g-4">
            <?php
            $modulos = [
                'agenda_interna' => ['nome' => 'Agenda da Equipe', 'desc' => 'Compromissos Internos', 'icon' => 'fa-calendar-check', 'bg' => 'bg-green-light', 'cor' => '#198754', 'link' => 'admin_agenda_interna.php'],
                'designer'      => ['nome' => 'Designer Express', 'desc' => 'Artes para WhatsApp', 'icon' => 'fa-magic', 'bg' => 'bg-purple-light', 'cor' => '#a855f7', 'link' => 'admin_designer.php'],
                'tarefas' => [
                    'nome' => 'Demandas', 
                    'desc' => 'Atribuir tarefas', 
                    'icon' => 'fa-tasks', 
                    'bg' => 'bg-purple-light', 
                    'cor' => '#8b5cf6', 
                    'link' => 'tarefas_listar.php'],
                'mapa'          => ['nome' => 'Mapa de Demandas',   'desc' => 'Georreferenciamento',   'icon' => 'fa-map-marked-alt',  'bg' => 'bg-cyan-light',    'cor' => '#0dcaf0', 'link' => 'admin_mapa_eleitores.php'],
                'campanha'      => ['nome' => 'Envolvimento',    'desc' => 'Gestão de Campanha',    'icon' => 'fa-bullseye',        'bg' => 'bg-red-light',     'cor' => '#dc3545', 'link' => 'admin_envolvimento.php'],
                'campanha_digital' => ['nome' => 'Campanha Digital', 'desc' => 'Site eleitoral e mobilização', 'icon' => 'fa-globe', 'bg' => 'bg-purple-light', 'cor' => '#7c3aed', 'link' => 'admin_campanha.php'],
                'liderancas'    => ['nome' => 'Multiplicadores', 'desc' => 'Gestão de Lideranças',  'icon' => 'fa-user-tie',        'bg' => 'bg-yellow-light',  'cor' => '#ffc107', 'link' => 'admin_liderancas.php'],
                'eleitores'     => ['nome' => 'Base Eleitoral',  'desc' => 'CRM de Eleitores',       'icon' => 'fa-users',           'bg' => 'bg-blue-light',    'cor' => '#0d6efd', 'link' => 'admin_eleitores.php'],
                'atendimentos'  => ['nome' => 'CRM Atendimento', 'desc' => 'Funil e Nutrição', 'icon' => 'fa-headset', 'bg' => 'bg-cyan-light', 'cor' => '#0dcaf0', 'link' => 'admin_atendimentos.php'],
                'peticoes'      => ['nome' => 'Abaixo-Assinados','desc' => 'Páginas de Captura',     'icon' => 'fa-file-signature',  'bg' => 'bg-red-light',     'cor' => '#dc3545', 'link' => 'admin_peticoes.php'],
                'cac_politico'  => ['nome' => 'Custo por Voto',  'desc' => 'Calculadora CAC',        'icon' => 'fa-bullseye',        'bg' => 'bg-yellow-light',  'cor' => '#ffc107', 'link' => 'admin_cac.php'],
                'projetos'      => ['nome' => 'Projetos de Lei', 'desc' => 'Minhas Leis e Moções', 'icon' => 'fa-file-signature', 'bg' => 'bg-purple-light', 'cor' => '#6f42c1', 'link' => 'admin_projetos.php'],
                'ouvidoria'     => ['nome' => 'Solicitações',    'desc' => 'Gerenciar Solicitações', 'icon' => 'fa-bullhorn',        'bg' => 'bg-yellow-light',  'cor' => '#ffc107', 'link' => 'admin_solicitacoes.php'],
                'aniversariantes' => ['nome' => 'Aniversariantes', 'desc' => 'CRM de Eleitores',       'icon' => 'fa-birthday-cake',    'bg' => 'bg-blue-light',    'cor' => '#dd0dfd', 'link' => 'admin_aniversariantes.php'],
                'noticias'      => ['nome' => 'Notícias',        'desc' => 'Site e Blog',            'icon' => 'fa-newspaper',       'bg' => 'bg-gray-light',    'cor' => '#6c757d', 'link' => 'admin_noticias_lista.php'],
                'agenda'        => ['nome' => 'Agenda Pública',  'desc' => 'Compromissos do Site',   'icon' => 'fa-calendar-alt',    'bg' => 'bg-red-light',     'cor' => '#d32f2f', 'link' => 'admin_agenda.php'],
                'push'          => ['nome' => 'Notificações',     'desc' => 'Disparos no Celular',    'icon' => 'fa-satellite-dish',  'bg' => 'bg-warning-light', 'cor' => '#f59e0b', 'link' => 'admin_push.php'],
                'marketing'     => ['nome' => 'E-mail Marketing','desc' => 'Disparos em Massa',      'icon' => 'fa-paper-plane',     'bg' => 'bg-purple-light',  'cor' => '#6f42c1', 'link' => 'admin_marketing.php'],
                'qrcode'        => ['nome' => 'Placa QR Code',   'desc' => 'Imprimir Cartaz',        'icon' => 'fa-qrcode',          'bg' => 'bg-green-light',   'cor' => '#198754', 'link' => 'admin_qrcode.php'],
                'financeiro'    => ['nome' => 'Meu Plano',       'desc' => 'Faturas e Pagamentos',   'icon' => 'fa-wallet',          'bg' => 'bg-green-light',   'cor' => '#198754', 'link' => 'admin_financeiro.php'],
                'oficios_ia'    => ['nome' => 'Redator de Ofícios IA',      'desc' => 'Gerar Ofícios Oficiais', 'icon' => 'fa-robot',           'bg' => 'bg-purple-light',  'cor' => '#8b5cf6', 'link' => 'admin_oficios_ia.php'],
                'enquetes'      => ['nome' => 'Enquetes',        'desc' => 'Pesquisas de Opinião',   'icon' => 'fa-poll',            'bg' => 'bg-yellow-light',  'cor' => '#fd7e14', 'link' => 'admin_enquetes.php'],
                'landing'       => ['nome' => 'Criador de LP', 'desc' => 'Páginas de Captura', 'icon' => 'fa-laptop-code', 'bg' => 'bg-blue-light', 'cor' => '#6610f2', 'link' => 'admin_lp_editor.php'],
                'templates'     => ['nome' => 'Templates Master','desc' => 'Modelos de Site',        'icon' => 'fa-magic',           'bg' => 'bg-purple-light',  'cor' => '#a855f7', 'link' => 'admin_paginas.php'],
                
                // MÓDULO FINANCEIRO NOVO INSERIDO NO LUGAR DE "TRANSPARÊNCIA"
                'transparencia' => ['nome' => 'Prestação de Contas',   'desc' => 'Orçamento e Gastos',    'icon' => 'fa-file-invoice-dollar',    'bg' => 'bg-green-light',   'cor' => '#20c997', 'link' => 'admin_prestacao_contas.php'],
                
                'relatorios'    => ['nome' => 'Relatórios',      'desc' => 'Dados e Inteligência',   'icon' => 'fa-chart-pie',       'bg' => 'bg-cyan-light',    'cor' => '#0dcaf0', 'link' => 'admin_relatorios_crm.php'],
                'equipe'        => ['nome' => 'Equipe',          'desc' => 'Gestão de Usuários',     'icon' => 'fa-users-cog',       'bg' => 'bg-red-light',     'cor' => '#d63384', 'link' => 'admin_equipe.php'],
                'auditoria'     => ['nome' => 'Auditoria',       'desc' => 'Logs de Segurança',      'icon' => 'fa-fingerprint',     'bg' => 'bg-gray-light',    'cor' => '#495057', 'link' => 'admin_auditoria.php'],
                'links'         => ['nome' => 'Encurtador',      'desc' => 'Links Monitorados',      'icon' => 'fa-link',            'bg' => 'bg-blue-light',    'cor' => '#0d6efd', 'link' => 'admin_links.php'],
                'tutoriais'     => ['nome' => 'Tutoriais',       'desc' => 'Aprenda a Usar',         'icon' => 'fa-video',           'bg' => 'bg-cyan-light',    'cor' => '#0dcaf0', 'link' => 'admin_tutoriais.php'],
                'config'        => ['nome' => 'Configurações',   'desc' => 'Ajustes do Sistema',     'icon' => 'fa-sliders-h',       'bg' => 'bg-gray-light',    'cor' => '#343a40', 'link' => 'admin_config.php']
            ];

            foreach($modulos as $chave => $m):
                
                // 🔒 BLINDAGEM DO "MEU PLANO": Só o DONO (master) pode ver o financeiro do SaaS
                if ($chave === 'financeiro' && function_exists('tem_acesso') && !tem_acesso('master')) {
                    continue; 
                }

                // 1. Módulos Sempre Grátis (Agenda Interna e Atendimentos adicionados)
                $modulosGratis = ['tutoriais', 'links', 'qrcode', 'financeiro', 'config', 'agenda_interna', 'atendimentos'];
                $is_free = in_array($chave, $modulosGratis, true);

                // 2. Dicionário Blindado
                $variacoes = [strtolower($chave), strtolower($m['nome'])];
                if ($chave === 'designer') array_push($variacoes, 'designer express', 'artes');
                if ($chave === 'tarefas') array_push($variacoes, '✅ tarefas e demandas', 'tarefas e demandas', 'demandas');
                if ($chave === 'oficios_ia') array_push($variacoes, 'redator de ofícios com ia', 'redator ia', 'redator_ia');
                if ($chave === 'campanha') array_push($variacoes, 'envolvimento');
                if ($chave === 'campanha_digital') array_push($variacoes, 'campanha digital', 'site de campanha', 'site eleitoral');
                if ($chave === 'mapa') array_push($variacoes, 'mapa geo', 'mapa de calor');
                if ($chave === 'liderancas') array_push($variacoes, 'lideranças', 'liderancas');
                if ($chave === 'ouvidoria') array_push($variacoes, 'ouvidoria');
                if ($chave === 'noticias') array_push($variacoes, 'site/notícias', 'site e notícias', 'site');
                if ($chave === 'eleitores') array_push($variacoes, 'crm eleitores', 'crm de eleitores');
                if ($chave === 'aniversariantes') array_push($variacoes, 'aniversariantes');
                if ($chave === 'agenda') array_push($variacoes, 'agenda', 'agenda publica', 'agenda pública');
                if ($chave === 'projetos') array_push($variacoes, 'projetos lei', 'projetos de lei');
                if ($chave === 'enquetes') array_push($variacoes, 'enquetes', 'enquete');
                if ($chave === 'landing') array_push($variacoes, 'landing pages', 'landing_pages', 'captura');
                if ($chave === 'templates') array_push($variacoes, 'modelos', 'modelos de arte');
                if ($chave === 'transparencia') array_push($variacoes, 'transparência', 'transparencia', 'prestação de contas', 'prestacao de contas');
                if ($chave === 'qrcode') array_push($variacoes, 'placa qr', 'placa qr code');
                if ($chave === 'links') array_push($variacoes, 'encurtador', 'encurtador de links');
                if ($chave === 'relatorios') array_push($variacoes, 'relatórios', 'relatorios');
                if ($chave === 'financeiro') array_push($variacoes, 'financeiro', 'meu plano');
                if ($chave === 'push') array_push($variacoes, 'push', 'notificações push');
                if ($chave === 'marketing') array_push($variacoes, 'e-mail mkt', 'e-mail marketing');
                if ($chave === 'equipe') array_push($variacoes, 'equipe', 'usuário');
                if ($chave === 'auditoria') array_push($variacoes, 'auditoria');
                if ($chave === 'config') array_push($variacoes, 'configurações', 'configuracoes');
                
                // Mapeamento dos módulos novos
                if ($chave === 'peticoes') array_push($variacoes, 'abaixo-assinados', 'abaixo assinados', 'petições');
                if ($chave === 'cac_politico') array_push($variacoes, 'custo por voto', 'cac', 'cac_politico');
                if ($chave === 'agenda_interna') array_push($variacoes, 'agenda interna', 'agenda da equipe');
                if ($chave === 'atendimentos') array_push($variacoes, 'crm atendimento', 'atendimento', 'atendimentos');

                // 3. Verifica liberação
                $liberado_master = count(array_intersect($variacoes, $modsSessaoLower)) > 0;

                // 4. Verifica a permissão do usuário nativa
                $liberado_usuario = false;
                foreach($variacoes as $v) {
                    if (function_exists('tem_acesso')) {
                        if (tem_acesso($chave) || tem_acesso($m['nome'])) {
                            $liberado_usuario = true;
                            break;
                        }
                    }
                }
                
                // Hack extra para os problemáticos
                if (function_exists('tem_acesso')) {
                    if ($chave == 'oficios_ia' && tem_acesso('Redator de Ofícios com IA')) $liberado_usuario = true;
                    if ($chave == 'landing' && tem_acesso('Landing Pages')) $liberado_usuario = true;
                    if ($chave == 'tarefas') $liberado_usuario = true;
                    if ($chave == 'designer' && tem_acesso('Designer Express')) $liberado_usuario = true;
                    if ($chave == 'mapa' && tem_acesso('Mapa Geo')) $liberado_usuario = true;
                    if ($chave == 'campanha' && tem_acesso('Envolvimento')) $liberado_usuario = true;
                    if ($chave == 'liderancas' && tem_acesso('Lideranças')) $liberado_usuario = true;
                    if ($chave == 'noticias' && tem_acesso('Site/Notícias')) $liberado_usuario = true;
                    if ($chave == 'eleitores' && tem_acesso('CRM Eleitores')) $liberado_usuario = true;
                    if ($chave == 'projetos' && tem_acesso('Projetos Lei')) $liberado_usuario = true;
                    if ($chave == 'templates' && tem_acesso('Modelos')) $liberado_usuario = true;
                    if ($chave == 'transparencia' && tem_acesso('Transparência')) $liberado_usuario = true;
                    if ($chave == 'relatorios' && tem_acesso('Relatórios')) $liberado_usuario = true;
                    if ($chave == 'marketing' && tem_acesso('E-mail Mkt')) $liberado_usuario = true;
                    if ($chave == 'equipe' && tem_acesso('Equipe')) $liberado_usuario = true;
                    if ($chave == 'auditoria' && tem_acesso('Auditoria')) $liberado_usuario = true;
                    if ($chave == 'agenda_interna') $liberado_usuario = true; 
                    if ($chave == 'atendimentos') $liberado_usuario = true; // Auto-libera o CRM Atendimento
                    
                    // Permissões extras dos módulos novos
                    if ($chave == 'peticoes' && tem_acesso('Abaixo-Assinados')) $liberado_usuario = true;
                    if ($chave == 'cac_politico' && tem_acesso('Custo por Voto')) $liberado_usuario = true;
                }

                // 5. Destranca se for grátis, ou se passou nas duas travas
                $liberado = $is_free || ($liberado_master && $liberado_usuario);

                // Campanha Digital: regra comercial exclusiva do plano Premium.
                // O card continua visível nos outros planos, porém bloqueado.
                if ($chave === 'campanha_digital') {
                    $liberado = $isPlanoPremium;
                }

                $badgeHtml = '';
                if ($chave == 'aniversariantes' && isset($totalAniversariantesHoje) && $totalAniversariantesHoje > 0) {
                    $badgeHtml = '<span class="position-absolute top-0 end-0 m-3 badge rounded-pill bg-danger border border-white shadow animate__animated animate__pulse animate__infinite">
                                    ' . $totalAniversariantesHoje . '
                                    <span class="visually-hidden">aniversariantes</span>
                                  </span>';
                }

                if (!$liberado && $chave === 'campanha_digital') {
                    $linkAtributo = 'href="#" onclick="alert(\'Campanha Digital é exclusiva do plano Premium. Faça o upgrade para liberar o criador de site eleitoral e a mobilização.\'); window.location.href=\'admin_financeiro.php\'; return false;"';
                } else {
                    $linkAtributo = $liberado ? 'href="' . $m['link'] . '"' : 'href="#" onclick="alert(\'Recurso Premium! Faça o upgrade do seu plano para liberar esta funcionalidade.\'); return false;"';
                }
                $estiloBloqueado = !$liberado ? 'opacity: 0.6; filter: grayscale(100%);' : '';
                $iconeCadeado = !$liberado ? '<i class="fas fa-lock text-danger ms-2" title="Requer Upgrade"></i>' : '';

                if ($chave == 'ouvidoria') { ?>
                    <div class="col-xl-3 col-md-4 col-sm-6">
                        <div class="card-modulo p-0 h-100 position-relative" style="border-bottom-color: <?= $m['cor'] ?>; <?= $estiloBloqueado ?>">
                            <a <?= $linkAtributo ?> class="text-decoration-none w-100 flex-grow-1 d-flex flex-column align-items-center justify-content-center p-4 text-dark">
                                <div class="icon-box <?= $m['bg'] ?>" style="color: <?= $m['cor'] ?>;">
                                    <i class="fas <?= $m['icon'] ?>"></i>
                                </div>
                                <div class="card-title-mod"><?= $m['nome'] ?><?= $iconeCadeado ?></div>
                                <div class="card-desc-mod"><?= $m['desc'] ?></div>
                            </a>
                        
                        </div>
                    </div>
                <?php } else { ?>
                    <div class="col-xl-3 col-md-4 col-sm-6">
                        <a <?= $linkAtributo ?> class="card-modulo position-relative" style="border-bottom-color: <?= $m['cor'] ?>; <?= $estiloBloqueado ?>">
                            <?= $badgeHtml ?>
                            <div class="icon-box <?= $m['bg'] ?>" style="color: <?= $m['cor'] ?>;">
                                <i class="fas <?= $m['icon'] ?>"></i>
                            </div>
                            <div class="card-title-mod"><?= $m['nome'] ?><?= $iconeCadeado ?></div>
                            <div class="card-desc-mod"><?= $m['desc'] ?></div>
                        </a>
                    </div>
                <?php }
            endforeach;
            ?>
        </div>
    </div>

    <a href="https://wa.me/5537999129477?text=Ol%C3%A1%2C+preciso+de+ajuda+no+Gabinete+Digital." target="_blank" class="btn-suporte">
        <i class="fab fa-whatsapp fa-lg"></i> Suporte
    </a>

    <div id="ai-chat-container" style="position: fixed; bottom: 100px; right: 30px; z-index: 1000; font-family: 'Inter', sans-serif;">
        <div id="ai-bubble" style="display: none; background: white; border-radius: 15px; padding: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); margin-bottom: 10px; width: 250px; position: relative; border: 1px solid #eee;">
            <small class="fw-bold text-primary d-block mb-1"><i class="fas fa-robot me-1"></i> Mentor Digital</small>
            <span id="ai-bubble-text" style="font-size: 13px; color: #444;">...</span>
            <button onclick="openAIChat()" style="display: block; width: 100%; border: none; background: #0d6efd; color: white; border-radius: 5px; margin-top: 10px; font-size: 12px; padding: 5px;">Ver Dica</button>
        </div>

        <button onclick="toggleAIChat()" style="width: 60px; height: 60px; border-radius: 50%; background: #0d6efd; color: white; border: none; box-shadow: 0 5px 15px rgba(13,110,253,0.4); font-size: 24px;">
            <i class="fas fa-robot"></i>
        </button>
    </div>

    <div id="ai-window" style="display: none; position: fixed; bottom: 100px; right: 30px; width: 350px; height: 450px; background: white; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); z-index: 1001; flex-direction: column;">
        <div style="background: #0d6efd; color: white; padding: 15px; border-radius: 15px 15px 0 0; display: flex; justify-content: space-between;">
            <span class="fw-bold">Mentor Estratégico</span>
            <button onclick="toggleAIChat()" style="background: none; border: none; color: white;"><i class="fas fa-times"></i></button>
        </div>
        <div id="ai-messages" style="flex-grow: 1; padding: 15px; overflow-y: auto; font-size: 14px; color: #333; background: #f9f9f9;"></div>
        <div style="padding: 10px; border-top: 1px solid #eee; display: flex; background: white; border-radius: 0 0 15px 15px;">
            <input type="text" id="ai-input" placeholder="Pergunte sobre sua estratégia..." style="flex-grow: 1; border: 1px solid #ddd; padding: 8px; border-radius: 20px; outline: none;">
            <button onclick="sendAIMessage()" style="background: #0d6efd; color: white; border: none; border-radius: 50%; width: 35px; height: 35px; margin-left: 5px;"><i class="fas fa-paper-plane"></i></button>
        </div>
    </div>
    
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet.heat/0.2.0/leaflet-heat.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Oculta o clique direito
        document.addEventListener('contextmenu', function(e) { e.preventDefault(); });

        // URL do endpoint (SEM .php para evitar 301)
        const API = `${window.location.origin}/api_ai_suporte`;

        // LÓGICA DO MAPA
        document.addEventListener("DOMContentLoaded", function() {
            var dadosCalor = <?= $json_calor ?>;
            var latCentro = <?= json_encode($lat_final) ?>;
            var lngCentro = <?= json_encode($lng_final) ?>;
            var zoomMap = <?= json_encode($zoom_final) ?>;

            var map = L.map('mapaHeat').setView([parseFloat(latCentro), parseFloat(lngCentro)], zoomMap);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap'
            }).addTo(map);

            if (dadosCalor.length > 0) {
                L.heatLayer(dadosCalor, {
                    radius: 25,
                    blur: 15,
                    maxZoom: 17
                }).addTo(map);
            }

            // IA PROATIVA (Balãozinho)
            const totalAnivHoje = <?= (int)$totalAniversariantesHoje ?>;
            let mensagemInicial = "";
            if (totalAnivHoje > 0) {
                mensagemInicial = `Olá! Temos ${totalAnivHoje} eleitor(es) fazendo aniversário hoje. Que tal enviar um parabéns?`;
            } else {
                const dicasGerais = [
                    "Olá! Vi que temos novas solicitações. Resolver rápido gera votos!",
                    "Dica: Use o Mapa de Votos para planejar visitas estratégicas.",
                    "Já atualizou o Instagram hoje? Use o Designer Express para criar conteúdo!"
                ];
                mensagemInicial = dicasGerais[Math.floor(Math.random() * dicasGerais.length)];
            }

            document.getElementById('ai-bubble-text').innerText = mensagemInicial;
            setTimeout(() => {
                document.getElementById('ai-bubble').style.display = 'block';
            }, 8000);
        });

        // Funções do Chat IA
        window.toggleAIChat = function() {
            const winEl = document.getElementById('ai-window');
            const bubble = document.getElementById('ai-bubble');

            if (winEl.style.display === 'none' || winEl.style.display === '') {
                winEl.style.display = 'flex';
                bubble.style.display = 'none';
            } else {
                winEl.style.display = 'none';
            }
        }

        window.openAIChat = function() {
            document.getElementById('ai-window').style.display = 'flex';
            document.getElementById('ai-bubble').style.display = 'none';
        }

        window.sendAIMessage = async function() {
            const input = document.getElementById('ai-input');
            const msgArea = document.getElementById('ai-messages');
            const text = (input.value || '').trim();

            if (!text) return;

            // Mostrar mensagem do usuário
            msgArea.innerHTML += `<div style="text-align: right; margin-bottom: 10px;"><span style="background: #e9ecef; color: #333; padding: 8px 12px; border-radius: 15px 15px 0 15px; display: inline-block;">${text}</span></div>`;
            input.value = '';
            msgArea.scrollTop = msgArea.scrollHeight;

            // Loading
            const loadingId = 'loading-' + Date.now();
            msgArea.innerHTML += `<div id="${loadingId}" style="text-align: left; margin-bottom: 10px;"><small class="text-muted"><i class="fas fa-circle-notch fa-spin"></i> Analisando...</small></div>`;

            try {
                const res = await fetch(API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: 'message=' + encodeURIComponent(text)
                });

                const raw = await res.text();

                let json;
                try {
                    json = JSON.parse(raw);
                } catch (e) {
                    console.error('Resposta não-JSON:', raw);
                    throw new Error('Servidor não retornou JSON.');
                }

                const loadingEl = document.getElementById(loadingId);
                if (loadingEl) loadingEl.remove();

                if (!res.ok || !json.success) {
                    const errMsg = json && json.error ? json.error : 'Erro desconhecido';
                    msgArea.innerHTML += `<p class="text-danger small text-center mt-2">Ops! ${errMsg}</p>`;
                    msgArea.scrollTop = msgArea.scrollHeight;
                    return;
                }

                const aiText = json.response;

                msgArea.innerHTML += `<div style="text-align: left; margin-bottom: 10px;"><span style="background: #0d6efd; color: white; padding: 8px 12px; border-radius: 15px 15px 15px 0; display: inline-block;">${aiText}</span></div>`;
                msgArea.scrollTop = msgArea.scrollHeight;

            } catch (e) {
                const loadingEl = document.getElementById(loadingId);
                if (loadingEl) loadingEl.remove();

                msgArea.innerHTML += `<p class="text-danger small text-center mt-2">Erro de conexão.</p>`;
                console.error(e);
            }
        }

        // Enviar com Enter
        document.getElementById('ai-input').addEventListener('keypress', function (e) {
            if (e.key === 'Enter') {
                window.sendAIMessage();
            }
        });
    </script>
</body>
</html>