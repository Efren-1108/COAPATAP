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
// Acciones independientes del permiso para consultar el módulo.
if ($method === 'POST') require_action_permission('activos', 'crear');
if ($method === 'PUT') require_action_permission('activos', 'modificar');
if ($method === 'DELETE') require_action_permission('activos', 'eliminar');

$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$empId  = isset($_GET['empleado_id']) ? (int)$_GET['empleado_id'] : null;
$q      = isset($_GET['q']) ? trim($_GET['q']) : null;

$current = require_permission_csrf('activos');
$isSuper = $current['es_superusuario'];

try {
    $pdo = db();

    switch ($method) {
        case 'GET':
            if ($id) {
                $sql = "SELECT a.*, di.ultimo_responsable_nombre, di.ultimo_area_nombre, di.motivo AS motivo_inservible, di.numero_diagnostico, di.declarado_en AS fecha_inservible,
                               e.numero_nomina, e.nombre AS empleado_nombre, e.cargo,
                               ar.nombre AS area_nombre,
                               ad.id     AS adscripcion_id,
                               ad.nombre AS adscripcion_nombre,
                               ad.clave  AS adscripcion_clave
                        FROM activos a
                        LEFT JOIN LATERAL (SELECT ultimo_responsable_nombre, ultimo_area_nombre, motivo, numero_diagnostico, declarado_en FROM public.declaraciones_inservible WHERE activo_id=a.id ORDER BY id DESC LIMIT 1) di ON true
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

                // El permiso de Activos permite consultar bienes del área del usuario,
                // no solamente los que estén asignados a su empleado.
                // Si la cuenta no tiene área, se deniega por seguridad.
                if (!can_read_area($row['area_id'] !== null ? (int)$row['area_id'] : null, $current)) {
                    json_response(['ok' => false, 'error' => 'Activo no encontrado o sin acceso'], 404);
                }

                $puedeOperarArea = can_write_area($row['area_id'] !== null ? (int)$row['area_id'] : null, $current);
                $row['puede_modificar'] = $puedeOperarArea && has_action_permission('activos', 'modificar');
                $row['puede_eliminar'] = $puedeOperarArea && has_action_permission('activos', 'eliminar');
                $row['puede_enviar_stock'] = $puedeOperarArea && has_action_permission('stock', 'modificar');
                json_response(['ok' => true, 'data' => $row]);
            }

            $sql = "SELECT a.*, di.ultimo_responsable_nombre, di.ultimo_area_nombre, di.motivo AS motivo_inservible, di.numero_diagnostico, di.declarado_en AS fecha_inservible,
                           e.numero_nomina, e.nombre AS empleado_nombre, e.cargo,
                           ar.nombre AS area_nombre,
                           ad.id     AS adscripcion_id,
                           ad.nombre AS adscripcion_nombre,
                           ad.clave  AS adscripcion_clave
                    FROM activos a
                    LEFT JOIN LATERAL (SELECT ultimo_responsable_nombre, ultimo_area_nombre, motivo, numero_diagnostico, declarado_en FROM public.declaraciones_inservible WHERE activo_id=a.id ORDER BY id DESC LIMIT 1) di ON true
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

            // Los roles con acceso a Activos ven TODOS los bienes de su área:
            // asignados a otro empleado, sin asignar e inservibles del área.
            // No se permite el acceso global a roles distintos de Superusuario.
            if (!$isSuper) {
                $where[] = read_area_condition('a.area_id', $current);
            }

            if ($where) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " ORDER BY a.creado_en DESC, a.id DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $filas = $stmt->fetchAll();
            foreach ($filas as &$fila) {
                $puedeOperarArea = can_write_area($fila['area_id'] !== null ? (int)$fila['area_id'] : null, $current);
                $fila['puede_modificar'] = $puedeOperarArea && has_action_permission('activos', 'modificar');
                $fila['puede_eliminar'] = $puedeOperarArea && has_action_permission('activos', 'eliminar');
                $fila['puede_enviar_stock'] = $puedeOperarArea && has_action_permission('stock', 'modificar');
            }
            unset($fila);
            json_response(['ok' => true, 'data' => $filas]);
            break;

        case 'POST':
            $input = json_input();
            $r = validarActivo($input, true);
            if ($r !== true) {
                json_response(['ok' => false, 'error' => $r], 400);
            }


            $inservible = ($input['estado_fisico'] ?? null) === 'INSERVIBLE';
            $motivoInservible = trim((string)($input['motivo_inservible'] ?? ''));
            if ($inservible && ($motivoInservible === '' || strlen($motivoInservible) > 1000)) json_response(['ok'=>false,'error'=>'Indica el motivo de inservibilidad (máximo 1000 caracteres).'],400);
            if ($inservible && !empty($input['crear_en_stock'])) json_response(['ok'=>false,'error'=>'Un bien inservible no puede registrarse como disponible en stock.'],400);
            $stockNuevo = !empty($input['crear_en_stock']);
            if ($stockNuevo && !has_action_permission('stock', 'modificar')) {
                json_response(['ok'=>false,'error'=>'Tu rol no tiene permiso para registrar bienes directamente en Stock.'],403);
            }
            if ($stockNuevo && !$isSuper && !read_area_ids($current)) {
                json_response(['ok'=>false,'error'=>'Tu rol necesita al menos un área autorizada para registrar bienes en Stock.'],403);
            }
            $pdo->beginTransaction();
            $empleadoId = $stockNuevo ? null : normalizarEmpleadoId($input['empleado_id'] ?? null);
            $newAreaId = isset($input['area_id']) && $input['area_id'] !== ''
                ? (int)$input['area_id'] : ($current['area_id'] !== null ? (int)$current['area_id'] : null);
            if ($empleadoId !== null) {
                // El área del activo debe coincidir con la de su responsable.
                $areaEmpleado = obtenerAreaDelEmpleado($pdo, $empleadoId);
                if ($areaEmpleado === null) {
                    json_response(['ok' => false, 'error' => 'Empleado no encontrado o sin área.'], 400);
                }
                if (!can_write_area((int)$areaEmpleado, $current)) {
                    json_response(['ok' => false, 'error' => 'No puedes asignar empleados de otra área.'], 403);
                }
                $newAreaId = $areaEmpleado;
            }
            if (!$stockNuevo && $newAreaId !== null) {
                $validarArea=$pdo->prepare('SELECT 1 FROM public.areas WHERE id=:id AND activo IS TRUE');
                $validarArea->execute([':id'=>$newAreaId]);
                if (!$validarArea->fetchColumn()) json_response(['ok'=>false,'error'=>'El área seleccionada no existe o está inactiva'],400);
            }
            if (!$stockNuevo && !$isSuper && ($newAreaId === null || !can_write_area((int)$newAreaId, $current))) {
                json_response(['ok'=>false,'error'=>'Selecciona un área autorizada para registrar el activo'],403);
            }

            $ultimoEmpleadoId = $inservible ? $empleadoId : null;
            if ($inservible) $empleadoId = null;
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
            if ($inservible) registrarInservible($pdo, $newId, $ultimoEmpleadoId, $newAreaId, $motivoInservible, $current);
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
            $pdo->commit();
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
                $own = $pdo->prepare('SELECT area_id FROM activos WHERE id=:id');
                $own->execute([':id'=>$id]);
                $idAreaActual = $own->fetchColumn();
                if ($idAreaActual === false || !can_write_area($idAreaActual !== null ? (int)$idAreaActual : null, $current)) {
                    json_response(['ok'=>false,'error'=>'No puedes modificar activos fuera de las áreas autorizadas'],403);
                }
            }

            $pdo->beginTransaction();
            $existe = $pdo->prepare("SELECT ruta_imagen, area_id, empleado_id, situacion_inventario, estado_fisico FROM activos WHERE id = :id FOR UPDATE");
            $existe->execute([':id' => $id]);
            $anterior = $existe->fetch(PDO::FETCH_ASSOC);
            if (!$anterior) {
                json_response(['ok' => false, 'error' => 'Activo no encontrado'], 404);
            }
            if (!can_write_area($anterior['area_id'] !== null ? (int)$anterior['area_id'] : null, $current)) {
                json_response(['ok'=>false,'error'=>'No puedes editar un activo fuera de tus áreas autorizadas'],403);
            }
            $oldImg = $anterior['ruta_imagen'];


            $inservible = ($input['estado_fisico'] ?? null) === 'INSERVIBLE';
            $declaracionNueva = $inservible && $anterior['estado_fisico'] !== 'INSERVIBLE';
            $motivoInservible = trim((string)($input['motivo_inservible'] ?? ''));
            // Solo puede cambiar el diagnostico de un activo que ya era INSERVIBLE.
            $actualizarNumeroDiagnostico = $inservible && $anterior['estado_fisico'] === 'INSERVIBLE'
                && array_key_exists('numero_diagnostico', $input);
            $numeroDiagnostico = $actualizarNumeroDiagnostico ? trim((string)($input['numero_diagnostico'] ?? '')) : '';
            if ($actualizarNumeroDiagnostico && preg_match_all('/./us', $numeroDiagnostico) > 100) {
                json_response(['ok'=>false,'error'=>'El número de diagnóstico no puede superar 100 caracteres.'],400);
            }
            if ($declaracionNueva && ($motivoInservible === '' || strlen($motivoInservible) > 1000)) json_response(['ok'=>false,'error'=>'Indica el motivo de inservibilidad (máximo 1000 caracteres).'],400);
            if ($inservible && $motivoInservible !== '' && strlen($motivoInservible) > 1000) json_response(['ok'=>false,'error'=>'El motivo de inservibilidad supera los 1000 caracteres.'],400);
            $empleadoId = normalizarEmpleadoId($input['empleado_id'] ?? null);
            if ($inservible && $anterior['situacion_inventario'] === 'STOCK') json_response(['ok'=>false,'error'=>'Primero retira el activo del stock antes de declararlo inservible.'],409);
            if ($anterior['situacion_inventario'] === 'STOCK' && $empleadoId !== null) {
                json_response(['ok'=>false,'error'=>'Para retirar un bien del stock utiliza Asignar desde Stock.'],409);
            }
            $nuevaImg   = $input['ruta_imagen'] ?? null;
            // Si el activo cambia de empleado, actualizar también su área.
            // Si queda No asignado, preservar el área que ya tenía.
            $nuevaAreaId = $anterior['area_id'];
            if ($anterior['situacion_inventario'] !== 'STOCK' && $empleadoId === null
                && isset($input['area_id']) && $input['area_id'] !== '') {
                $nuevaAreaId = (int)$input['area_id'];
                $validarArea=$pdo->prepare('SELECT 1 FROM public.areas WHERE id=:id AND activo IS TRUE');
                $validarArea->execute([':id'=>$nuevaAreaId]);
                if (!$validarArea->fetchColumn() || !can_write_area($nuevaAreaId, $current)) {
                    json_response(['ok'=>false,'error'=>'No puedes trasladar el activo a un área inactiva o no autorizada'],403);
                }
            }
            if ($empleadoId !== null) {
                $areaEmpleado = obtenerAreaDelEmpleado($pdo, $empleadoId);
                if ($areaEmpleado === null) {
                    json_response(['ok' => false, 'error' => 'Empleado no encontrado o sin área.'], 400);
                }
                if (!can_write_area((int)$areaEmpleado, $current)) {
                    json_response(['ok' => false, 'error' => 'No puedes asignar empleados de otra área.'], 403);
                }
                $nuevaAreaId = $areaEmpleado;
            }

            $ultimoEmpleadoId = $declaracionNueva ? $anterior['empleado_id'] : null;
            if ($inservible) $empleadoId = null;
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

            if ($declaracionNueva) {
                registrarInservible($pdo, $id, $ultimoEmpleadoId, $anterior['area_id'], $motivoInservible, $current);
            } elseif ($inservible && ($motivoInservible !== '' || $actualizarNumeroDiagnostico)) {
                // Actualizar unicamente los campos enviados, sin cambiar la fecha
                // ni los datos del ultimo responsable y su area.
                $camposDeclaracion = [];
                $paramsDeclaracion = [':activo_id' => $id];
                if ($motivoInservible !== '') {
                    $camposDeclaracion[] = 'motivo = :motivo';
                    $paramsDeclaracion[':motivo'] = $motivoInservible;
                }
                if ($actualizarNumeroDiagnostico) {
                    $camposDeclaracion[] = 'numero_diagnostico = :numero_diagnostico';
                    // Se permite borrar el numero dejandolo vacio.
                    $paramsDeclaracion[':numero_diagnostico'] = $numeroDiagnostico !== '' ? $numeroDiagnostico : null;
                }
                $actualizarDeclaracion = $pdo->prepare('UPDATE public.declaraciones_inservible
                    SET ' . implode(', ', $camposDeclaracion) . '
                    WHERE id = (
                        SELECT id FROM public.declaraciones_inservible
                        WHERE activo_id = :activo_id ORDER BY id DESC LIMIT 1
                    )');
                $actualizarDeclaracion->execute($paramsDeclaracion);

                // Los datos importados antiguos podrian no tener declaracion asociada.
                if ($actualizarDeclaracion->rowCount() === 0) {
                    if ($motivoInservible === '') {
                        throw new DomainException('Es necesario indicar un motivo para documentar este inservible antes de guardar el diagnóstico.');
                    }
                    registrarInservible($pdo, $id, null, $anterior['area_id'], $motivoInservible, $current,
                        $actualizarNumeroDiagnostico && $numeroDiagnostico !== '' ? $numeroDiagnostico : null);
                }
            }
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
            $pdo->commit();
            logger("Activo actualizado: id={$id} inv={$input['num_inventario']} por usuario_id={$current['id']}");
            json_response(['ok' => true, 'message' => 'Activo actualizado correctamente']);
            break;

        case 'DELETE':
            if (!$id) {
                json_response(['ok' => false, 'error' => 'ID requerido'], 400);
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT ruta_imagen, descripcion, area_id FROM activos WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();
            if (!$row) {
                json_response(['ok' => false, 'error' => 'Activo no encontrado'], 404);
            }
            if (!can_write_area($row['area_id'] !== null ? (int)$row['area_id'] : null, $current)) {
                json_response(['ok'=>false,'error'=>'No puedes eliminar activos fuera de tus áreas autorizadas'],403);
            }

            $estado = $pdo->prepare("SELECT estado_fisico FROM activos WHERE id=:id");
            $estado->execute([':id'=>$id]);
            if ($estado->fetchColumn() === 'INSERVIBLE') json_response(['ok'=>false,'error'=>'Los bienes inservibles se conservan para control patrimonial y no se pueden eliminar.'],409);
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
                ':aid' => $row['area_id'] ?? null,
            ]);
            $pdo->commit();
            logger("Activo eliminado: id={$id} por usuario_id={$current['id']}");
            json_response(['ok' => true, 'message' => 'Activo eliminado']);
            break;

        default:
            json_response(['ok' => false, 'error' => 'Método no permitido'], 405);
    }

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof DomainException) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 400);
    }
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

