<?php
require 'config.php';   // 1º O Cérebro (Define quem é o cliente)
require 'protecao.php'; // 2º O Porteiro (Verifica se pode entrar

// --- 0. SEGURANÇA SAAS ---
if (!defined('TENANT_ID')) {
    die("Erro Crítico: Tenant não identificado.");
}

// Ajuste de Fuso Horário
date_default_timezone_set('America/Sao_Paulo');

// --- LÓGICA DE EXCLUSÃO (COM TRAVA DE TENANT) ---
if (isset($_GET['deletar'])) {
    $id_delete = (int)$_GET['deletar'];
    
    // 1. Tenta apagar a imagem da pasta (SÓ SE FOR DESTE TENANT)
    $stmt_img = $pdo->prepare("SELECT imagem FROM noticias WHERE id = ? AND tenant_id = ?"); 
    $stmt_img->execute([$id_delete, TENANT_ID]);
    $img = $stmt_img->fetchColumn();
    
    if ($img && file_exists($img)) {
        unlink($img); // Deleta o arquivo físico
    }

    // 2. Apaga do banco (SÓ SE FOR DESTE TENANT)
    $pdo->prepare("DELETE FROM noticias WHERE id = ? AND tenant_id = ?")->execute([$id_delete, TENANT_ID]);
    
    header("Location: admin_noticias_lista.php");
    exit;
}

// --- BUSCAR NOTÍCIAS (FILTRADO POR TENANT) ---
$sql = "SELECT * FROM noticias WHERE tenant_id = ? ORDER BY data_publicacao DESC";
$stmt_lista = $pdo->prepare($sql);
$stmt_lista->execute([TENANT_ID]);
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Gerenciar Notícias</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-light p-4">

<div class="container bg-white p-4 rounded shadow-sm">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="text-primary fw-bold mb-0"><i class="fas fa-newspaper me-2"></i>Notícias</h3>
            <small class="text-muted">Gerencie o blog do mandato</small>
        </div>
        <div>
            <a href="admin_painel.php" class="btn btn-outline-secondary me-2">Voltar</a>
            <a href="admin_nova_noticia.php" class="btn btn-success fw-bold"><i class="fas fa-plus"></i> Nova Notícia</a>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th width="100">Status</th>
                    <th width="80">Capa</th>
                    <th>Título / Data</th>
                    <th class="text-end">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $stmt_lista->fetch(PDO::FETCH_ASSOC)): 
                    // --- LÓGICA DO AGENDAMENTO ---
                    $agendado = (strtotime($row['data_publicacao']) > time());
                ?>
                <tr class="<?= $agendado ? 'table-info' : '' ?>"> 
                    <td>
                        <?php if($agendado): ?>
                            <span class="badge bg-info text-dark shadow-sm">
                                <i class="fas fa-clock"></i> Agendado
                            </span>
                        <?php else: ?>
                            <span class="badge bg-success shadow-sm">
                                <i class="fas fa-check-circle"></i> Publicado
                            </span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php if(!empty($row['imagem'])): ?>
                            <img src="<?= $row['imagem'] ?>" class="rounded border" style="width: 60px; height: 40px; object-fit: cover;">
                        <?php else: ?>
                            <span class="text-muted small"><i class="fas fa-image"></i></span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <strong class="text-dark d-block text-truncate" style="max-width: 400px;">
                            <?= $row['titulo'] ?>
                        </strong>
                        <small class="<?= $agendado ? 'text-primary fw-bold' : 'text-muted' ?>">
                            <i class="far fa-calendar-alt"></i> 
                            <?= date('d/m/Y \à\s H:i', strtotime($row['data_publicacao'])) ?>
                            <?= $agendado ? '(Em breve)' : '' ?>
                        </small>
                    </td>

                    <td class="text-end">
                        <a href="admin_nova_noticia.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-primary shadow-sm" title="Editar">
                            <i class="fas fa-edit"></i>
                        </a>

                        <a href="?deletar=<?= $row['id'] ?>" 
                           class="btn btn-sm btn-outline-danger ms-1" 
                           onclick="return confirm('Tem certeza que deseja apagar esta notícia?');" title="Excluir">
                           <i class="fas fa-trash"></i>
                        </a>
                    </td>
                </tr>
                <?php endwhile; ?>

                <?php if($stmt_lista->rowCount() == 0): ?>
                    <tr>
                        <td colspan="4" class="text-center py-5 text-muted">
                            <i class="far fa-folder-open fa-2x mb-3 d-block"></i>
                            Nenhuma notícia cadastrada ainda.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>