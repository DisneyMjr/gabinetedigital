<?php
// 1. Configurações de Erro (Ative para depurar, desative em produção)
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once 'tracker.php'; 

// 2. Importação de dependências
if (file_exists('config.php')) {
    require_once 'config.php';
} else {
    die("Erro: Arquivo config.php não encontrado.");
}

// IMPORTANTE: Aqui carregamos os arquivos de pagamento para evitar o Erro 500
if (file_exists('gateway.php')) require_once 'gateway.php';

// --- BUSCA BANNER HERO EM saas_config ---
$hero_banners = [];
try {
    $cfg = [
        'hero_img'    => '',
        'hero_titulo' => 'Revolucione seu Mandato Digital',
        'hero_frase'  => 'A plataforma completa para políticos modernos.'
    ];

    $stmt = $pdo->query("SELECT chave, valor FROM saas_config WHERE chave IN ('hero_img', 'hero_titulo', 'hero_frase')");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $cfg[$row['chave']] = $row['valor'];
    }

    if (!empty($cfg['hero_img'])) {
        $imgLimpa = strtok(trim($cfg['hero_img']), '?');
        $imgLimpa = '/' . ltrim($imgLimpa, '/');

        $hero_banners[] = [
            'caminho_imagem' => $imgLimpa,
            'titulo' => $cfg['hero_titulo'],
            'frase_apoio' => $cfg['hero_frase']
        ];
    }
} catch (Exception $e) {
    $hero_banners = [];
}

$usar_carrossel = !empty($hero_banners);

// =========================================================================
// MÁGICA DO ONESIGNAL: FUNÇÃO PARA CRIAR APP AUTOMÁTICO
// =========================================================================
function automatizarOneSignal($nome_vereador, $subdominio_completo) {
    // 🛑 COLOQUE SUAS CHAVES DE ORGANIZAÇÃO AQUI
    $user_auth_key = "os_v2_org_zd6bbm4nbrcbzbxrasaqgxzid5hqn5v577oei25qgfgtcapoc5h4esnldf74qsi6iig4oheebp4lptorrrws4mqcg6ubmrjwcl6imkq"; 
    $org_id = "c8fc10b3-8d0c-441c-86f1-0481035f281f"; 

    $dados = [
        'name' => 'Gabinete - ' . $nome_vereador,
        'organization_id' => $org_id,
        'site_name' => 'Gabinete Digital',
        'chrome_web_origin' => $subdominio_completo,
        'safari_site_origin' => $subdominio_completo
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.onesignal.com/apps");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Basic ' . $user_auth_key
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dados));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE); 
    
    $resposta = curl_exec($ch);
    curl_close($ch);
    
    $resultado = json_decode($resposta, true);
    
    return [
        'app_id' => $resultado['id'] ?? null,
        'api_key' => $resultado['basic_auth_key'] ?? null
    ];
}

// --- PROCESSAMENTO DO CADASTRO ---
$msg_cadastro = "";
$erro_cadastro = "";
$link_direto = "";
$is_pago = false;
$preco_plano = 0;
$pix_data = null;
$txid = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao'])) {
    if (!isset($pdo)) die("Erro de conexão com o banco de dados.");

    $nome          = trim($_POST['nome'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $whatsapp      = trim($_POST['whatsapp'] ?? '');
    $senha         = (string)($_POST['senha'] ?? '');
    $slug_base     = strtolower(preg_replace('/[^a-z0-9]/', '', $_POST['slug'] ?? ''));
    $tipo_cadastro = $_POST['acao'] ?? '';
    $slug_plano    = $_POST['plano_escolhido'] ?? 'teste-gratis';
    $tenant_id     = 0;

    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if ($nome === '') throw new Exception("Informe seu nome.");
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception("Informe um e-mail válido.");
        if (strlen($senha) < 6) throw new Exception("A senha deve ter pelo menos 6 caracteres.");
        if (strlen($slug_base) < 3) throw new Exception("O endereço do site deve ter pelo menos 3 letras.");

        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM tenants WHERE slug = ?");
        $stmt_check->execute([$slug_base]);
        if ((int)$stmt_check->fetchColumn() > 0) throw new Exception("Este endereço de site já está em uso.");

        $stmt_email = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE email = ?");
        $stmt_email->execute([$email]);
        if ((int)$stmt_email->fetchColumn() > 0) throw new Exception("Este e-mail já está cadastrado.");

        if ($tipo_cadastro === 'criar_teste') {
            $status = 'ativo';
            $ativo = 1;
            $vencimento = date('Y-m-d H:i:s', strtotime('+7 days'));
        } else {
            $status = 'pendente_pagamento';
            $ativo = 0;
            $vencimento = date('Y-m-d H:i:s', strtotime('+30 days'));
            $is_pago = true;

            $stmt_plano = $pdo->prepare("SELECT preco FROM planos WHERE slug = ? AND ativo = 1 LIMIT 1");
            $stmt_plano->execute([$slug_plano]);
            $precoEncontrado = $stmt_plano->fetchColumn();
            if ($precoEncontrado === false) throw new Exception("Plano selecionado não foi encontrado.");
            $preco_plano = (float)$precoEncontrado;
        }

        $preco_final = $preco_plano;
        $codigo_cupom = strtoupper(trim($_POST['cupom'] ?? ''));
        $cupom_valido = false;

        if ($codigo_cupom !== '' && $preco_plano > 0) {
            $stmtC = $pdo->prepare("SELECT desconto_porcento FROM cupons WHERE codigo = ? AND ativo = 1 AND usos_atuais < limite_usos LIMIT 1");
            $stmtC->execute([$codigo_cupom]);
            $desconto = $stmtC->fetchColumn();
            if ($desconto !== false) {
                $preco_final = max(0, $preco_plano - ($preco_plano * ((float)$desconto / 100)));
                $cupom_valido = true;
            }
        }

        /*
         * ETAPA 1: grava o cadastro local e COMMITA antes de chamar gateway externo.
         * Assim uma assinatura criada no Mercado Pago nunca fica ligada a um tenant
         * que desapareceu por rollback do MySQL.
         */
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO tenants (nome, slug, email_contato, plano, ativo, data_vencimento, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$nome, $slug_base, $email, $slug_plano, $ativo, $vencimento, $status]);
        $tenant_id = (int)$pdo->lastInsertId();
        if ($tenant_id <= 0) throw new Exception("Não foi possível identificar o novo cliente.");

        $protocolo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $dominio = str_replace('www.', '', $_SERVER['HTTP_HOST'] ?? '');
        $url_completa = $protocolo . $slug_base . '.' . $dominio;

        // OneSignal não pode derrubar o cadastro caso a API externa falhe.
        $chaves_push = ['app_id' => null, 'api_key' => null];
        try {
            $chaves_push = automatizarOneSignal($nome, $url_completa);
        } catch (Throwable $pushError) {
            error_log('OneSignal cadastro tenant ' . $tenant_id . ': ' . $pushError->getMessage());
        }

        $pdo->prepare("INSERT INTO configuracoes (tenant_id, nome_vereador, whatsapp_oficial, cor_primaria, onesignal_app_id, onesignal_api_key) VALUES (?, ?, ?, '#6366f1', ?, ?)")
            ->execute([$tenant_id, $nome, $whatsapp, $chaves_push['app_id'] ?? null, $chaves_push['api_key'] ?? null]);

        $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
        $todas_permissoes = ['designer','oficios_ia','campanha','mapa','liderancas','noticias','ouvidoria','eleitores','aniversariantes','agenda','projetos','enquetes','landing','templates','transparencia','qrcode','links','relatorios','financeiro','marketing','push','equipe','auditoria','config'];
        $permissoes = json_encode($todas_permissoes, JSON_UNESCAPED_UNICODE);

        $pdo->prepare("INSERT INTO usuarios (tenant_id, nome, email, senha, permissoes) VALUES (?, 'Admin', ?, ?, ?)")
            ->execute([$tenant_id, $email, $senha_hash, $permissoes]);

        if ($cupom_valido) {
            $pdo->prepare("UPDATE cupons SET usos_atuais = usos_atuais + 1 WHERE codigo = ?")
                ->execute([$codigo_cupom]);
        }

        $pdo->commit();

        /* ETAPA 2: somente depois do cadastro existir definitivamente, cria cobrança. */
        if ($is_pago && $preco_plano > 0) {
            if (!function_exists('gerarCobrancaSaaS')) {
                throw new Exception("Cadastro criado, mas o gateway de pagamento não está configurado.");
            }

            $descricao_venda = "Assinatura " . ucfirst($slug_plano) . " - " . $nome;

            $pix_data = gerarCobrancaSaaS(
                $nome,
                $email,
                $preco_final,
                $descricao_venda,
                $slug_base,
                $tenant_id,
                $slug_plano
            );

            if (!is_array($pix_data) || isset($pix_data['erro'])) {
                $detalhe = is_array($pix_data) ? ($pix_data['erro'] ?? 'Resposta inválida do gateway.') : 'Resposta inválida do gateway.';
                throw new Exception("Seu cadastro foi criado, mas não foi possível iniciar o pagamento: " . $detalhe);
            }

            $txid = (string)($pix_data['assinatura_id'] ?? $pix_data['fatura_id'] ?? '');
            if ($txid !== '') {
                $pdo->prepare("UPDATE tenants SET txid_pagamento = ? WHERE id = ?")
                    ->execute([$txid, $tenant_id]);
            }
        }

        $link_painel = $protocolo . $dominio . "/login.php?cliente=" . urlencode($slug_base);
        $link_direto = $link_painel;
        $msg_cadastro = "sucesso";

    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        error_log('saas_landing cadastro: ' . $e->getMessage());
        $erro_cadastro = $e->getMessage();
    }
}

