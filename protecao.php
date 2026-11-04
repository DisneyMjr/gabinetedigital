<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$arquivoAtual = basename(
    (string)parse_url(
        $_SERVER['REQUEST_URI'] ?? '',
        PHP_URL_PATH
    )
);

$paginasPublicas = [
    'login.php',
    'logout.php',
    'renovar_conta.php',
    'assinar.php',
    'webhook_mp.php'
];

/*
|--------------------------------------------------------------------------
| SESSÃO
|--------------------------------------------------------------------------
*/

if (
    empty($_SESSION['id']) ||
    empty($_SESSION['tenant_id'])
) {
    if (!in_array($arquivoAtual, $paginasPublicas, true)) {
        header('Location: /login.php');
        exit;
    }

    return;
}

/*
|--------------------------------------------------------------------------
| TENANT
|--------------------------------------------------------------------------
*/

if (!defined('TENANT_ID')) {
    http_response_code(403);
    exit('Erro crítico: TENANT_ID não identificado.');
}

/*
|--------------------------------------------------------------------------
| MASTER
|--------------------------------------------------------------------------
*/

$permissoes = $_SESSION['permissoes'] ?? [];

if (!is_array($permissoes)) {
    $permissoes = [];
}

$ehMaster = in_array(
    'master',
    $permissoes,
    true
);

/*
|--------------------------------------------------------------------------
| SEGURANÇA CROSS-TENANT
|--------------------------------------------------------------------------
*/

if (
    (string)$_SESSION['tenant_id'] !== (string)TENANT_ID &&
    !$ehMaster
) {
    session_destroy();

    header('Location: /login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| PÁGINAS QUE PODEM SER ACESSADAS MESMO BLOQUEADO
|--------------------------------------------------------------------------
*/

$paginasLiberadas = [
    'renovar_conta.php',
    'assinar.php',
    'logout.php',
    'webhook_mp.php'
];

if (
    in_array(
        $arquivoAtual,
        $paginasLiberadas,
        true
    )
) {
    return;
}

/*
|--------------------------------------------------------------------------
| MASTER NÃO TEM BLOQUEIO FINANCEIRO
|--------------------------------------------------------------------------
*/

if ($ehMaster) {
    return;
}

/*
|--------------------------------------------------------------------------
| PDO
|--------------------------------------------------------------------------
*/

if (
    !isset($pdo) ||
    !($pdo instanceof PDO)
) {
    http_response_code(500);

    exit(
        'Erro interno: conexão com banco de dados indisponível.'
    );
}

/*
|--------------------------------------------------------------------------
| BUSCA TENANT DIRETAMENTE DO BANCO
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        nome,
        status,
        ativo,
        data_vencimento,
        txid_pagamento
    FROM tenants
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([
    (int)TENANT_ID
]);

$tenant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tenant) {
    session_destroy();

    header('Location: /login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| DADOS DO TENANT
|--------------------------------------------------------------------------
*/

$status = strtolower(
    trim(
        (string)(
            $tenant['status'] ?? ''
        )
    )
);

$ativo = (int)(
    $tenant['ativo'] ?? 0
);

$dataVencimento = trim(
    (string)(
        $tenant['data_vencimento'] ?? ''
    )
);

/*
|--------------------------------------------------------------------------
| DATA DE VENCIMENTO
|--------------------------------------------------------------------------
*/

$vencimentoTimestamp = false;

if ($dataVencimento !== '') {
    $vencimentoTimestamp =
        strtotime($dataVencimento);
}

/*
|--------------------------------------------------------------------------
| REGRAS DE BLOQUEIO
|--------------------------------------------------------------------------
*/

$statusBloqueado = in_array(
    $status,
    [
        'bloqueado',
        'inativo',
        'suspenso',
        'cancelado',
        'vencido'
    ],
    true
);

$assinaturaVencida = (
    $vencimentoTimestamp === false ||
    $vencimentoTimestamp <= time()
);

/*
|--------------------------------------------------------------------------
| BLOQUEIA
|--------------------------------------------------------------------------
*/

if (
    $statusBloqueado ||
    $assinaturaVencida
) {
    $motivos = [];

    if ($statusBloqueado) {
        $motivos[] =
            'status=' . $status;
    }

    if ($assinaturaVencida) {
        $motivos[] =
            'vencimento=' . $dataVencimento;
    }

    $_SESSION['conta_bloqueada'] = true;

    $_SESSION['motivo_bloqueio'] =
        implode(
            ' | ',
            $motivos
        );

    /*
    |--------------------------------------------------------------------------
    | LOG
    |--------------------------------------------------------------------------
    */

    $dirLog =
        __DIR__ . '/_logs';

    if (!is_dir($dirLog)) {
        @mkdir(
            $dirLog,
            0755,
            true
        );
    }

    @file_put_contents(
        $dirLog . '/protecao.log',
        '[' .
        date('Y-m-d H:i:s') .
        '] Tenant ' .
        TENANT_ID .
        ' BLOQUEADO: ' .
        implode(
            ' | ',
            $motivos
        ) .
        PHP_EOL,
        FILE_APPEND
    );

    header(
        'Location: /renovar_conta.php'
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| CONTA OK
|--------------------------------------------------------------------------
*/

unset(
    $_SESSION['conta_bloqueada'],
    $_SESSION['motivo_bloqueio']
);