PATCH ASSINATURA ESTÁVEL - MEU GABINETE DIGITAL

Arquivos incluídos:
- mp_helpers.php                         novo helper de logs, HTTPS, requests API e atualização de tenant
- mercadopago_recorrente.php             substitui criação da assinatura recorrente
- webhook_mp.php                         substitui webhook Mercado Pago com suporte a payment, subscription_preapproval e subscription_authorized_payment
- assinar.php                            ajustado para criar preapproval direto, não preapproval_plan solto
- sucesso_pagamento.php                  retorno seguro em HTTPS
- cron_sync_mp_assinaturas.php           rotina diária opcional para sincronizar assinaturas
- .htaccess                              proteção de .env, logs, backups e força HTTPS
- _logs/.htaccess                        bloqueia acesso público aos logs
- sql/00_sem_alteracao_obrigatoria.sql   observações de banco

COMO INSTALAR COM SEGURANÇA NO CPANEL

1) Faça backup completo antes:
   - Arquivos do public_html
   - Banco MySQL

2) Suba os arquivos do patch na raiz do sistema, por cima dos existentes.

3) Garanta que a pasta _logs exista e tenha permissão 755.

4) No Mercado Pago, configure o webhook apontando para:
   https://SEUDOMINIO.com.br/webhook_mp.php

5) Marque os eventos/tópicos:
   - Pagamentos / payment
   - subscription_preapproval
   - subscription_authorized_payment

6) Teste criando uma assinatura nova pelo assinar.php.

7) Verifique logs em:
   _logs/mercadopago_YYYY-MM-DD.log

8) Cron opcional no cPanel, 1 vez ao dia:
   /usr/local/bin/php -q /home/SEU_USUARIO/public_html/cron_sync_mp_assinaturas.php

ROLLBACK RÁPIDO

Se algo não funcionar, volte apenas estes arquivos do backup:
- assinar.php
- mercadopago_recorrente.php
- webhook_mp.php
- sucesso_pagamento.php
- .htaccess

IMPORTANTE

Este patch evita mexer no banco por padrão. Se o campo tenants.txid_pagamento for menor que 120 caracteres, rode o SQL opcional indicado dentro de sql/00_sem_alteracao_obrigatoria.sql.
