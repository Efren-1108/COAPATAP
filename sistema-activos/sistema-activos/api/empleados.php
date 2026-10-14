<?php
/**
 * API: Gestión de empleados (resguardantes)
 * Métodos: GET (listar/buscar), POST (crear), PUT (actualizar), DELETE
 *
 * Jerarquía: Adscripción → Área → Empleado → Activo
 *
 * Cambios respecto a la versión anterior:
 *   - [MEJORA] GET devuelve adscripcion_id y adscripcion_nombre (a través del área).
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id'])     ? (int)$_GET['id']     : null;
$nomina = isset($_GET['nomina']) ? (int)$_GET['nomina'] : null;
$q      = isset($_GET['q'])      ? trim($_GET['q'])     : null;

$current = require_auth_csrf();
$isSuper = $current['es_superusuario'];
$areaId  = $current['area_id'];

try {
    $pdo = db();

    switch ($method) {
        case 'GET':
            if ($id) {
                $sql = "SELECT e.*,
                               a.nombre AS area_nombre,
                               a.clave  AS area_clave,
                               ad.id    AS adscripcion_id,
                               ad.nombre AS adscripcion_nombre,
                               ad.clave  AS adscripcion_clave,
                               (u.id IS NOT NULL) AS tiene_usuario,
                               u.nombre_usuario
                        FROM empleados e
                        LEFT JOIN areas a ON a.id = e.area_id
                        LEFT JOIN adscripciones ad ON ad.id = a.adscripcion_id
                        LEFT JOIN usuarios u
                               ON u.nombre_usuario = CAST(e.numero_nomina AS VARCHAR)
                        WHERE e.id = :id";
                $params = [':id' => $id];

                if (!$isSuper) {
                    $sql .= " AND e.area_id = :__scope_area";
                    $params[':__scope_area'] = $areaId;
                }

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $row = $stmt->fetch();
                if (!$row) {
                    json_response(['ok' => false, 'error' => 'Empleado no encontrado o sin acceso'], 404);
                }
                $row['tiene_usuario'] = (bool)($row['tiene_usuario'] ?? false);
                json_response(['ok' => true, 'data' => $row]);
            }

            if ($nomina) {
                $sql = "SELECT e.*,
                               a.nombre AS area_nombre,
                               a.clave  AS area_clave,
                               ad.id    AS adscripcion_id,
                               ad.nombre AS adscripcion_nombre,
                               ad.clave  AS adscripcion_clave,
                               (u.id IS NOT NULL) AS tiene_usuario,
                               u.nombre_usuario
                        FROM empleados e
                        LEFT JOIN areas a ON a.id = e.area_id
                        LEFT JOIN adscripciones ad ON ad.id = a.adscripcion_id
                        LEFT JOIN usuarios u
                               ON u.nombre_usuario = CAST(e.numero_nomina AS VARCHAR)
                        WHERE e.numero_nomina = :nomina";
                $params = [':nomina' => $nomina];

                if (!$isSuper) {
                    $sql .= " AND e.area_id = :__scope_area";
                    $params[':__scope_area'] = $areaId;
                }

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $row = $stmt->fetch();
                if (!$row) {
                    json_response(['ok' => false, 'error' => 'Empleado no encontrado o sin acceso'], 404);
                }
                $row['tiene_usuario'] = (bool)($row['tiene_usuario'] ?? false);
                json_response(['ok' => true, 'data' => $row]);
            }

            // ---- Listar / buscar ----
            $sql = "SELECT e.*,
                           a.nombre AS area_nombre,
                           a.clave  AS area_clave,
                           ad.id    AS adscripcion_id,
                           ad.nombre AS adscripcion_nombre,
                           ad.clave  AS adscripcion_clave,
                           (u.id IS NOT NULL) AS tiene_usuario,
                           u.nombre_usuario
                    FROM empleados e
                    LEFT JOIN areas a ON a.id = e.area_id
                    LEFT JOIN adscripciones ad ON ad.id = a.adscripcion_id
                    LEFT JOIN usuarios u
                           ON u.nombre_usuario = CAST(e.numero_nomina AS VARCHAR)";
            $params = [];
            $where  = [];

            if ($q) {
                $where[] = "(CAST(e.numero_nomina AS TEXT) LIKE :q
                          OR LOWER(e.nombre) LIKE :q
                          OR LOWER(e.cargo)  LIKE :q)";
                $params[':q'] = '%' . strtolower($q) . '%';
            }

            if (!$isSuper) {
                $where[] = "e.area_id = :__scope_area";
                $params[':__scope_area'] = $areaId;
            }

            if ($where) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " ORDER BY e.numero_nomina ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            foreach ($rows as &$r) {
                $r['tiene_usuario'] = (bool)($r['tiene_usuario'] ?? false);
            }
            unset($r);

            json_response(['ok' => true, 'data' => $rows]);
            break;

        // ============================================================
        // POST — Crear empleado
        // ============================================================
        case 'POST':
            $input = json_input();

            $numero_nomina = isset($input['numero_nomina']) ? (int)$input['numero_nomina'] : 0;
            $nombre        = trim((string)($input['nombre'] ?? ''));
            $cargo         = trim((string)($input['cargo']  ?? ''));

            $err = validarEmpleado($numero_nomina, $nombre, $cargo);
            if ($err !== true) {
                json_response(['ok' => false, 'error' => $err], 400);
            }

            $chk = $pdo->prepare("SELECT id FROM empleados WHERE numero_nomina = :n");
            $chk->execute([':n' => $numero_nomina]);
            if ($chk->fetch()) {
                json_response(['ok' => false, 'error' => 'Ya existe un empleado con ese número de nómina'], 409);
            }

            if ($isSuper) {
                $newAreaId = normalizarAreaId($input['area_id'] ?? null);
                if ($newAreaId !== null && !validarAreaExiste($pdo, $newAreaId)) {
                    json_response(['ok' => false, 'error' => 'El área especificada no existe'], 400);
                }
            } else {
                $newAreaId = $areaId;
                if ($newAreaId === null) {
                    json_response(['ok' => false, 'error' => 'Tu usuario no tiene área asignada'], 400);
                }
            }

            $stmt = $pdo->prepare("INSERT INTO empleados (numero_nomina, nombre, cargo, area_id)
                                   VALUES (:n, :nom, :car, :aid)
                                   RETURNING id");
            $stmt->execute([
                ':n'   => $numero_nomina,
                ':nom' => $nombre,
                ':car' => $cargo,
                ':aid' => $newAreaId,
            ]);
            $newId = (int)$stmt->fetchColumn();

            $log = $pdo->prepare("INSERT INTO log_actividades
                                    (tabla, accion, registro_id, detalle, usuario, usuario_id, area_id)
                                  VALUES
                                    ('empleados', 'INSERT', :id, :det, :usr, :uid, :aid)");
            $log->execute([
                ':id'  => $newId,
                ':det' => "Empleado creado: {$nombre} (nómina {$numero_nomina})",
                ':usr' => $current['nombre_usuario'],
                ':uid' => $current['id'],
                ':aid' => $newAreaId,
            ]);
            logger("Empleado creado: id={$newId} nomina={$numero_nomina} por usuario_id={$current['id']}");
            json_response(['ok' => true, 'id' => $newId, 'message' => 'Empleado registrado correctamente'], 201);
            break;

        // ============================================================
        // PUT — Actualizar empleado
        // ============================================================
        case 'PUT':
            if (!$id) {
                json_response(['ok' => false, 'error' => 'ID requerido'], 400);
            }
            $input = json_input();

            $numero_nomina = isset($input['numero_nomina']) ? (int)$input['numero_nomina'] : 0;
            $nombre        = trim((string)($input['nombre'] ?? ''));
            $cargo         = trim((string)($input['cargo']  ?? ''));

            $err = validarEmpleado($numero_nomina, $nombre, $cargo);
            if ($err !== true) {
                json_response(['ok' => false, 'error' => $err], 400);
            }

            $check = $pdo->prepare("SELECT area_id FROM empleados WHERE id = :id");
            $check->execute([':id' => $id]);
            $empActual = $check->fetch();
            if (!$empActual) {
                json_response(['ok' => false, 'error' => 'Empleado no encontrado'], 404);
            }
            if (!$isSuper && (int)$empActual['area_id'] !== (int)$areaId) {
                json_response(['ok' => false, 'error' => 'No tienes permisos para editar este empleado (pertenece a otra área).'], 403);
            }

            $chk = $pdo->prepare("SELECT id FROM empleados WHERE numero_nomina = :n AND id <> :id");
            $chk->execute([':n' => $numero_nomina, ':id' => $id]);
            if ($chk->fetch()) {
                json_response(['ok' => false, 'error' => 'Otro empleado ya tiene ese número de nómina'], 409);
            }

            $nuevoAreaId = $empActual['area_id'];
            if ($isSuper && array_key_exists('area_id', $input)) {
                $nuevoAreaId = normalizarAreaId($input['area_id']);
                if ($nuevoAreaId !== null && !validarAreaExiste($pdo, $nuevoAreaId)) {
                    json_response(['ok' => false, 'error' => 'El área especificada no existe'], 400);
                }
            }

            $stmt = $pdo->prepare("UPDATE empleados
                                   SET numero_nomina = :n,
                                       nombre        = :nom,
                                       cargo         = :car,
                                       area_id       = :aid
                                   WHERE id = :id");
            $stmt->execute([
                ':n'   => $numero_nomina,
                ':nom' => $nombre,
                ':car' => $cargo,
                ':aid' => $nuevoAreaId,
                ':id'  => $id,
            ]);

            $log = $pdo->prepare("INSERT INTO log_actividades
                                    (tabla, accion, registro_id, detalle, usuario, usuario_id, area_id)
                                  VALUES
                                    ('empleados', 'UPDATE', :id, :det, :usr, :uid, :aid)");
            $log->execute([
                ':id'  => $id,
                ':det' => "Empleado actualizado: {$nombre} (nómina {$numero_nomina})",
                ':usr' => $current['nombre_usuario'],
                ':uid' => $current['id'],
                ':aid' => $nuevoAreaId,
            ]);
            logger("Empleado actualizado: id={$id} nomina={$numero_nomina} por usuario_id={$current['id']}");
            json_response(['ok' => true, 'message' => 'Empleado actualizado correctamente']);
            break;

        // ============================================================
        // DELETE — Eliminar empleado
        // ============================================================
        case 'DELETE':
            if (!$id) {
                json_response(['ok' => false, 'error' => 'ID requerido'], 400);
            }

            $check = $pdo->prepare("SELECT area_id, nombre FROM empleados WHERE id = :id");
            $check->execute([':id' => $id]);
            $emp = $check->fetch();
            if (!$emp) {
                json_response(['ok' => false, 'error' => 'Empleado no encontrado'], 404);
            }
            if (!$isSuper && (int)$emp['area_id'] !== (int)$areaId) {
                json_response(['ok' => false, 'error' => 'No tienes permisos para eliminar este empleado.'], 403);
            }

            $cnt = $pdo->prepare("SELECT COUNT(*) FROM activos WHERE empleado_id = :id");
            $cnt->execute([':id' => $id]);
            $numActivos = (int)$cnt->fetchColumn();

            $stmt = $pdo->prepare("DELETE FROM empleados WHERE id = :id");
            $stmt->execute([':id' => $id]);

            $detalle = "Empleado eliminado: {$emp['nombre']}";
            if ($numActivos > 0) {
                $detalle .= " — {$numActivos} activo(s) quedaron sin resguardante";
            }

            $log = $pdo->prepare("INSERT INTO log_actividades
                                    (tabla, accion, registro_id, detalle, usuario, usuario_id, area_id)
                                  VALUES
                                    ('empleados', 'DELETE', :id, :det, :usr, :uid, :aid)");
            $log->execute([
                ':id'  => $id,
                ':det' => $detalle,
                ':usr' => $current['nombre_usuario'],
                ':uid' => $current['id'],
                ':aid' => $emp['area_id'],
            ]);
            logger("Empleado eliminado: id={$id} por usuario_id={$current['id']} (activos desasignados: {$numActivos})");

            $msg = 'Empleado eliminado';
            if ($numActivos > 0) {
                $msg .= " — {$numActivos} activo(s) quedaron sin resguardante";
            }
            json_response(['ok' => true, 'message' => $msg]);
            break;

        default:
            json_response(['ok' => false, 'error' => 'Método no permitido'], 405);
    }

} catch (Throwable $e) {
    logger("Error en empleados: " . $e->getMessage(), 'ERROR');
    json_response(['ok' => false, 'error' => 'Error: ' . $e->getMessage()], 500);
}

/* ============================================================
 * FUNCIONES AUXILIARES
 * ============================================================ */

function validarEmpleado(int $numero_nomina, string $nombre, string $cargo)
{
    if ($numero_nomina <= 0) {
        return 'Número de nómina obligatorio y positivo';
    }
    if (strlen($nombre) < 3) {
        return 'El nombre es obligatorio (mín. 3 caracteres)';
    }
    if (strlen($nombre) > 200) {
        return 'El nombre no puede exceder 200 caracteres';
    }
    if (strlen($cargo) < 2) {
        return 'El cargo es obligatorio';
    }
    if (strlen($cargo) > 150) {
        return 'El cargo no puede exceder 150 caracteres';
    }
    return true;
}

function normalizarAreaId($valor): ?int
{
    if ($valor === null || $valor === '' || $valor === 0 || $valor === '0') {
        return null;
    }
    if (!filter_var($valor, FILTER_VALIDATE_INT)) {
        return null;
    }
    $n = (int)$valor;
    return $n > 0 ? $n : null;
}

function validarAreaExiste(PDO $pdo, int $areaId): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM areas WHERE id = :id AND activo = TRUE LIMIT 1");
    $stmt->execute([':id' => $areaId]);
    return (bool)$stmt->fetchColumn();
}