<?php
// robo_mensalidades.php

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

date_default_timezone_set('America/Sao_Paulo');

$diretorioLog = __DIR__ . '/_logs';
$arquivoLog = $diretorioLog . '/robo_mensalidades.log';

if (!is_dir($diretorioLog)) {
    @mkdir($diretorioLog, 0755, true);
}

function logMensalidades(string $mensagem): void
{
    global $arquivoLog;

    @file_put_contents(
        $arquivoLog,
        '[' . date('Y-m-d H:i:s') . '] ' . $mensagem . PHP_EOL,
        FILE_APPEND
    );
}

try {
    /*
    |--------------------------------------------------------------------------
    | Localiza somente contas realmente vencidas
    |--------------------------------------------------------------------------
    */

    $consulta = $pdo->prepare("
        SELECT
            id,
            nome,
            plano,
            status,
            ativo,
            data_vencimento
        FROM tenants
        WHERE
            ativo = 1
            AND data_vencimento IS NOT NULL
            AND data_vencimento < NOW()
            AND plano <> 'master'
            AND status NOT IN ('cancelado', 'bloqueado')
    ");

    $consulta->execute();

    $contasVencidas = $consulta->fetchAll(PDO::FETCH_ASSOC);

    if (!$contasVencidas) {
        $mensagem = 'Robô executado. Nenhuma conta vencida encontrada.';
        logMensalidades($mensagem);

        echo $mensagem;
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Bloqueia individualmente para manter rastreabilidade
    |--------------------------------------------------------------------------
    */

    $pdo->beginTransaction();

    $atualizar = $pdo->prepare("
        UPDATE tenants
        SET
            ativo = 0,
            status = 'pendente_pagamento'
        WHERE
            id = ?
            AND ativo = 1
            AND data_vencimento IS NOT NULL
            AND data_vencimento < NOW()
            AND plano <> 'master'
    ");

    $totalBloqueados = 0;

    foreach ($contasVencidas as $conta) {
        $atualizar->execute([
            (int)$conta['id']
        ]);

        if ($atualizar->rowCount() > 0) {
            $totalBloqueados++;

            logMensalidades(
                'Tenant bloqueado por vencimento: ' .
                'id=' . $conta['id'] .
                ' nome=' . $conta['nome'] .
                ' vencimento=' . $conta['data_vencimento'] .
                ' status_anterior=' . $conta['status'] .
                ' ativo_anterior=' . $conta['ativo']
            );
        }
    }

    $pdo->commit();

    $mensagem =
        'Robô executado com sucesso. Contas bloqueadas: ' .
        $totalBloqueados;

    logMensalidades($mensagem);

    echo $mensagem;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $mensagem = 'Erro no robô de mensalidades: ' . $e->getMessage();

    logMensalidades($mensagem);

    http_response_code(500);
    echo $mensagem;
}