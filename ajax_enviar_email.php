<?php
// ajax_enviar_email.php
header('Content-Type: application/json');

require 'config.php';
require 'protecao.php';

// Importa classes do PHPMailer (Ajuste o caminho se necessário)
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require 'libs/PHPMailer/src/Exception.php';
require 'libs/PHPMailer/src/PHPMailer.php';
require 'libs/PHPMailer/src/SMTP.php';

$response = ['erro' => false, 'msg' => '', 'enviados_agora' => 0];

try {
    if (!tem_acesso('marketing')) throw new Exception("Sem permissão.");

    $id_campanha = $_POST['id'] ?? 0;
    $offset = $_POST['offset'] ?? 0;
    $limit = $_POST['limit'] ?? 5;

    // 1. Busca dados da Campanha
    $stmt = $pdo->prepare("SELECT * FROM campanhas WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$id_campanha, TENANT_ID]);
    $campanha = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$campanha) throw new Exception("Campanha não encontrada.");

    // 2. Busca Configurações de SMTP do Cliente
    $stmtConf = $pdo->prepare("SELECT * FROM configuracoes WHERE tenant_id = ?");
    $stmtConf->execute([TENANT_ID]);
    $config = $stmtConf->fetch(PDO::FETCH_ASSOC);

    if (empty($config['smtp_host']) || empty($config['smtp_email']) || empty($config['smtp_senha'])) {
        throw new Exception("SMTP não configurado em 'Configurações'.");
    }

    // 3. Busca o Lote de Eleitores
    // Pega apenas quem tem e-mail, usando LIMIT e OFFSET para paginação
    $sqlEleitores = "SELECT nome, email FROM eleitores 
                     WHERE tenant_id = :tenant 
                     AND email IS NOT NULL AND email != '' 
                     LIMIT :limit OFFSET :offset";
    
    $stmtEl = $pdo->prepare($sqlEleitores);
    $stmtEl->bindValue(':tenant', TENANT_ID, PDO::PARAM_INT);
    $stmtEl->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
    $stmtEl->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    $stmtEl->execute();
    $eleitores = $stmtEl->fetchAll(PDO::FETCH_ASSOC);

    // Se não tiver mais ninguém nesse lote, encerra
    if (count($eleitores) == 0) {
        echo json_encode(['erro' => false, 'enviados_agora' => 0, 'msg' => 'Fim da lista']);
        exit;
    }

    // 4. Configura o PHPMailer
    $mail = new PHPMailer(true);
    
    // Configurações do Servidor
    $mail->isSMTP();
    $mail->Host       = $config['smtp_host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $config['smtp_email'];
    $mail->Password   = $config['smtp_senha']; // Senha ou App Password
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // Use ENCRYPTION_STARTTLS se for porta 587
    $mail->Port       = $config['smtp_porta'] ?? 465;
    $mail->CharSet    = 'UTF-8';

    // Remetente
    $mail->setFrom($config['smtp_email'], $config['nome_vereador'] ?? 'Gabinete');
    $mail->isHTML(true);
    $mail->Subject = $campanha['assunto'];

    $enviados_count = 0;

    // 5. Loop de Envio
    foreach ($eleitores as $eleitor) {
        try {
            // Personalização do corpo (Troca {nome} pelo nome do eleitor)
            $corpo_personalizado = str_replace('{nome}', explode(' ', $eleitor['nome'])[0], $campanha['conteudo']);
            
            // Rodapé obrigatório (Anti-spam)
            $corpo_personalizado .= "<br><hr><small>Enviado por Gabinete Digital. Para descadastrar, responda este e-mail.</small>";

            $mail->Body = $corpo_personalizado;
            
            // Adiciona Destinatário
            $mail->addAddress($eleitor['email'], $eleitor['nome']);

            $mail->send();
            $enviados_count++;

            // Limpa destinatários para o próximo loop
            $mail->clearAddresses();
            
        } catch (Exception $e) {
            // Se falhar um e-mail, continua para o próximo, mas reseta o destinatário
            $mail->clearAddresses();
            continue; 
        }
    }

    $response['enviados_agora'] = $enviados_count;
    $response['msg'] = "Lote processado.";

} catch (Exception $e) {
    $response['erro'] = true;
    $response['msg'] = $e->getMessage();
}

echo json_encode($response);