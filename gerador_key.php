<?php
// --- FERRAMENTA DO DESENVOLVEDOR ---
// Use isso para gerar o código que você vai colar no 'seguranca_licenca.php'

define('SALT_SECRETO', 'GABINETE_DIGITAL_2026_@#$'); // Tem que ser IGUAL ao outro arquivo

$dominio_cliente = isset($_GET['dominio']) ? $_GET['dominio'] : 'localhost';
$dominio_cliente = str_replace('www.', '', $dominio_cliente);

$hash_final = md5($dominio_cliente . SALT_SECRETO);
?>

<h1>Gerador de Licença</h1>
<form>
    <label>Domínio do Cliente (sem http/www):</label><br>
    <input type="text" name="dominio" value="<?= $dominio_cliente ?>" style="width:300px; padding:10px;">
    <button>Gerar Hash</button>
</form>

<hr>
<h3>Copie o código abaixo e cole na variável <code>$licenca_autorizada</code>:</h3>
<div style="background:#eee; padding:20px; font-family:monospace; font-size:18px; border:2px solid #333;">
    <?= $hash_final ?>
</div>