<?php
function start_secure_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            ini_set('session.cookie_secure', '1');
        }
        session_start();
    }
}

function auth_required(): void {
    start_secure_session();
    if (empty($_SESSION['user_id']) || empty($_SESSION['tenant_id'])) {
        header('Location: login.php');
        exit;
    }
}

function require_admin_role(): void {
    auth_required();
    $nivel = $_SESSION['nivel_acesso'] ?? $_SESSION['user_perfil'] ?? 'operador';
    if ($nivel !== 'admin') {
        http_response_code(403);
        exit('Acesso não autorizado.');
    }
}

function csrf_token(): string {
    start_secure_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function verify_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    start_secure_session();
    $sent = $_POST['csrf_token'] ?? '';
    $stored = $_SESSION['csrf_token'] ?? '';
    if (!$stored || !$sent || !hash_equals($stored, $sent)) {
        http_response_code(419);
        exit('Sessão expirada. Atualize a página e tente novamente.');
    }
}

function audit_log(PDO $pdo, int $tenantId, ?int $userId, string $acao, ?string $tabela = null, ?int $registroId = null): void {
    try {
        $stmt = $pdo->prepare("INSERT INTO audit_logs (tenant_id, user_id, acao, tabela, registro_id, ip, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $tenantId,
            $userId,
            $acao,
            $tabela,
            $registroId,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
        ]);
    } catch (Throwable $e) {
        // O log não deve interromper o atendimento se a tabela ainda não tiver sido migrada.
    }
}
