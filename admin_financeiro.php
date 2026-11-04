<?php
session_start(); // Inicia a sessão para garantir acesso aos dados do usuário
require 'config.php';
require 'protecao.php';

// --- SEGURANÇA BÁSICA ---
if (!defined('TENANT_ID')) {
    header("Location: login.php");
    exit;
}

// =========================================================
// 1. PROCESSAR RETORNO DO MERCADO PAGO (CALLBACK)
// =========================================================
$status_pagamento = $_GET['status'] ?? $_GET['collection_status'] ?? '';
$referencia_mp    = $_GET['external_reference'] ?? '';

if (($status_pagamento == 'approved') && !empty($referencia_mp)) {
    
    $ref = explode(':', $referencia_mp);
    
    // Valida referência: UPGRADE:ID_CLIENTE:SLUG_PLANO
    if (count($ref) == 3 && $ref[0] == 'UPGRADE' && $ref[1] == TENANT_ID) {
        $novo_plano_slug = $ref[2];

        // Busca detalhes do plano comprado
        $stmtPlano = $pdo->prepare("SELECT nome, preco, dias_validade FROM planos WHERE slug = ?");
        $stmtPlano->execute([$novo_plano_slug]);
        $detalhesPlano = $stmtPlano->fetch(PDO::FETCH_ASSOC);

        if ($detalhesPlano) {
            $dias = (int)$detalhesPlano['dias_validade'];
            
            // Busca vencimento atual para somar dias
            $stmtAtual = $pdo->prepare("SELECT data_vencimento FROM tenants WHERE id = ?");
            $stmtAtual->execute([TENANT_ID]);
            $vencimento_atual = $stmtAtual->fetchColumn();

            // Lógica de Vencimento: Se ainda não venceu, soma. Se venceu, começa de hoje.
            if ($vencimento_atual && strtotime($vencimento_atual) > time()) {
                $nova_validade = date('Y-m-d', strtotime($vencimento_atual . " + $dias days"));
            } else {
                $nova_validade = date('Y-m-d', strtotime("+$dias days"));
            }

            // A. Atualiza o Cliente (Libera acesso)
            $stmtUp = $pdo->prepare("UPDATE tenants SET plano = ?, status = 'ativo', ativo = 1, data_vencimento = ? WHERE id = ?");
            $stmtUp->execute([$novo_plano_slug, $nova_validade, TENANT_ID]);

            // B. Registra a Fatura no Histórico
            // Verifica duplicidade para não registrar 2x se o cliente der F5
            $stmtCheck = $pdo->prepare("SELECT id FROM faturas WHERE tenant_id = ? AND data_pagamento = CURDATE() AND valor = ?");
            $stmtCheck->execute([TENANT_ID, $detalhesPlano['preco']]);
            
            if ($stmtCheck->rowCount() == 0) {
                // Insere Fatura
                $stmtFat = $pdo->prepare("INSERT INTO faturas (tenant_id, valor, data_vencimento, data_pagamento, status, descricao) VALUES (?, ?, ?, NOW(), 'pago', ?)");
                $stmtFat->execute([
                    TENANT_ID, 
                    $detalhesPlano['preco'], 
                    date('Y-m-d'), 
                    "Renovação Plano " . $detalhesPlano['nome']
                ]);
            }

            // Redireciona limpo para evitar reenvio
            header("Location: meu_plano.php?sucesso=plano_atualizado");
            exit;
        }
    }
}

