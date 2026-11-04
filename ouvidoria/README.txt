# Projeto Ouvidoria SaaS (Empresa + Poder Público)

## Passo a passo
1) Suba os arquivos no servidor.
2) Crie o banco e tabelas do seu projeto original.
3) Rode **uma vez**: `migrar_publico.php` (e depois apague/renomeie).
4) Configure a variável de ambiente `OPENAI_API_KEY` (opcional). Se não configurar, o sistema funciona sem resumo IA.

## Módulos
- Ouvidoria (com denúncia anônima)
- LAI / e-SIC (prazo padrão 20 dias, com opção de prorrogação)

## Observações
- A criptografia agora usa IV aleatório por registro (coluna `iv` / `pedido_iv`).
