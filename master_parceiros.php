<?php
// --- CONFIGURAÇÕES INICIAIS ---
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require 'config.php';
// require 'protecao.php'; // Sua trava de segurança do master, se houver

// ==========================================================
// 1. CRIAÇÃO AUTOMÁTICA DAS TABELAS E COLUNAS
// ==========================================================
try {
    // Cria a tabela de Parceiros Oficiais
    $pdo->exec("CREATE TABLE IF NOT EXISTS parceiros (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(100) NOT NULL,
        codigo_ref VARCHAR(20) NOT NULL UNIQUE,
        comissao_porcento INT NOT NULL DEFAULT 0,
        pix_recebimento VARCHAR(100) NULL,
        ativo TINYINT(1) DEFAULT 1,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Adiciona a coluna para rastrear quem indicou o cliente na tabela tenants
    $colunas_tenants = $pdo->query("DESCRIBE tenants")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('parceiro_id', $colunas_tenants)) {
        $pdo->exec("ALTER TABLE tenants ADD COLUMN parceiro_id INT NULL");
    }
} catch (Exception $e) {
    die("Erro no Banco de Dados: " . $e->getMessage());
}

$msg_alerta = "";

// ==========================================================
// 2. PROCESSAMENTO DO FORMULÁRIO (CRIAR / EXCLUIR)
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['acao']) && $_POST['acao'] === 'criar_parceiro') {
        
        $nome = trim($_POST['nome']);
        $comissao = (int)$_POST['comissao_porcento'];
        $pix = trim($_POST['pix_recebimento']);
        
        // Gera um código de indicação único (Ex: NOME123)
        $codigo_base = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $nome), 0, 5));
        $codigo_ref = $codigo_base . rand(100, 999);

        if (empty($nome) || $comissao <= 0) {
            $msg_alerta = "<div class='alert alert-warning'>Preencha o nome e a comissão (maior que 0).</div>";
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO parceiros (nome, codigo_ref, comissao_porcento, pix_recebimento) VALUES (?, ?, ?, ?)");
                $stmt->execute([$nome, $codigo_ref, $comissao, $pix]);
                $msg_alerta = "<div class='alert alert-success fw-bold'><i class='fas fa-check-circle'></i> Parceiro '$nome' cadastrado! Link gerado com sucesso.</div>";
            } catch (Exception $e) {
                $msg_alerta = "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle'></i> Erro ao cadastrar parceiro.</div>";
            }
        }
    } elseif (isset($_POST['acao']) && $_POST['acao'] === 'excluir_parceiro') {
        $id_parceiro = (int)$_POST['id_parceiro'];
        try {
            // Apenas desativa para não quebrar o histórico financeiro
            $pdo->prepare("UPDATE parceiros SET ativo = 0 WHERE id = ?")->execute([$id_parceiro]);
            $msg_alerta = "<div class='alert alert-success'>Parceiro desativado com sucesso.</div>";
        } catch (Exception $e) {
            $msg_alerta = "<div class='alert alert-danger'>Erro ao desativar parceiro.</div>";
        }
    }
}

