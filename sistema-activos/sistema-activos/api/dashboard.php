<?php
/**
 * API: Dashboard — Datos agregados para gráficas
 * GET /api/dashboard.php
 *   → { ok, data: { por_adscripcion, por_area, top_empleados, por_anio, totales } }
 *
 * Seguridad:
 *   - Exige sesión (require_auth).
 *   - Si NO es superusuario, filtra por su adscripción (a través de su área).
 *
 * Query opcional:
 *   ?adscripcion_id=5   → filtrar por adscripción (solo super)
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') {
    json_response(['ok' => false, 'error' => 'Método no permitido'], 405);
}

$current = require_auth();
$isSuper = $current['es_superusuario'];
$areaId  = $current['area_id'];

// Filtro por adscripción (solo para super)
$adscripcionId = null;
if ($isSuper && isset($_GET['adscripcion_id']) && $_GET['adscripcion_id'] !== '') {
    $adscripcionId = (int)$_GET['adscripcion_id'];
}

try {
    $pdo = db();

    // ============================================================
    // Determinar la adscripción del usuario actual (si no es super)
    // ============================================================
    $adscripcionDelUsuario = null;
    if (!$isSuper && $areaId) {
        $stmt = $pdo->prepare("SELECT adscripcion_id FROM areas WHERE id = :id");
        $stmt->execute([':id' => $areaId]);
        $adscripcionDelUsuario = $stmt->fetchColumn() ?: null;
    }

    // ============================================================
    // Construir WHERE dinámico
    // ============================================================
    $whereBase = [];
    $paramsBase = [];

    if (!$isSuper) {
        if ($adscripcionDelUsuario) {
            $whereBase[] = "ar.adscripcion_id = :__scope_adsc";
            $paramsBase[':__scope_adsc'] = $adscripcionDelUsuario;
        } else {
            // Usuario sin adscripción → no ve nada
            $whereBase[] = "1 = 0";
        }
    } elseif ($adscripcionId !== null) {
        $whereBase[] = "ar.adscripcion_id = :__filter_adsc";
        $paramsBase[':__filter_adsc'] = $adscripcionId;
    }

    $whereSQL = $whereBase ? ' WHERE ' . implode(' AND ', $whereBase) : '';

    // ============================================================
    // 1) Totales generales
    // ============================================================
    $sqlTotales = "
        SELECT
            COUNT(*)::int AS total_activos,
            COUNT(a.empleado_id)::int AS asignados,
            (COUNT(*) - COUNT(a.empleado_id))::int AS sin_asignar,
            COALESCE(SUM(a.costo), 0)::numeric AS costo_total
        FROM activos a
        LEFT JOIN areas ar ON ar.id = a.area_id
        $whereSQL
    ";
    $stmt = $pdo->prepare($sqlTotales);
    $stmt->execute($paramsBase);
    $totales = $stmt->fetch();

    // ============================================================
    // 2) Activos y valor por adscripción
    // ============================================================
    $sqlAdsc = "
        SELECT
            ad.id AS adscripcion_id,
            ad.nombre AS adscripcion_nombre,
            ad.clave AS adscripcion_clave,
            COUNT(a.id)::int AS activos,
            COALESCE(SUM(a.costo), 0)::numeric AS costo
        FROM activos a
        LEFT JOIN areas ar ON ar.id = a.area_id
        LEFT JOIN adscripciones ad ON ad.id = ar.adscripcion_id
        $whereSQL
        GROUP BY ad.id, ad.nombre, ad.clave
        ORDER BY costo DESC
    ";
    $stmt = $pdo->prepare($sqlAdsc);
    $stmt->execute($paramsBase);
    $porAdscripcion = $stmt->fetchAll();

    // ============================================================
    // 3) Top 10 áreas con más activos
    // ============================================================
    $sqlAreas = "
        SELECT
            ar.id AS area_id,
            ar.nombre AS area_nombre,
            ad.nombre AS adscripcion_nombre,
            COUNT(a.id)::int AS activos,
            COALESCE(SUM(a.costo), 0)::numeric AS costo
        FROM activos a
        LEFT JOIN areas ar ON ar.id = a.area_id
        LEFT JOIN adscripciones ad ON ad.id = ar.adscripcion_id
        $whereSQL
        GROUP BY ar.id, ar.nombre, ad.nombre
        ORDER BY activos DESC, costo DESC
        LIMIT 10
    ";
    $stmt = $pdo->prepare($sqlAreas);
    $stmt->execute($paramsBase);
    $topAreas = $stmt->fetchAll();

    // ============================================================
    // 4) Top 10 empleados por valor de activos
    // ============================================================
    $sqlEmp = "
        SELECT
            e.id AS empleado_id,
            e.numero_nomina,
            e.nombre AS empleado_nombre,
            e.cargo,
            COUNT(a.id)::int AS activos,
            COALESCE(SUM(a.costo), 0)::numeric AS costo
        FROM activos a
        INNER JOIN empleados e ON e.id = a.empleado_id
        LEFT JOIN areas ar ON ar.id = a.area_id
        $whereSQL
        GROUP BY e.id, e.numero_nomina, e.nombre, e.cargo
        ORDER BY costo DESC
        LIMIT 10
    ";
    $stmt = $pdo->prepare($sqlEmp);
    $stmt->execute($paramsBase);
    $topEmpleados = $stmt->fetchAll();

    // ============================================================
    // 5) Valor por año de adquisición
    // ============================================================
    $sqlAnios = "
        SELECT
            EXTRACT(YEAR FROM a.fecha_adq)::int AS anio,
            COUNT(*)::int AS activos,
            COALESCE(SUM(a.costo), 0)::numeric AS costo
        FROM activos a
        LEFT JOIN areas ar ON ar.id = a.area_id
        $whereSQL " . ($whereSQL ? " AND " : " WHERE ") . "a.fecha_adq IS NOT NULL
        GROUP BY EXTRACT(YEAR FROM a.fecha_adq)
        ORDER BY anio ASC
    ";
    $stmt = $pdo->prepare($sqlAnios);
    $stmt->execute($paramsBase);
    $porAnio = $stmt->fetchAll();

    // ============================================================
    // Normalizar tipos numéricos
    // ============================================================
    $totales['costo_total']   = (float)$totales['costo_total'];
    $totales['total_activos'] = (int)$totales['total_activos'];
    $totales['asignados']     = (int)$totales['asignados'];
    $totales['sin_asignar']   = (int)$totales['sin_asignar'];

    foreach ($porAdscripcion as &$r) {
        $r['costo']    = (float)$r['costo'];
        $r['activos']  = (int)$r['activos'];
        $r['adscripcion_id'] = $r['adscripcion_id'] !== null ? (int)$r['adscripcion_id'] : null;
    }
    unset($r);

    foreach ($topAreas as &$r) {
        $r['costo']   = (float)$r['costo'];
        $r['activos'] = (int)$r['activos'];
        $r['area_id'] = (int)$r['area_id'];
    }
    unset($r);

    foreach ($topEmpleados as &$r) {
        $r['costo']        = (float)$r['costo'];
        $r['activos']      = (int)$r['activos'];
        $r['empleado_id']  = (int)$r['empleado_id'];
        $r['numero_nomina']= (int)$r['numero_nomina'];
    }
    unset($r);

    foreach ($porAnio as &$r) {
        $r['anio']     = (int)$r['anio'];
        $r['costo']    = (float)$r['costo'];
        $r['activos']  = (int)$r['activos'];
    }
    unset($r);

    // ============================================================
    // Respuesta
    // ============================================================
    json_response([
        'ok' => true,
        'data' => [
            'totales'          => $totales,
            'por_adscripcion'  => $porAdscripcion,
            'top_areas'        => $topAreas,
            'top_empleados'    => $topEmpleados,
            'por_anio'         => $porAnio,
            'filtro' => [
                'adscripcion_id' => $adscripcionId,
                'es_super'       => $isSuper,
            ],
        ],
    ]);

} catch (Throwable $e) {
    logger("dashboard error: " . $e->getMessage(), 'ERROR');
    json_response(['ok' => false, 'error' => 'Error: ' . $e->getMessage()], 500);
}