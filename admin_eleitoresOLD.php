<?php
// admin_eleitores.php
// CRM de Apoio - Eleitores
// Versão com Espectro Político, Gênero, Busca Rápida por CEP, Relatório PDF, Exportação Excel e Delegação em Massa para Atendimento 🎧

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
set_time_limit(0);
ini_set('memory_limit', '512M');

require 'config.php';
require 'protecao.php';

// --- 0. SEGURANÇA SAAS ---
if (!defined('TENANT_ID')) {
    die("Erro Crítico: Tenant não identificado.");
}

if (function_exists('tem_acesso') && !tem_acesso('eleitores') && !tem_acesso('master')) {
    die("Acesso Negado.");
}

// =========================================================================
// DELEGAÇÃO EM MASSA (Envia para o Kanban de Atendimento)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'delegar_massa') {
    $ids = explode(',', $_POST['ids_selecionados']);
    $atendente_id = (int)$_POST['atendente_id'];
    
    foreach ($ids as $id) {
        $id_int = (int)trim($id);
        if ($id_int > 0) {
            $stmt = $pdo->prepare("UPDATE eleitores SET atendente_id = ?, fase_funil = 'entrada' WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$atendente_id, $id_int, TENANT_ID]);
        }
    }
    header("Location: admin_eleitores.php?msg=delegacao_sucesso");
    exit;
}

// --- FUNÇÕES AUXILIARES ---
function h($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function limparTelefone($valor) {
    return preg_replace('/[^0-9]/', '', (string)$valor);
}

function normalizarTexto($valor) {
    $valor = trim((string)$valor);
    $valor = preg_replace('/\s+/', ' ', $valor);
    return $valor;
}

function converterDataParaSQL($valor) {
    $valor = trim((string)$valor);
    if ($valor === '') return null;

    if (is_numeric($valor) && (int)$valor > 20000 && (int)$valor < 80000) {
        $timestamp = ((int)$valor - 25569) * 86400;
        return gmdate('Y-m-d', $timestamp);
    }

    if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})$/', $valor, $m)) {
        $dia = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        $mes = str_pad($m[2], 2, '0', STR_PAD_LEFT);
        $ano = $m[3];
        if (strlen($ano) == 2) $ano = '20' . $ano;
        if (checkdate((int)$mes, (int)$dia, (int)$ano)) {
            return "$ano-$mes-$dia";
        }
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
        return $valor;
    }

    return null;
}

function detectarDelimitadorCSV($arquivo) {
    $linha = fgets($arquivo);
    rewind($arquivo);
    $delimitadores = [';' => 0, ',' => 0, "\t" => 0];
    foreach ($delimitadores as $delim => $qtd) {
        $delimitadores[$delim] = substr_count((string)$linha, $delim);
    }
    arsort($delimitadores);
    return key($delimitadores) ?: ';';
}

function normalizarEncodingCSV($valor) {
    if ($valor === null) return '';
    $valor = (string)$valor;
    $valor = preg_replace('/^\xEF\xBB\xBF/', '', $valor);
    if (!mb_check_encoding($valor, 'UTF-8')) {
        $valor = mb_convert_encoding($valor, 'UTF-8', 'ISO-8859-1, Windows-1252, UTF-8');
    }
    return trim($valor);
}

function lerLinhasCSV($tmpName) {
    $linhas = [];
    $file = fopen($tmpName, 'r');
    if (!$file) return $linhas;
    $delimitador = detectarDelimitadorCSV($file);
    fgetcsv($file, 0, $delimitador);
    while (($data = fgetcsv($file, 0, $delimitador)) !== false) {
        if (!is_array($data)) continue;
        $linha = [];
        foreach ($data as $campo) {
            $linha[] = normalizarEncodingCSV($campo);
        }
        $linhas[] = $linha;
    }
    fclose($file);
    return $linhas;
}

function colunaExcelParaNumero($coluna) {
    $coluna = strtoupper($coluna);
    $numero = 0;
    for ($i = 0; $i < strlen($coluna); $i++) {
        $numero = $numero * 26 + (ord($coluna[$i]) - 64);
    }
    return $numero;
}

function lerSharedStringsXLSX($zip) {
    $strings = [];
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml === false) return $strings;
    $sx = @simplexml_load_string($xml);
    if (!$sx) return $strings;
    foreach ($sx->si as $si) {
        $texto = '';
        if (isset($si->t)) { $texto = (string)$si->t; } 
        elseif (isset($si->r)) { foreach ($si->r as $r) { $texto .= (string)$r->t; } }
        $strings[] = $texto;
    }
    return $strings;
}

function lerLinhasXLSX($tmpName) {
    $linhas = [];
    if (!class_exists('ZipArchive')) throw new Exception("Seu PHP não tem ZipArchive ativo. Envie a planilha em CSV UTF-8 ou habilite a extensão ZipArchive.");
    
    $zip = new ZipArchive();
    if ($zip->open($tmpName) !== true) throw new Exception("Não foi possível abrir o arquivo XLSX.");
    
    $sharedStrings = lerSharedStringsXLSX($zip);
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) { $zip->close(); throw new Exception("Não encontrei a primeira aba da planilha XLSX."); }
    
    $sx = @simplexml_load_string($sheetXml);
    if (!$sx) { $zip->close(); throw new Exception("Não foi possível lire a estrutura da planilha XLSX."); }
    
    $primeiraLinha = true;
    foreach ($sx->sheetData->row as $row) {
        if ($primeiraLinha) { $primeiraLinha = false; continue; }
        $linha = array_fill(0, 7, '');
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            preg_match('/([A-Z]+)/', $ref, $m);
            $indice = isset($m[1]) ? colunaExcelParaNumero($m[1]) - 1 : 0;
            if ($indice < 0 || $indice > 50) continue;
            
            $tipo = (string)$c['t'];
            $valor = '';
            if ($tipo === 's') {
                $idx = (int)$c->v;
                $valor = $sharedStrings[$idx] ?? '';
            } elseif ($tipo === 'inlineStr') {
                $valor = (string)$c->is->t;
            } else {
                $valor = isset($c->v) ? (string)$c->v : '';
            }
            $linha[$indice] = trim($valor);
        }
        $temConteudo = false;
        foreach ($linha as $campo) { if (trim((string)$campo) !== '') { $temConteudo = true; break; } }
        if ($temConteudo) { $linhas[] = $linha; }
    }
    $zip->close();
    return $linhas;
}

// --- 1. BUSCAR CONFIGURAÇÕES E LIMITES DO PLANO ---
$sqlPlano = "SELECT p.limite_eleitores FROM tenants t JOIN planos p ON t.plano = p.slug WHERE t.id = ?";
$stmtPlano = $pdo->prepare($sqlPlano);
$stmtPlano->execute([TENANT_ID]);
$limite_plano = (int)$stmtPlano->fetchColumn();

