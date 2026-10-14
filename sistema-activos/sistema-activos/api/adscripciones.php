<?php
/**
 * API: Gestión de adscripciones / sucursales / planteles
 * Acceso: solo superusuario
 *
 * Jerarquía: Adscripción → Área → Empleado → Activo
 *
 * Métodos: GET, POST, PUT, DELETE
 * Query: ?id=5  ?q=texto
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$q      = isset($_GET['q'])  ? trim((string)$_GET['q']) : null;
$user   = require_super_csrf();

try {
    $pdo = db();

    switch ($method) {
        // ============================================================
        // GET — Listar / buscar / obtener por ID
        // ============================================================
        case 'GET':
            if ($id) {
                $stmt = $pdo->prepare("SELECT * FROM adscripciones WHERE id = :id");
                $stmt->execute([':id' => $id]);
                $row = $stmt->fetch();
                if (!$row) {
                    json_response(['ok' => false, 'error' => 'Adscripción no encontrada'], 404);
                }

                // Conteo de áreas y empleados asociados
                $stmtCount = $pdo->prepare("
                    SELECT
                        (SELECT COUNT(*) FROM areas WHERE adscripcion_id = :id)::int AS areas_count,
                        (SELECT COUNT(*) FROM empleados e
                            JOIN areas a ON a.id = e.area_id
                            WHERE a.adscripcion_id = :id)::int AS empleados_count
                ");
                $stmtCount->execute([':id' => $id]);
                $counts = $stmtCount->fetch();
                $row['areas_count']     = (int)$counts['areas_count'];
                $row['empleados_count'] = (int)$counts['empleados_count'];

                json_response(['ok' => true, 'data' => $row]);
            }

            $sql = "
                SELECT a.*,
                       (SELECT COUNT(*) FROM areas ar WHERE ar.adscripcion_id = a.id)::int AS areas_count,
                       (SELECT COUNT(*) FROM empleados e
                            JOIN areas ar ON ar.id = e.area_id
                            WHERE ar.adscripcion_id = a.id)::int AS empleados_count
                FROM adscripciones a
            ";
            $params = [];
            if ($q) {
                $sql .= " WHERE LOWER(a.nombre) LIKE :q OR LOWER(a.clave) LIKE :q
                          OR LOWER(COALESCE(a.descripcion,'')) LIKE :q
                          OR LOWER(COALESCE(a.direccion,'')) LIKE :q";
                $params[':q'] = '%' . strtolower($q) . '%';
            }
            $sql .= " ORDER BY a.activo DESC, a.nombre ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            json_response(['ok' => true, 'data' => $stmt->fetchAll()]);
            break;

        // ============================================================
        // POST — Crear adscripción
        // ============================================================
        case 'POST': {
            $input      = json_input();
            $clave      = trim((string)($input['clave'] ?? ''));
            $nombre     = trim((string)($input['nombre'] ?? ''));
            $desc       = trim((string)($input['descripcion'] ?? ''));
            $direccion  = trim((string)($input['direccion'] ?? ''));
            $activo     = !empty($input['activo']);

            // Validaciones
            $err = validarAdscripcion($clave, $nombre);
            if ($err !== true) {
                json_response(['ok' => false, 'error' => $err], 400);
            }

            // Unicidad de clave
            $chk = $pdo->prepare("SELECT id FROM adscripciones WHERE LOWER(clave) = LOWER(:c)");
            $chk->execute([':c' => $clave]);
            if ($chk->fetch()) {
                json_response(['ok' => false, 'error' => 'Ya existe una adscripción con esa clave'], 409);
            }

            $stmt = $pdo->prepare("
                INSERT INTO adscripciones (clave, nombre, descripcion, direccion, activo)
                VALUES (:c, :n, :d, :dir, :a)
                RETURNING id
            ");
            $stmt->bindValue(':c',   strtolower($clave), PDO::PARAM_STR);
            $stmt->bindValue(':n',   $nombre, PDO::PARAM_STR);
            $stmt->bindValue(':d',   $desc === ''      ? null : $desc,      $desc === ''      ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':dir', $direccion === '' ? null : $direccion, $direccion === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':a',   $activo ? 't' : 'f', PDO::PARAM_STR);
            $stmt->execute();

            $newId = (int)$stmt->fetchColumn();
            logger("adscripcion creada: id={$newId} clave={$clave}");

            json_response([
                'ok'      => true,
                'id'      => $newId,
                'message' => 'Adscripción registrada correctamente',
            ], 201);
            break;
        }

        // ============================================================
        // PUT — Actualizar adscripción
        // ============================================================
        case 'PUT': {
            if (!$id) {
                json_response(['ok' => false, 'error' => 'ID requerido'], 400);
            }
            $input      = json_input();
            $nombre     = trim((string)($input['nombre'] ?? ''));
            $desc       = trim((string)($input['descripcion'] ?? ''));
            $direccion  = trim((string)($input['direccion'] ?? ''));
            $activo     = !empty($input['activo']);

            if ($nombre === '' || strlen($nombre) < 2) {
                json_response(['ok' => false, 'error' => 'El nombre es obligatorio'], 400);
            }
            if (strlen($nombre) > 150) {
                json_response(['ok' => false, 'error' => 'El nombre no puede exceder 150 caracteres'], 400);
            }
            if (strlen($direccion) > 255) {
                json_response(['ok' => false, 'error' => 'La dirección no puede exceder 255 caracteres'], 400);
            }

            // Verificar que exista
            $chk = $pdo->prepare("SELECT id FROM adscripciones WHERE id = :id");
            $chk->execute([':id' => $id]);
            if (!$chk->fetch()) {
                json_response(['ok' => false, 'error' => 'Adscripción no encontrada'], 404);
            }

            $stmt = $pdo->prepare("
                UPDATE adscripciones
                SET nombre = :n,
                    descripcion = :d,
                    direccion = :dir,
                    activo = :a
                WHERE id = :id
            ");
            $stmt->bindValue(':n',   $nombre, PDO::PARAM_STR);
            $stmt->bindValue(':d',   $desc === ''      ? null : $desc,      $desc === ''      ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':dir', $direccion === '' ? null : $direccion, $direccion === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':a',   $activo ? 't' : 'f', PDO::PARAM_STR);
            $stmt->bindValue(':id',  $id, PDO::PARAM_INT);
            $stmt->execute();

            logger("adscripcion actualizada: id={$id}");
            json_response(['ok' => true, 'message' => 'Adscripción actualizada correctamente']);
            break;
        }

        // ============================================================
        // DELETE — Eliminar adscripción
        // ============================================================
        case 'DELETE':
            if (!$id) {
                json_response(['ok' => false, 'error' => 'ID requerido'], 400);
            }

            // Verificar que exista
            $chk = $pdo->prepare("SELECT clave, nombre FROM adscripciones WHERE id = :id");
            $chk->execute([':id' => $id]);
            $adsc = $chk->fetch();
            if (!$adsc) {
                json_response(['ok' => false, 'error' => 'Adscripción no encontrada'], 404);
            }

            // Proteger la adscripción por defecto
            if ($adsc['clave'] === 'direccion-general') {
                json_response(['ok' => false, 'error' => "La adscripción 'Dirección General' no se puede eliminar."], 409);
            }

            // Verificar que no tenga áreas asignadas
            $stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM areas WHERE adscripcion_id = :id");
            $stmtCnt->execute([':id' => $id]);
            $cnt = (int)$stmtCnt->fetchColumn();
            if ($cnt > 0) {
                json_response([
                    'ok'    => false,
                    'error' => "No se puede eliminar: la adscripción tiene {$cnt} área(s) asignada(s). Reasígnalas primero o desactívala.",
                ], 409);
            }

            $stmt = $pdo->prepare("DELETE FROM adscripciones WHERE id = :id");
            $stmt->execute([':id' => $id]);
            logger("adscripcion eliminada: id={$id} clave={$adsc['clave']}");
            json_response(['ok' => true, 'message' => 'Adscripción eliminada']);
            break;

        default:
            json_response(['ok' => false, 'error' => 'Método no permitido'], 405);
    }
} catch (Throwable $e) {
    logger("adscripciones error: " . $e->getMessage(), 'ERROR');
    json_response(['ok' => false, 'error' => 'Error: ' . $e->getMessage()], 500);
}

/* ============================================================
 * FUNCIONES AUXILIARES
 * ============================================================ */

/**
 * Valida los campos obligatorios de una adscripción.
 *
 * @return true|string
 */
function validarAdscripcion(string $clave, string $nombre)
{
    if ($clave === '' || strlen($clave) < 2) {
        return 'La clave es obligatoria (mín. 2 caracteres)';
    }
    if (strlen($clave) > 30) {
        return 'La clave no puede exceder 30 caracteres';
    }
    if (!preg_match('/^[a-z0-9_-]+$/i', $clave)) {
        return 'La clave solo puede contener letras, números, guion y guion bajo';
    }
    if ($nombre === '' || strlen($nombre) < 2) {
        return 'El nombre es obligatorio (mín. 2 caracteres)';
    }
    if (strlen($nombre) > 150) {
        return 'El nombre no puede exceder 150 caracteres';
    }
    return true;
}