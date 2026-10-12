<?php
/**
 * API: Gestión de áreas / departamentos
 * Acceso: solo superusuario
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$user   = require_super();     // ← bloquea todo lo que no sea superusuario

try {
    $pdo = db();

    switch ($method) {
        case 'GET':
            if ($id) {
                $stmt = $pdo->prepare("SELECT * FROM areas WHERE id = :id");
                $stmt->execute([':id' => $id]);
                $row = $stmt->fetch();
                if (!$row) json_response(['ok' => false, 'error' => 'Área no encontrada'], 404);
                $row['usuarios_count'] = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE area_id = {$row['id']}")->fetchColumn();
                json_response(['ok' => true, 'data' => $row]);
            }
            $rows = $pdo->query("
                SELECT a.*, COUNT(u.id)::int AS usuarios_count
                FROM areas a
                LEFT JOIN usuarios u ON u.area_id = a.id
                GROUP BY a.id
                ORDER BY a.activo DESC, a.nombre ASC
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

            $chk = $pdo->prepare("SELECT id FROM areas WHERE LOWER(clave) = LOWER(:c)");
            $chk->execute([':c' => $clave]);
            if ($chk->fetch()) {
                json_response(['ok' => false, 'error' => 'Ya existe un área con esa clave'], 409);
            }

            $stmt = $pdo->prepare("
    INSERT INTO areas (clave, nombre, descripcion, activo)
    VALUES (:c, :n, :d, :a)
    RETURNING id
");

$stmt->bindValue(':c', strtolower($clave), PDO::PARAM_STR);
$stmt->bindValue(':n', $nombre, PDO::PARAM_STR);

if ($desc === '') {
    $stmt->bindValue(':d', null, PDO::PARAM_NULL);
} else {
    $stmt->bindValue(':d', $desc, PDO::PARAM_STR);
}

$stmt->bindValue(':a', $activo, PDO::PARAM_BOOL);

$stmt->execute();
            $newId = (int)$stmt->fetchColumn();
            logger("area creada: id={$newId} clave={$clave}");
            json_response(['ok' => true, 'id' => $newId, 'message' => 'Área registrada correctamente'], 201);
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

            $stmt = $pdo->prepare("
    UPDATE areas
    SET nombre = :n,
        descripcion = :d,
        activo = :a
    WHERE id = :id
");

      $stmt->bindValue(':n', $nombre, PDO::PARAM_STR);

      if ($desc === '') {
      $stmt->bindValue(':d', null, PDO::PARAM_NULL);
      } else {
      $stmt->bindValue(':d', $desc, PDO::PARAM_STR);
}

      $stmt->bindValue(':a', $activo, PDO::PARAM_BOOL);
      $stmt->bindValue(':id', $id, PDO::PARAM_INT);

      $stmt->execute();
            logger("area actualizada: id={$id}");
            json_response(['ok' => true, 'message' => 'Área actualizada correctamente']);
            break;
        }

        case 'DELETE':
            if (!$id) json_response(['ok' => false, 'error' => 'ID requerido'], 400);

            // No permitir eliminar si tiene usuarios asignados
            $cnt = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE area_id = {$id}")->fetchColumn();
            if ($cnt > 0) {
                json_response([
                    'ok'    => false,
                    'error' => "No se puede eliminar: el área tiene {$cnt} usuario(s) asignado(s). Desactívala en su lugar.",
                ], 409);
            }
            // No permitir eliminar el área 'general' (es el respaldo del backfill)
            $general = $pdo->query("SELECT clave FROM areas WHERE id = {$id}")->fetchColumn();
            if ($general === 'general') {
                json_response(['ok' => false, 'error' => "El área 'General' no se puede eliminar."], 409);
            }

            $stmt = $pdo->prepare("DELETE FROM areas WHERE id = :id");
            $stmt->execute([':id' => $id]);
            logger("area eliminada: id={$id}");
            json_response(['ok' => true, 'message' => 'Área eliminada']);
            break;

        default:
            json_response(['ok' => false, 'error' => 'Método no permitido'], 405);
    }
} catch (Throwable $e) {
    logger("areas error: " . $e->getMessage(), 'ERROR');
    json_response(['ok' => false, 'error' => 'Error: ' . $e->getMessage()], 500);
}