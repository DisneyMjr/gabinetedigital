<?php
require 'config.php';
require 'campanha_lib.php';

/* =========================================================
   FUNÇÕES AUXILIARES
========================================================= */

function campanha_submit_host($url) {
    $url = trim((string)$url);

    if ($url === '') {
        return '';
    }

    $host = parse_url($url, PHP_URL_HOST);

    if (!$host) {
        return '';
    }

    $host = strtolower(
        preg_replace('/:\d+$/', '', (string)$host)
    );

    return preg_replace('/^www\./i', '', $host);
}


function campanha_submit_cfg_host($dominio) {
    $dominio = trim(strtolower((string)$dominio));

    if ($dominio === '') {
        return '';
    }

    if (strpos($dominio, '://') === false) {
        $dominio = 'https://' . $dominio;
    }

    $host = parse_url($dominio, PHP_URL_HOST);

    if (!$host) {
        return '';
    }

    $host = strtolower(
        preg_replace('/:\d+$/', '', (string)$host)
    );

    return preg_replace('/^www\./i', '', $host);
}


/**
 * Detecta se o envio veio pelo JavaScript/fetch.
 *
 * O site_campanha.php deve enviar:
 * X-Requested-With: XMLHttpRequest
 */
function campanha_submit_ajax() {

    $requestedWith =
        strtolower(
            trim(
                (string)(
                    $_SERVER['HTTP_X_REQUESTED_WITH']
                    ?? ''
                )
            )
        );

    return $requestedWith === 'xmlhttprequest';
}


/**
 * Monta a URL para voltar à campanha.
 */
function campanha_submit_url_campanha(array $cfg) {

    $dominio =
        trim(
            (string)(
                $cfg['dominio_personalizado']
                ?? ''
            )
        );

    /*
     * Se houver domínio personalizado,
     * voltamos para ele.
     */
    if ($dominio !== '') {

        if (
            strpos($dominio, 'http://') !== 0 &&
            strpos($dominio, 'https://') !== 0
        ) {
            $dominio = 'https://' . $dominio;
        }

        return rtrim($dominio, '/');
    }

    /*
     * Caso contrário usa o slug no MGD.
     */
    $slug =
        trim(
            (string)(
                $cfg['slug']
                ?? ''
            )
        );

    return
        'https://meugabinetedigital.com.br/site_campanha?slug='
        . rawurlencode($slug);
}


/**
 * Retorno JSON para AJAX.
 */
