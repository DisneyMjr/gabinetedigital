<?php
require 'config.php';
require 'protecao.php';

if (!defined('TENANT_ID')) die("Erro Crítico.");

// --- 1. BUSCA DADOS BÁSICOS ---
try {
    $stmt = $pdo->prepare("SELECT slug, nome FROM tenants WHERE id = ?");
    $stmt->execute([TENANT_ID]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

    // Tenta buscar a logo padrão do banco como inicial
    $stmtLogo = $pdo->prepare("SELECT valor FROM configuracoes WHERE tenant_id = ? AND chave = 'logo_site' LIMIT 1");
    $stmtLogo->execute([TENANT_ID]);
    $logo_db = $stmtLogo->fetchColumn();
} catch (Exception $e) { $logo_db = null; }

$nome_cliente = $tenant['nome'] ?? "Gabinete Digital";
$link_gabinete = "https://" . ($tenant['slug'] ?? $_SERVER['HTTP_HOST']) . ".meugabinetedigital.com.br/gabinete_online.php";

// Fallback para localhost
if ($_SERVER['HTTP_HOST'] == 'localhost' || $_SERVER['HTTP_HOST'] == '127.0.0.1') {
    $link_gabinete = "http://localhost/gabinete_online.php";
}

$telefone = $site_config['whatsapp_oficial'] ?? "(00) 00000-0000";
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8"><title>Designer de Placa QR Code</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@700&family=Montserrat:wght@700;900&display=swap" rel="stylesheet">

    <style>
        body { background: #f3f4f6; font-family: 'Montserrat', sans-serif; }
        .editor-container { padding: 30px; }
        .poster-preview-wrapper { background: #222; padding: 40px; border-radius: 20px; display: flex; justify-content: center; align-items: center; min-height: 850px; }

        .poster-container {
            width: 148mm; height: 210mm;
            background-color: #0F7153; 
            position: relative;
            display: flex; flex-direction: column;
            align-items: center; justify-content: space-between;
            padding: 25px 15px;
            box-shadow: 0 15px 40px rgba(0,0,0,0.5);
            overflow: hidden;
            -webkit-print-color-adjust: exact; print-color-adjust: exact;
        }

        .logo-area { width: 100%; height: 90px; display: flex; justify-content: center; align-items: center; z-index: 5; }
        .logo-img { max-width: 160px; max-height: 90px; object-fit: contain; }
        .nome-topo { color: white; font-size: 1.2rem; font-weight: 900; text-transform: uppercase; text-align: center; border-bottom: 2px solid; padding-bottom: 5px; }

        .poster-title { font-family: 'Caveat', cursive; font-size: 3.2rem; text-align: center; line-height: 0.8; color: #F4E883; transform: rotate(-2deg); }
        .poster-subtitle { font-size: 1.9rem; font-weight: 900; text-transform: uppercase; color: #F4E883; line-height: 1; text-align: center; margin-bottom: 10px; }
        .qr-frame { background: white; padding: 12px; border-radius: 15px; border: 6px solid #F4E883; }
        .speech-bubble { background: #E8F5E9; color: #111; padding: 15px; border-radius: 15px; font-weight: 700; font-size: 0.95rem; text-align: center; position: relative; border: 3px solid #fff; max-width: 95%; }
        .speech-bubble:after { content: ''; position: absolute; top: -15px; left: 50%; border: 15px solid transparent; border-bottom-color: #E8F5E9; margin-left: -15px; }
        .footer-phone { font-size: 1.5rem; color: #F4E883; font-weight: 900; }
        .arrow-drawn { position: absolute; top: 180px; left: 20px; font-size: 4rem; color: #F4E883; transform: rotate(45deg); opacity: 0.6; }
    </style>
</head>
<body>

<div class="container-fluid editor-container">
    <div class="row mb-4 align-items-center">
        <div class="col-md-6">
            <h3 class="fw-bold text-success"><i class="fas fa-magic me-2"></i> Criador de Placa Profissional</h3>
        </div>
        <div class="col-md-6 text-end">
            <a href="admin_painel.php" class="btn btn-outline-secondary me-2">Voltar</a>
            <button onclick="imprimirPopup()" class="btn btn-success btn-lg fw-bold shadow"><i class="fas fa-print me-2"></i> IMPRIMIR AGORA</button>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 p-4 mb-3">
                <h6 class="fw-bold text-uppercase border-bottom pb-2 mb-3"><i class="fas fa-image me-2"></i>Identidade Visual</h6>
                
                <div class="mb-3">
                    <label class="small fw-bold">Substituir Logo (Upload)</label>
                    <input type="file" id="inputLogoFile" class="form-control" accept="image/*">
                    <small class="text-muted" style="font-size: 10px;">Recomendado: Fundo transparente (PNG).</small>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="small fw-bold">Cor Fundo</label>
                        <input type="color" id="inputCorFundo" class="form-control form-control-color w-100" value="#0F7153">
                    </div>
                    <div class="col-6">
                        <label class="small fw-bold">Cor Detalhes</label>
                        <input type="color" id="inputCorDetalhe" class="form-control form-control-color w-100" value="#F4E883">
                    </div>
                </div>

                <h6 class="fw-bold text-uppercase border-bottom pb-2 mb-3 mt-3"><i class="fas fa-edit me-2"></i>Textos</h6>
                <div class="mb-2"><label class="small fw-bold">Título 1</label><input type="text" id="inputT1" class="form-control form-control-sm" value="Acesse o nosso"></div>
                <div class="mb-2"><label class="small fw-bold">Título 2</label><input type="text" id="inputT2" class="form-control form-control-sm" value="GABINETE ONLINE"></div>
                <div class="mb-2"><label class="small fw-bold">Balão</label><textarea id="inputB" class="form-control form-control-sm" rows="2">Faça solicitações e acompanhe nosso trabalho pelo celular! 📲</textarea></div>
                <div class="mb-2"><label class="small fw-bold">WhatsApp</label><input type="text" id="inputF" class="form-control form-control-sm" value="<?= $telefone ?>"></div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="poster-preview-wrapper">
                <div id="area-impressao" class="poster-container">
                    
                    <div class="logo-area" id="previewLogoArea">
                        <?php if($logo_db): ?>
                            <img src="<?= $logo_db ?>" class="logo-img" id="imgLogo">
                        <?php else: ?>
                            <div class="nome-topo" id="previewNome"><?= htmlspecialchars($nome_cliente) ?></div>
                        <?php endif; ?>
                    </div>

                    <i class="fas fa-share arrow-drawn" id="previewSeta"></i>

                    <div style="text-align: center; z-index: 2;">
                        <div id="previewT1" class="poster-title">Acesse o nosso</div>
                        <div id="previewT2" class="poster-subtitle">GABINETE ONLINE</div>
                    </div>

                    <div class="qr-frame" id="previewQRFrame"><div id="qrcode"></div></div>

                    <div id="previewB" class="speech-bubble">Faça solicitações e acompanhe nosso trabalho pelo celular! 📲</div>

                    <div style="text-align: center; z-index: 2;">
                        <div class="poster-title" id="previewZapTexto" style="font-size: 1.2rem; transform:none;">Dúvidas? Chame no Zap:</div>
                        <div id="previewF" class="footer-phone"><?= $telefone ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
    // Gerar QR Inicial
    function gerarQR() {
        document.getElementById("qrcode").innerHTML = "";
        new QRCode(document.getElementById("qrcode"), {
            text: "<?= $link_gabinete ?>",
            width: 170, height: 170
        });
    }

    // Atualização em Tempo Real
    function atualizar() {
        document.getElementById("previewT1").innerText = document.getElementById("inputT1").value;
        document.getElementById("previewT2").innerText = document.getElementById("inputT2").value;
        document.getElementById("previewB").innerText = document.getElementById("inputB").value;
        document.getElementById("previewF").innerText = document.getElementById("inputF").value;

        const fundo = document.getElementById("inputCorFundo").value;
        const detalhe = document.getElementById("inputCorDetalhe").value;

        document.getElementById("area-impressao").style.backgroundColor = fundo;
        
        const ids = ["previewT1", "previewT2", "previewF", "previewZapTexto", "previewSeta"];
        if(document.getElementById("previewNome")) ids.push("previewNome");
        
        ids.forEach(id => { 
            const el = document.getElementById(id);
            if(el) el.style.color = detalhe; 
        });
        document.getElementById("previewQRFrame").style.borderColor = detalhe;
    }

    // Lógica do Upload de Logo (Preview Instantâneo)
    document.getElementById('inputLogoFile').addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(event) {
                const area = document.getElementById('previewLogoArea');
                area.innerHTML = `<img src="${event.target.result}" class="logo-img" id="imgLogo">`;
            }
            reader.readAsDataURL(file);
        }
    });

    document.querySelectorAll('input, textarea').forEach(el => el.addEventListener('input', atualizar));
    
    gerarQR();
    atualizar();

    // Função de Impressão
    function imprimirPopup() {
        const conteudo = document.getElementById('area-impressao').outerHTML;
        const fundo = document.getElementById("inputCorFundo").value;
        const janela = window.open('', '', 'height=850,width=700');
        
        janela.document.write('<html><head><title>Imprimir Placa</title>');
        janela.document.write('<link href="https://fonts.googleapis.com/css2?family=Caveat:wght@700&family=Montserrat:wght@700;900&display=swap" rel="stylesheet">');
        janela.document.write('<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">');
        janela.document.write('<style>');
        janela.document.write(`
            @page { size: A5; margin: 0; }
            body { margin: 0; display: flex; justify-content: center; align-items: center; height: 100vh; }
            .poster-container {
                width: 148mm; height: 210mm;
                background-color: ${fundo} !important;
                box-shadow: inset 0 0 0 1000px ${fundo} !important;
                -webkit-print-color-adjust: exact !important; 
                display: flex; flex-direction: column; align-items: center; justify-content: space-between; padding: 25px 15px; box-sizing: border-box; position: relative;
            }
            .logo-img { max-width: 160px; max-height: 90px; object-fit: contain; }
            .nome-topo { color: white; font-size: 1.2rem; font-weight: 900; text-transform: uppercase; text-align: center; border-bottom: 2px solid; }
            .poster-title { font-family: 'Caveat', cursive; font-size: 3.2rem; text-align: center; line-height: 0.8; transform: rotate(-2deg); }
            .poster-subtitle { font-family: 'Montserrat'; font-size: 2rem; font-weight: 900; text-transform: uppercase; text-align: center; }
            .qr-frame { background: white; padding: 12px; border-radius: 12px; border: 5px solid; }
            .speech-bubble { background: #E8F5E9; color: #111; padding: 15px; border-radius: 15px; font-weight: 700; text-align: center; position: relative; border: 3px solid #fff; max-width: 90%; font-family: 'Montserrat'; }
            .speech-bubble:after { content: ''; position: absolute; top: -15px; left: 50%; border: 15px solid transparent; border-bottom-color: #E8F5E9; margin-left: -15px; }
            .footer-phone { font-size: 1.6rem; font-weight: 900; font-family: 'Montserrat'; }
            .arrow-drawn { position: absolute; top: 180px; left: 20px; font-size: 4rem; transform: rotate(45deg); }
        `);
        janela.document.write('</style></head><body>' + conteudo + '</body></html>');
        janela.document.close();
        
        setTimeout(() => { janela.focus(); janela.print(); }, 1200);
    }
</script>
</body>
</html>