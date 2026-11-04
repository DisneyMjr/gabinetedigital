<?php
session_start();
require 'mercadopago.php';

if (!isset($_SESSION['tenant_id'])) {
    header('Location: login.php'); exit;
}
$tenantId = (int)$_SESSION['tenant_id'];
$action = $_POST['acao'] ?? '';
if (!in_array($action, ['pause', 'resume', 'cancel'], true)) {
    header('Location: admin_assinatura.php?erro=acao'); exit;
}
$stmt = $pdo->prepare("SELECT mp_subscription_id FROM tenants WHERE id = ?");
$stmt->execute([$tenantId]);
$subId = $stmt->fetchColumn();
if (!$subId) {
    header('Location: admin_assinatura.php?erro=sem_assinatura'); exit;
}
$status = $action === 'pause' ? 'paused' : ($action === 'resume' ? 'authorized' : 'cancelled');
$result = mp_api($pdo, 'PUT', '/preapproval/' . rawurlencode($subId), ['status' => $status]);
if ($result['ok']) {
    mp_update_tenant_subscription($pdo, $tenantId, $result['data']);
    if ($action === 'cancel') {
        $pdo->prepare("UPDATE tenants SET mp_subscription_status = 'cancelled' WHERE id = ?")->execute([$tenantId]);
    }
    header('Location: admin_assinatura.php?ok=' . urlencode($action)); exit;
}
header('Location: admin_assinatura.php?erro=' . urlencode($result['error']));
