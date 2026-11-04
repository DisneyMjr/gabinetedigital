<?php
// apoiar.php
// Formulário público para cadastro de apoiadores via link da liderança

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require 'db.php';
require_once __DIR__ . '/mgd_duplicidades.php';

$hash_lider = $_GET['lider'] ?? '';

if (empty($hash_lider)) {
    die("
        <div style='font-family:sans-serif;text-align:center;padding:50px;'>
            <h2>Acesso Inválido</h2>
            <p>Link de indicação quebrado ou incompleto.</p>
        </div>
    ");
}

/*
|--------------------------------------------------------------------------
| LOCALIZA A LIDERANÇA
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        nome,
        tenant_id
    FROM eleitores
    WHERE MD5(id) = ?
      AND is_lider = 1
    LIMIT 1
");

$stmt->execute([$hash_lider]);

$lideranca = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$lideranca) {
    die("
        <div style='font-family:sans-serif;text-align:center;padding:50px;'>
            <h2>Link Expirado</h2>
            <p>A liderança responsável por este link não foi encontrada no sistema.</p>
        </div>
    ");
}

$tenant_id_correto = (int)$lideranca['tenant_id'];
$responsavel_id    = (int)$lideranca['id'];


/*
|--------------------------------------------------------------------------
| CONFIGURAÇÕES DO TENANT
|--------------------------------------------------------------------------
*/

$stmt_seo = $pdo->prepare("
    SELECT scripts_head
    FROM configuracoes
    WHERE tenant_id = ?
    LIMIT 1
");

$stmt_seo->execute([$tenant_id_correto]);

$site_config = $stmt_seo->fetch(PDO::FETCH_ASSOC);

$sucesso = false;
$erro = '';

/*
|--------------------------------------------------------------------------
| PROCESSA CADASTRO
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['acao'] ?? '') === 'apoiar'
) {

    $nome = trim($_POST['nome'] ?? '');

    $whatsapp = preg_replace(
        '/[^0-9]/',
        '',
        $_POST['whatsapp'] ?? ''
    );

    $bairro = trim($_POST['bairro'] ?? '');
    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')), 'UTF-8');
    $sexo = trim((string)($_POST['sexo'] ?? 'Não Informado'));
    $zona = trim((string)($_POST['zona'] ?? ''));
    $secao = trim((string)($_POST['secao'] ?? ''));
    $cep = preg_replace('/\D+/', '', (string)($_POST['cep'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $erro = 'Informe um e-mail válido.';
    if (!in_array($sexo, ['Não Informado','Masculino','Feminino','Outro'], true)) $erro = 'Selecione um gênero válido.';
    if ($zona !== '' && (!ctype_digit($zona) || strlen($zona) > 4)) $erro = 'Zona eleitoral inválida.';
    if ($secao !== '' && (!ctype_digit($secao) || strlen($secao) > 4)) $erro = 'Seção eleitoral inválida.';
    if ($cep !== '' && strlen($cep) !== 8) $erro = 'CEP deve ter 8 dígitos.';

    $nascimentoDigitado = trim(
        $_POST['nascimento'] ?? ''
    );

    $nascimento = null;

    /*
    |--------------------------------------------------------------------------
    | CONVERTE DD/MM/AAAA PARA AAAA-MM-DD
    |--------------------------------------------------------------------------
    */

    if ($nascimentoDigitado !== '') {

        $data = DateTime::createFromFormat(
            'd/m/Y',
            $nascimentoDigitado
        );

        $errosData = DateTime::getLastErrors();

        $dataValida =
            $data !== false &&
            (
                $errosData === false ||
                (
                    $errosData['warning_count'] === 0 &&
                    $errosData['error_count'] === 0
                )
            );

        if ($dataValida) {

            $nascimento = $data->format('Y-m-d');

            /*
             * Impede data futura
             */
            if ($data > new DateTime()) {
                $erro = 'Informe uma data de nascimento válida.';
            }

        } else {
            $erro = 'Informe a data de nascimento no formato DD/MM/AAAA.';
        }
    } else {
        $erro = 'Informe sua data de nascimento.';
    }


    /*
    |--------------------------------------------------------------------------
    | VALIDA CAMPOS
    |--------------------------------------------------------------------------
    */

    if ($nome === '') {
        $erro = 'Informe seu nome completo.';
    }

    if (strlen($whatsapp) < 10) {
        $erro = 'Informe um WhatsApp válido.';
    }

    if ($bairro === '') {
        $erro = 'Informe seu bairro ou localidade.';
    }


    /*
    |--------------------------------------------------------------------------
    | SALVA
    |--------------------------------------------------------------------------
    */

    if ($erro === '') {

        $sql = "
            INSERT INTO eleitores (
                tenant_id,
                nome,
                whatsapp,
                bairro,
                nascimento,
                email,
                sexo,
                zona,
                secao,
                cep,
                responsavel_id,
                status_voto,
                tags,
                observacoes
            )
            VALUES (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                2,
                'Indicação via Link',
                ?
            )
        ";

        try {
            mgd_lock($pdo, $tenant_id_correto);
            $jaExiste = mgd_localizar_duplicado($pdo,$tenant_id_correto,$whatsapp,$email);
            if ($jaExiste) {
                mgd_registrar_bloqueio($pdo,$tenant_id_correto,$jaExiste,$responsavel_id,'link');
                $erro='Este contato já está cadastrado. Não é possível registrar uma segunda indicação.';
            } else {
            $obs =
                "Cadastrado via link exclusivo da liderança: " .
                $lideranca['nome'];

            $pdo->prepare($sql)->execute([
                $tenant_id_correto,
                $nome,
                $whatsapp,
                $bairro,
                $nascimento,
                $email,
                $sexo,
                $zona,
                $secao,
                $cep,
                $responsavel_id,
                $obs
            ]);

            $sucesso = true;
            }
        } catch (PDOException $e) {

            /*
             * Se for cadastro duplicado, mantém tela de sucesso.
             * Outros erros são registrados.
             */

            if ((string)$e->getCode() === '23000') {

                $erro = 'Este contato já está cadastrado. Não é possível registrar uma segunda indicação.';

            } else {

                error_log(
                    'Erro apoiar.php: ' .
                    $e->getMessage()
                );

                $erro =
                    'Não foi possível concluir o cadastro. Tente novamente.';
            }
        } finally { mgd_unlock($pdo, $tenant_id_correto); }
    }
}

?>
<!DOCTYPE html>
<html lang="pt-br">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Apoie nossa causa!</title>

    <?= $site_config['scripts_head'] ?? '' ?>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"
    >

    <style>

        body {
            background: #f4f6f9;
            font-family: 'Segoe UI', sans-serif;
        }

        .hero-banner {
            background:
                linear-gradient(
                    135deg,
                    #1e3c72 0%,
                    #2a5298 100%
                );

            color: white;

            padding:
                40px
                20px
                60px;

            border-bottom-left-radius: 30px;
            border-bottom-right-radius: 30px;

            text-align: center;
        }

        .card-form {
            margin-top: -40px;

            border-radius: 20px;

            box-shadow:
                0
                10px
                30px
                rgba(0,0,0,0.1);

            border: none;
        }

        .form-control {
            min-height: 48px;
            border-radius: 10px;
        }

        .form-control:focus {
            box-shadow:
                0
                0
                0
                .2rem
                rgba(13,110,253,.12);
        }

        .campo-data {
            font-size: 17px;
            letter-spacing: .5px;
        }

    </style>

</head>

<body>


<?php if ($sucesso): ?>

    <div class="container mt-5 text-center">

        <div
            class="card card-form p-5 mx-auto shadow"
            style="max-width:500px;margin-top:50px;"
        >

            <i
                class="fas fa-check-circle text-success fa-5x mb-3"
            ></i>

            <h2 class="fw-bold text-success mb-3">
                Cadastro Confirmado!
            </h2>

            <p class="text-muted fs-5 mb-4">

                Seu cadastro foi recebido com sucesso por

                <strong>
                    <?= htmlspecialchars(
                        $lideranca['nome'],
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </strong>.

            </p>

        </div>

    </div>


<?php else: ?>


    <div class="hero-banner">

        <h2 class="fw-bold mb-2">
            Junte-se ao nosso grupo!
        </h2>

        <p
            class="lead opacity-75"
            style="
                font-size:1rem;
                max-width:600px;
                margin:0 auto;
            "
        >

            Você foi convidado(a) por

            <strong>
                <?= htmlspecialchars(
                    $lideranca['nome'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </strong>.

            Preencha os dados abaixo para fazer parte da nossa rede de apoio.

        </p>

    </div>


    <div class="container pb-5">

        <div class="row justify-content-center">

            <div class="col-md-5">

                <div class="card card-form p-4">

                    <h5 class="fw-bold text-center mb-4">

                        <i
                            class="fas fa-user-plus text-primary me-2"
                        ></i>

                        Cadastro de Apoio

                    </h5>


                    <?php if ($erro !== ''): ?>

                        <div
                            class="alert alert-danger"
                            role="alert"
                        >

                            <i
                                class="fas fa-circle-exclamation me-2"
                            ></i>

                            <?= htmlspecialchars(
                                $erro,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>

                        </div>

                    <?php endif; ?>


                    <form
                        method="POST"
                        id="formApoio"
                        autocomplete="on"
                    >

                        <input
                            type="hidden"
                            name="acao"
                            value="apoiar"
                        >


                        <!-- NOME -->

                        <div class="mb-3">

                            <label
                                class="fw-bold small text-muted"
                            >
                                Seu Nome Completo *
                            </label>

                            <input
                                type="text"
                                name="nome"
                                class="form-control bg-light"
                                value="<?= htmlspecialchars(
                                    $_POST['nome'] ?? '',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                autocomplete="name"
                                required
                            >

                        </div>


                        <!-- WHATSAPP -->

                        <div class="mb-3">

                            <label
                                class="fw-bold small text-muted"
                            >
                                Seu WhatsApp *
                            </label>

                            <input
                                type="tel"
                                name="whatsapp"
                                id="whatsapp"
                                class="form-control bg-light"
                                placeholder="(00) 00000-0000"
                                value="<?= htmlspecialchars(
                                    $_POST['whatsapp'] ?? '',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                inputmode="numeric"
                                autocomplete="tel"
                                maxlength="15"
                                required
                            >

                        </div>


                        <!-- DATA DE NASCIMENTO -->

                        <div class="mb-3">

                            <label
                                class="fw-bold small text-muted"
                            >
                                Data de Nascimento *
                            </label>

                            <input
                                type="text"
                                name="nascimento"
                                id="nascimento"
                                class="form-control bg-light campo-data"
                                placeholder="DD/MM/AAAA"
                                value="<?= htmlspecialchars(
                                    $_POST['nascimento'] ?? '',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                inputmode="numeric"
                                autocomplete="bday"
                                maxlength="10"
                                required
                            >

                            <div class="form-text">
                                Exemplo: 11/05/2006
                            </div>

                        </div>


                        <!-- BAIRRO -->

                        <div class="mb-4">

                            <label
                                class="fw-bold small text-muted"
                            >
                                Bairro / Localidade *
                            </label>

                            <input
                                type="text"
                                name="bairro"
                                class="form-control bg-light"
                                value="<?= htmlspecialchars(
                                    $_POST['bairro'] ?? '',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                autocomplete="address-level3"
                                required
                            >

                        </div>


                        <!-- CAMPOS COMPLEMENTARES -->
                        <div class="row g-3 mb-4">
                            <div class="col-12"><h6 class="fw-bold text-primary mb-0">Dados complementares (opcionais)</h6></div>
                            <div class="col-12"><label class="form-label fw-bold small">E-mail</label><input type="email" name="email" class="form-control bg-light" maxlength="190" autocomplete="email" value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></div>
                            <div class="col-12"><label class="form-label fw-bold small">Gênero</label><select name="sexo" class="form-select bg-light">
                                <?php foreach (['Não Informado','Masculino','Feminino','Outro'] as $op): ?>
                                <option value="<?= htmlspecialchars($op, ENT_QUOTES, 'UTF-8') ?>" <?= (($_POST['sexo'] ?? 'Não Informado') === $op) ? 'selected' : '' ?>><?= htmlspecialchars($op, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select></div>
                            <div class="col-6"><label class="form-label fw-bold small">Zona Eleitoral</label><input type="number" min="1" max="9999" name="zona" class="form-control bg-light" value="<?= htmlspecialchars($_POST['zona'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></div>
                            <div class="col-6"><label class="form-label fw-bold small">Seção Eleitoral</label><input type="number" min="1" max="9999" name="secao" class="form-control bg-light" value="<?= htmlspecialchars($_POST['secao'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></div>
                            <div class="col-12"><label class="form-label fw-bold small">CEP</label><input type="text" name="cep" id="cep" inputmode="numeric" maxlength="9" placeholder="00000-000" class="form-control bg-light" value="<?= htmlspecialchars($_POST['cep'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></div>
                        </div>

                        <button
                            type="submit"
                            class="
                                btn
                                btn-primary
                                w-100
                                py-3
                                fw-bold
                                rounded-pill
                                fs-5
                                shadow
                            "
                        >

                            CONFIRMAR APOIO

                        </button>

                    </form>

                </div>


                <div
                    class="text-center mt-3 text-muted small"
                >

                    <i
                        class="fas fa-shield-alt me-1"
                    ></i>

                    Seus dados estão seguros.

                </div>

            </div>

        </div>

    </div>


<?php endif; ?>


<script>

/*
|--------------------------------------------------------------------------
| MÁSCARA WHATSAPP
|--------------------------------------------------------------------------
*/

const whatsapp = document.getElementById('whatsapp');

if (whatsapp) {

    whatsapp.addEventListener('input', function () {

        let valor = this.value.replace(/\D/g, '');

        valor = valor.substring(0, 11);

        if (valor.length > 10) {

            valor = valor.replace(
                /^(\d{2})(\d{5})(\d{4})$/,
                '($1) $2-$3'
            );

        } else if (valor.length > 6) {

            valor = valor.replace(
                /^(\d{2})(\d{4})(\d{0,4})$/,
                '($1) $2-$3'
            );

        } else if (valor.length > 2) {

            valor = valor.replace(
                /^(\d{2})(\d+)/,
                '($1) $2'
            );

        } else if (valor.length > 0) {

            valor = valor.replace(
                /^(\d*)/,
                '($1'
            );
        }

        this.value = valor;
    });
}


/*
|--------------------------------------------------------------------------
| MÁSCARA DATA DD/MM/AAAA
|--------------------------------------------------------------------------
*/

const nascimento = document.getElementById('nascimento');

if (nascimento) {

    nascimento.addEventListener('input', function () {

        let valor = this.value.replace(/\D/g, '');

        valor = valor.substring(0, 8);

        if (valor.length >= 5) {

            valor =
                valor.substring(0, 2) +
                '/' +
                valor.substring(2, 4) +
                '/' +
                valor.substring(4, 8);

        } else if (valor.length >= 3) {

            valor =
                valor.substring(0, 2) +
                '/' +
                valor.substring(2, 4);
        }

        this.value = valor;
    });


    /*
    |--------------------------------------------------------------------------
    | VALIDAÇÃO ANTES DE ENVIAR
    |--------------------------------------------------------------------------
    */

    document
        .getElementById('formApoio')
        .addEventListener('submit', function (event) {

            const valor = nascimento.value;

            const regex =
                /^(\d{2})\/(\d{2})\/(\d{4})$/;

            const resultado =
                valor.match(regex);

            if (!resultado) {

                event.preventDefault();

                alert(
                    'Informe a data de nascimento no formato DD/MM/AAAA.'
                );

                nascimento.focus();

                return;
            }


            const dia = parseInt(
                resultado[1],
                10
            );

            const mes = parseInt(
                resultado[2],
                10
            );

            const ano = parseInt(
                resultado[3],
                10
            );


            const data = new Date(
                ano,
                mes - 1,
                dia
            );


            const dataValida =
                data.getFullYear() === ano &&
                data.getMonth() === mes - 1 &&
                data.getDate() === dia;


            if (!dataValida) {

                event.preventDefault();

                alert(
                    'Informe uma data de nascimento válida.'
                );

                nascimento.focus();

                return;
            }


            const hoje = new Date();

            if (data > hoje) {

                event.preventDefault();

                alert(
                    'A data de nascimento não pode ser futura.'
                );

                nascimento.focus();
            }

        });
}

</script>

</body>
</html>