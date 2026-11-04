<?php
// 1º Inicia a sessão
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require 'config.php';
require 'protecao.php';

// Segurança: Só master ou admin acessa essa ferramenta pesada
if (!defined('TENANT_ID') || !isset($_SESSION['nivel']) || !in_array($_SESSION['nivel'], ['admin', 'master'])) {
    die("Acesso Restrito.");
}

$mensagem = '';

// Processa o envio do arquivo CSV
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['arquivo_csv'])) {
    $arquivo = $_FILES['arquivo_csv']['tmp_name'];
    
    if (empty($arquivo)) {
        $mensagem = '<div class="alert alert-danger">Por favor, selecione um arquivo CSV.</div>';
    } else {
        // Abre o arquivo para leitura
        if (($handle = fopen($arquivo, "r")) !== FALSE) {
            
            // Pula a primeira linha (cabeçalho da planilha)
            fgetcsv($handle, 10000, ",");
            
            $inseridos = 0;
            
            // Prepara o SQL de inserção
            $stmt = $pdo->prepare("INSERT INTO tse_locais_votacao (uf, zona, secao, nome_local, endereco, bairro) VALUES (?, ?, ?, ?, ?, ?)");
            
            // Lê linha por linha do CSV
            while (($dados = fgetcsv($handle, 10000, ",")) !== FALSE) {
                // Mapeamento das colunas do seu CSV (ajuste conforme a planilha que você montar)
                // Exemplo esperado: UF [0], Zona [1], Seção [2], Local [3], Endereço [4], Bairro [5]
                $uf      = trim($dados[0]);
                $zona    = trim($dados[1]);
                $secao   = trim($dados[2]);
                $local   = trim($dados[3]);
                $end     = trim($dados[4]);
                $bairro  = trim($dados[5]);
                
                // Só insere se tiver zona e seção válidas
                if (!empty($zona) && !empty($secao)) {
                    $stmt->execute([$uf, $zona, $secao, $local, $end, $bairro]);
                    $inseridos++;
                }
            }
            fclose($handle);
            $mensagem = '<div class="alert alert-success fw-bold"><i class="fas fa-check-circle"></i> Sucesso! ' . $inseridos . ' seções importadas para o banco de dados.</div>';
        } else {
            $mensagem = '<div class="alert alert-danger">Erro ao ler o arquivo CSV.</div>';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Importar Zonas TSE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>body { background-color: #f3f4f6; font-family: 'Inter', sans-serif; }</style>
</head>
<body class="p-4">
    <div class="container" style="max-width: 600px; margin-top: 50px;">
        <div class="card border-0 shadow-sm rounded-4 p-4">
            <div class="text-center mb-4">
                <div class="bg-primary text-white d-inline-flex align-items-center justify-content-center rounded-circle mb-3" style="width: 70px; height: 70px;">
                    <i class="fas fa-file-csv fa-2x"></i>
                </div>
                <h4 class="fw-bold">Importador Oficial TSE</h4>
                <p class="text-muted small">Carregue a planilha com os locais de votação do DF.</p>
            </div>

            <?= $mensagem ?>

            <div class="alert alert-info small border-0 shadow-sm">
                <strong>Padrão exigido para o CSV (separado por vírgula):</strong><br>
                Coluna 1: UF (Ex: DF)<br>
                Coluna 2: Zona (Ex: 1)<br>
                Coluna 3: Seção (Ex: 45)<br>
                Coluna 4: Nome do Local (Ex: ESCOLA CLASSE 01)<br>
                Coluna 5: Endereço (Ex: SQS 106)<br>
                Coluna 6: Bairro (Ex: ASA SUL)
            </div>

            <form method="POST" enctype="multipart/form-data">
                <div class="mb-4">
                    <label class="form-label fw-bold">Arquivo CSV (.csv)</label>
                    <input type="file" name="arquivo_csv" class="form-control form-control-lg" accept=".csv" required>
                </div>
                <button type="submit" class="btn btn-primary w-100 py-3 fw-bold rounded-pill shadow-sm"><i class="fas fa-upload me-2"></i> INICIAR IMPORTAÇÃO</button>
            </form>
            <div class="text-center mt-3">
                <a href="admin_painel.php" class="text-secondary text-decoration-none small">Voltar ao Painel</a>
            </div>
        </div>
    </div>
</body>
</html>