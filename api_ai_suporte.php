<?php
/**
 * api_ai_suporte.php
 * Mentor Estratégico com CONTEXTO REAL + ROTEIRO DE VISITA (ÚLTIMOS 30 DIAS) + ORIGEM POR TENANT
 * - Retorna SEMPRE JSON (inclusive em warnings/fatais)
 * - Aceita POST x-www-form-urlencoded e JSON
 * - Exige sessão (tenant_id)
 * - Busca contexto real do banco:
 *    * solicitacoes por status
 *    * total eleitores / aniversariantes hoje
 *    * bairros quentes (30 dias, pendente pesa mais)
 *    * roteiro do dia: pontos (lat/lng) ordenados por proximidade a partir do centro do tenant (configuracoes.mapa_lat/mapa_lng)
 * - Chama OpenAI chat/completions com gpt-4o-mini
 */

// ==============================
// 0) SEMPRE JSON + TRATAMENTO DE ERROS
// ==============================
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => "PHP warning/notice: $message",
        'where'   => basename($file) . ':' . $line,
    ]);
    exit;
});

set_exception_handler(function ($e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'PHP exception: ' . $e->getMessage(),
        'where'   => basename($e->getFile()) . ':' . $e->getLine(),
    ]);
    exit;
});

register_shutdown_function(function () {
    $err = error_get_last();
    if (!$err) return;
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array($err['type'], $fatalTypes, true)) return;

    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'PHP fatal: ' . ($err['message'] ?? 'Erro fatal'),
        'where'   => basename($err['file'] ?? '') . ':' . ($err['line'] ?? ''),
    ]);
});

// ==============================
// 1) BOOTSTRAP
// ==============================
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ==============================
// 2) DEBUG (opcional)
// ==============================
if (isset($_GET['debug'])) {
    $raw = file_get_contents('php://input') ?: '';
    echo json_encode([
        'success'      => true,
        'method'       => $_SERVER['REQUEST_METHOD'] ?? null,
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
        'post'         => $_POST,
        'get'          => $_GET,
        'raw'          => $raw,
        'has_key'      => defined('OPENAI_API_KEY') && (bool)OPENAI_API_KEY,
        'tenant_id'    => $_SESSION['tenant_id'] ?? null,
    ]);
    exit;
}

// ==============================
// 3) VALIDAÇÕES BÁSICAS
// ==============================
if (!defined('OPENAI_API_KEY') || !OPENAI_API_KEY) {
    echo json_encode(['success' => false, 'error' => 'Chave da IA não configurada.']);
    exit;
}

if (empty($_SESSION['tenant_id'])) {
    echo json_encode(['success' => false, 'error' => 'Sessão expirada.']);
    exit;
}

$tenantId = (int)$_SESSION['tenant_id'];

// ==============================
// 4) LEITURA DA MENSAGEM (ROBUSTO)
// ==============================
$raw = file_get_contents('php://input') ?: '';
$bodyJson = json_decode($raw, true);
if (!is_array($bodyJson)) $bodyJson = [];

$userMsg =
    ($bodyJson['message']  ?? null) ??
    ($bodyJson['mensagem'] ?? null) ??
    ($_POST['message']     ?? null) ??
    ($_POST['mensagem']    ?? null) ??
    ($_GET['message']      ?? null) ??
    ($_GET['mensagem']     ?? null) ??
    '';

if ((string)$userMsg === '' && $raw && strpos($raw, '=') !== false) {
    $tmp = [];
    parse_str($raw, $tmp);
    if (is_array($tmp)) {
        $userMsg = $tmp['message'] ?? $tmp['mensagem'] ?? $userMsg;
    }
}

$userMsg = trim((string)$userMsg);
if ($userMsg === '') {
    echo json_encode(['success' => false, 'error' => 'Mensagem vazia.']);
    exit;
}

// ==============================
// HELPERS (rota por proximidade)
// ==============================
function safe_float($v) {
    $v = str_replace(',', '.', (string)$v);
    return is_numeric($v) ? (float)$v : 0.0;
}

