<?php
/**
 * API: Consulta de resguardo por número de nómina
 * GET /api/consulta.php?nomina=1001
 *   → { ok, empleado: {...}, activos: [...], total, costo_total }
 *
 * Seguridad:
 *   - Exige sesión.
 *   - Si NO es super, solo encuentra empleados de su área.
 *   - Solo lista activos del área del usuario (no del área del empleado).
 *
 * Cambios respecto a la versión anterior:
 *   - [FIX]    Se filtra también por area_id en la consulta de activos
 *              (antes se filtraba solo por empleado_id, lo que permitía
 *              ver activos de otras áreas si estaban asignados a un
 *              empleado del área del usuario).
 *   - [MEJORA] Validación explícita de nómina positiva.
 *   - [MEJORA] Respuesta normalizada: total y costo_total como int/float.
 *   - [MEJORA] Se agrega area_nombre del empleado en la respuesta.
 *   - [MEJORA] Log de búsqueda incluye total y costo_total.
 *   - [MEJORA] Se usa SELECT explícito en activos (no SELECT *) para
 *              dejar claro qué columnas se exponen.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    json_response(['ok' => false, 'error' => 'Método no permitido'], 405);
}

// Admite dos identificadores, sin cambiar las consultas antiguas:
// consulta.php?nomina=572   |   consulta.php?id=48
$idTexto     = trim((string) ($_GET['id'] ?? ''));
$nominaTexto = trim((string) ($_GET['nomina'] ?? ''));
$buscarPorId = $idTexto !== '';
$texto = $buscarPorId ? $idTexto : $nominaTexto;

if (!ctype_digit($texto) || (int) $texto < 1 || (float) $texto > 2147483647) {
    json_response([
        'ok' => false,
        'error' => $buscarPorId
            ? 'Identificador de empleado inválido'
            : 'Número de nómina requerido o inválido',
    ], 400);
}

$identificador = (int) $texto;
$current = require_auth();
$isSuper = (bool) $current['es_superusuario'];
$areaId  = $current['area_id'];

try {
    $pdo = db();

    // ============================================================
    // 1) Buscar empleado (con filtro de área si no es super)
    // ============================================================
    $sql = "SELECT e.*,
                   a.nombre AS area_nombre,
                   a.clave  AS area_clave
            FROM empleados e
            LEFT JOIN areas a ON a.id = e.area_id
            WHERE " . ($buscarPorId ? "e.id = :identificador" : "e.numero_nomina = :identificador");
    $params = [':identificador' => $identificador];

    if (!$isSuper) {
        $sql .= " AND e.area_id = :__scope_area";
        $params[':__scope_area'] = $areaId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $emp = $stmt->fetch();

    if (!$emp) {
        json_response([
            'ok'    => false,
            'error' => 'No se encontró el empleado solicitado'
                     . ($isSuper ? '' : ' en tu área'),
        ], 404);
    }

    // ============================================================
    // 2) Traer sus activos
    // ------------------------------------------------------------
    // IMPORTANTE: filtramos por empleado_id Y (si no es super) por
    // area_id del activo. Así evitamos que un usuario normal vea
    // activos que, aunque estén asignados a un empleado de su área,
    // pertenezcan administrativamente a otra área.
    // ============================================================
    $sqlAct = "SELECT a.id,
                      a.descripcion,
                      a.num_inventario,
                      a.marca,
                      a.modelo,
                      a.serie,
                      a.material,
                      a.fecha_adq,
                      a.factura,
                      a.costo,
                      a.observaciones,
                      a.empleado_id,
                      a.ruta_imagen,
                      a.area_id,
                      a.creado_en,
                      a.actualizado_en,
                      ar.nombre AS area_nombre
               FROM activos a
               LEFT JOIN areas ar ON ar.id = a.area_id
               WHERE a.empleado_id = :eid";
    $paramsAct = [':eid' => $emp['id']];

    if (!$isSuper) {
        $sqlAct .= " AND a.area_id = :__scope_area";
        $paramsAct[':__scope_area'] = $areaId;
    }

    $sqlAct .= " ORDER BY a.num_inventario ASC";

    $stmtAct = $pdo->prepare($sqlAct);
    $stmtAct->execute($paramsAct);
    $activos = $stmtAct->fetchAll();

    // ============================================================
    // 3) Calcular totales (normalizados)
    // ============================================================
    $total      = count($activos);
    $costoTotal = 0.0;
    foreach ($activos as $a) {
        $costoTotal += (float)($a['costo'] ?? 0);
    }
    // Normalizar tipos en cada activo
    foreach ($activos as &$a) {
        $a['id']         = (int)$a['id'];
        $a['empleado_id']= $a['empleado_id'] !== null ? (int)$a['empleado_id'] : null;
        $a['area_id']    = $a['area_id']     !== null ? (int)$a['area_id']     : null;
        $a['costo']      = (float)($a['costo'] ?? 0);
    }
    unset($a);

    // Normalizar tipos del empleado
    $emp['id']            = (int)$emp['id'];
    $emp['numero_nomina'] = $emp['numero_nomina'] !== null
        ? (int) $emp['numero_nomina'] : null;
    $emp['area_id']       = $emp['area_id'] !== null ? (int)$emp['area_id'] : null;

    // ============================================================
    // 4) Log de búsqueda (incluye totales)
    // ============================================================
    $log = $pdo->prepare("INSERT INTO log_actividades
                            (tabla, accion, registro_id, detalle, usuario, usuario_id, area_id)
                          VALUES
                            ('empleados', 'SEARCH', :id, :d, :u, :uid, :aid)");
    $log->execute([
        ':id'  => $emp['id'],
        ':d'   => ($buscarPorId ? "Búsqueda por ID {$identificador}" : "Búsqueda por nómina {$identificador}") . " — {$total} bien(es), costo total $" . number_format($costoTotal, 2),
        ':u'   => $current['nombre_usuario'],
        ':uid' => $current['id'],
        ':aid' => $emp['area_id'],
    ]);

    // ============================================================
    // 5) Respuesta
    // ============================================================
    json_response([
        'ok'          => true,
        'empleado'    => $emp,
        'activos'     => $activos,
        'total'       => $total,
        'costo_total' => round($costoTotal, 2),
    ]);

} catch (Throwable $e) {
    logger("Error en consulta: " . $e->getMessage(), 'ERROR');
    json_response(['ok' => false, 'error' => 'Error: ' . $e->getMessage()], 500);
}