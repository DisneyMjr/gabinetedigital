<?php
// admin.php - PAINEL DE GESTÃO COMPLETO (Ouvvi SaaS)
require 'security.php';
auth_required();
require 'conexao.php';
require 'verificar_assinatura.php'; // Bloqueia se o trial venceu

$tenant_id = (int)$_SESSION['tenant_id'];
$user_nome = $_SESSION['user_nome'] ?? 'Usuário';
$user_nivel = $_SESSION['nivel_acesso'] ?? $_SESSION['user_perfil'] ?? 'operador';
$user_setor_id = $_SESSION['setor_id'] ?? null;

// URL base usada nas notificações
$protocolo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$servidor = $_SERVER['HTTP_HOST'] ?? 'localhost';
$caminho = str_replace('/admin.php', '', $_SERVER['PHP_SELF'] ?? '/admin.php');

// --- 1. PROCESSAR ATUALIZAÇÃO E ENVIAR E-MAILS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    
    // 1A. ATUALIZAÇÃO DA OUVIDORIA E NOTIFICAÇÃO POR E-MAIL
    if (isset($_POST['atualizar_relato'])) {
        $id_relato = (int)$_POST['id_relato'];
        $statusPermitidos = ['novo','triagem','encaminhado','em_analise','aguardando_cidadao','respondido','concluido'];
        $novo_status = $_POST['status'] ?? 'novo';
        if (!in_array($novo_status, $statusPermitidos, true)) $novo_status = 'novo';
        $resposta = trim($_POST['resposta_publica']);

        $stmtUpd = $pdo->prepare("UPDATE reports SET status = ?, resposta_publica = ? WHERE id = ? AND tenant_id = ?");
        $stmtUpd->execute([$novo_status, $resposta, $id_relato, $tenant_id]);
        audit_log($pdo, $tenant_id, (int)($_SESSION['user_id'] ?? 0), 'atualizou_manifestacao', 'reports', $id_relato);
        
        // Disparar E-mail para o Cidadão (se não for anônimo)
        $stmtCidadao = $pdo->prepare("SELECT email, protocolo_visualizacao, is_anonimo FROM reports WHERE id = ?");
        $stmtCidadao->execute([$id_relato]);
        $cidadao = $stmtCidadao->fetch();

        if ($cidadao && $cidadao['is_anonimo'] == 0 && !empty($cidadao['email'])) {
            $link = $meu_link = "{$protocolo}://{$servidor}" . str_replace('/admin.php', '', $_SERVER['PHP_SELF']) . "/acompanhar.php?protocolo=" . $cidadao['protocolo_visualizacao'];
            $assunto = "Atualização no seu Protocolo #" . $cidadao['protocolo_visualizacao'];
            $mensagem_html = "
                <h3>Olá! Há uma atualização na sua manifestação.</h3>
                <p>O status do seu protocolo <b>#{$cidadao['protocolo_visualizacao']}</b> foi alterado para: <b>" . strtoupper($novo_status) . "</b>.</p>
                <p>Para ler a resposta oficial e avaliar nosso atendimento, clique no link abaixo:</p>
                <p><a href='{$link}' style='padding:10px 20px; background:#0d6efd; color:#fff; text-decoration:none; border-radius:5px;'>Acompanhar Protocolo</a></p>
                <br><p><small>Este é um e-mail automático da Ouvidoria.</small></p>
            ";
            $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: no-reply@{$servidor}\r\n";
            @mail($cidadao['email'], $assunto, $mensagem_html, $headers);
        }
        
        header("Location: admin.php?tab=ouvidoria"); 
        exit;
    }

    // 1B. ATUALIZAÇÃO DA LAI
    if (isset($_POST['atualizar_lai'])) {
        $id_lai = (int)$_POST['id_lai'];
        $novo_status = $_POST['status_lai'];
        $resposta_clara = trim($_POST['resposta_publica_lai']);
        
        $iv_b64 = '';
        $resposta_cipher = '';
        if (!empty($resposta_clara)) { $resposta_cipher = encrypt_payload($resposta_clara, $iv_b64); }

        $stmtUpd = $pdo->prepare("UPDATE lai_pedidos SET status = ?, resposta_cipher = ?, resposta_iv = ? WHERE id = ? AND tenant_id = ?");
        $stmtUpd->execute([$novo_status, $resposta_cipher, $iv_b64, $id_lai, $tenant_id]);
        audit_log($pdo, $tenant_id, (int)($_SESSION['user_id'] ?? 0), 'atualizou_lai', 'lai_pedidos', $id_lai);
        header("Location: admin.php?tab=lai");
        exit;
    }

    // 1C. PRORROGAÇÃO DE PRAZO LAI (+10 DIAS)
    if (isset($_POST['prorrogar_lai'])) {
        $id_lai = (int)$_POST['id_lai'];
        $stmtProrrogar = $pdo->prepare("UPDATE lai_pedidos SET prazo_resposta = DATE_ADD(prazo_resposta, INTERVAL 10 DAY) WHERE id = ? AND tenant_id = ?");
        $stmtProrrogar->execute([$id_lai, $tenant_id]);
        audit_log($pdo, $tenant_id, (int)($_SESSION['user_id'] ?? 0), 'prorrogou_lai', 'lai_pedidos', $id_lai);
        header("Location: admin.php?tab=lai");
        exit;
    }
}


