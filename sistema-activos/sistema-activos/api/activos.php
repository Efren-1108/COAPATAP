<?php
/**
 * API: Gestión de activos fijos (bienes)
 * Métodos: GET, POST, PUT, DELETE
 * Query: ?id=123  ?empleado_id=5  ?q=texto
 *
 * Seguridad:
 *   - Exige sesión (require_auth).
 *   - Si NO es superusuario, los SELECT/UPDATE/DELETE filtran por su área.
 *   - Cada INSERT guarda area_id (área del autor) y usuario_id (autor).
 *
 * Cambios respecto a la versión anterior:
 *   - [FIX] GET por ID: $row ya no se usa antes de definirse.
 *   - [FIX] PUT: se agregó la palabra UPDATE al SQL (antes estaba incompleto).
 *   - [FIX] PUT: el log ahora usa accion='UPDATE' (antes '' violaba el CHECK).
 *   - [FIX] JOIN empleados<->usuarios: se castea numero_nomina a texto para
 *           que la comparación sea consistente con nombre_usuario (VARCHAR).
 *   - [MEJORA] Normalización de empleado_id: se acepta 0, '', null y se
 *           guarda NULL en BD cuando no aplica.
 *   - [MEJORA] Validación de duplicado de inventario unificada.
 *   - [MEJORA] Se evita borrar la imagen antigua si el PUT falla antes.
 *   - [MEJORA] Manejo de errores más claro y logging consistente.
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
        // ============================================================
        // GET — Listar / buscar / obtener por ID
        // ============================================================
        case 'GET':
            if ($id) {
                // ---- Obtener un activo por ID ----
                $sql = "SELECT a.*,
                               e.numero_nomina, e.nombre AS empleado_nombre, e.cargo,
                               ar.nombre AS area_nombre
                        FROM activos a
                        LEFT JOIN empleados e ON e.id = a.empleado_id
                        LEFT JOIN areas    ar ON ar.id = a.area_id
                        WHERE a.id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':id' => $id]);
                $row = $stmt->fetch();

                if (!$row) {
                    json_response(['ok' => false, 'error' => 'Activo no encontrado o sin acceso'], 404);
                }

                // Validar acceso: si no es super, solo puede ver activos asignados a él
                if (!$isSuper) {
                    $myEmpId = obtenerEmpleadoIdDeUsuario($pdo, (int)$current['id']);
                    if (!$myEmpId || (int)$row['empleado_id'] !== (int)$myEmpId) {
                        json_response(['ok' => false, 'error' => 'Activo no encontrado o sin acceso'], 404);
                    }
                }

                json_response(['ok' => true, 'data' => $row]);
            }

            // ---- Listar / buscar ----
            $sql = "SELECT a.*,
                           e.numero_nomina, e.nombre AS empleado_nombre, e.cargo,
                           ar.nombre AS area_nombre
                    FROM activos a
                    LEFT JOIN empleados e ON e.id = a.empleado_id
                    LEFT JOIN areas    ar ON ar.id = a.area_id";
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
                          OR LOWER(a.serie)          LIKE :q)";
                $params[':q'] = '%' . strtolower($q) . '%';
            }

            if (!$isSuper) {
                // Usuario normal: solo ve activos asignados a él mismo
                $myEmpId = obtenerEmpleadoIdDeUsuario($pdo, (int)$current['id']);
                if ($myEmpId) {
                    $where[] = "a.empleado_id = :my_eid";
                    $params[':my_eid'] = $myEmpId;
                } else {
                    $where[] = "1 = 0"; // No es empleado → no ve nada
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

        // ============================================================
        // POST — Crear activo
        // ============================================================
        case 'POST':
            $input = json_input();
            $r = validarActivo($input);
            if ($r !== true) {
                json_response(['ok' => false, 'error' => $r], 400);
            }

            // Verificar num_inventario único (global)
            $chk = $pdo->prepare("SELECT id FROM activos WHERE num_inventario = :ni");
            $chk->execute([':ni' => $input['num_inventario']]);
            if ($chk->fetch()) {
                json_response(['ok' => false, 'error' => 'Ya existe un activo con ese número de inventario'], 409);
            }

            // Área del nuevo activo: la del autor (o la enviada por el super)
            $newAreaId = $isSuper
                ? (isset($input['area_id']) && $input['area_id'] !== '' ? (int)$input['area_id'] : null)
                : $areaId;

            $empleadoId = normalizarEmpleadoId($input['empleado_id'] ?? null);

            $sql = "INSERT INTO activos (
                        descripcion, num_inventario, marca, modelo, serie, material,
                        fecha_adq, factura, costo, observaciones, empleado_id, ruta_imagen,
                        area_id, usuario_id
                    ) VALUES (
                        :desc, :ni, :mar, :mod, :ser, :mat,
                        :fec, :fac, :cos, :obs, :eid, :img,
                        :aid, :uid
                    ) RETURNING id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':desc' => $input['descripcion'],
                ':ni'   => $input['num_inventario'],
                ':mar'  => $input['marca']         ?: null,
                ':mod'  => $input['modelo']        ?: null,
                ':ser'  => $input['serie']         ?: null,
                ':mat'  => $input['material']      ?: null,
                ':fec'  => $input['fecha_adq']     ?: null,
                ':fac'  => $input['factura']       ?: null,
                ':cos'  => $input['costo'],
                ':obs'  => $input['observaciones'] ?: null,
                ':eid'  => $empleadoId,
                ':img'  => $input['ruta_imagen']   ?: null,
                ':aid'  => $newAreaId,
                ':uid'  => $current['id'],
            ]);
            $newId = (int)$stmt->fetchColumn();

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

        // ============================================================
        // PUT — Actualizar activo
        // ============================================================
        case 'PUT':
            if (!$id) {
                json_response(['ok' => false, 'error' => 'ID requerido'], 400);
            }
            $input = json_input();
            $r = validarActivo($input);
            if ($r !== true) {
                json_response(['ok' => false, 'error' => $r], 400);
            }

            // Permiso por área
            if (!$isSuper) {
                $own = $pdo->prepare("SELECT id FROM activos WHERE id = :id AND area_id = :aid");
                $own->execute([':id' => $id, ':aid' => $areaId]);
                if (!$own->fetch()) {
                    json_response(['ok' => false, 'error' => 'No tienes permisos para editar este activo (pertenece a otra área).'], 403);
                }
            }

            // Verificar que el activo exista
            $existe = $pdo->prepare("SELECT ruta_imagen FROM activos WHERE id = :id");
            $existe->execute([':id' => $id]);
            $oldImg = $existe->fetchColumn();
            if ($oldImg === false) {
                json_response(['ok' => false, 'error' => 'Activo no encontrado'], 404);
            }

            // Duplicado de inventario (excluyendo el mismo)
            $chk = $pdo->prepare("SELECT id FROM activos WHERE num_inventario = :ni AND id <> :id");
            $chk->execute([':ni' => $input['num_inventario'], ':id' => $id]);
            if ($chk->fetch()) {
                json_response(['ok' => false, 'error' => 'Otro activo ya tiene ese número de inventario'], 409);
            }

            $empleadoId = normalizarEmpleadoId($input['empleado_id'] ?? null);
            $nuevaImg   = $input['ruta_imagen'] ?? null;

            // ---- UPDATE (antes faltaba la palabra UPDATE) ----
            $sql = "UPDATE activos SET
                        descripcion   = :desc,
                        num_inventario= :ni,
                        marca         = :mar,
                        modelo        = :mod,
                        serie         = :ser,
                        material      = :mat,
                        fecha_adq     = :fec,
                        factura       = :fac,
                        costo         = :cos,
                        observaciones = :obs,
                        empleado_id   = :eid,
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
                ':fec'  => $input['fecha_adq']     ?: null,
                ':fac'  => $input['factura']       ?: null,
                ':cos'  => $input['costo'],
                ':obs'  => $input['observaciones'] ?: null,
                ':eid'  => $empleadoId,
                ':img'  => $nuevaImg               ?: null,
                ':id'   => $id,
            ]);

            // Borrar imagen antigua SOLO si el UPDATE tuvo éxito y hay nueva
            if ($oldImg && $nuevaImg && $oldImg !== $nuevaImg) {
                $fullPath = __DIR__ . '/../' . $oldImg;
                if (file_exists($fullPath)) {
                    @unlink($fullPath);
                }
            }

            // ---- Log: accion debe ser 'UPDATE' (antes '' violaba el CHECK) ----
            $log = $pdo->prepare("INSERT INTO log_actividades
                                    (tabla, accion, registro_id, detalle, usuario, usuario_id, area_id)
                                  VALUES
                                    ('activos', 'UPDATE', :id, :det, :usr, :uid, :aid)");
            $log->execute([
                ':id'  => $id,
                ':det' => "Activo actualizado: {$input['descripcion']} (inv {$input['num_inventario']})",
                ':usr' => $current['nombre_usuario'],
                ':uid' => $current['id'],
                ':aid' => $isSuper ? null : $areaId,
            ]);
            logger("Activo actualizado: id={$id} inv={$input['num_inventario']} por usuario_id={$current['id']}");
            json_response(['ok' => true, 'message' => 'Activo actualizado correctamente']);
            break;

        // ============================================================
        // DELETE — Eliminar activo
        // ============================================================
        case 'DELETE':
            if (!$id) {
                json_response(['ok' => false, 'error' => 'ID requerido'], 400);
            }

            // Permiso por área
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

/**
 * Valida los campos obligatorios de un activo.
 *
 * @return true|string  true si todo OK, mensaje de error si no.
 */
