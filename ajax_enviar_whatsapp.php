<?php
// ajax_enviar_whatsapp.php
header('Content-Type: application/json');
require 'config.php';
require 'protecao.php';

$response = ['erro' => false, 'msg' => '', 'enviados_agora' => 0];

try {
    if (!tem_acesso('marketing')) throw new Exception("Sem permissão.");

    $id_campanha = $_POST['id'] ?? 0;
    $offset = $_POST['offset'] ?? 0;
    $limit = $_POST['limit'] ?? 1; // WhatsApp é mais lento, enviar 1 a cada X segundos é melhor

    // 1. Busca Configurações da API
    $stmtConf = $pdo->prepare("SELECT whatsapp_api_url, whatsapp_api_token, whatsapp_instancia FROM configuracoes WHERE tenant_id = ?");
    $stmtConf->execute([TENANT_ID]);
    $config = $stmtConf->fetch(PDO::FETCH_ASSOC);

    if (empty($config['whatsapp_api_url']) || empty($config['whatsapp_api_token'])) {
        throw new Exception("API do WhatsApp não configurada.");
    }

    // 2. Busca Campanha
    $stmt = $pdo->prepare("SELECT * FROM campanhas WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$id_campanha, TENANT_ID]);
    $campanha = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$campanha) throw new Exception("Campanha não encontrada.");

    // 3. Busca Eleitores (que tenham telefone)
    $sqlEleitores = "SELECT nome, whatsapp FROM eleitores 
                     WHERE tenant_id = :tenant 
                     AND whatsapp IS NOT NULL AND whatsapp != '' 
                     LIMIT :limit OFFSET :offset";
    
    $stmtEl = $pdo->prepare($sqlEleitores);
    $stmtEl->bindValue(':tenant', TENANT_ID, PDO::PARAM_INT);
    $stmtEl->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
    $stmtEl->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    $stmtEl->execute();
    $eleitores = $stmtEl->fetchAll(PDO::FETCH_ASSOC);

    if (count($eleitores) == 0) {
        echo json_encode(['erro' => false, 'enviados_agora' => 0, 'msg' => 'Fim da lista']);
        exit;
    }

    $enviados_count = 0;
    $url = rtrim($config['whatsapp_api_url'], '/') . '/message/sendText/' . $config['whatsapp_instancia'];

    // 4. Loop de Envio (Via cURL)
    foreach ($eleitores as $eleitor) {
        
        // Limpa número (deixa apenas dígitos)
        $numero = preg_replace('/[^0-9]/', '', $eleitor['whatsapp']);
        // Adiciona 55 se não tiver
        if (strlen($numero) <= 11) $numero = "55" . $numero;

        // Personaliza mensagem
        $mensagem = str_replace('{nome}', explode(' ', $eleitor['nome'])[0], $campanha['conteudo']);

        // Payload da Evolution API
        $data = [
            "number" => $numero,
            "options" => [
                "delay" => 1200,
                "presence" => "composing",
                "linkPreview" => true
            ],
            "textMessage" => [
                "text" => $mensagem
            ]
        ];

        $headers = [
            'Content-Type: application/json',
            'apikey: ' . $config['whatsapp_api_token']
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Se sucesso (200 ou 201)
        if ($httpCode >= 200 && $httpCode < 300) {
            $enviados_count++;
        }
        
        // Delay aleatório entre envios para evitar ban (1 a 3 segundos)
        sleep(rand(1, 3)); 
    }

    $response['enviados_agora'] = $enviados_count;
    $response['msg'] = "Lote processado.";

} catch (Exception $e) {
    $response['erro'] = true;
    $response['msg'] = $e->getMessage();
}

echo json_encode($response);