// =========================================================
// 2. GERAR LINK DE PAGAMENTO (CHECKOUT MP)
// =========================================================
if (isset($_GET['gerar_pagamento_upgrade'])) {
    $plano_slug = $_GET['gerar_pagamento_upgrade'];
    
    $stmtP = $pdo->prepare("SELECT nome, preco FROM planos WHERE slug = ?");
    $stmtP->execute([$plano_slug]);
    $plano_info = $stmtP->fetch(PDO::FETCH_ASSOC);

    if ($plano_info) {
        $valor = (float)$plano_info['preco'];

        // Configuração do Checkout Pro
        $dados_pagamento = [
            "items" => [
                [
                    "title" => "Assinatura: " . $plano_info['nome'],
                    "quantity" => 1,
                    "unit_price" => $valor,
                    "currency_id" => "BRL"
                ]
            ],
            "external_reference" => "UPGRADE:" . TENANT_ID . ":" . $plano_slug,
            "back_urls" => [
                "success" => "https://" . $_SERVER['HTTP_HOST'] . "/meu_plano.php",
                "pending" => "https://" . $_SERVER['HTTP_HOST'] . "/meu_plano.php",
                "failure" => "https://" . $_SERVER['HTTP_HOST'] . "/meu_plano.php"
            ],
            "auto_return" => "approved",
            "statement_descriptor" => "GABINETE DIGITAL"
        ];

        // Chama API do Mercado Pago
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => "https://api.mercadopago.com/checkout/preferences",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($dados_pagamento),
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer " . MP_ACCESS_TOKEN, // Definido no config.php
                "Content-Type: application/json"
            ],
            CURLOPT_SSL_VERIFYPEER => false 
        ]);
        
        $res = json_decode(curl_exec($curl), true);
        curl_close($curl);

        if (isset($res['init_point'])) {
            header("Location: " . $res['init_point']);
            exit;
        } else {
            die("Erro ao conectar com Mercado Pago. Verifique o Token.");
        }
    }
}

// =========================================================
// 3. SALVAR DADOS DE FATURAMENTO (POST)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'salvar_dados_faturamento') {
    try {
        $sql = "UPDATE tenants SET 
                doc_tipo = ?, doc_numero = ?, razao_social = ?, 
                cep = ?, endereco = ?, numero = ?, 
                bairro = ?, cidade = ?, estado = ?, inscricao_municipal = ? 
                WHERE id = ?";
        
        $pdo->prepare($sql)->execute([
            $_POST['doc_tipo'],
            preg_replace('/[^0-9]/', '', $_POST['doc_numero']), // Limpa formatação
            strtoupper($_POST['razao_social']),
            preg_replace('/[^0-9]/', '', $_POST['cep']),
            $_POST['endereco'],
            $_POST['numero'],
            $_POST['bairro'],
            $_POST['cidade'],
            $_POST['estado'],
            $_POST['inscricao_municipal'],
            TENANT_ID
        ]);
        
        header("Location: meu_plano.php?sucesso=dados_salvos");
        exit;
    } catch (Exception $e) {
        $erro_fat = "Erro ao salvar: " . $e->getMessage();
    }
}

// =========================================================
// 4. CARREGAR DADOS DA TELA
// =========================================================

