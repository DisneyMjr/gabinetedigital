<?php
session_start();
// Força UTF-8
header('Content-Type: text/html; charset=utf-8');
require 'config.php'; 

// --- 1. LÓGICA DE LOGIN ---
$erro = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    $senha = trim($_POST['senha']);

    try {
        if (!isset($pdo)) throw new Exception("Erro de conexão.");

        $sql = "SELECT u.*, t.status as tenant_status, t.slug as tenant_slug, t.nome as tenant_nome 
                FROM usuarios u 
                LEFT JOIN tenants t ON u.tenant_id = t.id 
                WHERE u.email = ? LIMIT 1";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$email]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        $login_sucesso = false;

        if ($usuario) {
            if (password_verify($senha, $usuario['senha'])) {
                $login_sucesso = true;
            } elseif ($senha === $usuario['senha']) {
                $login_sucesso = true;
                $novo_hash = password_hash($senha, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE usuarios SET senha = ? WHERE id = ?")->execute([$novo_hash, $usuario['id']]);
            }
        }

        if ($login_sucesso) {
            $permissoes = json_decode($usuario['permissoes'] ?? '[]', true);
            $eh_master = in_array('master', $permissoes) || ($usuario['nivel'] === 'master');

            // SE FOR MASTER, TEM PASSE LIVRE (IGNORA TENANT)
            if ($eh_master) {
                $login_sucesso = true;
            } else {
                // SE NÃO FOR MASTER, VERIFICA O TENANT (Subdomínio)
                if (defined('TENANT_ID')) {
                    if ($usuario['tenant_id'] != TENANT_ID) {
                        $erro = "Você não tem permissão para acessar o painel deste subdomínio.";
                        $login_sucesso = false; 
                    }
                } else {
                    // TENTOU LOGAR NO DOMÍNIO PRINCIPAL (Painel Central)
                    if ($usuario['tenant_status'] != 'ativo') {
                        $erro = "<div class='text-center py-2'>
                                    <i class='fas fa-exclamation-circle fa-3x mb-3 text-warning'></i><br>
                                    <b style='font-size: 1.2rem;'>Assinatura Pendente</b><br>
                                    <span class='d-block mt-2 mb-4'>Para renovar e liberar o seu acesso, faça o login no seu painel exclusivo clicando abaixo:</span>
                                    <a href='https://{$usuario['tenant_slug']}.meugabinetedigital.com.br/login.php' class='btn btn-warning w-100 fw-bold text-dark shadow-sm py-2'>IR PARA MEU PAINEL</a>
                                 </div>";
                    } else {
                        $erro = "<div class='text-center py-2'>
                                    <i class='fas fa-lock fa-3x mb-3'></i><br>
                                    <b style='font-size: 1.2rem;'>Acesso Restrito</b><br>
                                    <span class='d-block mt-2 mb-4'>Você está no site central. Acesse diretamente pelo link exclusivo do seu gabinete:</span>
                                    <a href='https://{$usuario['tenant_slug']}.meugabinetedigital.com.br/login.php' class='btn btn-light w-100 fw-bold text-dark shadow-sm py-2 border'>ACESSAR MEU GABINETE</a>
                                 </div>";
                    }
                    $login_sucesso = false; 
                }
            }

            if ($login_sucesso) {
                // A CORREÇÃO DE OURO: Cria a sessão ANTES de verificar o bloqueio!
                $_SESSION['id'] = $usuario['id'];
                $_SESSION['tenant_id'] = $usuario['tenant_id'];
                $_SESSION['admin_nome'] = $usuario['nome'];
                $_SESSION['admin_email'] = $usuario['email'];
                $_SESSION['permissoes'] = $eh_master ? ['master'] : $permissoes;
                $_SESSION['nivel'] = $usuario['nivel'];
                $_SESSION['ultimo_acesso'] = time();

                // Agora sim, com o cliente logado, verificamos se ele está devendo
                if (!$eh_master && $usuario['tenant_status'] != 'ativo' && !empty($usuario['tenant_id'])) {
                    // Como ele já tem sessão, o protecao.php vai deixar a página de renovação abrir!
                    header("Location: /renovar_conta.php");
                    exit;
                } else {
                    // Cliente com tudo em dia, vai pro Dashboard principal
                    header("Location: " . ($eh_master ? "/master_painel.php" : "/admin_painel.php"));
                    exit;
                }
            }
        } else {
            if(empty($erro)) {
                $erro = '<i class="fas fa-exclamation-triangle me-2"></i> E-mail ou senha incorretos.';
            }
        }
    } catch (Exception $e) {
        $erro = "Erro interno: " . $e->getMessage();
    }
}

// --- 2. BUSCA BANNER ATIVO ---
$img_fundo = '';
$titulo_banner = ''; 
$frase_banner = '';
$is_video = false;

