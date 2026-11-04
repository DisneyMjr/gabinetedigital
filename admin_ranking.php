<?php
// Busca o total de indicações agrupado por liderança
$sql_ranking = "SELECT l.id, l.nome, COUNT(e.id) as total_indicados
                FROM eleitores l
                LEFT JOIN eleitores e ON e.responsavel_id = l.id
                WHERE l.tenant_id = ? AND l.is_lider = 1
                GROUP BY l.id
                ORDER BY total_indicados DESC";

$stmt = $pdo->prepare($sql_ranking);
$stmt->execute([TENANT_ID]);
$ranking = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($ranking as $posicao => $lider):
    $total = (int)$lider['total_indicados'];
    
    // Regras da Gamificação
    if ($total >= 1000) {
        $medalha = 'Diamante 💎'; $cor_badge = 'bg-info text-dark';
    } elseif ($total >= 500) {
        $medalha = 'Platina 💿'; $cor_badge = 'bg-dark text-white';
    } elseif ($total >= 200) {
        $medalha = 'Ouro 🥇'; $cor_badge = 'bg-warning text-dark';
    } elseif ($total >= 100) {
        $medalha = 'Prata 🥈'; $cor_badge = 'bg-secondary text-white';
    } elseif ($total >= 50) {
        $medalha = 'Bronze 🥉'; $cor_badge = 'bg-orange text-white'; // Você pode criar uma classe css customizada pra cor bronze
    } else {
        $medalha = 'Iniciante 🎯'; $cor_badge = 'bg-light text-dark border';
    }
?>
    <tr>
        <td>#<?= $posicao + 1 ?></td>
        <td class="fw-bold"><?= htmlspecialchars($lider['nome']) ?></td>
        <td><?= $total ?> eleitores</td>
        <td><span class="badge <?= $cor_badge ?>"><?= $medalha ?></span></td>
    </tr>
<?php endforeach; ?>