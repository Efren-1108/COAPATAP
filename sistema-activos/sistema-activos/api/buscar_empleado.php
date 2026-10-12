<?php
/**
 * API: Autocompletado de empleados por nómina
 * GET /api/buscar_empleado.php?nomina=1001
 *
 * Respuesta:
 *   { ok: true, data: { id, numero_nomina, nombre, cargo, area_id, area_nombre } }
 *
 * Seguridad:
 *   - Exige sesión (require_auth).
 *   - Si NO es superusuario, solo encuentra empleados de su área.
 *
 * Cambios respecto a la versión anterior:
 *   - [FIX]    Se agrega require_auth() — antes era público.
 *   - [FIX]    Se filtra por área si el usuario no es superusuario.
 *   - [MEJORA] Se valida que la nómina sea un entero positivo.
 *   - [MEJORA] Se devuelve el id del empleado y numero_nomina.
 *   - [MEJORA] Se devuelve area_clave además de area_nombre.
 *   - [MEJORA] Se registra log de búsqueda (SEARCH) en log_actividades.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$nomina = isset($_GET['nomina']) ? trim((string)$_GET['nomina']) : '';

// ============================================================
// Validaciones básicas
// ============================================================
if ($method !== 'GET') {
    json_response(['ok' => false, 'error' => 'Método no permitido'], 405);
}
if ($nomina === '') {
    json_response(['ok' => false, 'error' => 'Nómina requerida'], 400);
}
if (!ctype_digit($nomina) || (int)$nomina <= 0) {
    json_response(['ok' => false, 'error' => 'La nómina debe ser un número entero positivo'], 400);
}

$nominaInt = (int)$nomina;

// ============================================================
// Autenticación y permisos
// ============================================================
$current = require_auth();
$isSuper = $current['es_superusuario'];
$areaId  = $current['area_id'];

try {
    $pdo = db();

    // ------------------------------------------------------------
    // Buscar empleado por nómina
    // ------------------------------------------------------------
    $sql = "SELECT e.id,
                   e.numero_nomina,
                   e.nombre,
                   e.cargo,
                   e.area_id,
                   a.clave  AS area_clave,
                   a.nombre AS area_nombre
            FROM empleados e
            LEFT JOIN areas a ON a.id = e.area_id
            WHERE e.numero_nomina = :n";
    $params = [':n' => $nominaInt];

    if (!$isSuper) {
        $sql .= " AND e.area_id = :__scope_area";
        $params[':__scope_area'] = $areaId;
    }
    $sql .= " LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $emp = $stmt->fetch();

    if (!$emp) {
        json_response([
            'ok'    => false,
            'error' => 'Empleado no encontrado'
                     . ($isSuper ? '' : ' en tu área'),
        ], 404);
    }

    // Normalizar tipos
    $emp['id']            = (int)$emp['id'];
    $emp['numero_nomina'] = (int)$emp['numero_nomina'];
    $emp['area_id']       = $emp['area_id'] !== null ? (int)$emp['area_id'] : null;

    // ------------------------------------------------------------
    // Log de búsqueda (opcional pero útil)
    // ------------------------------------------------------------
    $log = $pdo->prepare("INSERT INTO log_actividades
                            (tabla, accion, registro_id, detalle, usuario, usuario_id, area_id)
                          VALUES
                            ('empleados', 'SEARCH', :id, :det, :usr, :uid, :aid)");
    $log->execute([
        ':id'  => $emp['id'],
        ':det' => "Autocompletado por nómina {$nominaInt}",
        ':usr' => $current['nombre_usuario'],
        ':uid' => $current['id'],
        ':aid' => $emp['area_id'],
    ]);

    json_response(['ok' => true, 'data' => $emp]);

} catch (Throwable $e) {
    logger("Error en buscar_empleado: " . $e->getMessage(), 'ERROR');
    json_response(['ok' => false, 'error' => 'Error: ' . $e->getMessage()], 500);
}