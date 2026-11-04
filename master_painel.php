<?php

session_start();

require 'config.php';



// --- SEGURANÇA ---

if (!isset($_SESSION['permissoes']) || !in_array('master', $_SESSION['permissoes'])) {

    header("Location: login.php"); 

    exit;

}



// ==========================================================

// 0. ATUALIZAÇÃO AUTOMÁTICA DO DB (Cria as colunas sem dar erro)

// ==========================================================

try {

    $pdo->query("CREATE TABLE IF NOT EXISTS saas_config (id INT AUTO_INCREMENT PRIMARY KEY, chave VARCHAR(50) UNIQUE, valor TEXT)");

    $check = $pdo->query("SELECT id FROM saas_config WHERE chave = 'hero_img'");

    if ($check->rowCount() == 0) $pdo->query("INSERT INTO saas_config (chave, valor) VALUES ('hero_img', '')");

    

    // Atualiza tabela de Banners

    $colunas_banners = $pdo->query("DESCRIBE login_banners")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('data_inicio', $colunas_banners)) $pdo->query("ALTER TABLE login_banners ADD COLUMN data_inicio DATE NULL");

    if (!in_array('data_fim', $colunas_banners)) $pdo->query("ALTER TABLE login_banners ADD COLUMN data_fim DATE NULL");

    if (!in_array('tipo', $colunas_banners)) $pdo->query("ALTER TABLE login_banners ADD COLUMN tipo VARCHAR(20) DEFAULT 'login'"); 



    // Atualiza tabela de Planos (Para suportar Textos e Badges customizados)

    $colunas_planos = $pdo->query("DESCRIBE planos")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('badge', $colunas_planos)) $pdo->query("ALTER TABLE planos ADD COLUMN badge VARCHAR(50) NULL");

    if (!in_array('preco_texto', $colunas_planos)) $pdo->query("ALTER TABLE planos ADD COLUMN preco_texto VARCHAR(50) NULL");



} catch (Exception $e) { }



// ==========================================================

// 1. FUNÇÕES AUXILIARES

// ==========================================================

function formatBytes($bytes, $precision = 2) { 

    $units = array('B', 'KB', 'MB', 'GB', 'TB'); 

    $bytes = max($bytes, 0); 

    $pow = floor(($bytes ? log($bytes) : 0) / log(1024)); 

    $pow = min($pow, count($units) - 1); 

    $bytes /= pow(1024, $pow); 

    return round($bytes, $precision) . ' ' . $units[$pow]; 

}



function getStatusBanner($inicio, $fim, $ativo) {

    if (!$ativo) return '<span class="badge bg-secondary">Inativo</span>';

    $hoje = date('Y-m-d');

    if ($inicio && $inicio > $hoje) return '<span class="badge bg-info">Agendado</span>';

    if ($fim && $fim < $hoje) return '<span class="badge bg-danger">Vencido</span>';

    return '<span class="badge bg-success">No Ar</span>';

}



function renderPreview($caminho) {

    $ext = strtolower(pathinfo($caminho, PATHINFO_EXTENSION));

    if (in_array($ext, ['mp4', 'webm', 'ogg'])) {

        return '<video src="'.$caminho.'" style="height: 48px; width: 80px; object-fit: cover; border-radius: 8px; background-color: #000;" class="shadow-sm" muted loop onmouseover="this.play()" onmouseout="this.pause()"></video>';

    } else {

        return '<img src="'.$caminho.'" style="height: 48px; width: 80px; object-fit: cover; border-radius: 8px;" class="shadow-sm">';

    }

}



// ==========================================================

// 2. AÇÕES DO SISTEMA

// ==========================================================

$msg_sistema = "";



// A. UPLOAD DE BANNER

if (isset($_POST['acao']) && $_POST['acao'] == 'subir_banner') {

    $target_dir = __DIR__ . "/uploads/banners/";

    if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);

    

    if (isset($_FILES["banner"]) && $_FILES["banner"]["error"] == 0) {

        $ext = strtolower(pathinfo($_FILES["banner"]["name"], PATHINFO_EXTENSION));

        $permitidos = ['jpg', 'jpeg', 'png', 'webp', 'mp4', 'webm'];



        if (in_array($ext, $permitidos)) {

            $nome_arquivo = "banner_" . time() . "." . $ext;

            $target_file = $target_dir . $nome_arquivo;

            $caminho_relativo = "uploads/banners/" . $nome_arquivo;

            $inicio = !empty($_POST['inicio']) ? $_POST['inicio'] : date('Y-m-d');

            $fim = !empty($_POST['fim']) ? $_POST['fim'] : null;

            $tipo = $_POST['tipo'] ?? 'login';



            if (move_uploaded_file($_FILES["banner"]["tmp_name"], $target_file)) {

                $stmt = $pdo->prepare("INSERT INTO login_banners (caminho_imagem, titulo, frase_apoio, data_inicio, data_fim, ativo, tipo) VALUES (?, ?, ?, ?, ?, 1, ?)");

                $stmt->execute([$caminho_relativo, $_POST['titulo'], $_POST['frase'], $inicio, $fim, $tipo]);

                

                if ($tipo == 'hero') {

                    $caminho_hero = '/' . ltrim($caminho_relativo, '/');

                    $pdo->prepare("INSERT INTO saas_config (chave, valor) VALUES ('hero_img', ?) ON DUPLICATE KEY UPDATE valor = ?")

                        ->execute([$caminho_hero, $caminho_hero]);

                }

                $msg_sistema = "<div class='alert alert-success mt-3'>Arquivo enviado!</div>";

            } else { $msg_sistema = "<div class='alert alert-danger mt-3'>Erro no upload.</div>"; }

        } else { $msg_sistema = "<div class='alert alert-warning mt-3'>Formato inválido.</div>"; }

    }

}



// B. EDITAR BANNER

if (isset($_POST['acao']) && $_POST['acao'] == 'editar_banner') {

    $id = (int)$_POST['id']; 

    $inicio = !empty($_POST['inicio']) ? $_POST['inicio'] : null; 

    $fim = !empty($_POST['fim']) ? $_POST['fim'] : null; 

    $ativo = isset($_POST['ativo']) ? 1 : 0;

    

    $pdo->prepare("UPDATE login_banners SET titulo=?, frase_apoio=?, data_inicio=?, data_fim=?, ativo=? WHERE id=?")->execute([$_POST['titulo'], $_POST['frase'], $inicio, $fim, $ativo, $id]);

    $msg_sistema = "<div class='alert alert-success mt-3'>Banner atualizado!</div>";

}



// C. EXCLUIR BANNER