// --- 2. DADOS DA EMPRESA ---
$stmtEmpresa = $pdo->prepare("SELECT nome_empresa, slug, data_expiracao FROM tenants WHERE id = ?");
$stmtEmpresa->execute([$tenant_id]);
$dadosEmpresa = $stmtEmpresa->fetch();

$nome_empresa = $dadosEmpresa['nome_empresa'];
$slug_empresa = $dadosEmpresa['slug'];

// Cálculo de validade
$data_validade = new DateTime($dadosEmpresa['data_expiracao']);
$hoje = new DateTime();
$diferenca = $hoje->diff($data_validade);
$dias_restantes = (int)$diferenca->format("%r%a");

// Link público da instituição
$meu_link = "{$protocolo}://{$servidor}{$caminho}/index.php?empresa=" . $slug_empresa;

// --- 3. BUSCAR RELATOS (OUVIDORIA) ---
if ($user_nivel === 'operador' && $user_setor_id) {
    $sqlRelatos = "SELECT r.*, s.nome as nome_setor FROM reports r LEFT JOIN sectors s ON r.setor_id = s.id WHERE r.tenant_id = ? AND r.setor_id = ? ORDER BY r.created_at DESC";
    $stmtRelatos = $pdo->prepare($sqlRelatos);
    $stmtRelatos->execute([$tenant_id, $user_setor_id]);
} else {
    $sqlRelatos = "SELECT r.*, s.nome as nome_setor FROM reports r LEFT JOIN sectors s ON r.setor_id = s.id WHERE r.tenant_id = ? ORDER BY r.created_at DESC";
    $stmtRelatos = $pdo->prepare($sqlRelatos);
    $stmtRelatos->execute([$tenant_id]);
}
$relatorios = $stmtRelatos->fetchAll();

// --- 4. BUSCAR PEDIDOS (LAI) ---
$sqlLai = "SELECT * FROM lai_pedidos WHERE tenant_id = ? ORDER BY created_at DESC";
$stmtLai = $pdo->prepare($sqlLai);
$stmtLai->execute([$tenant_id]);
$pedidos_lai = $stmtLai->fetchAll();

// --- 5. BUSCAR TEMPLATES ---
$stmtTmpl = $pdo->prepare("SELECT * FROM templates WHERE tenant_id = ?");
$stmtTmpl->execute([$tenant_id]);
$todos_templates = $stmtTmpl->fetchAll();
$mensagens_padrao = array_filter($todos_templates, fn($t) => $t['tipo'] == 'mensagem');
$pareceres_padrao = array_filter($todos_templates, fn($t) => $t['tipo'] == 'parecer');

