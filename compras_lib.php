<?php
if (!defined('TENANT_ID')) {
    die('Acesso restrito.');
}

function compras_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function compras_usuario_id(): ?int {
    foreach (['id', 'usuario_id', 'user_id'] as $k) {
        if (!empty($_SESSION[$k])) return (int)$_SESSION[$k];
    }
    return null;
}

function compras_nivel(): string {
    return strtolower((string)($_SESSION['nivel'] ?? $_SESSION['tipo'] ?? ''));
}

function compras_pode(string $acao): bool {
    $nivel = compras_nivel();
    if (in_array($nivel, ['master','admin'], true)) return true;

    // Permissão granular nova.
    if (function_exists('usuario_pode') && usuario_pode('compras_' . $acao)) {
        return true;
    }

    // Compatibilidade: quem já tinha compras_aprovar pode aprovar qualquer nível.
    if (str_starts_with($acao, 'aprovar_n') && function_exists('usuario_pode') && usuario_pode('compras_aprovar')) {
        return true;
    }

    if (function_exists('tem_acesso')) {
        if (tem_acesso('master')) return true;
        if (tem_acesso('compras')) return true;
        if ($acao === 'ver' && tem_acesso('financeiro')) return true;
    }

    return false;
}

function compras_status_label(string $status): string {
    $map = [
        'rascunho' => 'Rascunho',
        'solicitada' => 'Solicitada',
        'em_aprovacao' => 'Em aprovação',
        'aprovada' => 'Aprovada',
        'recusada' => 'Recusada',
        'comprada' => 'Comprada',
        'aguardando_pagamento' => 'Aguardando pagamento',
        'paga' => 'Paga',
        'concluida' => 'Concluída',
        'cancelada' => 'Cancelada',
    ];
    return $map[$status] ?? ucfirst(str_replace('_',' ', $status));
}

function compras_status_class(string $status): string {
    return match ($status) {
        'solicitada', 'em_aprovacao' => 'warning text-dark',
        'aprovada' => 'primary',
        'comprada', 'aguardando_pagamento' => 'info text-dark',
        'paga', 'concluida' => 'success',
        'recusada', 'cancelada' => 'danger',
        default => 'secondary',
    };
}

function compras_moeda($valor): string {
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function compras_config(PDO $pdo): array {
    $pdo->prepare("INSERT IGNORE INTO compras_config (tenant_id) VALUES (?)")->execute([TENANT_ID]);
    $st = $pdo->prepare("SELECT * FROM compras_config WHERE tenant_id=?");
    $st->execute([TENANT_ID]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [
        'limite_coordenador'=>500,
        'limite_financeiro'=>2000,
        'exigir_aprovacao'=>1,
        'gerar_financeiro_automatico'=>1
    ];
}

function compras_registrar_historico(PDO $pdo, int $compraId, string $acao, ?string $anterior=null, ?string $novo=null, ?string $obs=null): void {
    $st = $pdo->prepare("INSERT INTO compras_historico
        (tenant_id, compra_id, usuario_id, acao, status_anterior, status_novo, observacao)
        VALUES (?, ?, ?, ?, ?, ?, ?)");
    $st->execute([TENANT_ID, $compraId, compras_usuario_id(), $acao, $anterior, $novo, $obs]);
}

function compras_upload(string $campo, string $prefixo): ?string {
    if (empty($_FILES[$campo]) || $_FILES[$campo]['error'] !== UPLOAD_ERR_OK) return null;

    $ext = strtolower(pathinfo($_FILES[$campo]['name'], PATHINFO_EXTENSION));
    $permitidas = ['pdf','jpg','jpeg','png','webp'];
    if (!in_array($ext, $permitidas, true)) {
        throw new Exception('Arquivo inválido. Envie PDF, JPG, PNG ou WEBP.');
    }

    if ((int)$_FILES[$campo]['size'] > 10 * 1024 * 1024) {
        throw new Exception('O arquivo excede 10 MB.');
    }

    $dirRel = 'uploads/compras/';
    $dirAbs = __DIR__ . '/' . $dirRel;
    if (!is_dir($dirAbs) && !mkdir($dirAbs, 0755, true) && !is_dir($dirAbs)) {
        throw new Exception('Não foi possível criar a pasta uploads/compras.');
    }

    $nome = TENANT_ID . '_' . $prefixo . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$campo]['tmp_name'], $dirAbs . $nome)) {
        throw new Exception('Falha ao salvar o arquivo.');
    }

    return $dirRel . $nome;
}

