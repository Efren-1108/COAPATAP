<?php
/**
 * COAPATAP | GET /api/buscar_empleados_nombre.php?q=texto
 * Búsqueda parcial por nombre, sin exigir número de nómina.
 * Resultados siempre limitados a la misma área autorizada en Consulta.
 * NO identifica empleados automáticamente: requiere seleccionar su id.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_response(['ok' => false, 'error' => 'Método no permitido'], 405);
}
$current = require_auth();
$q = trim((string) ($_GET['q'] ?? ''));
$q = preg_replace('/\s+/u', ' ', $q) ?? $q;
$longitud = function_exists('mb_strlen') ? mb_strlen($q, 'UTF-8') : strlen($q);
if ($longitud < 2 || $longitud > 100) {
    json_response(['ok' => false, 'error' => 'Escribe entre 2 y 100 caracteres del nombre'], 400);
}

try {
    $pdo = db();
    // POSITION interpreta el nombre como texto literal (no como comodines SQL).
    // TRANSLATE permite coincidir independientemente de tildes comunes.
    $sql = "SELECT e.id, e.nombre, e.numero_nomina, e.cargo,
                   e.area_id, a.nombre AS area_nombre,
                   ad.nombre AS adscripcion_nombre
            FROM empleados e
            LEFT JOIN areas a ON a.id = e.area_id
            LEFT JOIN adscripciones ad ON ad.id = a.adscripcion_id
            WHERE POSITION(
                TRANSLATE(UPPER(:texto), 'ÁÉÍÓÚÜÑ', 'AEIOUUN')
                IN TRANSLATE(UPPER(e.nombre), 'ÁÉÍÓÚÜÑ', 'AEIOUUN')
            ) > 0";
    $params = [':texto' => $q];

    if (!$current['es_superusuario']) {
        $sql .= ' AND e.area_id = :area_usuario';
        $params[':area_usuario'] = (int) $current['area_id'];
    }
    $sql .= ' ORDER BY e.nombre ASC, e.id ASC LIMIT 21';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $todos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $mas = count($todos) > 20;
    $todos = array_slice($todos, 0, 20);

    foreach ($todos as &$empleado) {
        $empleado['id'] = (int) $empleado['id'];
        $empleado['area_id'] = $empleado['area_id'] !== null ? (int) $empleado['area_id'] : null;
        $empleado['numero_nomina'] = $empleado['numero_nomina'] !== null
            ? (int) $empleado['numero_nomina'] : null;
    }
    unset($empleado);

    json_response(['ok' => true, 'data' => $todos, 'hay_mas' => $mas]);
} catch (Throwable $e) {
    logger('Error buscando empleados por nombre: ' . $e->getMessage(), 'ERROR');
    json_response(['ok' => false, 'error' => 'No fue posible buscar empleados'], 500);
}
