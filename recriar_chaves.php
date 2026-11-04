<?php
// Exibe erros para acompanharmos
ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'config.php';

// 🛑 COLOQUE SUAS CHAVES MESTRAS AQUI
$user_auth_key = "os_v2_org_zd6bbm4nbrcbzbxrasaqgxzid5hqn5v577oei25qgfgtcapoc5h4esnldf74qsi6iig4oheebp4lptorrrws4mqcg6ubmrjwcl6imkq"; 
$org_id = "c8fc10b3-8d0c-441c-86f1-0481035f281f"; 

function forcarCriacaoApp($nome, $url, $auth, $org) {
    $dados = [
        'name' => 'Gabinete - ' . $nome . ' (Auto)',
        'organization_id' => $org,
        'site_name' => 'Gabinete Digital',
        'chrome_web_origin' => $url,
        'safari_site_origin' => $url
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.onesignal.com/apps");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Basic ' . $auth
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dados));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE); 
    
    $resposta = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($resposta, true);
}

echo "<div style='font-family: sans-serif; padding: 20px;'>";
echo "<h2>🛠️ Recriando Chaves Corrompidas no OneSignal...</h2>";

// Busca no banco APENAS os clientes que estão com a API KEY vazia/nula
$stmt = $pdo->query("
    SELECT c.tenant_id, c.nome_vereador, t.slug 
    FROM configuracoes c 
    INNER JOIN tenants t ON c.tenant_id = t.id 
    WHERE c.onesignal_api_key IS NULL OR c.onesignal_api_key = ''
");
$clientes_quebrados = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($clientes_quebrados) == 0) {
    echo "<h3 style='color: green;'>Nenhum cliente com chave faltando! Tudo perfeito.</h3>";
} else {
    foreach($clientes_quebrados as $cliente) {
        $dominio = str_replace('www.', '', $_SERVER['HTTP_HOST']);
        $url_completa = "https://" . $cliente['slug'] . "." . $dominio;

        echo "⏳ Gerando NOVO App para: <b>" . $cliente['nome_vereador'] . "</b> ($url_completa)... <br>";

        // Manda o OneSignal criar um App zerado
        $resultado = forcarCriacaoApp($cliente['nome_vereador'], $url_completa, $user_auth_key, $org_id);

        if(isset($resultado['id']) && isset($resultado['basic_auth_key'])) {
            // Atualiza o banco com o NOVO App ID e a NOVA API Key (agora que o banco tem espaço)
            $update = $pdo->prepare("UPDATE configuracoes SET onesignal_app_id = ?, onesignal_api_key = ? WHERE tenant_id = ?");
            $update->execute([$resultado['id'], $resultado['basic_auth_key'], $cliente['tenant_id']]);
            
            echo "<span style='color:green;'>✅ Sucesso! Novas chaves salvas no banco.</span><br><hr>";
        } else {
            echo "<span style='color:red;'>❌ Falha na criação. Retorno: " . json_encode($resultado) . "</span><br><hr>";
        }
    }
    echo "<h3>🚀 Processo Finalizado com Sucesso!</h3>";
    echo "<p style='color: red; font-weight: bold;'>Pode apagar este arquivo 'recriar_chaves.php' do servidor agora.</p>";
}
echo "</div>";
?>