function validarActivo(array $d)
{
    $desc  = trim($d['descripcion']    ?? '');
    $ni    = trim($d['num_inventario'] ?? '');
    $costo = $d['costo'] ?? null;

    if (strlen($desc) < 2)                   return 'La descripción es obligatoria';
    if (strlen($ni)   < 1)                   return 'El número de inventario es obligatorio';
    if ($costo === null || $costo === '')    return 'El costo es obligatorio';
    if (!is_numeric($costo))                 return 'El costo debe ser numérico';
    if ((float)$costo < 0)                   return 'El costo no puede ser negativo';

    if (!empty($d['fecha_adq'])) {
        $ts = strtotime($d['fecha_adq']);
        if ($ts === false)                   return 'Fecha de adquisición inválida';
        if ($ts > time())                    return 'La fecha de adquisición no puede ser futura';
    }

    if (!empty($d['empleado_id'])) {
        if (!filter_var($d['empleado_id'], FILTER_VALIDATE_INT)) {
            return 'El empleado seleccionado no es válido';
        }
    }

    return true;
}

/**
 * Normaliza el valor de empleado_id:
 *  - Devuelve int > 0 si es válido.
 *  - Devuelve null si es 0, '', null o no numérico.
 */
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

/**
 * Devuelve el id de empleado asociado a un usuario del sistema.
 *
 * Nota: empleados.numero_nomina es INTEGER y usuarios.nombre_usuario es VARCHAR,
 * por lo que se castea numero_nomina a texto para que la comparación funcione
 * en PostgreSQL. Si en el futuro se unifican tipos, esta función puede
 * simplificarse.
 *
 * @return int|null  ID del empleado o null si no existe.
 */
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