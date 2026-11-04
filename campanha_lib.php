<?php
function campanha_h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function campanha_slug($v){$v=trim((string)$v);if(function_exists('iconv')){$x=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$v);if($x!==false)$v=$x;}$v=strtolower($v);$v=preg_replace('/[^a-z0-9]+/','-',$v);return trim($v,'-')?:'campanha';}
function campanha_tel($v){return preg_replace('/\D+/','',(string)$v);}
function campanha_config(PDO $pdo,int $tenant){$s=$pdo->prepare("SELECT * FROM campanha_sites WHERE tenant_id=? LIMIT 1");$s->execute([$tenant]);return $s->fetch(PDO::FETCH_ASSOC)?:null;}
function campanha_upload($campo,$pasta='geral'){
 if(empty($_FILES[$campo])||($_FILES[$campo]['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)return null;
 $ext=strtolower(pathinfo($_FILES[$campo]['name']??'',PATHINFO_EXTENSION));
 $ok=['jpg','jpeg','png','webp','gif','pdf','mp3','mp4','zip']; if(!in_array($ext,$ok,true))throw new Exception('Arquivo não permitido.');
 $dir=__DIR__.'/uploads/campanha/'.preg_replace('/[^a-z0-9_-]/i','',$pasta); if(!is_dir($dir))mkdir($dir,0755,true);
 $nome=date('YmdHis').'_'.bin2hex(random_bytes(4)).'.'.$ext; if(!move_uploaded_file($_FILES[$campo]['tmp_name'],$dir.'/'.$nome))throw new Exception('Falha no upload.');
 return 'uploads/campanha/'.preg_replace('/[^a-z0-9_-]/i','',$pasta).'/'.$nome;
}
function campanha_resolver_ref(PDO $pdo,int $tenant,$ref){
 $ref=trim((string)$ref); if($ref==='')return null; $s=$pdo->prepare("SELECT id FROM eleitores WHERE tenant_id=?");$s->execute([$tenant]);
 while($id=$s->fetchColumn()){if(hash_equals(md5((string)$id),$ref))return (int)$id;} return null;
}
function campanha_integrar_eleitor(PDO $pdo,int $tenant,array $d,?int $ref=null){
 $nome=trim($d['nome']??'');$zap=campanha_tel($d['whatsapp']??'');$email=trim($d['email']??'');$cidade=trim($d['cidade']??'');$bairro=trim($d['bairro']??'');$interesse=trim($d['interesse']??'Apoiador');
 if($nome==='')return null;$id=null;
 if($zap!==''){$s=$pdo->prepare("SELECT id FROM eleitores WHERE tenant_id=? AND whatsapp=? LIMIT 1");$s->execute([$tenant,$zap]);$id=$s->fetchColumn();}
 if(!$id&&$email!==''){$s=$pdo->prepare("SELECT id FROM eleitores WHERE tenant_id=? AND email=? LIMIT 1");$s->execute([$tenant,$email]);$id=$s->fetchColumn();}
 $tag='SITE_CAMPANHA,'.strtoupper(preg_replace('/[^A-Za-z0-9_]+/','_',$interesse));
 if($id){$s=$pdo->prepare("UPDATE eleitores SET tags=CONCAT_WS(',',NULLIF(tags,''),?), responsavel_id=IF(responsavel_id>0,responsavel_id,?) WHERE id=? AND tenant_id=?");$s->execute([$tag,$ref?:0,$id,$tenant]);return (int)$id;}
 $s=$pdo->prepare("INSERT INTO eleitores (tenant_id,nome,whatsapp,email,bairro,cidade,responsavel_id,status_voto,tags,observacoes) VALUES (?,?,?,?,?,?,?,?,?,?)");
 $s->execute([$tenant,$nome,$zap,$email,$bairro,$cidade,$ref?:0,1,$tag,'Cadastro realizado pelo site de campanha.']);return (int)$pdo->lastInsertId();
}
