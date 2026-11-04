<?php
require 'config.php';
require 'campanha_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Vary: Origin');

function cdc_out($ok, $mensagem, $extra = [], $status = 200) {
    http_response_code($status);
    echo json_encode(array_merge(['ok'=>$ok,'mensagem'=>$mensagem], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}
function cdc_tel($v) { return preg_replace('/\D+/', '', (string)$v); }
function cdc_host($url) {
    $host = parse_url(trim((string)$url), PHP_URL_HOST);
    return $host ? strtolower(preg_replace('/^www\./i','',preg_replace('/:\d+$/','',(string)$host))) : '';
}
function cdc_cfg_host($dominio) {
    $dominio=trim((string)$dominio);
    if($dominio==='') return '';
    if(strpos($dominio,'://')===false) $dominio='https://'.$dominio;
    return cdc_host($dominio);
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        $origin=trim((string)($_SERVER['HTTP_ORIGIN']??''));
        if($origin!==''){
            header('Access-Control-Allow-Origin: '.$origin);
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        }
        http_response_code(204); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Método inválido.');

    $projetoId=(int)($_POST['projeto_id']??0);
    if($projetoId<=0) throw new Exception('Documento inválido.');

    $q=$pdo->prepare("SELECT p.id,p.tenant_id,p.ativo,p.exigir_cadastro_download,s.dominio_personalizado,s.ativo AS site_ativo
                      FROM campanha_projetos_leis p
                      INNER JOIN campanha_sites s ON s.tenant_id=p.tenant_id
                      WHERE p.id=? LIMIT 1");
    $q->execute([$projetoId]);
    $doc=$q->fetch(PDO::FETCH_ASSOC);
    if(!$doc || !(int)$doc['ativo'] || !(int)$doc['site_ativo']) throw new Exception('Documento indisponível.');
    $tenant=(int)$doc['tenant_id'];

    $origin=trim((string)($_SERVER['HTTP_ORIGIN']??''));
    if($origin!==''){
        $oh=cdc_host($origin); $custom=cdc_cfg_host($doc['dominio_personalizado']??'');
        $mgdOk=($oh==='meugabinetedigital.com.br' || substr($oh,-strlen('.meugabinetedigital.com.br'))==='.meugabinetedigital.com.br');
        $customOk=($custom!=='' && $oh===$custom);
        if(!$mgdOk && !$customOk) throw new Exception('Origem não autorizada.');
        header('Access-Control-Allow-Origin: '.$origin);
    }

    $nome=trim((string)($_POST['nome']??''));
    $whatsapp=cdc_tel($_POST['whatsapp']??'');
    $email=strtolower(trim((string)($_POST['email']??'')));
    $cidade=trim((string)($_POST['cidade']??''));
    $bairro=trim((string)($_POST['bairro']??''));
    $consent=(int)($_POST['consentimento_whatsapp']??0)===1 ? 1 : 0;

    if($nome==='') throw new Exception('Informe seu nome.');
    if($whatsapp==='' && $email==='') throw new Exception('Informe WhatsApp ou e-mail.');
    if($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new Exception('Informe um e-mail válido.');

    $cols=[];
    foreach($pdo->query("SHOW COLUMNS FROM campanha_leads")->fetchAll(PDO::FETCH_ASSOC) as $c) $cols[$c['Field']]=true;
    if(!isset($cols['token_visitante'])) throw new Exception('Execute o SQL de atualização do módulo antes de usar o download protegido.');

    $where=[]; $vals=[$tenant];
    if($whatsapp!==''){ $where[]="whatsapp=?"; $vals[]=$whatsapp; }
    if($email!==''){ $where[]="LOWER(email)=?"; $vals[]=$email; }
    $lead=null;
    if($where){
        $s=$pdo->prepare("SELECT * FROM campanha_leads WHERE tenant_id=? AND (".implode(' OR ',$where).") ORDER BY id DESC LIMIT 1");
        $s->execute($vals); $lead=$s->fetch(PDO::FETCH_ASSOC);
    }

    try { $token=bin2hex(random_bytes(32)); }
    catch(Throwable $e){ $token=hash('sha256',uniqid('',true).microtime(true)); }

    $pdo->beginTransaction();
    if($lead){
        $sets=["token_visitante=?"]; $v=[$token];
        if($nome!==''){ $sets[]="nome=?"; $v[]=$nome; }
        if($whatsapp!==''){ $sets[]="whatsapp=?"; $v[]=$whatsapp; }
        if($email!==''){ $sets[]="email=?"; $v[]=$email; }
        if($cidade!==''){ $sets[]="cidade=?"; $v[]=$cidade; }
        if($bairro!==''){ $sets[]="bairro=?"; $v[]=$bairro; }
        if(isset($cols['consentimento_whatsapp'])){ $sets[]="consentimento_whatsapp=?"; $v[]=$consent; }
        $v[]=(int)$lead['id']; $v[]=$tenant;
        $pdo->prepare("UPDATE campanha_leads SET ".implode(',',$sets)." WHERE id=? AND tenant_id=?")->execute($v);
        $leadId=(int)$lead['id'];
    } else {
        $d=['nome'=>$nome,'whatsapp'=>$whatsapp,'email'=>$email,'cidade'=>$cidade,'bairro'=>$bairro,'interesse'=>'Download_Projeto'];
        $ref=0;
        $eleitor=null;
        if(function_exists('campanha_integrar_eleitor')){
            try { $eleitor=campanha_integrar_eleitor($pdo,$tenant,$d,$ref); } catch(Throwable $e) { $eleitor=null; }
        }
        $fields=['tenant_id','nome','whatsapp','email','cidade','bairro','interesse','token_visitante'];
        $iv=[$tenant,$nome,$whatsapp,$email,$cidade,$bairro,'Download_Projeto',$token];
        if(isset($cols['origem'])){ $fields[]='origem'; $iv[]='download_projeto'; }
        if(isset($cols['ref_eleitor_id'])){ $fields[]='ref_eleitor_id'; $iv[]=null; }
        if(isset($cols['eleitor_id'])){ $fields[]='eleitor_id'; $iv[]=$eleitor; }
        if(isset($cols['consentimento_whatsapp'])){ $fields[]='consentimento_whatsapp'; $iv[]=$consent; }
        $ph=implode(',',array_fill(0,count($fields),'?'));
        $pdo->prepare("INSERT INTO campanha_leads (".implode(',',$fields).") VALUES ($ph)")->execute($iv);
        $leadId=(int)$pdo->lastInsertId();
    }
    $pdo->commit();

    $cookie='campanha_visitante_'.$tenant;
    $secure=(!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS'])!=='off');
    setcookie($cookie,$token,time()+31536000,'/','',$secure,true);

    cdc_out(true,'Cadastro reconhecido. Seu download foi liberado.',['download_url'=>'/projeto_download?id='.$projetoId,'lead_id'=>$leadId]);
} catch(Throwable $e){
    if(isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    cdc_out(false,$e->getMessage(),[],400);
}
