<?php
// index.php - SISTEMA INTELIGENTE (MOSTRA LANDING PAGE OU OUVIDORIA)
require 'conexao.php';

// =========================================================================
// 1. LÓGICA DE ROTEAMENTO (LANDING PAGE vs OUVIDORIA DA EMPRESA)
// =========================================================================
if (!isset($_GET['empresa']) && !isset($_GET['id'])) {
    
    // Busca o valor da mensalidade para mostrar na Landing Page
    $stmtConfig = $pdo->query("SELECT valor FROM system_config WHERE chave = 'valor_mensalidade'");
    $valor_mensal = $stmtConfig->fetchColumn() ?: '97.00';
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Ouvidoria | Inteligência Artificial para Transparência</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
        <style>
            body { font-family: 'Segoe UI', system-ui, sans-serif; }
            .hero-section { background: linear-gradient(135deg, #0d6efd 0%, #084298 100%); color: white; padding: 100px 0; }
            .feature-icon { font-size: 2.5rem; color: #0d6efd; margin-bottom: 15px; }
            .card-pricing { border: 2px solid #0d6efd; border-radius: 20px; transform: scale(1.05); box-shadow: 0 15px 35px rgba(13, 110, 253, 0.15); }
            .navbar-brand { font-weight: 800; letter-spacing: -1px; }
        </style>
    </head>
    <body>

        <nav class="navbar navbar-expand-lg navbar-light bg-white py-3 shadow-sm fixed-top">
            <div class="container">
                <a class="navbar-brand text-primary fs-3" href="#"><i class="bi bi-shield-check"></i> Ouvvi<span class="text-dark"> Ouvidoria Inteligente</span></a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
                    <ul class="navbar-nav align-items-center gap-3">
                        <li class="nav-item"><a class="nav-link fw-semibold" href="#recursos">Recursos</a></li>
                        <li class="nav-item"><a class="nav-link fw-semibold" href="#preco">Preços</a></li>
                        <li class="nav-item"><a class="btn btn-outline-primary px-4 fw-bold" href="login.php">Fazer Login</a></li>
                        <li class="nav-item"><a class="btn btn-primary px-4 fw-bold shadow-sm" href="cadastro.php">Teste Grátis</a></li>
                    </ul>
                </div>
            </div>
        </nav>

        <section class="hero-section text-center" style="margin-top: 76px;">
            <div class="container">
                <div class="row justify-content-center">
                    <div class="col-lg-8">
                        <span class="badge bg-light text-primary mb-3 px-3 py-2 rounded-pill fw-bold">🚀 O Futuro do Compliance</span>
                        <h1 class="display-4 fw-bold mb-4">A Ouvidoria com Inteligência Artificial para a sua Empresa</h1>
                        <p class="lead mb-5 opacity-75">Cumpra a legislação, garanta o anonimato dos seus colaboradores e deixe nossa IA organizar, classificar e resumir os relatos automaticamente.</p>
                        <div class="d-flex justify-content-center gap-3">
                            <a href="cadastro.php" class="btn btn-light btn-lg text-primary fw-bold px-5 py-3 rounded-pill shadow">Começar 3 Dias Grátis</a>
                        </div>
                        <p class="small mt-3 opacity-50"><i class="bi bi-credit-card-2-front"></i> Não exige cartão de crédito para testar</p>
                    </div>
                </div>
            </div>
        </section>

        <section id="recursos" class="py-5 bg-light">
            <div class="container py-5">
                <div class="text-center mb-5">
                    <h2 class="fw-bold text-dark">Por que escolher nossa plataforma?</h2>
                    <p class="text-muted">Tudo o que você precisa para uma gestão transparente e segura.</p>
                </div>
                <div class="row g-4">
                    <div class="col-md-4 text-center">
                        <div class="card h-100 border-0 shadow-sm p-4">
                            <i class="bi bi-robot feature-icon"></i>
                            <h4 class="fw-bold">IA Jurídica Integrada</h4>
                            <p class="text-muted">Nossa Inteligência Artificial lê, resume e classifica as denúncias por urgência automaticamente, poupando horas de trabalho do RH.</p>
                        </div>
                    </div>
                    <div class="col-md-4 text-center">
                        <div class="card h-100 border-0 shadow-sm p-4">
                            <i class="bi bi-incognito feature-icon"></i>
                            <h4 class="fw-bold">Anonimato 100% Seguro</h4>
                            <p class="text-muted">Garantia total de proteção ao denunciante. Os dados são criptografados de ponta a ponta, gerando confiança na equipe.</p>
                        </div>
                    </div>
                    <div class="col-md-4 text-center">
                        <div class="card h-100 border-0 shadow-sm p-4">
                            <i class="bi bi-file-earmark-text feature-icon"></i>
                            <h4 class="fw-bold">Módulo LAI / e-SIC</h4>
                            <p class="text-muted">Pronto para órgãos públicos e empresas que precisam cumprir a Lei de Acesso à Informação com controle de prazos.</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="preco" class="py-5">
            <div class="container py-5">
                <div class="row justify-content-center">
                    <div class="col-lg-5">
                        <div class="card card-pricing p-5 text-center bg-white">
                            <h3 class="fw-bold text-dark">Plano Único e Completo</h3>
                            <p class="text-muted">Acesso total a todas as ferramentas.</p>
                            <h1 class="display-3 fw-bold text-primary my-4">R$ <?php echo number_format($valor_mensal, 2, ',', '.'); ?><span class="fs-5 text-muted fw-normal">/mês</span></h1>
                            <ul class="list-unstyled text-start mb-4 fs-5">
                                <li class="mb-2"><i class="bi bi-check2-circle text-success me-2"></i> Relatos Ilimitados</li>
                                <li class="mb-2"><i class="bi bi-check2-circle text-success me-2"></i> Análise com Inteligência Artificial</li>
                                <li class="mb-2"><i class="bi bi-check2-circle text-success me-2"></i> Módulo LAI / e-SIC Criptografado</li>
                                <li class="mb-2"><i class="bi bi-check2-circle text-success me-2"></i> Setores e Usuários Ilimitados</li>
                                <li class="mb-2"><i class="bi bi-check2-circle text-success me-2"></i> Link personalizado da sua empresa</li>
                            </ul>
                            <a href="cadastro.php" class="btn btn-primary btn-lg w-100 fw-bold py-3">Criar Conta Agora</a>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <footer class="bg-dark text-white py-4 text-center">
            <div class="container">
                <p class="mb-0 text-white-50">&copy; <?php echo date('Y'); ?> Ouvvi. Todos os direitos reservados.</p>
                <small class="text-white-50">Tecnologia, Transparência e Segurança.</small>
            </div>
        </footer>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>
    <?php
    exit; // Para a execução do script aqui. A parte da ouvidoria não carrega.
}


// =========================================================================
// 2. LÓGICA DA OUVIDORIA (QUANDO TEM A EMPRESA NA URL)
// =========================================================================

$tenant_id = null;
$nome_empresa = 'Empresa Não Encontrada';
$setores = [];

// VARIÁVEIS DE CONFIGURAÇÃO (SLA)
$permitir_anonimo = 1;
$exigir_cpf = 0;
$texto_apresentacao = '';

// Identifica a empresa e pega as configurações
if (isset($_GET['empresa']) || isset($_GET['id'])) {
    
    if (isset($_GET['empresa'])) {
        $stmt = $pdo->prepare("SELECT id, nome_empresa, permitir_anonimo, exigir_cpf, texto_apresentacao FROM tenants WHERE slug = ? AND ativo = 1");
        $stmt->execute([$_GET['empresa']]);
    } else {
        $stmt = $pdo->prepare("SELECT id, nome_empresa, permitir_anonimo, exigir_cpf, texto_apresentacao FROM tenants WHERE id = ? AND ativo = 1");
        $stmt->execute([(int)$_GET['id']]);
    }
    
    $dado = $stmt->fetch();

    if ($dado) {
        $tenant_id = $dado['id'];
        $nome_empresa = $dado['nome_empresa'];
        
        // Aplica as regras vindas do admin_config.php
        $permitir_anonimo = $dado['permitir_anonimo'];
        $exigir_cpf = $dado['exigir_cpf'];
        $texto_apresentacao = $dado['texto_apresentacao'];

        // Busca setores desta empresa
        $stmtSetores = $pdo->prepare("SELECT * FROM sectors WHERE tenant_id = ?");
        $stmtSetores->execute([$tenant_id]);
        $setores = $stmtSetores->fetchAll();
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ouvidoria | <?php echo htmlspecialchars($nome_empresa); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f0f2f5; }
        .hero-header { background: linear-gradient(135deg, #0d6efd, #0a58ca); color: white; padding: 40px 0 60px; border-radius: 0 0 20px 20px; margin-bottom: -40px; }
        .form-card { border: none; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
    </style>
</head>
<body>

    <div class="hero-header text-center position-relative">
        <h1><?php echo htmlspecialchars($nome_empresa); ?></h1>
        <p class="opacity-75">Canal Seguro e Confidencial</p>
        
        <div class="position-absolute top-0 end-0 m-3">
            <a href="acompanhar.php" class="btn btn-outline-light btn-sm rounded-pill px-3">
                <i class="bi bi-search"></i> Consultar Protocolo
            </a>
        </div>
    </div>

    <div class="container pb-5">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="card form-card bg-white p-4">
                    
                    <?php if (isset($_GET['status']) && ($_GET['status'] == 'sucesso' || $_GET['status'] == 'lai_sucesso')): ?>
                        <div class="text-center py-5">
                            <div class="mb-3 text-success" style="font-size: 60px;"><i class="bi bi-check-circle-fill"></i></div>
                            <h3 class="text-success fw-bold">Recebido!</h3>
                            <p class="text-muted">Anote seu protocolo para acompanhar o andamento:</p>
                            <div class="alert alert-warning border border-warning text-dark fw-bold fs-4 text-center user-select-all">
                                <?php echo htmlspecialchars($_GET['protocolo'] ?? $_GET['protocolo_lai'] ?? '---'); ?>
                            </div>
                            <p class="small text-muted">Copie o código acima. Você precisará dele para ver a resposta.</p>
                            <a href="index.php?empresa=<?php echo htmlspecialchars($_GET['empresa'] ?? ''); ?>" class="btn btn-outline-primary mt-3">Novo Relato</a>
                        </div>
                    
                    <?php elseif ($tenant_id): ?>
                        
                    <?php if (!empty($texto_apresentacao)): ?>
                        <div class="alert alert-secondary bg-light border-0 text-dark mb-4 fst-italic shadow-sm">
                            <i class="bi bi-info-square text-primary me-2"></i>
                            <?php echo nl2br(htmlspecialchars($texto_apresentacao)); ?>
                        </div>
                    <?php endif; ?>

                    <ul class="nav nav-pills mb-3" id="ouviTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active fw-bold" id="tab-ouvi" data-bs-toggle="pill" data-bs-target="#pane-ouvi" type="button" role="tab"><i class="bi bi-megaphone"></i> Ouvidoria</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link fw-bold" id="tab-lai" data-bs-toggle="pill" data-bs-target="#pane-lai" type="button" role="tab"><i class="bi bi-file-earmark-text"></i> LAI / e-SIC</button>
                        </li>
                    </ul>

                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="pane-ouvi" role="tabpanel" aria-labelledby="tab-ouvi">
                            <form action="process_ouvidoria.php" method="POST">
                                <input type="hidden" name="tenant_id" value="<?php echo htmlspecialchars($tenant_id); ?>">
                                <input type="hidden" name="slug" value="<?php echo htmlspecialchars($_GET['empresa'] ?? ''); ?>">
                                
                                <?php if(count($setores) > 0): ?>
                                <div class="mb-3 mt-3">
                                    <label class="form-label fw-bold">Para qual setor?</label>
                                    <select name="setor_id" class="form-select" required>
                                        <option value="" disabled selected>Selecione...</option>
                                        <?php foreach($setores as $s): ?>
                                            <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['nome']); ?></option>
                                        <?php endforeach; ?>
                                        <option value="0">Não sei / Outro</option>
                                    </select>
                                </div>
                                <?php endif; ?>

                                <div class="mb-3 <?php echo count($setores) == 0 ? 'mt-3' : ''; ?>">
                                    <label class="form-label fw-bold">Assunto</label>
                                    <select name="tipo_manifestacao" class="form-select" required>
                                        <option value="" disabled selected>Selecione...</option>
                                        <option value="elogio">Elogio</option>
                                        <option value="sugestao">Sugestão</option>
                                        <option value="reclamacao">Reclamação</option>
                                        <option value="denuncia">Denúncia</option>
                                        <option value="outro">Outro</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-bold">Relato</label>
                                    <textarea name="relato_bruto" class="form-control" rows="5" placeholder="Descreva aqui..." required></textarea>
                                </div>

                                <?php if ($permitir_anonimo): ?>
                                    <div class="alert alert-light border mt-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" value="1" id="isAnon" name="is_anonimo">
                                            <label class="form-check-label fw-semibold" for="isAnon">Quero enviar como anônimo</label>
                                            <div class="small text-muted">Se marcado, seus dados não serão solicitados.</div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <input type="hidden" name="is_anonimo" value="0">
                                    <div class="alert alert-warning border border-warning mt-3 pb-2 small">
                                        <i class="bi bi-exclamation-triangle-fill text-warning"></i> Esta instituição exige identificação. Seus dados serão mantidos em absoluto sigilo.
                                    </div>
                                <?php endif; ?>

                                <div id="identFields" class="row g-2 mt-1 mb-4">
                                    <div class="col-md-12">
                                        <label class="form-label">Nome <?php echo !$permitir_anonimo ? '*' : '(opcional)'; ?></label>
                                        <input type="text" class="form-control" name="nome" maxlength="150" placeholder="Seu nome" <?php echo !$permitir_anonimo ? 'required' : ''; ?>>
                                    </div>
                                    
                                    <?php if($exigir_cpf): ?>
                                        <div class="col-md-12 mt-2">
                                            <label class="form-label fw-semibold">CPF/CNPJ *</label>
                                            <input type="text" class="form-control" name="cpf_cnpj" maxlength="30" required placeholder="Digite apenas números">
                                        </div>
                                    <?php endif; ?>

                                    <div class="col-md-6 mt-2">
                                        <label class="form-label">E-mail <?php echo !$permitir_anonimo ? '*' : '(opcional)'; ?></label>
                                        <input type="email" class="form-control" name="email" maxlength="190" placeholder="seu@email.com" <?php echo !$permitir_anonimo ? 'required' : ''; ?>>
                                    </div>
                                    <div class="col-md-6 mt-2">
                                        <label class="form-label">Telefone (opcional)</label>
                                        <input type="text" class="form-control" name="telefone" maxlength="60" placeholder="(00) 00000-0000">
                                    </div>
                                </div>

                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary btn-lg fw-bold"><i class="bi bi-send-fill"></i> ENVIAR AGORA</button>
                                </div>
                            </form>
                        </div>

                        <div class="tab-pane fade" id="pane-lai" role="tabpanel" aria-labelledby="tab-lai">
                            <form action="process_lai.php" method="POST" class="mt-3">
                                <input type="hidden" name="tenant_id" value="<?php echo htmlspecialchars($tenant_id); ?>">
                                <input type="hidden" name="slug" value="<?php echo htmlspecialchars($_GET['empresa'] ?? ''); ?>">

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Nome *</label>
                                    <input type="text" class="form-control" name="nome" maxlength="150" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">E-mail *</label>
                                    <input type="email" class="form-control" name="email" maxlength="190" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">CPF/CNPJ <?php echo $exigir_cpf ? '*' : '(opcional)'; ?></label>
                                    <input type="text" class="form-control" name="cpf_cnpj" maxlength="30" <?php echo $exigir_cpf ? 'required' : ''; ?>>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Pedido de Acesso à Informação (LAI) *</label>
                                    <textarea class="form-control" name="pedido" rows="6" required placeholder="Descreva de forma objetiva a informação desejada."></textarea>
                                </div>

                                <button type="submit" class="btn btn-primary w-100 py-2 rounded-pill fw-bold">
                                    <i class="bi bi-send-check-fill"></i> Enviar Pedido LAI
                                </button>
                            </form>
                        </div>
                    </div>

                    <script>
                    (function() {
                        const anon = document.getElementById('isAnon');
                        const ident = document.getElementById('identFields');
                        function syncAnon() {
                            if (!anon || !ident) return;
                            ident.style.display = anon.checked ? 'none' : 'flex';
                            
                            // Remove a exigência (required) se for anônimo, para o form poder ser enviado
                            const inputs = ident.querySelectorAll('input');
                            inputs.forEach(input => {
                                if(anon.checked) {
                                    input.removeAttribute('required');
                                } else {
                                    // Volta a exigir apenas se o campo tiver o asterisco na label original
                                    const label = input.previousElementSibling;
                                    if(label && label.innerText.includes('*')) {
                                        input.setAttribute('required', 'required');
                                    }
                                }
                            });
                        }
                        if (anon) {
                            anon.addEventListener('change', syncAnon);
                            syncAnon(); // Roda ao carregar a página
                        }
                    })();
                    </script>

                    <?php else: ?>
                        <div class="text-center py-5">
                            <h2 class="text-secondary">Empresa não encontrada 😕</h2>
                            <p class="text-muted">Verifique se o link está correto ou se a assinatura da empresa está ativa.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>