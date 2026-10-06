<?php
/* ============================================================
   migrate_thumbs.php — Migración de miniaturas (una sola vez)
   Arrabbiata · Armá tu pizza

   Qué hace:
   - Recorre data/pizzas/*.json.
   - Si la pizza tiene la miniatura vieja embebida en base64 dentro del JSON,
     la extrae y la guarda como archivo en data/thumbs/{id}.webp
       * Si el hosting tiene GD con WebP -> la convierte a WebP 320px (liviana).
       * Si no, guarda los bytes originales tal cual (png/jpg) — igual sale del JSON.
   - Borra el campo "thumb" pesado del JSON para que list-pizzas vuele.
   - Es idempotente: se puede correr las veces que haga falta. Si ya existe el
     archivo de miniatura, solo se asegura de limpiar el JSON.

   Cómo correrlo:
   - Por consola (recomendado):   php migrate_thumbs.php
   - Por navegador (admin):        migrate_thumbs.php?go=1
                                   (requiere estar logueado como admin)
   ============================================================ */
declare(strict_types=1);
@set_time_limit(0);

$IS_CLI = (PHP_SAPI === 'cli');

/* ---- misma config que api.php ---- */
$CFG = [
  'pizzas_dir' => __DIR__ . '/data/pizzas',
];
$cfgFile = __DIR__ . '/config.php';
if (is_file($cfgFile)) { $u = include $cfgFile; if (is_array($u)) $CFG = array_merge($CFG, $u); }
if (empty($CFG['thumbs_dir'])) $CFG['thumbs_dir'] = dirname((string)$CFG['pizzas_dir']) . '/thumbs';

$dir  = (string)$CFG['pizzas_dir'];
$tdir = (string)$CFG['thumbs_dir'];

/* ---- salida en texto plano ---- */
if (!$IS_CLI) header('Content-Type: text/plain; charset=utf-8');
function say(string $s): void { echo $s . "\n"; }

/* ---- guardas de seguridad para el modo navegador ---- */
if (!$IS_CLI) {
  @session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
  @session_start();
  $isAdmin = !empty($_SESSION['admin_id']);
  if (!$isAdmin) {
    http_response_code(401);
    say('Necesitás iniciar sesión como admin para correr la migración por el navegador.');
    say('O corré por consola:  php migrate_thumbs.php');
    exit;
  }
  if (empty($_GET['go'])) {
    say('Esto va a: convertir las miniaturas viejas a archivos WebP en data/thumbs/');
    say('y limpiar el campo "thumb" pesado de cada JSON.');
    say('');
    say('Para ejecutar de verdad, volvé a entrar con:  migrate_thumbs.php?go=1');
    exit;
  }
}

/* ---- ¿tenemos GD con WebP para convertir? ---- */
$HAS_WEBP = function_exists('imagewebp') && function_exists('imagecreatefromstring');

say('== Migración de miniaturas ==');
say('Pizzas:  ' . $dir);
say('Thumbs:  ' . $tdir);
say('WebP en el servidor (GD): ' . ($HAS_WEBP ? 'sí (convierte a WebP 320px)' : 'no (guarda el original tal cual)'));
say('');

if (!is_dir($dir)) { say('ERROR: no existe la carpeta de pizzas.'); exit; }
if (!is_dir($tdir) && !@mkdir($tdir, 0775, true)) { say('ERROR: no pude crear data/thumbs (¿permisos?).'); exit; }

/* ---- escritura atómica (igual que api.php) ---- */
function m_atomic_write(string $path, string $contents): bool {
  $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
  if (@file_put_contents($tmp, $contents, LOCK_EX) === false) { @unlink($tmp); return false; }
  if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
  return true;
}

/* ---- convierte bytes de imagen a WebP 320px; '' si no puede ---- */
function to_webp_320(string $bin): string {
  $im = @imagecreatefromstring($bin);
  if ($im === false) return '';
  $w = imagesx($im); $h = imagesy($im);
  $dst = imagecreatetruecolor(320, 320);
  imagealphablending($dst, false);
  imagesavealpha($dst, true);
  imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
  imagecopyresampled($dst, $im, 0, 0, 0, 0, 320, 320, $w, $h);
  ob_start();
  $ok = imagewebp($dst, null, 82);
  $out = (string)ob_get_clean();
  imagedestroy($im); imagedestroy($dst);
  return ($ok && $out !== '') ? $out : '';
}

