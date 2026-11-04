<?php
// ==========================================
// 1. CONFIGURAÇÕES DA EFÍ (PREENCHA AQUI)
// ==========================================
$client_id = "Client_Id_0620faa3bb7d5a7c88473ac169fa26d844984b54";
$client_secret = "Client_Secret_a4b0c48ff87d0fffe312a6a3f4f7c3bd41fab4a7";
$nome_ficheiro_certificado = "producao-657093-GabineteDigital.p12"; 
$chave_pix = "27007d53-ca61-44f8-b662-1e9e0acb683d"; 

$url_do_seu_webhook = "https://meugabinetedigital.com.br/webhook_efi"; // Já sem o .php (que deu certo!)
$url_base = "https://pix.api.efipay.com.br"; // Servidor de Produção

echo "<h3>A iniciar a configuração do Webhook na Efí...</h3>";

// --- VERIFICAÇÃO DE SEGURANÇA DO CERTIFICADO ---
$caminho_absoluto = realpath(__DIR__ . '/' . $nome_ficheiro_certificado);

if (!$caminho_absoluto) {
    die("<div style='background:#ffebee; color:#c62828; padding:20px; border-radius:8px;'>
         <b>❌ ERRO GRAVE:</b> O sistema não conseguiu encontrar o ficheiro do certificado.
         </div>");
}

echo "<p style='color:blue;'>✓ Certificado encontrado em: $caminho_absoluto</p>";

// ==========================================
// 2. OBTER TOKEN DE ACESSO
// ==========================================
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url_base . "/oauth/token");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSLCERT, $caminho_absoluto); 
curl_setopt($ch, CURLOPT_SSLCERTTYPE, "P12");
curl_setopt($ch, CURLOPT_SSLCERTPASSWD, ""); 
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['grant_type' => 'client_credentials']));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Basic ' . base64_encode($client_id . ':' . $client_secret),
    'Content-Type: application/json'
]);

// Ignorar verificação de host local para evitar bloqueios do cURL
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

$response = curl_exec($ch);

if ($response === false) {
    die("<b>❌ ERRO NO SERVIDOR (cURL):</b> " . curl_error($ch));
}

$auth = json_decode($response, true);

if(!isset($auth['access_token'])) {
    die("<b style='color:red;'>Erro na Autenticação Efí:</b> Credenciais inválidas ou ambiente incorreto.<br><br>Retorno da Efí: " . $response);
}
$token = $auth['access_token'];
echo "<p style='color:green;'>✓ Token gerado com sucesso!</p>";

// ==========================================
// 3. ENVIAR O URL DO WEBHOOK PARA A EFÍ (COM A MÁGICA SKIP mTLS)
// ==========================================
curl_setopt($ch, CURLOPT_URL, $url_base . "/v2/webhook/" . $chave_pix);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Content-Type: application/json',
    'x-skip-mtls-checking: true' // <--- ESTA É A LINHA QUE SALVA A VIDA EM HOSPEDAGEM COMPARTILHADA!
]);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["webhookUrl" => $url_do_seu_webhook]));
$response_webhook = curl_exec($ch);

if ($response_webhook === false) {
    die("<b style='color:red;'>Erro ao enviar webhook:</b> " . curl_error($ch));
}

curl_close($ch);
$resultado = json_decode($response_webhook, true);

// ==========================================
// 4. EXIBIR O RESULTADO
// ==========================================
echo "<h3>Resposta da Efí:</h3>";
echo "<pre style='background:#f4f4f4; padding:15px; border:1px solid #ccc; border-radius:8px;'>";
print_r($resultado);
echo "</pre>";

if(isset($resultado['webhookUrl'])) {
    echo "<h2 style='color:green;'>🎉 Parabéns! O seu Webhook foi configurado com sucesso!</h2>";
    echo "<p>Agora o robô da Efí está treinado para avisar o seu sistema a cada PIX pago.</p>";
} else {
    echo "<h2 style='color:red;'>⚠️ Ops! Algo correu mal no registo do Webhook.</h2>";
}
?>