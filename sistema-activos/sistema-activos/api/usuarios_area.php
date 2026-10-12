<?php
/**
 * API: Detalle de usuarios por área
 * GET /api/usuarios_area.php?area_id=5
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$areaId = isset($_GET['area_id']) ? (int)$_GET['area_id'] : 0;

if ($areaId <= 0) {
    json_response(['ok' => false, 'error' => 'ID de área requerido'], 400);
}

$current = require_auth();

try {
    $pdo = db();

    // Buscamos la información del área primero
    $stmtArea = $pdo->prepare("SELECT nombre FROM areas WHERE id = :id AND activo = TRUE");
    $stmtArea->execute([':id' => $areaId]);
    $area = $stmtArea->fetch();

    if (!$area) {
        json_response(['ok' => false, 'error' => 'Área no encontrada o inactiva'], 404);
    }

    // Buscamos los usuarios asignados a esa área
    $stmt = $pdo->prepare("
        SELECT u.id, u.nombre_completo, u.nombre_usuario, u.numero_nomina, u.correo,
               u.estado, r.nombre AS rol_nombre
        FROM usuarios u
        JOIN roles r ON r.id = u.rol_id
        WHERE u.area_id = :aid
        ORDER BY u.nombre_completo ASC
    ");
    $stmt->execute([':aid' => $areaId]);
    $usuarios = $stmt->fetchAll();

    json_response([
        'ok' => true,
        'area_nombre' => $area['nombre'],
        'usuarios' => $usuarios
    ]);
} catch (Throwable $e) {
    logger("Error en usuarios_area: " . $e->getMessage(), 'ERROR');
    json_response(['ok' => false, 'error' => 'Error interno del servidor'], 500);
}