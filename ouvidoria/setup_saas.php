<?php
// setup_saas.php - RODE UMA VEZ E APAGUE
require 'conexao.php';

$email = 'disneymjr@gmail.com';
$senha = 'Cmjr&2286'; // Senha que você vai usar
$hash = password_hash($senha, PASSWORD_DEFAULT);

try {
    $sql = "INSERT INTO super_admins (email, senha_hash) VALUES (?, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$email, $hash]);
    echo "<h1>Sucesso!</h1> Login: $email <br> Senha: $senha <br><br> <a href='saas_login.php'>Ir para Login</a>";
} catch (Exception $e) {
    echo "Erro (talvez já exista): " . $e->getMessage();
}
?>