function haversine_km($lat1, $lon1, $lat2, $lon2) {
    $R = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2)*sin($dLat/2) + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLon/2)*sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $R * $c;
}

/**
 * Ordena pontos por proximidade (nearest neighbor) a partir da origem.
 * $points: [['lat'=>..,'lng'=>..], ...]
 */
function order_points_nearest($originLat, $originLng, $points, $limit = 10) {
    $points = array_values($points);
    if (count($points) <= 1) return array_slice($points, 0, $limit);

    $ordered = [];
    $curLat = $originLat;
    $curLng = $originLng;

    while (!empty($points) && count($ordered) < $limit) {
        $bestIdx = 0;
        $bestDist = null;

        foreach ($points as $i => $p) {
            $d = haversine_km($curLat, $curLng, $p['lat'], $p['lng']);
            if ($bestDist === null || $d < $bestDist) {
                $bestDist = $d;
                $bestIdx = $i;
            }
        }

        $chosen = $points[$bestIdx];
        $chosen['_dist_km_from_prev'] = round((float)$bestDist, 2);
        $ordered[] = $chosen;

        $curLat = $chosen['lat'];
        $curLng = $chosen['lng'];

        array_splice($points, $bestIdx, 1);
    }

    return $ordered;
}

function gm_dir_link($originLat, $originLng, $orderedPoints) {
    if (empty($orderedPoints)) return null;

    $origin = $originLat . ',' . $originLng;
    $destination = end($orderedPoints);
    $dest = $destination['lat'] . ',' . $destination['lng'];

    $wps = [];
    for ($i = 0; $i < count($orderedPoints) - 1; $i++) {
        $wps[] = $orderedPoints[$i]['lat'] . ',' . $orderedPoints[$i]['lng'];
    }

    $url = 'https://www.google.com/maps/dir/?api=1'
        . '&origin=' . urlencode($origin)
        . '&destination=' . urlencode($dest);

    if (!empty($wps)) {
        $url .= '&waypoints=' . urlencode(implode('|', $wps));
    }

    $url .= '&travelmode=driving';
    return $url;
}

// ==============================
// 5) CONTEXTO REAL DO BANCO (ÚLTIMOS 30 DIAS)
// ==============================
$ctx = [
    'data' => date('Y-m-d'),
    'tenant_id' => $tenantId,
    'origem' => ['lat' => -23.5505, 'lng' => -46.6333], // fallback SP
    'solicitacoes' => ['Pendente'=>0,'Em Análise'=>0,'Concluído'=>0],
    'eleitores_total' => 0,
    'aniversariantes_hoje' => 0,
    'bairros_quentes_30d' => [],
    'ultimas_solicitacoes' => [],
    'roteiro_por_bairro_30d' => [],
    'roteiro_dia_30d' => [
        'total_pontos' => 0,
        'pontos_ordenados' => [],
        'google_maps_rota' => null
    ],
];

