<?php

// --- CONFIGURAÇÕES INICIAIS ---

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require 'config.php';
require 'protecao.php';

if (!defined('TENANT_ID')) die("Erro de identificação do cliente.");
// Verifica se o usuário tem acesso a este módulo (Premium)
// if(!tem_acesso('oficios_ia')) die("Acesso negado. Faça upgrade do seu plano.");
$nome_sistema = $site_config['nome_vereador'] ?? 'Gabinete Digital';
$nome_user = explode(' ', $_SESSION['admin_nome'] ?? 'Admin')[0];
// =================================================================================
// 🔍 BUSCA AS ÚLTIMAS SOLICITAÇÕES DA OUVIDORIA (CORRIGIDO COM SEU BANCO)
// =================================================================================
try {
    // Agora buscando exatamente as colunas "tipo" e "mensagem" que existem na sua tabela
    $stmt = $pdo->prepare("SELECT id, nome_cidadao, tipo, mensagem, bairro, rua FROM solicitacoes WHERE tenant_id = ? ORDER BY id DESC LIMIT 50");
    $stmt->execute([TENANT_ID]);
    $solicitacoes_db = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $solicitacoes_db = [];
}
// =================================================================================
// 🤖 PROCESSAMENTO DA IA (AJAX POST)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'gerar_oficio') {
    header('Content-Type: application/json');
    
    $destinatario = trim($_POST['destinatario'] ?? '');
    $assunto = trim($_POST['assunto'] ?? '');
    $detalhes = trim($_POST['detalhes'] ?? '');
    
    // --- BUSCANDO A CHAVE DA API NO ARQUIVO .ENV ---
    $api_key = getenv('OPENAI_API_KEY') ?: ($_ENV['OPENAI_API_KEY'] ?? '');
    
    // Se não achou pelo ambiente normal, tenta ler o arquivo fisicamente
    if (empty($api_key) && file_exists(__DIR__ . '/.env')) {
        $env_vars = parse_ini_file(__DIR__ . '/.env');
        $api_key = $env_vars['OPENAI_API_KEY'] ?? '';
    }

    // Trava de segurança
    if (empty($api_key)) {
        echo json_encode([
            'sucesso' => false, 
            'texto' => '<p class="text-danger text-center mt-5"><i class="fas fa-exclamation-triangle fa-2x mb-2"></i><br><strong>Erro de Configuração:</strong><br>A chave da API da OpenAI não foi encontrada no arquivo <code>.env</code>. Contate o suporte técnico.</p>'
        ]);
        exit;
    }
    
    // =================================================================================
    // 📍 1. BUSCA A LOCALIZAÇÃO DINÂMICA E A LOGO DO CLIENTE
    // =================================================================================
    $cidade_sistema = 'Sua Cidade'; 
    $estado_sistema = 'UF'; 
    $logo_sistema = '';

    try {
        $stmtLoc = $pdo->prepare("SELECT cidade, estado, logo_site FROM configuracoes WHERE tenant_id = ?");
        $stmtLoc->execute([TENANT_ID]);
        $locDb = $stmtLoc->fetch(PDO::FETCH_ASSOC);
        
        if ($locDb) {
            if (!empty($locDb['cidade'])) $cidade_sistema = trim($locDb['cidade']);
            if (!empty($locDb['estado'])) $estado_sistema = trim($locDb['estado']);
            if (!empty($locDb['logo_site'])) $logo_sistema = trim($locDb['logo_site']);
        }
    } catch (Exception $e) {}

    $cidade_upper = mb_strtoupper($cidade_sistema, 'UTF-8');
    $estado_upper = mb_strtoupper($estado_sistema, 'UTF-8');
    $destinatario_upper = mb_strtoupper($destinatario, 'UTF-8');
    $nome_sistema_upper = mb_strtoupper($nome_sistema, 'UTF-8');

    // --- LÓGICA DO CABEÇALHO COM LOGO ---
    $incluir_logo = $_POST['incluir_logo'] ?? 'nao';
    $html_logo = "";
    
    if ($incluir_logo === 'sim' && !empty($logo_sistema)) {
        // Descobre o domínio do site para a imagem não "quebrar" na hora de imprimir
        $protocolo = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https://" : "http://";
        $dominio = $_SERVER['HTTP_HOST'];
        $url_logo = (strpos($logo_sistema, 'http') === 0) ? $logo_sistema : $protocolo . $dominio . '/' . ltrim($logo_sistema, '/');
        
        $html_logo = "<img src=\"{$url_logo}\" style=\"max-height: 80px; margin-bottom: 15px;\" alt=\"Logo Oficial\"><br>";
    }

    // =================================================================================
    // 📅 2. FORMATA A DATA REAL EM PORTUGUÊS
    // =================================================================================
    date_default_timezone_set('America/Sao_Paulo');
    $meses = [
        '1' => 'janeiro', '2' => 'fevereiro', '3' => 'março', '4' => 'abril', 
        '5' => 'maio', '6' => 'junho', '7' => 'julho', '8' => 'agosto', 
        '9' => 'setembro', '10' => 'outubro', '11' => 'novembro', '12' => 'dezembro'
    ];
    $data_hoje = date('d') . ' de ' . $meses[date('n')] . ' de ' . date('Y');
    
    // =================================================================================
    // 🧠 3. MONTANDO O PROMPT COM AS VARIÁVEIS E A LOGO INJETADA
    // =================================================================================
    $prompt = "Aja como um Assessor Parlamentar extremamente experiente do Brasil. O usuário quer gerar um ofício formal seguindo um padrão rigoroso.
    
    DADOS PARA O OFÍCIO:
    - Remetente: {$nome_sistema}
    - Destinatário: {$_POST['destinatario']}
    - Assunto: {$_POST['assunto']}
    - Detalhes do Pedido: {$_POST['detalhes']}
    
    Sua tarefa é retornar APENAS código HTML puro, contendo a estrutura abaixo preenchida. 
    Desenvolva um texto formal, objetivo, respeitoso e persuasivo no lugar do [CORPO DO TEXTO] usando os detalhes fornecidos. Use jargões adequados da administração pública.
    
    OBRIGATÓRIO USAR ESTA ESTRUTURA HTML EXATA:
    
    <div style=\"font-family: Arial, sans-serif; font-size: 14px; color: #000; line-height: 1.5;\">
        <div style=\"text-align: center; font-weight: bold;\">
            {$html_logo}
            CÂMARA MUNICIPAL DE {$cidade_upper}<br>
            ESTADO DE {$estado_upper}<br>
            <br>
            Ofício Gabinete do Presidente [GERAR_NUMERO_ALEATORIO]/" . date('Y') . "
        </div>
        <br><br>
        <div style=\"text-align: left;\">
            A Sua Senhoria<br>
            <strong>{$destinatario_upper}</strong><br>
        </div>
        <br>
        <div style=\"text-align: left;\">
            <strong>Assunto: {$_POST['assunto']}</strong>
        </div>
        <br>
        <div style=\"text-align: right;\">
            {$cidade_sistema}-{$estado_sistema}, {$data_hoje}.
        </div>
        <br><br>
        <div style=\"text-align: left;\">
            Ilmo. Servidor(a),<br><br>
        </div>
        <div style=\"text-align: justify; text-indent: 40px;\">
            [CORPO DO TEXTO DESENVOLVIDO POR VOCÊ AQUI. EXPLIQUE A DEMANDA COM CLAREZA.]
        </div>
        <br><br>
        <div style=\"text-align: left;\">
            Atenciosamente,
        </div>
        <br><br><br>
        <div style=\"text-align: left;\">
            <strong>{$nome_sistema_upper}</strong><br>
            Vereador
        </div>
    </div>
    
    NÃO adicione marcações Markdown como ```html no início ou no fim. Responda APENAS com as tags HTML estruturadas.";

    // --- COMUNICAÇÃO COM A OPENAI ---
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => 'gpt-3.5-turbo', // Pode mudar para gpt-4o-mini se preferir
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'temperature' => 0.7
    ]));
    $response = curl_exec($ch);
    $data = json_decode($response, true);

    // Fechar conexão
    curl_close($ch);

    // --- TRATAMENTO DE ERROS DA API ---
    if (isset($data['error'])) {
        $erro_msg = $data['error']['message'];
        echo json_encode([
            'sucesso' => false, 
            'texto' => "<p class='text-danger text-center mt-5'><i class='fas fa-times-circle fa-2x mb-2'></i><br><strong>Erro na OpenAI:</strong><br>{$erro_msg}</p>"
        ]);
        exit;
    }

    $oficio_gerado = $data['choices'][0]['message']['content'] ?? "";
    if (empty($oficio_gerado)) {
        echo json_encode(['sucesso' => false, 'texto' => '<p class="text-danger">A IA não retornou nenhuma resposta.</p>']);
        exit;
    }
    echo json_encode(['sucesso' => true, 'texto' => $oficio_gerado]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Redator IA - <?= htmlspecialchars($nome_sistema) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f7f6; font-family: 'Inter', sans-serif; padding-bottom: 50px; }
        .navbar-custom { background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%); padding: 15px 0; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .navbar-brand { font-weight: 800; font-size: 1.4rem; color: white !important; }
        .card-ia { background: white; border-radius: 16px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.03); overflow: hidden; }
        .card-header-ia { background: linear-gradient(90deg, #8b5cf6 0%, #d946ef 100%); color: white; padding: 20px 25px; border: none; }
        .form-control:focus { border-color: #8b5cf6; box-shadow: 0 0 0 0.25rem rgba(139, 92, 246, 0.25); }
        .btn-magico { background: linear-gradient(90deg, #8b5cf6 0%, #d946ef 100%); color: white; border: none; padding: 12px; border-radius: 10px; font-weight: 800; font-size: 1rem; width: 100%; transition: all 0.3s ease; box-shadow: 0 5px 15px rgba(139, 92, 246, 0.4); }
        .btn-magico:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(217, 70, 239, 0.5); color: white; }
        .btn-magico:disabled { opacity: 0.7; transform: none; cursor: not-allowed; }
        .editor-container { border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; background: #fff; min-height: 500px; position: relative;}
        .note-editor.note-frame { border: none !important; }
        .loader-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.9); z-index: 10; display: none; flex-direction: column; justify-content: center; align-items: center; border-radius: 10px; }
        .robot-bounce { font-size: 3rem; color: #8b5cf6; animation: bounce 1s infinite alternate; }
        @keyframes bounce { from { transform: translateY(0); } to { transform: translateY(-20px); } }
        .btn-acao { background: white; border: 1px solid #e2e8f0; color: #475569; padding: 8px 15px; border-radius: 8px; font-weight: 600; font-size: 0.9rem; transition: 0.2s; }
        .btn-acao:hover { background: #f8fafc; color: #0f172a; border-color: #cbd5e1; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-custom sticky-top">
        <div class="container">
            <a class="navbar-brand" href="admin_painel.php"><i class="fas fa-arrow-left me-2"></i>Voltar ao Painel</a>
            <div class="d-flex text-white align-items-center">
                <span class="opacity-75 me-3"><i class="fas fa-robot me-1"></i> Redator IA Premium</span>
            </div>
        </div>
    </nav>
    <div class="container mt-5">
        <div class="row mb-4">
            <div class="col-12 text-center">
                <h2 class="fw-bold" style="color: #1e293b;">Gerador de Ofícios com Inteligência Artificial</h2>
                <p class="text-muted">Descreva o problema e deixe o nosso robô redigir o documento formal perfeito para você.</p>
            </div>
        </div>
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card-ia h-100">
                    <div class="card-header-ia">
                        <h5 class="m-0 fw-bold"><i class="fas fa-keyboard me-2"></i> O que você precisa?</h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="mb-4 pb-3 border-bottom">
                            <label class="form-label fw-bold small" style="color: #8b5cf6 !important;"><i class="fas fa-link me-1"></i> VINCULAR DEMANDA DA OUVIDORIA</label>
                            <select id="solicitacao_id" class="form-select" style="border: 2px solid #8b5cf6; background-color: #f5f3ff; font-weight: 600; color: #4c1d95;">
                                <option value="">-- Criar ofício do zero (Não vincular) --</option>
                                <?php foreach($solicitacoes_db as $sol): ?>
                                    <option value="<?= $sol['id'] ?>" 
                                            data-tipo="<?= htmlspecialchars($sol['tipo'] ?? 'Demanda') ?>"
                                            data-desc="<?= htmlspecialchars($sol['mensagem'] ?? '') ?>"
                                            data-bairro="<?= htmlspecialchars($sol['bairro'] ?? '') ?>"
                                            data-rua="<?= htmlspecialchars($sol['rua'] ?? '') ?>"
                                            data-nome="<?= htmlspecialchars($sol['nome_cidadao'] ?? '') ?>">
                                        #<?= $sol['id'] ?> - <?= htmlspecialchars($sol['nome_cidadao']) ?> (<?= htmlspecialchars($sol['tipo'] ?? '') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted mt-1 d-block" style="font-size: 0.75rem;">A IA vai ler a mensagem original do cidadão e transformar num ofício.</small>
                        </div>
                        <form id="formIA">
                            <div class="mb-3">
                                <label class="form-label fw-bold text-muted small">DESTINATÁRIO (ÓRGÃO/PESSOA)</label>
                                <input type="text" id="destinatario" class="form-control" placeholder="Ex: Secretaria de Obras" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold text-muted small">ASSUNTO PRINCIPAL</label>
                                <input type="text" id="assunto" class="form-control" placeholder="Ex: Solicitação de Tapa-Buraco" required>
                            </div>
                            <div class="mb-4">
                                <label class="form-label fw-bold text-muted small">DETALHES DA SITUAÇÃO</label>
                                <textarea id="detalhes" class="form-control" rows="5" placeholder="Conte como se fosse para um humano... Ex: Preciso que peçam para tapar um buraco enorme na Rua XV de Novembro, altura do número 100. Os carros estão quebrando pneu todo dia lá." required></textarea>
                            </div>
                            
                            <div class="mb-4 form-check form-switch bg-light p-3 rounded border">
                                <input class="form-check-input ms-0 me-2" type="checkbox" id="incluir_logo" value="sim" checked style="cursor: pointer; width: 40px; height: 20px;">
                                <label class="form-check-label fw-bold text-primary small mt-1" for="incluir_logo" style="cursor: pointer;">
                                    <i class="fas fa-image me-1"></i> Inserir Cabeçalho Oficial (Logo do Sistema)
                                </label>
                            </div>

                            <button type="submit" id="btnGerar" class="btn-magico">
                                <i class="fas fa-magic me-2"></i> GERAR OFÍCIO AGORA
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="card-ia h-100 d-flex flex-column">
                    <div class="p-3 border-bottom d-flex justify-content-between align-items-center bg-light">
                        <h6 class="m-0 fw-bold text-muted"><i class="fas fa-file-alt me-2"></i> Documento Gerado</h6>
                        <div class="d-flex gap-2">
                            <button class="btn-acao" onclick="copiarTexto()"><i class="far fa-copy me-1"></i> Copiar</button>
                            <button class="btn-acao" onclick="imprimirOficio()"><i class="fas fa-print me-1"></i> Imprimir/PDF</button>
                        </div>
                    </div>
                    <div class="editor-container flex-grow-1 p-2 p-md-4">
                        <div class="loader-overlay" id="iaLoader">
                            <i class="fas fa-robot robot-bounce"></i>
                            <h4 class="fw-bold mt-3 text-dark">A IA está escrevendo...</h4>
                            <p class="text-muted small">Pesquisando formatação jurídica e aplicando tom formal.</p>
                        </div>
                        <div id="editorTexto">
                            <p style="color: #94a3b8; text-align: center; margin-top: 100px; font-size: 1.2rem;">
                                <i class="fas fa-arrow-left me-2"></i> Preencha os dados ao lado e clique em Gerar Ofício.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/lang/summernote-pt-BR.min.js"></script>
    <script>
        $(document).ready(function() {
            // Inicializa o editor de texto
            $('#editorTexto').summernote({
                placeholder: 'O resultado da IA aparecerá aqui para você revisar e editar...',
                tabsize: 2,
                height: 400,
                lang: 'pt-BR',
                toolbar: [
                    ['style', ['style']],
                    ['font', ['bold', 'underline', 'clear']],
                    ['color', ['color']],
                    ['para', ['ul', 'ol', 'paragraph']],
                    ['table', ['table']],
                    ['view', ['fullscreen', 'codeview', 'help']]
                ]
            });
        });

        // LÓGICA PARA PREENCHER OS DADOS DA OUVIDORIA
        document.getElementById('solicitacao_id').addEventListener('change', function() {
            var selected = this.options[this.selectedIndex];
            var assuntoField = document.getElementById('assunto');
            var detalhesField = document.getElementById('detalhes');
            if(this.value === '') {
                assuntoField.value = '';
                detalhesField.value = '';
                return;
            }
            var tipo = selected.getAttribute('data-tipo');
            var desc = selected.getAttribute('data-desc');
            var bairro = selected.getAttribute('data-bairro');
            var rua = selected.getAttribute('data-rua');
            var nome = selected.getAttribute('data-nome');
            var localizacaoStr = "";
            if(bairro || rua) {
                localizacaoStr = " (" + (rua ? rua + ", " : "") + (bairro ? "Bairro " + bairro : "") + ")";
            }
            assuntoField.value = "Providências sobre: " + tipo + localizacaoStr;
            var texto = "Por favor, elabore um ofício exigindo providências urgentes baseado no seguinte relato que recebemos no nosso painel de ouvidoria:\n\n";
            texto += "» Cidadão Solicitante: " + nome + "\n";
            texto += "» Local do Problema: " + (rua ? rua + ", " : "") + (bairro ? "Bairro " + bairro : "Não informado") + "\n";
            texto += "» Categoria do Pedido: " + tipo + "\n";
            texto += "» Relato do Cidadão: \"" + desc + "\"\n\n";
            texto += "Gere o texto justificando a importância de atender este munícipe.";
            detalhesField.value = texto;
        });
        // Envio do formulário via AJAX para a IA
        document.getElementById('formIA').addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = document.getElementById('btnGerar');
            const loader = document.getElementById('iaLoader');
            // Pega os valores
            const dest = document.getElementById('destinatario').value;
            const ass = document.getElementById('assunto').value;
            const det = document.getElementById('detalhes').value;
            const incLogo = document.getElementById('incluir_logo').checked ? 'sim' : 'nao';
            
            // Altera botão e mostra loader
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> GERANDO...';
            loader.style.display = 'flex';
            // Chamada Ajax
            $.ajax({
                url: '', // O próprio arquivo processa o POST
                type: 'POST',
                data: {
                    acao: 'gerar_oficio',
                    destinatario: dest,
                    assunto: ass,
                    detalhes: det,
                    incluir_logo: incLogo
                },
                success: function(response) {
                    if(response.sucesso) {
                        // Joga o texto gerado dentro do editor
                        $('#editorTexto').summernote('code', response.texto);
                    } else {
                        // Exibe a mensagem de erro que veio do PHP (ex: API Key não configurada)
                        $('#editorTexto').summernote('code', response.texto);
                    }
                },
                error: function() {
                    $('#editorTexto').summernote('code', '<p class="text-danger text-center mt-5">Erro de comunicação com o servidor.</p>');
                },
                complete: function() {
                    // Restaura botões
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-magic me-2"></i> GERAR OFÍCIO AGORA';
                    loader.style.display = 'none';
                }
            });
        });
        // Função para Copiar o Texto Limpo
        function copiarTexto() {
            var textoHTML = $('#editorTexto').summernote('code');
            // Se o texto for a mensagem inicial, avisa
            if(textoHTML.includes("Preencha os dados ao lado")) {
                alert("Gere um ofício primeiro antes de copiar.");
                return;
            }
            var tempDiv = document.createElement("div");
            tempDiv.innerHTML = textoHTML;
            var textoLimpo = tempDiv.textContent || tempDiv.innerText || "";
            navigator.clipboard.writeText(textoLimpo).then(function() {
                const btn = document.querySelector('.fa-copy').parentNode;
                const originalText = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check text-success me-1"></i> Copiado!';
                setTimeout(() => { btn.innerHTML = originalText; }, 2000);
            });
        }
        // Função nativa para imprimir apenas o ofício
        function imprimirOficio() {
            var conteudo = $('#editorTexto').summernote('code');
            if(conteudo.includes("Preencha os dados ao lado")) {
                alert("Gere um ofício primeiro antes de imprimir.");
                return;
            }
            var janelaImpressao = window.open('', '', 'height=800,width=800');
            janelaImpressao.document.write('<html><head><title>Imprimir Ofício</title>');
            // Adiciona estilos básicos de impressão para o ofício ficar bonito no papel A4
            janelaImpressao.document.write(`
                <style>
                    body { font-family: Arial, sans-serif; padding: 40px; color: #000; line-height: 1.6; }
                    p { margin-bottom: 10px; }
                    @page { margin: 2cm; }
                </style>
            `);
            janelaImpressao.document.write('</head><body>');
            janelaImpressao.document.write(conteudo);
            janelaImpressao.document.write('</body></html>');
            janelaImpressao.document.close();
            setTimeout(function() {
                janelaImpressao.print();
            }, 500);
        }
    </script>
</body>
</html>