$sqlTotal = "SELECT COUNT(*) FROM eleitores WHERE tenant_id = ?";
$stmtTotal = $pdo->prepare($sqlTotal);
$stmtTotal->execute([TENANT_ID]);
$total_atual = (int)$stmtTotal->fetchColumn();

$stmt_lideres = $pdo->prepare("SELECT id, nome FROM eleitores WHERE tenant_id = ? AND is_lider = 1 ORDER BY nome ASC");
$stmt_lideres->execute([TENANT_ID]);
$lista_lideres = $stmt_lideres->fetchAll(PDO::FETCH_ASSOC);

$msg = "";
$msg_debug = "";

if (isset($_GET['msg']) && $_GET['msg'] === 'delegacao_sucesso') {
    $msg = "<div class='alert alert-success shadow-sm fw-bold'><i class='fas fa-check-circle me-2'></i> Eleitores delegados para o Funil de Atendimento com sucesso!</div>";
}

// --- 2. IMPORTAÇÃO DE PLANILHA CSV/XLSX ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'importar_csv') {
    if (isset($_FILES['arquivo_csv']) && $_FILES['arquivo_csv']['error'] == 0) {

        $tmpName = $_FILES['arquivo_csv']['tmp_name'];
        $nomeArquivo = $_FILES['arquivo_csv']['name'] ?? '';
        $extensao = strtolower(pathinfo($nomeArquivo, PATHINFO_EXTENSION));

        $sucesso = 0; $erros = 0; $duplicados = 0; $lidas = 0;

        try {
            if ($extensao === 'xlsx') { $linhas = lerLinhasXLSX($tmpName); } 
            else { $linhas = lerLinhasCSV($tmpName); }

            if (empty($linhas)) throw new Exception("Nenhuma linha válida foi encontrada no arquivo.");

            $pdo->beginTransaction();

            $sqlInsert = "INSERT INTO eleitores (tenant_id, nome, email, whatsapp, nascimento, cidade, tags, status_voto) VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
            $stmtInsert = $pdo->prepare($sqlInsert);

            $sqlDupZap = "SELECT id FROM eleitores WHERE tenant_id = ? AND whatsapp = ? AND whatsapp != '' LIMIT 1";
            $stmtDupZap = $pdo->prepare($sqlDupZap);

            $sqlDupEmail = "SELECT id FROM eleitores WHERE tenant_id = ? AND email = ? AND email != '' LIMIT 1";
            $stmtDupEmail = $pdo->prepare($sqlDupEmail);

            foreach ($linhas as $numeroLinha => $data) {
                $lidas++;

                if ($limite_plano > 0 && $total_atual >= $limite_plano) {
                    $msg = "<div class='alert alert-warning shadow-sm fw-bold'><i class='fas fa-exclamation-triangle'></i> Importação interrompida! Limite do plano atingido. $sucesso contatos importados. $duplicados duplicados ignorados. $erros registros com erro.</div>";
                    break;
                }

                $nome = normalizarTexto($data[0] ?? '');
                $email = normalizarTexto($data[1] ?? '');
                $nascimento_br = normalizarTexto($data[2] ?? '');

                $telefone_cru = normalizarTexto($data[4] ?? '');
                if ($telefone_cru === '') { $telefone_cru = normalizarTexto($data[3] ?? ''); }

                $whatsapp = limparTelefone($telefone_cru);
                $cidade = normalizarTexto($data[5] ?? '');
                $tags = normalizarTexto($data[6] ?? '');

                if ($nome === '') {
                    $erros++;
                    if ($erros <= 20) { $msg_debug .= "<br>Linha " . ($numeroLinha + 2) . ": sem nome."; }
                    continue;
                }

                $nascimento_sql = converterDataParaSQL($nascimento_br);

                if ($whatsapp !== '') {
                    $stmtDupZap->execute([TENANT_ID, $whatsapp]);
                    if ($stmtDupZap->fetchColumn()) { $duplicados++; continue; }
                }

                if ($email !== '') {
                    $stmtDupEmail->execute([TENANT_ID, $email]);
                    if ($stmtDupEmail->fetchColumn()) { $duplicados++; continue; }
                }

                try {
                    $stmtInsert->execute([TENANT_ID, $nome, $email, $whatsapp, $nascimento_sql, $cidade, $tags]);
                    $sucesso++;
                    $total_atual++;
                } catch (Exception $e) {
                    $erros++;
                    if ($erros <= 20) { $msg_debug .= "<br>Linha " . ($numeroLinha + 2) . " ($nome): " . h($e->getMessage()); }
                }

                if ($sucesso > 0 && $sucesso % 500 == 0) {
                    $pdo->commit();
                    $pdo->beginTransaction();
                }
            }

            if ($pdo->inTransaction()) { $pdo->commit(); }

            if (empty($msg)) {
                $msg = "<div class='alert alert-success shadow-sm fw-bold'>
                            <i class='fas fa-check-circle'></i> Importação concluída! Linhas lidas: $lidas | Inseridos: $sucesso | Duplicados: $duplicados | Erros: $erros.
                        </div>";
                if ($erros > 0) {
                    $msg .= "<div class='alert alert-danger shadow-sm mt-2'><b>Alguns registros falharam.</b> Exibindo até 20 erros: $msg_debug</div>";
                }
            }

        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $msg = "<div class='alert alert-danger shadow-sm fw-bold'><i class='fas fa-times-circle'></i> Erro na importação: " . h($e->getMessage()) . "</div>";
        }

    } else {
        $msg = "<div class='alert alert-danger'>Erro ao ler o arquivo. Envie um arquivo CSV ou XLSX válido.</div>";
    }
}

