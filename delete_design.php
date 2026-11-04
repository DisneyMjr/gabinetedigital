<?php
session_start();
require 'config.php';
require 'protecao.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['tenant_id'])) { http_response_code(403); echo json_encode(['ok'=>false]); exit; }
$tenant_id = (int)$_SESSION['tenant_id'];

$design_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($design_id<=0) { http_response_code(400); echo json_encode(['ok'=>false]); exit; }

$stmt = $pdo->prepare("SELECT thumb_path, image_path FROM designs WHERE id=? AND tenant_id=? LIMIT 1");
$stmt->execute([$design_id, $tenant_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); echo json_encode(['ok'=>false]); exit; }

$del = $pdo->prepare("DELETE FROM designs WHERE id=? AND tenant_id=?");
$del->execute([$design_id, $tenant_id]);

foreach (['thumb_path','image_path'] as $k) {
  if (!empty($row[$k])) {
    $p = __DIR__ . '/' . $row[$k];
    if (is_file($p)) @unlink($p);
  }
}

echo json_encode(['ok'=>true]);
