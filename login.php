<?php
/**
 * login.php — para cumple.arrabbiata.com.ar
 *
 * Usa las MISMAS credenciales que el login principal: valida contra la
 * tabla `admins` y aplica rate limiting con `login_attempts`, igual que
 * el login de arrabbiata.com.ar. La única diferencia es que, al entrar,
 * redirige al panel de pizzas de este subdominio (no al editor de la carta).
 *
 * Requiere que db_connect.php exista en esta misma carpeta y apunte a la
 * base donde están las tablas `admins` y `login_attempts`.
 *
 *   CREATE TABLE login_attempts (
 *     ip VARCHAR(45) NOT NULL PRIMARY KEY,
 *     attempts INT NOT NULL DEFAULT 0,
 *     first_attempt INT NOT NULL
 *   );
 */

// Cookies de sesión seguras ANTES de session_start()
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => true,   // solo HTTPS
    'httponly' => true,   // JS no puede leer la cookie
    'samesite' => 'Lax',
]);
session_start();
@header('Content-Type: text/html; charset=utf-8');
require_once 'db_connect.php';

// Si ya hay sesión iniciada, ir directo al panel.
if (!empty($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

$MAX_ATTEMPTS = 10;
$WINDOW       = 600; // 10 minutos

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// --- Leer intentos desde la BD (sobrevive a que el atacante descarte cookies) ---
function getAttempts(PDO $pdo, string $ip, int $window): array {
    $st = $pdo->prepare("SELECT attempts, first_attempt FROM login_attempts WHERE ip = ?");
    $st->execute([$ip]);
    $row = $st->fetch();
    if (!$row || (time() - (int)$row['first_attempt']) > $window) {
        return ['attempts' => 0, 'first_attempt' => time()];
    }
    return ['attempts' => (int)$row['attempts'], 'first_attempt' => (int)$row['first_attempt']];
}

function recordFailure(PDO $pdo, string $ip, array $state): void {
    $st = $pdo->prepare("
        INSERT INTO login_attempts (ip, attempts, first_attempt)
        VALUES (?, 1, ?)
        ON DUPLICATE KEY UPDATE
            attempts = IF(? - first_attempt > 600, 1, attempts + 1),
            first_attempt = IF(VALUES(first_attempt) - first_attempt > 600, VALUES(first_attempt), first_attempt)
    ");
    $now = time();
    $st->execute([$ip, $now, $now]);
}

function clearAttempts(PDO $pdo, string $ip): void {
    $pdo->prepare("DELETE FROM login_attempts WHERE ip = ?")->execute([$ip]);
}

$state   = getAttempts($pdo, $ip, $WINDOW);
$blocked = $state['attempts'] >= $MAX_ATTEMPTS;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($blocked) {
        $error = "Demasiados intentos. Esperá unos minutos antes de volver a intentarlo.";
    } else {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ?");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password'])) {
            clearAttempts($pdo, $ip);
            session_regenerate_id(true);
            $_SESSION['admin_id'] = $admin['id'];
            header('Location: index.php');
            exit;
        } else {
            recordFailure($pdo, $ip, $state);
            $error = "Credenciales incorrectas.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Editor de pizzas · Arrabbiata</title>
    <link rel="icon" href="favicon.png">
    <style>
        body { font-family: Arial, sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; background: #F0E6D0; }
        .login-container { background: #fff; padding: 26px 24px; border-radius: 14px; box-shadow: 0 12px 30px rgba(43,26,10,.18); width: min(340px, 92vw); }
        h2 { font-family: Georgia, serif; color: #2b1a0a; margin: 0 0 4px; }
        .sub { color: #7a6a54; font-size: 13px; margin: 0 0 16px; }
        input { width: 100%; padding: 11px; margin: 7px 0; border: 1px solid #ddd; border-radius: 8px; box-sizing: border-box; font-size: 15px; }
        button { background: #CC1414; color: white; padding: 12px; border: none; border-radius: 8px; cursor: pointer; width: 100%; font-size: 15px; font-weight: 600; margin-top: 6px; }
        button:hover { background: #A50E0E; }
        .error { color: #CC1414; font-size: 14px; }
    </style>
</head>
<body>
    <div class="login-container">
        <h2>Editor de pizzas</h2>
        <p class="sub">Ingresá con tus credenciales de administrador.</p>
        <?php if (isset($error)) echo "<p class='error'>" . htmlspecialchars($error) . "</p>"; ?>
        <form method="POST">
            <input type="text" name="username" placeholder="Usuario" required autocomplete="username">
            <input type="password" name="password" placeholder="Contraseña" required autocomplete="current-password">
            <button type="submit">Entrar</button>
        </form>
    </div>
</body>
</html>