// --- BUSCA PLANOS NO BANCO ---
try {
    $stmt_planos = $pdo->query("SELECT * FROM planos WHERE ativo = 1 ORDER BY preco ASC");
    $planos_db = $stmt_planos->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { 
    $planos_db = []; 
}

$modulos_sistema = [
    'campanha'        => 'Envolvimento',
    'mapa'            => 'Mapa de Calor',
    'liderancas'      => 'Lideranças',
    'noticias'        => 'Site/Notícias',
    'ouvidoria'       => 'Ouvidoria',
    'eleitores'       => 'CRM Eleitores',
    'aniversariantes' => 'Aniversariantes',
    'agenda'          => 'Agenda',
    'projetos'        => 'Projetos Lei',
    'enquetes'        => 'Enquetes',
    'landing'         => 'Landing Pages',
    'templates'       => 'Modelos de Arte',
    'transparencia'   => 'Transparência',
    'qrcode'          => 'Placa QR Code',
    'links'           => 'Encurtador',
    'relatorios'      => 'Relatórios',
    'financeiro'      => 'Financeiro',
    'marketing'       => 'E-mail Marketing',
    'push'            => 'Notificações Push',
    'equipe'          => 'Equipe',
    'auditoria'       => 'Auditoria',
    'config'          => 'Configurações',
    'designer'        => 'Designer Express',
    'oficios_ia'      => 'Redator IA (Ofícios)'
];
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gabinete Digital | Sistema completo para mandato político</title>

    <meta name="description" content="Organize seu mandato com CRM de eleitores, ouvidoria, mapa de calor, agenda, site do mandato, solicitações online e comunicação política em uma única plataforma.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= BASE_URL ?? '' ?>">
    <meta property="og:title" content="Gabinete Digital | Seu mandato no controle total">
    <meta property="og:description" content="CRM de eleitores, ouvidoria, mapa de calor, solicitações online, agenda e site do mandato em uma única plataforma.">
    <meta property="og:image" content="<?= BASE_URL ?? '' ?>assets/img/share_v2.jpg">
    <meta property="og:image:type" content="image/jpeg">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">

    <link rel="icon" href="assets/img/favicon.svg" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;500;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">

    <style>
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --accent: #ec4899;
            --success: #10b981;
            --dark-bg: #0f172a;
            --dark-2: #111827;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --soft-bg: #f8fafc;
            --gradient: linear-gradient(90deg, #6366f1, #ec4899);
            --gradient-text: linear-gradient(to right, #818cf8, #f472b6);
        }

        * { box-sizing: border-box; }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--soft-bg);
            color: var(--text-main);
            overflow-x: hidden;
        }
        h1, h2, h3, h4, h5, .navbar-brand { font-family: 'Outfit', sans-serif; }
        a { text-decoration: none; }

        .navbar {
            background: rgba(255,255,255,.94);
            backdrop-filter: blur(16px);
            padding: .85rem 0;
            box-shadow: 0 10px 30px rgba(15,23,42,.06);
        }
        .navbar-brand {
            font-weight: 900;
            font-size: 1.45rem;
            background: linear-gradient(to right, #4f46e5, #ec4899);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .nav-link { font-weight: 700; color: var(--text-main) !important; margin: 0 8px; font-size: .95rem; }

        .btn-gradient {
            background: var(--gradient);
            color: white;
            border: none;
            padding: 15px 34px;
            border-radius: 999px;
            font-weight: 800;
            transition: .25s;
            box-shadow: 0 15px 35px rgba(99,102,241,.35);
        }
        .btn-gradient:hover { transform: translateY(-3px); color: white; box-shadow: 0 20px 45px rgba(236,72,153,.38); }
        .btn-soft { background: rgba(255,255,255,.12); color:#fff; border:1px solid rgba(255,255,255,.2); border-radius:999px; font-weight:800; padding:15px 30px; }
        .btn-soft:hover { background:#fff; color:var(--primary); }

        .hero-conversion {
            min-height: 100vh;
            padding: 155px 0 80px;
            background:
                radial-gradient(circle at 12% 10%, rgba(99,102,241,.32), transparent 32%),
                radial-gradient(circle at 80% 20%, rgba(236,72,153,.24), transparent 30%),
                linear-gradient(135deg, #0f172a 0%, #111827 55%, #020617 100%);
            color: #fff;
            position: relative;
            overflow: hidden;
        }
        .hero-conversion:before {
            content:"";
            position:absolute;
            inset:0;
            background-image: linear-gradient(rgba(255,255,255,.035) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.035) 1px, transparent 1px);
            background-size: 56px 56px;
            mask-image: linear-gradient(to bottom, black, transparent 88%);
        }
        .hero-title { font-size: clamp(2.45rem, 5vw, 4.8rem); line-height: 1.03; font-weight: 900; letter-spacing: -1.5px; }
        .text-gradient { background: var(--gradient-text); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .hero-subtitle { color: rgba(255,255,255,.74); font-size: 1.14rem; line-height:1.7; max-width: 650px; }
        .hero-badge { display:inline-flex; align-items:center; gap:8px; padding:10px 15px; border-radius:999px; background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.12); color:#fff; font-weight:800; font-size:.9rem; }
        .trust-row { border-top:1px solid rgba(255,255,255,.12); margin-top:34px; padding-top:26px; }
        .trust-number { font-size:2rem; font-weight:900; line-height:1; }
        .hero-panel {
            position:relative;
            border:1px solid rgba(255,255,255,.12);
            background:rgba(255,255,255,.08);
            backdrop-filter: blur(18px);
            border-radius:32px;
            padding:24px;
            box-shadow:0 35px 80px rgba(0,0,0,.35);
        }
        .hero-panel img { width:100%; border-radius:24px; box-shadow: 0 25px 60px rgba(0,0,0,.35); }
        .floating-card { position:absolute; background:#fff; color:var(--text-main); border-radius:20px; padding:16px 18px; box-shadow:0 20px 45px rgba(0,0,0,.22); font-weight:800; }
        .floating-card.one { top:40px; left:-18px; }
        .floating-card.two { bottom:45px; right:-18px; }

        .section-pad { padding: 90px 0; }
        .section-eyebrow { display:inline-block; padding:8px 14px; border-radius:999px; font-weight:900; font-size:.78rem; letter-spacing:.7px; text-transform:uppercase; }
        .card-soft {
            background:#fff;
            border:1px solid rgba(15,23,42,.06);
            border-radius:26px;
            padding:30px;
            height:100%;
            box-shadow:0 10px 30px rgba(15,23,42,.045);
            transition:.25s;
        }
        .card-soft:hover { transform: translateY(-6px); box-shadow:0 18px 45px rgba(99,102,241,.12); border-color:rgba(99,102,241,.28); }
        .feature-icon { width:62px; height:62px; border-radius:18px; display:flex; align-items:center; justify-content:center; font-size:25px; margin-bottom:20px; }
        .feature-card { background: white; border-radius: 24px; padding: 30px; border: 1px solid rgba(0,0,0,0.04); box-shadow: 0 10px 30px rgba(0,0,0,0.035); height: 100%; transition: .25s; }
        .feature-card:hover { transform: translateY(-5px); border-color: var(--primary); box-shadow: 0 15px 35px rgba(99,102,241,.12); }
        .feature-card h4 { font-weight: 800; margin-bottom: 10px; font-size: 1.18rem; }

        .demo-box { border-radius:34px; background:linear-gradient(135deg,#111827,#1e1b4b); padding:18px; box-shadow:0 30px 80px rgba(15,23,42,.28); }
        .demo-screen { background:#fff; border-radius:26px; padding:22px; overflow:hidden; }
        .fake-sidebar { width:12px; height:12px; border-radius:50%; background:#ef4444; box-shadow:20px 0 #f59e0b, 40px 0 #10b981; margin-bottom:18px; }
        .metric-card { border-radius:18px; background:#f8fafc; padding:18px; border:1px solid #e2e8f0; }
        .fake-bar { height:10px; border-radius:999px; background:#e2e8f0; overflow:hidden; }
        .fake-bar span { display:block; height:100%; border-radius:999px; background:var(--gradient); }
        .map-placeholder { min-height:230px; border-radius:22px; background: radial-gradient(circle at 30% 25%, rgba(236,72,153,.45), transparent 18%), radial-gradient(circle at 70% 50%, rgba(99,102,241,.48), transparent 20%), radial-gradient(circle at 50% 80%, rgba(16,185,129,.45), transparent 16%), #e0e7ff; position:relative; overflow:hidden; }
        .map-placeholder:after { content:"Mapa de Calor"; position:absolute; right:18px; bottom:16px; background:#fff; padding:8px 13px; border-radius:999px; font-size:.8rem; font-weight:900; box-shadow:0 8px 20px rgba(0,0,0,.12); }

        .pricing-section { background-color: #f8fafc; overflow: hidden; }
        .planos-wrapper { display: flex; flex-wrap: nowrap; justify-content: center; gap: 20px; overflow-x: auto; padding: 24px 5px 42px; scrollbar-width: thin; -webkit-overflow-scrolling: touch; }
        .planos-wrapper::-webkit-scrollbar { height: 8px; }
        .planos-wrapper::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .col-plano { flex: 1 1 0; min-width: 290px; max-width: 340px; }
        .pricing-card { background:#fff; border-radius:26px; padding:36px 22px 26px; box-shadow:0 10px 30px rgba(15,23,42,.06); transition:.25s; border:1px solid #e2e8f0; height:100%; display:flex; flex-direction:column; position:relative; }
        .pricing-card:hover { transform:translateY(-6px); border-color:var(--primary); box-shadow:0 24px 50px rgba(99,102,241,.14); }
        .pricing-card.popular { border:2px solid #10b981; }
        .pricing-card.premium { border:2px solid #6366f1; background:linear-gradient(to bottom,#fff,#f5f7ff); }
        .pricing-header h3 { font-size:1.08rem; font-weight:900; text-transform:uppercase; letter-spacing:1px; margin-bottom:14px; }
        .pricing-price { display:flex; align-items:baseline; justify-content:center; margin-bottom:18px; min-height:54px; }
        .pricing-price .currency { font-size:1rem; font-weight:700; margin-right:3px; }
        .pricing-price .amount { font-size:2.8rem; font-weight:900; letter-spacing:-2px; line-height:1; }
        .pricing-price .period { color:var(--text-muted); font-size:.8rem; margin-left:3px; font-weight:600; }
        .pricing-price .text-custom { font-size:2rem; font-weight:900; line-height:1; text-align:center; width:100%; }
        .pricing-features-container { flex-grow:1; margin-bottom:24px; max-height: 430px; overflow:auto; padding-right:4px; }
        .pricing-features ul { list-style:none; padding:0; margin:0; }
        .pricing-features li { display:flex; align-items:flex-start; margin-bottom:11px; font-size:.86rem; line-height:1.3; text-align:left; }
        .pricing-features li i { margin-right:8px; flex-shrink:0; font-size:.9rem; margin-top:2px; }
        .feature-on { color:var(--text-main); font-weight:600; }
        .feature-on i { color:#10b981; }
        .feature-off { color:#94a3b8; text-decoration:line-through; text-decoration-color:#ef4444; opacity:.68; }
        .feature-off i { color:#ef4444; opacity:.65; }
        .btn-pricing { width:100%; padding:15px; border-radius:999px; font-weight:900; transition:.25s; font-size:.92rem; }
        .btn-outline-custom { border:2px solid #e2e8f0; color:var(--text-main); background:#fff; }
        .btn-outline-custom:hover { border-color:var(--text-main); background:var(--text-main); color:#fff; }
        .btn-popular { background:#10b981; color:#fff; border:none; box-shadow:0 8px 20px rgba(16,185,129,.25); }
        .btn-popular:hover { background:#059669; transform:translateY(-2px); color:#fff; }
        .btn-premium { background:var(--primary); color:#fff; border:none; box-shadow:0 8px 20px rgba(99,102,241,.25); }
        .btn-premium:hover { background:var(--primary-dark); transform:translateY(-2px); color:#fff; }
        .badge-popular, .badge-premium, .badge-custom { position:absolute; top:-14px; left:50%; transform:translateX(-50%); z-index:10; color:#fff; padding:7px 15px; border-radius:999px; font-size:.72rem; font-weight:900; text-transform:uppercase; letter-spacing:1px; white-space:nowrap; }
        .badge-popular { background:#10b981; box-shadow:0 8px 20px rgba(16,185,129,.28); }
        .badge-premium { background:var(--gradient); box-shadow:0 8px 20px rgba(99,102,241,.28); }
        .badge-custom { background:linear-gradient(90deg,#3b82f6,#8b5cf6); }

        .faq-item { background:#fff; border:1px solid #e2e8f0; border-radius:20px; padding:24px; height:100%; }
        .cta-final { background:linear-gradient(135deg,#111827,#1e1b4b); border-radius:36px; color:#fff; padding:55px 30px; position:relative; overflow:hidden; }
        .cta-final:before { content:""; position:absolute; width:420px; height:420px; border-radius:50%; background:rgba(236,72,153,.25); right:-120px; top:-150px; filter:blur(20px); }

        .contrato-box { background:#f8fafc; border:1px solid #e2e8f0; border-radius:14px; padding:25px; height:320px; overflow-y:auto; font-size:.9rem; text-align:justify; }
        footer { background:var(--dark-bg); color:#fff; padding:80px 0 30px; }
        .mobile-cta { position:fixed; bottom:14px; left:14px; right:14px; z-index:9999; }
        .mobile-cta button { width:100%; border:none; padding:16px; border-radius:18px; font-weight:900; background:var(--gradient); color:white; box-shadow:0 12px 30px rgba(99,102,241,.42); }

        @keyframes float { 0% { transform:translateY(0); } 50% { transform:translateY(-14px); } 100% { transform:translateY(0); } }
        .floaty { animation: float 6s ease-in-out infinite; }

        @media (max-width: 991px) {
            .hero-conversion { padding:130px 0 60px; min-height:auto; }
            .floating-card { display:none; }
            .planos-wrapper { justify-content:flex-start; }
        }
        @media (max-width: 575px) {
            .section-pad { padding:65px 0; }
            .hero-subtitle { font-size:1rem; }
            .btn-gradient, .btn-soft { width:100%; text-align:center; justify-content:center; }
            body { padding-bottom:78px; }
        }
    </style>

    <!-- Meta Pixel Code -->
    <script>
    !function(f,b,e,v,n,t,s)
    {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};
    if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
    n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];
    s.parentNode.insertBefore(t,s)}(window, document,'script',
    'https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', '1687740479022420');
    fbq('track', 'PageView');
    </script>
    <noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=1687740479022420&ev=PageView&noscript=1" /></noscript>
    <!-- End Meta Pixel Code -->
</head>
<body>

<?php if(!empty($erro_cadastro)): ?>
    <div class="alert alert-danger alert-dismissible fade show fixed-top m-3 shadow-lg" role="alert" style="z-index: 99999;">
        <h5 class="alert-heading fw-bold"><i class="fas fa-exclamation-triangle"></i> Atenção! Não foi possível concluir o cadastro.</h5>
        <p class="mb-0"><?= htmlspecialchars($erro_cadastro) ?></p>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            var modal = new bootstrap.Modal(document.getElementById('modalCadastro'));
            document.getElementById('step-contract').style.display = 'none';
            document.getElementById('step-form').style.display = 'block';
            modal.show();
        });
    </script>
<?php endif; ?>

<nav class="navbar navbar-expand-lg fixed-top">
    <div class="container">
        <a class="navbar-brand" href="#topo"><i class="fas fa-layer-group me-2"></i>Gabinete<span class="fw-light">Digital</span></a>
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"><i class="fas fa-bars"></i></button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto align-items-center">
                <li class="nav-item"><a class="nav-link" href="#problema">Problema</a></li>
                <li class="nav-item"><a class="nav-link" href="#sistema">Sistema</a></li>
                <li class="nav-item"><a class="nav-link" href="#recursos">Funcionalidades</a></li>
                <li class="nav-item"><a class="nav-link" href="#planos">Planos</a></li>
                <li class="nav-item ms-lg-2"><a href="login.php" class="btn btn-outline-primary rounded-pill px-4 btn-sm fw-bold">Área do Cliente</a></li>
                <li class="nav-item ms-lg-2 mt-2 mt-lg-0"><a href="#planos" class="btn btn-gradient btn-sm px-4 py-2">Teste grátis</a></li>
            </ul>
        </div>
    </div>
</nav>

<header id="topo" class="hero-conversion">
    <div class="container position-relative" style="z-index:2;">
        <div class="row align-items-center gy-5">
            <div class="col-lg-6" data-aos="fade-right">
                <div class="hero-badge mb-4"><i class="fas fa-bolt text-warning"></i> +50 mil solicitações já organizadas na plataforma</div>
                <h1 class="hero-title mb-4">Pare de perder demanda de eleitor no <span class="text-gradient">WhatsApp e no papel</span>.</h1>
                <p class="hero-subtitle mb-4">Centralize CRM de eleitores, ouvidoria, mapa de calor, agenda e site do mandato em um só painel — e mostre resultado real pra sua equipe e pra população.</p>

                <div class="row g-3 mb-5">
                    <div class="col-6"><div class="text-white fw-bold"><i class="fas fa-check-circle text-success me-2"></i>Sem fidelidade</div></div>
                    <div class="col-6"><div class="text-white fw-bold"><i class="fas fa-check-circle text-success me-2"></i>Pronto em minutos</div></div>
                    <div class="col-6"><div class="text-white fw-bold"><i class="fas fa-check-circle text-success me-2"></i>Funciona no celular</div></div>
                    <div class="col-6"><div class="text-white fw-bold"><i class="fas fa-check-circle text-success me-2"></i>Suporte humanizado</div></div>
                </div>

                <div class="d-flex flex-wrap gap-3">
                    <button onclick="document.getElementById('planos').scrollIntoView({behavior:'smooth'})" class="btn btn-gradient btn-lg">QUERO ORGANIZAR MEU MANDATO</button>
                    <a href="#sistema" class="btn btn-soft btn-lg"><i class="fas fa-desktop me-2"></i>VER O SISTEMA FUNCIONANDO</a>
                </div>

                <div class="trust-row">
                    <div class="row text-center text-lg-start g-3">
                        <div class="col-4"><div class="trust-number">+50k</div><small class="text-white-50 fw-semibold">solicitações registradas</small></div>
                        <div class="col-4"><div class="trust-number">24h</div><small class="text-white-50 fw-semibold">gabinete online</small></div>
                        <div class="col-4"><div class="trust-number">100%</div><small class="text-white-50 fw-semibold">responsivo</small></div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6" data-aos="fade-left">
                <div class="hero-panel floaty">
                    <div class="floating-card one"><i class="fas fa-bell text-danger me-2"></i>Nova solicitação recebida</div>
                    <div class="floating-card two"><i class="fas fa-chart-line text-success me-2"></i>Base organizada</div>

                    <?php
                        $heroImg = 'assets/img/mockup_dashboard.png';
                        if (!empty($hero_banners[0]['caminho_imagem'])) {
                            $heroImg = $hero_banners[0]['caminho_imagem'];
                        }
                    ?>
                    <img src="<?= htmlspecialchars($heroImg) ?>" alt="Painel do Gabinete Digital" onerror="this.style.display='none'; document.getElementById('heroFallback').style.display='block';">

                    <div id="heroFallback" style="display:none;">
                        <div class="demo-screen">
                            <div class="fake-sidebar"></div>
                            <div class="row g-3 mb-3">
                                <div class="col-6"><div class="metric-card"><small class="text-muted fw-bold">Solicitações</small><h3 class="fw-bold mb-0">1.248</h3></div></div>
                                <div class="col-6"><div class="metric-card"><small class="text-muted fw-bold">Eleitores</small><h3 class="fw-bold mb-0">8.932</h3></div></div>
                            </div>
                            <div class="map-placeholder mb-3"></div>
                            <div class="fake-bar mb-2"><span style="width:86%"></span></div>
                            <div class="fake-bar mb-2"><span style="width:63%"></span></div>
                            <div class="fake-bar"><span style="width:74%"></span></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>

<section id="problema" class="section-pad bg-white">
    <div class="container">
        <div class="text-center mb-5" data-aos="fade-up">
            <span class="section-eyebrow bg-danger bg-opacity-10 text-danger mb-3">Problema real</span>
            <h2 class="display-5 fw-bold mb-3">Seu gabinete ainda funciona no WhatsApp, papel e planilha?</h2>
            <p class="text-muted mx-auto" style="max-width:720px;">Sem um sistema, demandas se perdem, a equipe fica desorganizada e você não consegue medir o que está acontecendo no mandato.</p>
        </div>
        <div class="row g-4">
            <div class="col-lg-3 col-md-6" data-aos="fade-up"><div class="card-soft text-center"><div class="feature-icon mx-auto text-danger" style="background:#fee2e2;"><i class="fas fa-times-circle"></i></div><h4 class="fw-bold">Demandas perdidas</h4><p class="text-muted mb-0">Pedidos da população esquecidos ou sem acompanhamento.</p></div></div>
            <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="80"><div class="card-soft text-center"><div class="feature-icon mx-auto text-warning" style="background:#fef3c7;"><i class="fas fa-users"></i></div><h4 class="fw-bold">Equipe sem controle</h4><p class="text-muted mb-0">Atendimentos espalhados e tarefas sem responsável claro.</p></div></div>
            <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="160"><div class="card-soft text-center"><div class="feature-icon mx-auto text-primary" style="background:#dbeafe;"><i class="fas fa-map"></i></div><h4 class="fw-bold">Sem visão da base</h4><p class="text-muted mb-0">Você não sabe onde estão apoiadores, bairros fortes e regiões com demandas.</p></div></div>
            <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="240"><div class="card-soft text-center"><div class="feature-icon mx-auto text-success" style="background:#dcfce7;"><i class="fas fa-bullhorn"></i></div><h4 class="fw-bold">Comunicação fraca</h4><p class="text-muted mb-0">Pouca interação e baixa aproximação com a população.</p></div></div>
        </div>
    </div>
</section>

<section id="sistema" class="section-pad" style="background:#f1f5f9;">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-6" data-aos="fade-right">
                <span class="section-eyebrow bg-primary bg-opacity-10 text-primary mb-3">Sistema completo</span>
                <h2 class="display-5 fw-bold mb-4">O problema não é sua equipe. É não ter um sistema pra sustentar o mandato.</h2>
                <p class="lead text-muted mb-5">Com o Gabinete Digital, cada solicitação, eleitor e compromisso fica registrado em um painel só — sua equipe atende mais rápido e você acompanha tudo em tempo real.</p>

                <div class="d-flex mb-4"><div class="me-3"><div class="feature-icon text-primary" style="background:#e0e7ff;"><i class="fas fa-users"></i></div></div><div><h5 class="fw-bold">CRM de Eleitores</h5><p class="text-muted mb-0">Organize contatos, bairros, lideranças, histórico e relacionamento.</p></div></div>
                <div class="d-flex mb-4"><div class="me-3"><div class="feature-icon text-success" style="background:#dcfce7;"><i class="fas fa-map-marked-alt"></i></div></div><div><h5 class="fw-bold">Mapa de Calor</h5><p class="text-muted mb-0">Visualize regiões mais ativas e acompanhe demandas por localidade.</p></div></div>
                <div class="d-flex"><div class="me-3"><div class="feature-icon text-danger" style="background:#fee2e2;"><i class="fas fa-comments"></i></div></div><div><h5 class="fw-bold">Ouvidoria Online</h5><p class="text-muted mb-0">Receba solicitações diretamente pelo portal do mandato.</p></div></div>
            </div>
            <div class="col-lg-6" data-aos="fade-left">
                <div class="demo-box">
                    <div class="demo-screen">
                        <div class="fake-sidebar"></div>
                        <div class="row g-3 mb-4">
                            <div class="col-md-4"><div class="metric-card"><small class="text-muted fw-bold">Eleitores</small><h3 class="fw-bold mb-0">8.932</h3></div></div>
                            <div class="col-md-4"><div class="metric-card"><small class="text-muted fw-bold">Demandas</small><h3 class="fw-bold mb-0">1.248</h3></div></div>
                            <div class="col-md-4"><div class="metric-card"><small class="text-muted fw-bold">Concluídas</small><h3 class="fw-bold mb-0">87%</h3></div></div>
                        </div>
                        <div class="map-placeholder mb-4"></div>
                        <div class="row g-3">
                            <div class="col-6"><div class="fake-bar mb-2"><span style="width:92%"></span></div><small class="text-muted fw-bold">Atendimentos</small></div>
                            <div class="col-6"><div class="fake-bar mb-2"><span style="width:72%"></span></div><small class="text-muted fw-bold">Agenda política</small></div>
                        </div>
                    </div>
                </div>
                <p class="text-center text-muted small mt-3 mb-0">Dica: depois você pode trocar esta prévia por um print real do painel.</p>
            </div>
        </div>
    </div>
</section>

<section id="recursos" class="section-pad bg-white">
    <div class="container">
        <div class="text-center mb-5" data-aos="fade-up">
            <span class="section-eyebrow bg-primary bg-opacity-10 text-primary mb-3">Funcionalidades</span>
            <h2 class="display-5 fw-bold mb-3">Tudo que seu mandato precisa em um só lugar</h2>
            <p class="text-muted mx-auto" style="max-width:680px;">Menos bagunça operacional. Mais controle, atendimento, comunicação e resultado.</p>
        </div>
        <div class="row g-4">
            <div class="col-lg-4 col-md-6"><div class="feature-card"><div class="feature-icon text-primary" style="background:#e0e7ff;"><i class="fas fa-users-cog"></i></div><h4>CRM de Eleitores</h4><p class="text-muted mb-0">Nunca mais perca o histórico de um eleitor. Bairro, pedido, contato — tudo buscável em segundos.</p></div></div>
            <div class="col-lg-4 col-md-6"><div class="feature-card"><div class="feature-icon text-danger" style="background:#fee2e2;"><i class="fas fa-map-marked-alt"></i></div><h4>Mapa de Calor</h4><p class="text-muted mb-0">Descubra em qual bairro sua base está mais forte e onde as demandas se concentram.</p></div></div>
            <div class="col-lg-4 col-md-6"><div class="feature-card"><div class="feature-icon text-success" style="background:#dcfce7;"><i class="fas fa-bullhorn"></i></div><h4>Ouvidoria Online</h4><p class="text-muted mb-0">A população registra o pedido sozinha, pelo celular — sua equipe só acompanha e resolve.</p></div></div>
            <div class="col-lg-4 col-md-6"><div class="feature-card"><div class="feature-icon text-info" style="background:#e0f2fe;"><i class="fas fa-laptop-code"></i></div><h4>Site do Mandato</h4><p class="text-muted mb-0">Tenha um endereço oficial pra mostrar transparência e projetos sem depender de rede social.</p></div></div>
            <div class="col-lg-4 col-md-6"><div class="feature-card"><div class="feature-icon text-warning" style="background:#fef3c7;"><i class="fas fa-calendar-alt"></i></div><h4>Agenda Política</h4><p class="text-muted mb-0">Sua equipe sabe exatamente onde você está e o que vem a seguir — sem troca de mensagem.</p></div></div>
            <div class="col-lg-4 col-md-6"><div class="feature-card"><div class="feature-icon" style="color:#ec4899;background:#fce7f3;"><i class="fas fa-wand-magic-sparkles"></i></div><h4>Redator IA e Artes</h4><p class="text-muted mb-0">Gere ofício, post e material de comunicação em minutos, sem depender de terceiros.</p></div></div>
        </div>
    </div>
</section>

<section class="section-pad" style="background:#0f172a;color:#fff;">
    <div class="container">
        <div class="text-center mb-5" data-aos="fade-up">
            <span class="section-eyebrow mb-3" style="background:rgba(255,255,255,.1);color:#fff;">Depois do Gabinete Digital</span>
            <h2 class="display-5 fw-bold">Seu mandato deixa de apagar incêndio e começa a trabalhar com dados.</h2>
        </div>
        <div class="row g-4">
            <div class="col-md-4"><div class="p-4 rounded-4 h-100" style="background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.1);"><h4 class="fw-bold"><i class="fas fa-check-circle text-success me-2"></i>Mais organização</h4><p class="text-white-50 mb-0">Tudo centralizado para a equipe trabalhar com clareza.</p></div></div>
            <div class="col-md-4"><div class="p-4 rounded-4 h-100" style="background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.1);"><h4 class="fw-bold"><i class="fas fa-check-circle text-success me-2"></i>Mais presença</h4><p class="text-white-50 mb-0">A população encontra um canal oficial para falar com o mandato.</p></div></div>
            <div class="col-md-4"><div class="p-4 rounded-4 h-100" style="background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.1);"><h4 class="fw-bold"><i class="fas fa-check-circle text-success me-2"></i>Mais resultado</h4><p class="text-white-50 mb-0">Você acompanha indicadores, demandas e ações com mais precisão.</p></div></div>
        </div>
    </div>
</section>

<section class="section-pad bg-white">
    <div class="container">
        <div class="text-center mb-5" data-aos="fade-up">
            <span class="section-eyebrow bg-success bg-opacity-10 text-success mb-3">Quem já usa</span>
            <h2 class="display-5 fw-bold mb-3">Gabinetes que saíram do WhatsApp e organizaram o mandato</h2>
        </div>
        <div class="row g-4">
            <div class="col-lg-4" data-aos="fade-up">
                <div class="card-soft h-100">
                    <div class="d-flex align-items-center mb-3">
                        <div class="feature-icon text-primary me-3" style="width:48px;height:48px;font-size:18px;background:#e0e7ff;"><i class="fas fa-quote-left"></i></div>
                        <div><strong class="d-block">Guilherme Bueno</strong><span class="text-muted small">Vereador em Nova Serrana MG</span></div>
                    </div>
                    <p class="text-muted mb-0">O sistema mudou completamento a organização do gabinete!</p>
                </div>
            </div>
            <div class="col-lg-4" data-aos="fade-up" data-aos-delay="80">
                <div class="card-soft h-100">
                    <div class="d-flex align-items-center mb-3">
                        <div class="feature-icon text-success me-3" style="width:48px;height:48px;font-size:18px;background:#dcfce7;"><i class="fas fa-quote-left"></i></div>
                        <div><strong class="d-block">Nathan Oliveira</strong><span class="text-muted small">Vereador Nova Serrana MG</span></div>
                    </div>
                    <p class="text-muted mb-0">Recomendo muito o sistema, porque aproxima o gabinete à população!</p>
                </div>
            </div>
            <div class="col-lg-4" data-aos="fade-up" data-aos-delay="160">
                <div class="card-soft h-100">
                    <div class="d-flex align-items-center mb-3">
                        <div class="feature-icon me-3" style="width:48px;height:48px;font-size:18px;color:#ec4899;background:#fce7f3;"><i class="fas fa-quote-left"></i></div>
                        <div><strong class="d-block">.</strong><span class="text-muted small">.</span></div>
                    </div>
                    <p class="text-muted mb-0">.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<section id="planos" class="pricing-section section-pad">
    <div class="container-fluid px-xl-5">
        <div class="text-center mb-4" data-aos="fade-up">
            <span class="section-eyebrow bg-primary bg-opacity-10 text-primary mb-3">Sem fidelidade</span>
            <h2 class="display-5 fw-bold text-dark">Escolha seu plano e comece hoje</h2>
            <p class="text-muted">Teste, assine e coloque seu gabinete digital para funcionar.</p>
        </div>

        <div class="planos-wrapper" data-aos="fade-up">
            <?php foreach($planos_db as $plano):
                $is_free = ($plano['preco'] <= 0 && empty($plano['preco_texto']));
                $is_popular = ($plano['preco'] > 100 && $plano['preco'] < 250);
                $is_premium = ($plano['preco'] > 250);
                $card_class = "";
                $btn_class = "btn-outline-custom";
                if($is_popular) { $card_class = "popular"; $btn_class = "btn-popular"; }
                elseif($is_premium) { $card_class = "premium"; $btn_class = "btn-premium"; }

                $beneficios_raw = array_map('trim', array_filter(explode("\n", $plano['beneficios'])));
                $limites_do_plano = array_filter($beneficios_raw, function($f) { return preg_match('/\d/', $f); });
                $plan_mods = json_decode($plano['modulos'] ?? '[]', true) ?? [];
                if (!is_array($plan_mods)) $plan_mods = [];
                if(in_array('landing', $plan_mods)) $plan_mods[] = 'landing_pages';

                $modulos_inclusos = [];
                $modulos_excluidos = [];
                foreach($modulos_sistema as $chave => $nome_oficial) {
                    $is_free_module = in_array($chave, ['tutoriais', 'links', 'qrcode', 'financeiro', 'config']);
                    if ($is_free_module || in_array($chave, $plan_mods)) $modulos_inclusos[] = $nome_oficial;
                    else $modulos_excluidos[] = $nome_oficial;
                }
            ?>
            <div class="col-plano d-flex align-items-stretch">
                <div class="pricing-card w-100 <?= $card_class ?>">
                    <?php if(!empty($plano['badge'])): ?><div class="badge-custom"><?= htmlspecialchars($plano['badge']) ?></div>
                    <?php elseif($is_popular): ?><div class="badge-popular">Mais escolhido</div>
                    <?php elseif($is_premium): ?><div class="badge-premium">Mais completo</div><?php endif; ?>

                    <div class="pricing-header text-center mt-2">
                        <h3><?= htmlspecialchars($plano['nome']) ?></h3>
                        <div class="pricing-price">
                            <?php if (!empty($plano['preco_texto'])): ?><span class="text-custom"><?= htmlspecialchars($plano['preco_texto']) ?></span>
                            <?php elseif ($is_free): ?><span class="text-custom">Grátis</span>
                            <?php else: ?><span class="currency">R$</span><span class="amount"><?= number_format($plano['preco'], 0, ',', '.') ?></span><span class="period">,<?= substr(number_format($plano['preco'], 2, ',', '.'), -2) ?> /mês</span><?php endif; ?>
                        </div>
                    </div>

                    <div class="pricing-features-container">
                        <div class="pricing-features">
                            <ul>
                                <?php foreach($limites_do_plano as $limite): ?><li class="feature-on"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($limite) ?></li><?php endforeach; ?>
                                <?php foreach($modulos_inclusos as $nome_incluso): ?><li class="feature-on"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($nome_incluso) ?></li><?php endforeach; ?>
                                <?php foreach($modulos_excluidos as $nome_excluido): ?><li class="feature-off"><i class="fas fa-times-circle"></i> <?= htmlspecialchars($nome_excluido) ?></li><?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    <div class="pricing-action">
                        <button onclick="abrirModal('<?= $is_free ? 'teste' : 'pagamento' ?>', '<?= htmlspecialchars($plano['slug'], ENT_QUOTES) ?>')" class="btn-pricing <?= $btn_class ?>"><?= $is_free ? 'COMEÇAR TESTE GRÁTIS' : 'ASSINAR AGORA' ?></button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="text-center mt-2 d-lg-none text-muted small"><i class="fas fa-arrows-alt-h me-1"></i> Deslize para o lado para ver todos os planos</div>

        <div class="row justify-content-center mt-5" data-aos="fade-up">
            <div class="col-lg-8">
                <div class="d-flex align-items-center gap-4 flex-wrap justify-content-center p-4 rounded-4 bg-white shadow-sm border">
                    <div class="d-flex align-items-center gap-2"><i class="fas fa-shield-halved fa-lg text-primary"></i><span class="fw-bold small">Sem fidelidade — cancele quando quiser</span></div>
                    <div class="d-flex align-items-center gap-2"><i class="fas fa-bolt fa-lg text-success"></i><span class="fw-bold small">Ambiente liberado assim que o pagamento confirma</span></div>
                    <div class="d-flex align-items-center gap-2"><i class="fas fa-lock fa-lg text-danger"></i><span class="fw-bold small">Dados protegidos conforme a LGPD</span></div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section-pad bg-white">
    <div class="container">
        <div class="text-center mb-5">
            <span class="section-eyebrow bg-success bg-opacity-10 text-success mb-3">Dúvidas frequentes</span>
            <h2 class="display-5 fw-bold">Perguntas antes de começar</h2>
        </div>
        <div class="row g-4">
            <div class="col-md-6 col-lg-4"><div class="faq-item"><h5 class="fw-bold">Minha equipe não é boa com sistema, vai dar trabalho?</h5><p class="text-muted mb-0">Não. A navegação é simples e o suporte te acompanha na configuração inicial e no treinamento da equipe.</p></div></div>
            <div class="col-md-6 col-lg-4"><div class="faq-item"><h5 class="fw-bold">Dá pra migrar os dados que já tenho em planilha?</h5><p class="text-muted mb-0">Sim. É só nos enviar a planilha atual que a equipe de suporte ajuda a importar os eleitores e contatos.</p></div></div>
            <div class="col-md-6 col-lg-4"><div class="faq-item"><h5 class="fw-bold">Se eu atrasar a mensalidade, perco tudo?</h5><p class="text-muted mb-0">Não. O acesso fica suspenso temporariamente após 5 dias de atraso, mas seus dados continuam salvos.</p></div></div>
            <div class="col-md-6 col-lg-4"><div class="faq-item"><h5 class="fw-bold">Serve pra qualquer cargo, não só vereador?</h5><p class="text-muted mb-0">Sim. Funciona para vereadores, prefeitos, deputados e qualquer mandato que precise organizar demandas e base.</p></div></div>
            <div class="col-md-6 col-lg-4"><div class="faq-item"><h5 class="fw-bold">Posso cancelar quando quiser?</h5><p class="text-muted mb-0">Sim, sem tempo mínimo de permanência. Você solicita o cancelamento e ainda exporta seus dados.</p></div></div>
            <div class="col-md-6 col-lg-4"><div class="faq-item"><h5 class="fw-bold">Os dados dos eleitores ficam seguros?</h5><p class="text-muted mb-0">Sim. Controle de acesso, backup e tratamento de dados conforme a LGPD — você é o controlador dos dados da sua base.</p></div></div>
        </div>
    </div>
</section>

<section class="pb-5 bg-white">
    <div class="container">
        <div class="cta-final text-center">
            <div class="position-relative" style="z-index:2;">
                <h2 class="display-5 fw-bold mb-3">Seu mandato continua apagando incêndio ou começa a trabalhar organizado hoje?</h2>
                <p class="lead text-white-50 mx-auto mb-4" style="max-width:680px;">Transforme pedidos soltos em solicitações organizadas, dados reais e mais proximidade com a população — sem fidelidade e sem risco.</p>
                <button onclick="document.getElementById('planos').scrollIntoView({behavior:'smooth'})" class="btn btn-gradient btn-lg px-5">QUERO ORGANIZAR MEU MANDATO AGORA</button>
            </div>
        </div>
    </div>
</section>

<footer>
    <div class="container">
        <div class="row g-5">
            <div class="col-lg-4"><h4 class="fw-bold mb-4"><i class="fas fa-layer-group text-primary me-2"></i>Gabinete Digital</h4><p class="text-white-50">Tecnologia para organização, comunicação e resultado no mandato.</p></div>
            <div class="col-lg-2 ms-auto"><h6 class="fw-bold mb-3">Links</h6><ul class="list-unstyled text-white-50 d-flex flex-column gap-2"><li><a href="#recursos" class="text-reset">Recursos</a></li><li><a href="#planos" class="text-reset">Preços</a></li><li><a href="login.php" class="text-reset">Login</a></li></ul></div>
            <div class="col-lg-3"><h6 class="fw-bold mb-3">Contato</h6><ul class="list-unstyled text-white-50 d-flex flex-column gap-2"><li><i class="fab fa-whatsapp me-2"></i> 37 99912 9477</li></ul></div>
        </div>
        <div class="border-top border-white border-opacity-10 mt-5 pt-4 text-center text-white-50 small">&copy; <?= date('Y') ?> Gabinete Digital.</div>
    </div>
</footer>

<div class="modal fade" id="modalCadastro" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 rounded-4 overflow-hidden">
            <div class="modal-header"><h5 class="modal-title fw-bold">Criar Nova Conta</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body p-4 p-lg-5 bg-white text-dark">
                <div id="step-contract">
                    <div class="text-center mb-4">
                        <h4 class="fw-bold">Termos e Condições</h4>
                        <p class="text-muted">Para continuar, aceite os termos de uso da plataforma.</p>
                    </div>
                    <div class="contrato-box mb-4 text-start bg-light border p-4 rounded">
                        <h5 class="text-center fw-bold mb-4">CONTRATO DE LICENÇA DE USO (SaaS)</h5>
                        <h6 class="fw-bold text-primary">1. OBJETO</h6><p class="small text-muted mb-3">O presente instrumento tem por objeto a concessão de licença de uso temporária, não exclusiva e intransferível do software "Gabinete Digital", hospedado na nuvem (SaaS), voltado para a gestão de mandatos políticos.</p>
                        <h6 class="fw-bold text-primary">2. DISPONIBILIDADE E ACESSO</h6><p class="small text-muted mb-3">O sistema ficará disponível 24 horas por dia, 7 dias por semana, garantindo um SLA de 99% de uptime, exceto em janelas de manutenção previamente agendadas. O acesso se dará por subdomínio exclusivo do CONTRATANTE.</p>
                        <h6 class="fw-bold text-primary">3. PLANOS E PAGAMENTOS</h6><p class="small text-muted mb-3">A utilização do sistema está condicionada ao pagamento antecipado da mensalidade referente ao plano escolhido. O não pagamento acarretará na suspensão temporária do acesso após 5 dias de atraso.</p>
                        <h6 class="fw-bold text-primary">4. PRIVACIDADE E LGPD</h6><p class="small text-muted mb-3">Em conformidade com a Lei Geral de Proteção de Dados, a CONTRATADA garante o sigilo dos dados inseridos na plataforma. O CONTRATANTE é o controlador dos dados de seus eleitores.</p>
                        <h6 class="fw-bold text-primary">5. SUPORTE TÉCNICO</h6><p class="small text-muted mb-3">O suporte técnico será prestado via WhatsApp e e-mail, em horário comercial, cobrindo dúvidas de utilização e correções de eventuais falhas.</p>
                        <h6 class="fw-bold text-primary">6. CANCELAMENTO E FIDELIDADE</h6><p class="small text-muted mb-0">Não exigimos tempo mínimo de permanência. O CONTRATANTE poderá solicitar o cancelamento a qualquer momento e exportar seus dados.</p>
                    </div>
                    <div class="form-check p-3 border rounded bg-light mb-4">
                        <input class="form-check-input" type="checkbox" id="checkTermos" onchange="liberarBotao()">
                        <label class="form-check-label fw-bold ms-2" for="checkTermos">Li e concordo com os termos de uso.</label>
                    </div>
                    <button id="btnAceitar" class="btn btn-dark w-100 py-3 rounded-pill fw-bold shadow" disabled onclick="avancarParaForm()">CONCORDAR E CONTINUAR</button>
                </div>

                <form method="POST" id="step-form" style="display:none;">
                    <input type="hidden" name="acao" id="acaoInput">
                    <input type="hidden" name="plano_escolhido" id="planoInput">
                    <div class="text-center mb-4"><h4 class="fw-bold">Crie seu gabinete digital</h4><p class="text-muted mb-0">Preencha os dados abaixo para liberar seu ambiente.</p></div>
                    <?php $cupom_url = isset($_GET['cupom']) ? htmlspecialchars($_GET['cupom']) : ''; ?>
                    <div class="row g-3">
                        <div class="col-md-6"><label class="small fw-bold text-muted">NOME DO POLÍTICO</label><input type="text" name="nome" class="form-control form-control-lg bg-light border-0" required></div>
                        <div class="col-md-6"><label class="small fw-bold text-muted">WHATSAPP OFICIAL</label><input type="text" name="whatsapp" class="form-control form-control-lg bg-light border-0" required></div>
                        <div class="col-12"><label class="small fw-bold text-muted">E-MAIL / LOGIN</label><input type="email" name="email" class="form-control form-control-lg bg-light border-0" required></div>
                        <div class="col-md-6"><label class="small fw-bold text-muted">CRIE UMA SENHA</label><input type="password" name="senha" class="form-control form-control-lg bg-light border-0" required></div>
                        <div class="col-md-6"><label class="small fw-bold text-muted">NOME DO SITE</label><div class="input-group"><input type="text" name="slug" class="form-control form-control-lg bg-light border-0" required placeholder="ex: joaosilva"><span class="input-group-text border-0 small bg-light text-muted">.<?= str_replace('www.', '', $_SERVER['HTTP_HOST']) ?></span></div></div>
                        <div class="col-12"><label class="small fw-bold text-muted">CUPOM DE DESCONTO</label><input type="text" name="cupom" class="form-control form-control-lg bg-light border-0" value="<?= $cupom_url ?>" placeholder="Possui um cupom?"><?php if(!empty($cupom_url)): ?><small class="text-success fw-bold mt-1 d-block"><i class="fas fa-check-circle"></i> Cupom detectado! O desconto será aplicado.</small><?php endif; ?></div>
                    </div>
                    <button type="submit" class="btn btn-gradient w-100 py-3 rounded-pill fw-bold mt-5 shadow">FINALIZAR CADASTRO</button>
                    <div class="text-center mt-3"><a href="#" onclick="voltarParaTermos(); return false;" class="text-muted small">Voltar e ler o contrato novamente</a></div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if($msg_cadastro == 'sucesso'): ?>
<div class="modal fade show" style="display:block;background:rgba(15,23,42,.95);z-index:1055;" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 text-center p-4 rounded-4 shadow-lg bg-white text-dark">
            <?php if($is_pago && isset($pix_data) && !isset($pix_data['erro'])): ?>
                <h3 class="fw-bold mb-2 text-dark">Falta pouco! 🚀</h3>
                <?php if(!empty($pix_data['checkout_link'])): ?>
                    <p class="text-muted small mb-4">Clique no botão abaixo para assinar. Assim que o pagamento for confirmado, liberamos seu painel automaticamente.</p>
                    <div class="fs-1 text-success mb-3" style="font-weight:900;">R$ <?= number_format($preco_plano, 2, ",", ".") ?></div>
                    <a href="<?= htmlspecialchars($pix_data['checkout_link']) ?>" class="btn btn-primary w-100 rounded-pill py-3 mb-3 fw-bold" target="_blank" rel="noopener"><i class="fas fa-credit-card me-2"></i> Assinar</a>
                    <div id="status_pagamento" class="bg-light rounded-pill py-2"><div class="spinner-border spinner-border-sm text-primary" role="status"></div><span class="ms-2 fw-bold text-primary small text-uppercase">Aguardando Confirmação...</span></div>
                <?php else: ?>
                    <p class="text-muted small mb-4">Escaneie o QR Code abaixo para liberar seu sistema.</p>
                    <div class="fs-1 text-success mb-3" style="font-weight:900;">R$ <?= number_format($preco_plano, 2, ",", ".") ?></div>
                    <div class="bg-light p-3 rounded-4 d-inline-block mb-3 border"><img src="<?= $pix_data['imagem_base64'] ?>" alt="QR Code" style="width:200px;height:200px;"></div>
                    <button onclick="copiarPix()" class="btn btn-outline-secondary w-100 rounded-pill py-3 mb-4 fw-bold"><i class="fas fa-copy"></i> Copiar Código Pix</button>
                    <div id="status_pagamento" class="bg-light rounded-pill py-2"><div class="spinner-border spinner-border-sm text-primary" role="status"></div><span class="ms-2 fw-bold text-primary small text-uppercase">Aguardando Pagamento...</span></div>
                    <input type="hidden" id="pix_string" value="<?= $pix_data['copia_cola'] ?>">
                <?php endif; ?>
                <input type="hidden" id="tenant_id" value="<?= (int)$tenant_id ?>">
            <?php elseif(isset($pix_data['erro'])): ?>
                <h3 class="text-danger fw-bold">Ops! Algo falhou.</h3><p><?= $pix_data['erro'] ?></p><a href="index.php" class="btn btn-secondary rounded-pill">Tentar novamente</a>
            <?php else: ?>
                <div class="mb-4"><div class="spinner-grow text-success" style="width:4rem;height:4rem;"></div></div>
                <h2 class="fw-bold display-5 mb-3 text-success">Sucesso! 🚀</h2><p class="fs-5 text-muted mb-5">Seu ambiente exclusivo foi criado.</p><a href="<?= $link_direto ?>" class="btn btn-success btn-lg rounded-pill px-5 py-3 fw-bold shadow-lg">ACESSAR MEU PAINEL</a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="mobile-cta d-lg-none"><button onclick="document.getElementById('planos').scrollIntoView({behavior:'smooth'})">ORGANIZAR MEU MANDATO</button></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script>
    AOS.init({ once:true, duration:650 });

    function abrirModal(tipo, planoSlug) {
        var modal = new bootstrap.Modal(document.getElementById('modalCadastro'));
        document.getElementById('acaoInput').value = (tipo === 'teste') ? "criar_teste" : "criar_pagamento";
        document.getElementById('planoInput').value = planoSlug;
        document.getElementById('step-contract').style.display = 'block';
        document.getElementById('step-form').style.display = 'none';
        document.getElementById('checkTermos').checked = false;
        liberarBotao();
        modal.show();
    }
    function liberarBotao() { document.getElementById('btnAceitar').disabled = !document.getElementById('checkTermos').checked; }
    function avancarParaForm() { document.getElementById('step-contract').style.display = 'none'; document.getElementById('step-form').style.display = 'block'; }
    function voltarParaTermos() { document.getElementById('step-contract').style.display = 'block'; document.getElementById('step-form').style.display = 'none'; }

    function copiarPix() {
        const el = document.getElementById("pix_string");
        if (!el) return;
        const txt = el.value || "";
        if (!txt) return;
        navigator.clipboard.writeText(txt).then(() => alert("Código Pix copiado!")).catch(() => {
            el.type = "text"; el.select(); document.execCommand("copy"); el.type = "hidden"; alert("Código Pix copiado!");
        });
    }

    (function iniciarVerificacao() {
        const tenantEl = document.getElementById("tenant_id");
        if (!tenantEl) return;
        const tenantId = tenantEl.value;
        if (!tenantId) return;
        const destino = <?= json_encode($link_painel ?? "login.php") ?>;
        const tick = async () => {
            try {
                const r = await fetch("verifica_status.php?id=" + encodeURIComponent(tenantId), {cache:"no-store"});
                const t = (await r.text()).trim();
                if (t === "ativo") window.location.href = destino || "login.php";
            } catch (e) {}
        };
        tick();
        setInterval(tick, 5000);
    })();
</script>
</body>
</html>