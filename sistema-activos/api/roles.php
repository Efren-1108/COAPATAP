<?php
/**
 * API: Gestión de roles
 * Acceso: solo superusuario
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$user   = require_super();

try {
    $pdo = db();

    switch ($method) {
        case 'GET':
            if ($id) {
                $stmt = $pdo->prepare("SELECT * FROM roles WHERE id = :id");
                $stmt->execute([':id' => $id]);
                $row = $stmt->fetch();
                if (!$row) json_response(['ok' => false, 'error' => 'Rol no encontrado'], 404);
                $row['usuarios_count'] = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol_id = {$row['id']}")->fetchColumn();
                json_response(['ok' => true, 'data' => $row]);
            }
            $rows = $pdo->query("
                SELECT r.*, COUNT(u.id)::int AS usuarios_count
                FROM roles r
                LEFT JOIN usuarios u ON u.rol_id = r.id
                GROUP BY r.id
                ORDER BY r.activo DESC, r.nombre ASC
            ")->fetchAll();
            json_response(['ok' => true, 'data' => $rows]);
            break;

        case 'POST': {
            $input   = json_input();
            $clave   = trim((string)($input['clave']   ?? ''));
            $nombre  = trim((string)($input['nombre']  ?? ''));
            $desc    = trim((string)($input['descripcion'] ?? ''));
            $activo  = !empty($input['activo']);

            if ($clave === '' || strlen($clave) < 2) {
                json_response(['ok' => false, 'error' => 'La clave es obligatoria (mín. 2 caracteres)'], 400);
            }
            if (!preg_match('/^[a-z0-9_-]+$/i', $clave)) {
                json_response(['ok' => false, 'error' => 'La clave solo puede contener letras, números, guion y guion bajo'], 400);
            }
            if ($nombre === '' || strlen($nombre) < 2) {
                json_response(['ok' => false, 'error' => 'El nombre es obligatorio'], 400);
            }

            $chk = $pdo->prepare("SELECT id FROM roles WHERE LOWER(clave) = LOWER(:c)");
            $chk->execute([':c' => $clave]);
            if ($chk->fetch()) {
                json_response(['ok' => false, 'error' => 'Ya existe un rol con esa clave'], 409);
            }

            $stmt = $pdo->prepare("INSERT INTO roles (clave, nombre, descripcion, activo) VALUES (:c, :n, :d, :a) RETURNING id");
            $stmt->execute([':c' => strtolower($clave), ':n' => $nombre, ':d' => $desc ?: null, ':a' => $activo]);
            $newId = (int)$stmt->fetchColumn();
            logger("rol creado: id={$newId} clave={$clave}");
            json_response(['ok' => true, 'id' => $newId, 'message' => 'Rol registrado correctamente'], 201);
            break;
        }

        case 'PUT': {
            if (!$id) json_response(['ok' => false, 'error' => 'ID requerido'], 400);
            $input   = json_input();
            $nombre  = trim((string)($input['nombre']  ?? ''));
            $desc    = trim((string)($input['descripcion'] ?? ''));
            $activo  = !empty($input['activo']);

            if ($nombre === '' || strlen($nombre) < 2) {
                json_response(['ok' => false, 'error' => 'El nombre es obligatorio'], 400);
            }

            $stmt = $pdo->prepare("UPDATE roles SET nombre=:n, descripcion=:d, activo=:a WHERE id=:id");
            $stmt->execute([':n' => $nombre, ':d' => $desc ?: null, ':a' => $activo, ':id' => $id]);
            logger("rol actualizado: id={$id}");
            json_response(['ok' => true, 'message' => 'Rol actualizado correctamente']);
            break;
        }

        case 'DELETE':
            if (!$id) json_response(['ok' => false, 'error' => 'ID requerido'], 400);

            // Proteger roles del sistema
            $clave = $pdo->query("SELECT clave FROM roles WHERE id = {$id}")->fetchColumn();
            if (in_array($clave, ['superusuario', 'usuario'], true)) {
                json_response(['ok' => false, 'error' => "El rol '{$clave}' es parte del sistema y no se puede eliminar."], 409);
            }
            $cnt = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol_id = {$id}")->fetchColumn();
            if ($cnt > 0) {
                json_response([
                    'ok'    => false,
                    'error' => "No se puede eliminar: hay {$cnt} usuario(s) con este rol.",
                ], 409);
            }

            $stmt = $pdo->prepare("DELETE FROM roles WHERE id = :id");
            $stmt->execute([':id' => $id]);
            logger("rol eliminado: id={$id}");
            json_response(['ok' => true, 'message' => 'Rol eliminado']);
            break;

        default:
            json_response(['ok' => false, 'error' => 'Método no permitido'], 405);
    }
} catch (Throwable $e) {
    logger("roles error: " . $e->getMessage(), 'ERROR');
    json_response(['ok' => false, 'error' => 'Error: ' . $e->getMessage()], 500);
}