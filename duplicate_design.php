<?php
session_start();
require 'config.php';
require 'protecao.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['tenant_id'])) { http_response_code(403); echo json_encode(['ok'=>false]); exit; }
$tenant_id = (int)$_SESSION['tenant_id'];

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id<=0) { http_response_code(400); echo json_encode(['ok'=>false]); exit; }

$stmt = $pdo->prepare("SELECT titulo, json FROM designs WHERE id=? AND tenant_id=? LIMIT 1");
$stmt->execute([$id, $tenant_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); echo json_encode(['ok'=>false]); exit; }

$newTitle = $row['titulo'] . ' (cópia)';
$ins = $pdo->prepare("INSERT INTO designs (tenant_id, titulo, json) VALUES (?, ?, ?)");
$ins->execute([$tenant_id, $newTitle, $row['json']]);
echo json_encode(['ok'=>true,'new_id'=>(int)$pdo->lastInsertId()]);
