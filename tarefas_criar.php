<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require 'config.php';
require 'protecao.php';

if (!defined('TENANT_ID')) {
    die('Erro de identificação do cliente.');
}

$nivel_usuario = strtolower((string)($_SESSION['nivel'] ?? ''));

if (!in_array($nivel_usuario, ['admin', 'master'], true)) {
    header('Location: tarefas_listar.php');
    exit;
}

$mensagem = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        $responsaveis = $_POST['responsaveis_id'] ?? [];

        if (!is_array($responsaveis)) {
            $responsaveis = [];
        }

        $responsaveis = array_values(
            array_unique(
                array_filter(
                    array_map('intval', $responsaveis)
                )
            )
        );

        if (!$responsaveis) {
            throw new Exception('Selecione pelo menos um responsável.');
        }

        $titulo = trim((string)($_POST['titulo'] ?? ''));
        $descricao = trim((string)($_POST['descricao'] ?? ''));
        $prazo = trim((string)($_POST['prazo'] ?? ''));
        $prioridade = strtolower((string)($_POST['prioridade'] ?? 'media'));

        if ($titulo === '') {
            throw new Exception('Informe o título da demanda.');
        }

        if ($prazo === '') {
            throw new Exception('Informe o prazo.');
        }

        if (!in_array($prioridade, ['baixa', 'media', 'alta'], true)) {
            $prioridade = 'media';
        }

        /*
         * Confirma que TODOS os responsáveis pertencem
         * ao tenant atual.
         */
        $placeholders = implode(',', array_fill(0, count($responsaveis), '?'));

        $sqlUsuarios = "
            SELECT id
            FROM usuarios
            WHERE tenant_id = ?
            AND id IN ($placeholders)
        ";

        $paramsUsuarios = array_merge(
            [(int)TENANT_ID],
            $responsaveis
        );

        $stmtUsuarios = $pdo->prepare($sqlUsuarios);
        $stmtUsuarios->execute($paramsUsuarios);

        $usuariosValidos = array_map(
            'intval',
            $stmtUsuarios->fetchAll(PDO::FETCH_COLUMN)
        );

        sort($usuariosValidos);
        $comparacao = $responsaveis;
        sort($comparacao);

        if ($usuariosValidos !== $comparacao) {
            throw new Exception('Um ou mais responsáveis selecionados são inválidos.');
        }

        $pdo->beginTransaction();

        /*
         * Mantemos responsavel_id preenchido com o primeiro
         * responsável para compatibilidade com páginas antigas.
         */
        $responsavelPrincipal = $responsaveis[0];

        $stmt = $pdo->prepare("
            INSERT INTO tarefas (
                tenant_id,
                responsavel_id,
                criado_por_id,
                titulo,
                descricao,
                prazo,
                status,
                prioridade
            )
            VALUES (?, ?, ?, ?, ?, ?, 'pendente', ?)
        ");

        $stmt->execute([
            (int)TENANT_ID,
            $responsavelPrincipal,
            (int)($_SESSION['id'] ?? 0),
            $titulo,
            $descricao,
            $prazo,
            $prioridade
        ]);

        $tarefaId = (int)$pdo->lastInsertId();

        $stmtResp = $pdo->prepare("
            INSERT INTO tarefas_responsaveis
            (tarefa_id, usuario_id)
            VALUES (?, ?)
        ");

        foreach ($responsaveis as $usuarioId) {
            $stmtResp->execute([
                $tarefaId,
                $usuarioId
            ]);
        }

        $pdo->commit();

        $quantidade = count($responsaveis);

        $mensagem = '
            <div class="alert alert-success shadow-sm border-0">
                <i class="fas fa-check-circle me-2"></i>
                Demanda criada para <strong>'.$quantidade.'</strong>
                responsável'.($quantidade > 1 ? 'eis' : '').'.
                <a href="tarefas_listar.php"
                   class="fw-bold text-success text-decoration-none ms-2">
                    Ir para o Kanban
                </a>
            </div>
        ';

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $mensagem = '
            <div class="alert alert-danger">
                <strong>Erro:</strong> '
                .htmlspecialchars($e->getMessage()).
            '</div>
        ';
    }
}

