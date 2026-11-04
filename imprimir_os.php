<?php
// 1. Configurações e Fuso Horário
date_default_timezone_set('America/Sao_Paulo');
ini_set('display_errors', 0); // Silencia avisos na impressão

require 'config.php';
require 'protecao.php';

// --- 0. SEGURANÇA SAAS ---
if (!defined('TENANT_ID')) die("Erro: Tenant não identificado.");

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

try {
    // 2. Busca de Dados da Solicitação
    $stmt = $pdo->prepare("SELECT * FROM solicitacoes WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$id, TENANT_ID]);
    $dados = $stmt->fetch(PDO::FETCH_ASSOC);

    if(!$dados) die("Solicitação não encontrada ou acesso negado.");

    // 3. Busca de Configurações do Gabinete (Com Fallback)
    $nome_politico = "Gabinete Oficial";
    $logo_politico = "";

    try {
        $stmt_conf = $pdo->prepare("SELECT * FROM configuracoes WHERE tenant_id = ? LIMIT 1");
        $stmt_conf->execute([TENANT_ID]);
        $conf = $stmt_conf->fetch(PDO::FETCH_ASSOC);
        
        if ($conf) {
            // Usa os nomes de colunas que o banco possuir
            $nome_politico = $conf['nome_vereador'] ?? $conf['nome'] ?? "Gabinete Oficial";
            $logo_politico = $conf['logo'] ?? "";
        }
    } catch (Exception $e_conf) {
        // Se a tabela der erro, mantemos os padrões acima
    }

} catch (PDOException $e) {
    die("Erro Crítico de Conexão.");
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>OS_<?= htmlspecialchars($dados['protocolo'] ?? $id) ?></title>
    <style>
        /* Estilos de Impressão A4 */
        @page { size: A4; margin: 10mm; }
        body { font-family: 'Segoe UI', Arial, sans-serif; color: #222; background: #f0f0f0; margin: 0; padding: 0; }
        
        .page { 
            width: 180mm; 
            margin: 20px auto; 
            background: #fff; 
            padding: 15mm; 
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            min-height: 260mm;
        }

        /* Cabeçalho */
        .header { 
            display: flex; 
            align-items: center; 
            border-bottom: 3px solid #1a2b49; 
            padding-bottom: 15px; 
            margin-bottom: 20px; 
        }
        .logo-img { max-width: 120px; max-height: 90px; margin-right: 25px; }
        .header-info h1 { margin: 0; font-size: 20pt; color: #1a2b49; text-transform: uppercase; letter-spacing: -1px; }
        .header-info p { margin: 2px 0; font-size: 11pt; color: #555; font-weight: 600; }

        /* Tabela de Dados */
        .grid { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .grid th { background: #f8f9fa; text-align: left; padding: 10px; border: 1px solid #ddd; font-size: 9pt; text-transform: uppercase; color: #666; width: 20%; }
        .grid td { padding: 10px; border: 1px solid #ddd; font-size: 11pt; color: #000; }

        /* Blocos de Conteúdo */
        .section-title { 
            background: #1a2b49; 
            color: #fff; 
            padding: 7px 12px; 
            font-size: 10pt; 
            font-weight: bold; 
            text-transform: uppercase; 
            border-radius: 4px 4px 0 0;
        }
        .section-content { 
            border: 1px solid #ddd; 
            border-top: none; 
            padding: 15px; 
            margin-bottom: 20px; 
            font-size: 11pt; 
            background: #fff;
            border-radius: 0 0 4px 4px;
        }
        
        /* Foto */
        .photo-area { text-align: center; }
        .photo-area img { max-width: 100%; max-height: 300px; border: 1px solid #eee; padding: 5px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }

        /* Assinaturas */
        .footer-sig { margin-top: 60px; display: flex; justify-content: space-between; }
        .sig-line { width: 45%; border-top: 1.5px solid #333; text-align: center; padding-top: 8px; font-size: 10pt; font-weight: bold; color: #444; }

        /* Botão */
        .no-print { position: fixed; top: 20px; right: 20px; z-index: 9999; }
        .btn-print { 
            background: #1a2b49; 
            color: #fff; 
            border: none; 
            padding: 12px 25px; 
            font-weight: bold; 
            cursor: pointer; 
            border-radius: 50px; 
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            text-transform: uppercase;
            font-size: 10pt;
        }
        
        @media print { 
            body { background: #fff; }
            .no-print { display: none; } 
            .page { border: none; width: 100%; margin: 0; padding: 5mm; box-shadow: none; } 
        }
    </style>
</head>
<body>

    <div class="no-print">
        <button onclick="window.print()" class="btn-print">🖨️ Imprimir Ordem de Serviço</button>
    </div>

    <div class="page">
        <div class="header">
            <?php if(!empty($logo_politico)): ?>
                <img src="<?= htmlspecialchars($logo_politico) ?>" class="logo-img" onerror="this.style.display='none'">
            <?php endif; ?>
            <div class="header-info">
                <h1>Solicitação do Cidadão</h1>
                <p>Mandato: <?= htmlspecialchars($nome_politico ?? '') ?></p>
                <p style="font-size: 8pt; font-weight: normal; color: #999;">Gerado via Gabinete Digital em <?= date('d/m/Y H:i') ?></p>
            </div>
        </div>

        <table class="grid">
            <tr>
                <th>Nº Protocolo</th>
                <td style="font-weight:bold; color: #d32f2f;">#<?= htmlspecialchars($dados['protocolo'] ?? $id) ?></td>
                <th>Data Abertura</th>
                <td><?= date('d/m/Y H:i', strtotime($dados['data_abertura'] ?? 'now')) ?></td>
            </tr>
            <tr>
                <th>Cidadão</th>
                <td><?= htmlspecialchars($dados['nome_cidadao'] ?? 'Não informado') ?></td>
                <th>Contato</th>
                <td><?= htmlspecialchars($dados['whatsapp'] ?? 'Não informado') ?></td>
            </tr>
        </table>

        <div class="section-title">Localização da Demanda</div>
        <div class="section-content">
            <strong>Endereço:</strong> <?= htmlspecialchars($dados['rua'] ?? '') ?>, <?= htmlspecialchars($dados['numero'] ?? 'S/N') ?> - <?= htmlspecialchars($dados['bairro'] ?? '') ?><br>
            <strong>Cidade:</strong> <?= htmlspecialchars($dados['cidade'] ?? '') ?>
            <?php if(!empty($dados['latitude'])): ?>
                <br><small style="color: #666;">Geolocalização: <?= htmlspecialchars($dados['latitude']) ?>, <?= htmlspecialchars($dados['longitude']) ?></small>
            <?php endif; ?>
        </div>

        <div class="section-title">Descrição do Pedido (<?= htmlspecialchars($dados['tipo'] ?? 'Geral') ?>)</div>
        <div class="section-content" style="min-height: 80px; font-style: italic; color: #333;">
            "<?= nl2br(htmlspecialchars($dados['mensagem'] ?? 'Sem detalhes adicionais.')) ?>"
        </div>

        <?php if(!empty($dados['foto'])): ?>
            <div class="section-title">Anexo Fotográfico</div>
            <div class="section-content photo-area">
                <img src="<?= htmlspecialchars($dados['foto']) ?>" onerror="this.parentElement.style.display='none'">
            </div>
        <?php endif; ?>

        <div class="section-title">Uso Interno / Encaminhamento</div>
        <div class="section-content" style="min-height: 90px; color: #bbb;">
            <p>Encaminhado para: ____________________________________________________________________</p>
            <p>Despacho/Obs: ________________________________________________________________________</p>
            <p>______________________________________________________________________________________</p>
        </div>

        <div class="footer-sig">
            <div class="sig-line">Responsável pelo Gabinete</div>
            <div class="sig-line">Ciente do Órgão Executor</div>
        </div>
    </div>

</body>
</html>