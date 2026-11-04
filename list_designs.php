<?php
session_start();
require 'config.php';
require 'protecao.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['tenant_id'])) { http_response_code(403); echo json_encode(['ok'=>false]); exit; }
$tenant_id = (int)$_SESSION['tenant_id'];

$stmt = $pdo->prepare("SELECT id, titulo, thumb_path, updated_at FROM designs WHERE tenant_id=? ORDER BY updated_at DESC LIMIT 200");
$stmt->execute([$tenant_id]);
echo json_encode(['ok'=>true,'items'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
