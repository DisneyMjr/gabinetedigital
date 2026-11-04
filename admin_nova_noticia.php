<?php
require 'config.php';   // 1º O Cérebro (Define quem é o cliente)
require 'protecao.php'; // 2º O Porteiro (Verifica se pode entrar

// --- 0. SEGURANÇA SAAS ---
if (!defined('TENANT_ID')) {
    die("Erro Crítico: Tenant não identificado.");
}

if (!tem_acesso('noticias')) { die("Acesso Negado."); }

// ==========================================================
// ATUALIZAÇÃO AUTOMÁTICA DO BANCO DE DADOS
// Adiciona a coluna "categoria" se ela não existir
// ==========================================================
try {
    $colunas_noticias = $pdo->query("DESCRIBE noticias")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('categoria', $colunas_noticias)) {
        $pdo->query("ALTER TABLE noticias ADD COLUMN categoria VARCHAR(100) DEFAULT 'Geral'");
    }
} catch (Exception $e) { }

$msg = "";
$id = $_GET['id'] ?? null;

// --- VALORES PADRÃO ---
$titulo = "";
$subtitulo = "";
$categoria = "Geral"; // Valor padrão
$texto = "";
$data_pub = date('Y-m-d\TH:i'); 
$imagem_atual = "";

// Função para salvar imagem vinda do Cropper (Base64) otimizada para Notícias (JPG)
function salvarImagemBase64News($base64_string, $pasta) {
    if(empty($base64_string)) return false;
    
    // Separa os metadados do arquivo base64
    list($type, $data) = explode(';', $base64_string);
    list(, $data)      = explode(',', $data);
    $data = base64_decode($data);
    
    // Salva como JPG para otimizar o carregamento do site
    $novo_nome = "news_" . uniqid() . ".jpg";
    $destino = $pasta . $novo_nome;
    
    if(file_put_contents($destino, $data)) {
        return $destino;
    }
    return false;
}

// --- 1. SE FOR EDIÇÃO: BUSCAR DADOS (COM TRAVA DE TENANT) ---
if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM noticias WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$id, TENANT_ID]);
    $dados = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($dados) {
        $titulo = $dados['titulo'];
        $subtitulo = $dados['subtitulo']; 
        $categoria = $dados['categoria'] ?? 'Geral'; // Puxa a categoria do banco
        $texto = $dados['texto'];
        $data_pub = $dados['data_publicacao'];
        $imagem_atual = $dados['imagem'];
    } else {
        die("Notícia não encontrada ou acesso negado.");
    }
}