/* ---- extensión a partir de un data-uri ---- */
function m_ext(string $mime): string {
  $mime = strtolower($mime);
  if ($mime === 'jpg' || $mime === 'jpeg') return 'jpg';
  if ($mime === 'png') return 'png';
  return 'webp';
}
function m_find_thumb(string $tdir, string $id): string {
  foreach (['webp','png','jpg','jpeg'] as $e) { $p = $tdir.'/'.$id.'.'.$e; if (is_file($p)) return $p; }
  return '';
}

$converted = 0;   // se creó el archivo de miniatura ahora
$hadFile   = 0;   // ya existía el archivo (solo se limpió el JSON)
$stripped  = 0;   // se limpió el campo thumb del JSON
$noThumb   = 0;   // la pizza no tenía miniatura
$errors    = 0;
$bytesSaved = 0;  // cuánto se achicaron los JSON

foreach ((array)glob($dir . '/*.json') as $f) {
  if (basename($f) === 'index.json') continue;
  $before = (int)@filesize($f);
  $j = json_decode((string)@file_get_contents($f), true);
  if (!is_array($j)) { $errors++; say('  ! JSON ilegible: ' . basename($f)); continue; }
  $id = (string)($j['id'] ?? pathinfo($f, PATHINFO_FILENAME));
  if ($id === '') { $errors++; continue; }

  $existing = m_find_thumb($tdir, $id);
  $thumb = (string)($j['thumb'] ?? '');
  $hasEmbedded = (bool)preg_match('~^data:image/(png|jpe?g|webp);base64,(.+)$~s', $thumb, $mm);

  // 1) crear el archivo de miniatura si todavía no existe
  if ($existing === '') {
    if ($hasEmbedded) {
      $bin = base64_decode((string)preg_replace('/\s+/', '', $mm[2]), true);
      if ($bin === false || $bin === '') { $errors++; say('  ! base64 inválido: ' . $id); }
      else {
        $webp = $HAS_WEBP ? to_webp_320($bin) : '';
        if ($webp !== '') {
          if (m_atomic_write($tdir.'/'.$id.'.webp', $webp)) { $converted++; }
          else { $errors++; say('  ! no pude escribir webp: ' . $id); }
        } else {
          // sin GD/WebP: guardamos el original tal cual (igual sale del JSON)
          $ext = m_ext($mm[1]);
          if (m_atomic_write($tdir.'/'.$id.'.'.$ext, $bin)) { $converted++; }
          else { $errors++; say('  ! no pude escribir imagen: ' . $id); }
        }
      }
    } else {
      $noThumb++;
    }
  } else {
    $hadFile++;
  }

  // 2) limpiar el campo pesado del JSON (solo si ya hay archivo de miniatura)
  $nowFile = m_find_thumb($tdir, $id);
  if ($nowFile !== '' && array_key_exists('thumb', $j)) {
    unset($j['thumb']);
    $enc = json_encode($j, JSON_UNESCAPED_UNICODE);
    if ($enc !== false && m_atomic_write($f, $enc)) {
      $stripped++;
      $bytesSaved += max(0, $before - strlen($enc));
    } else {
      $errors++; say('  ! no pude reescribir JSON: ' . $id);
    }
  }
}

say('');
say('== Resultado ==');
say('Miniaturas creadas ahora:      ' . $converted);
say('Ya tenían archivo (sin tocar): ' . $hadFile);
say('JSON limpiados (campo thumb):  ' . $stripped);
say('Pizzas sin miniatura:          ' . $noThumb);
say('Errores:                       ' . $errors);
say('Espacio liberado en los JSON:  ' . round($bytesSaved / 1024 / 1024, 2) . ' MB');
say('');
say('Listo. La galería ya sirve las miniaturas por api.php?action=thumb con cache.');
