<?php
// funcoes_email.php

// Carrega as classes do PHPMailer (Baseado na sua estrutura de pastas)
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// Ajuste dos caminhos conforme sua imagem
require 'libs/PHPMailer/src/Exception.php';
require 'libs/PHPMailer/src/PHPMailer.php';
require 'libs/PHPMailer/src/SMTP.php';

function enviarEmail($destinatario, $nome_destinatario, $assunto, $mensagemHTML) {
    $mail = new PHPMailer(true);

    try {
        // ====================================================
        // 1. CONFIGURAÇÕES DO SERVIDOR (DADOS DA SUA IMAGEM)
        // ====================================================
        // $mail->SMTPDebug = SMTP::DEBUG_SERVER; // Tire o // do início se der erro para ver o log detalhado
        
        $mail->isSMTP();
        $mail->Host       = 'mail.meugabinetedigital.com.br'; // Seu servidor de saída
        $mail->SMTPAuth   = true;
        $mail->Username   = 'contato@meugabinetedigital.com.br'; // Seu usuário
        $mail->Password   = 'Cmjr&2286'; // <--- COLOQUE A SENHA DO E-MAIL AQUI
        
        // Configuração para Porta 465 (SSL/TLS Implícito)
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; 
        $mail->Port       = 465; 
        
        // Correção para codificação de caracteres (acentos)
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';

        // ====================================================
        // 2. QUEM ENVIA E QUEM RECEBE
        // ====================================================
        $mail->setFrom('contato@meugabinetedigital.com.br', 'Gabinete Digital');
        $mail->addAddress($destinatario, $nome_destinatario);
        $mail->addReplyTo('contato@meugabinetedigital.com.br', 'Suporte');

        // ====================================================
        // 3. CONTEÚDO
        // ====================================================
        $mail->isHTML(true);
        $mail->Subject = $assunto;
        $mail->Body    = $mensagemHTML;
        $mail->AltBody = strip_tags($mensagemHTML); // Texto puro para clientes antigos

        $mail->send();
        return true;

    } catch (Exception $e) {
        // Salva o erro no log do servidor para você investigar depois, sem assustar o usuário
        error_log("Erro PHPMailer: {$mail->ErrorInfo}");
        return false;
    }
}
?>