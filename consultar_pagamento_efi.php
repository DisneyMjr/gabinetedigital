<?php
require 'config.php';
require 'gateway.php'; // Carrega efi_pix_config.php e as constantes do banco

header('Content-Type: application/json');

$txid = $_GET['txid'] ?? '';

if (empty($txid)) {
    echo json_encode(['status' => 'erro', 'mensagem' => 'TXID vazio']);
    exit;
}

// 1. Obtém o Token de acesso
$token = efi_obterToken();
if (!$token) {
    echo json_encode(['status' => 'erro', 'mensagem' => 'Falha na autenticação']);
    exit;
}

// 2. Consulta a cobrança na Efí
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://pix.api.efipay.com.br/v2/cob/" . $txid);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSLCERT, __DIR__ . '/' . trim(EFI_CERTIFICADO));
curl_setopt($ch, CURLOPT_SSLCERTTYPE, "P12");
curl_setopt($ch, CURLOPT_SSLCERTPASSWD, "");
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $token",
    "Content-Type: application/json"
]);

$resposta = curl_exec($ch);
curl_close($ch);

$dados = json_decode($resposta, true);

// 3. Verifica se foi concluída (paga)
if (isset($dados['status']) && $dados['status'] == 'CONCLUIDA') {
    
    // --- ATUALIZAÇÃO DO BANCO DE DADOS ---
    // Aqui você coloca a lógica para ativar a conta do seu cliente
    // Exemplo:
    // $stmt = $pdo->prepare("UPDATE tenants SET status = 'ativo', data_vencimento = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE txid_pix = ?");
    // $stmt->execute([$txid]);
    
    echo json_encode(['status' => 'pago']);
} else {
    echo json_encode(['status' => 'pendente']);
}