function compras_buscar(PDO $pdo, int $id): array {
    $sql = "SELECT c.*,
                   s.nome AS solicitante_nome,
                   a.nome AS aprovador_nome,
                   cp.nome AS comprador_nome,
                   rc.nome AS responsavel_compra_nome,
                   f.nome AS fornecedor_nome,
                   f.documento AS fornecedor_documento
            FROM compras c
            LEFT JOIN usuarios s ON s.id=c.solicitante_id AND s.tenant_id=c.tenant_id
            LEFT JOIN usuarios a ON a.id=c.aprovador_id AND a.tenant_id=c.tenant_id
            LEFT JOIN usuarios cp ON cp.id=c.comprador_id AND cp.tenant_id=c.tenant_id
            LEFT JOIN usuarios rc ON rc.id=c.responsavel_compra_id AND rc.tenant_id=c.tenant_id
            LEFT JOIN compras_fornecedores f ON f.id=c.fornecedor_id AND f.tenant_id=c.tenant_id
            WHERE c.id=? AND c.tenant_id=? LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$id, TENANT_ID]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('Compra não encontrada.');
    return $row;
}

function compras_criar_lancamento_financeiro(PDO $pdo, int $compraId): ?int {
    $compra = compras_buscar($pdo, $compraId);

    if (!empty($compra['prestacao_contas_id'])) {
        return (int)$compra['prestacao_contas_id'];
    }

    $valor = (float)($compra['valor_final'] ?: $compra['valor_estimado']);
    if ($valor <= 0) return null;

    $descricao = 'Compra #' . $compraId . ' - ' . $compra['titulo'];
    if (!empty($compra['fornecedor_nome'])) {
        $descricao .= ' - ' . $compra['fornecedor_nome'];
    }

    $data = $compra['data_compra'] ?: date('Y-m-d');
    $arquivo = null;
    if (!empty($compra['arquivo_comprovante'])) $arquivo = basename($compra['arquivo_comprovante']);
    elseif (!empty($compra['arquivo_nota'])) $arquivo = basename($compra['arquivo_nota']);

    // A tabela prestacao_contas já existe no sistema.
    $st = $pdo->prepare("INSERT INTO prestacao_contas
        (tenant_id, tipo, natureza, descricao, valor, data_vencimento, status, observacoes, arquivo_comprovante)
        VALUES (?, 'Despesa', 'Variável', ?, ?, ?, 'Pendente', ?, ?)");
    $obs = 'Gerado automaticamente pelo módulo Compras. Compra #' . $compraId;
    $st->execute([TENANT_ID, $descricao, $valor, $data, $obs, $arquivo]);

    $financeiroId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE compras SET prestacao_contas_id=?, status='aguardando_pagamento' WHERE id=? AND tenant_id=?")
        ->execute([$financeiroId, $compraId, TENANT_ID]);

    compras_registrar_historico($pdo, $compraId, 'financeiro_criado', $compra['status'], 'aguardando_pagamento', 'Lançamento financeiro #' . $financeiroId);
    return $financeiroId;
}

function compras_total_niveis_por_valor(PDO $pdo, float $valor): int {
    $cfg = compras_config($pdo);
    $lim1 = (float)($cfg['limite_coordenador'] ?? 500);
    $lim2 = (float)($cfg['limite_financeiro'] ?? 2000);

    if ($valor <= $lim1) return 1;
    if ($valor <= $lim2) return 2;
    return 3;
}

function compras_nome_nivel(PDO $pdo, int $nivel): string {
    $cfg = compras_config($pdo);
    return match ($nivel) {
        1 => (string)($cfg['nome_nivel_1'] ?? 'Coordenação'),
        2 => (string)($cfg['nome_nivel_2'] ?? 'Financeiro'),
        3 => (string)($cfg['nome_nivel_3'] ?? 'Administração'),
        default => 'Nível ' . $nivel,
    };
}

function compras_inicializar_aprovacoes(PDO $pdo, int $compraId, float $valor): int {
    $total = compras_total_niveis_por_valor($pdo, $valor);
    $cfg = compras_config($pdo);

    $previstos = [
        1 => !empty($cfg['aprovador_n1_id']) ? (int)$cfg['aprovador_n1_id'] : null,
        2 => !empty($cfg['aprovador_n2_id']) ? (int)$cfg['aprovador_n2_id'] : null,
        3 => !empty($cfg['aprovador_n3_id']) ? (int)$cfg['aprovador_n3_id'] : null,
    ];

    $pdo->prepare("DELETE FROM compras_aprovacoes WHERE tenant_id=? AND compra_id=?")
        ->execute([TENANT_ID, $compraId]);

    $st = $pdo->prepare("INSERT INTO compras_aprovacoes
        (tenant_id, compra_id, nivel, aprovador_previsto_id, status)
        VALUES (?, ?, ?, ?, 'pendente')");

    for ($n=1; $n <= $total; $n++) {
        $st->execute([TENANT_ID, $compraId, $n, $previstos[$n]]);
    }

    $pdo->prepare("UPDATE compras
                   SET status='em_aprovacao',
                       nivel_aprovacao_atual=1,
                       niveis_aprovacao_total=?
                   WHERE id=? AND tenant_id=?")
        ->execute([$total, $compraId, TENANT_ID]);

    return $total;
}

function compras_aprovacoes(PDO $pdo, int $compraId): array {
    $st = $pdo->prepare("SELECT ca.*, u.nome AS usuario_nome
                        FROM compras_aprovacoes ca
                        LEFT JOIN usuarios u ON u.id=ca.usuario_id AND u.tenant_id=ca.tenant_id
                        WHERE ca.tenant_id=? AND ca.compra_id=?
                        ORDER BY ca.nivel ASC");
    $st->execute([TENANT_ID, $compraId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function compras_aprovar_nivel(PDO $pdo, int $compraId, int $nivel, ?string $observacao=null): array {
    $compra = compras_buscar($pdo, $compraId);

    if ($compra['status'] !== 'em_aprovacao') {
        throw new Exception('Esta compra não está aguardando aprovação.');
    }

    $atual = (int)($compra['nivel_aprovacao_atual'] ?? 1);
    $total = (int)($compra['niveis_aprovacao_total'] ?? 1);

    if ($nivel !== $atual) {
        throw new Exception('Este nível ainda não é o próximo da fila.');
    }

    $stPrev = $pdo->prepare("SELECT aprovador_previsto_id FROM compras_aprovacoes
                             WHERE tenant_id=? AND compra_id=? AND nivel=? LIMIT 1");
    $stPrev->execute([TENANT_ID, $compraId, $nivel]);
    $previsto = $stPrev->fetchColumn();

    $uid = compras_usuario_id();
    $eAdmin = in_array(compras_nivel(), ['master','admin'], true);
    $temPermissaoNivel = compras_pode('aprovar_n' . $nivel);

    if (!$eAdmin && !$temPermissaoNivel && (!$previsto || (int)$previsto !== (int)$uid)) {
        throw new Exception('Esta aprovação não está atribuída a você.');
    }

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("UPDATE compras_aprovacoes
                            SET status='aprovada', usuario_id=?, observacao=?, decidido_em=NOW()
                            WHERE tenant_id=? AND compra_id=? AND nivel=? AND status='pendente'");
        $st->execute([compras_usuario_id(), $observacao, TENANT_ID, $compraId, $nivel]);

        if ($st->rowCount() !== 1) {
            throw new Exception('A aprovação já foi decidida ou não foi encontrada.');
        }

        if ($nivel >= $total) {
            $pdo->prepare("UPDATE compras
                           SET status='aprovada', aprovador_id=?, data_aprovacao=NOW()
                           WHERE id=? AND tenant_id=?")
                ->execute([compras_usuario_id(), $compraId, TENANT_ID]);
            $novoStatus = 'aprovada';
            compras_atribuir_comprador_padrao($pdo, $compraId);
        } else {
            $pdo->prepare("UPDATE compras
                           SET nivel_aprovacao_atual=?
                           WHERE id=? AND tenant_id=?")
                ->execute([$nivel + 1, $compraId, TENANT_ID]);
            $novoStatus = 'em_aprovacao';
        }

        compras_registrar_historico(
            $pdo,
            $compraId,
            'aprovacao_nivel_' . $nivel,
            'em_aprovacao',
            $novoStatus,
            compras_nome_nivel($pdo, $nivel) . ($observacao ? ': ' . $observacao : '')
        );

        $pdo->commit();
        return ['finalizada' => $nivel >= $total, 'proximo_nivel' => $nivel < $total ? $nivel + 1 : null];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function compras_recusar_nivel(PDO $pdo, int $compraId, int $nivel, string $motivo): void {
    $stPrev = $pdo->prepare("SELECT aprovador_previsto_id FROM compras_aprovacoes
                             WHERE tenant_id=? AND compra_id=? AND nivel=? LIMIT 1");
    $stPrev->execute([TENANT_ID, $compraId, $nivel]);
    $previsto = $stPrev->fetchColumn();

    $uid = compras_usuario_id();
    $eAdmin = in_array(compras_nivel(), ['master','admin'], true);
    $temPermissaoNivel = compras_pode('aprovar_n' . $nivel);

    if (!$eAdmin && !$temPermissaoNivel && (!$previsto || (int)$previsto !== (int)$uid)) {
        throw new Exception('Esta aprovação não está atribuída a você.');
    }

    $compra = compras_buscar($pdo, $compraId);
    $atual = (int)($compra['nivel_aprovacao_atual'] ?? 1);
    if ($compra['status'] !== 'em_aprovacao' || $nivel !== $atual) {
        throw new Exception('Este nível não está disponível para decisão.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE compras_aprovacoes
                       SET status='recusada', usuario_id=?, observacao=?, decidido_em=NOW()
                       WHERE tenant_id=? AND compra_id=? AND nivel=? AND status='pendente'")
            ->execute([compras_usuario_id(), $motivo, TENANT_ID, $compraId, $nivel]);

        $pdo->prepare("UPDATE compras
                       SET status='recusada', aprovador_id=?, data_aprovacao=NOW(), motivo_recusa=?
                       WHERE id=? AND tenant_id=?")
            ->execute([compras_usuario_id(), $motivo, $compraId, TENANT_ID]);

        compras_registrar_historico(
            $pdo, $compraId, 'recusa_nivel_' . $nivel, 'em_aprovacao', 'recusada',
            compras_nome_nivel($pdo, $nivel) . ': ' . $motivo
        );

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}


function compras_atribuir_comprador_padrao(PDO $pdo, int $compraId): void {
    $cfg = compras_config($pdo);
    $comprador = !empty($cfg['comprador_padrao_id']) ? (int)$cfg['comprador_padrao_id'] : null;

    if ($comprador) {
        $pdo->prepare("UPDATE compras SET responsavel_compra_id=? WHERE id=? AND tenant_id=?")
            ->execute([$comprador, $compraId, TENANT_ID]);
        compras_registrar_historico($pdo, $compraId, 'atribuicao_compra', 'aprovada', 'aprovada', 'Compra atribuída ao responsável padrão.');
    }
}
