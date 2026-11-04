<?php
session_start();
require 'config.php';
require 'protecao.php';

if (!defined('TENANT_ID')) {
    die("Erro Crítico: Tenant não identificado.");
}
if (function_exists('tem_acesso') && !tem_acesso('eleitores') && !tem_acesso('master')) {
    die("Acesso Negado.");
}

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function parentescoLabel($tipo) {
    $map = [
        'conjuge' => 'Cônjuge',
        'pai' => 'Pai',
        'mae' => 'Mãe',
        'filho' => 'Filho',
        'filha' => 'Filha',
        'irmao' => 'Irmão',
        'irma' => 'Irmã',
        'pai_mae' => 'Pai/Mãe',
        'irmao_irma' => 'Irmão/Irmã',
        'outro' => 'Outro familiar'
    ];
    return $map[$tipo] ?? ucfirst((string)$tipo);
}

function parentescoInverso($tipo) {
    $map = [
        'conjuge' => 'conjuge',
        'pai' => 'filho',
        'mae' => 'filho',
        'filho' => 'pai_mae',
        'filha' => 'pai_mae',
        'irmao' => 'irmao_irma',
        'irma' => 'irmao_irma',
        'outro' => 'outro'
    ];
    return $map[$tipo] ?? 'outro';
}

$id = (int)($_GET['id'] ?? $_POST['eleitor_id'] ?? 0);
if ($id <= 0) die("Eleitor não informado.");

$st = $pdo->prepare("SELECT * FROM eleitores WHERE id=? AND tenant_id=? LIMIT 1");
$st->execute([$id, TENANT_ID]);
$eleitor = $st->fetch(PDO::FETCH_ASSOC);
if (!$eleitor) die("Eleitor não encontrado.");

$msg = "";

// Vincular familiar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'vincular_familiar') {
    try {
        $familiarId = (int)($_POST['familiar_id'] ?? 0);
        $parentesco = trim($_POST['parentesco'] ?? 'outro');
        $permitidos = ['conjuge','pai','mae','filho','filha','irmao','irma','outro'];

        if ($familiarId <= 0 || $familiarId === $id) {
            throw new Exception("Escolha outra pessoa da base.");
        }
        if (!in_array($parentesco, $permitidos, true)) {
            $parentesco = 'outro';
        }

        $stF = $pdo->prepare("SELECT id FROM eleitores WHERE id=? AND tenant_id=? LIMIT 1");
        $stF->execute([$familiarId, TENANT_ID]);
        if (!$stF->fetchColumn()) throw new Exception("Familiar não encontrado neste gabinete.");

        $pdo->beginTransaction();

        $sql = "INSERT INTO eleitores_familiares
                (tenant_id, eleitor_id, familiar_id, parentesco)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE parentesco=VALUES(parentesco)";
        $ins = $pdo->prepare($sql);

        $ins->execute([TENANT_ID, $id, $familiarId, $parentesco]);
        $ins->execute([TENANT_ID, $familiarId, $id, parentescoInverso($parentesco)]);

        $pdo->commit();
        $msg = "<div class='alert alert-success'>Vínculo familiar salvo com sucesso.</div>";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = "<div class='alert alert-danger'>" . h($e->getMessage()) . "</div>";
    }
}

// Remover familiar
if (isset($_GET['remover_familiar'])) {
    $familiarId = (int)$_GET['remover_familiar'];
    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare("DELETE FROM eleitores_familiares
                             WHERE tenant_id=?
                               AND ((eleitor_id=? AND familiar_id=?)
                                 OR (eleitor_id=? AND familiar_id=?))");
        $del->execute([TENANT_ID, $id, $familiarId, $familiarId, $id]);
        $pdo->commit();
        header("Location: admin_arvore_eleitor.php?id=" . $id . "&msg=removido");
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = "<div class='alert alert-danger'>Erro ao remover vínculo.</div>";
    }
}

if (($_GET['msg'] ?? '') === 'removido') {
    $msg = "<div class='alert alert-success'>Vínculo familiar removido.</div>";
}

