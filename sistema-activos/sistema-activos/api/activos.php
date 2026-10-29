<?php
/**
 * API: Gestión de activos fijos (bienes)
 * Jerarquía: Adscripción → Área → Empleado → Activo
 *
 * Cambios:
 *   - [MEJORA] GET devuelve adscripcion_id, adscripcion_nombre y adscripcion_clave.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$empId  = isset($_GET['empleado_id']) ? (int)$_GET['empleado_id'] : null;
$q      = isset($_GET['q']) ? trim($_GET['q']) : null;

$current = require_auth_csrf();
$isSuper = $current['es_superusuario'];
$areaId  = $current['area_id'];

try {
    $pdo = db();

    switch ($method) {
        case 'GET':
            if ($id) {
                $sql = "SELECT a.*,
                               e.numero_nomina, e.nombre AS empleado_nombre, e.cargo,
                               ar.nombre AS area_nombre,
                               ad.id     AS adscripcion_id,
                               ad.nombre AS adscripcion_nombre,
                               ad.clave  AS adscripcion_clave
                        FROM activos a
                        LEFT JOIN empleados e ON e.id = a.empleado_id
                        LEFT JOIN areas    ar ON ar.id = a.area_id
                        LEFT JOIN adscripciones ad ON ad.id = ar.adscripcion_id
                        WHERE a.id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':id' => $id]);
                $row = $stmt->fetch();

                if (!$row) {
                    json_response(['ok' => false, 'error' => 'Activo no encontrado o sin acceso'], 404);
                }

                if (!$isSuper) {
                    $myEmpId = obtenerEmpleadoIdDeUsuario($pdo, (int)$current['id']);
                    if (!$myEmpId || (int)$row['empleado_id'] !== (int)$myEmpId) {
                        json_response(['ok' => false, 'error' => 'Activo no encontrado o sin acceso'], 404);
                    }
                }

                json_response(['ok' => true, 'data' => $row]);
            }

            $sql = "SELECT a.*,
                           e.numero_nomina, e.nombre AS empleado_nombre, e.cargo,
                           ar.nombre AS area_nombre,
                           ad.id     AS adscripcion_id,
                           ad.nombre AS adscripcion_nombre,
                           ad.clave  AS adscripcion_clave
                    FROM activos a
                    LEFT JOIN empleados e ON e.id = a.empleado_id
                    LEFT JOIN areas    ar ON ar.id = a.area_id
                    LEFT JOIN adscripciones ad ON ad.id = ar.adscripcion_id";
            $params = [];
            $where  = [];

            if ($empId) {
                $where[] = "a.empleado_id = :eid";
                $params[':eid'] = $empId;
            }
            if ($q) {
                $where[] = "(LOWER(a.descripcion)    LIKE :q
                          OR LOWER(a.num_inventario) LIKE :q
                          OR LOWER(a.marca)          LIKE :q
                          OR LOWER(a.modelo)         LIKE :q
                          OR LOWER(a.serie)          LIKE :q
                          OR LOWER(COALESCE(ad.nombre,'')) LIKE :q)";
                $params[':q'] = '%' . strtolower($q) . '%';
            }

            if (!$isSuper) {
                $myEmpId = obtenerEmpleadoIdDeUsuario($pdo, (int)$current['id']);
                if ($myEmpId) {
                    $where[] = "a.empleado_id = :my_eid";
                    $params[':my_eid'] = $myEmpId;
                } else {
                    $where[] = "1 = 0";
                }
            }

            if ($where) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " ORDER BY a.creado_en DESC, a.id DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            json_response(['ok' => true, 'data' => $stmt->fetchAll()]);
            break;

        case 'POST':
            $input = json_input();
            $r = validarActivo($input, true);
            if ($r !== true) {
                json_response(['ok' => false, 'error' => $r], 400);
            }


            $stockNuevo = !empty($input['crear_en_stock']);
            if ($stockNuevo && !$isSuper) json_response(['ok'=>false,'error'=>'Solo el superusuario puede registrar bienes en stock.'],403);
            $empleadoId = $stockNuevo ? null : normalizarEmpleadoId($input['empleado_id'] ?? null);
            $newAreaId = $isSuper
                ? (isset($input['area_id']) && $input['area_id'] !== '' ? (int)$input['area_id'] : null)
                : $areaId;
            if ($empleadoId !== null) {
                // El área del activo debe coincidir con la de su responsable.
                $areaEmpleado = obtenerAreaDelEmpleado($pdo, $empleadoId);
                if ($areaEmpleado === null) {
                    json_response(['ok' => false, 'error' => 'Empleado no encontrado o sin área.'], 400);
                }
                if (!$isSuper && (int)$areaEmpleado !== (int)$areaId) {
                    json_response(['ok' => false, 'error' => 'No puedes asignar empleados de otra área.'], 403);
                }
                $newAreaId = $areaEmpleado;
            }

            if ($stockNuevo) $newAreaId = null;
            $sql = "INSERT INTO activos (
                        descripcion, num_inventario, marca, modelo, serie, material,
                        estado_fisico, fecha_adq, factura, costo, observaciones, empleado_id, ruta_imagen,
                        area_id, usuario_id, situacion_inventario, fecha_ingreso_stock
                    ) VALUES (
                        :desc, :ni, :mar, :mod, :ser, :mat,
                        :est, :fec, :fac, :cos, :obs, :eid, :img,
                        :aid, :uid, :sit, :stockfecha
                    ) RETURNING id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':desc' => $input['descripcion'],
                ':ni'   => $input['num_inventario'],
                ':mar'  => $input['marca']         ?: null,
                ':mod'  => $input['modelo']        ?: null,
                ':ser'  => $input['serie']         ?: null,
                ':mat'  => $input['material']      ?: null,
                ':est'  => $input['estado_fisico'] ?? null,
                ':fec'  => $input['fecha_adq'] ?? null,
                ':fac'  => $input['factura']       ?: null,
                ':cos'  => $input['costo'],
                ':obs'  => $input['observaciones'] ?: null,
                ':eid'  => $empleadoId,
                ':img'  => $input['ruta_imagen']   ?: null,
                ':aid'  => $newAreaId,
                ':uid'  => $current['id'],
                ':sit'  => $stockNuevo ? 'STOCK' : ($empleadoId ? 'ASIGNADO' : 'SIN_ASIGNAR'),
                ':stockfecha' => $stockNuevo ? date('c') : null,
            ]);
            $newId = (int)$stmt->fetchColumn();
            if ($stockNuevo) {
                $mov = $pdo->prepare("INSERT INTO public.movimientos_stock(activo_id,tipo,motivo,usuario_id,usuario_nombre) VALUES (:id,'ENTRADA','Registro inicial en almacén general',:uid,:usr)");
                $mov->execute([':id'=>$newId,':uid'=>$current['id'],':usr'=>$current['nombre_usuario']]);
            }

            $log = $pdo->prepare("INSERT INTO log_actividades
                                    (tabla, accion, registro_id, detalle, usuario, usuario_id, area_id)
                                  VALUES
                                    ('activos', 'INSERT', :id, :det, :usr, :uid, :aid)");
            $log->execute([
                ':id'  => $newId,
                ':det' => "Activo creado: {$input['descripcion']} (inv {$input['num_inventario']})",
                ':usr' => $current['nombre_usuario'],
                ':uid' => $current['id'],
                ':aid' => $newAreaId,
            ]);
            logger("Activo creado: id={$newId} inv={$input['num_inventario']} por usuario_id={$current['id']}");
            json_response(['ok' => true, 'id' => $newId, 'message' => 'Activo registrado correctamente'], 201);
            break;

        case 'PUT':
            if (!$id) {
                json_response(['ok' => false, 'error' => 'ID requerido'], 400);
            }
            $input = json_input();
            $r = validarActivo($input, false);
            if ($r !== true) {
                json_response(['ok' => false, 'error' => $r], 400);
            }

            if (!$isSuper) {
                $own = $pdo->prepare("SELECT id FROM activos WHERE id = :id AND area_id = :aid");
                $own->execute([':id' => $id, ':aid' => $areaId]);
                if (!$own->fetch()) {
                    json_response(['ok' => false, 'error' => 'No tienes permisos para editar este activo (pertenece a otra área).'], 403);
                }
            }

            $existe = $pdo->prepare("SELECT ruta_imagen, area_id, empleado_id, situacion_inventario FROM activos WHERE id = :id");
            $existe->execute([':id' => $id]);
            $anterior = $existe->fetch(PDO::FETCH_ASSOC);
            if (!$anterior) {
                json_response(['ok' => false, 'error' => 'Activo no encontrado'], 404);
            }
            $oldImg = $anterior['ruta_imagen'];


            $empleadoId = normalizarEmpleadoId($input['empleado_id'] ?? null);
            if ($anterior['situacion_inventario'] === 'STOCK' && $empleadoId !== null) {
                json_response(['ok'=>false,'error'=>'Para retirar un bien del stock utiliza Asignar desde Stock.'],409);
            }
            $nuevaImg   = $input['ruta_imagen'] ?? null;
            // Si el activo cambia de empleado, actualizar también su área.
            // Si queda No asignado, preservar el área que ya tenía.
            $nuevaAreaId = $anterior['area_id'];
            if ($empleadoId !== null) {
                $areaEmpleado = obtenerAreaDelEmpleado($pdo, $empleadoId);
                if ($areaEmpleado === null) {
                    json_response(['ok' => false, 'error' => 'Empleado no encontrado o sin área.'], 400);
                }
                if (!$isSuper && (int)$areaEmpleado !== (int)$areaId) {
                    json_response(['ok' => false, 'error' => 'No puedes asignar empleados de otra área.'], 403);
                }
                $nuevaAreaId = $areaEmpleado;
            }

            $sql = "UPDATE activos SET
                        descripcion   = :desc,
                        num_inventario= :ni,
                        marca         = :mar,
                        modelo        = :mod,
                        serie         = :ser,
                        material      = :mat,
                        estado_fisico = :est,
                        fecha_adq     = :fec,
                        factura       = :fac,
                        costo         = :cos,
                        observaciones = :obs,
                        empleado_id   = :eid,
                        area_id       = :aid,
                        situacion_inventario = CASE WHEN situacion_inventario = 'STOCK' THEN 'STOCK' WHEN CAST(:eid_sit AS INTEGER) IS NOT NULL THEN 'ASIGNADO' ELSE 'SIN_ASIGNAR' END,
                        ruta_imagen   = :img
                    WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':desc' => $input['descripcion'],
                ':ni'   => $input['num_inventario'],
                ':mar'  => $input['marca']         ?: null,
                ':mod'  => $input['modelo']        ?: null,
                ':ser'  => $input['serie']         ?: null,
                ':mat'  => $input['material']      ?: null,
                ':est'  => $input['estado_fisico'] ?? null,
                ':fec'  => $input['fecha_adq'] ?? null,
                ':fac'  => $input['factura']       ?: null,
                ':cos'  => $input['costo'],
                ':obs'  => $input['observaciones'] ?: null,
                ':eid'  => $empleadoId,
                ':eid_sit' => $empleadoId,
                ':img'  => $nuevaImg               ?: null,
                ':aid'  => $nuevaAreaId,
                ':id'   => $id,
            ]);

            if ($oldImg && $nuevaImg && $oldImg !== $nuevaImg) {
                $fullPath = __DIR__ . '/../' . $oldImg;
                if (file_exists($fullPath)) {
                    @unlink($fullPath);
                }
            }

            $log = $pdo->prepare("INSERT INTO log_actividades
                                    (tabla, accion, registro_id, detalle, usuario, usuario_id, area_id)
                                  VALUES
                                    ('activos', 'UPDATE', :id, :det, :usr, :uid, :aid)");
            $log->execute([
                ':id'  => $id,
                ':det' => "Activo actualizado: {$input['descripcion']} (inv {$input['num_inventario']})",
                ':usr' => $current['nombre_usuario'],
                ':uid' => $current['id'],
                ':aid' => $nuevaAreaId,
            ]);
            logger("Activo actualizado: id={$id} inv={$input['num_inventario']} por usuario_id={$current['id']}");
            json_response(['ok' => true, 'message' => 'Activo actualizado correctamente']);
            break;

        case 'DELETE':
            if (!$id) {
                json_response(['ok' => false, 'error' => 'ID requerido'], 400);
            }

            if (!$isSuper) {
                $own = $pdo->prepare("SELECT id FROM activos WHERE id = :id AND area_id = :aid");
                $own->execute([':id' => $id, ':aid' => $areaId]);
                if (!$own->fetch()) {
                    json_response(['ok' => false, 'error' => 'No tienes permisos para eliminar este activo.'], 403);
                }
            }

            $stmt = $pdo->prepare("SELECT ruta_imagen, descripcion FROM activos WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();
            if (!$row) {
                json_response(['ok' => false, 'error' => 'Activo no encontrado'], 404);
            }

            $del = $pdo->prepare("DELETE FROM activos WHERE id = :id");
            $del->execute([':id' => $id]);

            if ($row['ruta_imagen'] && file_exists(__DIR__ . '/../' . $row['ruta_imagen'])) {
                @unlink(__DIR__ . '/../' . $row['ruta_imagen']);
            }

            $log = $pdo->prepare("INSERT INTO log_actividades
                                    (tabla, accion, registro_id, detalle, usuario, usuario_id, area_id)
                                  VALUES
                                    ('activos', 'DELETE', :id, :det, :usr, :uid, :aid)");
            $log->execute([
                ':id'  => $id,
                ':det' => "Activo eliminado: {$row['descripcion']}",
                ':usr' => $current['nombre_usuario'],
                ':uid' => $current['id'],
                ':aid' => $isSuper ? null : $areaId,
            ]);
            logger("Activo eliminado: id={$id} por usuario_id={$current['id']}");
            json_response(['ok' => true, 'message' => 'Activo eliminado']);
            break;

        default:
            json_response(['ok' => false, 'error' => 'Método no permitido'], 405);
    }

} catch (Throwable $e) {
    logger("Error en activos: " . $e->getMessage(), 'ERROR');
    json_response(['ok' => false, 'error' => 'Error: ' . $e->getMessage()], 500);
}

/* ============================================================
 * FUNCIONES AUXILIARES
 * ============================================================ */

