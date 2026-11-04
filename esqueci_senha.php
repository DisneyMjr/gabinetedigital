<?php
// Adicione isso no topo para ver o erro na tela
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// esqueci_senha.php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Carrega o PHPMailer
require 'libs/PHPMailer/src/Exception.php';
require 'libs/PHPMailer/src/PHPMailer.php';
require 'libs/PHPMailer/src/SMTP.php';

session_start();
require 'config.php';

$msg = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);

    // 1. Verifica se o usuário existe
    try {
        $stmt = $pdo->prepare("SELECT id, nome FROM usuarios WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // 2. Gera Token e Validade
            $token = bin2hex(random_bytes(50));
            $validade = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // 3. Limpa tokens antigos e salva o novo
            $pdo->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$email]);
            
            $sqlInsert = "INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)";
            $stmtInsert = $pdo->prepare($sqlInsert);
            
            if ($stmtInsert->execute([$email, $token, $validade])) {
                
                // --- 4. CONFIGURAÇÃO DO ENVIO (SEUS DADOS) ---
                
                // Link de recuperação
                $protocolo = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
                $dominio = $_SERVER['HTTP_HOST'];
                $caminho = dirname($_SERVER['PHP_SELF']); 
                $link = "$protocolo://$dominio$caminho/redefinir_senha.php?token=$token";

                // Mensagem HTML
                $mensagem_html = "
                <html>
                <body style='font-family: Arial, sans-serif; color: #333;'>
                  <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px;'>
                    <h2 style='color: #0d6efd;'>Olá, {$user['nome']}</h2>
                    <p>Recebemos um pedido para recuperar sua senha no <strong>Gabinete Digital</strong>.</p>
                    <p>Clique no botão abaixo para criar uma nova senha:</p>
                    <br>
                    <p style='text-align: center;'>
                        <a href='$link' style='background-color: #0d6efd; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px;'>REDEFINIR SENHA</a>
                    </p>
                    <br>
                    <p>Ou copie este link: <br><small style='color:#666'>$link</small></p>
                    <hr>
                    <p style='font-size: 12px; color: #888;'>Válido por 1 hora. Se não foi você, ignore.</p>
                  </div>
                </body>
                </html>
                ";

                $mail = new PHPMailer(true);

                try {
                    // --- CONFIGURAÇÃO SMTP (BASEADA NA SUA IMAGEM) ---
                    $mail->isSMTP();
                    $mail->Host       = 'mail.meugabinetedigital.com.br'; // Servidor de saída
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'contato@meugabinetedigital.com.br'; // Seu usuário
                    
                    // 👇👇 COLOQUE A SENHA DO E-MAIL AQUI 👇👇
                    $mail->Password   = 'Cmjr&2286'; 
                    
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // Porta 465 exige SSL/SMTPS
                    $mail->Port       = 465; 
                    $mail->CharSet    = 'UTF-8';

                    // Remetente e Destinatário
                    $mail->setFrom('contato@meugabinetedigital.com.br', 'Gabinete Digital');
                    $mail->addAddress($email, $user['nome']); 

                    // Conteúdo
                    $mail->isHTML(true);
                    $mail->Subject = 'Redefinir Senha - Gabinete Digital';
                    $mail->Body    = $mensagem_html;
                    $mail->AltBody = "Acesse o link para redefinir: $link";

                    $mail->send();

                    $msg = "<div class='alert alert-success text-center'>
                                <i class='fas fa-check-circle fa-3x mb-3'></i><br>
                                <strong>E-mail enviado com sucesso!</strong><br>
                                Verifique sua caixa de entrada (e Spam) para redefinir a senha.
                            </div>";

                } catch (Exception $e) {
                    $msg = "<div class='alert alert-danger text-center'>
                                Erro ao enviar e-mail: {$mail->ErrorInfo}
                            </div>";
                }

            } else {
                $msg = "<div class='alert alert-danger'>Erro no banco de dados.</div>";
            }

        } else {
            // Mensagem padrão de segurança
            $msg = "<div class='alert alert-info text-center'>Se este e-mail estiver cadastrado, enviaremos um link em instantes.</div>";
        }
    } catch (Exception $e) {
        $msg = "<div class='alert alert-danger'>Erro no sistema: " . $e->getMessage() . "</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Recuperar Senha | Gabinete Digital</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f0f2f5; height: 100vh; display: flex; align-items: center; justify-content: center; font-family: 'Montserrat', sans-serif; }
        .card-auth { max-width: 400px; width: 100%; border: none; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
    </style>
</head>
<body>

    <div class="card card-auth p-4 bg-white">
        <div class="text-center mb-4">
            <h4 class="fw-bold text-primary">Recuperar Acesso</h4>
            <p class="text-muted small">Digite seu e-mail para receber o link.</p>
        </div>

        <?= $msg ?>

        <?php if (empty($msg) || strpos($msg, 'alert-danger') !== false): ?>
        <form method="POST">
            <div class="mb-3">
                <label class="form-label small fw-bold text-muted">SEU E-MAIL</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0"><i class="fas fa-envelope text-muted"></i></span>
                    <input type="email" name="email" class="form-control border-start-0 ps-0" required placeholder="ex: admin@sistema.com">
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-100 fw-bold py-2">ENVIAR LINK</button>
        </form>
        <?php endif; ?>

        <div class="text-center mt-4 border-top pt-3">
            <a href="login.php" class="text-decoration-none small text-muted fw-bold"><i class="fas fa-arrow-left me-1"></i> Voltar para Login</a>
        </div>
    </div>

</body>
</html>