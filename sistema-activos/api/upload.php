<?php
/**
 * API: Subida de imágenes (fotos de activos)
 * POST /api/upload.php (multipart/form-data, campo "imagen")
 *   → { ok, ruta: "uploads/xxx.jpg" }
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

// Exigir sesión activa para subir imágenes.
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Método no permitido'], 405);
}

if (empty($_FILES['imagen']) || $_FILES['imagen']['error'] !== UPLOAD_ERR_OK) {
    $err = $_FILES['imagen']['error'] ?? 'No se recibió archivo';
    json_response(['ok' => false, 'error' => 'Error al subir archivo (código ' . $err . ')'], 400);
}

$file = $_FILES['imagen'];

if ($file['size'] > MAX_UPLOAD_SIZE) {
    json_response(['ok' => false, 'error' => 'Archivo demasiado grande (máx. 5 MB)'], 400);
}

$finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
$mime  = $finfo ? finfo_file($finfo, $file['tmp_name']) : $file['type'];
if ($finfo) finfo_close($finfo);

if (!in_array($mime, ALLOWED_MIME, true)) {
    json_response(['ok' => false, 'error' => 'Tipo de archivo no permitido: ' . $mime], 400);
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ALLOWED_EXT, true)) {
    json_response(['ok' => false, 'error' => 'Extensión no permitida'], 400);
}

if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

$name = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$dest = UPLOAD_DIR . $name;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    json_response(['ok' => false, 'error' => 'No se pudo guardar el archivo'], 500);
}

@chmod($dest, 0644);

logger("Imagen subida: {$name}");
json_response(['ok' => true, 'ruta' => UPLOAD_URL . $name, 'message' => 'Imagen subida correctamente']);
