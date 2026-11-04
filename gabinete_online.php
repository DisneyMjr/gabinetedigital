<?php
require_once 'config.php'; 
date_default_timezone_set('America/Sao_Paulo');

if (!defined('TENANT_ID')) { die("Erro Crítico: Tenant não identificado."); }

// --- FUNÇÃO DE CORES DOS STATUS ---
function obterCorStatus($status) {
    if ($status === 'Concluído') return 'success';
    if ($status === 'Concluído Pendente') return 'info text-dark';
    if ($status === 'Em Análise') return 'primary';
    return 'warning text-dark';
}

// --- 1. CONFIGURAÇÕES ---
try {
    $stmt_conf = $pdo->prepare("SELECT * FROM configuracoes WHERE tenant_id = ?");
    $stmt_conf->execute([TENANT_ID]);
    $conf = $stmt_conf->fetch(PDO::FETCH_ASSOC);

    $nome_vereador = $conf['nome_vereador'] ?? 'Gabinete Digital';
    $modo_mapa = $conf['mapa_modo'] ?? 'estado';
    $estado_uf = $conf['mapa_estado'] ?? 'MG';
    $lat_city = $conf['mapa_lat'] ?? '-19.9167'; 
    $lng_city = $conf['mapa_lng'] ?? '-43.9345';
    $cor_primaria = $conf['cor_primaria'] ?? '#0d6efd'; 
    $site_key = $conf['recaptcha_site'] ?? '';
    $secret_key = $conf['recaptcha_secret'] ?? '';
    
    // VARIÁVEL DO BANNER
    $banner_gabinete = $conf['banner_gabinete'] ?? ''; 
} catch (Exception $e) { 
    $nome_vereador = "Gabinete Digital"; 
    $banner_gabinete = '';
}

$sucesso = false;
$erro = false;
$msg_avaliacao = false;

// Variáveis de Controle
$aba_ativa = 'nova'; 
$tela_acompanhar = 'busca'; 
$historico = [];
$pedido_detalhe = null;
$identificacao_persistente = "";

// --- 2. FUNÇÃO UPLOAD ---
function uploadComprimido($arquivo, $destino) {
    if (!extension_loaded('gd')) return move_uploaded_file($arquivo['tmp_name'], $destino);
    $info = getimagesize($arquivo['tmp_name']);
    if ($info === false) return false;
    $tipo = $info[2];
    switch ($tipo) {
        case IMAGETYPE_JPEG: $imagem = imagecreatefromjpeg($arquivo['tmp_name']); break;
        case IMAGETYPE_PNG: $imagem = imagecreatefrompng($arquivo['tmp_name']); break;
        case IMAGETYPE_WEBP: $imagem = imagecreatefromwebp($arquivo['tmp_name']); break;
        default: return move_uploaded_file($arquivo['tmp_name'], $destino);
    }
    $max = 1280; 
    if ($info[0] > $max) {
        $nova_largura = $max; $nova_altura = ($info[1] / $info[0]) * $max;
        $nova_imagem = imagecreatetruecolor($nova_largura, $nova_altura);
        if ($tipo == IMAGETYPE_PNG || $tipo == IMAGETYPE_WEBP) {
            imagealphablending($nova_imagem, false); imagesavealpha($nova_imagem, true);
        }
        imagecopyresampled($nova_imagem, $imagem, 0, 0, 0, 0, $nova_largura, $nova_altura, $info[0], $info[1]);
        imagedestroy($imagem); $imagem = $nova_imagem;
    }
    $res = false;
    switch ($tipo) {
        case IMAGETYPE_JPEG: $res = imagejpeg($imagem, $destino, 70); break;
        case IMAGETYPE_PNG: $res = imagepng($imagem, $destino, 8); break;
        case IMAGETYPE_WEBP: $res = imagewebp($imagem, $destino, 70); break;
    }
    imagedestroy($imagem); return $res;
}

