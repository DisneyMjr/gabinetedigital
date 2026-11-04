<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';

header('Content-Type: text/html; charset=utf-8');

$sessionTenantId = (int)($_SESSION['tenant_id'] ?? 0);
$tenantConstante = defined('TENANT_ID') ? (int)TENANT_ID : 0;
$arquivoAtual = basename($_SERVER['PHP_SELF'] ?? '');
$uriAtual = $_SERVER['REQUEST_URI'] ?? '';

$tenantBanco = null;

if (isset($pdo) && $pdo instanceof PDO) {
    $idConsulta = $tenantConstante > 0
        ? $tenantConstante
        : $sessionTenantId;

    if ($idConsulta > 0) {
        $stmt = $pdo->prepare("
            SELECT
                id,
                nome,
                slug,
                status,
                ativo,
                plano,
                data_vencimento,
                txid_pagamento
            FROM tenants
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([$idConsulta]);
        $tenantBanco = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

$status = strtolower(trim((string)($tenantBanco['status'] ?? '')));
$ativo = isset($tenantBanco['ativo'])
    ? (int)$tenantBanco['ativo']
    : null;

$dataVencimento = trim(
    (string)($tenantBanco['data_vencimento'] ?? '')
);

$timestampVencimento = $dataVencimento !== ''
    ? strtotime($dataVencimento)
    : false;

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

$tenantInativo = ($ativo !== null && $ativo !== 1);

$assinaturaVencida = (
    $timestampVencimento === false ||
    $timestampVencimento <= time()
);

$permissoes = $_SESSION['permissoes'] ?? [];

if (!is_array($permissoes)) {
    $permissoes = [];
}

$ehMaster = in_array('master', $permissoes, true);

$motivos = [];

if ($statusBloqueado) {
    $motivos[] = 'status=' . $status;
}

if ($tenantInativo) {
    $motivos[] = 'ativo=' . var_export($ativo, true);
}

if ($assinaturaVencida) {
    $motivos[] = 'vencimento=' . $dataVencimento;
}

if (
    $sessionTenantId > 0 &&
    $tenantConstante > 0 &&
    $sessionTenantId !== $tenantConstante
) {
    $motivos[] =
        'sessão aponta tenant ' .
        $sessionTenantId .
        ', mas TENANT_ID é ' .
        $tenantConstante;
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >
    <title>Diagnóstico do tenant</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            padding: 30px;
        }

        .box {
            max-width: 900px;
            margin: 0 auto;
            background: #fff;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,.08);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        td, th {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
        }

        th {
            width: 260px;
            background: #f2f2f2;
        }

        .ok {
            color: #0a7b34;
            font-weight: bold;
        }

        .erro {
            color: #b00020;
            font-weight: bold;
        }

        pre {
            background: #111;
            color: #eee;
            padding: 15px;
            overflow: auto;
            border-radius: 8px;
        }
    </style>
</head>
<body>

<div class="box">

    <h1>Diagnóstico do tenant</h1>

    <table>
        <tr>
            <th>Horário do servidor</th>
            <td><?= date('Y-m-d H:i:s') ?></td>
        </tr>

        <tr>
            <th>Tenant da sessão</th>
            <td><?= $sessionTenantId ?></td>
        </tr>

        <tr>
            <th>Constante TENANT_ID</th>
            <td><?= $tenantConstante ?></td>
        </tr>

        <tr>
            <th>Arquivo atual</th>
            <td><?= htmlspecialchars($arquivoAtual) ?></td>
        </tr>

        <tr>
            <th>URI atual</th>
            <td><?= htmlspecialchars($uriAtual) ?></td>
        </tr>

        <tr>
            <th>É master?</th>
            <td><?= $ehMaster ? 'SIM' : 'NÃO' ?></td>
        </tr>

        <tr>
            <th>ID no banco</th>
            <td><?= htmlspecialchars((string)($tenantBanco['id'] ?? '')) ?></td>
        </tr>

        <tr>
            <th>Nome</th>
            <td><?= htmlspecialchars((string)($tenantBanco['nome'] ?? '')) ?></td>
        </tr>

        <tr>
            <th>Slug</th>
            <td><?= htmlspecialchars((string)($tenantBanco['slug'] ?? '')) ?></td>
        </tr>

        <tr>
            <th>Status</th>
            <td><?= htmlspecialchars($status) ?></td>
        </tr>

        <tr>
            <th>Ativo</th>
            <td><?= htmlspecialchars(var_export($ativo, true)) ?></td>
        </tr>

        <tr>
            <th>Vencimento</th>
            <td><?= htmlspecialchars($dataVencimento) ?></td>
        </tr>

        <tr>
            <th>Vencimento timestamp</th>
            <td><?= htmlspecialchars(var_export($timestampVencimento, true)) ?></td>
        </tr>

        <tr>
            <th>TXID</th>
            <td><?= htmlspecialchars((string)($tenantBanco['txid_pagamento'] ?? '')) ?></td>
        </tr>

        <tr>
            <th>Plano</th>
            <td><?= htmlspecialchars((string)($tenantBanco['plano'] ?? '')) ?></td>
        </tr>

        <tr>
            <th>Proteção bloquearia?</th>
            <td class="<?= empty($motivos) ? 'ok' : 'erro' ?>">
                <?= empty($motivos) ? 'NÃO' : 'SIM' ?>
            </td>
        </tr>

        <tr>
            <th>Motivos encontrados</th>
            <td>
                <?= empty($motivos)
                    ? 'Nenhum motivo de bloqueio encontrado.'
                    : htmlspecialchars(implode(' | ', $motivos))
                ?>
            </td>
        </tr>
    </table>

    <h3>Sessão atual</h3>

    <pre><?=
        htmlspecialchars(
            print_r($_SESSION, true),
            ENT_QUOTES,
            'UTF-8'
        )
    ?></pre>

</div>

</body>
</html>