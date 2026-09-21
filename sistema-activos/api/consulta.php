<?php
/**
 * API: Consulta de resguardo por número de nómina
 * GET /api/consulta.php?nomina=1001
 *   → { ok, empleado: {...}, activos: [...], total, costo_total }
 *
 * Seguridad:
 *   - Exige sesión.
 *   - Si NO es super, solo encuentra empleados de su área.
 *   - Solo lista activos del área del empleado.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$nomina = isset($_GET['nomina']) ? (int)$_GET['nomina'] : 0;

if ($method !== 'GET') json_response(['ok' => false, 'error' => 'Método no permitido'], 405);
if ($nomina <= 0)      json_response(['ok' => false, 'error' => 'Número de nómina requerido'], 400);

$current = require_auth();
$isSuper = $current['es_superusuario'];
$areaId  = $current['area_id'];

try {
    $pdo = db();

    // 1) Buscar empleado (con filtro de área si no es super)
    $sql = "SELECT * FROM empleados WHERE numero_nomina = :n";
    $params = [':n' => $nomina];
    if (!$isSuper) {
        $sql .= " AND area_id = :__scope_area";
        $params[':__scope_area'] = $areaId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $emp = $stmt->fetch();
    if (!$emp) {
        json_response([
            'ok'    => false,
            'error' => 'No se encontró ningún empleado con el número de nómina ' . $nomina
                . ($isSuper ? '' : ' en tu área'),
        ], 404);
    }

    // 2) Traer sus activos (también filtrados por el área del empleado)
    $stmt = $pdo->prepare("SELECT * FROM activos WHERE empleado_id = :id AND area_id = :aid ORDER BY num_inventario ASC");
    $stmt->execute([':id' => $emp['id'], ':aid' => $emp['area_id']]);
    $activos = $stmt->fetchAll();

    $costoTotal = array_sum(array_map(fn($a) => (float)($a['costo'] ?? 0), $activos));

    // 3) Log de búsqueda
    $log = $pdo->prepare("INSERT INTO log_actividades (tabla, accion, registro_id, detalle, usuario, usuario_id, area_id)
                          VALUES ('empleados','SEARCH',:id,:d,:u,:uid,:aid)");
    $log->execute([
        ':id'  => $emp['id'],
        ':d'   => "Búsqueda por nómina {$nomina}",
        ':u'   => $current['nombre_usuario'],
        ':uid' => $current['id'],
        ':aid' => $emp['area_id'],
    ]);

    json_response([
        'ok'          => true,
        'empleado'    => $emp,
        'activos'     => $activos,
        'total'       => count($activos),
        'costo_total' => $costoTotal,
    ]);
} catch (Throwable $e) {
    logger("Error en consulta: " . $e->getMessage(), 'ERROR');
    json_response(['ok' => false, 'error' => 'Error: ' . $e->getMessage()], 500);
}