$equipe = $pdo->prepare("
    SELECT id, nome
    FROM usuarios
    WHERE tenant_id = ?
    ORDER BY nome ASC
");

$equipe->execute([(int)TENANT_ID]);
$usuariosEquipe = $equipe->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>

<meta charset="UTF-8">

<title>Nova Demanda</title>

<meta name="viewport"
content="width=device-width, initial-scale=1.0">

<link
href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
rel="stylesheet">

<link
rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<link
href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap"
rel="stylesheet">

<style>

body {
    background:#f3f4f6;
    font-family:'Inter',sans-serif;
}

.card-custom {
    border:0;
    border-radius:16px;
    box-shadow:0 5px 20px rgba(0,0,0,.07);
}

.caixa-equipe {
    border:1px solid #dee2e6;
    border-radius:10px;
    max-height:220px;
    overflow-y:auto;
    background:white;
    padding:12px;
}

.usuario-item {
    padding:8px;
    border-radius:8px;
}

.usuario-item:hover {
    background:#f3f6ff;
}

</style>

</head>

<body class="p-4">

<div class="container" style="max-width:850px;">

<div class="d-flex justify-content-between align-items-center mb-4">

<h4 class="fw-bold m-0">
<i class="fas fa-plus-circle text-primary me-2"></i>
Atribuir Nova Demanda
</h4>

<a href="tarefas_listar.php"
class="btn btn-outline-secondary rounded-pill fw-bold">
<i class="fas fa-arrow-left me-1"></i>
Voltar
</a>

</div>

<?= $mensagem ?>

<div class="card card-custom p-4">

<form method="POST" id="formTarefa">

<div class="mb-3">

<label class="form-label fw-bold">
O que precisa ser feito?
</label>

<input
type="text"
name="titulo"
class="form-control form-control-lg"
required>

</div>

<div class="mb-4">

<label class="form-label fw-bold">
Detalhes da tarefa
</label>

<textarea
name="descricao"
class="form-control"
rows="4"></textarea>

</div>

<div class="row g-3">

<div class="col-md-5">

<label class="form-label fw-bold">
Responsáveis
<span class="badge bg-primary">
Pode selecionar vários
</span>
</label>

<div class="caixa-equipe">

<?php if (!$usuariosEquipe): ?>

<p class="text-muted text-center">
Nenhum usuário encontrado.
</p>

<?php endif; ?>

<?php foreach ($usuariosEquipe as $user): ?>

<div class="form-check usuario-item">

<input
class="form-check-input chk-responsavel"
type="checkbox"
name="responsaveis_id[]"
value="<?= (int)$user['id'] ?>"
id="user_<?= (int)$user['id'] ?>">

<label
class="form-check-label fw-bold"
for="user_<?= (int)$user['id'] ?>">

<?= htmlspecialchars($user['nome']) ?>

</label>

</div>

<?php endforeach; ?>

</div>

<div id="erro-resp"
class="text-danger fw-bold small mt-2 d-none">

Selecione pelo menos uma pessoa.

</div>

</div>

<div class="col-md-4">

<label class="form-label fw-bold">
Prazo
</label>

<input
type="datetime-local"
name="prazo"
class="form-control"
required>

</div>

<div class="col-md-3">

<label class="form-label fw-bold">
Prioridade
</label>

<select
name="prioridade"
class="form-select">

<option value="baixa">🔵 Baixa</option>
<option value="media" selected>🟡 Média</option>
<option value="alta">🔴 Alta</option>

</select>

</div>

</div>

<hr class="my-4">

<div class="text-end">

<button
type="submit"
class="btn btn-primary btn-lg rounded-pill fw-bold px-5">

<i class="fas fa-paper-plane me-2"></i>
Delegar Demanda

</button>

</div>

</form>

</div>

</div>

<script>

document
.getElementById('formTarefa')
.addEventListener('submit', function(event) {

    const selecionados =
        document.querySelectorAll(
            '.chk-responsavel:checked'
        );

    const erro =
        document.getElementById('erro-resp');

    if (selecionados.length === 0) {

        event.preventDefault();

        erro.classList.remove('d-none');

    } else {

        erro.classList.add('d-none');

    }

});

</script>

</body>
</html>