try {
    // Origem do roteiro por tenant (configuracoes.mapa_lat/mapa_lng)
    $stmt = $pdo->prepare("SELECT mapa_lat, mapa_lng FROM configuracoes WHERE tenant_id=? LIMIT 1");
    $stmt->execute([$tenantId]);
    $conf = $stmt->fetch(PDO::FETCH_ASSOC);

    $origLat = $ctx['origem']['lat'];
    $origLng = $ctx['origem']['lng'];

    if ($conf) {
        $tLat = safe_float($conf['mapa_lat'] ?? '');
        $tLng = safe_float($conf['mapa_lng'] ?? '');
        if ($tLat != 0.0) $origLat = $tLat;
        if ($tLng != 0.0) $origLng = $tLng;
    }

    $ctx['origem'] = ['lat' => $origLat, 'lng' => $origLng];

    // Solicitações por status (geral)
    $stmt = $pdo->prepare("
        SELECT status, COUNT(*) total
        FROM solicitacoes
        WHERE tenant_id = ?
        GROUP BY status
    ");
    $stmt->execute([$tenantId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $st = (string)($r['status'] ?? '');
        $tt = (int)($r['total'] ?? 0);
        if ($st !== '') $ctx['solicitacoes'][$st] = $tt;
    }

    // Total eleitores
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM eleitores WHERE tenant_id=?");
    $stmt->execute([$tenantId]);
    $ctx['eleitores_total'] = (int)$stmt->fetchColumn();

    // Aniversariantes hoje
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM eleitores
        WHERE tenant_id=?
          AND MONTH(data_nascimento)=MONTH(NOW())
          AND DAY(data_nascimento)=DAY(NOW())
    ");
    $stmt->execute([$tenantId]);
    $ctx['aniversariantes_hoje'] = (int)$stmt->fetchColumn();

    // Últimas solicitações (geral)
    $stmt = $pdo->prepare("
        SELECT nome_cidadao, bairro, status, data_abertura, mensagem
        FROM solicitacoes
        WHERE tenant_id=?
        ORDER BY id DESC
        LIMIT 5
    ");
    $stmt->execute([$tenantId]);
    $ctx['ultimas_solicitacoes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Bairros quentes (somente últimos 30 dias; pendente pesa mais)
    $stmt = $pdo->prepare("
        SELECT 
            bairro,
            SUM(CASE WHEN status = 'Pendente' THEN 3
                     WHEN status = 'Em Análise' THEN 2
                     ELSE 1 END) AS score,
            COUNT(*) total,
            SUM(CASE WHEN status = 'Pendente' THEN 1 ELSE 0 END) pendentes
        FROM solicitacoes
        WHERE tenant_id=?
          AND bairro <> ''
          AND data_abertura >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY bairro
        ORDER BY score DESC, pendentes DESC, total DESC
        LIMIT 5
    ");
    $stmt->execute([$tenantId]);
    $bairros = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $ctx['bairros_quentes_30d'] = $bairros;

    // Roteiro por bairro (pontos com lat/lng, últimos 30 dias)
    $roteiroPorBairro = [];
    $allPoints = [];

    foreach ($bairros as $b) {
        $bairro = (string)$b['bairro'];

        $stmtPts = $pdo->prepare("
            SELECT 
              id, bairro, status, data_abertura,
              latitude, longitude,
              mensagem, descricao
            FROM solicitacoes
            WHERE tenant_id=?
              AND bairro = ?
              AND data_abertura >= DATE_SUB(NOW(), INTERVAL 30 DAY)
              AND latitude <> '' AND longitude <> ''
            ORDER BY 
              (status='Pendente') DESC,
              data_abertura DESC,
              id DESC
            LIMIT 8
        ");
        $stmtPts->execute([$tenantId, $bairro]);
        $pts = $stmtPts->fetchAll(PDO::FETCH_ASSOC);

        $pontos = [];
        foreach ($pts as $p) {
            $lat = safe_float($p['latitude'] ?? '');
            $lng = safe_float($p['longitude'] ?? '');
            if ($lat == 0.0 || $lng == 0.0) continue;

            $resumo = trim((string)($p['mensagem'] ?? ''));
            if ($resumo === '') $resumo = trim((string)($p['descricao'] ?? ''));
            if (mb_strlen($resumo) > 120) $resumo = mb_substr($resumo, 0, 120) . '...';

            $point = [
                'id' => (int)$p['id'],
                'bairro' => (string)$p['bairro'],
                'status' => (string)$p['status'],
                'data_abertura' => (string)$p['data_abertura'],
                'lat' => $lat,
                'lng' => $lng,
                'resumo' => $resumo
            ];

            $pontos[] = $point;
            $allPoints[] = $point;
        }

        if (!empty($pontos)) {
            // Ordena pontos do bairro por proximidade à origem (limita 6)
            $pontosOrdenados = order_points_nearest($origLat, $origLng, $pontos, 6);

            $roteiroPorBairro[] = [
                'bairro' => $bairro,
                'score' => (int)($b['score'] ?? 0),
                'pendentes' => (int)($b['pendentes'] ?? 0),
                'total_30d' => (int)($b['total'] ?? 0),
                'pontos_ordenados' => $pontosOrdenados,
                'google_maps_rota_bairro' => gm_dir_link($origLat, $origLng, $pontosOrdenados),
                'maps_busca_bairro' => 'https://www.google.com/maps/search/?api=1&query=' . urlencode($bairro),
            ];
        }
    }

    $ctx['roteiro_por_bairro_30d'] = $roteiroPorBairro;

    // Roteiro do dia: junta pontos de todos os bairros e ordena por proximidade (limita 10)
    $orderedDay = order_points_nearest($origLat, $origLng, $allPoints, 10);
    $ctx['roteiro_dia_30d'] = [
        'total_pontos' => count($orderedDay),
        'pontos_ordenados' => $orderedDay,
        'google_maps_rota' => gm_dir_link($origLat, $origLng, $orderedDay)
    ];

} catch (Exception $e) {
    $ctx['erro_contexto'] = $e->getMessage();
}

// ==============================
// 6) PROMPT (COM ROTEIRO 30 DIAS + ORIGEM TENANT)
// ==============================
$nome_user = explode(' ', $_SESSION['admin_nome'] ?? 'Vereador')[0];
$ctxJson = json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$systemPrompt = <<<PROMPT
Você é o Mentor Estratégico do Gabinete Digital.

DADOS REAIS DO SISTEMA (inclui origem do tenant e roteiro de visitas dos últimos 30 dias):
{$ctxJson}

TAREFA:
- Criar um ROTEIRO DE VISITA POR BAIRRO para {$nome_user} hoje, usando:
  1) bairros_quentes_30d (prioridade)
  2) roteiro_dia_30d.pontos_ordenados (ordem sugerida)
- Entregar em turnos (Manhã / Tarde / Noite) e ser prático.

REGRAS:
- Máximo 6 bullets no total.
- Cada turno deve ter: bairro(s) + objetivo + 1 frase de abordagem + 1 ideia de conteúdo (foto/vídeo).
- Se aniversariantes_hoje > 0, inclua uma mensagem pronta curta para WhatsApp.
- Se existir roteiro_dia_30d.google_maps_rota, inclua no final: "Rota no Maps: <link>".

ESTILO:
- Direto e sem enrolação.
PROMPT;

// ==============================
// 7) PAYLOAD
// ==============================
$payload = [
    'model' => 'gpt-4o-mini',
    'messages' => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user',   'content' => $userMsg],
    ],
    'temperature' => 0.5,
    'max_tokens'  => 240,
];

// ==============================
// 8) CHAMADA OPENAI
// ==============================
$ch = curl_init("https://api.openai.com/v1/chat/completions");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => [
        "Content-Type: application/json",
        "Authorization: Bearer " . OPENAI_API_KEY,
    ],
    CURLOPT_TIMEOUT        => 30,
]);

$response = curl_exec($ch);
$curlErr  = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) {
    echo json_encode(['success' => false, 'error' => 'Curl error: ' . $curlErr]);
    exit;
}

$out = json_decode($response, true);
if (!is_array($out)) {
    echo json_encode([
        'success' => false,
        'error'   => 'Resposta inválida (não-JSON).',
        'http'    => $httpCode,
        'raw'     => $response,
    ]);
    exit;
}

if ($httpCode < 200 || $httpCode >= 300) {
    echo json_encode([
        'success' => false,
        'error'   => 'OpenAI HTTP ' . $httpCode . ': ' . ($out['error']['message'] ?? 'Erro desconhecido'),
        'raw'     => $out,
    ]);
    exit;
}

$content = trim((string)($out['choices'][0]['message']['content'] ?? ''));
if ($content === '') {
    echo json_encode([
        'success' => false,
        'error'   => 'Sem content em choices.',
        'raw'     => $out,
    ]);
    exit;
}

// ==============================
// 9) RESPOSTA FINAL
// ==============================
echo json_encode(['success' => true, 'response' => $content]);
exit;
