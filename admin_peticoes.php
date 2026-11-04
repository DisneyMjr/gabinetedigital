<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require 'config.php';
require 'protecao.php';

if (!defined('TENANT_ID')) die("Acesso Restrito.");

$msg = '';

// 1. SALVAR NOVO ABAIXO-ASSINADO (COM HASH DE URL NEUTRO)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'salvar') {
    try {
        // Gera um código único de 10 caracteres para blindar o link
        $hash_unico = substr(md5(uniqid(rand(), true)), 0, 10); 
        
        $stmt = $pdo->prepare("INSERT INTO peticoes (tenant_id, titulo, descricao, tag_destino, status, hash_url) VALUES (?, ?, ?, ?, 1, ?)");
        $stmt->execute([
            TENANT_ID, 
            $_POST['titulo'], 
            $_POST['descricao'], 
            "Abaixo-Assinado: " . trim($_POST['tag_destino']),
            $hash_unico
        ]);
        $msg = "<div class='alert alert-success shadow-sm fw-bold'><i class='fas fa-check-circle'></i> Página de captura criada com sucesso!</div>";
    } catch (Exception $e) {
        $msg = "<div class='alert alert-danger'>Erro: " . $e->getMessage() . "</div>";
    }
}

// 2. ALTERAR STATUS (ATIVAR / OCULTAR)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'toggle_status') {
    try {
        $id_peticao = (int)$_POST['id_peticao'];
        
        // Pega o status atual
        $stmt = $pdo->prepare("SELECT status FROM peticoes WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$id_peticao, TENANT_ID]);
        $status_atual = $stmt->fetchColumn();
        
        // Inverte o status (Se for 1 vira 0, se for 0 vira 1)
        $novo_status = ($status_atual == 1) ? 0 : 1;
        
        $stmt = $pdo->prepare("UPDATE peticoes SET status = ? WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$novo_status, $id_peticao, TENANT_ID]);
        
        $texto_msg = $novo_status == 1 ? "publicada no site" : "removida do site";
        $msg = "<div class='alert alert-success shadow-sm fw-bold'><i class='fas fa-info-circle'></i> Campanha $texto_msg com sucesso!</div>";
    } catch (Exception $e) {
        $msg = "<div class='alert alert-danger'>Erro: " . $e->getMessage() . "</div>";
    }
}

// 3. EXCLUIR CAMPANHA
if (isset($_GET['deletar'])) {
    try {
        $id_del = (int)$_GET['deletar'];
        $pdo->prepare("DELETE FROM peticoes WHERE id = ? AND tenant_id = ?")->execute([$id_del, TENANT_ID]);
        header("Location: admin_peticoes.php");
        exit;
    } catch (Exception $e) {
        $msg = "<div class='alert alert-danger'>Erro ao excluir: " . $e->getMessage() . "</div>";
    }
}

// BUSCAR EXISTENTES + CONTAGEM DE ASSINATURAS (ELEITORES COM A TAG DA PETIÇÃO)
$stmt = $pdo->prepare("
    SELECT p.*, 
           (SELECT COUNT(id) FROM eleitores e WHERE e.tenant_id = p.tenant_id AND e.tags = p.tag_destino) as total_assinaturas
    FROM peticoes p 
    WHERE p.tenant_id = ? 
    ORDER BY p.id DESC
");
$stmt->execute([TENANT_ID]);
$peticoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// URL BASE NEUTRA (Usando o domínio principal correto do sistema)
$url_base_neutra = "https://meugabinetedigital.com.br"; 
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Abaixo-Assinados (Captura)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>body { background: #f3f4f6; font-family: 'Inter', sans-serif; }</style>
</head>
<body class="p-4">

<div class="container bg-white p-4 rounded-4 shadow-sm" style="max-width: 1000px;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="text-primary fw-bold mb-0"><i class="fas fa-file-signature me-2"></i> Páginas de Captura</h3>
        <a href="admin_painel.php" class="btn btn-outline-secondary rounded-pill fw-bold px-4">Voltar</a>
    </div>

    <?= $msg ?>

    <div class="card bg-light border-0 shadow-sm p-4 mb-4">
        <h5 class="fw-bold text-dark mb-3"><i class="fas fa-plus-circle text-success me-1"></i> Novo Abaixo-Assinado</h5>
        <form method="POST">
            <input type="hidden" name="acao" value="salvar">
            <div class="mb-3">
                <label class="small fw-bold">Título da Causa (Ex: Pela construção da creche no bairro X)</label>
                <input type="text" name="titulo" class="form-control fw-bold" required>
            </div>
            <div class="mb-3">
                <label class="small fw-bold">Texto de Convencimento</label>
                <textarea name="descricao" class="form-control" rows="3" required placeholder="Explique por que o cidadão deve assinar..."></textarea>
            </div>
            <div class="mb-3">
                <label class="small fw-bold text-primary">Qual Tag os eleitores receberão no CRM?</label>
                <input type="text" name="tag_destino" class="form-control" required placeholder="Ex: Creche Bairro X">
                <small class="text-muted fst-italic">Todos que assinarem cairão no seu CRM com esta tag automaticamente.</small>
            </div>
            <div class="text-end">
                <button type="submit" class="btn btn-success fw-bold px-5 rounded-pill"><i class="fas fa-save me-1"></i> Criar Página</button>
            </div>
        </form>
    </div>

    <h5 class="fw-bold text-secondary mb-3">Campanhas</h5>
    <div class="row g-3">
        <?php foreach($peticoes as $p): 
            $is_active = ($p['status'] == 1);
            $card_border = $is_active ? 'border-primary' : 'border-secondary opacity-75';
            $badge_status = $is_active ? '<span class="badge bg-success"><i class="fas fa-globe"></i> No Site</span>' : '<span class="badge bg-secondary"><i class="fas fa-eye-slash"></i> Oculto</span>';
            $btn_toggle_text = $is_active ? '<i class="fas fa-eye-slash"></i> Ocultar' : '<i class="fas fa-eye"></i> Publicar';
            $btn_toggle_class = $is_active ? 'btn-outline-warning text-dark' : 'btn-outline-success';

            // BUSCAR OS ASSINANTES DESTA PETIÇÃO ESPECÍFICA
            $stmt_assinantes = $pdo->prepare("SELECT nome, whatsapp, bairro, sexo FROM eleitores WHERE tenant_id = ? AND tags = ? ORDER BY id DESC");
            $stmt_assinantes->execute([TENANT_ID, $p['tag_destino']]);
            $assinantes = $stmt_assinantes->fetchAll(PDO::FETCH_ASSOC);
        ?>
            <div class="col-md-6">
                <div class="card border shadow-sm h-100 p-3 <?= $card_border ?>">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <span class="badge bg-primary">TAG: <?= htmlspecialchars($p['tag_destino']) ?></span>
                        <?= $badge_status ?>
                    </div>
                    
                    <h6 class="fw-bold text-dark mb-1"><?= htmlspecialchars($p['titulo']) ?></h6>
                    
                    <div class="d-flex justify-content-between align-items-center text-muted small fw-bold mb-2">
                        <div>
                            <i class="fas fa-users text-primary"></i> <?= $p['total_assinaturas'] ?> assinaturas coletadas
                        </div>
                        <?php if($p['total_assinaturas'] > 0): ?>
                            <button class="btn btn-xs btn-outline-primary py-0 px-2 fw-bold" style="font-size: 0.75rem;" data-bs-toggle="collapse" data-bs-target="#lista_<?= $p['id'] ?>">
                                <i class="fas fa-list"></i> Ver Assinaturas
                            </button>
                        <?php endif; ?>
                    </div>

                    <div class="collapse mt-2" id="lista_<?= $p['id'] ?>">
                        <div class="card card-body p-2 bg-light border-0 small max-viewport" style="max-height: 200px; overflow-y: auto;">
                            <table class="table table-sm table-hover mb-0" style="font-size: 0.8rem;">
                                <thead>
                                    <tr>
                                        <th>Nome</th>
                                        <th>Zap</th>
                                        <th>Bairro</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($assinantes as $a): ?>
                                        <tr>
                                            <td class="fw-bold text-dark"><?= htmlspecialchars($a['nome']) ?></td>
                                            <td><?= htmlspecialchars($a['whatsapp']) ?></td>
                                            <td><?= htmlspecialchars($a['bairro']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <div class="mt-auto pt-3">
                        <label class="small text-muted fw-bold">Link de Disparo (Neutro):</label>
                        <?php
                            // Se a petição foi criada antes do sistema de hash, cria um hash provisório para evitar link quebrado
                            $hash_exibicao = !empty($p['hash_url']) ? $p['hash_url'] : md5('migracao_'.$p['id']); 
                        ?>
                        <input type="text" class="form-control form-control-sm bg-light mb-2" value="<?= $url_base_neutra ?>/abaixoassinado.php?c=<?= $hash_exibicao ?>" id="link_<?= $p['id'] ?>" readonly>
                        
                        <div class="d-flex gap-2">
                            <button onclick="copiarLink(<?= $p['id'] ?>)" class="btn btn-sm btn-primary flex-grow-1 fw-bold"><i class="fas fa-copy me-1"></i> Copiar Link</button>
                            
                            <form method="POST" class="m-0">
                                <input type="hidden" name="acao" value="toggle_status">
                                <input type="hidden" name="id_peticao" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn btn-sm <?= $btn_toggle_class ?> fw-bold" title="Ocultar ou Publicar no site principal"><?= $btn_toggle_text ?></button>
                            </form>

                            <a href="?deletar=<?= $p['id'] ?>" class="btn btn-sm btn-outline-danger fw-bold" onclick="return confirm('Apagar definitivamente esta campanha?')" title="Excluir"><i class="fas fa-trash"></i></a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        
        <?php if(empty($peticoes)): ?>
            <div class="text-center py-4 text-muted"><i class="fas fa-inbox fa-2x mb-2 opacity-50"></i><p>Nenhuma campanha criada ainda.</p></div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function copiarLink(id) {
    var copyText = document.getElementById("link_" + id);
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(copyText.value);
    alert("Link copiado! Agora é só enviar no WhatsApp.");
}
</script>
</body>
</html>