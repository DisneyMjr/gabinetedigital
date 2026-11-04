<?php
require 'config.php';   // 1º O Cérebro (Define quem é o cliente)
require 'protecao.php'; // 2º O Porteiro (Verifica se pode entrar

// --- 0. SEGURANÇA SAAS ---
if (!defined('TENANT_ID')) {
    die("Erro Crítico: Tenant não identificado.");
}

// Verifica Permissão
if (!tem_acesso('auditoria') && !tem_acesso('master')) { die("Acesso Negado."); }

// --- 1. BUSCA LOGS (FILTRADO PELO TENANT) ---
// Antes: SELECT * FROM auditoria...
// Agora: WHERE tenant_id = ?
try {
    $sql = "SELECT * FROM auditoria WHERE tenant_id = ? ORDER BY id DESC LIMIT 100";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([TENANT_ID]);
} catch (PDOException $e) {
    die("Erro ao ler logs: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8"><title>Auditoria do Sistema</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-light p-4">
<div class="container bg-white p-4 rounded shadow-sm">
    <div class="d-flex justify-content-between mb-4 align-items-center">
        <div>
            <h3 class="fw-bold text-secondary mb-0"><i class="fas fa-history"></i> Auditoria</h3>
            <small class="text-muted">Registro de atividades da equipe.</small>
        </div>
        <a href="admin_painel.php" class="btn btn-secondary btn-sm">Voltar</a>
    </div>

    <div class="table-responsive">
        <table class="table table-hover border align-middle text-secondary">
            <thead class="table-light">
                <tr>
                    <th>Ação</th>
                    <th>Usuário</th>
                    <th>Detalhes</th>
                    <th>IP</th>
                    <th class="text-end">Data</th>
                </tr>
            </thead>
            <tbody>
            <?php while ($log = $stmt->fetch(PDO::FETCH_ASSOC)): 
                $cor = 'secondary'; $icone = 'info-circle';
                $acao = strtolower($log['acao']);

                // Cores dinâmicas para facilitar a leitura
                if(str_contains($acao, 'login') || str_contains($acao, 'entrou')) { $cor = 'success'; $icone = 'sign-in-alt'; }
                if(str_contains($acao, 'removeu') || str_contains($acao, 'excluiu')) { $cor = 'danger'; $icone = 'trash-alt'; }
                if(str_contains($acao, 'cadastrou') || str_contains($acao, 'criou')) { $cor = 'primary'; $icone = 'plus-circle'; }
                if(str_contains($acao, 'editou') || str_contains($acao, 'alterou')) { $cor = 'warning text-dark'; $icone = 'edit'; }
            ?>
                <tr>
                    <td>
                        <span class="badge bg-<?= $cor ?> bg-opacity-75 text-white shadow-sm">
                            <i class="fas fa-<?= $icone ?> me-1"></i> <?= $log['acao'] ?>
                        </span>
                    </td>
                    <td class="fw-bold"><?= $log['usuario_nome'] ?></td>
                    <td class="small text-muted text-wrap" style="max-width: 300px;">
                        <?= $log['detalhes'] ?>
                    </td>
                    <td class="small font-monospace"><?= $log['ip'] ?></td>
                    <td class="small fw-bold text-end">
                        <?= date('d/m/y', strtotime($log['data_hora'])) ?> <br>
                        <span class="text-muted fw-normal"><?= date('H:i', strtotime($log['data_hora'])) ?></span>
                    </td>
                </tr>
            <?php endwhile; ?>
            
            <?php if($stmt->rowCount() == 0): ?>
                <tr>
                    <td colspan="5" class="text-center py-4 text-muted">
                        <i class="fas fa-ghost fa-2x mb-2"></i><br>Nenhum registro encontrado.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>