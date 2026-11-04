<?php
session_start();
// Inicia o buffer de saída para evitar que erros apareçam no arquivo final
ob_start();

require 'config.php';

// --- 1. SEGURANÇA ---
if (!isset($_SESSION['permissoes']) || !in_array('master', $_SESSION['permissoes'])) {
    die("Acesso negado.");
}

// --- 2. CONFIGURAÇÕES DO PRESTADOR (VOCÊ) ---
$stmtConfig = $pdo->prepare("SELECT cnpj, inscricao_municipal FROM configuracoes WHERE tenant_id = ?"); 
$stmtConfig->execute([$_SESSION['tenant_id']]); 
$configMaster = $stmtConfig->fetch(PDO::FETCH_ASSOC);

// Tratamento de nulo no CNPJ Master
$rawCnpj = $configMaster['cnpj'] ?? '';
$cnpjPrestador = preg_replace('/[^0-9]/', '', (string)$rawCnpj);
if (strlen($cnpjPrestador) !== 14) { $cnpjPrestador = '45745332000133'; } 

if (strlen($cnpjPrestador) < 14) {
    ob_end_clean(); // Limpa buffer antes de morrer
    die("<script>alert('Erro: Configure seu CNPJ no Painel Master antes de exportar.'); window.close();</script>");
}

// --- 3. BUSCAR FATURAS PAGAS PENDENTES ---
$sql = "
    SELECT 
        f.id, f.tenant_id, f.valor, f.data_pagamento, 
        t.doc_tipo, t.doc_numero, t.razao_social, t.nome, 
        t.email_contato,
        t.cep, t.endereco, t.numero, t.bairro, t.cidade, t.estado, t.inscricao_municipal as im_tomador
    FROM faturas f
    JOIN tenants t ON f.tenant_id = t.id
    WHERE f.status = 'pago' 
    AND (f.nota_numero IS NULL OR f.nota_numero = '')
    ORDER BY f.data_pagamento ASC
";

$stmt = $pdo->query($sql);
$faturas = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($faturas) == 0) {
    ob_end_clean();
    die("<script>alert('Nenhuma fatura pendente encontrada.'); window.close();</script>");
}

// --- 4. MONTAR O XML (PADRÃO NACIONAL - DPS) ---
$xml = '<?xml version="1.0" encoding="UTF-8"?>';
$xml .= '<LoteDPS xmlns="http://www.sped.fazenda.gov.br/nfse">';
$xml .= '<versao>1.00</versao>';
$xml .= '<CNPJ>' . $cnpjPrestador . '</CNPJ>';
$xml .= '<dhTrans>' . date('Y-m-d\TH:i:s') . '</dhTrans>';
$xml .= '<listaDPS>';

foreach ($faturas as $fatura) {
    
    $idDPS = "DPS" . $fatura['id'] . date('His');
    $dataFato = date('Y-m-d', strtotime($fatura['data_pagamento']));
    $valor = number_format($fatura['valor'], 2, '.', '');
    
    // --- TRATAMENTO DE CAMPOS VAZIOS (A CORREÇÃO PRINCIPAL ESTÁ AQUI) ---
    $docRaw = $fatura['doc_numero'] ?? '';
    $docTomador = preg_replace('/[^0-9]/', '', $docRaw);
    
    // Nome: Prioriza Razão Social, senão usa Nome, senão string vazia
    $nomeRaw = !empty($fatura['razao_social']) ? $fatura['razao_social'] : ($fatura['nome'] ?? '');
    $nomeTomador = strtoupper(substr($nomeRaw, 0, 60));
    
    // Endereço (Garante que não seja nulo)
    $cepRaw = $fatura['cep'] ?? '';
    $cepTomador = preg_replace('/[^0-9]/', '', $cepRaw);
    
    $endereco = $fatura['endereco'] ?? 'Não Informado';
    $numero = $fatura['numero'] ?? 'S/N';
    $bairro = $fatura['bairro'] ?? 'Centro';
    
    $codMunTomador = '3145208'; // IBGE Fixo (Ajuste se necessário buscar do banco)
    
    $xml .= '<DPS>';
    $xml .= '<infDPS Id="' . $idDPS . '">';
    
        $xml .= '<dhEmi>' . $dataFato . 'T' . date('H:i:s') . '</dhEmi>';
        
        $xml .= '<serv>';
            $xml .= '<locPrest>';
                $xml .= '<cMun>3145208</cMun>'; 
            $xml .= '</locPrest>';
            
            $xml .= '<cServ>';
                $xml .= '<cTribNac>130301</cTribNac>'; 
                            $desc = 'LICENCA DE USO DE SOFTWARE | FATURA_ID=' . $fatura['id'] . ' | TENANT_ID=' . $fatura['tenant_id'];
            $xml .= '<xDescServ>' . htmlspecialchars($desc, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</xDescServ>';
            $xml .= '</cServ>';
        $xml .= '</serv>';

        $xml .= '<valores>';
            $xml .= '<vServ>' . $valor . '</vServ>';
            $xml .= '<trib>';
                $xml .= '<tribMun>';
                    $xml .= '<pAliq>2.00</pAliq>'; 
                    $xml .= '<tpRetISS>2</tpRetISS>';
                $xml .= '</tribMun>';
            $xml .= '</trib>';
        $xml .= '</valores>';

        $xml .= '<prest>';
            $xml .= '<CNPJ>' . $cnpjPrestador . '</CNPJ>';
        $xml .= '</prest>';

        $xml .= '<toma>';
            if (strlen($docTomador) == 14) {
                $xml .= '<CNPJ>' . $docTomador . '</CNPJ>';
            } else {
                // Garante CPF válido ou vazio se não tiver
                $cpfValido = (strlen($docTomador) == 11) ? $docTomador : '00000000000';
                $xml .= '<CPF>' . $cpfValido . '</CPF>';
            }
            $xml .= '<xNome>' . $nomeTomador . '</xNome>';
            
            $xml .= '<end>';
                $xml .= '<endNac>';
                    $xml .= '<cMun>' . $codMunTomador . '</cMun>';
                    $xml .= '<CEP>' . $cepTomador . '</CEP>';
                $xml .= '</endNac>';
                $xml .= '<xLgr>' . substr($endereco, 0, 60) . '</xLgr>';
                $xml .= '<nro>' . substr($numero, 0, 10) . '</nro>';
                $xml .= '<xBairro>' . substr($bairro, 0, 60) . '</xBairro>';
            $xml .= '</end>';
        $xml .= '</toma>';

    $xml .= '</infDPS>';
    $xml .= '</DPS>';
}

$xml .= '</listaDPS>';
$xml .= '</LoteDPS>';

// --- 5. LIMPAR BUFFER E BAIXAR ARQUIVO ---
// Remove qualquer aviso de "Deprecated" que tenha sido gerado antes
if (ob_get_length()) ob_clean();

$nomeArquivo = "Lote_Nacional_NFS-e_" . date('Y-m-d_Hi') . ".xml";

header('Content-Description: File Transfer');
header('Content-Type: application/xml');
header('Content-Disposition: attachment; filename="'. $nomeArquivo .'"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . strlen($xml));

echo $xml;
exit;
?>