<?php
require 'config.php';
require 'gateway.php'; 

$token = efi_obterToken();
if (!$token) die("Erro ao gerar Token. Verifique se o arquivo .p12 está na mesma pasta deste script.");

// 1. URL EXATA (Verifique se seu site usa WWW ou não e ajuste aqui)
$link_webhook = "https://meugabinetedigital.com.br/webhook_efi.php?v=" . time();
$chave_pix = trim(EFI_CHAVE_PIX);

$ch = curl_init();

// Endpoint de cadastro
curl_setopt($ch, CURLOPT_URL, "https://pix.api.efipay.com.br/v2/webhook/" . $chave_pix);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["webhookUrl" => $link_webhook]));

// Certificado .p12
$caminho_cert = __DIR__ . '/' . trim(EFI_CERTIFICADO);
curl_setopt($ch, CURLOPT_SSLCERT, $caminho_cert);
curl_setopt($ch, CURLOPT_SSLCERTTYPE, "P12");
curl_setopt($ch, CURLOPT_SSLCERTPASSWD, "");

// HEADERS CRUCIAIS PARA SKIP-MTLS
// Note o uso de "x-skip-mtls" em minúsculas e sem espaços
$headers = [
    "Authorization: Bearer " . $token,
    "Content-Type: application/json",
    "x-skip-mtls: true" 
];

curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

// Ignora erros de SSL do lado do servidor (comum em HostGator)
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

$resposta = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<h2>Resultado do Registro Forçado</h2>";
echo "HTTP Code: $http_code <br>";
echo "<pre>";
print_r(json_decode($resposta, true));
echo "</pre>";

if ($http_code == 204 || $http_code == 200) {
    echo "<h3 style='color:green'>✅ WEBHOOK REGISTRADO!</h3>";
}
?>