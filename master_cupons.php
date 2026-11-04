<?php
// --- CONFIGURAÇÕES INICIAIS ---
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require 'config.php';
// Adicione aqui a sua verificação de segurança do Master (ex: require 'protecao_master.php'; se existir)

// --- 1. CRIAÇÃO AUTOMÁTICA DA TABELA ---
// Garante que a tabela existe sem precisar de ir ao phpMyAdmin
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS cupons (
        id INT AUTO_INCREMENT PRIMARY KEY,
        codigo VARCHAR(50) NOT NULL UNIQUE,
        desconto_porcento INT NOT NULL,
        limite_usos INT DEFAULT 10,
        usos_atuais INT DEFAULT 0,
        ativo TINYINT(1) DEFAULT 1,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {
    die("Erro ao verificar a tabela de cupons: " . $e->getMessage());
}

$msg_alerta = "";

// --- 2. PROCESSAMENTO DO FORMULÁRIO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['acao']) && $_POST['acao'] === 'criar') {
        $codigo = strtoupper(trim(preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['codigo'])));
        $desconto = (int)$_POST['desconto_porcento'];
        $limite = (int)$_POST['limite_usos'];

        if (empty($codigo) || $desconto <= 0 || $limite <= 0) {
            $msg_alerta = "<div class='alert alert-warning'>Por favor, preencha todos os campos corretamente.</div>";
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO cupons (codigo, desconto_porcento, limite_usos) VALUES (?, ?, ?)");
                $stmt->execute([$codigo, $desconto, $limite]);
                $msg_alerta = "<div class='alert alert-success fw-bold'><i class='fas fa-check-circle'></i> Cupom '$codigo' criado com sucesso!</div>";
            } catch (Exception $e) {
                $msg_alerta = "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle'></i> Erro: O código '$codigo' já existe na base de dados.</div>";
            }
        }
    } elseif (isset($_POST['acao']) && $_POST['acao'] === 'excluir') {
        $id_cupom = (int)$_POST['id_cupom'];
        try {
            $pdo->prepare("DELETE FROM cupons WHERE id = ?")->execute([$id_cupom]);
            $msg_alerta = "<div class='alert alert-success'>Cupom eliminado com sucesso.</div>";
        } catch (Exception $e) {
            $msg_alerta = "<div class='alert alert-danger'>Erro ao eliminar o Cupom.</div>";
        }
    }
}

// --- 3. BUSCA DOS cupons EXISTENTES ---
try {
    $stmt = $pdo->query("SELECT * FROM cupons ORDER BY id DESC");
    $lista_cupons = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $lista_cupons = [];
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Master - Gestão de cupons</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f3f4f6; font-family: 'Inter', sans-serif; }
        .card-custom { border: none; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); }
        .navbar-master { background: #1a2b49; color: white; }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-master py-3 mb-4">
        <div class="container">
            <span class="navbar-brand text-white fw-bold"><i class="fas fa-crown text-warning me-2"></i> Painel Master</span>
            <a href="master_painel.php" class="btn btn-outline-light btn-sm rounded-pill px-3">Voltar ao Início</a>
        </div>
    </nav>

    <div class="container pb-5">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold mb-0 text-dark">Gestão de cupons</h3>
                <p class="text-muted small">Crie links de desconto para Câmaras e parcerias.</p>
            </div>
        </div>

        <?= $msg_alerta ?>

        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card card-custom h-100">
                    <div class="card-header bg-white border-bottom-0 pt-4 pb-0">
                        <h6 class="fw-bold text-primary"><i class="fas fa-plus-circle me-2"></i>Novo Cupom</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="acao" value="criar">
                            
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted">CÓDIGO DO Cupom (Sem espaços)</label>
                                <input type="text" name="codigo" class="form-control text-uppercase fw-bold" placeholder="EX: CAMARARN50" required>
                                <small class="text-muted" style="font-size: 11px;">O cliente vai usar este código na URL.</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted">DESCONTO (%)</label>
                                <div class="input-group">
                                    <input type="number" name="desconto_porcento" class="form-control" min="1" max="100" placeholder="Ex: 30" required>
                                    <span class="input-group-text bg-light">%</span>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label small fw-bold text-muted">LIMITE DE UTILIZAÇÕES</label>
                                <input type="number" name="limite_usos" class="form-control" min="1" placeholder="Ex: 10" required>
                                <small class="text-muted" style="font-size: 11px;">Quantas contas podem ser criadas com este cupom?</small>
                            </div>

                            <button type="submit" class="btn btn-primary w-100 fw-bold py-2 rounded-pill">GERAR Cupom</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card card-custom h-100">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="text-secondary small fw-bold py-3 ps-4">CÓDIGO</th>
                                        <th class="text-secondary small fw-bold py-3">DESCONTO</th>
                                        <th class="text-secondary small fw-bold py-3">UTILIZAÇÕES</th>
                                        <th class="text-secondary small fw-bold py-3">STATUS</th>
                                        <th class="text-secondary small fw-bold py-3 pe-4 text-end">AÇÃO</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($lista_cupons)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-5 text-muted">
                                                <i class="fas fa-ticket-alt fa-3x mb-3 opacity-25"></i><br>
                                                Nenhum Cupom criado ainda.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach($lista_cupons as $c): 
                                            $esgotado = ($c['usos_atuais'] >= $c['limite_usos']);
                                            $badge_status = $esgotado ? '<span class="badge bg-danger">Esgotado</span>' : '<span class="badge bg-success">Ativo</span>';
                                        ?>
                                        <tr>
                                            <td class="ps-4">
                                                <strong class="text-primary"><?= htmlspecialchars($c['codigo']) ?></strong><br>
                                                <small class="text-muted" style="font-size: 11px;" title="Clique para copiar link" style="cursor:pointer;" onclick="copiarLink('<?= htmlspecialchars($c['codigo']) ?>')">
                                                    <i class="far fa-copy"></i> Copiar Link
                                                </small>
                                            </td>
                                            <td><span class="fw-bold fs-5"><?= $c['desconto_porcento'] ?>%</span></td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="progress flex-grow-1" style="height: 8px;">
                                                        <?php 
                                                            $percent = ($c['limite_usos'] > 0) ? ($c['usos_atuais'] / $c['limite_usos']) * 100 : 0;
                                                            $cor_barra = $esgotado ? 'bg-danger' : 'bg-primary';
                                                        ?>
                                                        <div class="progress-bar <?= $cor_barra ?>" style="width: <?= $percent ?>%"></div>
                                                    </div>
                                                    <span class="small fw-bold text-muted"><?= $c['usos_atuais'] ?>/<?= $c['limite_usos'] ?></span>
                                                </div>
                                            </td>
                                            <td><?= $badge_status ?></td>
                                            <td class="pe-4 text-end">
                                                <form method="POST" onsubmit="return confirm('Tem a certeza que deseja eliminar este cupom? Os clientes que já o usaram não serão afetados.');">
                                                    <input type="hidden" name="acao" value="excluir">
                                                    <input type="hidden" name="id_cupom" value="<?= $c['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash-alt"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function copiarLink(codigo) {
            // Ajuste aqui com o seu domínio real
            const url = window.location.origin + '/index.php?cupom=' + codigo;
            navigator.clipboard.writeText(url).then(() => {
                alert("Link mágico copiado!\n\n" + url + "\n\nEnvie isto para o cliente.");
            });
        }
    </script>
</body>
</html>