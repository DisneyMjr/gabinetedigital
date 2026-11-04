<?php
// migrar_base.php
require 'config.php';

echo "<h1>🔄 Migração de Base de Dados</h1>";

try {
    // 1. Busca todos os usuários da tabela ANTIGA (admins)
    // que AINDA NÃO ESTÃO na tabela NOVA (usuarios)
    $sql = "SELECT * FROM admins 
            WHERE email NOT IN (SELECT email FROM usuarios)";
    
    $stmt = $pdo->query($sql);
    $usuarios_antigos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($usuarios_antigos) == 0) {
        echo "<div style='background:#d4edda; padding:15px; border-radius:5px;'>
                <h3>✅ Tudo atualizado!</h3>
                <p>Todos os usuários da tabela antiga já estão na nova.</p>
              </div>";
    } else {
        echo "<p>Encontrados <b>" . count($usuarios_antigos) . "</b> usuários para migrar.</p>";
        echo "<ul>";

        $count = 0;
        foreach ($usuarios_antigos as $user) {
            // Define o nível (se era admin do sistema antigo, viram 'admin' no novo)
            // Se você quiser que sejam 'equipe', mude abaixo.
            $nivel = 'admin'; 

            // Prepara a inserção na tabela NOVA
            $insert = $pdo->prepare("INSERT INTO usuarios (tenant_id, nome, email, senha, nivel, permissoes, ativo, data_criacao) 
                                     VALUES (?, ?, ?, ?, ?, ?, 1, NOW())");
            
            // Mantém a senha antiga (o login.php vai converter ela pra hash no primeiro acesso)
            $insert->execute([
                $user['tenant_id'],
                $user['nome'],
                $user['email'],
                $user['senha'], // Copia a senha exatamente como está (seja hash ou texto)
                $nivel,
                $user['permissoes'] ?? '[]'
            ]);

            echo "<li>Migrado: <b>{$user['nome']}</b> ({$user['email']})</li>";
            $count++;
        }
        echo "</ul>";
        echo "<div style='background:#cce5ff; padding:15px; border-radius:5px;'>
                <h3>🚀 Sucesso! $count usuários migrados.</h3>
                <p>Agora o Guilherme (e outros) conseguem logar.</p>
                <a href='login.php'>Ir para Login</a>
              </div>";
    }

} catch (PDOException $e) {
    echo "<div style='background:#f8d7da; padding:15px; color:red;'>
            <h3>❌ Erro Fatal</h3>
            <p>" . $e->getMessage() . "</p>
          </div>";
}
?>