// Busca dados do Cliente e do Plano Atual
$stmt = $pdo->prepare("
    SELECT t.*, p.nome as nome_plano, p.preco as preco_plano 
    FROM tenants t 
    LEFT JOIN planos p ON t.plano = p.slug 
    WHERE t.id = ?
");
$stmt->execute([TENANT_ID]);
$dados_cliente = $stmt->fetch(PDO::FETCH_ASSOC);

// Verifica se cadastro financeiro está completo
$dados_completos = !empty($dados_cliente['doc_numero']) && !empty($dados_cliente['endereco']);

// Variáveis de Exibição
$nome_plano_atual = $dados_cliente['nome_plano'] ?? 'Gratuito / Teste';
$preco_atual = (float)($dados_cliente['preco_plano'] ?? 0);
$data_venc = $dados_cliente['data_vencimento'] ? date('d/m/Y', strtotime($dados_cliente['data_vencimento'])) : '---';

// Lista de Planos Disponíveis
$todos_planos = $pdo->query("SELECT * FROM planos WHERE ativo = 1 ORDER BY preco ASC")->fetchAll(PDO::FETCH_ASSOC);

// Histórico de Faturas
$stmt_fat = $pdo->prepare("SELECT * FROM faturas WHERE tenant_id = ? ORDER BY id DESC");
$stmt_fat->execute([TENANT_ID]);
$faturas = $stmt_fat->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8"><title>Minha Assinatura</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
        .card-upgrade { border: 1px solid #e2e8f0; border-radius: 15px; transition: 0.3s; background: #fff; }
        .card-upgrade:hover { border-color: #0d6efd; transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.1); }
        .badge-pago { background-color: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .accordion-button:not(.collapsed) { background-color: #e7f1ff; color: #0d6efd; }
    </style>
</head>
<body class="p-3 p-md-5">

<div class="container" style="max-width: 1100px;">
    
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 gap-3">
        <div>
            <h3 class="fw-bold m-0 text-dark"><i class="fas fa-wallet text-success me-2"></i> Minha Assinatura</h3>
            <p class="text-muted small mb-0">Gerencie seu plano e notas fiscais.</p>
        </div>
        <a href="admin_painel.php" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">
            <i class="fas fa-arrow-left me-2"></i> Voltar ao Painel
        </a>
    </div>

    <?php if(isset($_GET['sucesso'])): ?>
        <div class="alert alert-success shadow-sm rounded-4 border-0 mb-4 d-flex align-items-center">
            <i class="fas fa-check-circle fa-2x me-3 text-success"></i>
            <div>
                <h6 class="fw-bold mb-0">Tudo certo!</h6>
                <p class="mb-0 small">Operação realizada com sucesso.</p>
            </div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden bg-white">
        <div class="card-body p-4">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <small class="text-uppercase text-muted fw-bold ls-1" style="font-size: 0.75rem;">SEU PLANO ATUAL</small>
                    <h2 class="fw-bold text-primary mb-1 mt-1"><?= htmlspecialchars($nome_plano_atual) ?></h2>
                    <div class="d-flex align-items-center gap-3 mt-3">
                        <span class="badge bg-light text-dark border px-3 py-2 rounded-pill">
                            <i class="far fa-calendar-alt me-2 text-muted"></i> Vence em: <strong><?= $data_venc ?></strong>
                        </span>
                        <?php if($dados_cliente['status'] == 'ativo'): ?>
                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-3 py-2 rounded-pill">Status: Ativo</span>
                        <?php else: ?>
                            <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-3 py-2 rounded-pill">Status: Bloqueado</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-4 text-center text-md-end mt-4 mt-md-0">
                    <?php if($preco_atual > 0): ?>
                        <a href="?gerar_pagamento_upgrade=<?= $dados_cliente['plano'] ?>" class="btn btn-success btn-lg fw-bold rounded-pill px-5 shadow-sm w-100">
                            <i class="fas fa-sync-alt me-2"></i> RENOVAR AGORA
                        </a>
                        <div class="mt-2 small text-muted">Renovação segura via Mercado Pago</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if(!$dados_completos): ?>
    <div class="alert alert-warning border-0 shadow-sm rounded-4 mb-4 d-flex align-items-center">
        <div class="me-3 bg-warning bg-opacity-25 p-3 rounded-circle text-warning">
            <i class="fas fa-exclamation-triangle fa-lg"></i>
        </div>
        <div>
            <h6 class="fw-bold mb-1 text-dark">Cadastro Financeiro Pendente</h6>
            <p class="mb-0 small text-muted">Para emitirmos sua <b>Nota Fiscal (NFS-e)</b> automaticamente, precisamos que você preencha seus dados abaixo.</p>
        </div>
        <button class="btn btn-sm btn-warning ms-auto fw-bold text-dark" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFat">Preencher</button>
    </div>
    <?php endif; ?>

    <div class="accordion mb-5 shadow-sm rounded-4 border-0" id="accFaturamento">
        <div class="accordion-item border-0 overflow-hidden rounded-4">
            <h2 class="accordion-header">
                <button class="accordion-button <?= $dados_completos ? 'collapsed' : '' ?> fw-bold bg-white text-dark py-3" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFat">
                    <i class="fas fa-file-invoice-dollar text-primary me-3 fa-lg"></i> Dados para Nota Fiscal
                    <?php if($dados_completos): ?> 
                        <i class="fas fa-check-circle text-success ms-2"></i>
                    <?php endif; ?>
                </button>
            </h2>
            <div id="collapseFat" class="accordion-collapse collapse <?= $dados_completos ? '' : 'show' ?>" data-bs-parent="#accFaturamento">
                <div class="accordion-body bg-white p-4">
                    
                    <form method="POST">
                        <input type="hidden" name="acao" value="salvar_dados_faturamento">
                        
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="small fw-bold text-muted">Tipo Pessoa</label>
                                <select name="doc_tipo" class="form-select bg-light">
                                    <option value="CPF" <?= ($dados_cliente['doc_tipo']??'') == 'CPF' ? 'selected' : '' ?>>Pessoa Física (CPF)</option>
                                    <option value="CNPJ" <?= ($dados_cliente['doc_tipo']??'') == 'CNPJ' ? 'selected' : '' ?>>Empresa (CNPJ)</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="small fw-bold text-muted">CPF / CNPJ</label>
                                <input type="text" name="doc_numero" class="form-control" value="<?= $dados_cliente['doc_numero'] ?? '' ?>" required placeholder="Somente números">
                            </div>
                            <div class="col-md-5">
                                <label class="small fw-bold text-muted">Nome Completo / Razão Social</label>
                                <input type="text" name="razao_social" class="form-control text-uppercase" value="<?= $dados_cliente['razao_social'] ?? '' ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="small fw-bold text-muted">CEP</label>
                                <div class="input-group">
                                    <input type="text" name="cep" id="cep" class="form-control" value="<?= $dados_cliente['cep'] ?? '' ?>" maxlength="9" onblur="buscarCep(this.value)" required>
                                    <span class="input-group-text bg-light text-muted"><i class="fas fa-search"></i></span>
                                </div>
                            </div>
                            <div class="col-md-7">
                                <label class="small fw-bold text-muted">Endereço</label>
                                <input type="text" name="endereco" id="endereco" class="form-control" value="<?= $dados_cliente['endereco'] ?? '' ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label class="small fw-bold text-muted">Número</label>
                                <input type="text" name="numero" class="form-control" value="<?= $dados_cliente['numero'] ?? '' ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="small fw-bold text-muted">Bairro</label>
                                <input type="text" name="bairro" id="bairro" class="form-control" value="<?= $dados_cliente['bairro'] ?? '' ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="small fw-bold text-muted">Cidade</label>
                                <input type="text" name="cidade" id="cidade" class="form-control bg-light" value="<?= $dados_cliente['cidade'] ?? '' ?>" readonly>
                            </div>
                            <div class="col-md-2">
                                <label class="small fw-bold text-muted">UF</label>
                                <input type="text" name="estado" id="estado" class="form-control bg-light" value="<?= $dados_cliente['estado'] ?? '' ?>" readonly>
                            </div>
                            <div class="col-md-2">
                                <label class="small fw-bold text-muted">Insc. Municipal</label>
                                <input type="text" name="inscricao_municipal" class="form-control" value="<?= $dados_cliente['inscricao_municipal'] ?? '' ?>" placeholder="(Opcional)">
                            </div>
                            <div class="col-12 text-end mt-4 pt-3 border-top">
                                <button type="submit" class="btn btn-primary fw-bold px-5 rounded-pill shadow-sm">
                                    <i class="fas fa-save me-2"></i> SALVAR DADOS
                                </button>
                            </div>
                        </div>
                    </form>

                </div>
            </div>
        </div>
    </div>

    <h5 class="fw-bold mb-4 text-dark ps-3 border-start border-4 border-primary">📦 Escolha seu Plano</h5>
    <div class="row g-4 mb-5">
        <?php foreach($todos_planos as $p): 
            if ((float)$p['preco'] <= 0) continue; 
            $is_current = ($p['slug'] == $dados_cliente['plano']);
        ?>
        <div class="col-md-4">
            <div class="card h-100 card-upgrade bg-white p-4">
                <div class="d-flex flex-column h-100">
                    <div class="mb-3">
                        <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3 py-2 fw-bold small text-uppercase"><?= $p['nome'] ?></span>
                    </div>
                    <h2 class="fw-bold text-dark mb-4">
                        R$ <?= number_format($p['preco'], 2, ',', '.') ?>
                        <small class="fs-6 text-muted fw-normal">/mês</small>
                    </h2>
                    <ul class="list-unstyled flex-grow-1 mb-4">
                        <?php foreach(explode("\n", $p['beneficios']) as $b): if(trim($b)): ?>
                            <li class="mb-2 text-secondary small d-flex align-items-start">
                                <i class="fas fa-check text-success me-2 mt-1"></i> <span><?= trim($b) ?></span>
                            </li>
                        <?php endif; endforeach; ?>
                    </ul>
                    <a href="?gerar_pagamento_upgrade=<?= $p['slug'] ?>" class="btn <?= $is_current ? 'btn-outline-primary' : 'btn-primary' ?> fw-bold rounded-pill w-100 py-3">
                        <?= $is_current ? 'Renovar Este Plano' : 'Escolher Plano' ?>
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white mb-5">
        <div class="card-header bg-white py-3 fw-bold border-bottom">
            <i class="fas fa-history me-2 text-secondary"></i> Histórico de Pagamentos
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light text-secondary small text-uppercase">
                    <tr>
                        <th class="ps-4 py-3">Data</th>
                        <th class="py-3">Valor</th>
                        <th class="py-3">Status</th>
                        <th class="text-end pe-4 py-3">Comprovantes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($faturas as $f): ?>
                    <tr>
                        <td class="ps-4 fw-bold text-secondary">
                            <?= date('d/m/Y', strtotime($f['data_pagamento'])) ?>
                        </td>
                        <td class="fw-bold text-dark">
                            R$ <?= number_format($f['valor'], 2, ',', '.') ?>
                        </td>
                        <td>
                            <span class="badge badge-pago rounded-pill px-3 py-1">PAGO</span>
                        </td>
                        <td class="text-end pe-4">
                            <a href="gerar_comprovante.php?id=<?= $f['id'] ?>" target="_blank" class="btn btn-sm btn-light border fw-bold text-secondary me-2" title="Baixar Recibo">
                                <i class="fas fa-file-pdf text-danger"></i> Recibo
                            </a>

                            <?php if (!empty($f['nota_numero'])): ?>
                                <?php 
                                    $linkNota = "https://novaserrana.sigiss.com.br/verificacao?num={$f['nota_numero']}&cod={$f['nota_codigo_verificacao']}";
                                ?>
                                <a href="<?= $linkNota ?>" target="_blank" class="btn btn-sm btn-primary fw-bold shadow-sm px-3">
                                    <i class="fas fa-file-invoice me-1"></i> NFSe #<?= $f['nota_numero'] ?>
                                </a>
                            <?php else: ?>
                                <span class="badge bg-secondary bg-opacity-25 text-secondary fw-normal px-2" title="Nota Fiscal em processamento">
                                    <i class="fas fa-clock me-1"></i> Proc. Nota
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <?php if(empty($faturas)): ?>
                        <tr><td colspan="4" class="text-center py-5 text-muted">Nenhum pagamento registrado ainda.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function buscarCep(cep) {
        cep = cep.replace(/\D/g, '');
        if (cep != "") {
            var validacep = /^[0-9]{8}$/;
            if(validacep.test(cep)) {
                document.getElementById('endereco').value = "...";
                var script = document.createElement('script');
                script.src = 'https://viacep.com.br/ws/'+ cep + '/json/?callback=meu_callback';
                document.body.appendChild(script);
            } else {
                alert("CEP inválido.");
            }
        }
    }
    function meu_callback(conteudo) {
        if (!("erro" in conteudo)) {
            document.getElementById('endereco').value = (conteudo.logradouro);
            document.getElementById('bairro').value = (conteudo.bairro);
            document.getElementById('cidade').value = (conteudo.localidade);
            document.getElementById('estado').value = (conteudo.uf);
            document.getElementById('numero').focus();
        } else {
            alert("CEP não encontrado.");
            document.getElementById('endereco').value = "";
        }
    }
</script>

</body>
</html>