<?php
// ====================================================
// CONFIGURADOR DE WEBHOOK EFÍ - MÉTODO SMARTLIGA (PEM)
// ====================================================
ini_set('display_errors', 1);
error_reporting(E_ALL);

$client_id     = "Client_Id_0620faa3bb7d5a7c88473ac169fa26d844984b54";
$client_secret = "Client_Secret_a4b0c48ff87d0fffe312a6a3f4f7c3bd41fab4a7";
$chave_pix     = "27007d53-ca61-44f8-b662-1e9e0acb683d";

// Usando os arquivos PEM extraídos
$cert_publico  = __DIR__ . '/public_cert.pem';
$chave_privada = __DIR__ . '/private_key.pem';

$webhook_url = "https://meugabinetedigital.com.br/webhook_efi.php";
$url_base    = "https://pix.api.efipay.com.br";

echo "<body style='background:#f1f5f9; font-family:sans-serif; padding:40px;'>";
echo "<div style='background:#fff; padding:30px; border-radius:15px; box-shadow:0 10px 25px rgba(0,0,0,0.1); max-width:600px; margin:auto;'>";
echo "<h2 style='color:#6366f1;'>Enviando requisição limpa via Túnel PEM...</h2>";

// 1. AUTENTICAÇÃO
$credenciais = base64_encode($client_id . ':' . $client_secret);
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url_base . "/oauth/token",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSLCERT => $cert_publico,
    CURLOPT_SSLKEY  => $chave_privada,
    CURLOPT_HTTPHEADER => [
        "Authorization: Basic $credenciais",
        "Content-Type: application/json"
    ],
    CURLOPT_POSTFIELDS => json_encode(['grant_type' => 'client_credentials'])
]);

$auth_res = json_decode(curl_exec($ch), true);
$access_token = $auth_res['access_token'] ?? null;

if (!$access_token) {
    die("<p style='color:red;'>❌ Erro no Token:</p><pre>" . print_r($auth_res, true) . "</pre></div></body>");
}

// ==========================================
// 2. REGISTRO DO WEBHOOK (LIMPO)
// ==========================================
// Como os certificados .pem já validam o mTLS, enviamos apenas a URL
$payload = [
    'webhookUrl' => $webhook_url
];

curl_setopt_array($ch, [
    CURLOPT_URL => $url_base . "/v2/webhook/" . $chave_pix,
    CURLOPT_CUSTOMREQUEST => "PUT",
    CURLOPT_SSLCERT => $cert_publico,
    CURLOPT_SSLKEY  => $chave_privada,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $access_token",
        "Content-Type: application/json"
    ],
    CURLOPT_POSTFIELDS => json_encode($payload)
]);

$resposta = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// ==========================================
// 3. RESULTADO
// ==========================================
if ($http_code == 200 || $http_code == 201 || $http_code == 204) {
    echo "<h2 style='color:#10b981;'>🚀 Sucesso Absoluto! Webhook Ativado via PEM.</h2>";
    echo "<p>O Gabinete Digital agora está conectado à Efí assim como o SmartLiga!</p>";
    echo "<p><b>URL configurada:</b> $webhook_url</p>";
} else {
    $err_data = json_decode($resposta, true);
    echo "<h2 style='color:#ef4444;'>Erro no Registro (Código $http_code)</h2>";
    echo "<div style='background:#fee2e2; padding:15px; border-radius:8px; border:1px solid #fecaca;'>";
    echo "<b>Detalhes:</b> " . ($err_data['mensagem'] ?? htmlspecialchars($resposta));
    if(isset($err_data['erros'])) {
        echo "<pre style='font-size: 12px; margin-top: 10px;'>" . print_r($err_data['erros'], true) . "</pre>";
    }
    echo "</div>";
}
echo "</div></body>";
?>