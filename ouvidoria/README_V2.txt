OUVIDORIA SaaS V2

INSTALAÇÃO
1. Envie os arquivos para o cPanel.
2. Copie config.local.example.php para config.local.php.
3. Preencha banco, usuário, senha e app_key.
4. Use uma app_key longa e aleatória. Não troque a app_key depois que já existirem relatos criptografados.
5. Acesse migracao_v2.php uma única vez.
6. Apague migracao_v2.php, setup_saas.php, diagnostico_final.php e webhook_simulado.php após a instalação.
7. Teste login, abertura de manifestação, acompanhamento e LAI.

SEGURANÇA
As credenciais que estavam gravadas na versão antiga foram removidas deste pacote.
Troque a senha do banco e qualquer chave de API que tenha sido usada na versão anterior.

MELHORIAS DA V2
- Sessão reforçada e regeneração de ID no login
- Perfis admin/operador corrigidos
- CSRF no painel principal
- Mais etapas de status
- Auditoria de alterações
- Estrutura para comentários internos
- Configuração externa ao código
- Cabeçalhos de segurança e proteção de arquivos sensíveis
- Dashboard com visão rápida dos atendimentos

============================================
MERCADO PAGO - ASSINATURA RECORRENTE (V3)
============================================
1. Execute migracao_v3_mercadopago.php uma única vez e depois apague o arquivo.
2. Entre no Painel SaaS > Configurações.
3. Informe:
   - URL pública do sistema, ex.: https://seudominio.com.br
   - Access Token de PRODUÇÃO do Mercado Pago
   - Chave secreta do Webhook
4. No painel do Mercado Pago, configure a URL de Webhook:
   https://seudominio.com.br/webhook_mp.php
5. Habilite notificações relacionadas a pagamentos e assinaturas/preapproval.
6. O cliente acessa admin_assinatura.php e clica em "Assinar com Mercado Pago".
7. A API cria uma assinatura mensal recorrente. O Mercado Pago retorna o checkout seguro.
8. O webhook sincroniza pagamentos e status da assinatura no banco.

ARQUIVOS PRINCIPAIS:
- mercadopago.php: cliente da API e funções auxiliares
- api_assinatura.php: cria a assinatura recorrente
- admin_assinatura.php: tela do cliente
- assinatura_acao.php: pausar, reativar e cancelar
- retorno_assinatura.php: retorno do checkout
- webhook_mp.php: sincronização automática
- migracao_v3_mercadopago.php: novas colunas/tabelas

OBSERVAÇÃO:
O antigo api_pagamento.php de Pix avulso foi mantido por compatibilidade, mas a assinatura principal agora usa /preapproval (recorrência mensal).
