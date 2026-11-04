<?php
// admin_novo_relato.php - INCLUSÃO MANUAL DE MANIFESTAÇÕES (Balcão/Telefone)
session_start();
require 'conexao.php';
require 'verificar_assinatura.php';

$tenant_id = $_SESSION['tenant_id'];
$user_nome = $_SESSION['user_nome'];
$mensagem = '';
$protocolo_gerado = '';

// Busca setores da empresa
$stmtSetores = $pdo->prepare("SELECT id, nome FROM sectors WHERE tenant_id = ?");
$stmtSetores->execute([$tenant_id]);
$setores = $stmtSetores->fetchAll();

// --- PROCESSAR O FORMULÁRIO DE INCLUSÃO MANUAL ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $setor_id = $_POST['setor_id'] ?? null;
    $setor_id = ($setor_id === '' || $setor_id === '0') ? null : (int)$setor_id;
    
    $tipo_manifestacao = trim($_POST['tipo_manifestacao'] ?? 'Solicitação');
    $origem = trim($_POST['origem'] ?? 'Presencial'); // Novo campo: Presencial, Telefone, etc
    $texto_bruto = trim($_POST['relato_bruto'] ?? '');
    
    // Dados do Manifestante
    $is_anonimo = (int)($_POST['is_anonimo'] ?? 0);
    $nome = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telefone = trim($_POST['telefone'] ?? '');
    
    if ($is_anonimo === 1) { 
        $nome = $email = $telefone = 'ANÔNIMO'; 
    }

    if ($texto_bruto !== '') {
        // Gera Protocolo
        $protocolo = strtoupper(substr(bin2hex(random_bytes(6)), 0, 12));
        $protocolo_gerado = $protocolo;

        // Adiciona a origem ao texto para a IA ter contexto
        $texto_para_ia = "[Recebido via: $origem] " . $texto_bruto;

        // Criptografa o relato
        $iv_b64 = '';
        $texto_cipher = encrypt_payload($texto_bruto, $iv_b64);

        // --- IA JURÍDICA ---
        $resumo_ai = 'Análise de IA não processada.';
        $categoria_ia = 'Não classificado';
        $urgencia_ia = 3;
        $sugestao_acao = 'Analisar o caso manualmente.';

        $openAiKey = openai_api_key();
        if ($openAiKey && $openAiKey !== 'sk-proj-COLE_SUA_CHAVE_AQUI') {
            $url = 'https://api.openai.com/v1/chat/completions';
            $prompt_sistema = "Você é um Diretor de Compliance. Analise o relato e retorne APENAS um objeto JSON com: 'resumo_ia' (resumo técnico imparcial), 'categoria_ia' (ex: Reclamação, Denúncia Trabalhista, etc), 'urgencia_ia' (1 a 5), 'sugestao_acao' (passo a passo para o setor responsável).";
            
            $payload = [
                'model' => 'gpt-4o-mini',
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $prompt_sistema],
                    ['role' => 'user', 'content' => $texto_para_ia]
                ],
                'temperature' => 0.3
            ];
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $openAiKey],
                CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_TIMEOUT => 25, CURLOPT_SSL_VERIFYPEER => false
            ]);
            
            $resp = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($resp && $http >= 200 && $http < 300) {
                $j = json_decode($resp, true);
                $ia_content = json_decode($j['choices'][0]['message']['content'], true);
                if (is_array($ia_content)) {
                    $resumo_ai = $ia_content['resumo_ia'] ?? $resumo_ai;
                    $categoria_ia = $ia_content['categoria_ia'] ?? $categoria_ia;
                    $urgencia_ia = (int)($ia_content['urgencia_ia'] ?? $urgencia_ia);
                    $sugestao_acao = $ia_content['sugestao_acao'] ?? $sugestao_acao;
                }
            }
        }

        // Salva no Banco de Dados
        $sql = "INSERT INTO reports 
        (tenant_id, setor_id, protocolo_visualizacao, tipo_manifestacao, texto_original_criptografado, iv, resumo_ia, categoria_ia, urgencia_ia, sugestao_acao, status, is_anonimo, nome, email, telefone, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'novo', ?, ?, ?, ?, NOW())";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $tenant_id, $setor_id, $protocolo, $tipo_manifestacao, 
            $texto_cipher, $iv_b64, $resumo_ai, $categoria_ia, $urgencia_ia, $sugestao_acao,
            $is_anonimo, $nome, $email, $telefone
        ]);

        $mensagem = "<div class='alert alert-success fw-bold fs-5'><i class='bi bi-check-circle-fill'></i> Relato cadastrado com sucesso! Protocolo gerado: <span class='text-dark bg-light px-2 rounded border user-select-all'>$protocolo_gerado</span></div>";
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Novo Relato | Ouvvi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', sans-serif; overflow-x: hidden; }
        .wrapper { display: flex; width: 100%; align-items: stretch; min-height: 100vh; }
        
        /* Sidebar */
        #sidebar { min-width: 250px; max-width: 250px; background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%); color: #fff; }
        #sidebar .sidebar-header { padding: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        #sidebar ul li a { padding: 12px 20px; display: block; color: rgba(255,255,255,0.8); text-decoration: none; transition: 0.2s;}
        #sidebar ul li a:hover, #sidebar ul li a.active { color: #fff; background: rgba(255,255,255,0.05); border-left: 4px solid #0d6efd; }
        #sidebar ul li a i { margin-right: 10px; }
        
        /* Content */
        #content { width: 100%; padding: 0; min-height: 100vh; }
        .top-navbar { background: #fff; padding: 15px 30px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .main-container { padding: 30px; }
        .card-custom { border: none; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
    </style>
</head>
<body>
    <div class="wrapper">
        <nav id="sidebar">
            <div class="sidebar-header">
                <h4 class="mb-0 fw-bold"><i class="bi bi-shield-check text-primary"></i> Ouvvi<span class="text-secondary fs-6">SaaS</span></h4>
            </div>
            <ul class="list-unstyled mt-4">
                <li><a href="admin.php"><i class="bi bi-arrow-left"></i> Voltar ao Painel</a></li>
                <li><a href="admin_novo_relato.php" class="active"><i class="bi bi-pencil-square"></i> Inclusão Manual</a></li>
            </ul>
        </nav>

        <div id="content">
            <div class="top-navbar d-flex justify-content-between align-items-center">
                <h5 class="m-0 fw-bold text-dark"><i class="bi bi-headset text-primary"></i> Cadastro de Manifestação (Balcão)</h5>
                <span class="fw-bold small text-muted"><?php echo htmlspecialchars($user_nome); ?></span>
            </div>

            <div class="main-container">
                <div class="row justify-content-center">
                    <div class="col-lg-8">
                        
                        <?php echo $mensagem; ?>

                        <div class="card card-custom bg-white p-4">
                            <form method="POST">
                                <h6 class="fw-bold text-primary mb-3 border-bottom pb-2">Informações da Manifestação</h6>
                                
                                <div class="row g-3 mb-4">
                                    <div class="col-md-4">
                                        <label class="form-label small fw-bold">Origem do Relato</label>
                                        <select name="origem" class="form-select">
                                            <option value="Presencial (Balcão)">Presencial (Balcão)</option>
                                            <option value="Telefone">Telefone</option>
                                            <option value="E-mail">E-mail</option>
                                            <option value="Caixa de Sugestões">Caixa de Sugestões (Física)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small fw-bold">Assunto/Tipo</label>
                                        <select name="tipo_manifestacao" class="form-select" required>
                                            <option value="elogio">Elogio</option>
                                            <option value="sugestao">Sugestão</option>
                                            <option value="reclamacao">Reclamação</option>
                                            <option value="denuncia" selected>Denúncia</option>
                                            <option value="outro">Outro</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small fw-bold">Encaminhar p/ Setor</label>
                                        <select name="setor_id" class="form-select">
                                            <option value="0">Triagem (Não Sei)</option>
                                            <?php foreach($setores as $s): ?>
                                                <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['nome']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label small fw-bold">Descrição do Relato</label>
                                    <textarea name="relato_bruto" class="form-control" rows="5" placeholder="Digite exatamente como o cidadão relatou..." required></textarea>
                                </div>

                                <h6 class="fw-bold text-primary mb-3 border-bottom pb-2">Identificação do Manifestante</h6>
                                
                                <div class="form-check mb-3 bg-light p-2 rounded border">
                                    <input class="form-check-input ms-1" type="checkbox" value="1" id="isAnon" name="is_anonimo">
                                    <label class="form-check-label fw-bold ms-2" for="isAnon text-danger">
                                        Cidadão deseja sigilo/anonimato absoluto
                                    </label>
                                </div>

                                <div id="identFields" class="row g-3 mb-4">
                                    <div class="col-md-12">
                                        <label class="form-label small">Nome Completo</label>
                                        <input type="text" class="form-control" name="nome">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small">E-mail (Para enviar andamento)</label>
                                        <input type="email" class="form-control" name="email">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small">Telefone / WhatsApp</label>
                                        <input type="text" class="form-control" name="telefone">
                                    </div>
                                </div>

                                <div class="text-end">
                                    <button type="submit" class="btn btn-primary fw-bold px-4"><i class="bi bi-save"></i> Salvar e Gerar Protocolo</button>
                                </div>
                            </form>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Oculta os campos se for anônimo
        const anon = document.getElementById('isAnon');
        const ident = document.getElementById('identFields');
        anon.addEventListener('change', function() {
            if(this.checked) {
                ident.style.opacity = '0.4';
                ident.style.pointerEvents = 'none';
            } else {
                ident.style.opacity = '1';
                ident.style.pointerEvents = 'auto';
            }
        });
    </script>
</body>
</html>