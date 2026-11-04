<?php
// index.php - COM SETORES E CONSULTA DE PROTOCOLO (CORRIGIDO)
require 'conexao.php';

$tenant_id = null;
$nome_empresa = 'Empresa Não Encontrada';
$setores = [];

// Identifica a empresa (Slug ou ID)
if (isset($_GET['empresa'])) {
    $slug = $_GET['empresa'];
    $stmt = $pdo->prepare("SELECT id, nome_empresa FROM tenants WHERE slug = ? AND ativo = 1");
    $stmt->execute([$slug]);
    $dado = $stmt->fetch();
} elseif (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT id, nome_empresa FROM tenants WHERE id = ? AND ativo = 1");
    $stmt->execute([$id]);
    $dado = $stmt->fetch();
}

if (isset($dado) && $dado) {
    $tenant_id = $dado['id'];
    $nome_empresa = $dado['nome_empresa'];

    // Busca setores desta empresa
    $stmtSetores = $pdo->prepare("SELECT * FROM sectors WHERE tenant_id = ?");
    $stmtSetores->execute([$tenant_id]);
    $setores = $stmtSetores->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ouvidoria | <?php echo htmlspecialchars($nome_empresa); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f0f2f5; }
        .hero-header { background: linear-gradient(135deg, #0d6efd, #0a58ca); color: white; padding: 40px 0 60px; border-radius: 0 0 20px 20px; margin-bottom: -40px; }
        .form-card { border: none; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
    </style>
</head>
<body>

    <div class="hero-header text-center position-relative">
        <h1><?php echo htmlspecialchars($nome_empresa); ?></h1>
        <p class="opacity-75">Canal Seguro e Anônimo</p>
        
        <div class="position-absolute top-0 end-0 m-3">
            <a href="acompanhar.php" class="btn btn-outline-light btn-sm rounded-pill px-3">
                🔍 Consultar Protocolo
            </a>
        </div>
    </div>

    <div class="container pb-5">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="card form-card bg-white p-4">
                    
                    <?php if (isset($_GET['status']) && ($_GET['status'] == 'sucesso' || $_GET['status'] == 'lai_sucesso')): ?>
                        <div class="text-center py-5">
                            <div class="mb-3 text-success" style="font-size: 60px;">✅</div>
                            <h3 class="text-success fw-bold">Recebido!</h3>
                            <p class="text-muted">Anote seu protocolo para acompanhar o andamento:</p>
                            <div class="alert alert-warning border border-warning text-dark fw-bold fs-4 text-center user-select-all">
                                <?php echo htmlspecialchars($_GET['protocolo'] ?? $_GET['protocolo_lai'] ?? '---'); ?>
                            </div>
                            <p class="small text-muted">Copie o código acima. Você precisará dele para ver a resposta.</p>
                            <a href="index.php?empresa=<?php echo htmlspecialchars($_GET['empresa'] ?? ''); ?>" class="btn btn-outline-primary mt-3">Novo Relato</a>
                        </div>
                    
                    <?php elseif ($tenant_id): ?>
                        
                    <ul class="nav nav-pills mb-3" id="ouviTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="tab-ouvi" data-bs-toggle="pill" data-bs-target="#pane-ouvi" type="button" role="tab">Ouvidoria</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-lai" data-bs-toggle="pill" data-bs-target="#pane-lai" type="button" role="tab">LAI / e-SIC</button>
                        </li>
                    </ul>

                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="pane-ouvi" role="tabpanel" aria-labelledby="tab-ouvi">
                            <form action="process_report.php" method="POST">
                                
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

                                <div class="alert alert-light border mt-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" value="1" id="isAnon" name="is_anonimo">
                                        <label class="form-check-label fw-semibold" for="isAnon">Quero enviar como anônimo</label>
                                        <div class="small text-muted">Se marcado, seus dados de identificação não serão coletados.</div>
                                    </div>
                                </div>

                                <div id="identFields" class="row g-2 mt-1 mb-4">
                                    <div class="col-md-6">
                                        <label class="form-label">Nome (opcional)</label>
                                        <input type="text" class="form-control" name="nome" maxlength="150" placeholder="Seu nome">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">E-mail (opcional)</label>
                                        <input type="email" class="form-control" name="email" maxlength="190" placeholder="seu@email.com">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Telefone (opcional)</label>
                                        <input type="text" class="form-control" name="telefone" maxlength="60" placeholder="(00) 00000-0000">
                                    </div>
                                </div>

                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary btn-lg fw-bold">ENVIAR AGORA</button>
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
                                    <label class="form-label">CPF/CNPJ (opcional)</label>
                                    <input type="text" class="form-control" name="cpf_cnpj" maxlength="30">
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Pedido de Acesso à Informação (LAI/e-SIC) *</label>
                                    <textarea class="form-control" name="pedido" rows="6" required placeholder="Descreva de forma objetiva a informação desejada."></textarea>
                                    <div class="form-text">Prazo padrão de resposta: até 20 dias (com possibilidade de prorrogação, quando justificado).</div>
                                </div>

                                <button type="submit" class="btn btn-primary w-100 py-2 rounded-pill fw-bold">
                                    Enviar Pedido LAI
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
                        }
                        if (anon) {
                            anon.addEventListener('change', syncAnon);
                            syncAnon();
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