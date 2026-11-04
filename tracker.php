<?php
// tracker.php - VERSÃO ROBUSTA PARA HOSPEDAGEM COMPARTILHADA
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Garante que o PDO existe (caso o config não tenha sido chamado corretamente)
if (!isset($pdo)) { return; }

if (defined('TENANT_ID')) {
    try {
        $sessao_id = session_id();
        $ip = $_SERVER['REMOTE_ADDR'];
        $pagina = $_SERVER['REQUEST_URI'];
        $agente = $_SERVER['HTTP_USER_AGENT'] ?? 'Desconhecido';
        $dispositivo = (stripos($agente, 'mobile') !== false) ? 'Mobile' : 'Desktop';
        $agora = date('Y-m-d H:i:s');

        // 1. Verifica se essa sessão já existe HOJE
        $stmtCheck = $pdo->prepare("SELECT id FROM visitas WHERE sessao_id = ? AND tenant_id = ? AND DATE(data_acesso) = CURDATE()");
        $stmtCheck->execute([$sessao_id, TENANT_ID]);
        $visita = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if ($visita) {
            // JÁ EXISTE: Só atualiza que ele ainda está online
            $pdo->prepare("UPDATE visitas SET ultimo_ping = ?, pagina_atual = ? WHERE id = ?")
                ->execute([$agora, $pagina, $visita['id']]);
        } else {
            // NOVA VISITA: Tenta descobrir cidade
            $cidade = 'Desconhecido';
            $estado = '--';
            
            // Ignora localhost
            if ($ip != '127.0.0.1' && $ip != '::1') {
                // Tenta via cURL (Mais seguro para servidores bloqueados)
                $ch = curl_init("http://ip-api.com/json/{$ip}?fields=status,city,region");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 2); // Não trava o site se a API demorar
                $json = curl_exec($ch);
                curl_close($ch);
                
                $geo = json_decode($json, true);
                if ($geo && isset($geo['status']) && $geo['status'] == 'success') {
                    $cidade = $geo['city'];
                    $estado = $geo['region'];
                }
            }

            $stmtInsert = $pdo->prepare("INSERT INTO visitas (tenant_id, ip, cidade, estado, dispositivo, pagina_atual, sessao_id, ultimo_ping, data_acesso) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtInsert->execute([TENANT_ID, $ip, $cidade, $estado, $dispositivo, $pagina, $sessao_id, $agora, $agora]);
        }

    } catch (Exception $e) {
        // Erro silencioso para não quebrar o site do usuário
    }
}
?>