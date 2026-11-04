<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require 'config.php';
require 'protecao.php';

if (!defined('TENANT_ID')) {
    die('Acesso negado.');
}

$nivel = strtolower((string)($_SESSION['nivel'] ?? ''));

if (!in_array($nivel, ['admin', 'master'], true)) {
    http_response_code(403);
    die('Você não possui permissão para editar esta demanda.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: tarefas_listar.php');
    exit;
}

$tarefaId = (int)($_POST['tarefa_id'] ?? 0);

$titulo = trim((string)($_POST['titulo'] ?? ''));
$descricao = trim((string)($_POST['descricao'] ?? ''));
$prazo = trim((string)($_POST['prazo'] ?? ''));
$prioridade = strtolower(
    trim((string)($_POST['prioridade'] ?? 'media'))
);

if ($tarefaId <= 0) {
    die('Tarefa inválida.');
}

if ($titulo === '') {
    die('Informe o título.');
}

if ($prazo === '') {
    die('Informe o prazo.');
}

if (!in_array(
    $prioridade,
    ['baixa', 'media', 'alta'],
    true
)) {
    $prioridade = 'media';
}

try {

    $stmt = $pdo->prepare("
        UPDATE tarefas
        SET
            titulo = ?,
            descricao = ?,
            prazo = ?,
            prioridade = ?
        WHERE id = ?
        AND tenant_id = ?
    ");

    $stmt->execute([
        $titulo,
        $descricao,
        $prazo,
        $prioridade,
        $tarefaId,
        (int)TENANT_ID
    ]);

    header('Location: tarefas_listar.php');
    exit;

} catch (Throwable $e) {

    http_response_code(500);

    die(
        'Erro ao salvar alterações: ' .
        htmlspecialchars($e->getMessage())
    );
}