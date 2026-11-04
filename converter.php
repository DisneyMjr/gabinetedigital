<?php
$p12_path = __DIR__ . '/producao-657093-GabineteDigital.p12';
$p12_content = file_get_contents($p12_path);
$password = ''; // Efí usa senha vazia por padrão

if (openssl_pkcs12_read($p12_content, $certs, $password)) {
    // Esses são os arquivos que o cURL vai "carregar no bolso"
    file_put_contents(__DIR__ . '/cert_gabinete.pem', $certs['cert']);
    file_put_contents(__DIR__ . '/key_gabinete.pem', $certs['pkey']);
    echo "✅ Sucesso! cert_gabinete.pem e key_gabinete.pem gerados na raiz.";
} else {
    echo "❌ Erro ao ler o arquivo .p12.";
}