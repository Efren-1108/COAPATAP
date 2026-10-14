<?php
/**
 * API: Subida de imágenes (fotos de activos)
 * POST /api/upload.php (multipart/form-data, campo "imagen")
 *   → { ok, ruta: "http://.../uploads/xxx.jpg" }
 *
 * Cambios:
 *   - [MEJORA] Mapeo de UPLOAD_ERR_* a mensajes legibles.
 *   - [MEJORA] Devuelve ruta absoluta usando public_url().
 *   - [FIX]    finfo_close deprecation (PHP 8.5) condicional.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

require_auth_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Método no permitido'], 405);
}

if (empty($_FILES['imagen'])) {
    json_response(['ok' => false, 'error' => 'No se recibió archivo'], 400);
}

// ---- Mapeo de errores de subida ----
$uploadErrors = [
    UPLOAD_ERR_INI_SIZE   => 'El archivo excede el tamaño máximo permitido por el servidor.',
    UPLOAD_ERR_FORM_SIZE  => 'El archivo excede el tamaño máximo del formulario.',
    UPLOAD_ERR_PARTIAL    => 'El archivo se subió solo parcialmente. Intenta de nuevo.',
    UPLOAD_ERR_NO_FILE    => 'No se seleccionó ningún archivo.',
    UPLOAD_ERR_NO_TMP_DIR => 'Falta la carpeta temporal del servidor. Contacta al administrador.',
    UPLOAD_ERR_CANT_WRITE => 'No se pudo escribir el archivo en el disco. Contacta al administrador.',
    UPLOAD_ERR_EXTENSION  => 'Una extensión de PHP bloqueó la subida. Contacta al administrador.',
];

$errCode = $_FILES['imagen']['error'] ?? UPLOAD_ERR_NO_FILE;
if ($errCode !== UPLOAD_ERR_OK) {
    $msg = $uploadErrors[$errCode] ?? ('Error desconocido al subir archivo (código ' . $errCode . ')');
    json_response(['ok' => false, 'error' => $msg], 400);
}

$file = $_FILES['imagen'];

if ($file['size'] > MAX_UPLOAD_SIZE) {
    $maxMb = round(MAX_UPLOAD_SIZE / 1024 / 1024);
    json_response(['ok' => false, 'error' => "Archivo demasiado grande (máx. {$maxMb} MB)"], 400);
}

// ---- Validar MIME ----
$finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
$mime  = $finfo ? finfo_file($finfo, $file['tmp_name']) : ($file['type'] ?? '');
// finfo_close está deprecado en PHP 8.5; en versiones anteriores lo cerramos
if ($finfo && PHP_VERSION_ID < 80100) {
    finfo_close($finfo);
}

if (!in_array($mime, ALLOWED_MIME, true)) {
    json_response(['ok' => false, 'error' => 'Tipo de archivo no permitido: ' . $mime], 400);
}

// ---- Validar extensión ----
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ALLOWED_EXT, true)) {
    json_response(['ok' => false, 'error' => 'Extensión no permitida'], 400);
}

// ---- Asegurar carpeta ----
if (!is_dir(UPLOAD_DIR)) {
    if (!mkdir(UPLOAD_DIR, 0755, true)) {
        json_response(['ok' => false, 'error' => 'No se pudo crear la carpeta de uploads'], 500);
    }
}

// ---- Guardar archivo ----
$name = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$dest = UPLOAD_DIR . $name;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    json_response(['ok' => false, 'error' => 'No se pudo guardar el archivo'], 500);
}

@chmod($dest, 0644);

// ---- Devolver ruta absoluta ----
$rutaRelativa = 'uploads/' . $name;
$rutaAbsoluta = public_url() . '/' . $rutaRelativa;

logger("Imagen subida: {$name} por usuario_id=" . ($_SESSION['user_id'] ?? '?'));

json_response([
    'ok'      => true,
    'ruta'    => $rutaAbsoluta,
    'name'    => $name,
    'message' => 'Imagen subida correctamente',
]);