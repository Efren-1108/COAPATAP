<?php
/**
 * API: Gestión de áreas / departamentos
 * Escritura: permisos Crear/Modificar/Eliminar del módulo Empleados y Áreas.
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
if ($method === 'POST') require_action_permission('empleados', 'crear');
if ($method === 'PUT') require_action_permission('empleados', 'modificar');
if ($method === 'DELETE') require_action_permission('empleados', 'eliminar');
$user = $method === 'GET'
    ? require_any_permission(['empleados','activos','adscripciones','usuarios','stock','importar'])
    : require_permission_csrf('empleados');

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
                if (!$row || !can_read_area((int)$row['id'], $user)) json_response(['ok' => false, 'error' => 'Área no encontrada'], 404);

                $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE area_id = :id");
                $stmtCount->execute([':id' => $row['id']]);
                $row['usuarios_count'] = (int)$stmtCount->fetchColumn();

                $stmtEmp = $pdo->prepare("SELECT COUNT(*) FROM empleados WHERE area_id = :id");
                $stmtEmp->execute([':id' => $row['id']]);
                $row['empleados_count'] = (int)$stmtEmp->fetchColumn();

                $row['puede_modificar'] = can_write_area((int)$row['id'], $user) && has_action_permission('empleados', 'modificar');
                $row['puede_eliminar'] = can_write_area((int)$row['id'], $user) && has_action_permission('empleados', 'eliminar');
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
            if (empty($user['es_superusuario'])) {
                $rows = array_values(array_filter($rows, fn($r) => can_read_area((int)$r['id'], $user)));
            }
            foreach ($rows as &$area) {
                $area['puede_modificar'] = can_write_area((int)$area['id'], $user) && has_action_permission('empleados', 'modificar');
                $area['puede_eliminar'] = can_write_area((int)$area['id'], $user) && has_action_permission('empleados', 'eliminar');
            }
            unset($area);
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

            // Crear un área nueva solo dentro de una adscripción que ya contenga
            // alguna de las áreas autorizadas al rol; nunca una adscripción ajena.
            if (empty($user['es_superusuario']) &&
                ($adscripcionId === null || !adscripcion_autorizada_para_area($pdo, $adscripcionId, $user))) {
                json_response(['ok'=>false,'error'=>'Solo puedes crear áreas en una adscripción autorizada para tu rol'],403);
            }

            $chk = $pdo->prepare("SELECT id FROM areas WHERE LOWER(clave) = LOWER(:c)");
            $chk->execute([':c' => $clave]);
            if ($chk->fetch()) {
                json_response(['ok' => false, 'error' => 'Ya existe un área con esa clave'], 409);
            }

            $pdo->beginTransaction();
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
            // En el modo de áreas seleccionadas, la nueva área pasa a ser gestionable
            // por los usuarios de este mismo rol. En modo propia se mantiene la regla
            // de solo el área personal, sin ampliarla automáticamente.
            $accesoAutomatico = !empty($user['es_superusuario']);
            if (empty($user['es_superusuario'])) {
                $alcance = $pdo->prepare('SELECT alcance_areas FROM public.roles WHERE id=:id');
                $alcance->execute([':id'=>(int)$user['rol_id']]);
                if ($alcance->fetchColumn() === 'seleccionadas') {
                    $accesoAutomatico = true;
                    $pdo->prepare('INSERT INTO public.rol_areas(rol_id,area_id) VALUES (:r,:a) ON CONFLICT DO NOTHING')
                        ->execute([':r'=>(int)$user['rol_id'], ':a'=>$newId]);
                }
            }
            $pdo->commit();
            logger("area creada: id={$newId} clave={$clave} adscripcion={$adscripcionId}");
            $mensaje = $accesoAutomatico ? 'Área registrada correctamente' :
                'Área registrada. Para verla y administrarla, el Superusuario debe incluirla en las áreas seleccionadas de tu rol.';
            json_response(['ok' => true, 'id' => $newId, 'message' => $mensaje], 201);
            break;
        }

        case 'PUT': {
            if (!$id) json_response(['ok' => false, 'error' => 'ID requerido'], 400);
            if (!area_existente_y_editable($pdo, $id, $user)) {
                json_response(['ok'=>false,'error'=>'No tienes permiso para modificar esta área'],403);
            }
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

            if (empty($user['es_superusuario']) &&
                ($adscripcionId === null || !adscripcion_autorizada_para_area($pdo, $adscripcionId, $user))) {
                json_response(['ok'=>false,'error'=>'No puedes mover el área a una adscripción fuera de tu alcance'],403);
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
            if (!area_existente_y_editable($pdo, $id, $user)) {
                json_response(['ok'=>false,'error'=>'No tienes permiso para eliminar esta área'],403);
            }

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
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
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
/** Operaciones de área solo si pertenece al alcance del rol. */
function area_existente_y_editable(PDO $pdo, int $areaId, array $user): bool
{
    $st=$pdo->prepare('SELECT id FROM public.areas WHERE id=:id');
    $st->execute([':id'=>$areaId]);
    return (bool)$st->fetchColumn() && can_write_area($areaId, $user);
}

/** Permite crear un área dentro de una adscripción que ya contiene áreas autorizadas. */
function adscripcion_autorizada_para_area(PDO $pdo, int $adscripcionId, array $user): bool
{
    if (!empty($user['es_superusuario'])) return true;
    $st=$pdo->prepare('SELECT 1 FROM public.areas a WHERE a.adscripcion_id=:ad AND '
        . read_area_condition('a.id', $user) . ' LIMIT 1');
    $st->execute([':ad'=>$adscripcionId]);
    return (bool)$st->fetchColumn();
}
