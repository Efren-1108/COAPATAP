<?php
/**
 * API: Gestión de empleados (resguardantes)
 * Métodos: GET (listar/buscar), POST (crear), PUT (actualizar), DELETE
 *
 * Seguridad:
 *   - Exige sesión (require_auth).
 *   - Si NO es superusuario, los SELECT/UPDATE/DELETE filtran por su área.
 *   - Cada INSERT guarda el area_id y usuario_id del autor.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id'])   ? (int)$_GET['id']   : null;
$nomina = isset($_GET['nomina']) ? (int)$_GET['nomina'] : null;
$q      = isset($_GET['q'])    ? trim($_GET['q'])    : null;

$current = require_auth();
$isSuper = $current['es_superusuario'];
$areaId  = $current['area_id'];

try {
    $pdo = db();

    switch ($method) {
        case 'GET':
            if ($id) {
                $sql = "SELECT e.*, a.nombre AS area_nombre
                        FROM empleados e
                        LEFT JOIN areas a ON a.id = e.area_id
                        WHERE e.id = :id";
                $params = [':id' => $id];
                if (!$isSuper) {
                    $sql .= " AND e.area_id = :__scope_area";
                    $params[':__scope_area'] = $areaId;
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $row = $stmt->fetch();
                if (!$row) json_response(['ok' => false, 'error' => 'Empleado no encontrado o sin acceso'], 404);
                json_response(['ok' => true, 'data' => $row]);
            }
            if ($nomina) {
                $sql = "SELECT * FROM empleados WHERE numero_nomina = :nomina";
                $params = [':nomina' => $nomina];
                if (!$isSuper) {
                    $sql .= " AND area_id = :__scope_area";
                    $params[':__scope_area'] = $areaId;
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $row = $stmt->fetch();
                if (!$row) json_response(['ok' => false, 'error' => 'Empleado no encontrado o sin acceso'], 404);
                json_response(['ok' => true, 'data' => $row]);
            }
            // listar / buscar
            $sql = "SELECT e.*, a.nombre AS area_nombre FROM empleados e LEFT JOIN areas a ON a.id = e.area_id";
            $params = [];
            $where = [];
            if ($q) {
                $where[] = "(CAST(e.numero_nomina AS TEXT) LIKE :q OR LOWER(e.nombre) LIKE :q OR LOWER(e.cargo) LIKE :q)";
                $params[':q'] = '%' . strtolower($q) . '%';
            }
            if (!$isSuper) {
                $where[] = "e.area_id = :__scope_area";
                $params[':__scope_area'] = $areaId;
            }
            if ($where) $sql .= " WHERE " . implode(' AND ', $where);
            $sql .= " ORDER BY e.numero_nomina ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            json_response(['ok' => true, 'data' => $rows]);
            break;

        case 'POST':
            $input = json_input();
            $numero_nomina = isset($input['numero_nomina']) ? (int)$input['numero_nomina'] : 0;
            $nombre        = isset($input['nombre']) ? trim($input['nombre']) : '';
            $cargo         = isset($input['cargo'])  ? trim($input['cargo'])  : '';

            if ($numero_nomina <= 0)               json_response(['ok' => false, 'error' => 'Número de nómina obligatorio y positivo'], 400);
            if (strlen($nombre) < 3)               json_response(['ok' => false, 'error' => 'El nombre es obligatorio (mín. 3 caracteres)'], 400);
            if (strlen($cargo) < 2)                json_response(['ok' => false, 'error' => 'El cargo es obligatorio'], 400);

            // Verificar duplicado (en TODAS las áreas, no aplica filtro de área)
            $chk = $pdo->prepare("SELECT id, area_id FROM empleados WHERE numero_nomina = :n");
            $chk->execute([':n' => $numero_nomina]);
            $existente = $chk->fetch();
            if ($existente) {
                json_response(['ok' => false, 'error' => 'Ya existe un empleado con ese número de nómina'], 409);
            }

            // El area_id del nuevo empleado = área del usuario actual (si no es super)
            $newAreaId = $isSuper
                ? (isset($input['area_id']) && $input['area_id'] !== '' ? (int)$input['area_id'] : null)
                : $areaId;

            $stmt = $pdo->prepare("INSERT INTO empleados (numero_nomina, nombre, cargo, area_id) VALUES (:n, :nom, :car, :aid) RETURNING id");
            $stmt->execute([':n' => $numero_nomina, ':nom' => $nombre, ':car' => $cargo, ':aid' => $newAreaId]);
            $newId = (int)$stmt->fetchColumn();

            // Auditoría
            $log = $pdo->prepare("INSERT INTO log_actividades (tabla, accion, registro_id, detalle, usuario, usuario_id, area_id)
                                  VALUES ('empleados', 'INSERT', :id, :det, :usr, :uid, :aid)");
            $log->execute([
                ':id' => $newId,
                ':det' => "Empleado creado: {$nombre} (nómina {$numero_nomina})",
                ':usr' => $current['nombre_usuario'],
                ':uid' => $current['id'],
                ':aid' => $newAreaId,
            ]);
            logger("Empleado creado: id={$newId} nomina={$numero_nomina} por usuario_id={$current['id']}");
            json_response(['ok' => true, 'id' => $newId, 'message' => 'Empleado registrado correctamente'], 201);
            break;

        case 'PUT':
            if (!$id) json_response(['ok' => false, 'error' => 'ID requerido'], 400);
            $input = json_input();
            $numero_nomina = isset($input['numero_nomina']) ? (int)$input['numero_nomina'] : 0;
            $nombre        = isset($input['nombre']) ? trim($input['nombre']) : '';
            $cargo         = isset($input['cargo'])  ? trim($input['cargo'])  : '';

            if ($numero_nomina <= 0) json_response(['ok' => false, 'error' => 'Número de nómina obligatorio y positivo'], 400);
            if (strlen($nombre) < 3) json_response(['ok' => false, 'error' => 'El nombre es obligatorio'], 400);
            if (strlen($cargo)  < 2) json_response(['ok' => false, 'error' => 'El cargo es obligatorio'], 400);

            // Duplicado (excepto el mismo) en toda la BD
            $chk = $pdo->prepare("SELECT id FROM empleados WHERE numero_nomina = :n AND id <> :id");
            $chk->execute([':n' => $numero_nomina, ':id' => $id]);
            if ($chk->fetch()) {
                json_response(['ok' => false, 'error' => 'Otro empleado ya tiene ese número de nómina'], 409);
            }

            // Permiso: super puede editar cualquiera; usuario normal solo de su área
            if (!$isSuper) {
                $own = $pdo->prepare("SELECT id FROM empleados WHERE id = :id AND area_id = :aid");
                $own->execute([':id' => $id, ':aid' => $areaId]);
                if (!$own->fetch()) {
                    json_response(['ok' => false, 'error' => 'No tienes permisos para editar este empleado (pertenece a otra área).'], 403);
                }
            }

            $stmt = $pdo->prepare("UPDATE empleados SET numero_nomina=:n, nombre=:nom, cargo=:car WHERE id=:id");
            $stmt->execute([':n' => $numero_nomina, ':nom' => $nombre, ':car' => $cargo, ':id' => $id]);

            $log = $pdo->prepare("INSERT INTO log_actividades (tabla, accion, registro_id, detalle, usuario, usuario_id, area_id)
                                  VALUES ('empleados', 'UPDATE', :id, :det, :usr, :uid, :aid)");
            $log->execute([
                ':id' => $id,
                ':det' => "Empleado actualizado: {$nombre} (nómina {$numero_nomina})",
                ':usr' => $current['nombre_usuario'],
                ':uid' => $current['id'],
                ':aid' => $isSuper ? null : $areaId,
            ]);
            logger("Empleado actualizado: id={$id} nomina={$numero_nomina} por usuario_id={$current['id']}");
            json_response(['ok' => true, 'message' => 'Empleado actualizado correctamente']);
            break;

        case 'DELETE':
            if (!$id) json_response(['ok' => false, 'error' => 'ID requerido'], 400);

            // Permiso por área
            if (!$isSuper) {
                $own = $pdo->prepare("SELECT id FROM empleados WHERE id = :id AND area_id = :aid");
                $own->execute([':id' => $id, ':aid' => $areaId]);
                if (!$own->fetch()) {
                    json_response(['ok' => false, 'error' => 'No tienes permisos para eliminar este empleado.'], 403);
                }
            }

            // Tomar nombre antes de eliminar (para log)
            $g = $pdo->prepare("SELECT nombre FROM empleados WHERE id = :id");
            $g->execute([':id' => $id]);
            $prev = $g->fetchColumn() ?: '';

            $stmt = $pdo->prepare("DELETE FROM empleados WHERE id = :id");
            $stmt->execute([':id' => $id]);

            $log = $pdo->prepare("INSERT INTO log_actividades (tabla, accion, registro_id, detalle, usuario, usuario_id, area_id)
                                  VALUES ('empleados', 'DELETE', :id, :det, :usr, :uid, :aid)");
            $log->execute([
                ':id' => $id,
                ':det' => "Empleado eliminado: {$prev}",
                ':usr' => $current['nombre_usuario'],
                ':uid' => $current['id'],
                ':aid' => $isSuper ? null : $areaId,
            ]);
            logger("Empleado eliminado: id={$id} por usuario_id={$current['id']}");
            json_response(['ok' => true, 'message' => 'Empleado eliminado']);
            break;

        default:
            json_response(['ok' => false, 'error' => 'Método no permitido'], 405);
    }
} catch (Throwable $e) {
    logger("Error en empleados: " . $e->getMessage(), 'ERROR');
    json_response(['ok' => false, 'error' => 'Error: ' . $e->getMessage()], 500);
}