<?php
require 'conexao.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Método inválido'); }

$tenant_id = (int)($_POST['tenant_id'] ?? 0);
$slug = $_POST['slug'] ?? '';
$nome = trim($_POST['nome'] ?? '');
$email = trim($_POST['email'] ?? '');
$cpf = trim($_POST['cpf_cnpj'] ?? '');
$pedido = trim($_POST['pedido'] ?? '');

if ($tenant_id <= 0 || $nome === '' || $email === '' || $pedido === '') { http_response_code(400); exit('Dados obrigatórios ausentes.'); }

$protocolo = 'LAI-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 12));
$prazo_resposta = (new DateTime('now'))->modify('+20 days')->format('Y-m-d H:i:s');

$iv_b64 = '';
$pedido_cipher = encrypt_payload($pedido, $iv_b64);

$sql = "INSERT INTO lai_pedidos
(tenant_id, protocolo, nome_solicitante, email_solicitante, cpf_cnpj, pedido_cipher, pedido_iv, status, prazo_resposta, created_at)
VALUES (?, ?, ?, ?, ?, ?, ?, 'Recebido', ?, NOW())";
$stmt = $pdo->prepare($sql);
$stmt->execute([$tenant_id, $protocolo, $nome, $email, ($cpf?:null), $pedido_cipher, $iv_b64, $prazo_resposta]);

header("Location: index.php?empresa=" . urlencode($slug) . "&status=lai_sucesso&protocolo_lai=" . urlencode($protocolo));
exit;
?>