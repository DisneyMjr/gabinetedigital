<?php
// Controle de duplicidade por tenant. Não divulga dados pessoais ao formulário público.
if (!function_exists('mgd_normalizar_whatsapp')) {
 function mgd_normalizar_whatsapp($v) { return preg_replace('/\D+/', '', (string)$v); }
 function mgd_localizar_duplicado(PDO $pdo, int $tenant, $whatsapp, $email='', int $ignorar=0) {
  $zap=mgd_normalizar_whatsapp($whatsapp); $email=mb_strtolower(trim((string)$email),'UTF-8');
  if (strlen($zap)>=10) {
   // Normaliza formatação antiga sem alterar dados já gravados.
   $sql="SELECT id FROM eleitores WHERE tenant_id=? AND id<>? AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(whatsapp,' ',''),'-',''),'(',''),')',''),'+','')=? LIMIT 1";
   $st=$pdo->prepare($sql); $st->execute([$tenant,$ignorar,$zap]); $id=$st->fetchColumn(); if ($id) return (int)$id;
  }
  if ($email!=='' && filter_var($email,FILTER_VALIDATE_EMAIL)) {
   $st=$pdo->prepare('SELECT id FROM eleitores WHERE tenant_id=? AND id<>? AND LOWER(TRIM(email))=? LIMIT 1');
   $st->execute([$tenant,$ignorar,$email]); $id=$st->fetchColumn(); if ($id) return (int)$id;
  }
  return 0;
 }
 function mgd_lock(PDO $pdo,int $tenant) {
  $st=$pdo->prepare('SELECT GET_LOCK(?,10)'); $st->execute(['mgd_eleitores_'.$tenant]);
  if ((int)$st->fetchColumn()!==1) throw new RuntimeException('Cadastro ocupado. Tente novamente.');
 }
 function mgd_unlock(PDO $pdo,int $tenant) {
  try { $st=$pdo->prepare('SELECT RELEASE_LOCK(?)'); $st->execute(['mgd_eleitores_'.$tenant]); } catch(Throwable $e) {}
 }
 function mgd_registrar_bloqueio(PDO $pdo,int $tenant,int $eleitor,int $lideranca,string $origem) {
  try { $st=$pdo->prepare('INSERT INTO eleitor_tentativas_duplicadas (tenant_id,eleitor_existente_id,lideranca_solicitante_id,origem) VALUES (?,?,?,?)'); $st->execute([$tenant,$eleitor,$lideranca?:null,$origem]); } catch(Throwable $e) { error_log('MGD auditoria duplicidade: '.$e->getMessage()); }
 }
}
