<?php
session_start();
require 'config.php';
require 'protecao.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['tenant_id'])) { http_response_code(403); echo json_encode(['ok'=>false]); exit; }
$tenant_id = (int)$_SESSION['tenant_id'];

$design_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($design_id <= 0) { http_response_code(400); echo json_encode(['ok'=>false]); exit; }

$stmt = $pdo->prepare("SELECT id, titulo, json FROM designs WHERE id=? AND tenant_id=? LIMIT 1");
$stmt->execute([$design_id, $tenant_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) { http_response_code(404); echo json_encode(['ok'=>false]); exit; }

echo json_encode(['ok'=>true,'id'=>$row['id'],'titulo'=>$row['titulo'],'json'=>$row['json']]);
