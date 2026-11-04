<?php
/**
 * Leitura de notas/recibos usando OpenAI Responses API.
 *
 * Configure a chave no config.php:
 * define('OPENAI_API_KEY', 'sk-...');
 *
 * Opcional:
 * define('COMPRAS_IA_MODEL', 'gpt-5.6-luna');
 */
function compras_ia_api_key(): string {
    if (defined('OPENAI_API_KEY') && OPENAI_API_KEY) {
        return (string)OPENAI_API_KEY;
    }
    $env = getenv('OPENAI_API_KEY');
    return $env ? (string)$env : '';
}

function compras_ia_modelo(): string {
    return defined('COMPRAS_IA_MODEL') && COMPRAS_IA_MODEL
        ? (string)COMPRAS_IA_MODEL
        : 'gpt-5.6-luna';
}

function compras_ia_extrair_texto_resposta(array $resp): string {
    if (!empty($resp['output_text']) && is_string($resp['output_text'])) {
        return $resp['output_text'];
    }

    foreach (($resp['output'] ?? []) as $item) {
        if (($item['type'] ?? '') !== 'message') continue;
        foreach (($item['content'] ?? []) as $content) {
            if (($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
                return (string)$content['text'];
            }
        }
    }

    return '';
}

function compras_ia_ler_nota_binaria(string $bytes, string $mime): array {
    $apiKey = compras_ia_api_key();
    if ($apiKey === '') {
        throw new Exception('IA não configurada. Defina OPENAI_API_KEY no config.php.');
    }

    if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
        throw new Exception('Para leitura automática use uma foto JPG, PNG ou WEBP.');
    }

    $dataUrl = 'data:' . $mime . ';base64,' . base64_encode($bytes);

    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'fornecedor' => ['type' => 'string'],
            'documento' => ['type' => 'string'],
            'data' => ['type' => 'string', 'description' => 'Data em YYYY-MM-DD; vazio se ilegível'],
            'numero_nota' => ['type' => 'string'],
            'valor_total' => ['type' => 'number'],
            'descricao' => ['type' => 'string'],
            'itens' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'descricao' => ['type' => 'string'],
                        'quantidade' => ['type' => 'number'],
                        'valor_unitario' => ['type' => 'number'],
                        'valor_total' => ['type' => 'number'],
                    ],
                    'required' => ['descricao','quantidade','valor_unitario','valor_total']
                ]
            ],
            'confianca' => [
                'type' => 'string',
                'enum' => ['alta','media','baixa']
            ]
        ],
        'required' => ['fornecedor','documento','data','numero_nota','valor_total','descricao','itens','confianca']
    ];

    $payload = [
        'model' => compras_ia_modelo(),
        'input' => [[
            'role' => 'user',
            'content' => [
                [
                    'type' => 'input_text',
                    'text' => 'Leia esta nota fiscal, cupom ou recibo brasileiro. Extraia apenas o que estiver visível. Não invente dados. Documento deve ser CPF/CNPJ apenas se legível. Valor_total é o total efetivamente cobrado. Em descricao, faça um resumo curto dos itens/serviço. Se algum campo não estiver legível, use string vazia ou 0.'
                ],
                [
                    'type' => 'input_image',
                    'image_url' => $dataUrl,
                    'detail' => 'high'
                ]
            ]
        ]],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'nota_fiscal',
                'strict' => true,
                'schema' => $schema
            ]
        ]
    ];

    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception('Falha ao conectar à IA: ' . $err);
    }

    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $resp = json_decode($raw, true);
    if ($http < 200 || $http >= 300) {
        $msg = $resp['error']['message'] ?? ('HTTP ' . $http);
        throw new Exception('Erro da IA: ' . $msg);
    }

    $texto = compras_ia_extrair_texto_resposta($resp);
    if ($texto === '') {
        throw new Exception('A IA não retornou dados legíveis.');
    }

    $dados = json_decode($texto, true);
    if (!is_array($dados)) {
        throw new Exception('Resposta inválida da IA.');
    }

    return $dados;
}