function validarActivo(array $d, bool $esNuevo = true)
{
    $desc = trim((string)($d['descripcion'] ?? ''));
    $ni = trim((string)($d['num_inventario'] ?? ''));
    $costo = $d['costo'] ?? null;
    $estado = $d['estado_fisico'] ?? null;

    if (strlen($desc) < 2 || strlen($desc) > 200) return 'La descripción debe tener de 2 a 200 caracteres';
    if ($ni === '' || strlen($ni) > 50) return 'El número de inventario debe tener hasta 50 caracteres';
    if ($esNuevo && ($costo === null || $costo === '')) return 'El costo es obligatorio para activos nuevos';
    if ($costo !== null && $costo !== '') {
        if (!is_numeric($costo)) return 'El costo debe ser numérico';
        if ((float)$costo < 0 || (float)$costo > 9999999999.99) return 'El costo está fuera del rango permitido';
    }
    // Los valores NULL importados desde Excel se conservan al editar.
    if ($esNuevo && ($estado === null || $estado === '')) return 'Selecciona el estado físico';
    if ($estado !== null && $estado !== '' &&
        !in_array($estado, ['BUENO', 'NUEVO', 'REGULAR', 'INSERVIBLE'], true)) {
        return 'Estado físico no permitido';
    }
    if (!empty($d['fecha_adq'])) {
        $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$d['fecha_adq']);
        if (!$fecha || $fecha->format('Y-m-d') !== $d['fecha_adq']) return 'Fecha de adquisición inválida';
        if ($fecha > new DateTimeImmutable('today')) return 'La fecha de adquisición no puede ser futura';
    }
    if (!empty($d['empleado_id']) && !filter_var($d['empleado_id'], FILTER_VALIDATE_INT)) {
        return 'El empleado seleccionado no es válido';
    }
    return true;
}

function normalizarEmpleadoId($valor): ?int
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

function obtenerEmpleadoIdDeUsuario(PDO $pdo, int $usuarioId): ?int
{
    $sql = "SELECT e.id
            FROM empleados e
            JOIN usuarios u
              ON u.nombre_usuario = CAST(e.numero_nomina AS VARCHAR)
            WHERE u.id = :uid
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => $usuarioId]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}
/** Obtiene el área actual del empleado; el ID de empleado es la relación estable. */
function obtenerAreaDelEmpleado(PDO $pdo, int $empleadoId): ?int
{
    $st = $pdo->prepare('SELECT area_id FROM empleados WHERE id = :id');
    $st->execute([':id' => $empleadoId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return (!$r || $r['area_id'] === null) ? null : (int)$r['area_id'];
}
