<?php
// --- CONFIGURAÇÕES INICIAIS ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'config.php';
require 'protecao.php';

// --- 0. SEGURANÇA SAAS ---
if (!defined('TENANT_ID')) {
    die("Erro Crítico: Tenant não identificado.");
}

// Verifica permissão
if (function_exists('tem_acesso') && !tem_acesso('relatorios') && !tem_acesso('master')) { 
    die("Acesso Negado."); 
}

// ==============================
// FILTROS (PERÍODO / BAIRRO / STATUS / TIPO)
// ==============================
$periodo = $_GET['periodo'] ?? '30'; // 7, 30, 90, all
$bairroFiltro = trim($_GET['bairro'] ?? '');
$statusFiltro = trim($_GET['status'] ?? '');
$tipoFiltro   = trim($_GET['tipo'] ?? '');

$validPeriodos = ['7','30','90','all'];
if (!in_array($periodo, $validPeriodos, true)) $periodo = '30';

// Monta WHERE parametrizado
$where = "tenant_id = ?";
$params = [TENANT_ID];

if ($periodo !== 'all') {
    $where .= " AND data_abertura >= DATE_SUB(NOW(), INTERVAL {$periodo} DAY)";
}
if ($bairroFiltro !== '') {
    $where .= " AND bairro = ?";
    $params[] = $bairroFiltro;
}
if ($statusFiltro !== '') {
    $where .= " AND status = ?";
    $params[] = $statusFiltro;
}
if ($tipoFiltro !== '') {
    $where .= " AND tipo = ?";
    $params[] = $tipoFiltro;
}

// --- LÓGICA DE EXPORTAÇÃO (CSV) - RESPEITA FILTROS ---
if (isset($_GET['exportar_csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=contatos_gabinete_' . date('d-m-Y') . '.csv');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, ['Nome do Cidadão', 'WhatsApp', 'Data Nasc.', 'Bairro', 'Data Contato', 'Status', 'Tipo'], ';');

    try {
        $stmt = $pdo->prepare("
            SELECT nome_cidadao, whatsapp, nascimento, bairro, data_abertura, status, tipo
            FROM solicitacoes
            WHERE $where
            ORDER BY nome_cidadao ASC
        ");
        $stmt->execute($params);

        // Remove duplicados por WhatsApp
        $seen = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $zap_limpo = preg_replace("/[^0-9]/", "", (string)$row['whatsapp']);
            if (strlen($zap_limpo) <= 11 && strlen($zap_limpo) > 9) $zap_limpo = "55" . $zap_limpo;

            if ($zap_limpo === '' || strlen($zap_limpo) < 10) continue;
            if (isset($seen[$zap_limpo])) continue;
            $seen[$zap_limpo] = true;

            fputcsv($output, [
                $row['nome_cidadao'],
                $zap_limpo,
                $row['nascimento'] ? date('d/m/Y', strtotime($row['nascimento'])) : '-',
                $row['bairro'],
                $row['data_abertura'] ? date('d/m/Y', strtotime($row['data_abertura'])) : '-',
                $row['status'],
                $row['tipo'],
            ], ';');
        }
    } catch (Exception $e) {
        fputcsv($output, ['Erro ao gerar dados: ' . $e->getMessage()], ';');
    }
    fclose($output);
    exit;
}

// --- CONSULTAS PARA OS GRÁFICOS ---
$res_bairros = [];
$res_sexo = [];
$res_tipo = [];
$res_status = [];
$res_idade = [];
$res_tendencia = [];

$kpi_total = 0;
$kpi_pendentes = 0;
$kpi_concluidos = 0;
$kpi_media_horas = 0.0;
$kpi_sla_48h = 0.0;
$top_pend_bairros = [];

