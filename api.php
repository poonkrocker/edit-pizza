<?php
/* ============================================================
   api.php  —  Backend mínimo para Arrabbiata
   - Guarda ingredients.json (biblioteca) directo al servidor
   - Galería de pizzas armadas: guardar / listar / abrir / borrar
   Compatible con PHP 7.4+. Un solo archivo, sin dependencias.
   Configuración: copiá config.sample.php a config.php y editá el token.
   ============================================================ */
declare(strict_types=1);

/* ---- polyfill para PHP < 8.1 ---- */
if (!function_exists('array_is_list')) {
  function array_is_list(array $a): bool { $i = 0; foreach ($a as $k => $_) { if ($k !== $i++) return false; } return true; }
}

/* ---- config por defecto (sobreescribible por config.php) ---- */
$CFG = [
  'token'      => 'xJGsyljPdnMcy43EZyt3YEXVgiTzWP4QnpZAYERLcVQ',                              // token secreto; vacío = sin auth (no recomendado en público)
  'lib_file'   => __DIR__ . '/ingredients.json',   // dónde vive la biblioteca
  'pizzas_dir' => __DIR__ . '/data/pizzas',        // carpeta de pizzas guardadas
  'max_body'   => 8 * 1024 * 1024,                 // 8 MB
];
$cfgFile = __DIR__ . '/config.php';
if (is_file($cfgFile)) { $u = include $cfgFile; if (is_array($u)) $CFG = array_merge($CFG, $u); }

/* ---- cabeceras ---- */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

/* ---- sesión de admin (mismas credenciales que login.php) ----
   Se arranca antes de cualquier salida para poder autorizar por sesión.
   Mismos parámetros de cookie que login.php/guard.php para compartir la sesión. */
@session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
@session_start();

/* ---- helpers ---- */
function out($data, int $code = 200): void { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function fail(string $msg, int $code = 400): void { out(['ok' => false, 'error' => $msg], $code); }

function check_auth(array $CFG): void {
  // 1) Sesión de admin del subdominio: si entraste por login.php, ya estás autorizado.
  if (!empty($_SESSION['admin_id'])) return;
  // 2) Respaldo: token compartido (por si querés usar la API sin login).
  $t = (string)($CFG['token'] ?? '');
  if ($t === '') return;                            // auth desactivada
  $got = (string)($_SERVER['HTTP_X_AUTH_TOKEN'] ?? '');
  if (!hash_equals($t, $got)) fail('No autorizado', 401);
}

function read_body(array $CFG): array {
  $raw = file_get_contents('php://input');
  if ($raw === false || $raw === '') fail('Body vacío');
  if (strlen($raw) > (int)$CFG['max_body']) fail('Body demasiado grande', 413);
  $j = json_decode($raw, true);
  if (!is_array($j)) fail('JSON inválido');
  return $j;
}

function atomic_write(string $path, string $contents): bool {
  $dir = dirname($path);
  if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
  $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
  if (@file_put_contents($tmp, $contents, LOCK_EX) === false) return false;
  if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
  return true;
}

function safe_id(string $id): string { return (string)preg_replace('/[^a-zA-Z0-9_-]/', '', $id); }

/* ---- router ---- */
$action = (string)($_GET['action'] ?? '');
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

switch ($action) {

  case 'ping':
    out(['ok' => true, 'pong' => true, 'authRequired' => (($CFG['token'] ?? '') !== '')]);

  case 'save-lib': {
    if ($method !== 'POST') fail('Usá POST', 405);
    check_auth($CFG);
    $body = read_body($CFG);
    if (isset($body['ingredients']) && is_array($body['ingredients'])) { $lib = $body; }
    else if (array_is_list($body)) { $lib = ['version' => 1, 'ingredients' => $body]; }
    else { fail('Formato de biblioteca inválido'); }
    if (!isset($lib['ingredients']) || !is_array($lib['ingredients']) || count($lib['ingredients']) === 0) fail('La biblioteca está vacía; no se guarda por seguridad');
    if (!isset($lib['version'])) $lib['version'] = 1;
    $json = json_encode($lib, JSON_UNESCAPED_UNICODE);
    if ($json === false) fail('No pude serializar la biblioteca', 500);
    if (!atomic_write((string)$CFG['lib_file'], $json)) fail('No pude escribir ingredients.json (¿permisos de escritura?)', 500);
    out(['ok' => true, 'count' => count($lib['ingredients']), 'bytes' => strlen($json)]);
  }

  case 'list-pizzas': {
    $dir = (string)$CFG['pizzas_dir'];
    $items = [];
    if (is_dir($dir)) {
      foreach ((array)glob($dir . '/*.json') as $f) {
        if (basename($f) === 'index.json') continue;
        $j = json_decode((string)@file_get_contents($f), true);
        if (!is_array($j)) continue;
        $items[] = [
          'id'        => (string)($j['id'] ?? basename($f, '.json')),
          'name'      => (string)($j['name'] ?? '(sin nombre)'),
          'base'      => (string)($j['base'] ?? ''),
          'count'     => is_array($j['items'] ?? null) ? count($j['items']) : 0,
          'thumb'     => (string)($j['thumb'] ?? ''),
          'updatedAt' => (int)($j['updatedAt'] ?? @filemtime($f)),
        ];
      }
    }
    usort($items, function ($a, $b) { return $b['updatedAt'] <=> $a['updatedAt']; });
    out(['ok' => true, 'pizzas' => $items]);
  }

  case 'get-pizza': {
    $id = safe_id((string)($_GET['id'] ?? ''));
    if ($id === '') fail('Falta id');
    $f = (string)$CFG['pizzas_dir'] . '/' . $id . '.json';
    if (!is_file($f)) fail('No existe esa pizza', 404);
    $j = json_decode((string)@file_get_contents($f), true);
    if (!is_array($j)) fail('Pizza corrupta', 500);
    out(['ok' => true, 'pizza' => $j]);
  }

  case 'save-pizza': {
    if ($method !== 'POST') fail('Usá POST', 405);
    check_auth($CFG);
    $body = read_body($CFG);
    if (!isset($body['items']) || !is_array($body['items'])) fail('Faltan items de la pizza');
    $id = safe_id((string)($body['id'] ?? ''));
    if ($id === '') $id = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
    $now = time();
    $body['id'] = $id;
    if (!isset($body['createdAt'])) $body['createdAt'] = $now;
    $body['updatedAt'] = $now;
    $json = json_encode($body, JSON_UNESCAPED_UNICODE);
    if ($json === false) fail('No pude serializar la pizza', 500);
    $f = (string)$CFG['pizzas_dir'] . '/' . $id . '.json';
    if (!atomic_write($f, $json)) fail('No pude guardar la pizza (¿permisos?)', 500);
    out(['ok' => true, 'id' => $id, 'updatedAt' => $now]);
  }

  case 'delete-pizza': {
    if ($method !== 'POST') fail('Usá POST', 405);
    check_auth($CFG);
    $id = safe_id((string)($_GET['id'] ?? ''));
    if ($id === '') { $b = read_body($CFG); $id = safe_id((string)($b['id'] ?? '')); }
    if ($id === '') fail('Falta id');
    $f = (string)$CFG['pizzas_dir'] . '/' . $id . '.json';
    if (is_file($f)) @unlink($f);
    out(['ok' => true, 'id' => $id]);
  }

  default:
    fail('Acción desconocida: ' . $action, 404);
}