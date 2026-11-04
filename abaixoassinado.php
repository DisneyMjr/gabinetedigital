<?php
// ATIVA A EXIBIÇÃO DE ERROS (Remova ou comente estas 3 linhas quando tudo estiver funcionando em produção)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1. CONEXÃO COM O BANCO DE DADOS
// Usamos o db.php para não passar pela trava do config.php que exige subdomínio
require 'db.php'; 

// 2. IDENTIFICA A CAMPANHA PELO HASH (Link Neutro)
$hash = $_GET['c'] ?? '';

if (empty($hash)) {
    die("<div style='font-family: sans-serif; text-align: center; padding: 50px;'>
            <h2>Página Indisponível</h2>
            <p>Código da campanha não informado.</p>
         </div>");
}

// Busca a petição pelo hash_url
$stmt = $pdo->prepare("SELECT * FROM peticoes WHERE hash_url = ? AND status = 1");
$stmt->execute([$hash]);
$peticao = $stmt->fetch(PDO::FETCH_ASSOC);

// Fallback: Se for uma petição antiga que não tinha hash, usa a conversão MD5
if (!$peticao) {
    $stmt = $pdo->prepare("SELECT * FROM peticoes WHERE MD5(CONCAT('migracao_', id)) = ? AND status = 1");
    $stmt->execute([$hash]);
    $peticao = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$peticao) {
    die("<div style='font-family: sans-serif; text-align: center; padding: 50px;'>
            <h2>Página Indisponível</h2>
            <p>Esta campanha não existe, foi encerrada ou pertence a outro mandato.</p>
         </div>");
}

// DESCOBRE O TENANT CORRETO A PARTIR DA PETIÇÃO ENCONTRADA
$tenant_id_correto = $peticao['tenant_id'];

// 3. BUSCA AS CONFIGURAÇÕES DE SEO E SCRIPT HEAD DO MANDATO ATUAL (TENANT)
$stmt_seo = $pdo->prepare("SELECT seo_description, seo_keywords, scripts_head FROM configuracoes WHERE tenant_id = ?");
$stmt_seo->execute([$tenant_id_correto]);
$site_config = $stmt_seo->fetch(PDO::FETCH_ASSOC);

// 4. PROCESSA O FORMULÁRIO DE ASSINATURA
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'assinar') {
    $nome = trim($_POST['nome']);
    $whatsapp = preg_replace('/[^0-9]/', '', $_POST['whatsapp']);
    
    // Novos campos
    $nascimento = !empty($_POST['nascimento']) ? $_POST['nascimento'] : null;
    $sexo = $_POST['sexo'] ?? 'Não Informado';
    
    $cidade = $_POST['cidade'] ?? '';
    $bairro = $_POST['bairro'] ?? '';
    $lat = $_POST['latitude'] ?? null;
    $lng = $_POST['longitude'] ?? null;
    
    $tag = $peticao['tag_destino'];

    if(!empty($nome) && !empty($whatsapp)) {
        // Insere na base de eleitores com o tenant_id correto (descoberto pelo hash)
        $sql = "INSERT INTO eleitores (tenant_id, nome, whatsapp, cidade, bairro, latitude, longitude, tags, status_voto, observacoes, nascimento, sexo) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 'Capturado via Abaixo-Assinado', ?, ?)";
        try {
            $pdo->prepare($sql)->execute([$tenant_id_correto, $nome, $whatsapp, $cidade, $bairro, $lat, $lng, $tag, $nascimento, $sexo]);
            // REDIRECIONA PARA A PÁGINA DE SUCESSO NEUTRA
            header("Location: peticao_sucesso.php?c=" . $hash);
            exit;
        } catch (Exception $e) {
            // Se der erro de duplicidade (já assinou), manda pro sucesso igual para não travar o usuário
            header("Location: peticao_sucesso.php?c=" . $hash);
            exit; 
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <title><?= htmlspecialchars($peticao['titulo']) ?></title>
    <meta name="description" content="<?= htmlspecialchars($site_config['seo_description'] ?? '') ?>">
    <meta name="keywords" content="<?= htmlspecialchars($site_config['seo_keywords'] ?? '') ?>">
    
    <meta property="og:title" content="<?= htmlspecialchars($peticao['titulo']) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($site_config['seo_description'] ?? '') ?>">
    <meta property="og:type" content="website">

    <?= $site_config['scripts_head'] ?? '' ?>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
        .hero-banner { background: linear-gradient(135deg, #0d6efd 0%, #000 100%); color: white; padding: 40px 20px; border-bottom-left-radius: 30px; border-bottom-right-radius: 30px; text-align: center; }
        .card-form { margin-top: -30px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border: none; }
    </style>
</head>
<body>

    <div class="hero-banner">
        <h2 class="fw-bold mb-3"><?= htmlspecialchars($peticao['titulo']) ?></h2>
        <p class="lead opacity-75" style="font-size: 1rem; max-width: 600px; margin: 0 auto;">
            <?= nl2br(htmlspecialchars($peticao['descricao'])) ?>
        </p>
    </div>

    <div class="container pb-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card card-form p-4">
                    <h5 class="fw-bold text-center mb-4"><i class="fas fa-pen-nib text-primary me-2"></i> Assine e Apoie</h5>
                    
                    <form method="POST">
                        <input type="hidden" name="acao" value="assinar">
                        <input type="hidden" name="latitude" id="lat">
                        <input type="hidden" name="longitude" id="lng">

                        <div class="mb-3">
                            <label class="fw-bold small">Nome Completo *</label>
                            <input type="text" name="nome" class="form-control bg-light" required>
                        </div>
                        <div class="mb-3">
                            <label class="fw-bold small">WhatsApp *</label>
                            <input type="text" name="whatsapp" class="form-control bg-light" placeholder="(00) 00000-0000" required>
                        </div>
                        
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="fw-bold small">Data Nasc. *</label>
                                <input type="date" name="nascimento" class="form-control bg-light" required>
                            </div>
                            <div class="col-6">
                                <label class="fw-bold small">Gênero *</label>
                                <select name="sexo" class="form-select bg-light" required>
                                    <option value="">Selecione...</option>
                                    <option value="Masculino">Masculino</option>
                                    <option value="Feminino">Feminino</option>
                                    <option value="Outro">Outro</option>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="fw-bold small text-primary">CEP *</label>
                            <div class="input-group">
                                <input type="text" id="cep" class="form-control bg-light" placeholder="Ex: 70000-000" required onblur="buscarCEP(this.value)">
                                <button class="btn btn-primary" type="button" onclick="buscarCEP(document.getElementById('cep').value)"><i class="fas fa-search"></i></button>
                            </div>
                            <small id="status_cep" class="text-muted" style="font-size: 0.75rem;">Digite o CEP para preencher o local no mapa.</small>
                        </div>

                        <div class="row g-2 mb-4">
                            <div class="col-6">
                                <label class="fw-bold small">Cidade *</label>
                                <input type="text" name="cidade" id="cidade" class="form-control bg-light" required readonly>
                            </div>
                            <div class="col-6">
                                <label class="fw-bold small">Bairro *</label>
                                <input type="text" name="bairro" id="bairro" class="form-control bg-light" required readonly>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-3 fw-bold rounded-pill fs-5 shadow">
                            ASSINAR AGORA
                        </button>
                    </form>
                </div>
                <div class="text-center mt-3 text-muted small">
                    <i class="fas fa-lock me-1"></i> Seus dados estão seguros e não serão expostos publicamente.
                </div>
            </div>
        </div>
    </div>

    <script>
    function buscarCEP(cep) {
        let cepLimpo = cep.replace(/\D/g, '');
        if (cepLimpo.length !== 8) return;

        const status = document.getElementById('status_cep');
        status.innerHTML = "<i class='fas fa-spinner fa-spin text-primary'></i> Buscando local no mapa...";

        fetch(`https://viacep.com.br/ws/${cepLimpo}/json/`)
        .then(res => res.json())
        .then(data => {
            if(data.erro) {
                status.innerHTML = "<span class='text-danger'>CEP não encontrado.</span>";
                return;
            }
            
            document.getElementById('bairro').value = data.bairro;
            document.getElementById('cidade').value = data.localidade;

            let enderecoBusca = `${data.logradouro}, ${data.localidade}, ${data.uf}, Brazil`;
            fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(enderecoBusca)}`)
            .then(r => r.json())
            .then(geo => {
                if(geo.length > 0) {
                    document.getElementById('lat').value = geo[0].lat;
                    document.getElementById('lng').value = geo[0].lon;
                    status.innerHTML = "<span class='text-success fw-bold'><i class='fas fa-check'></i> GPS validado com sucesso!</span>";
                } else {
                    status.innerHTML = "<span class='text-warning'>CEP achado, mas sem GPS preciso.</span>";
                }
            });
        }).catch(() => {
            status.innerHTML = "<span class='text-danger'>Erro na busca do CEP.</span>";
        });
    }
    </script>
</body>
</html>