try {
    // ==============================
    // KPIs
    // ==============================
    // Total
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM solicitacoes WHERE $where");
    $stmt->execute($params);
    $kpi_total = (int)$stmt->fetchColumn();

    // Pendentes
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM solicitacoes WHERE $where AND status='Pendente'");
    $stmt->execute($params);
    $kpi_pendentes = (int)$stmt->fetchColumn();

    // Concluídos
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM solicitacoes WHERE $where AND status='Concluído'");
    $stmt->execute($params);
    $kpi_concluidos = (int)$stmt->fetchColumn();

    // Tempo médio de atendimento (abertura -> data_resposta) em horas
    // CORREÇÃO: não referencia '0000-00-00 00:00:00' (strict mode quebra). Usa corte seguro.
    $stmt = $pdo->prepare("
        SELECT AVG(TIMESTAMPDIFF(MINUTE, data_abertura, data_resposta)) AS media_min
        FROM solicitacoes
        WHERE $where
          AND data_abertura IS NOT NULL
          AND data_resposta IS NOT NULL
          AND data_resposta > '1970-01-01 00:00:00'
    ");
    $stmt->execute($params);
    $media_min = $stmt->fetchColumn();
    $kpi_media_horas = $media_min ? round(((float)$media_min) / 60, 1) : 0.0;

    // SLA 48h: % respondidos em até 48h (entre os que têm resposta)
    // CORREÇÃO: usa corte seguro.
    $stmt = $pdo->prepare("
        SELECT 
          SUM(CASE WHEN TIMESTAMPDIFF(HOUR, data_abertura, data_resposta) <= 48 THEN 1 ELSE 0 END) AS ok_48,
          COUNT(*) AS total_resp
        FROM solicitacoes
        WHERE $where
          AND data_abertura IS NOT NULL
          AND data_resposta IS NOT NULL
          AND data_resposta > '1970-01-01 00:00:00'
    ");
    $stmt->execute($params);
    $sla = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_resp = (int)($sla['total_resp'] ?? 0);
    $ok_48 = (int)($sla['ok_48'] ?? 0);
    $kpi_sla_48h = $total_resp > 0 ? round(($ok_48 / $total_resp) * 100, 1) : 0.0;

    // Top 3 bairros com pendências
    $stmt = $pdo->prepare("
        SELECT bairro, COUNT(*) total
        FROM solicitacoes
        WHERE $where AND status='Pendente' AND bairro<>'' 
        GROUP BY bairro
        ORDER BY total DESC
        LIMIT 3
    ");
    $stmt->execute($params);
    $top_pend_bairros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ==============================
    // 1. Bairros
    // ==============================
    $sql_bairros = "
        SELECT bairro, COUNT(*) as total
        FROM solicitacoes
        WHERE $where AND bairro IS NOT NULL AND bairro != ''
        GROUP BY bairro
        ORDER BY total DESC
        LIMIT 10
    ";
    $stmt_bairros = $pdo->prepare($sql_bairros);
    $stmt_bairros->execute($params);
    $res_bairros = $stmt_bairros->fetchAll(PDO::FETCH_ASSOC);

    // ==============================
    // 2. Sexo
    // ==============================
    $sql_sexo = "
        SELECT 
            COALESCE(NULLIF(sexo, ''), 'Não Informado') as nome_sexo, 
            COUNT(*) as total 
        FROM solicitacoes 
        WHERE $where
        GROUP BY nome_sexo
    ";
    $stmt_sexo = $pdo->prepare($sql_sexo);
    $stmt_sexo->execute($params);
    $res_sexo = $stmt_sexo->fetchAll(PDO::FETCH_ASSOC);

    // ==============================
    // 3. Tipo
    // ==============================
    $sql_tipo = "
        SELECT COALESCE(NULLIF(tipo,''),'Outros') as tipo, COUNT(*) as total 
        FROM solicitacoes 
        WHERE $where
        GROUP BY tipo 
        ORDER BY total DESC
    ";
    $stmt_tipo = $pdo->prepare($sql_tipo);
    $stmt_tipo->execute($params);
    $res_tipo = $stmt_tipo->fetchAll(PDO::FETCH_ASSOC);

    // ==============================
    // 4. Status
    // ==============================
    $sql_status = "
        SELECT status, COUNT(*) as total 
        FROM solicitacoes 
        WHERE $where
        GROUP BY status
    ";
    $stmt_status = $pdo->prepare($sql_status);
    $stmt_status->execute($params);
    $res_status = $stmt_status->fetchAll(PDO::FETCH_ASSOC);

    // ==============================
    // 5. Faixa Etária
    // ==============================
    $sql_idade = "
        SELECT 
        CASE 
            WHEN nascimento IS NULL THEN 'Não Informado'
            WHEN nascimento < '1900-01-01' THEN 'Não Informado'
            WHEN TIMESTAMPDIFF(YEAR, nascimento, CURDATE()) < 18 THEN 'Jovens (-18)'
            WHEN TIMESTAMPDIFF(YEAR, nascimento, CURDATE()) BETWEEN 18 AND 29 THEN '18-29 anos'
            WHEN TIMESTAMPDIFF(YEAR, nascimento, CURDATE()) BETWEEN 30 AND 44 THEN '30-44 anos'
            WHEN TIMESTAMPDIFF(YEAR, nascimento, CURDATE()) BETWEEN 45 AND 59 THEN '45-59 anos'
            WHEN TIMESTAMPDIFF(YEAR, nascimento, CURDATE()) >= 60 THEN 'Idosos (60+)'
            ELSE 'Não Informado'
        END as faixa,
        COUNT(*) as total
        FROM solicitacoes 
        WHERE $where
        GROUP BY faixa
        ORDER BY faixa ASC
    ";
    $stmt_idade = $pdo->prepare($sql_idade);
    $stmt_idade->execute($params);
    $res_idade = $stmt_idade->fetchAll(PDO::FETCH_ASSOC);

    // ==============================
    // 6. Tendência (Atendimentos por dia)
    // - Para "all", mostra últimos 90 dias
    // ==============================
    $diasTrend = ($periodo === 'all') ? 90 : (int)$periodo;

    $sqlTrend = "
        SELECT DATE(data_abertura) as dia, COUNT(*) as total
        FROM solicitacoes
        WHERE tenant_id = ?
          AND data_abertura >= DATE_SUB(NOW(), INTERVAL {$diasTrend} DAY)
    ";
    $pTrend = [TENANT_ID];

    if ($bairroFiltro !== '') { $sqlTrend .= " AND bairro = ?"; $pTrend[] = $bairroFiltro; }
    if ($statusFiltro !== '') { $sqlTrend .= " AND status = ?"; $pTrend[] = $statusFiltro; }
    if ($tipoFiltro   !== '') { $sqlTrend .= " AND tipo = ?";   $pTrend[] = $tipoFiltro; }

    $sqlTrend .= " GROUP BY DATE(data_abertura) ORDER BY dia ASC";

    $stmt = $pdo->prepare($sqlTrend);
    $stmt->execute($pTrend);
    $res_tendencia = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $erro_db = $e->getMessage();
}

// Prepara JSON para o JS
$json_bairros_labels = json_encode(array_column($res_bairros, 'bairro'));
$json_bairros_data   = json_encode(array_column($res_bairros, 'total'));

$json_sexo_labels = json_encode(array_column($res_sexo, 'nome_sexo'));
$json_sexo_data   = json_encode(array_column($res_sexo, 'total'));

$json_tipo_labels = json_encode(array_column($res_tipo, 'tipo'));
$json_tipo_data   = json_encode(array_column($res_tipo, 'total'));

$json_idade_labels = json_encode(array_column($res_idade, 'faixa'));
$json_idade_data   = json_encode(array_column($res_idade, 'total'));

$json_trend_labels = json_encode(array_map(fn($r)=>date('d/m', strtotime($r['dia'])), $res_tendencia));
$json_trend_data   = json_encode(array_column($res_tendencia, 'total'));

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Relatórios e Dados</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-light p-4">

<div class="container bg-white p-4 rounded shadow">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="text-primary fw-bold"><i class="fas fa-chart-pie me-2"></i>Inteligência de Dados</h3>
        <a href="admin_painel.php" class="btn btn-secondary btn-sm">Voltar ao Painel</a>
    </div>

    <?php if(isset($erro_db)): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i> <b>Atenção:</b> Erro ao processar dados. <br>
            <small><?= htmlspecialchars($erro_db) ?></small>
        </div>
    <?php endif; ?>

    <!-- FILTROS -->
    <form class="row g-2 mb-4">
        <div class="col-md-3">
            <select name="periodo" class="form-select">
                <option value="7"  <?= $periodo==='7'?'selected':'' ?>>Últimos 7 dias</option>
                <option value="30" <?= $periodo==='30'?'selected':'' ?>>Últimos 30 dias</option>
                <option value="90" <?= $periodo==='90'?'selected':'' ?>>Últimos 90 dias</option>
                <option value="all"<?= $periodo==='all'?'selected':'' ?>>Todo período</option>
            </select>
        </div>

        <div class="col-md-3">
            <input name="bairro" class="form-control" placeholder="Bairro (opcional)" value="<?= htmlspecialchars($bairroFiltro) ?>">
        </div>

        <div class="col-md-3">
            <input name="tipo" class="form-control" placeholder="Tipo (opcional)" value="<?= htmlspecialchars($tipoFiltro) ?>">
        </div>

        <div class="col-md-2">
            <select name="status" class="form-select">
                <option value="">Status (todos)</option>
                <option value="Pendente" <?= $statusFiltro==='Pendente'?'selected':'' ?>>Pendente</option>
                <option value="Em Análise" <?= $statusFiltro==='Em Análise'?'selected':'' ?>>Em Análise</option>
                <option value="Concluído" <?= $statusFiltro==='Concluído'?'selected':'' ?>>Concluído</option>
            </select>
        </div>

        <div class="col-md-1 d-grid">
            <button class="btn btn-primary"><i class="fas fa-filter"></i></button>
        </div>
    </form>

    <!-- KPIs -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Atendimentos no período</div>
                    <div class="fs-3 fw-bold"><?= (int)$kpi_total ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Pendentes</div>
                    <div class="fs-3 fw-bold text-warning"><?= (int)$kpi_pendentes ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Tempo médio (horas)</div>
                    <div class="fs-3 fw-bold text-primary"><?= number_format((float)$kpi_media_horas, 1, ',', '.') ?></div>
                    <div class="text-muted small">Abertura → Resposta</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">SLA 48h (respondidos)</div>
                    <div class="fs-3 fw-bold text-success"><?= number_format((float)$kpi_sla_48h, 1, ',', '.') ?>%</div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small mb-2">Bairros críticos (pendências)</div>
                    <?php if(empty($top_pend_bairros)): ?>
                        <span class="text-muted">Sem pendências no filtro atual.</span>
                    <?php else: ?>
                        <?php foreach($top_pend_bairros as $b): ?>
                            <span class="badge bg-danger me-1"><?= htmlspecialchars($b['bairro']) ?> (<?= (int)$b['total'] ?>)</span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Base de Contatos -->
    <div class="card bg-primary text-white mb-4 shadow-sm border-0">
        <div class="card-body p-4">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h4 class="fw-bold"><i class="fab fa-whatsapp me-2"></i>Base de Contatos (Leads)</h4>
                    <p class="mb-0 opacity-75">Exporte a lista de cidadãos e filtre por dados demográficos.</p>
                    <small class="opacity-75">
                        Filtros ativos:
                        <b><?= $periodo==='all'?'Todo período':"Últimos {$periodo} dias" ?></b>
                        <?= $bairroFiltro ? ' | Bairro: <b>'.htmlspecialchars($bairroFiltro).'</b>' : '' ?>
                        <?= $statusFiltro ? ' | Status: <b>'.htmlspecialchars($statusFiltro).'</b>' : '' ?>
                        <?= $tipoFiltro ? ' | Tipo: <b>'.htmlspecialchars($tipoFiltro).'</b>' : '' ?>
                    </small>
                </div>
                <div class="col-md-4 text-end">
                    <a href="?<?= http_build_query(array_merge($_GET, ['exportar_csv' => 'true'])) ?>" class="btn btn-light fw-bold text-primary me-2">
                        <i class="fas fa-file-csv"></i> Baixar CSV
                    </a>
                    <button type="button" class="btn btn-outline-light fw-bold" data-bs-toggle="modal" data-bs-target="#modalLista">
                        <i class="fas fa-copy"></i> Copiar Lista
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Tendência -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-header bg-white fw-bold">
                    Tendência de Atendimentos (<?= $periodo==='all'?'últimos 90 dias':"últimos {$periodo} dias" ?>)
                </div>
                <div class="card-body"><canvas id="chartTrend"></canvas></div>
            </div>
        </div>
    </div>

    <!-- Gráficos principais -->
    <div class="row mb-4">
        <div class="col-md-8">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-header bg-white fw-bold">Atendimentos por Bairro (Top 10)</div>
                <div class="card-body"><canvas id="chartBairros"></canvas></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-header bg-white fw-bold">Perfil (Sexo)</div>
                <div class="card-body"><canvas id="chartSexo"></canvas></div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-header bg-white fw-bold">Faixa Etária (Idade)</div>
                <div class="card-body"><canvas id="chartIdade"></canvas></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-header bg-white fw-bold">Tipos de Demanda</div>
                <div class="card-body"><canvas id="chartTipo"></canvas></div>
            </div>
        </div>
    </div>

    <!-- Status -->
    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white fw-bold">Resumo Geral de Status</div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0 text-center">
                        <thead class="table-light"><tr><th>Status Atual</th><th>Quantidade</th><th>Percentual</th></tr></thead>
                        <tbody>
                            <?php 
                            $total_geral = 0;
                            foreach($res_status as $r) $total_geral += (int)$r['total'];

                            if(empty($res_status)): ?>
                                <tr><td colspan="3" class="text-muted py-3">Sem dados registrados.</td></tr>
                            <?php else:
                                foreach($res_status as $row): 
                                    $bg = match($row['status']) { 'Concluído'=>'success', 'Pendente'=>'warning', 'Arquivado'=>'secondary', 'Em Análise'=>'primary', default=>'dark' };
                                    $pct = $total_geral > 0 ? round(((int)$row['total'] / $total_geral) * 100, 1) : 0;
                            ?>
                            <tr>
                                <td><span class="badge bg-<?= $bg ?> rounded-pill px-3"><?= htmlspecialchars($row['status']) ?></span></td>
                                <td class="fw-bold"><?= (int)$row['total'] ?></td>
                                <td class="text-muted small"><?= $pct ?>%</td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <tfoot class="table-dark">
                            <tr>
                                <td>TOTAL GERAL</td>
                                <td colspan="2"><?= (int)$total_geral ?> atendimentos</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Modal lista (respeita filtros + remove duplicados) -->
<div class="modal fade" id="modalLista" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Lista Rápida de Telefones (filtrada)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <textarea class="form-control" rows="10" readonly><?php
                    try {
                        $stmt_lista = $pdo->prepare("
                            SELECT whatsapp, nome_cidadao
                            FROM solicitacoes
                            WHERE $where
                        ");
                        $stmt_lista->execute($params);

                        $seen = [];
                        while($c = $stmt_lista->fetch(PDO::FETCH_ASSOC)) {
                            $z = preg_replace("/[^0-9]/", "", (string)$c['whatsapp']);
                            if (strlen($z) >= 10) {
                                if (strlen($z) <= 11) $z = "55" . $z;
                                if (isset($seen[$z])) continue;
                                $seen[$z] = true;
                                echo $z . "," . $c['nome_cidadao'] . "\n";
                            }
                        }
                    } catch (Exception $e) { echo "Erro ao listar."; }
                ?></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="navigator.clipboard.writeText(document.querySelector('#modalLista textarea').value); alert('Copiado!');">
                    <i class="fas fa-copy"></i> Copiar Tudo
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    const bairrosLabels = <?= $json_bairros_labels ?: '[]' ?>;
    const bairrosData   = <?= $json_bairros_data   ?: '[]' ?>;
    const sexoLabels    = <?= $json_sexo_labels    ?: '[]' ?>;
    const sexoData      = <?= $json_sexo_data      ?: '[]' ?>;
    const tipoLabels    = <?= $json_tipo_labels    ?: '[]' ?>;
    const tipoData      = <?= $json_tipo_data      ?: '[]' ?>;
    const idadeLabels   = <?= $json_idade_labels   ?: '[]' ?>;
    const idadeData     = <?= $json_idade_data     ?: '[]' ?>;

    const trendLabels   = <?= $json_trend_labels   ?: '[]' ?>;
    const trendData     = <?= $json_trend_data     ?: '[]' ?>;

    // Tendência
    new Chart(document.getElementById('chartTrend'), {
        type: 'line',
        data: {
            labels: trendLabels,
            datasets: [{
                label: 'Atendimentos',
                data: trendData,
                tension: 0.3,
                fill: false,
                borderColor: '#4e73df'
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: true } },
            scales: { y: { beginAtZero: true } }
        }
    });

    // Bairros
    new Chart(document.getElementById('chartBairros'), {
        type: 'bar',
        data: { labels: bairrosLabels, datasets: [{ label: 'Chamados', data: bairrosData, borderRadius: 4, backgroundColor: '#4e73df' }] },
        options: { responsive: true, plugins: { legend: {display: false} }, scales: { y: { beginAtZero: true } } }
    });

    // Sexo
    new Chart(document.getElementById('chartSexo'), {
        type: 'doughnut',
        data: {
            labels: sexoLabels,
            datasets: [{
                data: sexoData,
                backgroundColor: function(context) {
                    const label = context.chart.data.labels[context.dataIndex];
                    if (label === 'Masculino' || label === 'M') return '#36b9cc';
                    if (label === 'Feminino' || label === 'F') return '#e74a3b';
                    return '#858796';
                }
            }]
        }
    });

    // Função para gerar cores baseadas no valor (Volume)
    function getColorForVolume(value, max) {
        const ratio = max === 0 ? 0 : value / max;
        // Se for muito alto (> 80%), Vermelho. Médio (> 40%), Amarelo/Laranja. Baixo, Verde/Azul.
        if (ratio > 0.8) return 'rgba(231, 74, 59, 0.7)';   // Vermelho (Alerta)
        if (ratio > 0.5) return 'rgba(246, 194, 62, 0.7)';  // Amarelo
        if (ratio > 0.2) return 'rgba(54, 185, 204, 0.7)';  // Ciano
        return 'rgba(28, 200, 138, 0.7)';                   // Verde (Tranquilo)
    }

    // Encontra o valor máximo para calibrar as cores
    const maxDemanda = Math.max(...tipoData, 0);
    const bgColorsDemanda = tipoData.map(val => getColorForVolume(val, maxDemanda));
    const borderColorsDemanda = bgColorsDemanda.map(c => c.replace('0.7', '1.0')); // Borda mais escura

    // Tipo de Demanda (Mudado de 'radar' para 'polarArea' para suportar cores por fatia)
    new Chart(document.getElementById('chartTipo'), {
        type: 'polarArea',
        data: { 
            labels: tipoLabels, 
            datasets: [{ 
                label: 'Volume', 
                data: tipoData, 
                backgroundColor: bgColorsDemanda,
                borderColor: borderColorsDemanda,
                borderWidth: 1
            }] 
        },
        options: { 
            responsive: true,
            plugins: {
                legend: { position: 'right' }
            },
            scales: { r: { suggestMin: 0 } } 
        }
    });

    // Idade
    new Chart(document.getElementById('chartIdade'), {
        type: 'bar',
        data: { labels: idadeLabels, datasets: [{ label: 'Pessoas', data: idadeData, borderRadius: 4, backgroundColor: '#1cc88a' }] },
        options: { indexAxis: 'y', responsive: true, plugins: { legend: {display: false} } }
    });
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>