// --- 3. SALVAR / EDITAR INDIVIDUAL ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'salvar') {
    try {
        if (empty($_POST['id']) && $limite_plano > 0 && $total_atual >= $limite_plano) {
            $msg = "<div class='alert alert-danger shadow p-3 mb-4 text-center'>
                        <h5 class='fw-bold'><i class='fas fa-lock'></i> Limite Atingido!</h5>
                        <p class='mb-2'>Seu plano permite apenas <b>{$limite_plano}</b> cadastros.</p>
                        <a href='admin_financeiro.php' class='btn btn-success fw-bold rounded-pill btn-sm'>Fazer Upgrade Agora</a>
                    </div>";
        } else {
            $zap_limpo = limparTelefone($_POST['whatsapp'] ?? '');
            $nascimento = !empty($_POST['nascimento']) ? $_POST['nascimento'] : null;
            $responsavel_id = !empty($_POST['responsavel_id']) ? (int)$_POST['responsavel_id'] : 0;
            $status_voto = !empty($_POST['status_voto']) ? (int)$_POST['status_voto'] : 1;
            $lat = !empty($_POST['latitude']) ? $_POST['latitude'] : null;
            $lng = !empty($_POST['longitude']) ? $_POST['longitude'] : null;
            
            $sexo = !empty($_POST['sexo']) ? $_POST['sexo'] : 'Não Informado';
            $espectro = !empty($_POST['espectro_politico']) ? $_POST['espectro_politico'] : 'Não Informado';

            if (empty($_POST['id'])) {
                $sql = "INSERT INTO eleitores
                    (tenant_id, nome, whatsapp, email, nascimento, sexo, bairro, rua, numero, city, zona, secao, responsavel_id, status_voto, latitude, longitude, tags, observacoes, espectro_politico)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
                $pdo->prepare($sql)->execute([
                    TENANT_ID,
                    $_POST['nome'] ?? '',
                    $zap_limpo,
                    $_POST['email'] ?? '',
                    $nascimento,
                    $sexo,
                    $_POST['bairro'] ?? '',
                    $_POST['rua'] ?? '',
                    $_POST['numero'] ?? '',
                    $_POST['cidade'] ?? '',
                    $_POST['zona'] ?? '',
                    $_POST['secao'] ?? '',
                    $responsavel_id,
                    $status_voto,
                    $lat,
                    $lng,
                    $_POST['tags'] ?? '',
                    $_POST['obs'] ?? '',
                    $espectro
                ]);
                $msg = "<div class='alert alert-success shadow-sm'>Eleitor cadastrado com sucesso!</div>";
                $total_atual++;
            } else {
                $sql = "UPDATE eleitores SET
                    nome=?, whatsapp=?, email=?, nascimento=?, sexo=?, bairro=?, rua=?, numero=?, cidade=?, zona=?, secao=?,
                    responsavel_id=?, status_voto=?, latitude=?, longitude=?, tags=?, observacoes=?, espectro_politico=?
                    WHERE id=? AND tenant_id=?";
                $pdo->prepare($sql)->execute([
                    $_POST['nome'] ?? '',
                    $zap_limpo,
                    $_POST['email'] ?? '',
                    $nascimento,
                    $sexo,
                    $_POST['bairro'] ?? '',
                    $_POST['rua'] ?? '',
                    $_POST['numero'] ?? '',
                    $_POST['cidade'] ?? '',
                    $_POST['zona'] ?? '',
                    $_POST['secao'] ?? '',
                    $responsavel_id,
                    $status_voto,
                    $lat,
                    $lng,
                    $_POST['tags'] ?? '',
                    $_POST['obs'] ?? '',
                    $espectro,
                    $_POST['id'],
                    TENANT_ID
                ]);
                $msg = "<div class='alert alert-success shadow-sm'>Dados atualizados com sucesso!</div>";
            }
        }
    } catch (PDOException $e) {
        $msg = "<div class='alert alert-danger'>Erro no Banco: " . h($e->getMessage()) . "</div>";
    }
}

// --- 4. EXCLUIR ---
if (isset($_GET['deletar'])) {
    $id = (int)$_GET['deletar'];
    $pdo->prepare("DELETE FROM eleitores WHERE id = ? AND tenant_id = ?")->execute([$id, TENANT_ID]);
    header("Location: admin_eleitores.php");
    exit;
}

$stmtTotal = $pdo->prepare($sqlTotal);
$stmtTotal->execute([TENANT_ID]);
$total_atual = (int)$stmtTotal->fetchColumn();

// --- 5. BUSCA E FILTROS ---
$where = "e.tenant_id = " . (int)TENANT_ID;
$params = [];

if (!empty($_GET['busca'])) {
    $termo = "%" . $_GET['busca'] . "%";
    $where .= " AND (e.nome LIKE ? OR e.whatsapp LIKE ? OR e.email LIKE ?)";
    $params[] = $termo; $params[] = $termo; $params[] = $termo;
}
if (!empty($_GET['bairro'])) { $where .= " AND e.bairro LIKE ?"; $params[] = "%" . $_GET['bairro'] . "%"; }
if (!empty($_GET['zona'])) { $where .= " AND e.zona = ?"; $params[] = $_GET['zona']; }
if (!empty($_GET['secao'])) { $where .= " AND e.secao = ?"; $params[] = $_GET['secao']; }
if (!empty($_GET['filtro_lider'])) { $where .= " AND e.responsavel_id = ?"; $params[] = $_GET['filtro_lider']; }
if (!empty($_GET['filtro_tag'])) { $where .= " AND e.tags LIKE ?"; $params[] = "%" . $_GET['filtro_tag'] . "%"; }