// --- 2. PROCESSAR O SALVAMENTO ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id_editar = $_POST['id_editar'] ?? null;
    $titulo_post = trim($_POST['titulo']);
    $subtitulo_post = trim($_POST['subtitulo'] ?? ''); 
    $categoria_post = trim($_POST['categoria'] ?? 'Geral'); // Captura a Categoria
    $texto_post = $_POST['texto'];
    $data_pub_post = $_POST['data_publicacao']; 
    
    if (empty($categoria_post)) $categoria_post = 'Geral'; // Se o usuário apagar tudo, volta pra Geral

    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $titulo_post)));

    // Lógica da Imagem
    $caminho_imagem = $_POST['imagem_atual_hidden']; 
    $pasta_destino = "img/news/" . TENANT_ID . "/"; 
    
    if (!is_dir($pasta_destino)) { mkdir($pasta_destino, 0777, true); }

    // Verifica primeiro se veio imagem recortada pelo Cropper
    if (!empty($_POST['cropped_imagem'])) {
        $destino_final = salvarImagemBase64News($_POST['cropped_imagem'], $pasta_destino);
        
        if ($destino_final) {
            if(!empty($caminho_imagem) && file_exists($caminho_imagem)) unlink($caminho_imagem);
            $caminho_imagem = $destino_final; 
        } else {
            $msg = "<div class='alert alert-danger'>Erro ao salvar a imagem recortada.</div>";
        }
    } 
    // Fallback: Se por acaso o upload foi feito de forma tradicional
    elseif (isset($_FILES['imagem']) && $_FILES['imagem']['error'] == 0) {
        $extensao = strtolower(pathinfo($_FILES['imagem']['name'], PATHINFO_EXTENSION));
        $permitidos = ['jpg', 'jpeg', 'png', 'webp'];
        
        if (in_array($extensao, $permitidos)) {
            $novo_nome = "news_" . uniqid() . "." . $extensao;
            $destino_final = $pasta_destino . $novo_nome;

            if (move_uploaded_file($_FILES['imagem']['tmp_name'], $destino_final)) {
                if(!empty($caminho_imagem) && file_exists($caminho_imagem)) unlink($caminho_imagem);
                $caminho_imagem = $destino_final; 
            } else {
                $msg = "<div class='alert alert-danger'>Erro ao salvar a nova imagem.</div>";
            }
        } else {
            $msg = "<div class='alert alert-danger'>Formato inválido! Use JPG, PNG ou WEBP.</div>";
        }
    }

    if (empty($msg)) {
        try {
            if ($id_editar) {
                // --- MODO UPDATE ---
                // ATENÇÃO: Adicionado o categoria=? no UPDATE
                $sql = "UPDATE noticias SET titulo=?, subtitulo=?, categoria=?, texto=?, imagem=?, data_publicacao=? WHERE id=? AND tenant_id=?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$titulo_post, $subtitulo_post, $categoria_post, $texto_post, $caminho_imagem, $data_pub_post, $id_editar, TENANT_ID]);
                
                if(function_exists('registrar_log')) registrar_log($pdo, 'Editou Notícia', $titulo_post);
                $msg = "<div class='alert alert-success'>Notícia atualizada com sucesso! <a href='admin_noticias_lista.php' class='alert-link'>Voltar para a lista</a></div>";
                
                $titulo = $titulo_post; $subtitulo = $subtitulo_post; $categoria = $categoria_post; $texto = $texto_post; $imagem_atual = $caminho_imagem;

            } else {
                // --- MODO INSERT ---
                // ATENÇÃO: Adicionado a coluna categoria no INSERT
                $sql = "INSERT INTO noticias (tenant_id, titulo, subtitulo, categoria, slug, texto, imagem, data_publicacao) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([TENANT_ID, $titulo_post, $subtitulo_post, $categoria_post, $slug, $texto_post, $caminho_imagem, $data_pub_post]);
                
                if(function_exists('registrar_log')) registrar_log($pdo, 'Criou Notícia', $titulo_post);
                $msg = "<div class='alert alert-success'>Notícia criada com sucesso! <a href='admin_noticias_lista.php' class='alert-link'>Voltar para a lista</a></div>";
            }

        } catch (PDOException $e) {
            $msg = "<div class='alert alert-danger'>Erro no banco: " . $e->getMessage() . "</div>";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title><?= $id ? 'Editar' : 'Nova' ?> Notícia</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css" rel="stylesheet">
    
    <style>
        body { background-color: #f8f9fa; }
        .editor-box { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .crop-container { max-height: 60vh; text-align: center; }
        .crop-container img { max-width: 100%; display: block; }
    </style>
</head>
<body>

    <nav class="navbar navbar-dark bg-primary mb-4 shadow-sm">
        <div class="container">
            <span class="navbar-brand fw-bold">
                <i class="fas fa-edit"></i> <?= $id ? 'Editar Notícia' : 'Nova Publicação' ?>
            </span>
            <a href="admin_noticias_lista.php" class="btn btn-outline-light btn-sm">Voltar à Lista</a>
        </div>
    </nav>

    <div class="container pb-5">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                
                <?= $msg ?>

                <form method="POST" enctype="multipart/form-data" id="newsForm">
                    <input type="hidden" name="id_editar" value="<?= $id ?>">
                    <input type="hidden" name="imagem_atual_hidden" value="<?= $imagem_atual ?>">
                    <input type="hidden" name="cropped_imagem" id="cropped_imagem">

                    <div class="editor-box">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Título da Matéria</label>
                                    <input type="text" name="titulo" class="form-control form-control-lg" 
                                           value="<?= htmlspecialchars($titulo) ?>" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-bold">Subtítulo (Resumo)</label>
                                    <input type="text" name="subtitulo" class="form-control" 
                                           value="<?= htmlspecialchars($subtitulo) ?>">
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label fw-bold text-primary"><i class="fas fa-tags me-1"></i> Categoria / Tema</label>
                                    <input type="text" name="categoria" class="form-control fw-bold text-primary bg-primary bg-opacity-10 border-primary border-opacity-50" 
                                           list="categoriasPadrao" value="<?= htmlspecialchars($categoria) ?>" placeholder="Ex: Saúde, Educação, Esporte..." required>
                                    <datalist id="categoriasPadrao">
                                        <option value="Geral">
                                        <option value="Saúde">
                                        <option value="Educação">
                                        <option value="Infraestrutura">
                                        <option value="Esporte e Lazer">
                                        <option value="Cultura">
                                        <option value="Ação Social">
                                        <option value="Segurança Pública">
                                    </datalist>
                                    <div class="form-text small">Selecione uma opção na lista ou digite uma nova categoria.</div>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="p-3 bg-light border rounded mb-3">
                                    <label class="form-label fw-bold text-dark">Data de Publicação</label>
                                    <input type="datetime-local" name="data_publicacao" class="form-control" 
                                           value="<?= date('Y-m-d\TH:i', strtotime($data_pub)) ?>" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-bold">Imagem de Capa</label>
                                    
                                    <div id="preview_imagem_container">
                                        <?php if($imagem_atual): ?>
                                            <div class="mb-2 text-center p-2 border bg-light rounded" id="current_image_box">
                                                <small class="d-block text-muted mb-1">Imagem Atual:</small>
                                                <img src="<?= $imagem_atual ?>" style="max-height: 120px; max-width: 100%; border-radius: 8px;">
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <input type="file" name="imagem" id="input_imagem" class="form-control image-cropper-input" data-target="cropped_imagem" accept="image/*">
                                    <div class="form-text small">Escolha uma imagem para recortar e posicionar.</div>
                                </div>
                            </div>
                        </div>

                        <hr>

                        <div class="mb-4">
                            <label class="form-label fw-bold">Conteúdo da Notícia</label>
                            <textarea id="summernote" name="texto"><?= $texto ?></textarea>
                        </div>

                        <div class="d-flex justify-content-between align-items-center">
                            <a href="admin_noticias_lista.php" class="btn btn-light text-muted">Cancelar</a>
                            <button type="submit" class="btn btn-success btn-lg fw-bold px-5 shadow-sm">
                                <i class="fas fa-check"></i> SALVAR NOTÍCIA
                            </button>
                        </div>

                    </div>
                </form>

            </div>
        </div>
    </div>

    <div class="modal fade" id="cropModal" tabindex="-1" data-bs-backdrop="static" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-primary"><i class="fas fa-crop-alt"></i> Posicionar Imagem de Capa</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body bg-light">
                    <div class="crop-container w-100">
                        <img id="imageToCrop" src="" alt="Imagem para cortar">
                    </div>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary fw-bold" id="btnCropConfirm"><i class="fas fa-check"></i> Confirmar Recorte</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.4.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>

    <script>
        // --- Inicializa o Editor de Texto (Summernote) ---
        $('#summernote').summernote({
            placeholder: 'Escreva sua notícia aqui...',
            tabsize: 2,
            height: 400,
            toolbar: [
                ['style', ['style', 'bold', 'italic', 'underline', 'clear']],
                ['font', ['strikethrough', 'superscript', 'subscript']],
                ['para', ['ul', 'ol', 'paragraph']],
                ['insert', ['link', 'picture', 'video', 'hr']],
                ['view', ['fullscreen', 'codeview']]
            ]
        });

        // --- SISTEMA DE RECORTE DE IMAGENS (CROPPER.JS) ---
        let cropper;
        let currentInputTarget = null;
        const cropModalElement = document.getElementById('cropModal');
        const cropModal = new bootstrap.Modal(cropModalElement);
        const imageToCrop = document.getElementById('imageToCrop');

        document.querySelectorAll('.image-cropper-input').forEach(input => {
            input.addEventListener('change', function(e) {
                const files = e.target.files;
                if (files && files.length > 0) {
                    const file = files[0];
                    currentInputTarget = {
                        inputElement: this,
                        hiddenTarget: this.getAttribute('data-target')
                    };

                    const reader = new FileReader();
                    reader.onload = function(event) {
                        imageToCrop.src = event.target.result;
                        cropModal.show();
                    };
                    reader.readAsDataURL(file);
                }
            });
        });

        cropModalElement.addEventListener('shown.bs.modal', function () {
            // Oculta a caixa da imagem antiga temporariamente
            const oldImgBox = document.getElementById('current_image_box');
            if(oldImgBox) oldImgBox.style.display = 'none';

            cropper = new Cropper(imageToCrop, {
                viewMode: 1,
                autoCropArea: 1,
                background: true,
                // Sem aspectRatio definido = corte totalmente livre!
            });
        });

        cropModalElement.addEventListener('hidden.bs.modal', function () {
            if (cropper) {
                cropper.destroy();
                cropper = null;
            }
            // Se o usuário fechar o modal sem salvar, limpa o input file
            if(currentInputTarget && document.getElementById(currentInputTarget.hiddenTarget).value === "") {
                 currentInputTarget.inputElement.value = "";
                 const oldImgBox = document.getElementById('current_image_box');
                 if(oldImgBox) oldImgBox.style.display = 'block'; // Volta a mostrar a antiga
            }
        });

        document.getElementById('btnCropConfirm').addEventListener('click', function() {
            if (!cropper || !currentInputTarget) return;

            // Recorta a imagem
            const canvas = cropper.getCroppedCanvas({
                imageSmoothingEnabled: true,
                imageSmoothingQuality: 'high',
            });
            
            // Salva como JPG com 85% de qualidade para otimizar carregamento
            const base64Image = canvas.toDataURL('image/jpeg', 0.85);

            // Grava no campo oculto
            document.getElementById(currentInputTarget.hiddenTarget).value = base64Image;

            // Mostra o preview atualizado na tela
            const previewContainer = document.getElementById('preview_imagem_container');
            if (previewContainer) {
                previewContainer.innerHTML = `
                    <div class="mb-2 text-center p-2 border border-success bg-success-subtle rounded">
                        <small class="d-block text-success fw-bold mb-1"><i class="fas fa-check-double"></i> Imagem Ajustada!</small>
                        <img src="${base64Image}" style="max-height: 120px; max-width: 100%; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    </div>
                `;
            }

            cropModal.hide();
        });
    </script>
</body>
</html>