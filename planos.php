<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gabinete Digital - Crie o Seu</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
        
        /* Hero Section */
        .hero {
            background: linear-gradient(135deg, #0d6efd 0%, #0043a8 100%);
            color: white; padding: 80px 0 120px; text-align: center;
            border-radius: 0 0 50px 50px; margin-bottom: -80px;
        }
        
        /* Cards de Preço */
        .pricing-card {
            border: none; border-radius: 20px;
            background: white; transition: all 0.3s;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            position: relative; overflow: hidden;
            height: 100%; display: flex; flex-direction: column;
        }
        .pricing-card:hover { transform: translateY(-10px); box-shadow: 0 20px 40px rgba(0,0,0,0.15); }
        
        /* Destaque do Card */
        .popular-badge {
            background: #ffc107; color: #000; font-weight: bold;
            text-transform: uppercase; font-size: 0.8rem;
            padding: 5px 30px; position: absolute; top: 20px; right: -30px;
            transform: rotate(45deg); box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }

        .price-tag { color: #0d6efd; }
        .price-tag span { font-size: 3rem; font-weight: 800; }
        .price-tag small { font-size: 1rem; color: #6c757d; font-weight: normal; }

        .feature-list { list-style: none; padding: 0; margin: 20px 0; flex-grow: 1; }
        .feature-list li { padding: 10px 0; border-bottom: 1px solid #f0f0f0; color: #555; }
        .feature-list li i { color: #198754; margin-right: 10px; }

        .btn-cta {
            background: #0d6efd; color: white; border: none;
            padding: 15px; border-radius: 50px; font-weight: bold; width: 100%;
            text-transform: uppercase; letter-spacing: 1px; transition: 0.3s;
        }
        .btn-cta:hover { background: #0b5ed7; transform: scale(1.02); }
        .btn-outline-cta {
            border: 2px solid #0d6efd; color: #0d6efd; background: transparent;
            padding: 15px; border-radius: 50px; font-weight: bold; width: 100%;
        }
        .btn-outline-cta:hover { background: #0d6efd; color: white; }

        /* Ícones Flutuantes */
        .floating-icon {
            font-size: 3rem; color: rgba(255,255,255,0.2);
            position: absolute; animation: float 6s ease-in-out infinite;
        }
        @keyframes float { 0% { transform: translateY(0px); } 50% { transform: translateY(-20px); } 100% { transform: translateY(0px); } }

    </style>
</head>
<body>

    <div class="hero position-relative">
        <i class="fas fa-bullhorn floating-icon" style="top: 20px; left: 10%;"></i>
        <i class="fas fa-map-marked-alt floating-icon" style="top: 50px; right: 15%;"></i>
        
        <div class="container">
            <h1 class="fw-bold display-4 mb-3">Este Gabinete Digital ainda não existe...</h1>
            <p class="lead opacity-75 mb-4">Mas pode ser o <strong>SEU</strong>! Modernize seu mandato hoje mesmo.</p>
            <span class="badge bg-white text-primary px-4 py-2 rounded-pill fs-6 shadow-sm">
                <i class="fas fa-rocket me-2"></i> Tecnologia usada por vereadores inovadores
            </span>
        </div>
    </div>

    <div class="container mb-5">
        <div class="row justify-content-center g-4">
            
            <div class="col-md-5 col-lg-4">
                <div class="pricing-card p-4">
                    <div class="text-center mb-4">
                        <h5 class="text-uppercase text-muted fw-bold ls-1">Plano Anual</h5>
                        <div class="price-tag my-3">
                            <small>R$</small><span>350</span><small>/mês</small>
                        </div>
                        <p class="text-muted small">Faturado anualmente</p>
                    </div>
                    
                    <ul class="feature-list">
                        <li><i class="fas fa-check-circle"></i> Aplicativo PWA (Android/iOS)</li>
                        <li><i class="fas fa-check-circle"></i> Mapa de Calor de Votos</li>
                        <li><i class="fas fa-check-circle"></i> CRM de Eleitores</li>
                        <li><i class="fas fa-check-circle"></i> Suporte Prioritário</li>
                        <li><i class="fas fa-check-circle"></i> Renovação anual</li>
                    </ul>

                    <a href="https://wa.me/5537999129477?text=Ol%C3%A1%2C+tenho+interesse+no+*Plano+Anual*+do+Gabinete+Digital+por+R%24+350%2Fm%C3%AAs." class="btn btn-outline-cta mt-auto" target="_blank">
                        <i class="fab fa-whatsapp me-2"></i> Começar Agora
                    </a>
                </div>
            </div>

            <div class="col-md-5 col-lg-4">
                <div class="pricing-card p-4 border-primary border-2">
                    <div class="popular-badge">Recomendado</div>
                    <div class="text-center mb-4">
                        <h5 class="text-uppercase text-primary fw-bold ls-1">Plano Mandato</h5>
                        <div class="price-tag my-3">
                            <small>R$</small><span>200</span><small>/mês</small>
                        </div>
                        <p class="text-success fw-bold small">Economize R$ 7.200 no mandato!</p>
                    </div>
                    
                    <ul class="feature-list">
                        <li><i class="fas fa-check-circle"></i> <strong>Tudo do Plano Anual</strong></li>
                        <li><i class="fas fa-check-circle"></i> Contrato de 4 Anos</li>
                        <li><i class="fas fa-check-circle"></i> Setup e Configuração Grátis</li>
                        <li><i class="fas fa-check-circle"></i> Treinamento da Equipe</li>
                        <li><i class="fas fa-check-circle"></i> Consultoria de Disparo em Massa</li>
                    </ul>

                    <a href="https://wa.me/5537999129477?text=Ol%C3%A1%2C+quero+garantir+o+desconto+do+*Plano+Mandato*+(4+anos)+por+R%24+200%2Fm%C3%AAs." class="btn btn-cta mt-auto shadow" target="_blank">
                        <i class="fab fa-whatsapp me-2"></i> Garantir Desconto
                    </a>
                </div>
            </div>

        </div>
    </div>

    <div class="container py-5">
        <div class="row text-center g-4">
            <div class="col-md-4">
                <div class="p-3">
                    <i class="fas fa-mobile-alt fa-3x text-primary mb-3"></i>
                    <h5 class="fw-bold">App na Palma da Mão</h5>
                    <p class="text-muted small">Seu eleitor baixa o aplicativo direto do navegador, sem ocupar memória.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3">
                    <i class="fas fa-map-marker-alt fa-3x text-primary mb-3"></i>
                    <h5 class="fw-bold">Geolocalização (GPS)</h5>
                    <p class="text-muted small">Saiba exatamente onde estão os problemas da cidade e seus eleitores.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3">
                    <i class="fas fa-users fa-3x text-primary mb-3"></i>
                    <h5 class="fw-bold">Gestão de Base</h5>
                    <p class="text-muted small">Controle total de aniversariantes, lideranças e histórico de atendimento.</p>
                </div>
            </div>
        </div>
        
        <div class="text-center mt-5 text-muted small">
            &copy; <?= date('Y') ?> Gabinete Digital SaaS. Todos os direitos reservados.
        </div>
    </div>

</body>
</html>