// Toda a base para construir rede
$stTodos = $pdo->prepare("SELECT id,nome,whatsapp,bairro,responsavel_id,is_lider
                         FROM eleitores WHERE tenant_id=? ORDER BY nome");
$stTodos->execute([TENANT_ID]);
$todos = $stTodos->fetchAll(PDO::FETCH_ASSOC);

$porId = [];
$filhos = [];
foreach ($todos as $p) {
    $pid = (int)$p['id'];
    $porId[$pid] = $p;
    $resp = (int)($p['responsavel_id'] ?? 0);
    if ($resp > 0) $filhos[$resp][] = $pid;
}

// Familiares
$stFam = $pdo->prepare("SELECT ef.familiar_id, ef.parentesco, e.nome, e.whatsapp, e.bairro
                        FROM eleitores_familiares ef
                        JOIN eleitores e
                          ON e.id=ef.familiar_id
                         AND e.tenant_id=ef.tenant_id
                        WHERE ef.tenant_id=? AND ef.eleitor_id=?
                        ORDER BY e.nome");
$stFam->execute([TENANT_ID, $id]);
$familiares = $stFam->fetchAll(PDO::FETCH_ASSOC);

// Pessoas disponíveis para vínculo familiar
$stDisp = $pdo->prepare("SELECT id,nome FROM eleitores
                        WHERE tenant_id=? AND id<>?
                        ORDER BY nome");
$stDisp->execute([TENANT_ID, $id]);
$disponiveis = $stDisp->fetchAll(PDO::FETCH_ASSOC);

// Cadeia acima: quem indicou quem
$ancestrais = [];
$visitados = [];
$cursor = (int)($eleitor['responsavel_id'] ?? 0);
$limite = 50;
while ($cursor > 0 && isset($porId[$cursor]) && !isset($visitados[$cursor]) && $limite-- > 0) {
    $visitados[$cursor] = true;
    $ancestrais[] = $porId[$cursor];
    $cursor = (int)($porId[$cursor]['responsavel_id'] ?? 0);
}
$ancestrais = array_reverse($ancestrais);

// Contar rede descendente
function contarRede($id, $filhos, &$visitados = []) {
    if (isset($visitados[$id])) return 0;
    $visitados[$id] = true;
    $total = 0;
    foreach ($filhos[$id] ?? [] as $filho) {
        $total++;
        $total += contarRede($filho, $filhos, $visitados);
    }
    return $total;
}

$v = [];
$totalRede = contarRede($id, $filhos, $v);
$totalDiretos = count($filhos[$id] ?? []);

function renderRede($id, $filhos, $porId, $nivel = 0, &$visitados = []) {
    if ($nivel > 15 || isset($visitados[$id])) return;
    $visitados[$id] = true;

    $children = $filhos[$id] ?? [];
    if (!$children) return;

    echo "<ul class='tree-list'>";
    foreach ($children as $cid) {
        if (!isset($porId[$cid])) continue;
        $p = $porId[$cid];
        echo "<li>";
        echo "<div class='person-card'>";
        echo "<div class='fw-bold'>" . h($p['nome']) . "</div>";
        echo "<small class='text-muted'>" . (!empty($p['is_lider']) ? "Liderança" : "Apoiador") . "</small>";
        echo "<div class='mt-2'><a class='btn btn-sm btn-outline-primary rounded-pill' href='admin_arvore_eleitor.php?id=" . (int)$cid . "'>Abrir</a></div>";
        echo "</div>";
        renderRede($cid, $filhos, $porId, $nivel + 1, $visitados);
        echo "</li>";
    }
    echo "</ul>";
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Família e Rede - <?= h($eleitor['nome']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
body{background:#f3f4f6}
.hero{background:linear-gradient(135deg,#0d6efd,#073b83);color:#fff}
.person-card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:12px 14px;min-width:180px;box-shadow:0 4px 14px rgba(0,0,0,.05)}
.tree-list{list-style:none;margin:15px 0 0 24px;padding-left:24px;border-left:2px solid #dbeafe}
.tree-list>li{position:relative;margin:15px 0}
.tree-list>li:before{content:"";position:absolute;left:-24px;top:24px;width:24px;border-top:2px solid #dbeafe}
.family-card{border-left:4px solid #198754}
.chain{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.chain .node{background:#fff;border:1px solid #dbeafe;padding:8px 12px;border-radius:999px;font-weight:700}
</style>
</head>
<body>
<div class="container py-4" style="max-width:1200px">

<div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
    <a href="admin_eleitores.php" class="btn btn-outline-secondary rounded-pill">
        <i class="fas fa-arrow-left me-1"></i> Base de Apoio
    </a>
    <a href="admin_arvore_eleitor.php?id=<?= $id ?>" class="btn btn-outline-primary rounded-pill">
        <i class="fas fa-rotate-right me-1"></i> Atualizar
    </a>
</div>

<?= $msg ?>

<div class="hero rounded-4 shadow-sm p-4 mb-4">
    <div class="row align-items-center">
        <div class="col-lg-8">
            <div class="small opacity-75 text-uppercase fw-bold">Pessoa selecionada</div>
            <h2 class="fw-bold mb-1"><?= h($eleitor['nome']) ?></h2>
            <div class="opacity-75">
                <?= h($eleitor['bairro'] ?? '') ?>
                <?= !empty($eleitor['whatsapp']) ? ' • ' . h($eleitor['whatsapp']) : '' ?>
            </div>
        </div>
        <div class="col-lg-4 mt-3 mt-lg-0">
            <div class="row text-center">
                <div class="col-6"><div class="fs-3 fw-bold"><?= $totalDiretos ?></div><small>Indicados diretos</small></div>
                <div class="col-6"><div class="fs-3 fw-bold"><?= $totalRede ?></div><small>Rede total</small></div>
            </div>
        </div>
    </div>
</div>

<ul class="nav nav-pills mb-4" role="tablist">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#mobilizacao">
            <i class="fas fa-sitemap me-1"></i> Rede de Mobilização
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="pill" data-bs-target="#familia">
            <i class="fas fa-people-roof me-1"></i> Núcleo Familiar
        </button>
    </li>
</ul>

<div class="tab-content">

<div class="tab-pane fade show active" id="mobilizacao">
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <h5 class="fw-bold mb-3">Caminho até esta pessoa</h5>
            <div class="chain">
                <?php if(empty($ancestrais)): ?>
                    <span class="text-muted">Voto direto / sem indicação acima.</span>
                <?php else: ?>
                    <?php foreach($ancestrais as $a): ?>
                        <a class="node text-decoration-none" href="admin_arvore_eleitor.php?id=<?= (int)$a['id'] ?>"><?= h($a['nome']) ?></a>
                        <i class="fas fa-arrow-right text-muted"></i>
                    <?php endforeach; ?>
                <?php endif; ?>
                <span class="node bg-primary text-white"><?= h($eleitor['nome']) ?></span>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            <h5 class="fw-bold">Pessoas abaixo na rede</h5>
            <p class="text-muted small">A estrutura abaixo usa o campo “Quem indicou?” da Base de Apoio.</p>
            <div class="person-card border-primary">
                <div class="fw-bold text-primary"><?= h($eleitor['nome']) ?></div>
                <small class="text-muted">Raiz selecionada</small>
            </div>
            <?php
            $vr = [];
            renderRede($id, $filhos, $porId, 0, $vr);
            if (empty($filhos[$id])) {
                echo "<div class='alert alert-light border mt-3 mb-0'>Esta pessoa ainda não possui indicados diretos.</div>";
            }
            ?>
        </div>
    </div>
</div>

<div class="tab-pane fade" id="familia">
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3"><i class="fas fa-people-roof text-success me-2"></i>Núcleo familiar</h5>

                    <?php if($familiares): ?>
                        <div class="row g-3">
                        <?php foreach($familiares as $f): ?>
                            <div class="col-md-6">
                                <div class="family-card bg-white border rounded-4 p-3 h-100">
                                    <div class="small text-success fw-bold text-uppercase"><?= h(parentescoLabel($f['parentesco'])) ?></div>
                                    <div class="fw-bold fs-5"><?= h($f['nome']) ?></div>
                                    <small class="text-muted"><?= h($f['bairro'] ?? '') ?></small>
                                    <div class="mt-3 d-flex gap-2">
                                        <a class="btn btn-sm btn-outline-primary rounded-pill" href="admin_arvore_eleitor.php?id=<?= (int)$f['familiar_id'] ?>">Ver cadastro</a>
                                        <a class="btn btn-sm btn-outline-danger rounded-pill"
                                           href="admin_arvore_eleitor.php?id=<?= $id ?>&remover_familiar=<?= (int)$f['familiar_id'] ?>"
                                           onclick="return confirm('Remover este vínculo familiar?')">Remover</a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-light border">Nenhum familiar vinculado ainda.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <h5 class="fw-bold">Vincular familiar</h5>
                    <p class="text-muted small">O familiar precisa existir na Base de Apoio. A pessoa não é duplicada; apenas criamos o vínculo.</p>

                    <form method="post">
                        <input type="hidden" name="acao" value="vincular_familiar">
                        <input type="hidden" name="eleitor_id" value="<?= $id ?>">

                        <label class="form-label fw-bold small">Pessoa</label>
                        <select name="familiar_id" class="form-select mb-3" required>
                            <option value="">Selecione...</option>
                            <?php foreach($disponiveis as $d): ?>
                                <option value="<?= (int)$d['id'] ?>"><?= h($d['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <label class="form-label fw-bold small">Parentesco desta pessoa com <?= h($eleitor['nome']) ?></label>
                        <select name="parentesco" class="form-select mb-3" required>
                            <option value="conjuge">Cônjuge</option>
                            <option value="pai">Pai</option>
                            <option value="mae">Mãe</option>
                            <option value="filho">Filho</option>
                            <option value="filha">Filha</option>
                            <option value="irmao">Irmão</option>
                            <option value="irma">Irmã</option>
                            <option value="outro">Outro familiar</option>
                        </select>

                        <button class="btn btn-success rounded-pill px-4 fw-bold">
                            <i class="fas fa-link me-1"></i> Vincular familiar
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
