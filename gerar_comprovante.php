<?php
require 'config.php';
require 'protecao.php';

if (!defined('TENANT_ID')) die("Erro de acesso.");

// Pega o ID da fatura
$id_fatura = (int)$_GET['id'];

// Busca dados da fatura + dados do cliente + dados fiscais
$sql = "SELECT f.*, 
               t.nome as cliente_nome, 
               t.email_contato,
               c.cnpj_campanha,
               c.cpf_vereador
        FROM faturas f 
        JOIN tenants t ON f.tenant_id = t.id 
        LEFT JOIN configuracoes c ON c.tenant_id = t.id
        WHERE f.id = ? AND f.tenant_id = ?";

$stmt = $pdo->prepare($sql);
$stmt->execute([$id_fatura, TENANT_ID]);
$d = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$d) die("Comprovante não encontrado.");

// Define qual documento mostrar (Prioridade: CNPJ da Campanha -> CPF do Vereador -> Não informado)
$documento_cliente = 'Não informado';
if (!empty($d['cnpj_campanha'])) {
    $documento_cliente = "CNPJ: " . $d['cnpj_campanha'];
} elseif (!empty($d['cpf_vereador'])) {
    $documento_cliente = "CPF: " . $d['cpf_vereador'];
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Recibo #<?= str_pad($d['id'], 6, "0", STR_PAD_LEFT) ?></title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #555; display: flex; justify-content: center; padding: 40px; margin: 0; }
        .folha-a4 { background: white; width: 210mm; min-height: 140mm; padding: 20mm; box-shadow: 0 0 10px rgba(0,0,0,0.3); position: relative; }
        .header { border-bottom: 2px solid #333; padding-bottom: 20px; margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; }
        .titulo { font-size: 24px; font-weight: bold; text-transform: uppercase; color: #333; }
        .subtitulo { font-size: 14px; color: #666; }
        .info-pagamento { text-align: right; }
        .label { font-size: 12px; text-transform: uppercase; color: #888; font-weight: bold; }
        .valor-destaque { font-size: 28px; font-weight: bold; color: #333; }
        .corpo-texto { font-size: 16px; line-height: 1.8; color: #444; text-align: justify; margin: 40px 0; }
        .assinatura-box { margin-top: 80px; text-align: center; }
        .assinatura-linha { border-top: 1px solid #333; width: 60%; margin: 0 auto 10px auto; }
        .empresa-nome { font-weight: bold; font-size: 14px; }
        .empresa-doc { font-size: 12px; color: #666; }
        .footer { position: absolute; bottom: 10mm; left: 0; width: 100%; text-align: center; font-size: 10px; color: #999; }
        .btn-print { position: fixed; top: 20px; right: 20px; background: #0d6efd; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: bold; box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
        .btn-print:hover { background: #0b5ed7; }
        
        @media print {
            body { background: white; padding: 0; }
            .folha-a4 { box-shadow: none; width: 100%; height: auto; padding: 0; }
            .btn-print { display: none; }
        }
    </style>
</head>
<body>

    <button onclick="window.print()" class="btn-print">🖨️ IMPRIMIR / SALVAR PDF</button>

    <div class="folha-a4">
        
        <div class="header">
            <div>
                <div class="titulo">Recibo de Pagamento</div>
                <div class="subtitulo">Comprovante de Licença de Software</div>
            </div>
            <div class="info-pagamento">
                <div class="label">NÚMERO</div>
                <div style="font-size: 18px; font-weight: bold; margin-bottom: 5px;">#<?= str_pad($d['id'], 6, "0", STR_PAD_LEFT) ?></div>
                <div class="label">DATA DO PAGAMENTO</div>
                <div><?= date('d/m/Y', strtotime($d['data_pagamento'])) ?></div>
            </div>
        </div>

        <div style="background: #f8f9fa; padding: 15px; border-left: 5px solid #333; margin-bottom: 30px;">
            <div class="label">VALOR PAGO</div>
            <div class="valor-destaque">R$ <?= number_format($d['valor'], 2, ',', '.') ?></div>
        </div>

        <div class="corpo-texto">
            <p>
                Recebemos de <strong><?= mb_strtoupper($d['cliente_nome']) ?></strong>, 
                inscrito(a) no documento <strong><?= $documento_cliente ?></strong>, 
                a importância supra de <strong>R$ <?= number_format($d['valor'], 2, ',', '.') ?></strong>.
            </p>
            <p>
                Este valor refere-se ao pagamento da assinatura/licença de uso do software 
                <strong>Gabinete Digital</strong> (SaaS - Software as a Service) 
                para gestão de mandato parlamentar e relacionamento com eleitores.
            </p>
            <p>
                <em>Forma de Pagamento: Processado via Mercado Pago.</em>
            </p>
        </div>

        <div class="assinatura-box">
            <div class="assinatura-linha"></div>
            <div class="empresa-nome">ILUSTRE DESIGN STUDIO</div>
            <div class="empresa-doc">CNPJ: 45.745.332/0001-33</div>
            <div class="empresa-doc">Nova Serrana - MG</div>
        </div>

        <div class="footer">
            Este documento é um recibo provisório. A Nota Fiscal de Serviços Eletrônica (NFS-e) será emitida e enviada para o e-mail cadastrado em até 48 horas úteis.<br>
            Autenticação do Sistema: <?= md5($d['id'] . $d['data_pagamento'] . TENANT_ID) ?>
        </div>

    </div>

</body>
</html>