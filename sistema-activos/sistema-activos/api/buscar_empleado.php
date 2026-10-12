<?php
/**
 * API: Autocompletado de empleados por nómina
 * GET /api/buscar_empleado.php?nomina=1001
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$nomina = isset($_GET['nomina']) ? trim($_GET['nomina']) : '';

if (empty($nomina)) {
    json_response(['ok' => false, 'error' => 'Nómina requerida'], 400);
}

try {
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT e.nombre, e.cargo, a.id AS area_id, a.nombre AS area_nombre
        FROM empleados e
        LEFT JOIN areas a ON a.id = e.area_id
        WHERE e.numero_nomina = :n
        LIMIT 1
    ");
    $stmt->execute([':n' => $nomina]);
    $emp = $stmt->fetch();

    if (!$emp) {
        json_response(['ok' => false, 'error' => 'Empleado no encontrado'], 404);
    }

    json_response(['ok' => true, 'data' => $emp]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'Error: ' . $e->getMessage()], 500);
}
