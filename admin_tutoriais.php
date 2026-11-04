<?php
require 'config.php';
require 'protecao.php';

if (!defined('TENANT_ID')) die("Erro de acesso.");
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Manual do Sistema - Gabinete Digital</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f4f7f6; font-family: 'Inter', sans-serif; color: #334155; }
        .tutorial-header { background: white; border-bottom: 1px solid #e2e8f0; padding: 40px 0; margin-bottom: 30px; }
        .card-tutorial { border: none; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); margin-bottom: 15px; overflow: hidden; transition: 0.3s; }
        .card-tutorial:hover { box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
        
        .accordion-button { font-weight: 700; color: #1e293b; padding: 20px; font-size: 1.1rem; }
        .accordion-button:not(.collapsed) { background-color: #f1f5f9; color: #0f172a; box-shadow: inset 0 -1px 0 rgba(0,0,0,.125); }
        .accordion-button:focus { box-shadow: none; border-color: rgba(0,0,0,.125); }
        
        .accordion-body { background: #fff; padding: 30px; line-height: 1.7; color: #475569; }
        .accordion-body h6 { color: #0f172a; font-weight: 800; margin-top: 20px; margin-bottom: 10px; display: flex; align-items: center; }
        .accordion-body h6 i { margin-right: 10px; color: #6366f1; }
        
        .badge-module { font-size: 0.75rem; text-transform: uppercase; padding: 6px 12px; border-radius: 6px; margin-right: 15px; width: 100px; text-align: center; display: inline-block; font-weight: 800; letter-spacing: 0.5px; }
        
        .step-box { background: #f8fafc; border-left: 4px solid #6366f1; padding: 15px; margin-bottom: 15px; border-radius: 0 8px 8px 0; }
        .tip-box { background: #fffbeb; border: 1px solid #fcd34d; color: #92400e; padding: 15px; border-radius: 8px; margin-top: 15px; font-size: 0.9rem; }

        /* Estilo do Vídeo Responsivo */
        .video-container { position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; border-radius: 8px; background: #000; margin-bottom: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .video-container iframe { position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: none; }

        .btn-suporte { position: fixed; bottom: 30px; right: 30px; z-index: 9999; background-color: #25D366; color: white; border-radius: 50px; padding: 12px 25px; font-weight: bold; box-shadow: 0 4px 15px rgba(0,0,0,0.2); transition: transform 0.2s; text-decoration: none; display: flex; align-items: center; gap: 10px; }
        .btn-suporte:hover { transform: scale(1.05); color: white; background-color: #128C7E; }
    </style>
</head>
<body>

    <div class="tutorial-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h2 class="fw-bold text-dark mb-1"><i class="fas fa-book-reader me-2 text-primary"></i> Manual de Operações</h2>
                    <p class="text-muted mb-0">Guia passo-a-passo para extrair o máximo do seu Gabinete Digital.</p>
                </div>
                <div>
                    <a href="admin_painel.php" class="btn btn-outline-secondary rounded-pill px-4 fw-bold me-2">Voltar ao Painel</a>
                    <button onclick="window.print()" class="btn btn-primary rounded-pill px-4 fw-bold"><i class="fas fa-print me-2"></i> Imprimir Manual</button>
                </div>
            </div>
        </div>
    </div>

    <div class="container pb-5">
        <div class="accordion" id="manualGabinete">

            <div class="card card-tutorial">
                <div class="accordion-item border-0">
                    <h2 class="accordion-header">
                        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#sec-intro">
                            <span class="badge bg-dark badge-module">Início</span> 1. Primeiros Passos e Configuração
                        </button>
                    </h2>
                    <div id="sec-intro" class="accordion-collapse collapse show" data-bs-parent="#manualGabinete">
                        <div class="accordion-body">
                            <p>Bem-vindo ao seu sistema de gestão. Assista ao vídeo abaixo para aprender a configurar as cores, logos e o servidor de e-mail.</p>
                            
                            <div class="video-container">
                                <iframe src="https://www.youtube.com/embed/kmkroGbYORA" allowfullscreen></iframe>
                            </div>

                            <h6><i class="fas fa-cog"></i> Configurações Básicas</h6>
                            <div class="step-box">
                                1. Vá até o menu <strong>Configurações</strong>.<br>
                                2. Preencha os dados do mandato (Nome do Vereador/Deputado, Cidade, Estado).<br>
                                3. Configure o centro do <strong>Mapa</strong> (Latitude e Longitude da sua cidade) para que o georreferenciamento funcione corretamente.
                            </div>

                            <h6><i class="fas fa-users-cog"></i> Cadastrando sua Equipe</h6>
                            <div class="step-box">
                                1. Acesse o menu <strong>Equipe</strong>.<br>
                                2. Cadastre seus assessores. Eles receberão um login e senha.<br>
                                3. Defina o nível de acesso (Administrador ou Assessor).
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card card-tutorial">
                <div class="accordion-item border-0">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#sec-crm">
                            <span class="badge bg-primary badge-module">CRM</span> 2. Cadastro de Eleitores (A Base)
                        </button>
                    </h2>
                    <div id="sec-crm" class="accordion-collapse collapse" data-bs-parent="#manualGabinete">
                        <div class="accordion-body">
                            <p>O coração do sistema é a sua base de dados. Mantenha ela sempre atualizada.</p>

                            <h6><i class="fas fa-user-plus"></i> Como cadastrar um apoio</h6>
                            <div class="step-box">
                                1. Vá em <strong>Base Eleitoral</strong> e clique em "Novo Eleitor".<br>
                                2. Preencha o máximo de dados possíveis (WhatsApp e Bairro são essenciais).<br>
                                3. <strong>Muito Importante:</strong> Use o campo "Tags" para segmentar (Ex: Saúde, Professor, Futebol, Igreja). Isso ajudará a filtrar listas depois.
                            </div>

                            <h6><i class="fas fa-map-marker-alt"></i> Captura de GPS (Georreferenciamento)</h6>
                            <div class="step-box">
                                Quando estiver visitando um eleitor, use o botão <strong>"Capturar Localização Atual"</strong> no formulário de cadastro pelo celular. Isso salvará a posição exata para o Mapa de Calor.
                            </div>

                            <div class="tip-box">
                                <i class="fas fa-lightbulb me-2"></i> <strong>Dica:</strong> Cadastre a data de nascimento para que o sistema avise sobre os aniversariantes do dia no Painel Principal.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card card-tutorial">
                <div class="accordion-item border-0">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#sec-lider">
                            <span class="badge bg-warning text-dark badge-module">Estratégia</span> 3. Gestão de Lideranças e Metas
                        </button>
                    </h2>
                    <div id="sec-lider" class="accordion-collapse collapse" data-bs-parent="#manualGabinete">
                        <div class="accordion-body">
                            <p>Não faça campanha sozinho. Organize seus multiplicadores.</p>

                            <h6><i class="fas fa-user-tie"></i> Cadastrando um Multiplicador</h6>
                            <div class="step-box">
                                1. Acesse o menu <strong>Multiplicadores</strong>.<br>
                                2. Clique em "Nova Liderança".<br>
                                3. Defina uma <strong>Meta de Votos</strong> para este líder (Ex: Trazer 50 votos).
                            </div>

                            <h6><i class="fas fa-link"></i> Vinculando Eleitores ao Líder</h6>
                            <div class="step-box">
                                Ao cadastrar um eleitor comum em "Base Eleitoral", selecione o nome do líder no campo <strong>"Quem indicou?"</strong>.<br>
                                Automaticamente, o sistema atualizará a barra de progresso e o ranking daquele líder.
                            </div>

                            <h6><i class="fas fa-thermometer-half"></i> Termômetro do Voto</h6>
                            <p>Classifique cada eleitor para saber onde focar:</p>
                            <ul>
                                <li><span class="badge bg-warning text-dark">Indeciso (Frio)</span>: Precisa ser trabalhado/visitado.</li>
                                <li><span class="badge bg-info">Provável (Morno)</span>: Já demonstrou simpatia.</li>
                                <li><span class="badge bg-success">Voto Certo (Quente)</span>: Fidelizado.</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card card-tutorial">
                <div class="accordion-item border-0">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#sec-ouv">
                            <span class="badge bg-info text-dark badge-module">Ouvidoria</span> 4. Gestão de Solicitações (Kanban)
                        </button>
                    </h2>
                    <div id="sec-ouv" class="accordion-collapse collapse" data-bs-parent="#manualGabinete">
                        <div class="accordion-body">
                            <p>Não deixe demandas se perderem em papéis ou conversas de WhatsApp.</p>

                            <h6><i class="fas fa-columns"></i> O Fluxo de Trabalho</h6>
                            <div class="step-box">
                                O sistema usa um quadro estilo Kanban (Colunas):<br>
                                1. <strong>Pendente:</strong> Solicitação chegou (pelo site ou cadastrada manualmente).<br>
                                2. <strong>Em Análise:</strong> Sua equipe já viu e está providenciando (fazendo ofício, cobrando prefeitura).<br>
                                3. <strong>Concluído:</strong> O problema foi resolvido ou a resposta foi dada ao cidadão.
                            </div>

                            <div class="tip-box">
                                <i class="fas fa-check-circle me-2"></i> <strong>Importante:</strong> Sempre que mover um card para "Concluído", envie uma mensagem para o eleitor informando o sucesso. Isso gera fidelização!
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card card-tutorial">
                <div class="accordion-item border-0">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#sec-mapa">
                            <span class="badge bg-secondary badge-module">Dados</span> 5. Mapas e Inteligência
                        </button>
                    </h2>
                    <div id="sec-mapa" class="accordion-collapse collapse" data-bs-parent="#manualGabinete">
                        <div class="accordion-body">
                            <h6><i class="fas fa-map-marked-alt"></i> Mapa de Calor</h6>
                            <p>Acesse o menu <strong>Mapa de Votos</strong> para ver onde estão seus eleitores.</p>
                            <ul>
                                <li>Use os filtros para ver apenas "Votos Certos" (Verdes) ou "Indecisos" (Amarelos).</li>
                                <li>Isso ajuda a definir onde fazer a próxima caminhada ou reunião.</li>
                            </ul>

                            <h6><i class="fas fa-chart-pie"></i> Relatórios</h6>
                            <p>Em <strong>Relatórios</strong>, você consegue exportar listas para Excel ou PDF (Ex: "Lista de todos os aniversariantes de Março do Bairro Centro").</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card card-tutorial">
                <div class="accordion-item border-0">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#sec-site">
                            <span class="badge bg-danger badge-module">Mídia</span> 6. Site, Notícias e Agenda
                        </button>
                    </h2>
                    <div id="sec-site" class="accordion-collapse collapse" data-bs-parent="#manualGabinete">
                        <div class="accordion-body">
                            <h6><i class="fas fa-laptop"></i> Seu Site / App</h6>
                            <p>Tudo o que você cadastra no menu <strong>Notícias</strong> e <strong>Agenda</strong> aparece automaticamente no seu Site Público (se ativado no plano).</p>
                            
                            <h6><i class="fas fa-bullhorn"></i> Disparos (Marketing)</h6>
                            <p>Use o módulo de <strong>E-mail Marketing</strong> para enviar newsletters ou convites para sua base, filtrando por Tags ou Bairros.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card card-tutorial border-success bg-light mt-4">
                <div class="accordion-item border-0 bg-transparent">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed bg-transparent" type="button" data-bs-toggle="collapse" data-bs-target="#sec-suporte">
                            <span class="badge bg-success badge-module">Ajuda</span> Precisa de suporte técnico?
                        </button>
                    </h2>
                    <div id="sec-suporte" class="accordion-collapse collapse" data-bs-parent="#manualGabinete">
                        <div class="accordion-body text-center py-4">
                            <h5 class="fw-bold mb-3">Estamos aqui para ajudar!</h5>
                            <p class="mb-4">Se encontrou algum erro ou tem dúvida sobre como usar uma ferramenta, chame nossa equipe.</p>
                            <a href="https://wa.me/5537999129477?text=Olá,+preciso+de+ajuda+no+Gabinete+Digital." target="_blank" class="btn btn-success fw-bold px-5 rounded-pill shadow-sm">
                                <i class="fab fa-whatsapp fa-lg me-2"></i> Falar no WhatsApp
                            </a>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>