function campanha_submit_json(
    $ok,
    $mensagem,
    $httpCode = 200
) {

    http_response_code((int)$httpCode);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode(
        [
            'ok' => (bool)$ok,
            'mensagem' => (string)$mensagem
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}


/**
 * Página visual de sucesso.
 */
function campanha_submit_obrigado(
    array $cfg,
    $titulo,
    $mensagem
) {

    header(
        'Content-Type: text/html; charset=utf-8'
    );

    $nome =
        (string)(
            $cfg['nome_candidato']
            ?? 'Campanha'
        );

    $numero =
        trim(
            (string)(
                $cfg['numero_eleitoral']
                ?? ''
            )
        );

    $logo =
        trim(
            (string)(
                $cfg['logo_campanha']
                ?? ''
            )
        );

    $corPrincipal =
        trim(
            (string)(
                $cfg['cor_primaria']
                ?? '#5B21B6'
            )
        );

    $corDestaque =
        trim(
            (string)(
                $cfg['cor_destaque']
                ?? '#F59E0B'
            )
        );

    /*
     * Proteção das cores.
     */
    if (
        !preg_match(
            '/^#[0-9a-fA-F]{6}$/',
            $corPrincipal
        )
    ) {
        $corPrincipal = '#5B21B6';
    }

    if (
        !preg_match(
            '/^#[0-9a-fA-F]{6}$/',
            $corDestaque
        )
    ) {
        $corDestaque = '#F59E0B';
    }

    $urlVoltar =
        campanha_submit_url_campanha($cfg);

    ?>
<!doctype html>
<html lang="pt-br">

<head>

<meta charset="utf-8">

<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>

<title>
    <?= campanha_h($titulo) ?>
</title>

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet"
>

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
>

<style>

:root {
    --principal:
        <?= campanha_h($corPrincipal) ?>;

    --destaque:
        <?= campanha_h($corDestaque) ?>;
}

* {
    box-sizing: border-box;
}

body {

    min-height: 100vh;

    margin: 0;

    display: flex;

    align-items: center;

    justify-content: center;

    padding: 24px;

    font-family:
        system-ui,
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;

    background:

        radial-gradient(
            circle at 80% 20%,
            rgba(255,255,255,.10),
            transparent 25%
        ),

        linear-gradient(
            135deg,
            var(--principal),
            #07131c
        );

}

.obrigado-card {

    width: 100%;

    max-width: 680px;

    background: #fff;

    border-radius: 32px;

    padding: 55px 45px;

    text-align: center;

    box-shadow:
        0 30px 80px
        rgba(0,0,0,.25);

    position: relative;

    overflow: hidden;

}

.obrigado-card::before {

    content: "";

    position: absolute;

    width: 220px;

    height: 220px;

    border-radius: 50%;

    background: var(--destaque);

    opacity: .08;

    right: -90px;

    top: -100px;

}

.logo {

    max-width: 210px;

    max-height: 85px;

    object-fit: contain;

    margin-bottom: 30px;

}

.check {

    width: 100px;

    height: 100px;

    border-radius: 50%;

    margin:
        0 auto
        30px;

    display: flex;

    align-items: center;

    justify-content: center;

    background:
        rgba(25,135,84,.12);

    color:
        #198754;

    font-size:
        46px;

}

h1 {

    font-weight: 900;

    letter-spacing:
        -1.5px;

    margin-bottom:
        15px;

}

.mensagem {

    font-size:
        1.15rem;

    color:
        #64748b;

    line-height:
        1.7;

    max-width:
        520px;

    margin:
        0 auto
        30px;

}

.candidato {

    font-weight:
        800;

    font-size:
        1.1rem;

    color:
        var(--principal);

    margin-bottom:
        30px;

}

.btn-voltar {

    border: 0;

    border-radius:
        999px;

    padding:
        16px 34px;

    font-weight:
        800;

    font-size:
        1.05rem;

    color:
        #fff;

    background:
        var(--principal);

    text-decoration:
        none;

    display:
        inline-flex;

    align-items:
        center;

    gap:
        10px;

    transition:
        .2s;

}

.btn-voltar:hover {

    color:
        #fff;

    transform:
        translateY(-2px);

    box-shadow:
        0 12px 25px
        rgba(0,0,0,.18);

}

.rodape {

    margin-top:
        35px;

    font-size:
        .82rem;

    color:
        #94a3b8;

}

@media(max-width:576px) {

    .obrigado-card {

        padding:
            42px 24px;

        border-radius:
            24px;

    }

    h1 {

        font-size:
            2rem;

    }

    .check {

        width:
            82px;

        height:
            82px;

        font-size:
            38px;

    }

}

</style>

</head>

<body>

<div class="obrigado-card">

    <?php if ($logo !== ''): ?>

        <img
            src="<?= campanha_h($logo) ?>"
            class="logo"
            alt="<?= campanha_h($nome) ?>"
        >

    <?php endif; ?>


    <div class="check">

        <i class="fas fa-check"></i>

    </div>


    <h1>
        <?= campanha_h($titulo) ?>
    </h1>


    <div class="mensagem">

        <?= campanha_h($mensagem) ?>

    </div>


    <div class="candidato">

        <?= campanha_h($nome) ?>

        <?php if ($numero !== ''): ?>

            • <?= campanha_h($numero) ?>

        <?php endif; ?>

    </div>


    <a
        href="<?= campanha_h($urlVoltar) ?>"
        class="btn-voltar"
    >

        <i class="fas fa-arrow-left"></i>

        Voltar para o site

    </a>


    <div class="rodape">

        Obrigado pela participação.

    </div>

</div>

</body>

</html>
<?php

    exit;
}


/* =========================================================
   CABEÇALHOS
========================================================= */

header('Vary: Origin');


/* =========================================================
   PROCESSAMENTO
========================================================= */

try {

    /* -----------------------------------------------------
       PREFLIGHT CORS
    ----------------------------------------------------- */

    if (
        $_SERVER['REQUEST_METHOD']
        === 'OPTIONS'
    ) {

        $origin =
            trim(
                (string)(
                    $_SERVER['HTTP_ORIGIN']
                    ?? ''
                )
            );

        if ($origin !== '') {

            header(
                'Access-Control-Allow-Origin: '
                . $origin
            );

            header(
                'Access-Control-Allow-Methods: POST, OPTIONS'
            );

            header(
                'Access-Control-Allow-Headers: Content-Type, X-Requested-With'
            );

            header(
                'Access-Control-Max-Age: 600'
            );

        }

        http_response_code(204);

        exit;
    }


    /* -----------------------------------------------------
       SOMENTE POST
    ----------------------------------------------------- */

    if (
        $_SERVER['REQUEST_METHOD']
        !== 'POST'
    ) {

        campanha_submit_json(
            false,
            'Método inválido.',
            405
        );
    }


    /* -----------------------------------------------------
       TENANT
    ----------------------------------------------------- */

    $tenant =
        (int)(
            $_POST['tenant_id']
            ?? 0
        );


    if ($tenant <= 0) {

        throw new Exception(
            'Campanha inválida.'
        );

    }


    /* -----------------------------------------------------
       CAMPANHA
    ----------------------------------------------------- */

    $cfg =
        campanha_config(
            $pdo,
            $tenant
        );


    if (
        !$cfg ||
        !(int)(
            $cfg['ativo']
            ?? 0
        )
    ) {

        throw new Exception(
            'Campanha indisponível.'
        );

    }


    /* -----------------------------------------------------
       CORS / DOMÍNIO
    ----------------------------------------------------- */

    $origin =
        trim(
            (string)(
                $_SERVER['HTTP_ORIGIN']
                ?? ''
            )
        );


    if ($origin !== '') {

        $originHost =
            campanha_submit_host(
                $origin
            );

        $customHost =
            campanha_submit_cfg_host(
                $cfg[
                    'dominio_personalizado'
                ] ?? ''
            );


        $mgdOk = (

            $originHost
            ===
            'meugabinetedigital.com.br'

            ||

            substr(
                $originHost,
                -strlen(
                    '.meugabinetedigital.com.br'
                )
            )
            ===
            '.meugabinetedigital.com.br'

        );


        $customOk = (

            $customHost !== ''

            &&

            $originHost
            ===
            $customHost

        );


        if (
            !$mgdOk &&
            !$customOk
        ) {

            throw new Exception(
                'Origem não autorizada para esta campanha.'
            );

        }


        header(
            'Access-Control-Allow-Origin: '
            . $origin
        );

        header(
            'Access-Control-Allow-Methods: POST, OPTIONS'
        );

        header(
            'Access-Control-Allow-Headers: Content-Type, X-Requested-With'
        );

    }


    /* -----------------------------------------------------
       REFERÊNCIA
    ----------------------------------------------------- */

    $ref =
        campanha_resolver_ref(
            $pdo,
            $tenant,
            $_POST['ref'] ?? ''
        );


    /* =====================================================
       SOLICITAÇÃO DE MATERIAL
    ===================================================== */

    if (
        ($_POST['tipo'] ?? 'lead')
        === 'material'
    ) {

        $nome =
            trim(
                $_POST['nome']
                ?? ''
            );


        if ($nome === '') {

            throw new Exception(
                'Informe seu nome.'
            );

        }


        $itens =
            $_POST['itens']
            ?? [];


        if (
            !is_array($itens)
        ) {

            $itens =
                [$itens];

        }


        $pdo
            ->prepare("
                INSERT INTO
                    campanha_solicitacoes_materiais
                (
                    tenant_id,
                    nome,
                    whatsapp,
                    cep,
                    endereco,
                    numero,
                    complemento,
                    bairro,
                    cidade,
                    uf,
                    itens,
                    observacoes,
                    ref_eleitor_id
                )
                VALUES
                (
                    ?,?,?,?,?,?,?,?,?,?,?,?,?
                )
            ")
            ->execute([
                $tenant,
                $nome,
                campanha_tel(
                    $_POST['whatsapp']
                    ?? ''
                ),
                trim(
                    $_POST['cep']
                    ?? ''
                ),
                trim(
                    $_POST['endereco']
                    ?? ''
                ),
                trim(
                    $_POST['numero']
                    ?? ''
                ),
                trim(
                    $_POST['complemento']
                    ?? ''
                ),
                trim(
                    $_POST['bairro']
                    ?? ''
                ),
                trim(
                    $_POST['cidade']
                    ?? ''
                ),
                strtoupper(
                    substr(
                        trim(
                            $_POST['uf']
                            ?? ''
                        ),
                        0,
                        2
                    )
                ),
                implode(
                    ', ',
                    $itens
                ),
                trim(
                    $_POST['observacoes']
                    ?? ''
                ),
                $ref
            ]);


        /*
         * AJAX
         */
        if (
            campanha_submit_ajax()
        ) {

            campanha_submit_json(
                true,
                'Solicitação enviada com sucesso.'
            );

        }


        /*
         * POST NORMAL
         */
        campanha_submit_obrigado(
            $cfg,
            'Solicitação enviada!',
            'Recebemos sua solicitação de material de campanha.'
        );

    }


    /* =====================================================
       LEAD / APOIADOR
    ===================================================== */

    $d = [

        'nome' =>
            trim(
                $_POST['nome']
                ?? ''
            ),

        'whatsapp' =>
            campanha_tel(
                $_POST['whatsapp']
                ?? ''
            ),

        'email' =>
            trim(
                $_POST['email']
                ?? ''
            ),

        'cidade' =>
            trim(
                $_POST['cidade']
                ?? ''
            ),

        'bairro' =>
            trim(
                $_POST['bairro']
                ?? ''
            ),

        'interesse' =>
            trim(
                $_POST['interesse']
                ?? 'Apoiador'
            )

    ];


    if (
        $d['nome'] === ''
    ) {

        throw new Exception(
            'Informe seu nome.'
        );

    }


    if (
        $d['email'] !== ''

        &&

        !filter_var(
            $d['email'],
            FILTER_VALIDATE_EMAIL
        )
    ) {

        throw new Exception(
            'Informe um e-mail válido.'
        );

    }


    /* -----------------------------------------------------
       TRANSAÇÃO
    ----------------------------------------------------- */

    $pdo->beginTransaction();


    $eleitor =
        campanha_integrar_eleitor(
            $pdo,
            $tenant,
            $d,
            $ref
        );


    $pdo
        ->prepare("
            INSERT INTO
                campanha_leads
            (
                tenant_id,
                nome,
                whatsapp,
                email,
                cidade,
                bairro,
                interesse,
                origem,
                ref_eleitor_id,
                eleitor_id
            )
            VALUES
            (
                ?,?,?,?,?,?,?,?,?,?
            )
        ")
        ->execute([
            $tenant,
            $d['nome'],
            $d['whatsapp'],
            $d['email'],
            $d['cidade'],
            $d['bairro'],
            $d['interesse'],
            'site_campanha',
            $ref,
            $eleitor
        ]);


    $pdo->commit();


    /* -----------------------------------------------------
       RESPOSTA AJAX
    ----------------------------------------------------- */

    if (
        campanha_submit_ajax()
    ) {

        campanha_submit_json(
            true,
            'Cadastro realizado. Obrigado por fazer parte!'
        );

    }


    /* -----------------------------------------------------
       PÁGINA DE OBRIGADO
    ----------------------------------------------------- */

    campanha_submit_obrigado(
        $cfg,
        'Cadastro realizado!',
        'Obrigado por fazer parte. Seus dados foram recebidos com sucesso.'
    );


} catch (Throwable $e) {

    if (
        isset($pdo) &&
        $pdo instanceof PDO &&
        $pdo->inTransaction()
    ) {

        $pdo->rollBack();

    }


    /*
     * Se foi AJAX, devolve JSON.
     */
    if (
        campanha_submit_ajax()
    ) {

        campanha_submit_json(
            false,
            $e->getMessage(),
            400
        );

    }


    /*
     * Fallback simples para erro.
     */
    http_response_code(400);

    header(
        'Content-Type: text/html; charset=utf-8'
    );

    ?>
<!doctype html>
<html lang="pt-br">

<head>

<meta charset="utf-8">

<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>

<title>Não foi possível enviar</title>

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet"
>

</head>

<body class="bg-light">

<div class="container py-5">

    <div
        class="card border-0 shadow-sm rounded-4 mx-auto"
        style="max-width:650px;"
    >

        <div class="card-body p-5 text-center">

            <div
                class="text-danger mb-3"
                style="font-size:60px;"
            >
                ⚠
            </div>

            <h1 class="h3 fw-bold">
                Não foi possível enviar
            </h1>

            <p class="text-muted mt-3">
                <?= campanha_h(
                    $e->getMessage()
                ) ?>
            </p>

            <button
                type="button"
                class="btn btn-dark rounded-pill px-4 mt-3"
                onclick="history.back()"
            >
                Voltar
            </button>

        </div>

    </div>

</div>

</body>

</html>
<?php

}