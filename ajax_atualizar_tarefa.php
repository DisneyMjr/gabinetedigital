<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require 'config.php';
require 'protecao.php';

if (!defined('TENANT_ID')) {
    echo json_encode([
        'sucesso' => false,
        'erro' => 'Sessão inválida.'
    ]);
    exit;
}

$raw = file_get_contents('php://input');
$json = json_decode($raw, true);

$dados = is_array($json) ? $json : $_POST;

$tarefaId = (int)($dados['tarefa_id'] ?? 0);
$novoStatus = trim((string)($dados['novo_status'] ?? ''));

$statusPermitidos = [
    'pendente',
    'em_andamento',
    'concluido'
];

if (
    $tarefaId <= 0 ||
    !in_array($novoStatus, $statusPermitidos, true)
) {
    echo json_encode([
        'sucesso' => false,
        'erro' => 'Dados inválidos.'
    ]);
    exit;
}

$usuarioId = (int)($_SESSION['id'] ?? 0);
$nivel = strtolower((string)($_SESSION['nivel'] ?? ''));

$ehAdmin = in_array(
    $nivel,
    ['admin', 'master'],
    true
);

try {

    /*
     * Confirma que a tarefa pertence ao tenant.
     */
    $stmt = $pdo->prepare("
        SELECT id
        FROM tarefas
        WHERE id = ?
        AND tenant_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $tarefaId,
        (int)TENANT_ID
    ]);

    if (!$stmt->fetchColumn()) {

        echo json_encode([
            'sucesso' => false,
            'erro' => 'Tarefa não encontrada.'
        ]);

        exit;
    }

    /*
     * Usuário comum precisa estar entre
     * os responsáveis.
     */
    if (!$ehAdmin) {

        $stmt = $pdo->prepare("
            SELECT 1
            FROM tarefas_responsaveis tr
            INNER JOIN tarefas t
                ON t.id = tr.tarefa_id
            WHERE tr.tarefa_id = ?
            AND tr.usuario_id = ?
            AND t.tenant_id = ?
            LIMIT 1
        ");

        $stmt->execute([
            $tarefaId,
            $usuarioId,
            (int)TENANT_ID
        ]);

        if (!$stmt->fetchColumn()) {

            echo json_encode([
                'sucesso' => false,
                'erro' => 'Você não é responsável por esta demanda.'
            ]);

            exit;
        }
    }

    $stmt = $pdo->prepare("
        UPDATE tarefas
        SET status = ?
        WHERE id = ?
        AND tenant_id = ?
    ");

    $stmt->execute([
        $novoStatus,
        $tarefaId,
        (int)TENANT_ID
    ]);

    echo json_encode([
        'sucesso' => true,
        'status' => $novoStatus
    ]);

} catch (Throwable $e) {

    echo json_encode([
        'sucesso' => false,
        'erro' => 'Erro ao atualizar a demanda.'
    ]);
}

exit;