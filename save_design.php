<?php
session_start();
require 'config.php';
require 'protecao.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['tenant_id'])) { http_response_code(403); echo json_encode(['ok'=>false,'err'=>'tenant']); exit; }
$tenant_id = (int)$_SESSION['tenant_id'];

$title = trim($_POST['title'] ?? 'Sem título');
$json  = $_POST['json'] ?? '';
$design_id = isset($_POST['design_id']) && $_POST['design_id'] !== '' ? (int)$_POST['design_id'] : null;

$thumb_b64 = $_POST['thumb_base64'] ?? '';
$image_b64 = $_POST['image_base64'] ?? ''; // opcional (PNG final)

if ($json === '' || $thumb_b64 === '') {
  http_response_code(400);
  echo json_encode([
    'ok'=>false,
    'err'=>'missing',
    'debug'=>[
      'has_json'=> ($json !== ''),
      'has_thumb'=> ($thumb_b64 !== ''),
      'len_json'=> strlen($json),
      'len_thumb'=> strlen($thumb_b64),
      'keys'=> array_keys($_POST)
    ]
  ]);
  exit;
}


function b64_to_png($dataUrl) {
  if (!str_starts_with($dataUrl, 'data:image/')) return null;
  $parts = explode(',', $dataUrl, 2);
  if (count($parts) !== 2) return null;
  $bin = base64_decode($parts[1], true);
  return $bin === false ? null : $bin;
}

$thumb_bin = b64_to_png($thumb_b64);
if (!$thumb_bin) { http_response_code(400); echo json_encode(['ok'=>false,'err'=>'thumb']); exit; }

$image_bin = null;
if ($image_b64 !== '') {
  $image_bin = b64_to_png($image_b64);
  if (!$image_bin) { http_response_code(400); echo json_encode(['ok'=>false,'err'=>'image']); exit; }
}

$baseDir = __DIR__ . "/uploads/designs/{$tenant_id}";
if (!is_dir($baseDir)) mkdir($baseDir, 0755, true);

try {
  $pdo->beginTransaction();

  if ($design_id) {
    // garante tenant
    $stmt = $pdo->prepare("SELECT id, thumb_path, image_path FROM designs WHERE id=? AND tenant_id=? LIMIT 1");
    $stmt->execute([$design_id, $tenant_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { $pdo->rollBack(); http_response_code(404); echo json_encode(['ok'=>false,'err'=>'notfound']); exit; }

    $thumbFile = "thumb_{$design_id}_" . time() . ".png";
    $thumbPath = "uploads/designs/{$tenant_id}/{$thumbFile}";
    file_put_contents(__DIR__ . "/{$thumbPath}", $thumb_bin);

    $imgPath = $row['image_path'];
    if ($image_bin) {
      $imgFile = "arte_{$design_id}_" . time() . ".png";
      $imgPath = "uploads/designs/{$tenant_id}/{$imgFile}";
      file_put_contents(__DIR__ . "/{$imgPath}", $image_bin);
    }

    $upd = $pdo->prepare("UPDATE designs SET titulo=?, json=?, thumb_path=?, image_path=? WHERE id=? AND tenant_id=?");
    $upd->execute([$title, $json, $thumbPath, $imgPath, $design_id, $tenant_id]);

  } else {
    $ins = $pdo->prepare("INSERT INTO designs (tenant_id, titulo, json) VALUES (?, ?, ?)");
    $ins->execute([$tenant_id, $title, $json]);
    $design_id = (int)$pdo->lastInsertId();

    $thumbFile = "thumb_{$design_id}_" . time() . ".png";
    $thumbPath = "uploads/designs/{$tenant_id}/{$thumbFile}";
    file_put_contents(__DIR__ . "/{$thumbPath}", $thumb_bin);

    $imgPath = null;
    if ($image_bin) {
      $imgFile = "arte_{$design_id}_" . time() . ".png";
      $imgPath = "uploads/designs/{$tenant_id}/{$imgFile}";
      file_put_contents(__DIR__ . "/{$imgPath}", $image_bin);
    }

    $upd = $pdo->prepare("UPDATE designs SET thumb_path=?, image_path=? WHERE id=? AND tenant_id=?");
    $upd->execute([$thumbPath, $imgPath, $design_id, $tenant_id]);
  }

  $pdo->commit();
  echo json_encode(['ok'=>true,'design_id'=>$design_id]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['ok'=>false,'err'=>'db','msg'=>$e->getMessage()]);
}
