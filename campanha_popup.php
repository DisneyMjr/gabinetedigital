<?php
// Componente do popup de captação.
// Inclua este arquivo em site_campanha.php, antes do <footer> ou antes de </body>.
if (!isset($cfg, $tenant)) return;
if (empty($cfg['popup_ativo'])) return;

$popupTitulo = trim((string)($cfg['popup_titulo'] ?? ''));
if ($popupTitulo === '') $popupTitulo = 'VOTE ' . (string)($cfg['numero_eleitoral'] ?? '');
$popupTexto = trim((string)($cfg['popup_texto'] ?? ''));
if ($popupTexto === '') $popupTexto = 'Entre para a lista oficial e receba conteúdos, agenda e mensagens da campanha direto no WhatsApp.';
$popupBotao = trim((string)($cfg['popup_botao'] ?? 'ENTRAR NA LISTA')) ?: 'ENTRAR NA LISTA';
$popupFundo = $cfg['popup_cor_fundo'] ?? '#0B2C6B';
$popupDestaque = $cfg['popup_cor_destaque'] ?? '#FF7A00';
$popupDelay = max(0, (int)($cfg['popup_delay_segundos'] ?? 3));
$popupUmaVez = !empty($cfg['popup_exibir_uma_vez']);
$popupNome = !empty($cfg['popup_pedir_nome']);
$popupEmail = !empty($cfg['popup_pedir_email']);
$popupWhatsapp = !empty($cfg['popup_pedir_whatsapp']);
?>
<style>
#campanhaPopupBackdrop{position:fixed;inset:0;background:rgba(3,10,35,.82);backdrop-filter:blur(7px);z-index:99998;display:none;align-items:center;justify-content:center;padding:18px}
#campanhaPopup{width:min(430px,100%);max-height:92vh;overflow:auto;position:relative;background:<?= campanha_h($popupFundo) ?>;color:#fff;padding:34px 34px 38px;box-shadow:0 28px 80px rgba(0,0,0,.45);border:1px solid rgba(255,255,255,.10)}
#campanhaPopup:after{content:"";position:absolute;right:-88px;bottom:-150px;width:190px;height:540px;background:<?= campanha_h($popupDestaque) ?>;transform:rotate(28deg);z-index:0}
#campanhaPopup .cp-inner{position:relative;z-index:2}
#campanhaPopup .cp-close{position:absolute;right:16px;top:16px;z-index:4;width:42px;height:42px;border:0;background:<?= campanha_h($popupDestaque) ?>;font-size:28px;line-height:1;color:#071324}
#campanhaPopup .cp-kicker{font-size:.76rem;font-weight:900;letter-spacing:.12em;color:<?= campanha_h($popupDestaque) ?>;text-transform:uppercase;margin-bottom:12px}
#campanhaPopup h2{font-size:clamp(2.6rem,8vw,4rem);line-height:.92;font-weight:900;letter-spacing:-.04em;margin:0 0 16px}
#campanhaPopup p{font-size:1rem;line-height:1.5;color:rgba(255,255,255,.88)}
#campanhaPopup label{display:block;font-size:.68rem;font-weight:900;letter-spacing:.12em;color:<?= campanha_h($popupDestaque) ?>;text-transform:uppercase;margin:12px 0 5px}
#campanhaPopup .cp-input{width:100%;border:2px solid rgba(255,122,0,.35);background:rgba(255,255,255,.96);padding:14px 14px;color:#111;outline:none}
#campanhaPopup .cp-terms{font-size:.72rem;color:#fff;display:flex;gap:8px;align-items:flex-start;margin:13px 0}
#campanhaPopup .cp-submit{width:100%;border:0;background:<?= campanha_h($popupDestaque) ?>;color:#091220;font-weight:900;letter-spacing:.10em;padding:16px 20px;text-transform:uppercase}
#campanhaPopup .cp-submit:disabled{opacity:.6}
#campanhaPopup .cp-msg{margin-top:12px}
@media(max-width:575px){#campanhaPopup{padding:28px 22px 28px}#campanhaPopup h2{font-size:3rem}}
</style>

<div id="campanhaPopupBackdrop" aria-hidden="true">
  <div id="campanhaPopup" role="dialog" aria-modal="true" aria-labelledby="campanhaPopupTitulo">
    <button type="button" class="cp-close" aria-label="Fechar" onclick="fecharCampanhaPopup()">×</button>
    <div class="cp-inner">
      <?php if (!empty($cfg['logo_campanha'])): ?>
        <img src="<?= campanha_h($cfg['logo_campanha']) ?>" alt="" style="max-height:65px;max-width:230px;margin-bottom:28px">
      <?php endif; ?>
      <div class="cp-kicker">Lista oficial</div>
      <h2 id="campanhaPopupTitulo"><?= campanha_h($popupTitulo) ?></h2>
      <p><?= nl2br(campanha_h($popupTexto)) ?></p>

      <form id="campanhaPopupForm" onsubmit="return enviarCampanhaPopup(this)">
        <input type="hidden" name="tenant_id" value="<?= (int)$tenant ?>">
        <input type="hidden" name="ref" value="<?= campanha_h($ref ?? '') ?>">
        <input type="hidden" name="tipo" value="lead">
        <input type="hidden" name="interesse" value="Popup_Site">

        <?php if ($popupNome): ?>
          <label>Nome</label>
          <input class="cp-input" name="nome" placeholder="Seu nome" <?= $popupNome ? 'required' : '' ?>>
        <?php endif; ?>

        <?php if ($popupEmail): ?>
          <label>E-mail</label>
          <input class="cp-input" type="email" name="email" placeholder="seu@email.com">
        <?php endif; ?>

        <?php if ($popupWhatsapp): ?>
          <label>WhatsApp</label>
          <input class="cp-input" name="whatsapp" placeholder="11999999999">
        <?php endif; ?>

        <label class="cp-terms"><input type="checkbox" required> <span>Li e aceito os termos e a política de privacidade.</span></label>
        <button class="cp-submit" type="submit"><?= campanha_h($popupBotao) ?> &nbsp;→</button>
        <div class="cp-msg"></div>
      </form>
    </div>
  </div>
</div>

<script>
(function(){
  const key='mgd_popup_<?= (int)$tenant ?>';
  const umaVez=<?= $popupUmaVez ? 'true' : 'false' ?>;
  const delay=<?= (int)$popupDelay * 1000 ?>;
  if(umaVez && localStorage.getItem(key)==='1') return;
  setTimeout(function(){
    const el=document.getElementById('campanhaPopupBackdrop');
    if(!el) return;
    el.style.display='flex';
    el.setAttribute('aria-hidden','false');
  },delay);
  window.fecharCampanhaPopup=function(){
    const el=document.getElementById('campanhaPopupBackdrop');
    if(el){el.style.display='none';el.setAttribute('aria-hidden','true');}
    if(umaVez)localStorage.setItem(key,'1');
  };
  document.getElementById('campanhaPopupBackdrop')?.addEventListener('click',function(e){if(e.target===this)fecharCampanhaPopup();});
  document.addEventListener('keydown',function(e){if(e.key==='Escape')fecharCampanhaPopup();});
  window.enviarCampanhaPopup=async function(form){
    const btn=form.querySelector('.cp-submit'), msg=form.querySelector('.cp-msg'), old=btn.innerHTML;
    btn.disabled=true;btn.innerHTML='ENVIANDO...';msg.innerHTML='';
    try{
      const r=await fetch('campanha_form_submit.php',{method:'POST',body:new FormData(form)});
      const j=await r.json();
      msg.innerHTML='<div style="padding:10px;background:'+(j.ok?'#d1fae5':'#fee2e2')+';color:#111">'+j.mensagem+'</div>';
      if(j.ok){localStorage.setItem(key,'1');setTimeout(fecharCampanhaPopup,1200);}
    }catch(e){msg.innerHTML='<div style="padding:10px;background:#fee2e2;color:#111">Não foi possível enviar.</div>';}
    btn.disabled=false;btn.innerHTML=old;return false;
  };
})();
</script>
