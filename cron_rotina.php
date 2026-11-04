<?php
// cron_rotina.php - O Robô da Cobrança (Versão Vencimento Dinâmico)
// Configure no cPanel para rodar 1x por dia (ex: 00:00)

require 'config.php';

// Aumenta o tempo limite para evitar timeout em bancos grandes
set_time_limit(300); 

echo "<h1>🤖 Iniciando Rotina de Verificação...</h1>";

try {
    // ---------------------------------------------------------
    // 1. ATUALIZAR STATUS DE FATURAS (Apenas Visual/Financeiro)
    // ---------------------------------------------------------
    // Isso serve para o seu painel financeiro mostrar "Vencido" em vermelho.
    // Não afeta o acesso do cliente, apenas a organização financeira.
    $sql_faturas = "UPDATE faturas 
                    SET status = 'vencido' 
                    WHERE status = 'pendente' AND data_vencimento < CURDATE()";
    
    $stmt = $pdo->query($sql_faturas);
    echo "<p>✅ Faturas marcadas como vencidas: <b>" . $stmt->rowCount() . "</b></p>";

    // ---------------------------------------------------------
    // 2. BLOQUEIO DE ACESSO (O Guardião)
    // ---------------------------------------------------------
    // A regra agora é única e absoluta: 
    // Se a data_vencimento no cadastro do cliente já passou = BLOQUEIO.
    // Isso vale para Demo, Mensal, Anual, etc.
    
    // Bloqueia apenas se a data de vencimento for MENOR que a data de ONTEM
    $sql_bloqueio = "UPDATE tenants 
                 SET status = 'bloqueado', ativo = 0 
                 WHERE data_vencimento < DATE_SUB(CURDATE(), INTERVAL 0 DAY)
                     AND status = 'ativo' 
                     AND slug != 'master'"; // Nunca bloqueia o Admin Master

    $stmt = $pdo->query($sql_bloqueio);
    $bloqueados = $stmt->rowCount();
    
    echo "<p>🔒 Clientes bloqueados por vencimento: <b>" . $bloqueados . "</b></p>";

    // ---------------------------------------------------------
    // 3. LOG DA OPERAÇÃO
    // ---------------------------------------------------------
    $msg_log = "[" . date('d/m/Y H:i') . "] Rotina executada. Faturas atualizadas e $bloqueados clientes bloqueados." . PHP_EOL;
    file_put_contents('log_cron.txt', $msg_log, FILE_APPEND);

    echo "<hr><h3>🏁 Rotina Finalizada com Sucesso!</h3>";

} catch (Exception $e) {
    echo "<h3 style='color:red'>Erro Crítico: " . $e->getMessage() . "</h3>";
    file_put_contents('log_cron.txt', "[" . date('d/m/Y H:i') . "] ERRO: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
}
?>