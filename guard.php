<?php
/**
 * guard.php — Portón de sesión para el subdominio de pizzas (uso interno).
 *
 * Incluí esto al principio de cada página que quieras dejar detrás del login:
 *
 *     <?php require __DIR__ . '/guard.php'; ?>
 *
 * Usa las MISMAS credenciales que el resto del sitio: valida contra la sesión
 * de admin ($_SESSION['admin_id']) que setea login.php contra la tabla `admins`.
 * Si no hay sesión, manda al login y corta la ejecución.
 *
 * Los parámetros de la cookie son idénticos a los de login.php para que la
 * sesión se comparta entre login, editor, galería y api.php.
 */

@session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => true,   // solo HTTPS
    'httponly' => true,   // JS no puede leer la cookie
    'samesite' => 'Lax',
]);
@session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}
