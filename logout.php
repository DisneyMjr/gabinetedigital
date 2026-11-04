<?php
ob_start(); // <--- O SEGREDO: Segura qualquer erro de espaço em branco
session_start();

// 1. Apaga todas as variáveis
$_SESSION = array();

// 2. Mata o Cookie da sessão no navegador
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Destrói a sessão no servidor
session_destroy();

// 4. Limpa o buffer
ob_end_clean();

// 5. REDIRECIONAMENTO DUPLO (PHP + JAVASCRIPT)
// Se o PHP falhar, o JavaScript assume.
if (!headers_sent()) {
    header("Location: login.php");
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="refresh" content="0;url=login.php">
    <script type="text/javascript">
        window.location.href = 'login.php';
    </script>
</head>
<body>
    <p>Saindo... <a href="login.php">Clique aqui se demorar</a>.</p>
</body>
</html>
