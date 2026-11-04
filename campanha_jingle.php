<?php
// campanha_jingle.php
// Requer $cfg carregado no site_campanha.php.

if (!empty($cfg['jingle_ativo']) && !empty($cfg['jingle_arquivo'])):
    $jingleVolume = max(0, min(100, (int)($cfg['jingle_volume'] ?? 60))) / 100;
    $jingleAutoplay = !empty($cfg['jingle_autoplay']);
    $jingleLoop = !empty($cfg['jingle_loop']);
    $jinglePlayer = !empty($cfg['jingle_mostrar_player']);
    $jingleSrc = (string)$cfg['jingle_arquivo'];

    // Compatibilidade caso algum registro antigo tenha sido salvo com caminho iniciado por "/".
    $jingleSrc = ltrim($jingleSrc, '/');
?>
<style>
#campanhaJinglePlayer{
    position:fixed;
    right:22px;
    bottom:22px;
    z-index:99999;
    display:flex;
    align-items:center;
    gap:10px;
    background:rgba(15,23,42,.96);
    color:#fff;
    padding:9px 15px 9px 9px;
    border-radius:999px;
    box-shadow:0 15px 40px rgba(0,0,0,.32);
    backdrop-filter:blur(12px);
}
#campanhaJinglePlayer button{
    width:44px;
    height:44px;
    border:0;
    border-radius:50%;
    display:grid;
    place-items:center;
    background:var(--a,#f59e0b);
    color:#111827;
    font-size:16px;
    cursor:pointer;
}
#campanhaJinglePlayer .jingle-title{
    display:block;
    font-weight:800;
    font-size:.78rem;
    line-height:1.15;
}
#campanhaJinglePlayer .jingle-status{
    display:block;
    opacity:.7;
    font-size:.68rem;
    line-height:1.15;
}
#jingleAutoplayNotice{
    position:fixed;
    left:50%;
    bottom:24px;
    transform:translateX(-50%);
    z-index:99998;
    background:#fff;
    color:#111827;
    border-radius:999px;
    padding:10px 18px;
    box-shadow:0 12px 30px rgba(0,0,0,.18);
    font-size:.84rem;
    display:none;
}
@media(max-width:576px){
    #campanhaJinglePlayer{right:12px;bottom:12px}
    #campanhaJinglePlayer .jingle-copy{display:none}
    #jingleAutoplayNotice{bottom:70px;width:calc(100% - 24px);text-align:center}
}
</style>

<audio id="campanhaJingleAudio"
       preload="auto"
       playsinline
       <?= $jingleLoop ? 'loop' : '' ?>>
    <source src="<?= campanha_h($jingleSrc) ?>" type="audio/mpeg">
</audio>

<?php if ($jinglePlayer): ?>
<div id="campanhaJinglePlayer">
    <button type="button" id="campanhaJingleToggle" aria-label="Tocar ou pausar jingle">
        <i class="fas fa-play" id="campanhaJingleIcon"></i>
    </button>
    <div class="jingle-copy">
        <span class="jingle-title">Jingle da campanha</span>
        <span class="jingle-status" id="campanhaJingleStatus">Clique para ouvir</span>
    </div>
</div>
<?php endif; ?>

<div id="jingleAutoplayNotice">
    <i class="fas fa-music me-1"></i>
    Toque na página para ouvir o jingle.
</div>

<script>
(function(){
    var audio = document.getElementById('campanhaJingleAudio');
    if (!audio) return;

    var btn = document.getElementById('campanhaJingleToggle');
    var icon = document.getElementById('campanhaJingleIcon');
    var status = document.getElementById('campanhaJingleStatus');
    var notice = document.getElementById('jingleAutoplayNotice');
    var autoplay = <?= $jingleAutoplay ? 'true' : 'false' ?>;

    audio.volume = <?= json_encode($jingleVolume) ?>;

    function atualizar() {
        var tocando = !audio.paused && !audio.ended;
        if (icon) icon.className = tocando ? 'fas fa-pause' : 'fas fa-play';
        if (status) status.textContent = tocando ? 'Tocando agora' : 'Clique para ouvir';
        if (tocando && notice) notice.style.display = 'none';
    }

    function tocar() {
        var p;
        try {
            p = audio.play();
        } catch (e) {
            return Promise.reject(e);
        }
        if (p && typeof p.then === 'function') return p;
        return Promise.resolve();
    }

    if (btn) {
        btn.addEventListener('click', function(e){
            e.preventDefault();
            e.stopPropagation();
            if (audio.paused) {
                tocar().catch(function(){});
            } else {
                audio.pause();
            }
        });
    }

    audio.addEventListener('play', atualizar);
    audio.addEventListener('pause', atualizar);
    audio.addEventListener('ended', atualizar);
    audio.addEventListener('error', function(){
        if (status) status.textContent = 'Erro ao carregar áudio';
        if (notice) {
            notice.textContent = 'Não foi possível carregar o jingle.';
            notice.style.display = 'block';
        }
    });

    if (autoplay) {
        tocar().then(atualizar).catch(function(){
            // Navegadores normalmente bloqueiam autoplay com som.
            if (notice) notice.style.display = 'block';

            var liberarAudio = function(){
                tocar().then(function(){
                    atualizar();
                }).catch(function(){});
            };

            document.addEventListener('pointerdown', liberarAudio, {once:true});
            document.addEventListener('touchstart', liberarAudio, {once:true, passive:true});
            document.addEventListener('keydown', liberarAudio, {once:true});
        });
    }

    atualizar();
})();
</script>
<?php endif; ?>