// ==========================================================
// 🖨️ GERAÇÃO DE RELATÓRIO / PDF (Quando o botão é clicado)
// ==========================================================
if (isset($_GET['imprimir']) && $_GET['imprimir'] == 1) {
    $sql_relatorio = "SELECT e.*, l.nome as nome_lider 
                      FROM eleitores e 
                      LEFT JOIN eleitores l ON e.responsavel_id = l.id 
                      WHERE $where 
                      ORDER BY e.nome ASC LIMIT 3000";
    $stmt_rel = $pdo->prepare($sql_relatorio);
    $stmt_rel->execute($params);
    $dados_relatorio = $stmt_rel->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <!DOCTYPE html>
    <html lang="pt-br">
    <head>
        <meta charset="UTF-8">
        <title>Relatório da Base Eleitoral</title>
        <style>
            body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 11px; color: #333; margin: 0; padding: 20px; }
            table { width: 100%; border-collapse: collapse; margin-top: 15px; page-break-inside: auto; }
            tr { page-break-inside: avoid; page-break-after: auto; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #f4f4f4; font-weight: bold; text-transform: uppercase; font-size: 10px; }
            .cabecalho { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #333; padding-bottom: 10px; }
            .cabecalho h2 { margin: 0 0 5px 0; font-size: 20px; }
            .cabecalho p { margin: 0; color: #666; }
            .badge-status { padding: 3px 6px; border-radius: 4px; font-weight: bold; font-size: 9px; text-transform: uppercase; }
            .s1 { background: #fdf2cd; color: #856404; }
            .s2 { background: #cff4fc; color: #055160; }
            .s3 { background: #d1e7dd; color: #0f5132; }
            @media print {
                .no-print { display: none !important; }
                body { padding: 0; }
            }
            .btn-print { background: #0d6efd; color: white; border: none; padding: 10px 20px; border-radius: 50px; cursor: pointer; font-size: 14px; font-weight: bold; }
            .btn-close { background: #6c757d; color: white; border: none; padding: 10px 20px; border-radius: 50px; cursor: pointer; font-size: 14px; font-weight: bold; margin-left: 10px; }
        </style>
    </head>
    <body onload="window.print()">
        <div class="no-print" style="margin-bottom:20px; text-align:center;">
            <button onclick="window.print()" class="btn-print">🖨️ Imprimir / Salvar como PDF</button>
            <button onclick="window.close()" class="btn-close">Fechar Aba</button>
        </div>
        <div class="cabecalho">
            <h2>Relatório da Base Eleitoral</h2>
            <p>Gerado em: <?= date('d/m/Y H:i') ?> | Total listado: <?= count($dados_relatorio) ?> eleitor(es)</p>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Nome do Eleitor</th>
                    <th>WhatsApp / Tel</th>
                    <th>Bairro / Localidade</th>
                    <th>Liderança / Indicação</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($dados_relatorio as $r): 
                    $status_class = "s1"; $status_text = "Indeciso / Frio";
                    if($r['status_voto'] == 2) { $status_class = "s2"; $status_text = "Apoio Provável"; }
                    if($r['status_voto'] == 3) { $status_class = "s3"; $status_text = "Voto Certo"; }
                ?>
                <tr>
                    <td><strong><?= h($r['nome']) ?></strong></td>
                    <td><?= h($r['whatsapp']) ?: '-' ?></td>
                    <td><?= h($r['bairro']) ?: '-' ?></td>
                    <td><?= h($r['nome_lider'] ?: 'Voto Direto') ?></td>
                    <td><span class="badge-status <?= $status_class ?>"><?= $status_text ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($dados_relatorio)): ?>
                <tr><td colspan="5" style="text-align:center; padding: 20px;">Nenhum eleitor encontrado com estes filtros.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    exit;
}

// ==========================================================
// 📊 EXPORTAÇÃO EXCEL (CSV Formato BR)
// ==========================================================
if (isset($_GET['exportar_excel']) && $_GET['exportar_excel'] == 1) {
    $sql_export = "SELECT e.*, l.nome as nome_lider 
                   FROM eleitores e 
                   LEFT JOIN eleitores l ON e.responsavel_id = l.id 
                   WHERE $where 
                   ORDER BY e.nome ASC LIMIT 10000";
    
    $stmt_export = $pdo->prepare($sql_export);
    $stmt_export->execute($params);
    $dados_export = $stmt_export->fetchAll(PDO::FETCH_ASSOC);

    $nome_arquivo = "eleitores_crm_" . date('Ymd_Hi') . ".csv";
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $nome_arquivo);
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // Adiciona o BOM do UTF-8
    
    fputcsv($output, ['Nome', 'WhatsApp', 'Email', 'Nascimento', 'Sexo', 'Bairro', 'Cidade', 'Liderança', 'Termômetro', 'Espectro', 'Tags', 'Zona', 'Seção'], ';');
    
    foreach ($dados_export as $r) {
        $status_text = "Indeciso / Frio";
        if($r['status_voto'] == 2) { $status_text = "Apoio Provável"; }
        if($r['status_voto'] == 3) { $status_text = "Voto Certo"; }
        
        fputcsv($output, [
            $r['nome'],
            $r['whatsapp'],
            $r['email'],
            $r['nascimento'],
            $r['sexo'],
            $r['bairro'],
            $r['cidade'],
            $r['nome_lider'] ? $r['nome_lider'] : 'Voto Direto',
            $status_text,
            $r['espectro_politico'],
            $r['tags'],
            $r['zona'],
            $r['secao']
        ], ';');
    }
    
    fclose($output);
    exit;
}

// Lógica de tags e paginação regular do sistema
$stmt_tags = $pdo->prepare("SELECT tags FROM eleitores WHERE tenant_id = ? AND tags IS NOT NULL AND tags != ''");
$stmt_tags->execute([TENANT_ID]);
$todas_tags_db = $stmt_tags->fetchAll(PDO::FETCH_COLUMN);

$lista_tags_unicas = [];
foreach ($todas_tags_db as $string_tag) {
    $separadas = explode(',', $string_tag);
    foreach ($separadas as $t) {
        $t_clean = trim($t);
        if (!empty($t_clean) && !in_array($t_clean, $lista_tags_unicas)) { $lista_tags_unicas[] = $t_clean; }
    }
}
sort($lista_tags_unicas);

try {
    $pagina = max(1, (int)($_GET['pagina'] ?? 1));
    $por_pagina = (int)($_GET['por_pagina'] ?? 50);
    $opcoes_por_pagina = [25, 50, 100, 200];
    if (!in_array($por_pagina, $opcoes_por_pagina, true)) { $por_pagina = 50; }
    $offset = ($pagina - 1) * $por_pagina;

    $sql_total_lista = "SELECT COUNT(*) FROM eleitores e WHERE " . $where;
    $stmt_total_lista = $pdo->prepare($sql_total_lista);
    $stmt_total_lista->execute($params);
    $total_registros = (int)$stmt_total_lista->fetchColumn();
    $total_paginas = max(1, (int)ceil($total_registros / $por_pagina));

    if ($pagina > $total_paginas && $total_paginas > 0) {
        $pagina = $total_paginas;
        $offset = ($pagina - 1) * $por_pagina;
    }

    $sql = "SELECT e.*, l.nome as nome_lider
            FROM eleitores e
            LEFT JOIN eleitores l ON e.responsavel_id = l.id
            WHERE $where
            ORDER BY e.nome ASC
            LIMIT " . (int)$offset . ", " . (int)$por_pagina;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $lista = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $inicio_exibindo = ($total_registros > 0) ? ($offset + 1) : 0;
    $fim_exibindo = min($offset + $por_pagina, $total_registros);

    $mes_atual = date('m');
    $stmt_niver = $pdo->prepare("SELECT COUNT(*) FROM eleitores WHERE tenant_id = ? AND MONTH(nascimento) = ?");
    $stmt_niver->execute([TENANT_ID, $mes_atual]);
    $aniversariantes = (int)$stmt_niver->fetchColumn();

} catch (Exception $e) { die("Erro ao carregar dados: " . h($e->getMessage())); }

$porcentagem_uso = ($limite_plano > 0) ? min(100, round(($total_atual / $limite_plano) * 100)) : 0;
$cor_uso = ($porcentagem_uso >= 90) ? 'bg-danger' : 'bg-primary';
$texto_limite = ($limite_plano > 0) ? "$total_atual / $limite_plano" : "$total_atual (Ilimitado)";
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>CRM de Apoio - Eleitores</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f3f4f6; }
        .tag-badge { font-size: 0.7rem; background: #e9ecef; color: #495057; padding: 2px 8px; border-radius: 12px; margin-right: 3px; font-weight: 600; border: 1px solid #dee2e6; display: inline-block; margin-bottom: 3px; }
        .card-stats { border-left: 4px solid #0d6efd; transition: 0.3s; background: white; }
        .card-stats:hover { transform: translateY(-3px); box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
        .status-dot { height: 10px; width: 10px; border-radius: 50%; display: inline-block; margin-right: 5px; }
        .status-1 { background-color: #ffc107; }
        .status-2 { background-color: #0dcaf0; }
        .status-3 { background-color: #198754; }
    </style>
</head>
<body class="p-4">

<div class="container bg-white p-4 rounded-4 shadow-sm">

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 gap-3">
        <div>
            <h3 class="text-primary fw-bold mb-0"><i class="fas fa-address-book me-2"></i> Minha Base de Apoio</h3>
            <small class="text-muted">CRM Político e Gestão de Contatos</small>
        </div>

        <div class="d-flex flex-wrap gap-2 justify-content-end">
            <button class="btn btn-info text-white btn-sm fw-bold px-3 rounded-pill shadow-sm" onclick="delegarSelecionados()">
                <i class="fas fa-random me-1"></i> Delegar (Atendimento)
            </button>
            <a href="admin_painel.php" class="btn btn-outline-secondary btn-sm rounded-pill fw-bold px-3"><i class="fas fa-arrow-left me-1"></i> Voltar</a>
            <a href="admin_relatorios_crm.php" class="btn btn-warning btn-sm fw-bold px-3 rounded-pill shadow-sm text-dark">
                <i class="fas fa-chart-pie me-1"></i> Inteligência
            </a>
            <button type="button" class="btn btn-success btn-sm fw-bold px-3 rounded-pill shadow-sm" onclick="new bootstrap.Modal(document.getElementById('modalImportar')).show()">
                <i class="fas fa-file-excel me-1"></i> Importar Planilha
            </button>
            <button type="button" class="btn btn-primary btn-sm fw-bold px-3 rounded-pill shadow-sm" onclick="abrirModal()">
                <i class="fas fa-plus me-1"></i> Novo Eleitor
            </button>
        </div>
    </div>

    <?= $msg ?>

    <?php if($limite_plano > 0): ?>
    <div class="row justify-content-center mb-4">
        <div class="col-md-8">
            <div class="d-flex justify-content-between small fw-bold mb-1 text-muted">
                <span>Uso do Plano</span>
                <span><?= h($texto_limite) ?> eleitores</span>
            </div>
            <div class="progress" style="height: 8px;">
                <div class="progress-bar <?= h($cor_uso) ?>" style="width: <?= (int)$porcentagem_uso ?>%"></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row mb-4 g-3">
        <div class="col-md-6">
            <div class="card card-stats shadow-sm p-3">
                <h6 class="text-muted small fw-bold text-uppercase mb-1">Total de Eleitores</h6>
                <h3 class="mb-0 fw-bold"><?= (int)$total_atual ?></h3>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card card-stats shadow-sm p-3" style="border-left-color: #ffc107;">
                <h6 class="text-muted small fw-bold text-uppercase mb-1">Aniversariantes do Mês</h6>
                <h3 class="mb-0 fw-bold text-warning"><?= (int)$aniversariantes ?> 🎂</h3>
            </div>
        </div>
    </div>

    <form method="GET" id="formFiltro" class="row g-2 mb-4 bg-light p-3 rounded-3 border">
        <div class="col-md-3">
            <input type="text" name="busca" class="form-control form-control-sm" placeholder="Buscar por nome ou zap..." value="<?= h($_GET['busca'] ?? '') ?>">
        </div>
        <div class="col-md-2">
            <input type="text" name="bairro" class="form-control form-control-sm" placeholder="Bairro" value="<?= h($_GET['bairro'] ?? '') ?>">
        </div>
        <div class="col-md-2">
            <input type="number" name="zona" class="form-control form-control-sm" placeholder="Zona Eleitoral" value="<?= h($_GET['zona'] ?? '') ?>">
        </div>
        <div class="col-md-2">
            <input type="number" name="secao" class="form-control form-control-sm" placeholder="Seção" value="<?= h($_GET['secao'] ?? '') ?>">
        </div>
        <div class="col-md-3">
            <select name="filtro_lider" class="form-select form-select-sm">
                <option value="">-- Liderança --</option>
                <?php foreach($lista_lideres as $l): ?>
                    <option value="<?= (int)$l['id'] ?>" <?= (isset($_GET['filtro_lider']) && $_GET['filtro_lider'] == $l['id']) ? 'selected' : '' ?>><?= h($l['nome']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <select name="filtro_tag" class="form-select form-select-sm">
                <option value="">-- Grupos / Tags --</option>
                <?php foreach($lista_tags_unicas as $tg): ?>
                    <option value="<?= h($tg) ?>" <?= (isset($_GET['filtro_tag']) && $_GET['filtro_tag'] == $tg) ? 'selected' : '' ?>><?= h($tg) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select name="por_pagina" class="form-select form-select-sm" title="Registros por página">
                <?php foreach([25, 50, 100, 200] as $op): ?>
                    <option value="<?= $op ?>" <?= ($por_pagina == $op) ? 'selected' : '' ?>><?= $op ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="col-md-7 d-flex justify-content-end align-items-center gap-2">
             <button type="button" onclick="gerarRelatorio()" class="btn btn-sm btn-outline-danger fw-bold shadow-sm"><i class="fas fa-file-pdf me-1"></i> Relatório PDF</button>
             <button type="button" onclick="exportarExcel()" class="btn btn-sm btn-success fw-bold shadow-sm"><i class="fas fa-file-excel me-1"></i> Exportar Excel</button>
             <a href="admin_eleitores.php" class="btn btn-sm btn-outline-secondary fw-bold"><i class="fas fa-eraser me-1"></i> Limpar Filtros</a>
             <button type="submit" class="btn btn-dark btn-sm px-4 fw-bold"><i class="fas fa-search me-1"></i> Buscar</button>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-light small text-uppercase">
                <tr>
                    <th style="width: 40px;"><input type="checkbox" id="checkAll" onclick="toggleAll(this)"></th>
                    <th>Nome / Contato</th>
                    <th>Termômetro / Espectro</th>
                    <th>Liderança</th>
                    <th>Localização / Votação</th>
                    <th class="text-end">Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($lista as $row):
                $idade = !empty($row['nascimento']) ? date_diff(date_create($row['nascimento']), date_create('today'))->y . "a" : "";
                $zap_limpo = limparTelefone($row['whatsapp'] ?? '');
                $zap_link = $zap_limpo ? "https://wa.me/55" . $zap_limpo : "#";

                $status_label = "Indeciso";
                $status_class = "status-1";
                if (($row['status_voto'] ?? 1) == 2) { $status_label = "Apoio Provável"; $status_class = "status-2"; }
                if (($row['status_voto'] ?? 1) == 3) { $status_label = "Voto Certo"; $status_class = "status-3"; }
                
                $espectro = htmlspecialchars($row['espectro_politico'] ?? 'Não Informado');
            ?>
                <tr>
                    <td><input type="checkbox" class="check-eleitor" value="<?= (int)$row['id'] ?>"></td>
                    <td>
                        <strong class="text-dark"><?= h($row['nome']) ?></strong>
                        <?php if($idade): ?> <span class="text-muted small ms-1">• <?= h($idade) ?></span> <?php endif; ?>
                        <?php if(isset($row['atendente_id']) && $row['atendente_id'] > 0): ?>
                            <span class="badge bg-primary ms-1" style="font-size: 0.6rem;" title="Delegado para o Funil de Atendimento"><i class="fas fa-headset"></i> CRM</span>
                        <?php endif; ?>
                        <br>
                        <?php if($zap_limpo): ?>
                            <a href="<?= h($zap_link) ?>" target="_blank" class="text-success text-decoration-none small fw-bold">
                                <i class="fab fa-whatsapp"></i> <?= h($row['whatsapp']) ?>
                            </a>
                        <?php else: ?>
                            <span class="text-muted small">Sem WhatsApp</span>
                        <?php endif; ?>
                        <div class="mt-1">
                            <?php
                            $tags = explode(',', $row['tags'] ?? '');
                            foreach($tags as $t) {
                                if(trim($t)) echo "<span class='tag-badge'>" . h(trim($t)) . "</span>";
                            }
                            ?>
                        </div>
                    </td>
                    <td>
                        <span class="badge rounded-pill border text-dark bg-light fw-normal d-inline-block mb-1">
                            <span class="status-dot <?= h($status_class) ?>"></span> <?= h($status_label) ?>
                        </span><br>
                        <?php if($espectro !== 'Não Informado'): ?>
                            <span class="small fw-bold text-secondary border rounded px-1" style="font-size: 0.65rem; background:#f8f9fa;"><i class="fas fa-flag"></i> <?= $espectro ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if(!empty($row['nome_lider'])): ?>
                            <div class="d-flex align-items-center text-primary fw-bold small"><i class="fas fa-user-tie me-2"></i> <?= h($row['nome_lider']) ?></div>
                        <?php else: ?>
                            <span class="text-muted small fst-italic">Voto Direto</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="small fw-bold"><?= h($row['bairro'] ?? '') ?></div>
                        <small class="text-muted"><?= h($row['cidade'] ?? '') ?></small>
                        <?php if(!empty($row['zona']) || !empty($row['secao'])): ?>
                            <div class="mt-1">
                                <span class="badge bg-light text-dark border"><i class="fas fa-person-booth text-primary me-1"></i> Zona <?= h($row['zona'] ?? '--') ?> / Seç <?= h($row['secao'] ?? '--') ?></span>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-primary border-0" onclick='editar(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)'><i class="fas fa-pen"></i></button>
                        <a href="?deletar=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-danger border-0" onclick="return confirm('Apagar este eleitor?')"><i class="fas fa-trash"></i></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if(empty($lista)): ?>
                <tr>
                    <td colspan="6" class="text-center py-5 text-muted">
                        <i class="fas fa-folder-open fa-2x mb-3 d-block opacity-50"></i> Nenhum eleitor encontrado com estes filtros.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-2 mt-3">
        <div class="small text-muted">Exibindo <strong><?= $inicio_exibindo ?></strong> a <strong><?= $fim_exibindo ?></strong> de <strong><?= $total_registros ?></strong> registros encontrados.</div>
        <?php if($total_paginas > 1): ?>
            <nav aria-label="Paginação de eleitores">
                <ul class="pagination pagination-sm mb-0 shadow-sm">
                    <?php
                        $query_base = $_GET;
                        unset($query_base['pagina']);
                    ?>
                    <li class="page-item <?= ($pagina <= 1) ? 'disabled' : '' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($query_base, ['pagina' => 1])) ?>">Primeira</a></li>
                    <li class="page-item <?= ($pagina <= 1) ? 'disabled' : '' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($query_base, ['pagina' => max(1, $pagina - 1)])) ?>">‹</a></li>
                    <?php
                        $inicio_pag = max(1, $pagina - 2);
                        $fim_pag = min($total_paginas, $pagina + 2);
                        if ($inicio_pag > 1) { echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; }
                        for($i = $inicio_pag; $i <= $fim_pag; $i++):
                    ?>
                        <li class="page-item <?= ($i == $pagina) ? 'active' : '' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($query_base, ['pagina' => $i])) ?>"><?= $i ?></a></li>
                    <?php endfor; ?>
                    <?php if ($fim_pag < $total_paginas): ?> <li class="page-item disabled"><span class="page-link">...</span></li> <?php endif; ?>
                    <li class="page-item <?= ($pagina >= $total_paginas) ? 'disabled' : '' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($query_base, ['pagina' => min($total_paginas, $pagina + 1)])) ?>">›</a></li>
                    <li class="page-item <?= ($pagina >= $total_paginas) ? 'disabled' : '' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($query_base, ['pagina' => $total_paginas])) ?>">Última</a></li>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="modalDelegar" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content shadow-lg border-0">
            <input type="hidden" name="acao" value="delegar_massa">
            <input type="hidden" name="ids_selecionados" id="ids_selecionados">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-random me-2"></i> Delegar para Atendimento</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <label class="fw-bold small mb-2 text-dark">Escolha quem será o responsável por nutrir estes contatos:</label>
                <select name="atendente_id" class="form-select border-info" required>
                    <option value="">-- Selecione o membro da equipe --</option>
                    <?php 
                    $eq = $pdo->prepare("SELECT id, nome FROM usuarios WHERE tenant_id = ? ORDER BY nome ASC");
                    $eq->execute([TENANT_ID]);
                    foreach($eq->fetchAll() as $u) echo "<option value='{$u['id']}'>👤 {$u['nome']}</option>";
                    ?>
                </select>
                <div class="mt-3 text-muted small text-center">Os eleitores selecionados serão enviados para a coluna "Novos Leads" do CRM de Atendimento da pessoa escolhida.</div>
            </div>
            <div class="modal-footer border-0 bg-light rounded-bottom-4">
                <button type="button" class="btn btn-secondary rounded-pill px-4 fw-bold" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-info text-white rounded-pill px-4 fw-bold shadow-sm">Confirmar Delegação</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalEleitor" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-user-edit me-2"></i> Dados do Eleitor</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="formEleitor">
                <div class="modal-body bg-light">
                    <input type="hidden" name="acao" value="salvar">
                    <input type="hidden" name="id" id="id">

                    <div class="card p-3 border-0 shadow-sm mb-3">
                        <h6 class="text-primary fw-bold border-bottom pb-2 mb-3">Informações Pessoais</h6>
                        <div class="row g-3">
                            <div class="col-md-6"><label class="small fw-bold">Nome Completo *</label><input type="text" name="nome" id="nome" class="form-control" required></div>
                            <div class="col-md-3"><label class="small fw-bold">WhatsApp</label><input type="text" name="whatsapp" id="whatsapp" class="form-control" placeholder="(00) 00000-0000"></div>
                            <div class="col-md-3"><label class="small fw-bold">Nascimento</label><input type="date" name="nascimento" id="nascimento" class="form-control"></div>
                            <div class="col-md-8"><label class="small fw-bold">E-mail</label><input type="email" name="email" id="email" class="form-control"></div>
                            <div class="col-md-4">
                                <label class="small fw-bold">Gênero</label>
                                <select name="sexo" id="sexo" class="form-select">
                                    <option value="Não Informado">Não Informado</option>
                                    <option value="Masculino">Masculino</option>
                                    <option value="Feminino">Feminino</option>
                                    <option value="Outro">Outro</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="card p-3 border-0 shadow-sm mb-3">
                        <h6 class="text-primary fw-bold border-bottom pb-2 mb-3">Estratégia & Voto</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="small fw-bold text-success">Quem indicou? (Liderança)</label>
                                <select name="responsavel_id" id="responsavel_id" class="form-select">
                                    <option value="0">--- Sem indicação (Voto Direto) ---</option>
                                    <?php foreach($lista_lideres as $lid): ?>
                                        <option value="<?= (int)$lid['id'] ?>"><?= h($lid['nome']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="small fw-bold">Termômetro do Voto</label>
                                <select name="status_voto" id="status_voto" class="form-select fw-bold">
                                    <option value="1" class="text-warning">🟠 Indeciso / Frio</option>
                                    <option value="2" class="text-info">🔵 Apoio Provável / Morno</option>
                                    <option value="3" class="text-success">🟢 Voto Certo / Quente</option>
                                </select>
                            </div>
                            <div class="col-md-12">
                                <label class="small fw-bold text-primary">Posicionamento (Ideologia / Área de Interesse)</label>
                                <select name="espectro_politico" id="espectro_politico" class="form-select">
                                    <option value="Não Informado">Não Informado</option>
                                    <option value="Bolsonarista">Bolsonarista / Direita</option>
                                    <option value="Lulista">Lulista / Esquerda</option>
                                    <option value="Centro">Centro / Neutro</option>
                                    <option value="Anti-Política">Anti-Política (Não gosta de políticos)</option>
                                </select>
                            </div>
                            <div class="col-12 mt-3"><label class="small fw-bold">Tags (Interesses/Grupos)</label><input type="text" name="tags" id="tags" class="form-control" placeholder="Ex: Saúde, Professor, Futebol..."></div>
                        </div>
                    </div>

                    <div class="card p-3 border-0 shadow-sm">
                        <h6 class="text-muted fw-bold border-bottom pb-2 mb-3">Endereço & Zona</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="small fw-bold text-primary">Buscar CEP</label>
                                <div class="input-group">
                                    <input type="text" id="cep" class="form-control" placeholder="00000-000" onblur="buscarCEP(this.value)">
                                    <button class="btn btn-outline-primary" type="button" onclick="buscarCEP(document.getElementById('cep').value)"><i class="fas fa-search"></i></button>
                                </div>
                            </div>
                            <div class="col-md-6"><label class="small fw-bold">Rua</label><input type="text" name="rua" id="rua" class="form-control"></div>
                            <div class="col-md-2"><label class="small fw-bold">Nº</label><input type="text" name="numero" id="numero" class="form-control"></div>
                            <div class="col-md-5"><label class="small fw-bold">Bairro</label><input type="text" name="bairro" id="bairro" class="form-control"></div>
                            <div class="col-md-7"><label class="small fw-bold">Cidade</label><input type="text" name="cidade" id="cidade" class="form-control"></div>
                            <div class="col-md-6"><label class="small fw-bold">Zona Eleitoral</label><input type="number" name="zona" id="zona" class="form-control"></div>
                            <div class="col-md-6"><label class="small fw-bold">Seção</label><input type="number" name="secao" id="secao" class="form-control"></div>
                            <div class="col-12 border-top pt-2 mt-2">
                                <input type="hidden" name="latitude" id="lat">
                                <input type="hidden" name="longitude" id="lng">
                                <button type="button" class="btn btn-sm btn-outline-primary w-100" onclick="pegarLocalizacao()"><i class="fas fa-map-marker-alt me-1"></i> Capturar Localização Atual (GPS Manual)</button>
                                <small id="gps_status" class="text-muted d-block text-center mt-1" style="font-size: 0.7rem;">O CEP tenta adivinhar o GPS automaticamente.</small>
                            </div>
                            <div class="col-12"><label class="small fw-bold">Observações Gerais</label><textarea name="obs" id="obs" class="form-control" rows="2"></textarea></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-top-0">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary fw-bold px-4">Salvar Cadastro</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalImportar" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" enctype="multipart/form-data" class="modal-content border-0 shadow">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-file-excel me-2"></i> Importar Contatos</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light">
                <input type="hidden" name="acao" value="importar_csv">
                <div class="alert alert-info small border-0 shadow-sm"><i class="fas fa-info-circle me-1"></i> Aceita arquivos em formato <strong>CSV</strong> e <strong>XLSX</strong>.</div>
                <p class="small fw-bold mb-2 text-muted">A planilha deve seguir esta ordem de colunas:</p>
                <ol class="small text-dark fw-bold bg-white p-3 rounded border">
                    <li>Nome Completo (Obrigatório)</li>
                    <li>E-mail</li>
                    <li>Data de Nasc. (DD/MM/AAAA)</li>
                    <li>Telefone</li>
                    <li>WhatsApp</li>
                    <li>Município (Cidade)</li>
                    <li>Grupos (Tags separadas por vírgula)</li>
                </ol>
                <div class="mb-3 mt-4">
                    <label class="form-label small fw-bold">Selecione o arquivo (.csv ou .xlsx)</label>
                    <input type="file" name="arquivo_csv" class="form-control border-success" accept=".csv,.xlsx" required>
                </div>
            </div>
            <div class="modal-footer bg-light border-top-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-success fw-bold px-4"><i class="fas fa-upload me-2"></i> Iniciar Importação</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const modal = new bootstrap.Modal(document.getElementById('modalEleitor'));

function toggleAll(source) {
    document.querySelectorAll('.check-eleitor').forEach(c => c.checked = source.checked);
}

function delegarSelecionados() {
    let selecionados = [];
    document.querySelectorAll('.check-eleitor:checked').forEach(c => selecionados.push(c.value));
    
    if (selecionados.length === 0) {
        alert("Você precisa selecionar pelo menos um eleitor na tabela para delegar.");
        return;
    }
    
    document.getElementById('ids_selecionados').value = selecionados.join(',');
    new bootstrap.Modal(document.getElementById('modalDelegar')).show();
}

function valorSeguro(v) { return v === null || v === undefined ? '' : v; }

function abrirModal() {
    document.getElementById('formEleitor').reset();
    document.getElementById('id').value = '';
    document.getElementById('cep').value = '';
    document.getElementById('responsavel_id').value = '0';
    document.getElementById('status_voto').value = '1';
    document.getElementById('sexo').value = 'Não Informado';
    document.getElementById('espectro_politico').value = 'Não Informado';
    document.getElementById('lat').value = '';
    document.getElementById('lng').value = '';
    document.getElementById('gps_status').innerHTML = "O CEP tenta adivinhar o GPS automaticamente.";
    document.getElementById('gps_status').className = "text-muted d-block text-center mt-1";
    modal.show();
}

function editar(dados) {
    document.getElementById('id').value = valorSeguro(dados.id);
    document.getElementById('nome').value = valorSeguro(dados.nome);
    document.getElementById('whatsapp').value = valorSeguro(dados.whatsapp);
    document.getElementById('email').value = valorSeguro(dados.email);
    document.getElementById('nascimento').value = valorSeguro(dados.nascimento);
    document.getElementById('sexo').value = valorSeguro(dados.sexo) || 'Não Informado';
    document.getElementById('responsavel_id').value = dados.responsavel_id || 0;
    document.getElementById('status_voto').value = dados.status_voto || 1;
    document.getElementById('espectro_politico').value = valorSeguro(dados.espectro_politico) || 'Não Informado';
    document.getElementById('cep').value = '';
    document.getElementById('bairro').value = valorSeguro(dados.bairro);
    document.getElementById('rua').value = valorSeguro(dados.rua);
    document.getElementById('numero').value = valorSeguro(dados.numero);
    document.getElementById('cidade').value = valorSeguro(dados.cidade);
    document.getElementById('zona').value = valorSeguro(dados.zona);
    document.getElementById('secao').value = valorSeguro(dados.secao);
    document.getElementById('lat').value = valorSeguro(dados.latitude);
    document.getElementById('lng').value = valorSeguro(dados.longitude);

    if (dados.latitude) {
        document.getElementById('gps_status').innerHTML = "✅ Localização já cadastrada";
        document.getElementById('gps_status').className = "text-success d-block text-center mt-1 fw-bold";
    } else {
        document.getElementById('gps_status').innerHTML = "Sem localização definida";
        document.getElementById('gps_status').className = "text-muted d-block text-center mt-1";
    }
    document.getElementById('tags').value = valorSeguro(dados.tags);
    document.getElementById('obs').value = valorSeguro(dados.observacoes);
    modal.show();
}

function gerarRelatorio() {
    const form = document.getElementById('formFiltro');
    const url = new URL(window.location.href.split('?')[0]);
    const formData = new FormData(form);
    for (const [key, value] of formData) { if(value) url.searchParams.append(key, value); }
    url.searchParams.append('imprimir', '1');
    window.open(url.toString(), '_blank');
}

function exportarExcel() {
    const form = document.getElementById('formFiltro');
    const url = new URL(window.location.href.split('?')[0]);
    const formData = new FormData(form);
    for (const [key, value] of formData) { if (value) url.searchParams.append(key, value); }
    url.searchParams.append('exportar_excel', '1');
    window.location.href = url.toString();
}

function buscarCEP(cep) {
    let cepLimpo = cep.replace(/\D/g, '');
    if (cepLimpo.length !== 8) return;

    const status = document.getElementById('gps_status');
    status.innerHTML = "<i class='fas fa-spinner fa-spin'></i> Buscando dados do CEP...";
    status.className = "text-primary d-block text-center mt-1 fw-bold";

    fetch(`https://viacep.com.br/ws/${cepLimpo}/json/`)
    .then(res => res.json())
    .then(data => {
        if(data.erro) {
            alert("CEP não encontrado.");
            status.innerHTML = "<span class='text-danger'>CEP não encontrado.</span>";
            return;
        }
        document.getElementById('rua').value = data.logradouro;
        document.getElementById('bairro').value = data.bairro;
        document.getElementById('cidade').value = data.localidade;
        document.getElementById('numero').focus();

        let enderecoBusca = `${data.logradouro}, ${data.localidade}, ${data.uf}, Brazil`;
        fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(enderecoBusca)}`)
        .then(r => r.json())
        .then(geo => {
            if(geo.length > 0) {
                document.getElementById('lat').value = geo[0].lat;
                document.getElementById('lng').value = geo[0].lon;
                status.innerHTML = "✅ Endereço e GPS obtidos pelo CEP!";
                status.className = "text-success d-block text-center mt-1 fw-bold";
            } else {
                status.innerHTML = "⚠️ Endereço achado, mas GPS falhou. Tente capturar pelo botão acima.";
                status.className = "text-warning d-block text-center mt-1 fw-bold";
            }
        });
    });
}

function pegarLocalizacao() {
    const status = document.getElementById('gps_status');
    if (!navigator.geolocation) { alert("Navegador não suporta GPS."); return; }
    status.innerHTML = "Localizando...";

    navigator.geolocation.getCurrentPosition(
        (position) => {
            document.getElementById('lat').value = position.coords.latitude;
            document.getElementById('lng').value = position.coords.longitude;
            status.innerHTML = "✅ Capturado! Lat: " + position.coords.latitude.toFixed(4);
            status.className = "text-success d-block text-center mt-1 fw-bold";
        },
        () => {
            status.innerHTML = "❌ Erro ao obter GPS. Permita o acesso.";
            status.className = "text-danger d-block text-center mt-1 fw-bold";
        }
    );
}
</script>
</body>
</html>