<?php
/**
 * API: Gestión de áreas / departamentos
 * Acceso: solo superusuario
 *
 * Jerarquía: Adscripción → Área → Empleado → Activo
 *
 * Cambios respecto a la versión anterior:
 *   - [MEJORA] Se incluye adscripcion_id en POST/PUT.
 *   - [MEJORA] GET devuelve adscripcion_nombre y adscripcion_clave.
 *   - [MEJORA] Validación de adscripcion_id.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$user   = require_super_csrf();

try {
    $pdo = db();

    switch ($method) {
        case 'GET':
            if ($id) {
                $stmt = $pdo->prepare("
                    SELECT a.*,
                           ad.nombre AS adscripcion_nombre,
                           ad.clave  AS adscripcion_clave
                    FROM areas a
                    LEFT JOIN adscripciones ad ON ad.id = a.adscripcion_id
                    WHERE a.id = :id
                ");
                $stmt->execute([':id' => $id]);
                $row = $stmt->fetch();
                if (!$row) json_response(['ok' => false, 'error' => 'Área no encontrada'], 404);

                $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE area_id = :id");
                $stmtCount->execute([':id' => $row['id']]);
                $row['usuarios_count'] = (int)$stmtCount->fetchColumn();

                $stmtEmp = $pdo->prepare("SELECT COUNT(*) FROM empleados WHERE area_id = :id");
                $stmtEmp->execute([':id' => $row['id']]);
                $row['empleados_count'] = (int)$stmtEmp->fetchColumn();

                json_response(['ok' => true, 'data' => $row]);
            }
            $rows = $pdo->query("
                SELECT a.*,
                       ad.nombre AS adscripcion_nombre,
                       ad.clave  AS adscripcion_clave,
                       COUNT(DISTINCT u.id)::int AS usuarios_count,
                       COUNT(DISTINCT e.id)::int AS empleados_count
                FROM areas a
                LEFT JOIN adscripciones ad ON ad.id = a.adscripcion_id
                LEFT JOIN usuarios u ON u.area_id = a.id
                LEFT JOIN empleados e ON e.area_id = a.id
                GROUP BY a.id, ad.nombre, ad.clave
                ORDER BY a.activo DESC, ad.nombre ASC NULLS LAST, a.nombre ASC
            ")->fetchAll();
            json_response(['ok' => true, 'data' => $rows]);
            break;

        case 'POST': {
            $input   = json_input();
            $clave   = trim((string)($input['clave']   ?? ''));
            $nombre  = trim((string)($input['nombre']  ?? ''));
            $desc    = trim((string)($input['descripcion'] ?? ''));
            $activo  = !empty($input['activo']);
            $adscripcionId = isset($input['adscripcion_id']) && $input['adscripcion_id'] !== ''
                ? (int)$input['adscripcion_id']
                : null;

            if ($clave === '' || strlen($clave) < 2) {
                json_response(['ok' => false, 'error' => 'La clave es obligatoria (mín. 2 caracteres)'], 400);
            }
            if (!preg_match('/^[a-z0-9_-]+$/i', $clave)) {
                json_response(['ok' => false, 'error' => 'La clave solo puede contener letras, números, guion y guion bajo'], 400);
            }
            if ($nombre === '' || strlen($nombre) < 2) {
                json_response(['ok' => false, 'error' => 'El nombre es obligatorio'], 400);
            }

            // Validar adscripción si se envía
            if ($adscripcionId !== null && !validarAdscripcionExiste($pdo, $adscripcionId)) {
                json_response(['ok' => false, 'error' => 'La adscripción seleccionada no existe o está inactiva'], 400);
            }

            $chk = $pdo->prepare("SELECT id FROM areas WHERE LOWER(clave) = LOWER(:c)");
            $chk->execute([':c' => $clave]);
            if ($chk->fetch()) {
                json_response(['ok' => false, 'error' => 'Ya existe un área con esa clave'], 409);
            }

            $stmt = $pdo->prepare("
                INSERT INTO areas (clave, nombre, descripcion, activo, adscripcion_id)
                VALUES (:c, :n, :d, :a, :ad)
                RETURNING id
            ");
            $stmt->bindValue(':c',  strtolower($clave), PDO::PARAM_STR);
            $stmt->bindValue(':n',  $nombre, PDO::PARAM_STR);
            $stmt->bindValue(':d',  $desc === '' ? null : $desc, $desc === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':a',  $activo ? 't' : 'f', PDO::PARAM_STR);
            $stmt->bindValue(':ad', $adscripcionId, $adscripcionId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->execute();

            $newId = (int)$stmt->fetchColumn();
            logger("area creada: id={$newId} clave={$clave} adscripcion={$adscripcionId}");
            json_response(['ok' => true, 'id' => $newId, 'message' => 'Área registrada correctamente'], 201);
            break;
        }

        case 'PUT': {
            if (!$id) json_response(['ok' => false, 'error' => 'ID requerido'], 400);
            $input   = json_input();
            $nombre  = trim((string)($input['nombre']  ?? ''));
            $desc    = trim((string)($input['descripcion'] ?? ''));
            $activo  = !empty($input['activo']);
            $adscripcionId = isset($input['adscripcion_id']) && $input['adscripcion_id'] !== ''
                ? (int)$input['adscripcion_id']
                : null;

            if ($nombre === '' || strlen($nombre) < 2) {
                json_response(['ok' => false, 'error' => 'El nombre es obligatorio'], 400);
            }

            if ($adscripcionId !== null && !validarAdscripcionExiste($pdo, $adscripcionId)) {
                json_response(['ok' => false, 'error' => 'La adscripción seleccionada no existe o está inactiva'], 400);
            }

            $stmt = $pdo->prepare("
                UPDATE areas
                SET nombre = :n,
                    descripcion = :d,
                    activo = :a,
                    adscripcion_id = :ad
                WHERE id = :id
            ");
            $stmt->bindValue(':n',  $nombre, PDO::PARAM_STR);
            $stmt->bindValue(':d',  $desc === '' ? null : $desc, $desc === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':a',  $activo ? 't' : 'f', PDO::PARAM_STR);
            $stmt->bindValue(':ad', $adscripcionId, $adscripcionId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();

            logger("area actualizada: id={$id}");
            json_response(['ok' => true, 'message' => 'Área actualizada correctamente']);
            break;
        }

        case 'DELETE':
            if (!$id) json_response(['ok' => false, 'error' => 'ID requerido'], 400);

            $stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE area_id = :id");
            $stmtCnt->execute([':id' => $id]);
            $cnt = (int)$stmtCnt->fetchColumn();
            if ($cnt > 0) {
                json_response([
                    'ok'    => false,
                    'error' => "No se puede eliminar: el área tiene {$cnt} usuario(s) asignado(s). Desactívala en su lugar.",
                ], 409);
            }

            // Verificar que no tenga empleados
            $stmtEmp = $pdo->prepare("SELECT COUNT(*) FROM empleados WHERE area_id = :id");
            $stmtEmp->execute([':id' => $id]);
            $cntEmp = (int)$stmtEmp->fetchColumn();
            if ($cntEmp > 0) {
                json_response([
                    'ok'    => false,
                    'error' => "No se puede eliminar: el área tiene {$cntEmp} empleado(s) asignado(s). Reasígnalos primero.",
                ], 409);
            }

            $stmtGen = $pdo->prepare("SELECT clave FROM areas WHERE id = :id");
            $stmtGen->execute([':id' => $id]);
            $general = $stmtGen->fetchColumn();
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

/* ============================================================
 * FUNCIONES AUXILIARES
 * ============================================================ */

function validarAdscripcionExiste(PDO $pdo, int $adscripcionId): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM adscripciones WHERE id = :id AND activo = TRUE LIMIT 1");
    $stmt->execute([':id' => $adscripcionId]);
    return (bool)$stmt->fetchColumn();
}