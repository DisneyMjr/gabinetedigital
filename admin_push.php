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

// if(!tem_acesso('marketing')) die("Acesso negado. Faça upgrade do seu plano.");

$nome_sistema = $site_config['nome_vereador'] ?? 'Gabinete Digital';
$msg_alerta = "";

// --- PROCESSAR SALVAMENTO DAS CHAVES ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'salvar_config') {
    $novo_app_id = trim($_POST['onesignal_app_id']);
    $nova_api_key = trim($_POST['onesignal_api_key']);

    try {
        $stmt = $pdo->prepare("UPDATE configuracoes SET onesignal_app_id = ?, onesignal_api_key = ? WHERE tenant_id = ?");
        $stmt->execute([$novo_app_id, $nova_api_key, TENANT_ID]);
        
        // Atualiza a variável da sessão atual para não precisar dar F5
        $site_config['onesignal_app_id'] = $novo_app_id;
        $site_config['onesignal_api_key'] = $nova_api_key;
        
        $msg_alerta = "<div class='alert alert-success shadow-sm'><i class='fas fa-check-circle me-2'></i> Integração salva com sucesso! Seu sistema de Push está ativo.</div>";
    } catch (Exception $e) {
        $msg_alerta = "<div class='alert alert-danger shadow-sm'><i class='fas fa-times-circle me-2'></i> Erro ao salvar: " . $e->getMessage() . "</div>";
    }
}

// Puxa as chaves atuais
$app_id = $site_config['onesignal_app_id'] ?? ''; 
$rest_api_key = $site_config['onesignal_api_key'] ?? '';
$tem_configuracao = (!empty($app_id) && !empty($rest_api_key));

// MODO DE EDIÇÃO FORÇADA
if (isset($_GET['configurar'])) {
    $tem_configuracao = false;
}

