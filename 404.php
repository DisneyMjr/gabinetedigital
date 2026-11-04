<?php
// Define o código de resposta HTTP correto para SEO e navegadores
http_response_code(404);

session_start();

// Lógica Inteligente de Redirecionamento
$link_voltar = 'index.php'; // Padrão: vai para a home do site público
$texto_voltar = 'Ir para o Início';

if (isset($_SESSION['permissoes']) && in_array('master', $_SESSION['permissoes'])) {
    // Se for o Dono do SaaS
    $link_voltar = 'master_painel.php';
    $texto_voltar = 'Voltar ao Painel Master';
} elseif (isset($_SESSION['id'])) {
    // Se for um Cliente (Político/Assessor)
    $link_voltar = 'admin_painel.php';
    $texto_voltar = 'Voltar ao Meu Painel';
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Página não encontrada | 404</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f8fafc;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', sans-serif;
            overflow: hidden;
        }
        .error-container {
            text-align: center;
            padding: 40px;
            max-width: 600px;
        }
        .error-code {
            font-size: 8rem;
            font-weight: 900;
            line-height: 1;
            background: -webkit-linear-gradient(45deg, #0d6efd, #6610f2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 20px;
            text-shadow: 4px 4px 10px rgba(0,0,0,0.1);
        }
        .error-icon {
            font-size: 5rem;
            color: #cbd5e1;
            margin-bottom: 20px;
            animation: float 3s ease-in-out infinite;
        }
        .btn-custom {
            padding: 12px 30px;
            font-weight: 600;
            border-radius: 50px;
            transition: 0.3s;
        }
        .btn-custom:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(13, 110, 253, 0.3);
        }
        
        @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-15px); }
            100% { transform: translateY(0px); }
        }
    </style>
</head>
<body>

    <div class="container error-container">
        <div class="error-icon">
            <i class="fas fa-map-marked-alt"></i>
            <i class="fas fa-question text-primary position-absolute" style="font-size: 2rem; margin-left: -15px; margin-top: -10px;"></i>
        </div>

        <div class="error-code">404</div>

        <h2 class="fw-bold text-dark mb-3">Ops! Página não encontrada.</h2>
        <p class="text-muted fs-5 mb-5">
            O link que você acessou pode estar quebrado, a página foi removida ou você não tem permissão para visualizar este conteúdo.
        </p>

        <div class="d-flex justify-content-center gap-3">
            <a href="<?= $link_voltar ?>" class="btn btn-primary btn-custom shadow">
                <i class="fas fa-home me-2"></i> <?= $texto_voltar ?>
            </a>
            
            <button onclick="history.back()" class="btn btn-outline-secondary btn-custom">
                <i class="fas fa-arrow-left me-2"></i> Voltar
            </button>
        </div>

        <div class="mt-5 small text-muted">
            Precisa de ajuda? <a href="https://wa.me/5537999129477" class="text-decoration-none fw-bold">Fale com o Suporte</a>
        </div>
    </div>

</body>
</html>