try {
    $sql_banner = "SELECT * FROM login_banners 
                   WHERE tipo = 'login' 
                   AND ativo = 1 
                   AND (data_inicio IS NULL OR data_inicio <= CURDATE()) 
                   AND (data_fim IS NULL OR data_fim >= CURDATE())
                   ORDER BY RAND() LIMIT 1";
                   
    $banner = $pdo->query($sql_banner)->fetch(PDO::FETCH_ASSOC);
    
    if ($banner) {
        $img_fundo = $banner['caminho_imagem'];
        if (!empty($banner['titulo'])) $titulo_banner = $banner['titulo'];
        if (!empty($banner['frase_apoio'])) $frase_banner = $banner['frase_apoio'];
        
        $ext = strtolower(pathinfo($img_fundo, PATHINFO_EXTENSION));
        if (in_array($ext, ['mp4', 'webm', 'ogg'])) {
            $is_video = true;
        }
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Login | Gabinete Digital</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        body, html { height: 100%; margin: 0; font-family: 'Montserrat', sans-serif; overflow: hidden; }
        .login-container { display: flex; height: 100vh; width: 100%; }
        .login-form-side { width: 450px; min-width: 320px; display: flex; flex-direction: column; justify-content: center; padding: 40px 60px; background: white; z-index: 10; box-shadow: 5px 0 30px rgba(0,0,0,0.05); }
        .login-banner-side { flex-grow: 1; background-color: #f0f2f5; position: relative; overflow: hidden; display: flex; flex-direction: column; justify-content: center; align-items: center; color: white; text-align: center; }
        .bg-image { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background-image: url('<?= $is_video ? '' : $img_fundo ?>'); background-size: cover; background-position: center; z-index: 0; }
        .bg-video { position: absolute; top: 50%; left: 50%; min-width: 100%; min-height: 100%; width: auto; height: auto; z-index: 0; transform: translate(-50%, -50%); object-fit: cover; }
        .overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.4); z-index: 1; display: none; }
        .has-text .overlay { display: block; }
        .banner-content { z-index: 2; padding: 40px; max-width: 800px; }
        .banner-content h1 { font-size: 3.5rem; font-weight: 700; margin-bottom: 1rem; text-shadow: 2px 2px 10px rgba(0,0,0,0.5); }
        .banner-content p { font-size: 1.5rem; font-weight: 400; text-shadow: 1px 1px 5px rgba(0,0,0,0.5); }
        .form-control { border-radius: 8px; padding: 12px 15px; border: 1px solid #dee2e6; background: #f8f9fa; font-size: 0.95rem; }
        .form-control:focus { background: #fff; box-shadow: 0 0 0 3px rgba(13,110,253,0.15); border-color: #0d6efd; }
        .btn-login { background: #0d6efd; border: none; padding: 12px; border-radius: 8px; font-weight: 700; color: white; width: 100%; font-size: 1rem; letter-spacing: 0.5px; transition: all 0.3s ease; margin-top: 10px; }
        .btn-login:hover { background: #0b5ed7; transform: translateY(-1px); box-shadow: 0 5px 15px rgba(13,110,253,0.3); }
        .password-wrapper { position: relative; }
        .toggle-password { position: absolute; right: 15px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #6c757d; font-size: 1.1rem; z-index: 5; }
        .toggle-password:hover { color: #0d6efd; }
        @media (max-width: 992px) { .login-banner-side { display: none; } .login-form-side { width: 100%; padding: 30px; } }
    </style>
</head>
<body>

<div class="login-container">
    <div class="login-form-side">
        <div class="mb-5">
            <?php if(defined('TENANT_NOME')): ?>
                <h5 class="text-muted text-uppercase small fw-bold mb-1">Painel Administrativo</h5>
                <h3 class="fw-bold text-primary"><?= htmlspecialchars(TENANT_NOME) ?></h3>
            <?php else: ?>
                <img src="assets/img/logo_login.png" alt="Logo" style="max-height: 50px;" onerror="this.style.display='none'; this.nextElementSibling.style.display='block'">
                <h3 class="fw-bold text-primary mt-2">Gabinete Digital</h3>
            <?php endif; ?>
        </div>

        <?php if($erro): ?>
            <div class="alert alert-danger border-0 shadow-sm mb-4 small rounded-3"><?= $erro ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label class="form-label small fw-bold text-muted text-uppercase">E-mail de Acesso</label>
                <input type="email" name="email" class="form-control" placeholder="seu@email.com" required>
            </div>
            
            <div class="mb-4">
                <div class="d-flex justify-content-between align-items-center">
                    <label class="form-label small fw-bold text-muted text-uppercase">Senha</label>
                    <a href="esqueci_senha.php" class="small text-decoration-none text-primary fw-bold" tabindex="-1">Esqueceu?</a>
                </div>
                <div class="password-wrapper">
                    <input type="password" name="senha" id="inputSenha" class="form-control" placeholder="******" required>
                    <i class="fas fa-eye toggle-password" onclick="toggleSenha()"></i>
                </div>
            </div>

            <button type="submit" class="btn-login">ENTRAR NO SISTEMA</button>
        </form>

        <div class="mt-auto pt-5 text-center">
            <p class="text-muted small mb-0">&copy; <?= date('Y') ?> Gabinete Digital. <br>Todos os direitos reservados.</p>
        </div>
    </div>

    <div class="login-banner-side <?= (!empty($titulo_banner) || !empty($frase_banner)) ? 'has-text' : '' ?>">
        <?php if($is_video): ?>
            <video class="bg-video" autoplay muted loop playsinline><source src="<?= $img_fundo ?>" type="video/mp4"></video>
        <?php else: ?>
            <div class="bg-image"></div>
        <?php endif; ?>
        
        <div class="overlay"></div> 
        <?php if(!empty($titulo_banner) || !empty($frase_banner)): ?>
        <div class="banner-content">
            <h1><?= htmlspecialchars($titulo_banner) ?></h1>
            <p><?= htmlspecialchars($frase_banner) ?></p>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
    function toggleSenha() {
        const input = document.getElementById('inputSenha');
        const icon = document.querySelector('.toggle-password');
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }
</script>

</body>
</html>