// --- PROCESSAR DISPARO DE MENSAGEM ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'enviar_push' && $tem_configuracao) {
    
    $titulo = trim($_POST['titulo'] ?? '');
    $mensagem = trim($_POST['mensagem'] ?? '');
    $url_link = trim($_POST['url_link'] ?? '');

    if (empty($titulo) || empty($mensagem)) {
        $msg_alerta = "<div class='alert alert-warning shadow-sm'><i class='fas fa-exclamation-circle me-2'></i> Título e mensagem são obrigatórios.</div>";
    } else {
        $filtros = [
            ["field" => "tag", "key" => "tipo", "relation" => "=", "value" => "eleitor"],
            ["operator" => "AND"],
            ["field" => "tag", "key" => "tenant_id", "relation" => "=", "value" => (string)TENANT_ID]
        ];

        $fields = [
            'app_id' => $app_id,
            'filters' => $filtros,
            'contents' => ["en" => $mensagem, "pt" => $mensagem],
            'headings' => ["en" => $titulo, "pt" => $titulo]
        ];

        if (!empty($url_link)) { $fields['url'] = $url_link; }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json; charset=utf-8', 'Authorization: Basic ' . $rest_api_key]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            $msg_alerta = "<div class='alert alert-danger shadow-sm'><i class='fas fa-times-circle me-2'></i> Erro interno no Servidor de disparo.</div>";
        } else {
            $resp_data = json_decode($response, true);
            if ($httpCode == 200 && isset($resp_data['id'])) {
                $msg_alerta = "<div class='alert alert-success shadow-sm fw-bold border-success border-2'><i class='fas fa-paper-plane me-2'></i> Sucesso! Sua mensagem foi enviada.</div>";
            } else {
                $erro_msg = $resp_data['errors'][0] ?? "Verifique se suas chaves estão corretas.";
                $msg_alerta = "<div class='alert alert-danger shadow-sm'><i class='fas fa-times-circle me-2'></i> Falha no OneSignal: {$erro_msg}</div>";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Disparo Push - <?= htmlspecialchars($nome_sistema) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap" rel="stylesheet">
    
    <style>
        body { background-color: #f4f7f6; font-family: 'Inter', sans-serif; padding-bottom: 50px; }
        .navbar-custom { background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%); padding: 15px 0; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .navbar-brand { font-weight: 800; font-size: 1.4rem; color: white !important; }
        .card-custom { background: white; border-radius: 16px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.03); overflow: hidden; }
        .form-control:focus { border-color: #0d6efd; box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25); }
        .btn-magico { background: linear-gradient(90deg, #0d6efd 0%, #0dcaf0 100%); color: white; border: none; padding: 15px; border-radius: 10px; font-weight: 800; font-size: 1.1rem; width: 100%; transition: all 0.3s ease; }
        .btn-magico:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(13, 110, 253, 0.5); color: white; }
        .passo-box { background: #f8fafc; border-left: 4px solid #0d6efd; padding: 15px 20px; border-radius: 0 8px 8px 0; margin-bottom: 15px; }
        .passo-numero { font-weight: 900; color: #0d6efd; font-size: 1.2rem; margin-right: 10px; }
        
        /* Estilos do Mockup (Celular) */
        .phone-mockup { width: 300px; height: 600px; background-color: #000; border-radius: 40px; margin: 0 auto; position: relative; box-shadow: inset 0 0 0 10px #1f2937, 0 20px 40px rgba(0,0,0,0.2); overflow: hidden; display: flex; flex-direction: column; }
        .phone-notch { position: absolute; top: 0; left: 50%; transform: translateX(-50%); width: 120px; height: 25px; background-color: #1f2937; border-bottom-left-radius: 15px; border-bottom-right-radius: 15px; z-index: 10; }
        .phone-screen { background-color: #f8fafc; background-image: url('https://images.unsplash.com/photo-1618506557422-901726a4574f?q=80&w=300'); background-size: cover; background-position: center; flex-grow: 1; padding: 50px 15px 20px; }
        .push-notification { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); border-radius: 12px; padding: 12px; display: flex; gap: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .push-icon { width: 40px; height: 40px; background: #0d6efd; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: white; flex-shrink: 0; }
        .push-content { overflow: hidden; width: 100%; }
        .push-app-name { font-size: 0.7rem; color: #64748b; font-weight: 600; text-transform: uppercase; margin-bottom: 2px; display: flex; justify-content: space-between;}
        .push-title { font-size: 0.9rem; font-weight: 700; color: #0f172a; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .push-body { font-size: 0.8rem; color: #475569; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-custom sticky-top mb-5">
        <div class="container">
            <a class="navbar-brand" href="admin_painel.php"><i class="fas fa-arrow-left me-2"></i>Voltar ao Painel</a>
            <div class="d-flex text-white align-items-center">
                <span class="opacity-75 me-3"><i class="fas fa-paper-plane me-1"></i> Disparo Push</span>
            </div>
        </div>
    </nav>

    <div class="container">
        <?= $msg_alerta ?>

        <?php if (!$tem_configuracao): ?>
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="card-custom p-4 p-md-5 border border-primary border-opacity-25">
                    <div class="text-center mb-5">
                        <div class="d-inline-block bg-primary bg-opacity-10 text-primary p-3 rounded-circle mb-3">
                            <i class="fas fa-cogs fa-2x"></i>
                        </div>
                        <h2 class="fw-bold">Configure seu Sistema de Notificações</h2>
                        <p class="text-muted">Para garantir sua privacidade e controle total dos seus eleitores, nós utilizamos a plataforma internacional <b>OneSignal</b>. Siga os passos abaixo para conectar o seu site gratuitamente.</p>
                    </div>

                    <div class="row g-5">
                        <div class="col-md-6">
                            <h5 class="fw-bold mb-4">Passo a Passo:</h5>
                            
                            <div class="passo-box">
                                <span class="passo-numero">1</span>
                                Crie uma conta gratuita acessando <a href="https://onesignal.com/" target="_blank" class="fw-bold">onesignal.com</a>
                            </div>
                            
                            <div class="passo-box">
                                <span class="passo-numero">2</span>
                                No painel deles, clique em <b>"New App/Website"</b> e coloque o nome do seu Mandato.
                            </div>

                            <div class="passo-box">
                                <span class="passo-numero">3</span>
                                Escolha a opção <b>Web</b>. No campo <i>Site URL</i>, digite exatamente: <br>
                                <code class="d-block mt-2 p-2 bg-dark text-white rounded">https://<?= $_SERVER['HTTP_HOST'] ?></code>
                            </div>

                            <div class="passo-box">
                                <span class="passo-numero">4</span>
                                Após salvar, vá no menu esquerdo em <b>Settings > Keys & IDs</b>. Copie as duas chaves e cole no formulário ao lado!
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="bg-light p-4 rounded-4 border">
                                <h5 class="fw-bold mb-4"><i class="fas fa-link me-2 text-primary"></i>Cole suas Chaves Aqui</h5>
                                <form method="POST">
                                    <input type="hidden" name="acao" value="salvar_config">
                                    
                                    <div class="mb-3">
                                        <label class="form-label fw-bold small text-muted">ONESIGNAL APP ID</label>
                                        <input type="text" name="onesignal_app_id" value="<?= htmlspecialchars($app_id) ?>" class="form-control" placeholder="Ex: 8d2c0b... " required>
                                    </div>

                                    <div class="mb-4">
                                        <label class="form-label fw-bold small text-muted">REST API KEY (Legacy API Key)</label>
                                        <input type="text" name="onesignal_api_key" value="<?= htmlspecialchars($rest_api_key) ?>" class="form-control" placeholder="Ex: MzJhY2..." required>
                                    </div>

                                    <button type="submit" class="btn btn-primary w-100 py-3 fw-bold rounded-3">
                                        <i class="fas fa-save me-2"></i> SALVAR INTEGRAÇÃO
                                    </button>
                                    
                                    <?php if(isset($_GET['configurar'])): ?>
                                        <div class="text-center mt-3">
                                            <a href="admin_push.php" class="text-muted small">Cancelar edição</a>
                                        </div>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php else: ?>
        <div class="row mb-4 align-items-center">
            <div class="col-md-8">
                <h2 class="fw-bold" style="color: #1e293b;">Central de Engajamento</h2>
                <p class="text-muted mb-0">Faça o celular dos seus eleitores apitar com novidades do mandato e convites.</p>
            </div>
            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                <a href="admin_push.php?configurar=1" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                    <i class="fas fa-cog me-1"></i> Editar Chaves de API
                </a>
            </div>
        </div>

        <div class="row g-5">
            <div class="col-lg-7">
                <div class="card-custom p-4 p-md-5 h-100 border border-light">
                    <form method="POST">
                        <input type="hidden" name="acao" value="enviar_push">
                        
                        <div class="mb-4">
                            <label class="form-label fw-bold text-dark small">TÍTULO DA NOTIFICAÇÃO</label>
                            <input type="text" name="titulo" id="inpTitulo" class="form-control form-control-lg bg-light" placeholder="Ex: 💉 Vacinação liberada no Centro!" maxlength="50" required>
                            <div class="text-end text-muted mt-1" style="font-size: 11px;"><span id="countTitulo">0</span>/50</div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label fw-bold text-dark small">MENSAGEM (O que eles vão ler)</label>
                            <textarea name="mensagem" id="inpMensagem" class="form-control bg-light" rows="3" placeholder="Ex: Venha vacinar seus filhos hoje no posto central até as 17h. Compartilhe!" maxlength="150" required></textarea>
                            <div class="text-end text-muted mt-1" style="font-size: 11px;"><span id="countMsg">0</span>/150</div>
                        </div>

                        <div class="mb-5">
                            <label class="form-label fw-bold text-dark small">LINK DE DESTINO (Opcional)</label>
                            <input type="url" name="url_link" class="form-control bg-light" placeholder="https://seu-site.com.br/noticia">
                            <small class="text-muted" style="font-size: 12px;">Para onde o eleitor será direcionado ao clicar na notificação.</small>
                        </div>

                        <button type="submit" class="btn-magico" onclick="return confirm('Deseja disparar esta mensagem para todos os eleitores agora?');">
                            <i class="fas fa-satellite-dish me-2"></i> DISPARAR PARA TODOS AGORA
                        </button>
                    </form>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card-custom p-4 bg-light border h-100 d-flex flex-column align-items-center justify-content-center">
                    <h5 class="fw-bold mb-4 text-secondary text-center"><i class="fas fa-mobile-alt me-2"></i> Preview no Celular</h5>
                    <div class="phone-mockup">
                        <div class="phone-notch"></div>
                        <div class="phone-screen">
                            <div class="push-notification" id="boxPush">
                                <div class="push-icon shadow-sm"><i class="fas fa-bullhorn"></i></div>
                                <div class="push-content">
                                    <div class="push-app-name"><span>Gabinete Digital</span><span>Agora</span></div>
                                    <div class="push-title" id="prevTitulo">Digite um título...</div>
                                    <div class="push-body" id="prevMensagem">Escreva sua mensagem para ver como ficará na tela do eleitor.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php if ($tem_configuracao): ?>
    <script>
        const inpTitulo = document.getElementById('inpTitulo');
        const inpMensagem = document.getElementById('inpMensagem');
        const prevTitulo = document.getElementById('prevTitulo');
        const prevMensagem = document.getElementById('prevMensagem');
        
        inpTitulo.addEventListener('input', function() {
            prevTitulo.innerText = this.value ? this.value : 'Digite um título...';
            document.getElementById('countTitulo').innerText = this.value.length;
        });

        inpMensagem.addEventListener('input', function() {
            prevMensagem.innerText = this.value ? this.value : 'Escreva sua mensagem para ver como ficará na tela do eleitor.';
            document.getElementById('countMsg').innerText = this.value.length;
        });
    </script>
    <?php endif; ?>
</body>
</html>