// --- FUNÇÕES DE APOIO ---
function getCorUrgencia($nivel) { return $nivel >= 5 ? 'danger' : ($nivel == 4 ? 'warning' : 'info'); }
function getCorStatus($status) {
    $s = strtolower($status);
    if($s == 'novo' || $s == 'recebido') return 'primary';
    if(in_array($s, ['triagem','encaminhado','em_analise','em analise','aguardando_cidadao'], true)) return 'warning';
    if(in_array($s, ['respondido','concluido'], true)) return 'success';
    return 'secondary';
}
function safe_decrypt($cipher, $iv) {
    if (empty($cipher) || empty($iv)) return "⚠️ Dados incompletos.";
    try { return decrypt_payload((string)$cipher, (string)$iv); } catch (Exception $e) { return "⚠️ Erro na descriptografia."; }
}

$stats = [
    'total' => count($relatorios),
    'novos' => count(array_filter($relatorios, fn($r) => in_array(strtolower($r['status'] ?? ''), ['novo','recebido'], true))),
    'andamento' => count(array_filter($relatorios, fn($r) => in_array(strtolower($r['status'] ?? ''), ['triagem','encaminhado','em_analise','aguardando_cidadao'], true))),
    'concluidos' => count(array_filter($relatorios, fn($r) => in_array(strtolower($r['status'] ?? ''), ['respondido','concluido'], true))),
];
$tab_ativa = $_GET['tab'] ?? 'ouvidoria';
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel Gestor | <?php echo htmlspecialchars($nome_empresa); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', sans-serif; overflow-x: hidden; }
        .wrapper { display: flex; width: 100%; align-items: stretch; min-height: 100vh; }
        #sidebar { min-width: 260px; max-width: 260px; background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%); color: #fff; transition: all 0.3s; }
        #sidebar .sidebar-header { padding: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        #sidebar ul.components { padding: 20px 0; }
        #sidebar ul li a { padding: 12px 20px; font-size: 0.9rem; display: block; color: rgba(255,255,255,0.8); text-decoration: none; border-left: 4px solid transparent; }
        #sidebar ul li a:hover, #sidebar ul li a.active { color: #fff; background: rgba(255,255,255,0.05); border-left: 4px solid #0d6efd; }
        #sidebar ul li a i { margin-right: 10px; }
        #content { width: 100%; }
        .top-navbar { background: #fff; padding: 15px 30px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center;}
        .main-container { padding: 30px; }
        .card-dash { border: none; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .blur-text { filter: blur(6px); user-select: none; cursor: pointer; transition: 0.4s; }
        .reveal { filter: blur(0); cursor: text; }
        .dashboard-tab { display: none; }
        .dashboard-tab.active { display: block; }
        .metric-card { border:0; border-radius:18px; box-shadow:0 8px 28px rgba(15,23,42,.06); }
        .metric-icon { width:44px; height:44px; border-radius:12px; display:grid; place-items:center; background:#eef2ff; color:#4f46e5; font-size:1.2rem; }
        @media (max-width: 900px) { #sidebar { min-width:82px; max-width:82px; } #sidebar p, #sidebar a span, #sidebar .brand-text { display:none; } #sidebar ul li a { text-align:center; padding:14px 8px; border-left:0; } #sidebar ul li a i { margin:0; font-size:1.25rem; } .main-container { padding:18px; } }
    </style>
</head>
<body>

    <div class="wrapper">
        <nav id="sidebar">
            <div class="sidebar-header text-center">
                <h4 class="mb-0 fw-bold"><i class="bi bi-shield-check text-primary"></i> Ouvvi<span class="text-secondary fs-6"></span></h4>
            </div>
            <ul class="list-unstyled components">
                <p class="text-uppercase text-muted small fw-bold px-4 mb-2">Caixa de Entrada</p>
                <li><a href="admin.php?tab=ouvidoria" class="<?php echo $tab_ativa == 'ouvidoria' ? 'active' : ''; ?>"><i class="bi bi-megaphone"></i> Manifestações</a></li>
                <li><a href="admin.php?tab=lai" class="<?php echo $tab_ativa == 'lai' ? 'active' : ''; ?>"><i class="bi bi-file-earmark-text"></i> Pedidos LAI</a></li>
                <li><a href="admin_novo_relato.php"><i class="bi bi-pencil-square"></i> Inclusão Manual</a></li>
                
                <p class="text-uppercase text-muted small fw-bold px-4 mb-2 mt-4">Configurações</p>
                <li><a href="admin_config.php"><i class="bi bi-sliders"></i> SLA e Prazos</a></li>
                <li><a href="admin_setores.php"><i class="bi bi-building"></i> Unidades (Assuntos)</a></li>
                <li><a href="admin_usuarios.php"><i class="bi bi-people"></i> Unidade x Usuário</a></li>
                
                <p class="text-uppercase text-muted small fw-bold px-4 mb-2 mt-4">Padronização</p>
                <li><a href="admin_templates.php?tipo=mensagem"><i class="bi bi-chat-left-quote"></i> Mensagem Padrão</a></li>
                <li><a href="admin_templates.php?tipo=parecer"><i class="bi bi-journal-check"></i> Parecer Padrão</a></li>
                
                <p class="text-uppercase text-muted small fw-bold px-4 mb-2 mt-4">Relatórios e Financeiro</p>
                <li><a href="admin_graficos.php"><i class="bi bi-graph-up"></i> Dashboard Analítico</a></li>
                <li><a href="admin_assinatura.php"><i class="bi bi-credit-card"></i> Minha Assinatura</a></li>
            </ul>
            <div class="px-4 mt-4 mb-5"><a href="logout.php" class="btn btn-outline-danger w-100 btn-sm fw-bold">Sair</a></div>
        </nav>

        <div id="content">
            <div class="top-navbar">
                <h5 class="m-0 fw-bold"><?php echo htmlspecialchars($nome_empresa); ?></h5>
                <div class="d-flex align-items-center gap-3">
                    <span class="badge <?php echo $dias_restantes > 5 ? 'bg-success' : 'bg-danger'; ?> rounded-pill shadow-sm">Plano: <?php echo $dias_restantes; ?> dias</span>
                    <div class="border-start ps-3 small">
                        <span class="fw-bold d-block"><?php echo htmlspecialchars($user_nome); ?></span>
                    </div>
                </div>
            </div>

            <div class="main-container">
                <div class="row g-3 mb-4">
                    <div class="col-6 col-xl-3"><div class="card metric-card p-3"><div class="d-flex justify-content-between"><div><div class="text-muted small fw-semibold">Total</div><div class="fs-3 fw-bold"><?php echo $stats['total']; ?></div></div><div class="metric-icon"><i class="bi bi-inbox"></i></div></div></div></div>
                    <div class="col-6 col-xl-3"><div class="card metric-card p-3"><div class="d-flex justify-content-between"><div><div class="text-muted small fw-semibold">Novas</div><div class="fs-3 fw-bold"><?php echo $stats['novos']; ?></div></div><div class="metric-icon"><i class="bi bi-bell"></i></div></div></div></div>
                    <div class="col-6 col-xl-3"><div class="card metric-card p-3"><div class="d-flex justify-content-between"><div><div class="text-muted small fw-semibold">Em andamento</div><div class="fs-3 fw-bold"><?php echo $stats['andamento']; ?></div></div><div class="metric-icon"><i class="bi bi-hourglass-split"></i></div></div></div></div>
                    <div class="col-6 col-xl-3"><div class="card metric-card p-3"><div class="d-flex justify-content-between"><div><div class="text-muted small fw-semibold">Concluídas</div><div class="fs-3 fw-bold"><?php echo $stats['concluidos']; ?></div></div><div class="metric-icon"><i class="bi bi-check2-circle"></i></div></div></div></div>
                </div>

                <div class="card card-dash bg-primary bg-opacity-10 border-0 mb-4 p-4">
                    <div class="row align-items-center">
                        <div class="col-md-7">
                            <h6 class="fw-bold text-primary mb-1"><i class="bi bi-link-45deg"></i> Link do seu Portal de Transparência</h6>
                            <input type="text" class="form-control form-control-sm border-primary" id="meuLink" value="<?php echo $meu_link; ?>" readonly>
                        </div>
                        <div class="col-md-5 text-end mt-3 mt-md-0">
                            <button class="btn btn-sm btn-primary fw-bold px-3" onclick="copiarLink()"><i class="bi bi-clipboard"></i> Copiar Link</button>
                            <a href="<?php echo $meu_link; ?>" target="_blank" class="btn btn-sm btn-outline-primary fw-bold px-3"><i class="bi bi-box-arrow-up-right"></i> Abrir</a>
                        </div>
                    </div>
                </div>

                <div class="dashboard-tab <?php echo $tab_ativa == 'ouvidoria' ? 'active' : ''; ?>">
                    <h5 class="fw-bold mb-4">Manifestações Recebidas (Ouvidoria)</h5>
                    
                    <?php if(count($relatorios) == 0): ?>
                        <div class="text-center py-5 bg-white rounded-3 shadow-sm">
                            <i class="bi bi-folder2-open display-1 text-muted opacity-50"></i>
                            <h4 class="mt-3 text-muted">A caixa de entrada está vazia.</h4>
                        </div>
                    <?php endif; ?>

                    <?php foreach($relatorios as $relato): 
                        $texto_original = safe_decrypt($relato['texto_original_criptografado'], $relato['iv']);
                    ?>
                    <div class="card card-dash mb-4 p-4 border-0 shadow-sm">
                        <div class="d-flex justify-content-between border-bottom pb-3 mb-3">
                            <div>
                                <span class="badge bg-<?php echo getCorStatus($relato['status']); ?> text-uppercase shadow-sm"><?php echo str_replace('_', ' ', $relato['status']); ?></span>
                                <span class="badge border border-secondary text-secondary ms-1"><i class="bi bi-robot"></i> <?php echo htmlspecialchars($relato['categoria_ia']); ?></span>
                                <?php if($relato['nome_setor']): ?>
                                    <span class="badge bg-light text-dark border ms-1"><i class="bi bi-building"></i> <?php echo htmlspecialchars($relato['nome_setor']); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex align-items-center gap-3">
                                <span class="small text-muted fw-bold">#<?php echo $relato['protocolo_visualizacao']; ?></span>
                                <a href="relatorio_print.php?id=<?php echo $relato['id']; ?>" target="_blank" class="btn btn-sm btn-outline-dark fw-bold" title="Imprimir Relatório">
                                    <i class="bi bi-printer"></i>
                                </a>
                            </div>
                        </div>

                        <div class="row mt-2">
                            <div class="col-md-6 border-end">
                                <h6 class="small fw-bold text-primary"><i class="bi bi-robot"></i> PARECER TÉCNICO (IA):</h6>
                                <p class="small mb-2" style="font-size: 0.95rem;"><?php echo htmlspecialchars($relato['resumo_ia']); ?></p>
                                <div class="bg-primary bg-opacity-10 p-2 rounded border-start border-4 border-primary">
                                    <span class="fst-italic text-dark small">"<?php echo nl2br(htmlspecialchars($relato['sugestao_acao'])); ?>"</span>
                                </div>
                            </div>
                            <div class="col-md-6 ps-4">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="small fw-bold text-secondary"><i class="bi bi-file-earmark-lock-fill"></i> RELATO DO CIDADÃO:</h6>
                                    <button class="btn btn-sm btn-outline-secondary py-0 fw-bold" style="font-size: 0.75rem;" onclick="toggleText('ouvi-<?php echo $relato['id']; ?>')"><i class="bi bi-eye"></i> Revelar Sigilo</button>
                                </div>
                                <div id="raw-text-ouvi-<?php echo $relato['id']; ?>" class="blur-text small p-2 bg-light rounded border border-light-subtle" style="font-size: 0.95rem;">
                                    <?php echo nl2br(htmlspecialchars($texto_original)); ?>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 bg-light p-3 rounded border border-light-subtle">
                            <form method="POST">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="atualizar_relato" value="1">
                                <input type="hidden" name="id_relato" value="<?php echo $relato['id']; ?>">
                                <div class="row g-3">
                                    <div class="col-md-2">
                                        <label class="small fw-bold text-muted mb-1">Status</label>
                                        <select name="status" class="form-select form-select-sm fw-semibold">
                                            <option value="novo" <?php echo $relato['status']=='novo'?'selected':''; ?>>Novo</option>
                                            <option value="triagem" <?php echo $relato['status']=='triagem'?'selected':''; ?>>Em triagem</option>
                                            <option value="encaminhado" <?php echo $relato['status']=='encaminhado'?'selected':''; ?>>Encaminhado</option>
                                            <option value="em_analise" <?php echo $relato['status']=='em_analise'?'selected':''; ?>>Em análise</option>
                                            <option value="aguardando_cidadao" <?php echo $relato['status']=='aguardando_cidadao'?'selected':''; ?>>Aguardando cidadão</option>
                                            <option value="respondido" <?php echo $relato['status']=='respondido'?'selected':''; ?>>Respondido</option>
                                            <option value="concluido" <?php echo $relato['status']=='concluido'?'selected':''; ?>>Concluído</option>
                                        </select>
                                    </div>
                                    <div class="col-md-8">
                                        <div class="d-flex justify-content-between mb-1">
                                            <label class="small fw-bold text-muted">Resposta ao Cidadão</label>
                                            
                                            <div class="d-flex gap-2">
                                                <div class="dropdown">
                                                    <button class="btn btn-link btn-sm p-0 text-primary text-decoration-none fw-bold" type="button" data-bs-toggle="dropdown"><i class="bi bi-lightning-charge-fill text-warning"></i> Mensagem Padrão</button>
                                                    <ul class="dropdown-menu shadow">
                                                        <?php foreach($mensagens_padrao as $m): ?>
                                                            <li><a class="dropdown-item small" href="#" onclick="aplicarTemplate('<?php echo $relato['id']; ?>', `<?php echo addslashes($m['conteudo']); ?>`); return false;"><?php echo htmlspecialchars($m['titulo']); ?></a></li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                </div>
                                                <div class="border-start border-secondary opacity-25"></div>
                                                <div class="dropdown">
                                                    <button class="btn btn-link btn-sm p-0 text-success text-decoration-none fw-bold" type="button" data-bs-toggle="dropdown"><i class="bi bi-journal-check"></i> Parecer Técnico</button>
                                                    <ul class="dropdown-menu shadow">
                                                        <?php foreach($pareceres_padrao as $p): ?>
                                                            <li><a class="dropdown-item small" href="#" onclick="aplicarTemplate('<?php echo $relato['id']; ?>', `<?php echo addslashes($p['conteudo']); ?>`); return false;"><?php echo htmlspecialchars($p['titulo']); ?></a></li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                </div>
                                            </div>

                                        </div>
                                        <textarea id="resp-<?php echo $relato['id']; ?>" name="resposta_publica" class="form-control form-control-sm" rows="2" placeholder="Digite ou selecione um modelo acima..."><?php echo htmlspecialchars($relato['resposta_publica'] ?? ''); ?></textarea>
                                    </div>
                                    <div class="col-md-2 d-flex align-items-end">
                                        <button type="submit" class="btn btn-sm btn-success w-100 fw-bold shadow-sm"><i class="bi bi-save"></i> Salvar</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="dashboard-tab <?php echo $tab_ativa == 'lai' ? 'active' : ''; ?>">
                    <h5 class="fw-bold mb-4">Pedidos de Informação (LAI)</h5>
                    <?php foreach($pedidos_lai as $lai): 
                        $pedido_dec = safe_decrypt($lai['pedido_cipher'], $lai['pedido_iv']);
                        $resposta_dec = !empty($lai['resposta_cipher']) ? safe_decrypt($lai['resposta_cipher'], $lai['resposta_iv']) : '';
                    ?>
                    <div class="card card-dash mb-4 p-4 border-0 shadow-sm">
                        <div class="d-flex justify-content-between border-bottom pb-3 mb-3">
                            <div>
                                <span class="badge bg-<?php echo getCorStatus($lai['status']); ?> text-uppercase shadow-sm"><?php echo htmlspecialchars($lai['status']); ?></span>
                                <span class="small text-danger fw-bold ms-2"><i class="bi bi-calendar-x"></i> Prazo: <?php echo date('d/m/Y', strtotime($lai['prazo_resposta'])); ?></span>
                            </div>
                            <span class="small text-muted fw-bold">LAI #<?php echo $lai['protocolo']; ?></span>
                        </div>
                        
                        <div class="mb-2">
                            <button class="btn btn-sm btn-outline-secondary py-0 fw-bold" style="font-size: 0.75rem;" onclick="toggleText('lai-<?php echo $lai['id']; ?>')"><i class="bi bi-eye"></i> Revelar Pedido</button>
                        </div>
                        <div class="p-3 bg-light rounded blur-text border border-light-subtle" id="raw-text-lai-<?php echo $lai['id']; ?>" style="font-size: 0.95rem;">
                            <?php echo nl2br(htmlspecialchars($pedido_dec)); ?>
                        </div>
                        
                        <div class="mt-4 bg-light p-3 rounded border border-light-subtle">
                            <form method="POST">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="atualizar_lai" value="1">
                                <input type="hidden" name="id_lai" value="<?php echo $lai['id']; ?>">
                                <div class="row g-3">
                                    <div class="col-md-2">
                                        <label class="small fw-bold text-muted mb-1">Status</label>
                                        <select name="status_lai" class="form-select form-select-sm fw-semibold">
                                            <option value="Recebido" <?php echo $lai['status']=='Recebido'?'selected':''; ?>>Recebido</option>
                                            <option value="Em Analise" <?php echo $lai['status']=='Em Analise'?'selected':''; ?>>Em Análise</option>
                                            <option value="Respondido" <?php echo $lai['status']=='Respondido'?'selected':''; ?>>Respondido</option>
                                        </select>
                                    </div>
                                    <div class="col-md-8">
                                        <label class="small fw-bold text-muted mb-1">Resposta Sigilosa (Criptografada)</label>
                                        <textarea name="resposta_publica_lai" class="form-control form-control-sm" rows="2" placeholder="Resposta oficial da LAI..."><?php echo htmlspecialchars($resposta_dec); ?></textarea>
                                    </div>
                                    <div class="col-md-2 d-flex align-items-end">
                                        <button type="submit" class="btn btn-sm btn-primary w-100 fw-bold shadow-sm"><i class="bi bi-save"></i> Responder</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleText(id) {
            const el = document.getElementById('raw-text-' + id);
            el.classList.toggle('blur-text');
            el.classList.toggle('reveal');
        }
        function copiarLink() {
            var copyText = document.getElementById("meuLink");
            copyText.select();
            navigator.clipboard.writeText(copyText.value);
            alert("Link do Portal copiado com sucesso!");
        }
        function aplicarTemplate(id, texto) {
            const campo = document.getElementById('resp-' + id);
            if (campo) {
                campo.value = texto;
                campo.style.backgroundColor = '#fff3cd';
                setTimeout(() => campo.style.backgroundColor = '#fff', 500);
            }
        }
    </script>
</body>
</html>