/** Guarda una instantánea independiente del empleado, para conservar el dato aunque se elimine. */
function registrarInservible(PDO $pdo, int $activoId, ?int $empleadoId, ?int $areaId, string $motivo, array $usuario, ?string $numeroDiagnostico = null): void {
    $st=$pdo->prepare("SELECT e.nombre, ar.nombre AS area_nombre FROM empleados e LEFT JOIN areas ar ON ar.id=e.area_id WHERE e.id=:id");
    $st->execute([':id'=>$empleadoId]);
    $e=$empleadoId ? $st->fetch(PDO::FETCH_ASSOC) : false;
    $areaNombre=null;
    if ($areaId) { $ar=$pdo->prepare('SELECT nombre FROM areas WHERE id=:id'); $ar->execute([':id'=>$areaId]); $areaNombre=$ar->fetchColumn() ?: null; }
    $st=$pdo->prepare("INSERT INTO public.declaraciones_inservible (activo_id,ultimo_responsable_id,ultimo_responsable_nombre,ultimo_area_nombre,motivo,numero_diagnostico,usuario_id,usuario_nombre) VALUES (:aid,:eid,:nombre,:area,:motivo,:diagnostico,:uid,:usr)");
    $st->execute([':aid'=>$activoId,':eid'=>$empleadoId,':nombre'=>$e['nombre']??'Sin responsable anterior',':area'=>$e['area_nombre']??$areaNombre,':motivo'=>$motivo,':diagnostico'=>$numeroDiagnostico,':uid'=>$usuario['id'],':usr'=>$usuario['nombre_usuario']]);
}