if (isset($_GET['acao']) && $_GET['acao'] == 'del_banner') {

    $id = (int)$_GET['id'];

    $b = $pdo->query("SELECT caminho_imagem FROM login_banners WHERE id=$id")->fetch();

    if($b && file_exists(__DIR__.'/'.$b['caminho_imagem'])) unlink(__DIR__.'/'.$b['caminho_imagem']);

    $pdo->prepare("DELETE FROM login_banners WHERE id=?")->execute([$id]);

    header("Location: master_painel.php"); 

    exit;

}



// D. OUTRAS AÇÕES

if (isset($_POST['acao']) && $_POST['acao'] == 'limpar_fotos') {

    $dias=90; $agora=time(); $cont=0; $dir=__DIR__.'/uploads';

    if(is_dir($dir)){ 

        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)); 

        foreach($it as $f){ 

            if($f->isFile() && ($agora-$f->getMTime())>($dias*86400)){ 

                unlink($f->getRealPath()); 

                $cont++; 

            } 

        } 

    }

    $msg_sistema = "<div class='alert alert-success mt-3'>Limpeza: $cont arquivos removidos.</div>";

}



// SALVAR PLANO COM BADGES E PREÇOS CUSTOMIZADOS

if (isset($_POST['acao']) && $_POST['acao'] == 'salvar_plano') {

    $id = $_POST['id_plano'] ?? ''; 

    $slug = $_POST['slug'] ?: strtolower(preg_replace('/[^a-zA-Z0-9]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $_POST['nome']))); 

    $json = json_encode($_POST['modulos'] ?? []);

    

    $preco_raw = str_replace(',', '.', $_POST['preco']);

    $preco = is_numeric($preco_raw) ? (float)$preco_raw : 0.00;

    

    // Captura os novos campos

    $badge = trim($_POST['badge']);

    $preco_texto = trim($_POST['preco_texto']);



    if($id) {

        $pdo->prepare("UPDATE planos SET nome=?, slug=?, preco=?, limite_eleitores=?, dias_validade=?, beneficios=?, modulos=?, badge=?, preco_texto=? WHERE id=?")

            ->execute([$_POST['nome'], $slug, $preco, $_POST['limite'], $_POST['dias'], $_POST['beneficios'], $json, $badge, $preco_texto, $id]);

    } else {

        $pdo->prepare("INSERT INTO planos (nome, slug, preco, limite_eleitores, dias_validade, beneficios, modulos, badge, preco_texto) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")

            ->execute([$_POST['nome'], $slug, $preco, $_POST['limite'], $_POST['dias'], $_POST['beneficios'], $json, $badge, $preco_texto]);

    }

    header("Location: master_painel.php"); 

    exit;

}



if (isset($_GET['acao']) && $_GET['acao'] == 'del_plano') { 

    $pdo->prepare("DELETE FROM planos WHERE id=?")->execute([(int)$_GET['id']]); 

    header("Location: master_painel.php"); 

    exit; 

}



if (isset($_POST['acao']) && $_POST['acao'] == 'editar_cliente') {

    $status = $_POST['status'];

    $ativo = ($status == 'ativo') ? 1 : 0;

    $pdo->prepare("
        UPDATE tenants
        SET
            nome = ?,
            plano = ?,
            status = ?,
            ativo = ?,
            data_vencimento = ?
        WHERE id = ?
    ")->execute([
        trim($_POST['nome']),
        $_POST['plano'],
        $status,
        $ativo,
        $_POST['vencimento'],
        (int)$_POST['id']
    ]);

    header("Location: master_painel.php");
    exit;
}



if (isset($_GET['acao']) && $_GET['acao'] == 'excluir_cliente') { 

    $id = (int)$_GET['id']; 

    

    if($id > 1) { // Protege o admin master (ID 1)

        try {

            $pdo->beginTransaction();



            // 1. Remove usuários vinculados a este tenant

            $pdo->prepare("DELETE FROM usuarios WHERE tenant_id = ?")->execute([$id]);



            // 2. Remove configurações vinculadas

            $pdo->prepare("DELETE FROM configuracoes WHERE tenant_id = ?")->execute([$id]);



            // 3. Agora remove o tenant (cliente)

            $pdo->prepare("DELETE FROM tenants WHERE id = ?")->execute([$id]);



            $pdo->commit();

            header("Location: master_painel.php?msg=excluido"); 

            exit;

        } catch (Exception $e) {

            if ($pdo->inTransaction()) $pdo->rollBack();

            $msg_sistema = "<div class='alert alert-danger'>Erro ao excluir: " . $e->getMessage() . "</div>";

        }

    } 

}



if (isset($_GET['acao']) && $_GET['acao'] == 'login_rapido') { 

    $t = $pdo->query("SELECT * FROM tenants WHERE id=".(int)$_GET['id'])->fetch(); 

    if($t){ 

        $_SESSION['tenant_id']=$t['id']; 

        $_SESSION['tenant_nome']=$t['nome']; 

        $_SESSION['tenant_slug']=$t['slug']; 

        $_SESSION['nivel']='master'; 

        $_SESSION['permissoes']=['master']; 

        $p=$pdo->query("SELECT modulos FROM planos WHERE slug='{$t['plano']}'")->fetch(); 

        $_SESSION['modulos_plano']=json_decode($p['modulos']??'[]',true); 

        header("Location: admin_painel.php"); 

        exit; 

    } 

}



if (isset($_POST['acao']) && $_POST['acao'] == 'simular_ciclo') { 

    $id = (int)$_POST['id_simulacao']; 

    if($_POST['evento']=='pagamento_ok') {

        $pdo->prepare("UPDATE tenants SET status='ativo', ativo=1, data_vencimento=? WHERE id=?")->execute([date('Y-m-d', strtotime('+30 days')), $id]); 

    } else {

        $pdo->prepare("UPDATE tenants SET status='bloqueado', ativo=0, data_vencimento=? WHERE id=?")->execute([date('Y-m-d', strtotime('-1 day')), $id]); 

    }

    $msg_sistema = "<div class='alert alert-info mt-3'>Status atualizado.</div>"; 

}



if (isset($_POST['acao']) && $_POST['acao'] == 'config_global_eleicao') { 

    $pdo->prepare("UPDATE campanha_config SET data_eleicao = ?")->execute([$_POST['data_eleicao']]); 

    $msg_sistema = "<div class='alert alert-success mt-3'>Data atualizada!</div>"; 

}



// ==========================================================

// 3. BUSCA DE DADOS

// ==========================================================

$lista_login = []; $lista_hero = [];

try {

    $todos_banners = $pdo->query("SELECT * FROM login_banners ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

    foreach($todos_banners as $b) { 

        if(isset($b['tipo']) && $b['tipo'] == 'hero') {

            $lista_hero[] = $b; 

        } else {

            $lista_login[] = $b; 

        }

    }

} catch (Exception $e) { }



// DADOS DE TRÁFEGO

try {

    $online_agora = $pdo->query("SELECT COUNT(DISTINCT sessao_id) FROM visitas WHERE ultimo_ping >= NOW() - INTERVAL 5 MINUTE")->fetchColumn();

    $visitas_hoje = $pdo->query("SELECT COUNT(*) FROM visitas WHERE DATE(data_acesso) = CURDATE()")->fetchColumn();

    $top_cidades = $pdo->query("SELECT cidade, estado, COUNT(*) as qtd FROM visitas GROUP BY cidade, estado ORDER BY qtd DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

    $stmtDev = $pdo->query("SELECT dispositivo, COUNT(*) as qtd FROM visitas GROUP BY dispositivo");

    $dispositivos = $stmtDev->fetchAll(PDO::FETCH_KEY_PAIR);

    $total_dev = array_sum($dispositivos);

    $pc_mobile = isset($dispositivos['Mobile']) ? round(($dispositivos['Mobile'] / ($total_dev ?: 1)) * 100) : 0;

    $pc_desktop = 100 - $pc_mobile;

} catch (Exception $e) { 

    $online_agora = 0; $visitas_hoje = 0; $top_cidades = []; $pc_mobile = 0; $pc_desktop = 0; 

}



// DADOS GERAIS

$disk_total = disk_total_space(__DIR__); 

$disk_used = $disk_total - disk_free_space(__DIR__); 

$disk_percent = ($disk_used/$disk_total)*100; 

$status_disk = $disk_percent>80?'bg-danger':'bg-success';



// BUSA DE CLIENTES ATUALIZADA COM O LEFT JOIN PARA PEGAR O WHATSAPP

$clientes = $pdo->query("SELECT t.*, c.whatsapp_oficial FROM tenants t LEFT JOIN configuracoes c ON t.id = c.tenant_id WHERE t.slug != 'master' ORDER BY t.criado_em DESC")->fetchAll(PDO::FETCH_ASSOC);

$lista_planos = $pdo->query("SELECT * FROM planos ORDER BY preco ASC")->fetchAll(PDO::FETCH_ASSOC);



$data_eleicao_atual = $pdo->query("SELECT data_eleicao FROM campanha_config LIMIT 1")->fetchColumn();

if(!$data_eleicao_atual) $data_eleicao_atual = '2026-10-04';



$ativos=0; $bloqueados=0; $receita=0; $grafico=[];

foreach($clientes as $c){ 

    if($c['status']=='ativo'){

        $ativos++; 

        foreach($lista_planos as $p) {

            if($p['slug']==$c['plano']) $receita+=$p['preco'];

        }

    } else {

        $bloqueados++; 

    }

    $grafico[ucfirst($c['plano'])] = ($grafico[ucfirst($c['plano'])]??0)+1; 

}



// LISTA DE MÓDULOS COM ÍCONES PARA O MODAL

$modulos_disponiveis = [
    'designer'        => '🎨 Designer Express',
    'oficios_ia'      => '🤖 Redator de Ofícios com IA',
    'campanha'        => '📢 Envolvimento',
    'campanha_digital' => '🌐 Campanha Digital',
    'mapa'            => '🗺️ Mapa Geo',
    'agenda_interna'  => '🗓️ Agenda da Equipe',
    'liderancas'      => '🤝 Lideranças',
    'noticias'        => '📰 Site/Notícias',
    'ouvidoria'       => '📣 Ouvidoria',
    'eleitores'       => '👥 CRM Eleitores',
    'atendimentos'    => '🎧 CRM Atendimento', 
    'aniversariantes' => '🎂 Aniversariantes',
    'agenda'          => '📅 Agenda',
    'projetos'        => '⚖️ Projetos Lei',
    'enquetes'        => '📊 Enquetes',
    'landing'         => '🚀 Landing Pages',
    'peticoes'        => '✍️ Abaixo-Assinados', 
    'templates'       => '🎨 Modelos',
    'push'            => '🔔 Notificações Push',
    'transparencia'   => '🧾 Prestação de Contas', // <-- Atualizado aqui (antiga Transparência)
    'qrcode'          => '📱 Placa QR',
    'links'           => '🔗 Encurtador',
    'relatorios'      => '📈 Relatórios',
    'financeiro'      => '💰 Financeiro',
    'cac_politico'    => '🎯 Custo por Voto',    
    'marketing'       => '📧 E-mail Mkt',
    'equipe'          => '🛡️ Equipe',
    'auditoria'       => '👁️ Auditoria',
    'config'          => '⚙️ Configurações',
    'tarefas'         => '✅ Tarefas e Demandas' 
];
?>

<!DOCTYPE html>

<html lang="pt-br">

<head>

    <meta charset="UTF-8">

    <title>Painel Master | Gabinete Digital</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>

        :root { --primary: #4f46e5; --bg-body: #f3f4f6; --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }

        body { background-color: var(--bg-body); font-family: 'Inter', sans-serif; color: #374151; overflow-x: hidden; }

        .sidebar { min-height: 100vh; background: #1e1b4b; color: #fff; position: fixed; width: 260px; left: 0; top: 0; padding-top: 25px; z-index: 1000; }

        .main-content { margin-left: 260px; padding: 40px; }

        .sidebar .nav-link { color: rgba(255,255,255,0.7); padding: 14px 25px; font-weight: 500; transition: 0.3s; display: flex; align-items: center; gap: 10px; }

        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: #fff; background: rgba(255,255,255,0.1); border-left: 4px solid #6366f1; }

        .card-dash { border: none; border-radius: 16px; box-shadow: var(--card-shadow); background: #fff; overflow: hidden; }

        .stat-card { border-radius: 16px; color: white; padding: 1.5rem; position: relative; overflow: hidden; }

        .bg-gradient-primary { background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); }

        .bg-gradient-success { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }

        .bg-gradient-dark { background: linear-gradient(135deg, #1f2937 0%, #111827 100%); }

        .table thead th { background: #f9fafb; font-size: 0.75rem; text-transform: uppercase; color: #6b7280; letter-spacing: 0.05em; }

        .btn-primary { background-color: var(--primary); border-color: var(--primary); }

        .badge { padding: 0.5em 0.8em; border-radius: 6px; font-weight: 600; }

        @media (max-width: 768px) { .sidebar { display: none; } .main-content { margin-left: 0; padding: 20px; } }

    </style>

</head>

<body>



<div class="sidebar d-none d-md-block">

    <div class="px-4 mb-5 d-flex align-items-center gap-2">

        <div class="bg-white text-primary rounded p-1"><i class="fas fa-layer-group fa-lg"></i></div>

        <h5 class="fw-bold m-0 text-white tracking-wide">MASTER</h5>

    </div>

    <ul class="nav flex-column gap-1">

        <li class="nav-item"><a href="master_painel.php" class="nav-link active"><i class="fas fa-chart-pie me-2"></i> Dashboard</a></li>

        <li class="nav-item"><a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#modalConfigGlobal"><i class="fas fa-calendar-check me-2"></i> Data Eleição</a></li>

        <li class="nav-item"><a href="master_templates.php" class="nav-link"><i class="fas fa-palette me-2"></i> Modelos LP</a></li>

        <li class="nav-item"><a href="master_designer.php" class="nav-link"><i class="fas fa-palette me-2"></i> Modelos Canva</a></li>

        <li class="nav-item"><a href="master_cupons.php" class="nav-link"><i class="fas fa-ticket-alt me-2"></i> Cupons</a></li>

        <li class="nav-item"><a href="master_parceiros.php" class="nav-link"><i class="fas fa-ticket-alt me-2"></i> Parceiros</a></li>

        <li class="nav-item"><a href="master_novo_cliente.php" class="nav-link"><i class="fas fa-user-plus me-2"></i> Novo Cliente</a></li>

        <li class="nav-item"><a href="master_financeiro.php" class="nav-link"><i class="fas fa-file-invoice-dollar me-2"></i> Financeiro</a></li>

        <li class="nav-item">

            <a href="admin_gateways.php" class="nav-link text-white">

                <i class="fas fa-plug fa-fw"></i> Gateways

            </a>

        </li>

        <li class="nav-item"><a href="master_admin.php" class="nav-link text-warning"><i class="fas fa-lock fa-fw me-2"></i> Seg. Máxima</a></li>

        <li class="nav-item"><a href="#" class="nav-link" onclick="abrirModalLogs()"><i class="fas fa-file-code me-2"></i> Log MP</a></li>

        <li class="nav-item mt-4 pt-4 border-top border-secondary border-opacity-25"><a href="logout.php" class="nav-link text-danger"><i class="fas fa-sign-out-alt me-2"></i> Sair</a></li>

    </ul>

</div>



<div class="main-content">

    <?= $msg_sistema ?>

    

    <?php if(isset($_GET['msg']) && $_GET['msg'] == 'excluido'): ?>

        <div class="alert alert-success">Cliente e todos os seus dados foram removidos permanentemente!</div>

    <?php endif; ?>

    

    <div class="d-flex justify-content-between align-items-end mb-5">

        <div><h3 class="fw-bold text-dark mb-1">Visão Geral</h3><p class="text-muted m-0">Performance do Sistema</p></div>

        <div class="d-flex gap-2">

            <a href="master_importar_retorno.php" class="btn btn-light shadow-sm fw-bold"><i class="fas fa-upload me-2"></i> Retorno</a>

            <a href="master_exportar_lote.php" target="_blank" class="btn btn-primary shadow-sm fw-bold"><i class="fas fa-download me-2"></i> Baixar Lote</a>

        </div>

    </div>



    <div class="row g-4 mb-4">

        <div class="col-md-3">

            <div class="stat-card bg-gradient-primary shadow-lg h-100">

                <div class="d-flex justify-content-between align-items-center">

                    <div><h6 class="text-white-50 text-uppercase text-xs fw-bold mb-2">Online Agora</h6><h2 class="fw-bold text-white mb-0"><?= $online_agora ?></h2></div>

                    <i class="fas fa-user-clock fa-2x text-white-50"></i>

                </div>

            </div>

        </div>

        <div class="col-md-3">

            <div class="stat-card bg-gradient-success shadow-lg h-100">

                <div class="d-flex justify-content-between align-items-center">

                    <div><h6 class="text-white-50 text-uppercase text-xs fw-bold mb-2">Visitas Hoje</h6><h2 class="fw-bold text-white mb-0"><?= number_format($visitas_hoje,0,',','.') ?></h2></div>

                    <i class="fas fa-chart-line fa-2x text-white-50"></i>

                </div>

            </div>

        </div>

        <div class="col-md-3">

            <div class="stat-card bg-white shadow-sm border text-dark h-100">

                <h6 class="text-muted text-uppercase text-xs fw-bold mb-2">Ativos</h6>

                <h2 class="fw-bold text-success mb-0"><?= $ativos ?></h2>

            </div>

        </div>

        <div class="col-md-3">

            <div class="stat-card bg-white shadow-sm border text-dark h-100">

                <h6 class="text-muted text-uppercase text-xs fw-bold mb-2">MRR (Mensal)</h6>

                <h2 class="fw-bold text-warning mb-0">R$ <?= number_format($receita, 2, ',', '.') ?></h2>

            </div>

        </div>

    </div>



    <div class="row g-4 mb-5">

        <div class="col-md-6">

            <div class="card card-dash h-100">

                <div class="card-header border-0 pb-0 pt-3"><h6 class="fw-bold text-xs text-uppercase text-muted">Top Cidades (Hoje)</h6></div>

                <div class="card-body">

                    <ul class="list-group list-group-flush small">

                        <?php foreach($top_cidades as $city): ?>

                        <li class="list-group-item d-flex justify-content-between align-items-center border-0 px-0">

                            <span class="fw-bold text-dark"><?= $city['cidade'] ?> - <?= $city['estado'] ?></span>

                            <span class="badge bg-light text-dark border"><?= $city['qtd'] ?></span>

                        </li>

                        <?php endforeach; if(empty($top_cidades)): ?><li class="text-muted">Sem dados</li><?php endif; ?>

                    </ul>

                </div>

            </div>

        </div>

        <div class="col-md-6">

            <div class="card card-dash h-100 p-4">

                <h6 class="fw-bold text-xs text-uppercase text-muted mb-3">Dispositivos</h6>

                <div class="d-flex align-items-center mb-3">

                    <i class="fas fa-mobile-alt text-primary me-3 fa-lg"></i>

                    <div class="flex-grow-1">

                        <div class="d-flex justify-content-between small mb-1"><span>Mobile</span> <span><?= $pc_mobile ?>%</span></div>

                        <div class="progress" style="height: 10px;"><div class="progress-bar" style="width: <?= $pc_mobile ?>%"></div></div>

                    </div>

                </div>

                <div class="d-flex align-items-center">

                    <i class="fas fa-desktop text-secondary me-3 fa-lg"></i>

                    <div class="flex-grow-1">

                        <div class="d-flex justify-content-between small mb-1"><span>Desktop</span> <span><?= $pc_desktop ?>%</span></div>

                        <div class="progress" style="height: 10px;"><div class="progress-bar bg-secondary" style="width: <?= $pc_desktop ?>%"></div></div>

                    </div>

                </div>

            </div>

        </div>

    </div>



    <div class="row g-4 mb-5">

        <div class="col-lg-8">

            <div class="card card-dash h-100">

                <div class="card-header d-flex justify-content-between align-items-center">

                    <h6 class="fw-bold m-0 text-dark"><i class="fas fa-images me-2 text-primary"></i> Gestão de Banners</h6>

                    <button class="btn btn-sm btn-primary rounded-pill fw-bold px-3" onclick="new bootstrap.Modal(document.getElementById('modalBanner')).show()">+ Adicionar Novo</button>

                </div>

                <div class="card-body p-0">

                    <div class="px-3 py-2 bg-light border-bottom"><small class="fw-bold text-primary text-uppercase">Topo do Site (Hero)</small></div>

                    <div class="table-responsive">

                    <table class="table table-hover align-middle mb-0">

                        <thead class="bg-white text-muted"><tr><th class="ps-4">Preview</th><th>Info / Validade</th><th>Status</th><th class="text-end pe-4">Ações</th></tr></thead>

                        <tbody>

                            <?php foreach($lista_hero as $b): $status = getStatusBanner($b['data_inicio'], $b['data_fim'], $b['ativo']); $val = ($b['data_inicio']?date('d/m',strtotime($b['data_inicio'])):'Agora').' até '.($b['data_fim']?date('d/m',strtotime($b['data_fim'])):'Sempre'); ?>

                            <tr>

                                <td class="ps-4"><?= renderPreview($b['caminho_imagem']) ?></td>

                                <td><div class="fw-bold"><?= $b['titulo']?:'Hero Item' ?></div><small class="text-primary text-xs"><?= $val ?></small></td>

                                <td><?= $status ?></td>

                                <td class="text-end pe-4">

                                    <button class="btn btn-sm btn-light border me-1" onclick='editarBanner(<?= json_encode($b) ?>)'><i class="fas fa-pen"></i></button>

                                    <a href="?acao=del_banner&id=<?= $b['id'] ?>" class="btn btn-sm btn-light border text-danger" onclick="return confirm('Excluir?')"><i class="fas fa-trash"></i></a>

                                </td>

                            </tr>

                            <?php endforeach; if(empty($lista_hero)): ?><tr><td colspan="4" class="text-center py-4 text-muted small">Vazio</td></tr><?php endif; ?>

                        </tbody>

                    </table>

                    </div>



                    <div class="px-3 py-2 bg-light border-top border-bottom mt-3"><small class="fw-bold text-secondary text-uppercase">Tela de Login</small></div>

                    <div class="table-responsive">

                    <table class="table table-hover align-middle mb-0">

                        <thead class="bg-white text-muted"><tr><th class="ps-4">Preview</th><th>Info / Validade</th><th>Status</th><th class="text-end pe-4">Ações</th></tr></thead>

                        <tbody>

                            <?php foreach($lista_login as $b): $status = getStatusBanner($b['data_inicio'], $b['data_fim'], $b['ativo']); $val = ($b['data_inicio']?date('d/m',strtotime($b['data_inicio'])):'Agora').' até '.($b['data_fim']?date('d/m',strtotime($b['data_fim'])):'Sempre'); ?>

                            <tr>

                                <td class="ps-4"><?= renderPreview($b['caminho_imagem']) ?></td>

                                <td><div class="fw-bold"><?= $b['titulo']?:'Banner Login' ?></div><small class="text-primary text-xs"><?= $val ?></small></td>

                                <td><?= $status ?></td>

                                <td class="text-end pe-4">

                                    <button class="btn btn-sm btn-light border me-1" onclick='editarBanner(<?= json_encode($b) ?>)'><i class="fas fa-pen"></i></button>

                                    <a href="?acao=del_banner&id=<?= $b['id'] ?>" class="btn btn-sm btn-light border text-danger" onclick="return confirm('Excluir?')"><i class="fas fa-trash"></i></a>

                                </td>

                            </tr>

                            <?php endforeach; if(empty($lista_login)): ?><tr><td colspan="4" class="text-center py-4 text-muted small">Vazio</td></tr><?php endif; ?>

                        </tbody>

                    </table>

                    </div>

                </div>

            </div>

        </div>

        

        <div class="col-lg-4">

            <div class="card card-dash h-100">

                <div class="card-header"><h6 class="fw-bold m-0">Sistema</h6></div>

                <div class="card-body p-4">

                    <div class="mb-4">

                        <div class="d-flex justify-content-between mb-2"><span class="text-muted small fw-bold">Uso de Disco</span><span class="fw-bold"><?= formatBytes($disk_used) ?> / <?= formatBytes($disk_total) ?></span></div>

                        <div class="progress" style="height: 8px;"><div class="progress-bar <?= $status_disk ?>" style="width: <?= $disk_percent ?>%"></div></div>

                    </div>

                    <div class="bg-light p-3 rounded-3 border">

                        <h6 class="fw-bold text-dark mb-2"><i class="fas fa-broom me-2 text-warning"></i> Manutenção</h6>

                        <form method="POST" onsubmit="return confirm('Limpar?');">

                            <input type="hidden" name="acao" value="limpar_fotos">

                            <button class="btn btn-outline-danger w-100 btn-sm fw-bold">Limpar Lixo (>90 dias)</button>

                        </form>

                    </div>

                </div>

            </div>

        </div>

    </div>



    <div class="row g-4 mb-5">

        <div class="col-md-4">

            <div class="card card-dash h-100">

                <div class="card-header border-0 pt-4 px-4"><h6 class="fw-bold m-0">Planos Ativos</h6></div>

                <div class="card-body d-flex align-items-center justify-content-center p-4">

                    <div style="width: 220px; height: 220px;"><canvas id="graficoPlanos"></canvas></div>

                </div>

            </div>

        </div>

        

        <div class="col-md-8">

            <div class="card card-dash h-100">

                <div class="card-header d-flex justify-content-between align-items-center py-3">

                    <h6 class="fw-bold m-0">Planos de Venda</h6>

                    <button class="btn btn-sm btn-primary rounded-pill fw-bold" onclick="novoPlano()">+ Novo Plano</button>

                </div>

                <div class="table-responsive">

                    <table class="table table-hover align-middle mb-0">

                        <thead class="bg-light">

                            <tr>

                                <th class="ps-4">Plano</th>

                                <th>Valor Oficial</th>

                                <th>Limites</th>

                                <th class="text-end pe-4">Ações</th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach($lista_planos as $p): ?>

                            <tr>

                                <td class="ps-4">

                                    <div class="fw-bold"><?= htmlspecialchars($p['nome']) ?></div>

                                    <?php if(!empty($p['badge'])): ?>

                                        <span class="badge bg-warning text-dark" style="font-size: 0.65rem;"><?= htmlspecialchars($p['badge']) ?></span>

                                    <?php endif; ?>

                                </td>

                                

                                <td class="fw-bold text-success">

                                    <?php if (!empty($p['preco_texto'])): ?>

                                        <span class="text-primary"><i class="fas fa-tag me-1"></i> <?= htmlspecialchars($p['preco_texto']) ?></span>

                                    <?php else: ?>

                                        <?= $p['preco'] == 0 ? 'Grátis' : 'R$ ' . number_format($p['preco'], 2, ',', '.') ?>

                                    <?php endif; ?>

                                </td>

                                

                                <td><span class="badge bg-light text-dark border"><?= $p['limite_eleitores'] > 0 ? $p['limite_eleitores'] . ' leads' : 'Ilimitado' ?></span></td>

                                <td class="text-end pe-4">

                                    <button class="btn btn-sm btn-light border" onclick='editarPlano(<?= json_encode($p) ?>)'><i class="fas fa-pen"></i></button>

                                    <a href="?acao=del_plano&id=<?= $p['id'] ?>" class="btn btn-sm btn-light border text-danger" onclick="return confirm('Excluir?')"><i class="fas fa-trash"></i></a>

                                </td>

                            </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

    </div>



    <div class="card card-dash mb-5">

        <div class="card-header py-3 d-flex justify-content-between align-items-center">

            <h6 class="fw-bold m-0">Clientes</h6>

            <a href="master_novo_cliente.php" class="btn btn-success btn-sm rounded-pill fw-bold shadow-sm px-3"><i class="fas fa-plus me-1"></i> Novo</a>

        </div>

        <div class="table-responsive">

            <table class="table table-hover align-middle mb-0">

                <thead class="bg-light">

                    <tr><th class="ps-4">Cliente</th><th>Plano</th><th>Status</th><th class="text-end pe-4">Ações</th></tr>

                </thead>

                <tbody>

                    <?php foreach($clientes as $c): ?>

                    <tr>

                        <td class="ps-4"><div class="fw-bold"><?= $c['nome'] ?></div><small class="text-muted">/<?= $c['slug'] ?></small></td>

                        <td><span class="badge bg-light text-dark border fw-normal"><?= strtoupper($c['plano']) ?></span></td>

                        <td><?= $c['status']=='ativo'?'<span class="badge bg-success">Ativo</span>':'<span class="badge bg-danger">Bloqueado</span>' ?></td>

                        

                        <td class="text-end pe-4">

                            <?php 

                                // Formatação do Número do WhatsApp

                                $num_whats = preg_replace('/[^0-9]/', '', $c['whatsapp_oficial'] ?? '');

                                if(strlen($num_whats) >= 10 && substr($num_whats, 0, 2) !== '55') { $num_whats = '55' . $num_whats; }

                                

                                // Mensagem Padrão de Boas Vindas

                                $msg_padrao = urlencode("Olá, {$c['nome']}! Parabéns por ativar o seu Gabinete Digital. 🚀 Sou do time de suporte, posso te ajudar a dar os primeiros passos no painel?");

                            ?>

                            

                            <a href="<?= empty($num_whats) ? '#' : "https://wa.me/{$num_whats}?text={$msg_padrao}" ?>" 

                               target="<?= empty($num_whats) ? '_self' : '_blank' ?>" 

                               class="btn btn-sm btn-light border text-success <?= empty($num_whats) ? 'opacity-50' : '' ?>" 

                               title="<?= empty($num_whats) ? 'WhatsApp não informado' : 'Boas-vindas no WhatsApp' ?>">

                                <i class="fab fa-whatsapp fa-lg"></i>

                            </a>

                            

                            <button class="btn btn-sm btn-light border text-warning" onclick='abrirSimulador(<?= json_encode($c) ?>)' title="Simular"><i class="fas fa-flask"></i></button>

                            <a href="?acao=login_rapido&id=<?= $c['id'] ?>" target="_blank" class="btn btn-sm btn-light border text-primary" title="Acessar"><i class="fas fa-external-link-alt"></i></a>

                            <button class="btn btn-sm btn-light border" onclick='editarCliente(<?= json_encode($c) ?>)' title="Editar"><i class="fas fa-pen"></i></button>

                            <a href="?acao=excluir_cliente&id=<?= $c['id'] ?>" class="btn btn-sm btn-light border text-danger" onclick="return confirm('Excluir?')" title="Excluir"><i class="fas fa-trash"></i></a>

                        </td>

                    </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>



<div class="modal fade" id="modalPlano" tabindex="-1">

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <form method="POST" class="modal-content border-0 shadow rounded-4">

            <div class="modal-header bg-dark text-white border-0">

                <h5 class="modal-title fw-bold">Gerenciar Plano</h5>

                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>

            </div>

            <div class="modal-body p-4">

                <input type="hidden" name="acao" value="salvar_plano">

                <input type="hidden" name="id_plano" id="id_plano">

                <input type="hidden" name="slug" id="slug_plano">

                

                <div class="row g-3 mb-3">

                    <div class="col-md-7">

                        <label class="small fw-bold text-muted text-uppercase mb-1">Nome do Plano</label>

                        <input type="text" name="nome" id="nome_plano" class="form-control fw-bold" required>

                    </div>

                    <div class="col-md-5">

                        <label class="small fw-bold text-muted text-uppercase mb-1">Badge / Tag (Topo do Card)</label>

                        <input type="text" name="badge" id="badge_plano" class="form-control" placeholder="Ex: Mais Popular">

                    </div>

                </div>



                <div class="row g-3 mb-4">

                    <div class="col-md-3">

                        <label class="small fw-bold text-muted text-uppercase mb-1">Preço Numérico (R$)</label>

                        <input type="text" name="preco" id="preco_plano" class="form-control text-success fw-bold" required placeholder="Ex: 99.90">

                    </div>

                    <div class="col-md-4">

                        <label class="small fw-bold text-muted text-uppercase mb-1">Texto do Preço (Opcional)</label>

                        <input type="text" name="preco_texto" id="preco_texto_plano" class="form-control" placeholder="Ex: Sob Consulta, Grátis">

                    </div>

                    <div class="col-md-2">

                        <label class="small fw-bold text-muted text-uppercase mb-1">Dias Válido</label>

                        <input type="number" name="dias" id="dias_plano" value="30" class="form-control text-center" required>

                    </div>

                    <div class="col-md-3">

                        <label class="small fw-bold text-muted text-uppercase mb-1">Lim. Leads</label>

                        <input type="number" name="limite" id="limite_plano" class="form-control text-center" required placeholder="0=Inf">

                    </div>

                </div>

                

                <div class="bg-light border rounded-3 p-4 mb-3">

                    <label class="fw-bold mb-3 d-block text-primary"><i class="fas fa-cubes me-2"></i>Módulos Inclusos:</label>

                    <div class="row g-3">

                        <?php foreach($modulos_disponiveis as $key => $label): ?>

                        <div class="col-md-4 col-sm-6">

                            <div class="form-check form-switch">

                                <input class="form-check-input check-modulo" type="checkbox" name="modulos[]" value="<?= $key ?>" id="mod_<?= $key ?>" style="cursor: pointer;">

                                <label class="form-check-label small fw-bold" for="mod_<?= $key ?>" style="cursor: pointer;"><?= $label ?></label>

                            </div>

                        </div>

                        <?php endforeach; ?>

                    </div>

                </div>

                <label class="small fw-bold text-muted text-uppercase mb-1">Benefícios (Lista para o Site)</label>

                <textarea name="beneficios" id="beneficios_plano" class="form-control bg-light" rows="3" placeholder="Um item por linha..."></textarea>

            </div>

            <div class="modal-footer border-0 bg-light">

                <button class="btn btn-dark w-100 fw-bold py-2">Salvar Plano</button>

            </div>

        </form>

    </div>

</div>



<div class="modal fade" id="modalConfigGlobal" tabindex="-1">

    <div class="modal-dialog modal-dialog-centered">

        <form method="POST" class="modal-content border-0 shadow rounded-4">

            <div class="modal-header bg-primary text-white border-0">

                <h5 class="modal-title fw-bold">Configuração Global</h5>

                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>

            </div>

            <div class="modal-body p-4">

                <input type="hidden" name="acao" value="config_global_eleicao">

                <div class="mb-3">

                    <label class="fw-bold small mb-1">Data da Eleição</label>

                    <input type="date" name="data_eleicao" class="form-control fw-bold" value="<?= $data_eleicao_atual ?>" required>

                </div>

            </div>

            <div class="modal-footer border-0 bg-light">

                <button class="btn btn-primary w-100 fw-bold">Atualizar</button>

            </div>

        </form>

    </div>

</div>



<div class="modal fade" id="modalSimulador" tabindex="-1">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content border-0 shadow rounded-4">

            <div class="modal-header bg-warning text-dark border-0">

                <h5 class="modal-title fw-bold">Simulador de Ciclo</h5>

                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>

            </div>

            <div class="modal-body p-4 text-center">

                <p class="mb-4">Cliente: <b id="simula_cliente_nome"></b></p>

                <form method="POST" class="d-grid gap-2">

                    <input type="hidden" name="acao" value="simular_ciclo">

                    <input type="hidden" name="id_simulacao" id="simula_id">

                    <button type="submit" name="evento" value="pagamento_ok" class="btn btn-success p-3 text-start shadow-sm border-0"><b><i class="fas fa-check-circle me-2"></i> Pagamento OK (+30 Dias)</b></button>

                    <button type="submit" name="evento" value="vencimento" class="btn btn-danger p-3 text-start shadow-sm border-0"><b><i class="fas fa-times-circle me-2"></i> Simular Inadimplência</b></button>

                </form>

            </div>

        </div>

    </div>

</div>



<div class="modal fade" id="modalEditar" tabindex="-1">

    <div class="modal-dialog modal-dialog-centered">

        <form method="POST" class="modal-content border-0 shadow rounded-4">

            <div class="modal-header border-0">

                <h5 class="modal-title fw-bold">Editar Cliente</h5>

                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>

            </div>

            <div class="modal-body p-4">

                <input type="hidden" name="acao" value="editar_cliente">

                <input type="hidden" name="id" id="edit_id">

                

                <div class="mb-3">

                    <label class="small fw-bold mb-1">Nome</label>

                    <input type="text" name="nome" id="edit_nome" class="form-control">

                </div>

                

                <div class="mb-3">

                    <label class="small fw-bold mb-1">Plano</label>

                    <select name="plano" id="edit_plano" class="form-select">

                        <?php foreach($lista_planos as $p): ?>

                            <option value="<?= $p['slug'] ?>"><?= $p['nome'] ?></option>

                        <?php endforeach; ?>

                    </select>

                </div>

                

                <div class="mb-3">

                    <label class="small fw-bold mb-1">Vencimento</label>

                    <input type="date" name="vencimento" id="edit_vencimento" class="form-control">

                </div>

                

                <div class="mb-3">

                    <label class="small fw-bold mb-1">Status</label>

                    <select name="status" id="edit_status" class="form-select">

                        <option value="ativo">Ativo</option>

                        <option value="bloqueado">Bloqueado</option>

                    </select>

                </div>

            </div>

            <div class="modal-footer border-0 bg-light">

                <button class="btn btn-success w-100 fw-bold">Salvar Alterações</button>

            </div>

        </form>

    </div>

</div>



<div class="modal fade" id="modalLogs" tabindex="-1">

    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">

        <div class="modal-content border-0 shadow-lg">

            <div class="modal-header bg-dark text-white border-0">

                <h5 class="modal-title fw-bold"><i class="fas fa-file-code me-2"></i>Logs do Sistema</h5>

                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>

            </div>

            <div class="modal-body p-0">

                <iframe id="iframeLogs" src="" style="width: 100%; height: 500px; border: none;"></iframe>

            </div>

            <div class="modal-footer border-0">

                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fechar</button>

                <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('iframeLogs').contentWindow.location.reload();"><i class="fas fa-sync-alt"></i> Atualizar Logs</button>

            </div>

        </div>

    </div>

</div>



<div class="modal fade" id="modalBanner" tabindex="-1">

    <div class="modal-dialog modal-dialog-centered">

        <form method="POST" enctype="multipart/form-data" class="modal-content border-0 shadow rounded-4">

            <div class="modal-header border-0">

                <h5 class="modal-title fw-bold">Novo Banner</h5>

                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>

            </div>

            <div class="modal-body p-4">

                <input type="hidden" name="acao" value="subir_banner">

                <div class="mb-3">

                    <label class="fw-bold small mb-1">Local de Exibição</label>

                    <select name="tipo" class="form-select bg-light fw-bold text-primary">

                        <option value="login">🖥️ Tela de Login</option>

                        <option value="hero">🌐 Topo do Site (Hero)</option>

                    </select>

                </div>

                <div class="mb-3">

                    <label class="fw-bold small mb-1">Arquivo (Imagem ou Vídeo MP4)</label>

                    <input type="file" name="banner" class="form-control" accept="image/*,video/mp4,video/webm" required>

                </div>

                <div class="row g-2 mb-3">

                    <div class="col-6">

                        <label class="fw-bold small mb-1">Data de Início</label>

                        <input type="date" name="inicio" class="form-control">

                    </div>

                    <div class="col-6">

                        <label class="fw-bold small mb-1">Data de Fim (Opcional)</label>

                        <input type="date" name="fim" class="form-control">

                    </div>

                </div>

                <div class="mb-3">

                    <label class="fw-bold small mb-1">Título</label>

                    <input type="text" name="titulo" class="form-control" placeholder="Apenas para controle interno">

                </div>

                <div class="mb-3">

                    <label class="fw-bold small mb-1">Frase de Apoio</label>

                    <input type="text" name="frase" class="form-control">

                </div>

            </div>

            <div class="modal-footer border-0 bg-light">

                <button class="btn btn-dark w-100 fw-bold">Salvar Banner</button>

            </div>

        </form>

    </div>

</div>



<div class="modal fade" id="modalEditarBanner" tabindex="-1">

    <div class="modal-dialog modal-dialog-centered">

        <form method="POST" class="modal-content border-0 shadow rounded-4">

            <div class="modal-header border-0 bg-light">

                <h5 class="modal-title fw-bold">Editar Banner</h5>

                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>

            </div>

            <div class="modal-body p-4">

                <input type="hidden" name="acao" value="editar_banner">

                <input type="hidden" name="id" id="edit_banner_id">

                

                <div class="mb-3">

                    <label class="fw-bold small mb-1">Título</label>

                    <input type="text" name="titulo" id="edit_banner_titulo" class="form-control">

                </div>

                <div class="mb-3">

                    <label class="fw-bold small mb-1">Frase de Apoio</label>

                    <input type="text" name="frase" id="edit_banner_frase" class="form-control">

                </div>

                <div class="row g-2 mb-3">

                    <div class="col-6">

                        <label class="fw-bold small mb-1">Data Início</label>

                        <input type="date" name="inicio" id="edit_banner_inicio" class="form-control">

                    </div>

                    <div class="col-6">

                        <label class="fw-bold small mb-1">Data Fim</label>

                        <input type="date" name="fim" id="edit_banner_fim" class="form-control">

                    </div>

                </div>

                <div class="form-check form-switch mt-4">

                    <input class="form-check-input" type="checkbox" name="ativo" id="edit_banner_ativo" value="1">

                    <label class="form-check-label fw-bold" for="edit_banner_ativo">Banner Ativo e Visível</label>

                </div>

            </div>

            <div class="modal-footer border-0 bg-light">

                <button class="btn btn-primary w-100 fw-bold">Atualizar Dados</button>

            </div>

        </form>

    </div>

</div>



<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>

    const ctx = document.getElementById('graficoPlanos');

    if(ctx) {

        new Chart(ctx, { 

            type: 'doughnut', 

            data: { 

                labels: <?= json_encode(array_keys($grafico)) ?>, 

                datasets: [{ 

                    data: <?= json_encode(array_values($grafico)) ?>, 

                    backgroundColor: ['#4f46e5','#10b981','#f59e0b','#ef4444','#06b6d4'], 

                    borderWidth: 0 

                }] 

            }, 

            options: { 

                maintainAspectRatio: false, 

                plugins: { 

                    legend: { 

                        position: 'bottom', 

                        labels: { usePointStyle: true, padding: 20 } 

                    } 

                }, 

                cutout: '75%' 

            } 

        });

    }



    function novoPlano() { 

        document.getElementById('id_plano').value = ''; 

        document.getElementById('nome_plano').value = ''; 

        document.getElementById('slug_plano').value = ''; 

        document.getElementById('preco_plano').value = ''; 

        document.getElementById('badge_plano').value = ''; 

        document.getElementById('preco_texto_plano').value = ''; 

        document.getElementById('dias_plano').value = '30'; 

        document.getElementById('limite_plano').value = '0'; 

        document.getElementById('beneficios_plano').value = ''; 

        

        document.querySelectorAll('.check-modulo').forEach(el => el.checked = false); 

        new bootstrap.Modal(document.getElementById('modalPlano')).show(); 

    }

    

    function editarPlano(p) { 

        document.getElementById('id_plano').value = p.id; 

        document.getElementById('nome_plano').value = p.nome; 

        document.getElementById('slug_plano').value = p.slug; 

        document.getElementById('preco_plano').value = p.preco; 

        document.getElementById('badge_plano').value = p.badge || ''; 

        document.getElementById('preco_texto_plano').value = p.preco_texto || ''; 

        document.getElementById('dias_plano').value = p.dias_validade; 

        document.getElementById('limite_plano').value = p.limite_eleitores || 0; 

        document.getElementById('beneficios_plano').value = p.beneficios; 

        

        let modulos = []; 

        try {

            modulos = typeof p.modulos === 'string' ? JSON.parse(p.modulos) : p.modulos;

        } catch(e) {} 

        

        document.querySelectorAll('.check-modulo').forEach(el => el.checked = false); 

        

        if(modulos) {

            modulos.forEach(mod => {

                let check = document.getElementById('mod_' + mod);

                if(check) check.checked = true;

            });

        } 

        new bootstrap.Modal(document.getElementById('modalPlano')).show(); 

    }

    

    function editarCliente(c) { 

        document.getElementById('edit_id').value = c.id; 

        document.getElementById('edit_nome').value = c.nome; 

        document.getElementById('edit_plano').value = c.plano; 

        document.getElementById('edit_status').value = c.status; 

        if(c.data_vencimento) {

            document.getElementById('edit_vencimento').value = c.data_vencimento.split(' ')[0]; 

        } else {

            document.getElementById('edit_vencimento').value = ''; 

        }

        new bootstrap.Modal(document.getElementById('modalEditar')).show(); 

    }

    

    function abrirSimulador(c) { 

        document.getElementById('simula_id').value = c.id; 

        document.getElementById('simula_cliente_nome').innerText = c.nome; 

        new bootstrap.Modal(document.getElementById('modalSimulador')).show(); 

    }

    

    function editarBanner(b) { 

        document.getElementById('edit_banner_id').value = b.id; 

        document.getElementById('edit_banner_titulo').value = b.titulo; 

        document.getElementById('edit_banner_frase').value = b.frase_apoio; 

        document.getElementById('edit_banner_inicio').value = b.data_inicio; 

        document.getElementById('edit_banner_fim').value = b.data_fim; 

        document.getElementById('edit_banner_ativo').checked = (b.ativo == 1); 

        new bootstrap.Modal(document.getElementById('modalEditarBanner')).show(); 

    }

    

    function abrirModalLogs() { 

        document.getElementById('iframeLogs').src = 'ver_logs.php'; 

        var modal = new bootstrap.Modal(document.getElementById('modalLogs')); 

        modal.show(); 

    }

</script>

</body>

</html>
