<?php
// webhook_efi.php - RECEBE O AVISO DE PAGAMENTO DA EFÍ
require 'conexao.php';
require 'efi_config.php';

// A Efí envia um POST contendo um token de notificação
$notification_token = $_POST['notification'] ?? '';

// Se não tiver token, encerra a execução (evita curiosos acessando a URL)
if (empty($notification_token)) {
    http_response_code(400);
    die("Token de notificação não recebido.");
}

// 1. Pegar o Token de Acesso da API (para ter permissão de ler a notificação)
$access_token = getEfiToken();
if (!$access_token) {
    http_response_code(500);
    die("Erro interno de autenticação.");
}

// 2. Consultar o status real da notificação na Efí
$url_base = EFI_SANDBOX ? 'https://sandbox.gerencianet.com.br' : 'https://api.gerencianet.com.br';
$url_notification = $url_base . '/v1/notification/' . $notification_token;

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url_notification);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $access_token
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
curl_close($ch);

$retorno = json_decode($response, true);

// 3. Processar a resposta e atualizar o Banco de Dados
if (isset($retorno['code']) && $retorno['code'] == 200 && isset($retorno['data'])) {
    
    // O retorno pode conter um histórico de mudanças, vamos analisar cada uma
    foreach ($retorno['data'] as $notificacao) {
        
        if ($notificacao['type'] === 'charge') {
            $status_atual = $notificacao['status']['current'];
            $charge_id = $notificacao['identifiers']['charge_id'];

            // Se o status for PAGO (paid) ou LIQUIDADO (settled)
            if ($status_atual === 'paid' || $status_atual === 'settled') {
                
                // Renova a assinatura por mais 30 dias a partir de HOJE
                $sql = "UPDATE tenants SET 
                        status_assinatura = 'ativo',
                        data_expiracao = DATE_ADD(NOW(), INTERVAL 30 DAY)
                        WHERE efi_charge_id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$charge_id]);
                
            } 
            // Se o boleto/pix venceu e não foi pago
            elseif ($status_atual === 'unpaid' || $status_atual === 'canceled') {
                $sql = "UPDATE tenants SET status_assinatura = 'inadimplente' WHERE efi_charge_id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$charge_id]);
            }
        }
    }
}

// 4. Resposta obrigatória: A Efí exige que seu servidor retorne HTTP 200 OK
http_response_code(200);
echo "Webhook processado com sucesso.";
?>
