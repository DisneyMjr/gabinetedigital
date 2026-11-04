<div id="cardAniversario" style="width: 600px; height: 600px; background: var(--primary-blue); position: relative; color: white; overflow: hidden;">
    <div style="position: absolute; top: -50px; right: -50px; width: 200px; height: 200px; background: var(--accent-yellow); border-radius: 50%; opacity: 0.3;"></div>
    
    <div style="padding: 40px; text-align: center;">
        <h2 style="font-family: 'Montserrat'; font-size: 40px; margin-bottom: 0;">FELIZ</h2>
        <h1 style="font-family: 'Montserrat'; font-size: 60px; color: var(--accent-yellow); margin-top: 0;">ANIVERSÁRIO!</h1>
        
        <div style="width: 200px; height: 200px; border: 8px solid white; border-radius: 50%; margin: 20px auto; background: url('foto_eleitor.jpg') center/cover;"></div>
        
        <p style="font-size: 22px; font-weight: bold;">Parabéns, [NOME DO ELEITOR]!</p>
        <p style="font-size: 16px; opacity: 0.9;">Muita saúde, paz e prosperidade em sua vida.</p>
    </div>

    <div style="position: absolute; bottom: 0; width: 100%; background: rgba(0,0,0,0.2); padding: 15px; display: flex; align-items: center; justify-content: center;">
        <img src="<?= $foto_hero ?>" style="width: 50px; height: 50px; border-radius: 50%; margin-right: 15px;">
        <div style="text-align: left;">
            <p style="margin: 0; font-weight: bold; font-size: 14px;"><?= $site_config['nome_vereador'] ?></p>
            <small style="font-size: 12px;"><?= $site_config['hero_cargo'] ?></small>
        </div>
    </div>
</div>