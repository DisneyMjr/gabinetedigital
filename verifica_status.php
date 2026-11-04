<?php
// verifica_status.php
require 'config.php';
if (!isset($_GET['id'])) die("pendente");

$stmt = $pdo->prepare("SELECT status FROM tenants WHERE id = ?");
$stmt->execute([(int)$_GET['id']]);
echo $stmt->fetchColumn(); // Irá imprimir "ativo" ou "pendente_pagamento"
?>