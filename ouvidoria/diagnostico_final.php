<?php
// diagnostico_final.php - TESTE DE CONEXÃO DIRETA
$apiKey = 'AIzaSyBL8LN-bd-JnRhuZkFynhbf_mvxX5-7b0Q'; // <--- COLE SUA CHAVE AQUI

// Vamos tentar o modelo FLASH (V1BETA)
$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $apiKey;

$data = [
    "contents" => [
        ["parts" => [["text" => "Diga apenas a palavra: FUNCIONOU"]]]
    ]
];

$options = [
    "http" => [
        "header"  => "Content-Type: application/json\r\n",
        "method"  => "POST",
        "content" => json_encode($data),
        "ignore_errors" => true // Importante para ver o erro se houver
    ],
    "ssl" => [
        "verify_peer" => false,
        "verify_peer_name" => false
    ]
];

echo "<h1>Diagnóstico de Conexão (Sem cURL)</h1>";
echo "<p>Testando conexão com: <strong>$url</strong></p>";

$context  = stream_context_create($options);
$result = file_get_contents($url, false, $context);
$headers = $http_response_header; // Pega o código HTTP (200, 400, 404...)

echo "<h3>Resposta do Servidor:</h3>";

if ($result === FALSE) {
    echo "<h2 style='color:red'>FALHA TOTAL DE CONEXÃO</h2>";
    echo "O PHP não conseguiu nem sair do seu computador. Verifique sua internet ou firewall.";
} else {
    // Tenta ler o JSON
    $json = json_decode($result, true);
    
    // Mostra o cabeçalho HTTP (Status Code)
    echo "<div style='background:#ddd; padding:10px; margin-bottom:10px;'>Status: " . $headers[0] . "</div>";
    
    // Mostra a resposta crua
    echo "<textarea style='width:100%; height:150px;'>" . $result . "</textarea>";
    
    if (strpos($headers[0], '200') !== false) {
        echo "<h2 style='color:green'>✅ SUCESSO! A IA ESTÁ FUNCIONANDO.</h2>";
        echo "<p>Se apareceu o texto JSON acima, o problema era o cURL do XAMPP.</p>";
    } else {
        echo "<h2 style='color:red'>❌ ERRO NO GOOGLE.</h2>";
        echo "<p>Leia a mensagem de erro no quadro acima (campo 'message').</p>";
    }
}
?>