// --- 3. PROCESSAMENTO POST ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // A. NOVA SOLICITAÇÃO
    if (isset($_POST['acao']) && $_POST['acao'] == 'nova_solicitacao') {
        try {
            $nome = trim($_POST['nome'] ?? ''); 
            $email = trim($_POST['email'] ?? ''); 
            $nascimento = $_POST['nascimento'] ?? ''; 
            $whatsapp = preg_replace('/[^0-9]/', '', $_POST['whatsapp'] ?? ''); 
            $sexo = $_POST['sexo'] ?? 'Não Informado'; 

            if(empty($nome) || empty($whatsapp)) throw new Exception("Preencha os campos obrigatórios.");
            
            if(!empty($nascimento)){
                $ano_nasc = date('Y', strtotime($nascimento));
                if ($ano_nasc >= date('Y') || $ano_nasc < 1900) throw new Exception("Data de nascimento inválida.");
            }
            
            $cidade = ($modo_mapa == 'coordenadas') ? "Sede / Municipal" : ($_POST['cidade'] ?? 'Não Informada');
            $bairro = $_POST['bairro'] ?? ''; $rua = $_POST['rua'] ?? ''; $numero = $_POST['numero'] ?? '';
            $tipo = $_POST['tipo'] ?? 'Solicitação'; $mensagem = $_POST['mensagem'] ?? '';
            $lat = $_POST['latitude'] ?? ''; $lng = $_POST['longitude'] ?? '';

            // --- CAPTURA DA ZONA E SEÇÃO OPCIONAL ---
            $zona_eleitoral = !empty($_POST['zona_eleitoral']) ? $_POST['zona_eleitoral'] : null;
            $secao_eleitoral = !empty($_POST['secao_eleitoral']) ? $_POST['secao_eleitoral'] : null;

            $foto_url = '';
            if (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
                $ext = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                    $novo_nome = uniqid('img_') . '.' . $ext;
                    $pasta_destino = 'img/solicitacoes/' . TENANT_ID . '/';
                    if (!is_dir($pasta_destino)) mkdir($pasta_destino, 0777, true);
                    if (uploadComprimido($_FILES['foto'], $pasta_destino . $novo_nome)) $foto_url = $pasta_destino . $novo_nome;
                }
            }

            $prot = date('Ymd') . '-' . rand(1000, 9999);
            
            $sql = "INSERT INTO solicitacoes (tenant_id, protocolo, nome_cidadao, email, whatsapp, sexo, nascimento, cidade, bairro, rua, numero, tipo, mensagem, foto, latitude, longitude, data_abertura) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $pdo->prepare($sql)->execute([TENANT_ID, $prot, $nome, $email, $whatsapp, $sexo, $nascimento, $cidade, $bairro, $rua, $numero, $tipo, $mensagem, $foto_url, $lat, $lng]);
            
            // --- INSERE ELEITOR COM ZONA E SEÇÃO ---
            $stmt_check = $pdo->prepare("SELECT id FROM eleitores WHERE tenant_id = ? AND whatsapp = ?");
            $stmt_check->execute([TENANT_ID, $whatsapp]);
            if ($stmt_check->rowCount() == 0) {
                $sql_eleitor = "INSERT INTO eleitores (tenant_id, nome, whatsapp, email, nascimento, sexo, bairro, rua, numero, cidade, tags, observacoes, zona_eleitoral, secao_eleitoral) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $pdo->prepare($sql_eleitor)->execute([TENANT_ID, $nome, $whatsapp, $email, $nascimento, $sexo, $bairro, $rua, $numero, $cidade, "Capturado via Gabinete Online", "Origem: $tipo", $zona_eleitoral, $secao_eleitoral]);
            }
            $sucesso = $prot;
            $aba_ativa = 'nova';

        } catch (Exception $e) { $erro = $e->getMessage(); }
    }

    // B. BUSCAR
    if (isset($_POST['acao']) && ($_POST['acao'] == 'buscar_solicitacao' || $_POST['acao'] == 'busca_persistente')) {
        $aba_ativa = 'acompanhar';
        $tela_acompanhar = 'lista';
        $identificacao = trim($_POST['identificacao'] ?? '');
        $identificacao_persistente = $identificacao;
        
        $termo_limpo = preg_replace('/[^0-9]/', '', $identificacao);
        $termo_original = $identificacao;

        try {
            $params = [':tenant' => TENANT_ID];
            $condicoes = ["email LIKE :email", "protocolo LIKE :proto"];
            $params[':email'] = "%$termo_original%";
            $params[':proto'] = "%$termo_original%";
            if (strlen($termo_limpo) >= 8) {
                $condicoes[] = "whatsapp LIKE :zap"; 
                $params[':zap'] = "%" . substr($termo_limpo, -8);
            }
            $sql_where = implode(' OR ', $condicoes);
            $stmt = $pdo->prepare("SELECT * FROM solicitacoes WHERE tenant_id = :tenant AND ($sql_where) ORDER BY data_abertura DESC"); 
            $stmt->execute($params);
            $historico = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
    }

    // C. VER DETALHE
    if (isset($_POST['acao']) && $_POST['acao'] == 'ver_detalhe') {
        $aba_ativa = 'acompanhar';
        $tela_acompanhar = 'detalhe';
        $identificacao_persistente = $_POST['identificacao_persistente'] ?? '';
        $id_pedido = $_POST['id_pedido'];
        
        $stmt = $pdo->prepare("SELECT * FROM solicitacoes WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$id_pedido, TENANT_ID]);
        $pedido_detalhe = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // D. AVALIAR
    if (isset($_POST['acao']) && $_POST['acao'] == 'avaliar') {
        $aba_ativa = 'acompanhar';
        $tela_acompanhar = 'detalhe';
        $identificacao_persistente = $_POST['identificacao_persistente'] ?? '';
        $id_pedido = $_POST['id_pedido'];
        $nota = $_POST['nota'];
        $comentario = $_POST['comentario_avaliacao'];

        try {
            $stmt = $pdo->prepare("UPDATE solicitacoes SET avaliacao_nota = ?, avaliacao_comentario = ? WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$nota, $comentario, $id_pedido, TENANT_ID]);
            
            $stmt = $pdo->prepare("SELECT * FROM solicitacoes WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id_pedido, TENANT_ID]);
            $pedido_detalhe = $stmt->fetch(PDO::FETCH_ASSOC);
            $msg_avaliacao = true;
        } catch (Exception $e) {}
    }
    
    // E. NOVA BUSCA (RESET)
    if (isset($_POST['acao']) && $_POST['acao'] == 'nova_busca_reset') {
        $aba_ativa = 'acompanhar';
        $tela_acompanhar = 'busca';
        $identificacao = '';
        $identificacao_persistente = '';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="<?= $cor_primaria ?>">
    <title><?= htmlspecialchars($nome_vereador) ?></title>
    
    <?php if(!empty($conf['logo_site'])): ?>
    <link rel="icon" href="<?= htmlspecialchars($conf['logo_site']) ?>">
    <?php endif; ?>
    
    <link rel="manifest" href="manifest.php">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
    
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <?php if(!empty($site_key)): ?>
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <?php endif; ?>

    <style>
        :root { --cor-primaria: <?= $cor_primaria ?>; }
        body { background-color: #f4f6f9; font-family: 'Segoe UI', sans-serif; padding-bottom: 80px; }
        
        .header-bg { 
            background: linear-gradient(135deg, var(--cor-primaria) 0%, #000 100%); 
            color: white; 
            padding: 40px 0 80px; 
            border-radius: 0 0 40px 40px; 
            margin-bottom: -50px; 
            box-shadow: 0 4px 20px rgba(0,0,0, 0.3); 
            position: relative; 
            z-index: 1; 
        }
        
        .card-form { border: none; border-radius: 20px; box-shadow: 0 15px 35px rgba(0,0,0,0.1); overflow: hidden; background: white; }
        .nav-pills-custom { background-color: rgba(255, 255, 255, 0.2); border-radius: 50px; padding: 5px; display: inline-flex; }
        .nav-pills-custom .btn { border-radius: 50px; padding: 10px 20px; font-weight: 600; border: none; transition: all 0.3s; color: rgba(255,255,255,0.8); }
        .btn-active-tab { background-color: white !important; color: var(--cor-primaria) !important; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .btn-inactive-tab:hover { color: white; background-color: rgba(255,255,255,0.1); }
        .map-container { height: 250px; width: 100%; border-radius: 12px; border: 2px solid #e9ecef; z-index: 1; }
        .section-title { color: var(--cor-primaria); font-weight: 800; font-size: 1.1rem; text-transform: uppercase; margin-bottom: 15px; border-bottom: 2px solid #f0f2f5; padding-bottom: 5px; display: block; }
        .loading-gps { display: none; } .loading-gps.active { display: inline-block; }
        .btn-primary { background-color: var(--cor-primaria); border-color: var(--cor-primaria); }

        .step-progress { position: relative; padding-left: 40px; margin-top: 20px; }
        .step-progress::before { content: ''; position: absolute; left: 14px; top: 5px; bottom: 5px; width: 2px; background: #e9ecef; z-index: 0; }
        .step-item { position: relative; margin-bottom: 30px; }
        .step-icon { position: absolute; left: -40px; top: 0; width: 30px; height: 30px; background: white; border: 2px solid #e9ecef; border-radius: 50%; display: flex; align-items: center; justify-content: center; z-index: 1; }
        .step-item.active .step-icon { border-color: #198754; background: white; }
        .step-item.active .step-icon::after { content: ''; width: 14px; height: 14px; background: #198754; border-radius: 50%; }
        .rating { display: flex; flex-direction: row-reverse; justify-content: center; font-size: 2rem; }
        .rating input { display: none; }
        .rating label { color: #ddd; cursor: pointer; transition: 0.2s; }
        .rating input:checked ~ label, .rating label:hover, .rating label:hover ~ label { color: #ffc107; }
        .lista-item { transition: 0.2s; border: 1px solid #eee; border-radius: 15px; margin-bottom: 15px; background: white; cursor: pointer; }
        .lista-item:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,0,0,0.05); border-color: var(--cor-primaria); }
    </style>
</head>
<body>

    <div class="header-bg text-center">
        <div class="container">
            <?php 
                $tem_banner = (!empty($banner_gabinete) && file_exists($banner_gabinete)); 
            ?>

            <?php if($tem_banner): ?>
                <div class="mb-4 d-flex justify-content-center">
                    <img src="<?= htmlspecialchars($banner_gabinete) ?>" alt="Banner do Gabinete" class="img-fluid rounded-4 shadow-sm" style="max-height: 180px; width: 100%; max-width: 700px; object-fit: cover;">
                </div>
            <?php else: ?>
                <h1 class="fw-bold mb-1"><i class="fas fa-bullhorn"></i> <?= htmlspecialchars($nome_vereador) ?></h1>
                <p class="lead opacity-75 mb-4" style="font-size: 1rem;">Seu canal direto com o mandato.</p>
            <?php endif; ?>

            <div class="nav-pills-custom">
                <button onclick="mudarAba('nova')" id="tab-nova" class="btn <?= $aba_ativa == 'nova' ? 'btn-active-tab' : 'btn-inactive-tab' ?>">Nova Solicitação</button>
                <button onclick="mudarAba('acompanhar')" id="tab-acompanhar" class="btn <?= $aba_ativa == 'acompanhar' ? 'btn-active-tab' : 'btn-inactive-tab' ?>">Acompanhar</button>
            </div>
        </div>
    </div>
    
    <div class="container mb-5 position-relative" style="z-index: 10;">
        
        <?php if($sucesso): ?>
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="card card-form p-4 text-center mt-4 animate__animated animate__fadeIn">
                        <div class="mb-3 text-success"><i class="fas fa-check-circle fa-4x"></i></div>
                        <h2 class="fw-bold text-success">Recebido!</h2>
                        <p class="text-muted">Sua solicitação foi enviada com sucesso.</p>
                        
                        <div class="bg-light p-3 rounded-4 border my-4">
                            <small class="text-uppercase fw-bold text-muted d-block mb-1">Seu Protocolo</small>
                            <h3 class="fw-bold text-dark mb-0"><?= $sucesso ?></h3>
                        </div>

                        <div class="d-grid gap-2 d-md-flex justify-content-center">
                            <form method="POST" class="m-0">
                                <input type="hidden" name="acao" value="buscar_solicitacao">
                                <input type="hidden" name="identificacao" value="<?= $sucesso ?>">
                                <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold">
                                    <i class="fas fa-search me-2"></i> Acompanhar Agora
                                </button>
                            </form>
                            <a href="index.php" class="btn btn-outline-primary rounded-pill px-4 fw-bold">Nova Solicitação</a>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>

            <?php if($erro): ?>
                <div class="alert alert-danger text-center shadow-sm rounded-4 mt-4"><?= $erro ?></div>
            <?php endif; ?>

            <div id="conteudo-nova" style="display: <?= $aba_ativa == 'nova' ? 'block' : 'none' ?>;">
                <div class="row justify-content-center">
                    <div class="col-lg-8">
                        <div class="card card-form p-3 p-md-5">
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="acao" value="nova_solicitacao">
                                <div class="mb-4">
                                    <h4 class="section-title">1. Seus Dados</h4>
                                    <div class="row g-2">
                                        <div class="col-12"><input type="text" name="nome" class="form-control" required placeholder="Seu Nome Completo"></div>
                                        <div class="col-12"><input type="text" name="whatsapp" class="form-control" required placeholder="WhatsApp (Com DDD)"></div>
                                        <div class="col-6">
                                            <select name="sexo" class="form-select text-muted" required>
                                                <option value="" selected disabled>Gênero / Sexo</option>
                                                <option value="Masculino">Masculino</option>
                                                <option value="Feminino">Feminino</option>
                                                <option value="Outro">Outro</option>
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <div class="form-floating">
                                                <input type="date" name="nascimento" id="inpNasc" class="form-control" required max="<?= date('Y')-1 ?>-12-31">
                                                <label for="inpNasc" class="text-muted small">Data Nascimento</label>
                                            </div>
                                        </div>
                                        <div class="col-12"><input type="email" name="email" class="form-control" required placeholder="Seu melhor E-mail"></div>
                                        <?php if($modo_mapa == 'estado'): ?>
                                            <div class="col-12"><select name="cidade" id="selectCidade" class="form-select" required onchange="focarCidade(this.value)"><option value="">Selecione sua cidade...</option></select></div>
                                        <?php endif; ?>
                                        
                                        <!-- DADOS ELEITORAIS (DIGITAÇÃO MANUAL) -->
<div class="col-6">
    <div class="form-floating">
        <input type="number" name="zona_eleitoral" id="inpZona" class="form-control" placeholder="Apenas números" min="1" max="999">
        <label for="inpZona" class="text-muted small">Zona Eleitoral (Opcional)</label>
    </div>
</div>
<div class="col-6">
    <div class="form-floating">
        <input type="number" name="secao_eleitoral" id="inpSecao" class="form-control" placeholder="Apenas números" min="1" max="9999">
        <label for="inpSecao" class="text-muted small">Seção Eleitoral (Opcional)</label>
    </div>
</div>
<div class="col-12 mt-1">
    <small class="text-muted fst-italic"><i class="fas fa-info-circle me-1"></i> Preencha apenas com números se souber o seu local de votação.</small>
</div>
<!-- FIM DADOS ELEITORAIS -->
                                        </div>
                                        </div>
                                </div>
                                <div class="mb-4">
                                    <h4 class="section-title">2. Local da Solicitação</h4>
                                    <div class="row g-2">
                                        <div class="col-6"><input type="text" name="bairro" id="inputBairro" class="form-control" required placeholder="Bairro"></div>
                                        <div class="col-6"><input type="text" name="rua" id="inputRua" class="form-control" required placeholder="Rua"></div>
                                        <div class="col-4"><input type="text" name="numero" class="form-control" placeholder="Nº"></div>
                                        <div class="col-8 d-grid"><button type="button" class="btn btn-outline-primary btn-sm rounded-pill" onclick="pegarGPS()"><i class="fas fa-map-marker-alt"></i> <span id="gps-txt">Usar GPS</span> <span class="spinner-border spinner-border-sm loading-gps ms-1" id="loading"></span></button></div>
                                        <div class="col-12 mt-2"><div id="map" class="map-container"></div><input type="hidden" name="latitude" id="lat"><input type="hidden" name="longitude" id="lng"></div>
                                    </div>
                                </div>
                                <div class="mb-4">
                                    <h4 class="section-title">3. Detalhes</h4>
                                    <div class="mb-3"><select name="tipo" class="form-select fw-bold text-primary"><option>Iluminação Pública</option><option>Buraco na Rua</option><option>Limpeza / Capina</option><option>Saúde</option><option>Educação</option><option>Segurança</option><option>Trânsito</option><option>Outros</option></select></div>
                                    <div class="mb-3"><textarea name="mensagem" class="form-control" rows="3" required placeholder="Descreva o problema aqui..."></textarea></div>
                                    <div class="mb-3"><label class="form-label small fw-bold text-secondary">Foto (Opcional)</label><input type="file" name="foto" class="form-control" accept="image/*"></div>
                                    <?php if(!empty($site_key)): ?><div class="mb-3 d-flex justify-content-center"><div class="g-recaptcha" data-sitekey="<?= $site_key ?>"></div></div><?php endif; ?>
                                </div>
                                <button type="submit" class="btn btn-primary w-100 py-3 fw-bold rounded-pill shadow fs-5">ENVIAR SOLICITAÇÃO</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div id="conteudo-acompanhar" style="display: <?= $aba_ativa == 'acompanhar' ? 'block' : 'none' ?>;">
                <div class="row justify-content-center">
                    <div class="col-lg-8">
                        
                        <?php if($tela_acompanhar == 'busca'): ?>
                        <div class="card card-form p-4 mb-4">
                            <form method="POST">
                                <input type="hidden" name="acao" value="buscar_solicitacao">
                                <label class="fw-bold text-muted small mb-2 text-uppercase">Digite seu WhatsApp ou Protocolo</label>
                                <div class="input-group input-group-lg">
                                    <input type="text" name="identificacao" class="form-control bg-light" placeholder="Ex: 37999998888" value="<?= htmlspecialchars($identificacao_persistente) ?>" required>
                                    <button class="btn btn-primary fw-bold" type="submit">BUSCAR</button>
                                </div>
                            </form>
                        </div>
                        <?php endif; ?>

                        <?php if($tela_acompanhar == 'lista'): ?>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="fw-bold text-secondary m-0">Resultados</h5>
                                <form method="POST" class="m-0">
                                    <input type="hidden" name="acao" value="nova_busca_reset">
                                    <button class="btn btn-sm btn-outline-primary fw-bold"><i class="fas fa-search me-1"></i> Nova Busca</button>
                                </form>
                            </div>
                            <?php if (count($historico) > 0): ?>
                                <?php foreach($historico as $h): ?>
                                    <form method="POST" class="m-0">
                                        <input type="hidden" name="acao" value="ver_detalhe">
                                        <input type="hidden" name="id_pedido" value="<?= $h['id'] ?>">
                                        <input type="hidden" name="identificacao_persistente" value="<?= htmlspecialchars($identificacao_persistente) ?>">
                                        <button type="submit" class="lista-item w-100 text-start p-3 border-0 d-block shadow-sm">
                                            <div class="d-flex justify-content-between">
                                                <span class="badge bg-light text-dark border mb-2">#<?= $h['protocolo'] ?></span>
                                                <small class="text-muted"><?= date('d/m/Y', strtotime($h['data_abertura'] ?? $h['criado_em'])) ?></small>
                                            </div>
                                            <h6 class="fw-bold text-primary mb-1"><?= $h['tipo'] ?></h6>
                                            <div class="mt-2 d-flex justify-content-between align-items-center">
                                                <div>
                                                    <span class="badge bg-<?= obterCorStatus($h['status']) ?>"><?= $h['status'] ?></span>
                                                    <?php 
                                                    if ($h['status'] !== 'Concluído') {
                                                        $dt_abertura = !empty($h['data_abertura']) ? new DateTime($h['data_abertura']) : new DateTime($h['criado_em'] ?? 'now');
                                                        $dias = $dt_abertura->diff(new DateTime())->days;
                                                        $txt_tempo = ($dias == 0) ? 'Hoje' : $dias . ' dias';
                                                        echo '<span class="badge bg-light text-dark border ms-1"><i class="fas fa-clock text-muted"></i> ' . $txt_tempo . '</span>';
                                                    }
                                                    
                                                    // Decodifica a string para saber quantos anexos existem
                                                    if (!empty($h['anexo_resposta'])) {
                                                        $dec_list = json_decode($h['anexo_resposta'], true);
                                                        $qtd_anx = is_array($dec_list) ? count($dec_list) : 1;
                                                        echo '<span class="badge bg-light text-dark border ms-1"><i class="fas fa-paperclip"></i> ' . $qtd_anx . ' Anexo(s)</span>';
                                                    }
                                                    ?>
                                                </div>
                                                <span class="text-primary small fw-bold">Detalhes <i class="fas fa-chevron-right"></i></span>
                                            </div>
                                        </button>
                                    </form>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-5 text-muted">
                                    <i class="fas fa-inbox fa-3x opacity-25"></i>
                                    <p class="mt-3">Nenhum pedido encontrado.</p>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if($tela_acompanhar == 'detalhe' && $pedido_detalhe): 
                            $st = $pedido_detalhe['status'];
                            $concluido = (stripos($st, 'Conclu') !== false);
                            $em_analise = ($st != 'Pendente');
                            $cor_badge_principal = obterCorStatus($st);
                        ?>
                            <div class="card card-form p-4">
                                <?php if($msg_avaliacao): ?>
                                    <div class="alert alert-success"><i class="fas fa-star me-2"></i> Obrigado por avaliar nosso atendimento!</div>
                                <?php endif; ?>

                                <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-4">
                                    <div>
                                        <h4 class="fw-bold text-dark mb-1"><?= htmlspecialchars($pedido_detalhe['tipo']) ?></h4>
                                        <p class="text-muted mb-0 small"><i class="fas fa-map-marker-alt me-1 text-danger"></i> <?= htmlspecialchars($pedido_detalhe['bairro']) ?></p>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-light text-dark border mb-1 d-block">#<?= $pedido_detalhe['protocolo'] ?></span>
                                        <span class="badge bg-<?= $cor_badge_principal ?>"><?= $st ?></span>
                                        <?php 
                                        if ($st !== 'Concluído') {
                                            $dt_abertura_det = !empty($pedido_detalhe['data_abertura']) ? new DateTime($pedido_detalhe['data_abertura']) : new DateTime($pedido_detalhe['criado_em'] ?? 'now');
                                            $dias_det = $dt_abertura_det->diff(new DateTime())->days;
                                            $txt_tempo_det = ($dias_det == 0) ? 'Hoje' : $dias_det . ' dias';
                                            echo '<span class="badge bg-light text-dark border mt-1 d-block"><i class="fas fa-clock text-muted"></i> ' . $txt_tempo_det . '</span>';
                                        }
                                        ?>
                                    </div>
                                </div>

                                <div class="step-progress">
                                    <div class="step-item active">
                                        <div class="step-icon"></div>
                                        <div class="step-content"><h6 class="text-success mb-0">Recebida</h6></div>
                                    </div>
                                    <div class="step-item <?= $em_analise ? 'active' : '' ?>">
                                        <div class="step-icon"></div>
                                        <div class="step-content"><h6 class="<?= $em_analise ? 'text-success' : 'text-muted' ?> mb-0">Análise</h6></div>
                                    </div>
                                    <div class="step-item <?= $concluido ? 'active' : '' ?>">
                                        <div class="step-icon"></div>
                                        <div class="step-content">
                                            <h6 class="<?= $concluido ? ($st == 'Concluído Pendente' ? 'text-info' : 'text-success') : 'text-muted' ?> mb-0">
                                                <?= $concluido ? $st : 'Concluído' ?>
                                            </h6>
                                        </div>
                                    </div>
                                </div>

                                <?php if(!empty($pedido_detalhe['resposta_admin'])): ?>
                                    <div class="alert alert-light border mt-4">
                                        <h6 class="fw-bold"><i class="fas fa-comment-dots text-primary"></i> Resposta:</h6>
                                        <p class="mb-2 text-secondary"><?= nl2br(htmlspecialchars($pedido_detalhe['resposta_admin'])) ?></p>
                                        
                                        <?php if(!empty($pedido_detalhe['anexo_resposta'])): ?>
                                            <hr>
                                            <h6 class="fw-bold mb-2"><i class="fas fa-paperclip"></i> Anexos da Resposta:</h6>
                                            <div class="d-flex flex-column gap-2">
                                                <?php 
                                                $anexos_front = [];
                                                $dec_front = json_decode($pedido_detalhe['anexo_resposta'], true);
                                                $anexos_front = is_array($dec_front) ? $dec_front : [$pedido_detalhe['anexo_resposta']];
                                                
                                                foreach($anexos_front as $index => $anx): 
                                                    $ext = strtolower(pathinfo($anx, PATHINFO_EXTENSION));
                                                    $icon = in_array($ext, ['pdf']) ? 'fa-file-pdf' : 'fa-file-image';
                                                ?>
                                                    <a href="<?= htmlspecialchars($anx) ?>" target="_blank" class="btn btn-outline-danger w-100 fw-bold text-start text-truncate">
                                                        <i class="fas <?= $icon ?> me-2"></i> Visualizar Anexo <?= count($anexos_front) > 1 ? ($index + 1) : '' ?>
                                                    </a>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if($concluido): ?>
                                    <div class="mt-4 p-3 bg-light rounded border">
                                        <?php if(empty($pedido_detalhe['avaliacao_nota'])): ?>
                                            <h6 class="fw-bold text-center mb-3">Avalie nosso atendimento</h6>
                                            <form method="POST">
                                                <input type="hidden" name="acao" value="avaliar">
                                                <input type="hidden" name="id_pedido" value="<?= $pedido_detalhe['id'] ?>">
                                                <input type="hidden" name="identificacao_persistente" value="<?= htmlspecialchars($identificacao_persistente) ?>">
                                                <div class="rating mb-3">
                                                    <input type="radio" name="nota" value="5" id="5"><label for="5">★</label>
                                                    <input type="radio" name="nota" value="4" id="4"><label for="4">★</label>
                                                    <input type="radio" name="nota" value="3" id="3"><label for="3">★</label>
                                                    <input type="radio" name="nota" value="2" id="2"><label for="2">★</label>
                                                    <input type="radio" name="nota" value="1" id="1" required><label for="1">★</label>
                                                </div>
                                                <textarea name="comentario_avaliacao" class="form-control mb-2" placeholder="Deixe um comentário (opcional)" rows="2"></textarea>
                                                <button class="btn btn-warning w-100 fw-bold text-dark">ENVIAR AVALIAÇÃO</button>
                                            </form>
                                        <?php else: ?>
                                            <div class="text-center text-warning fs-4"><?php for($i=0; $i<$pedido_detalhe['avaliacao_nota']; $i++) echo '★'; ?></div>
                                            <p class="text-center small text-muted">Você avaliou este atendimento.</p>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="text-center mt-4">
                                    <form method="POST">
                                        <input type="hidden" name="acao" value="busca_persistente">
                                        <input type="hidden" name="identificacao" value="<?= htmlspecialchars($identificacao_persistente) ?>">
                                        <button class="btn btn-outline-secondary rounded-pill px-4">Voltar à Lista</button>
                                    </form>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <button class="btn border-0 shadow-lg position-fixed fw-bold text-white d-flex align-items-center gap-2" 
            style="bottom: 25px; right: 25px; z-index: 1040; padding: 12px 24px; border-radius: 50px; background: linear-gradient(135deg, var(--cor-primaria), #000);"
            data-bs-toggle="offcanvas" data-bs-target="#pwaGuideOffcanvas">
        <i class="fas fa-download fa-lg"></i> Instalar App
    </button>

    <div class="offcanvas offcanvas-bottom shadow-lg" tabindex="-1" id="pwaGuideOffcanvas" style="height: auto; max-height: 90vh; border-top-left-radius: 25px; border-top-right-radius: 25px; border: none;">
        
        <div class="offcanvas-header text-white" id="pwaHeader" style="background: var(--cor-primaria); border-top-left-radius: 25px; border-top-right-radius: 25px; transition: background 0.4s;">
            <div class="d-flex align-items-center gap-2 w-100">
                <button class="btn text-white p-0 me-2 d-none" id="btnVoltarGuia" onclick="voltarSelecaoGuia()" style="border:none;"><i class="fas fa-arrow-left fa-lg"></i></button>
                <i class="fas fa-mobile-alt fa-lg"></i>
                <h6 class="offcanvas-title fw-bold m-0" id="offcanvasLabel">Adicionar à Tela Inicial</h6>
            </div>
            <button type="button" class="btn-close btn-close-white m-0" data-bs-dismiss="offcanvas" aria-label="Close" onclick="resetPwaGuide()"></button>
        </div>
        
        <div class="offcanvas-body px-4 py-4" id="guiaAppContent">
            </div>
    </div>

    <script>
        const pwaColorPrimary = '<?= $cor_primaria ?>';

        const passosAndroid = [
            { titulo: "Abra o Menu", descricao: "Toque nos três pontinhos (⋮) no canto superior direito do seu navegador Chrome.", icone: "fas fa-ellipsis-v", cor: "success" },
            { titulo: "Adicionar à Tela", descricao: "Role o menu para baixo e toque em 'Adicionar à tela inicial' ou 'Instalar aplicativo'.", icone: "fas fa-plus-square", cor: "success" },
            { titulo: "Confirme", descricao: "Toque em 'Adicionar' na janela que aparecer. O ícone aparecerá no seu celular!", icone: "fas fa-check-circle", cor: "success" }
        ];

        const passosIphone = [
            { titulo: "Compartilhar", descricao: "Na barra inferior do Safari, toque no ícone de compartilhar (quadrado com seta ↑).", icone: "fas fa-share-square", cor: "primary" },
            { titulo: "Adicionar à Tela", descricao: "Role o menu um pouco para baixo e toque em 'Adicionar à Tela de Início'.", icone: "fas fa-plus-square", cor: "primary" },
            { titulo: "Confirme", descricao: "No canto superior direito toque em 'Adicionar'. O ícone aparecerá no seu celular!", icone: "fas fa-check-circle", cor: "primary" }
        ];

        let pwaSistema = null;
        let pwaPasso = 0;

        function renderizarTelaSelecao() {
            document.getElementById('guiaAppContent').innerHTML = `
                <div class="text-center mb-4">
                    <h5 class="fw-bold text-dark mb-1">Qual é o seu celular?</h5>
                    <p class="text-muted small">Vamos te ensinar a instalar o nosso App na tela inicial.</p>
                </div>
                
                <button onclick="selecionarSistemaPwa('android')" class="btn btn-outline-success w-100 d-flex align-items-center text-start p-3 mb-3" style="border-radius: 15px; border-width: 2px;">
                    <div class="bg-success text-white rounded p-3 me-3"><i class="fab fa-android fa-xl"></i></div>
                    <div class="flex-grow-1">
                        <strong class="d-block fs-5">Android</strong>
                        <small class="text-muted">Samsung, Motorola, Xiaomi...</small>
                    </div>
                    <i class="fas fa-chevron-right text-muted"></i>
                </button>

                <button onclick="selecionarSistemaPwa('iphone')" class="btn btn-outline-primary w-100 d-flex align-items-center text-start p-3 mb-3" style="border-radius: 15px; border-width: 2px;">
                    <div class="bg-primary text-white rounded p-3 me-3"><i class="fab fa-apple fa-xl" style="padding: 0 2px;"></i></div>
                    <div class="flex-grow-1">
                        <strong class="d-block fs-5">iPhone (Apple)</strong>
                        <small class="text-muted">iPhone, iPad...</small>
                    </div>
                    <i class="fas fa-chevron-right text-muted"></i>
                </button>

                <button class="btn btn-link text-muted w-100 fw-bold text-decoration-none mt-2" data-bs-dismiss="offcanvas" onclick="resetPwaGuide()">Agora não</button>
            `;
        }

        function selecionarSistemaPwa(sys) {
            pwaSistema = sys;
            pwaPasso = 0;
            document.getElementById('pwaHeader').style.background = (sys === 'android') ? '#198754' : '#0d6efd';
            document.getElementById('btnVoltarGuia').classList.remove('d-none');
            renderizarPassosPwa();
        }

        function voltarSelecaoGuia() {
            resetPwaGuide();
            renderizarTelaSelecao();
        }

        function resetPwaGuide() {
            pwaSistema = null;
            pwaPasso = 0;
            document.getElementById('pwaHeader').style.background = pwaColorPrimary;
            document.getElementById('btnVoltarGuia').classList.add('d-none');
        }

        function renderizarPassosPwa() {
            const passos = pwaSistema === 'android' ? passosAndroid : passosIphone;
            const dados = passos[pwaPasso];
            const cor = dados.cor;

            let dots = '<div class="d-flex justify-content-center gap-2 mb-4">';
            passos.forEach((_, i) => {
                dots += `<div class="rounded-pill ${i === pwaPasso ? 'bg-'+cor : 'bg-secondary opacity-25'}" style="height: 8px; width: ${i === pwaPasso ? '30px' : '8px'}; transition: 0.3s;"></div>`;
            });
            dots += '</div>';

            let botoes = '<div class="d-flex gap-3 w-100 mt-4">';
            if (pwaPasso > 0) {
                botoes += `<button onclick="pwaPasso--; renderizarPassosPwa()" class="btn btn-outline-secondary fw-bold flex-grow-1 py-2" style="border-radius: 12px;"><i class="fas fa-arrow-left me-1"></i> Anterior</button>`;
            }
            if (pwaPasso < passos.length - 1) {
                botoes += `<button onclick="pwaPasso++; renderizarPassosPwa()" class="btn btn-${cor} fw-bold text-white flex-grow-1 py-2" style="border-radius: 12px;">Próximo <i class="fas fa-arrow-right ms-1"></i></button>`;
            } else {
                botoes += `<button data-bs-dismiss="offcanvas" onclick="resetPwaGuide()" class="btn btn-${cor} fw-bold text-white flex-grow-1 py-2" style="border-radius: 12px;"><i class="fas fa-check-circle me-1"></i> Entendi!</button>`;
            }
            botoes += '</div>';

            document.getElementById('guiaAppContent').innerHTML = `
                ${dots}
                <div class="text-center px-2">
                    <div class="d-inline-flex align-items-center justify-content-center bg-${cor} bg-opacity-10 text-${cor} rounded-4 mb-4" style="width: 80px; height: 80px;">
                        <i class="${dados.icone} fa-3x"></i>
                    </div>
                    <p class="text-muted small fw-bold text-uppercase tracking-widest mb-2">Passo ${pwaPasso + 1} de ${passos.length}</p>
                    <h4 class="fw-bold text-dark mb-3">${dados.titulo}</h4>
                    <p class="text-muted" style="font-size: 1.05rem;">${dados.descricao}</p>
                </div>
                ${botoes}
            `;
        }

        // Renderiza a tela de seleção inicial assim que a página carrega
        renderizarTelaSelecao();
        
        // Garante que o guia seja resetado se o offcanvas for fechado clicando fora dele
        document.getElementById('pwaGuideOffcanvas').addEventListener('hidden.bs.offcanvas', function () {
            resetPwaGuide();
            renderizarTelaSelecao();
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    function buscarSecoesPublico(zona) {
        const secaoSelect = document.getElementById('secao_eleitoral');
        const infoLocal = document.getElementById('info_local_publico');

        secaoSelect.innerHTML = '<option value="">Carregando...</option>';
        secaoSelect.disabled = true;
        infoLocal.style.display = 'none';

        if(!zona) {
            secaoSelect.innerHTML = '<option value="">Seção (Opcional)</option>';
            return;
        }

        fetch('ajax_tse.php?acao=get_secoes&zona=' + zona)
            .then(response => response.json())
            .then(data => {
                secaoSelect.innerHTML = '<option value="">Qual a sua Seção?</option>';

                if(data.length === 0) {
                    secaoSelect.innerHTML = '<option value="">Nenhuma encontrada</option>';
                    return;
                }

                data.forEach(item => {
                    let option = document.createElement('option');
                    option.value = item.secao;
                    option.text = 'Seção ' + item.secao;
                    option.setAttribute('data-local', item.nome_local + ' - ' + item.bairro);
                    secaoSelect.appendChild(option);
                });

                secaoSelect.disabled = false;
            })
            .catch(e => {
                console.error(e);
                secaoSelect.innerHTML = '<option value="">Erro ao carregar</option>';
            });
    }

    function mostrarLocalPublico(selectElement) {
        const infoLocal = document.getElementById('info_local_publico');
        const textoLocal = document.getElementById('texto_local_publico');
        const opcaoSelecionada = selectElement.options[selectElement.selectedIndex];

        if(selectElement.value !== "") {
            textoLocal.innerText = opcaoSelecionada.getAttribute('data-local');
            infoLocal.style.display = 'block';
        } else {
            infoLocal.style.display = 'none';
        }
    }
    </script>

    <script>
    // --- 1. CONFIGURAÇÕES INICIAIS ---
    const modo = '<?= $modo_mapa ?>';
    const uf = '<?= $estado_uf ?>';
    const latDefault = <?= $lat_city ?>;
    const lngDefault = <?= $lng_city ?>;
    var map, marker;

    if (document.getElementById('map')) {
        var startLat = -18.5122; var startLng = -44.5550; var startZoom = 6;
        if (modo === 'coordenadas') { 
            startLat = latDefault; startLng = lngDefault; startZoom = 14; 
        }

        map = L.map('map').setView([startLat, startLng], startZoom);
        
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { 
            attribution: '© OpenStreetMap' 
        }).addTo(map);

        map.on('locationfound', function(e) {
            document.getElementById('loading').classList.remove('active');
            document.getElementById('gps-txt').innerText = "Usar GPS";
            setPino(e.latlng.lat, e.latlng.lng); 
            map.setView(e.latlng, 16); 
        });

        map.on('locationerror', function(e) {
            document.getElementById('loading').classList.remove('active');
            document.getElementById('gps-txt').innerText = "Usar GPS";
            alert("Não conseguimos pegar sua localização via GPS.");
        });

        map.on('click', function(e) { setPino(e.latlng.lat, e.latlng.lng); });
    }

    function pegarGPS() {
        const btn = document.getElementById('gps-txt'); 
        const load = document.getElementById('loading');
        btn.innerText = "Buscando..."; 
        load.classList.add('active');
        map.locate({ setView: true, maxZoom: 16, enableHighAccuracy: true, timeout: 10000 });
    }

    function setPino(lat, lng) {
        if(marker) map.removeLayer(marker);
        marker = L.marker([lat, lng]).addTo(map);
        document.getElementById('lat').value = lat; 
        document.getElementById('lng').value = lng;
        
        fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`)
            .then(response => response.json())
            .then(data => {
                if(data.address) {
                    if(data.address.road) document.getElementById('inputRua').value = data.address.road;
                    if(data.address.suburb || data.address.neighbourhood) document.getElementById('inputBairro').value = (data.address.suburb || data.address.neighbourhood);
                }
            });
    }

    function mudarAba(aba) {
        const c_nova = document.getElementById('conteudo-nova');
        const c_acompanhar = document.getElementById('conteudo-acompanhar');
        const t_nova = document.getElementById('tab-nova');
        const t_acompanhar = document.getElementById('tab-acompanhar');

        if(c_nova) c_nova.style.display = 'none'; 
        if(c_acompanhar) c_acompanhar.style.display = 'none';
        
        t_nova.className = 'btn btn-inactive-tab'; 
        t_acompanhar.className = 'btn btn-inactive-tab';
        
        const content = document.getElementById('conteudo-' + aba);
        if(content) content.style.display = 'block'; 
        document.getElementById('tab-' + aba).className = 'btn btn-active-tab';
        
        if(aba === 'nova' && map) setTimeout(function(){ map.invalidateSize(); }, 200); 
    }

    if(document.getElementById('selectCidade')) {
        fetch(`https://servicodados.ibge.gov.br/api/v1/localidades/estados/${uf}/municipios`)
            .then(r => r.json())
            .then(cidades => {
                cidades.sort((a, b) => a.nome.localeCompare(b.nome)); 
                const sel = document.getElementById('selectCidade');
                cidades.forEach(c => sel.innerHTML += `<option value="${c.nome}">${c.nome}</option>`);
            });
    }

    async function focarCidade(nome) {
        if(!nome) return;
        const res = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(nome + ', ' + uf + ', Brazil')}`);
        const data = await res.json();
        if(data.length > 0) map.setView([data[0].lat, data[0].lon], 14);
    }
    </script>
</body>
</html>