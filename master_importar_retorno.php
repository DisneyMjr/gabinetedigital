<?php
session_start();
require 'config.php';

// --- SEGURANÇA ---
if (!isset($_SESSION['permissoes']) || !in_array('master', $_SESSION['permissoes'])) {
    die("Acesso negado.");
}

$msg = "";
$relatorio = [];

function extract_between(string $haystack, string $key): ?string {
    // Extrai FATURA_ID=123 ou TENANT_ID=19
    if (preg_match('/\b' . preg_quote($key, '/') . '\s*=\s*([0-9]+)\b/i', $haystack, $m)) {
        return $m[1];
    }
    return null;
}

function safe_node_value(?DOMNode $n): string {
    return $n ? trim((string)$n->nodeValue) : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['arquivo_retorno'])) {

    $xmlFile = $_FILES['arquivo_retorno']['tmp_name'];
    $ext = strtolower(pathinfo($_FILES['arquivo_retorno']['name'], PATHINFO_EXTENSION));

    if ($ext !== 'xml') {
        $msg = "<div class='alert alert-danger'>Por favor, envie apenas o arquivo <b>.XML</b>.</div>";
    } else {

        $xmlContent = file_get_contents($xmlFile);
        if ($xmlContent === false || trim($xmlContent) === '') {
            $msg = "<div class='alert alert-danger'>Não foi possível ler o arquivo XML.</div>";
        } else {

            $dom = new DOMDocument();
            $dom->preserveWhiteSpace = false;

            if (!@$dom->loadXML($xmlContent)) {
                $msg = "<div class='alert alert-danger'>XML inválido.</div>";
            } else {

                // Suporta 2 layouts:
// 1) Nacional: <NFSe><infNFSe>...
// 2) ABRASF: <CompNfse><InfNfse>...
$notasNacional = $dom->getElementsByTagName('NFSe');
$notasAbrasf   = $dom->getElementsByTagName('CompNfse');

$countSucesso = 0;

if ($notasNacional->length === 0 && $notasAbrasf->length === 0) {
    $msg = "<div class='alert alert-warning'>Nenhuma nota encontrada. Este importador aceita XML Nacional (<b>NFSe</b>) ou ABRASF (<b>CompNfse</b>).</div>";
} else {

    // -------------------------
    // LAYOUT NACIONAL (NFSe)
    // -------------------------
    if ($notasNacional->length > 0) {
        foreach ($notasNacional as $nfse) {
            $inf = $nfse->getElementsByTagName('infNFSe')->item(0);
            if (!$inf) {
                $relatorio[] = ["status" => "warning", "msg" => "NFSe sem infNFSe encontrada. Ignorada."];
                continue;
            }

            $numeroNota = safe_node_value($inf->getElementsByTagName('nNF')->item(0));
            $codVerificacao = safe_node_value($inf->getElementsByTagName('cNFSe')->item(0));
            $dataEmissao = safe_node_value($inf->getElementsByTagName('dhEmi')->item(0));

            $descServico = safe_node_value($inf->getElementsByTagName('xDescServ')->item(0));

            $faturaIdFromXml = $descServico ? extract_between($descServico, 'FATURA_ID') : null;
            $tenantIdFromXml = $descServico ? extract_between($descServico, 'TENANT_ID') : null;

            $fatura = null;

            // 1) Pelo FATURA_ID (melhor)
            if (!empty($faturaIdFromXml)) {
                $stmt = $pdo->prepare("
                    SELECT id, tenant_id
                    FROM faturas
                    WHERE id = ?
                      AND status = 'pago'
                      AND (nota_numero IS NULL OR nota_numero = '')
                    LIMIT 1
                ");
                $stmt->execute([(int)$faturaIdFromXml]);
                $fatura = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            // 2) Pelo TENANT_ID (fallback)
            if (!$fatura && !empty($tenantIdFromXml)) {
                $stmt = $pdo->prepare("
                    SELECT id, tenant_id
                    FROM faturas
                    WHERE tenant_id = ?
                      AND status = 'pago'
                      AND (nota_numero IS NULL OR nota_numero = '')
                    ORDER BY data_pagamento DESC, id DESC
                    LIMIT 1
                ");
                $stmt->execute([(int)$tenantIdFromXml]);
                $fatura = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            if ($fatura) {
                $xmlIndividual = $dom->saveXML($nfse);

                $sqlUp = "UPDATE faturas SET
                            nota_numero = ?,
                            nota_codigo_verificacao = ?,
                            nota_xml = ?,
                            data_emissao_nota = ?
                          WHERE id = ?";

                $pdo->prepare($sqlUp)->execute([
                    $numeroNota,
                    $codVerificacao,
                    $xmlIndividual,
                    $dataEmissao ?: null,
                    (int)$fatura['id']
                ]);

                $relatorio[] = ["status" => "success", "msg" => "NFSe <b>{$numeroNota}</b> vinculada à fatura <b>#{$fatura['id']}</b> (tenant {$fatura['tenant_id']})."];
                $countSucesso++;
            } else {
                $relatorio[] = ["status" => "warning", "msg" => "NFSe <b>{$numeroNota}</b>: não encontrei fatura correspondente (adicione <b>FATURA_ID=</b> em xDescServ)."];
            }
        }
    }

    // -------------------------
    // LAYOUT ABRASF (CompNfse)
    // -------------------------
    if ($notasAbrasf->length > 0) {
        foreach ($notasAbrasf as $nota) {
            $infNfse = $nota->getElementsByTagName('InfNfse')->item(0);
            if (!$infNfse) {
                $relatorio[] = ["status" => "warning", "msg" => "CompNfse sem InfNfse encontrada. Ignorada."];
                continue;
            }

            $numeroNota = safe_node_value($infNfse->getElementsByTagName('Numero')->item(0));
            $codVerificacao = safe_node_value($infNfse->getElementsByTagName('CodigoVerificacao')->item(0));
            $dataEmissao = safe_node_value($infNfse->getElementsByTagName('DataEmissao')->item(0));

            $descServico = '';
            $discriminacao = $infNfse->getElementsByTagName('Discriminacao')->item(0);
            if ($discriminacao) $descServico = safe_node_value($discriminacao);

            $faturaIdFromXml = $descServico ? extract_between($descServico, 'FATURA_ID') : null;
            $tenantIdFromXml = $descServico ? extract_between($descServico, 'TENANT_ID') : null;

            $docTomador = '';
            $tomador = $infNfse->getElementsByTagName('Tomador')->item(0);
            if ($tomador) {
                $cpfCnpjNode = $tomador->getElementsByTagName('CpfCnpj')->item(0);
                if ($cpfCnpjNode) {
                    if ($cpfCnpjNode->getElementsByTagName('Cnpj')->length > 0) {
                        $docTomador = safe_node_value($cpfCnpjNode->getElementsByTagName('Cnpj')->item(0));
                    } elseif ($cpfCnpjNode->getElementsByTagName('Cpf')->length > 0) {
                        $docTomador = safe_node_value($cpfCnpjNode->getElementsByTagName('Cpf')->item(0));
                    }
                }
            }

            $fatura = null;

            if (!empty($faturaIdFromXml)) {
                $stmt = $pdo->prepare("
                    SELECT id, tenant_id
                    FROM faturas
                    WHERE id = ?
                      AND status = 'pago'
                      AND (nota_numero IS NULL OR nota_numero = '')
                    LIMIT 1
                ");
                $stmt->execute([(int)$faturaIdFromXml]);
                $fatura = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            if (!$fatura && !empty($tenantIdFromXml)) {
                $stmt = $pdo->prepare("
                    SELECT id, tenant_id
                    FROM faturas
                    WHERE tenant_id = ?
                      AND status = 'pago'
                      AND (nota_numero IS NULL OR nota_numero = '')
                    ORDER BY data_pagamento DESC, id DESC
                    LIMIT 1
                ");
                $stmt->execute([(int)$tenantIdFromXml]);
                $fatura = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            // Fallback por documento (se existir doc no tenants)
            if (!$fatura && !empty($docTomador)) {
                $stmt = $pdo->prepare("
                    SELECT f.id, f.tenant_id
                    FROM faturas f
                    JOIN tenants t ON t.id = f.tenant_id
                    WHERE REPLACE(REPLACE(REPLACE(t.doc_numero,'.',''),'-',''),'/','') = REPLACE(REPLACE(REPLACE(?,'.',''),'-',''),'/','')
                      AND f.status = 'pago'
                      AND (f.nota_numero IS NULL OR f.nota_numero = '')
                    ORDER BY f.data_pagamento DESC, f.id DESC
                    LIMIT 1
                ");
                $stmt->execute([$docTomador]);
                $fatura = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            if ($fatura) {
                $xmlIndividual = $dom->saveXML($nota);

                $sqlUp = "UPDATE faturas SET
                            nota_numero = ?,
                            nota_codigo_verificacao = ?,
                            nota_xml = ?,
                            data_emissao_nota = ?
                          WHERE id = ?";

                $pdo->prepare($sqlUp)->execute([
                    $numeroNota,
                    $codVerificacao,
                    $xmlIndividual,
                    $dataEmissao ?: null,
                    (int)$fatura['id']
                ]);

                $relatorio[] = ["status" => "success", "msg" => "Nota <b>{$numeroNota}</b> vinculada à fatura <b>#{$fatura['id']}</b> (tenant {$fatura['tenant_id']})."];
                $countSucesso++;
            } else {
                $relatorio[] = ["status" => "warning", "msg" => "Nota <b>{$numeroNota}</b>: não encontrei fatura correspondente (adicione <b>FATURA_ID=</b> na descrição do serviço)."];
            }
        }
    }

    $msg = "<div class='alert alert-success'>Processamento concluído! <b>{$countSucesso}</b> notas vinculadas.</div>";
}
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Retorno NFS-e (Nacional)</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-light">
  <div class="container py-4" style="max-width: 980px;">
    <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
      <div>
        <h4 class="fw-bold mb-0"><i class="fa-solid fa-file-invoice text-primary me-2"></i>Importar Retorno NFS-e</h4>
        <div class="text-muted small">Aceita XML Nacional (NFSe/infNFSe) e também ABRASF (CompNfse/InfNfse).</div>
      </div>
      <div class="d-flex gap-2">
        <a class="btn btn-outline-dark" href="master_painel.php"><i class="fa-solid fa-arrow-left me-2"></i>Retorno</a>
        <a class="btn btn-primary" href="master_exportar_lote.php"><i class="fa-solid fa-download me-2"></i>Baixar Lote</a>
      </div>
    </div>

    <?= $msg ?>

    <div class="card shadow-sm border-0">
      <div class="card-body p-4 p-md-5">
        <div class="row g-4 align-items-center">
          <div class="col-md-7">
            <h5 class="fw-bold mb-1">Enviar XML de retorno</h5>
            <p class="text-muted mb-0">
              Baixe o retorno no Ambiente Nacional e envie aqui para o sistema vincular automaticamente as notas nas faturas.
              <br><span class="small">Dica: o melhor é o seu lote conter <b>FATURA_ID</b> em <b>xDescServ</b>.</span>
            </p>
          </div>
          <div class="col-md-5">
            <form method="POST" enctype="multipart/form-data" class="d-grid gap-2">
              <input type="file" name="arquivo_retorno" class="form-control form-control-lg" accept=".xml" required>
              <button type="submit" class="btn btn-success btn-lg fw-bold">
                <i class="fa-solid fa-gears me-2"></i>Processar Retorno
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>

    <?php if (!empty($relatorio)): ?>
      <div class="card shadow-sm border-0 mt-4">
        <div class="card-header bg-white fw-bold">Relatório</div>
        <ul class="list-group list-group-flush">
          <?php foreach ($relatorio as $item): ?>
            <li class="list-group-item d-flex align-items-start gap-3">
              <?php if(($item['status'] ?? '') === 'success'): ?>
                <i class="fa-solid fa-circle-check text-success mt-1"></i>
              <?php else: ?>
                <i class="fa-solid fa-triangle-exclamation text-warning mt-1"></i>
              <?php endif; ?>
              <div><?= $item['msg'] ?></div>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