// ==========================================================
// 3. BUSCA DOS PARCEIROS E SUAS ASSINATURAS ATIVAS
// ==========================================================
try {
    // Busca os parceiros ativos e conta quantos clientes ATIVOS cada um tem
    $query = "
        SELECT p.*, 
        (SELECT COUNT(id) FROM tenants WHERE parceiro_id = p.id AND status = 'ativo') as assinaturas_ativas
        FROM parceiros p 
        WHERE p.ativo = 1 
        ORDER BY p.id DESC
    ";
    $lista_parceiros = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $lista_parceiros = [];
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Master - Gestão de Parceiros</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f3f4f6; font-family: 'Inter', sans-serif; }
        .card-custom { border: none; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); }
        .navbar-master { background: #1e1b4b; color: white; }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-master py-3 mb-4 shadow-sm">
        <div class="container">
            <span class="navbar-brand text-white fw-bold"><i class="fas fa-crown text-warning me-2"></i> Painel Master</span>
            <div class="d-flex gap-2">
                <a href="master_painel.php" class="btn btn-outline-light btn-sm rounded-pill px-3">Voltar ao Dashboard</a>
            </div>
        </div>
    </nav>

    <div class="container pb-5">
        
        <div class="mb-4">
            <h3 class="fw-bold mb-0 text-dark">Programa de Parceiros (Afiliados)</h3>
            <p class="text-muted small">Cadastre agências, assessores e marqueteiros. Eles ganham comissão recorrente pelas assinaturas.</p>
        </div>

        <?= $msg_alerta ?>

        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card card-custom h-100">
                    <div class="card-header bg-white border-bottom-0 pt-4 pb-0">
                        <h6 class="fw-bold text-primary"><i class="fas fa-user-plus me-2"></i>Cadastrar Parceiro</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="acao" value="criar_parceiro">
                            
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted">NOME DO PARCEIRO / AGÊNCIA</label>
                                <input type="text" name="nome" class="form-control fw-bold" placeholder="Ex: Agência Eleva" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted">COMISSÃO RECORRENTE (%)</label>
                                <div class="input-group">
                                    <input type="number" name="comissao_porcento" class="form-control" min="1" max="100" placeholder="Ex: 30" required>
                                    <span class="input-group-text bg-light">% / mês</span>
                                </div>
                                <small class="text-muted" style="font-size: 11px;">Percentual que ele ganha todo mês sobre a assinatura.</small>
                            </div>

                            <div class="mb-4">
                                <label class="form-label small fw-bold text-muted">CHAVE PIX (Para pagamento)</label>
                                <input type="text" name="pix_recebimento" class="form-control" placeholder="CPF, CNPJ, Email ou Celular">
                            </div>

                            <button type="submit" class="btn btn-primary w-100 fw-bold py-2 rounded-pill">GERAR LINK DE PARCEIRO</button>
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
                                        <th class="text-secondary small fw-bold py-3 ps-4">PARCEIRO</th>
                                        <th class="text-secondary small fw-bold py-3">COMISSÃO</th>
                                        <th class="text-secondary small fw-bold py-3 text-center">ASSINATURAS</th>
                                        <th class="text-secondary small fw-bold py-3 pe-4 text-end">AÇÃO</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($lista_parceiros)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center py-5 text-muted">
                                                <i class="fas fa-handshake fa-3x mb-3 opacity-25"></i><br>
                                                Nenhum parceiro cadastrado.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach($lista_parceiros as $p): ?>
                                        <tr>
                                            <td class="ps-4">
                                                <strong class="text-dark fs-6"><?= htmlspecialchars($p['nome']) ?></strong><br>
                                                <span class="badge bg-primary mt-1" style="cursor:pointer;" onclick="copiarLink('<?= $p['codigo_ref'] ?>')" title="Copiar Link de Venda">
                                                    <i class="fas fa-link me-1"></i> Copiar Link
                                                </span>
                                                <small class="d-block text-muted mt-1" style="font-size: 10px;">Ref: <?= $p['codigo_ref'] ?></small>
                                            </td>
                                            
                                            <td>
                                                <span class="fw-bold text-success fs-5"><?= $p['comissao_porcento'] ?>%</span><br>
                                                <small class="text-muted" style="font-size: 11px;">PIX: <?= htmlspecialchars($p['pix_recebimento'] ?: 'Não inf.') ?></small>
                                            </td>
                                            
                                            <td class="text-center">
                                                <div class="bg-light rounded p-2 d-inline-block border">
                                                    <span class="fw-bold fs-5 text-dark"><?= $p['assinaturas_ativas'] ?></span>
                                                    <small class="d-block text-muted" style="font-size: 10px;">Ativas</small>
                                                </div>
                                            </td>
                                            
                                            <td class="pe-4 text-end">
                                                <form method="POST" onsubmit="return confirm('Desativar este parceiro? Ele não receberá novas assinaturas, mas as antigas continuam válidas.');">
                                                    <input type="hidden" name="acao" value="excluir_parceiro">
                                                    <input type="hidden" name="id_parceiro" value="<?= $p['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Desativar Parceiro"><i class="fas fa-ban"></i></button>
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
        function copiarLink(codigo_ref) {
            // ATENÇÃO: Ajuste a URL abaixo para o nome do arquivo de assinatura que enviamos antes (assinar.php)
            const url = window.location.origin + '/assinar.php?plano=premium&ref=' + codigo_ref;
            
            navigator.clipboard.writeText(url).then(() => {
                alert("Link de Assinatura Copiado!\n\n" + url + "\n\nEnvie para o parceiro. Toda assinatura feita por este link vai amarrar o cliente a este afiliado.");
            });
        }
    </script>
</body>
</html>