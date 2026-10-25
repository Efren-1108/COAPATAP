
<?php
/**
 * API: Gestión de activos fijos - COAPATAP
 *
 * Funciones:
 * - GET: Consultar activos.
 * - POST: Crear activos.
 * - PUT: Actualizar activos.
 * - DELETE: Eliminar activos.
 *
 * Características:
 * - Permite números de inventario repetidos.
 * - Cada activo se identifica por su ID.
 * - Guarda el estado físico completo.
 * - Conserva las validaciones de acceso.
 * - Conserva el registro de actividades.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$method = $_SERVER['REQUEST_METHOD'];

$id = isset($_GET['id'])
    ? (int) $_GET['id']
    : null;

$empId = isset($_GET['empleado_id'])
    ? (int) $_GET['empleado_id']
    : null;

$q = isset($_GET['q'])
    ? trim($_GET['q'])
    : null;

$current = require_auth_csrf();

$isSuper = $current['es_superusuario'];

$areaId = $current['area_id'];

try {

    $pdo = db();

    switch ($method) {

        // =====================================
        // GET - CONSULTAR ACTIVOS
        // =====================================

        case 'GET':

            // CONSULTAR UN ACTIVO POR ID

            if ($id) {

                $sql = "
                    SELECT
                        a.*,
                        e.numero_nomina,
                        e.nombre AS empleado_nombre,
                        e.cargo,
                        ar.nombre AS area_nombre,
                        ad.id AS adscripcion_id,
                        ad.nombre AS adscripcion_nombre,
                        ad.clave AS adscripcion_clave

                    FROM activos a

                    LEFT JOIN empleados e
                        ON e.id = a.empleado_id

                    LEFT JOIN areas ar
                        ON ar.id = a.area_id

                    LEFT JOIN adscripciones ad
                        ON ad.id = ar.adscripcion_id

                    WHERE a.id = :id
                ";

                $stmt = $pdo->prepare($sql);

                $stmt->execute([
                    ':id' => $id
                ]);

                $row = $stmt->fetch();

                if (!$row) {

                    json_response([
                        'ok' => false,
                        'error' => 'Activo no encontrado o sin acceso'
                    ], 404);
                }

                if (!$isSuper) {

                    $myEmpId = obtenerEmpleadoIdDeUsuario(
                        $pdo,
                        (int) $current['id']
                    );

                    if (
                        !$myEmpId ||
                        (int) $row['empleado_id'] !==
                        (int) $myEmpId
                    ) {

                        json_response([
                            'ok' => false,
                            'error' => 'Activo no encontrado o sin acceso'
                        ], 404);
                    }
                }

                json_response([
                    'ok' => true,
                    'data' => $row
                ]);
            }

            // CONSULTAR LISTA DE ACTIVOS

            $sql = "
                SELECT
                    a.*,
                    e.numero_nomina,
                    e.nombre AS empleado_nombre,
                    e.cargo,
                    ar.nombre AS area_nombre,
                    ad.id AS adscripcion_id,
                    ad.nombre AS adscripcion_nombre,
                    ad.clave AS adscripcion_clave

                FROM activos a

                LEFT JOIN empleados e
                    ON e.id = a.empleado_id

                LEFT JOIN areas ar
                    ON ar.id = a.area_id

                LEFT JOIN adscripciones ad
                    ON ad.id = ar.adscripcion_id
            ";

            $params = [];

            $where = [];

            // FILTRAR POR EMPLEADO

            if ($empId) {

                $where[] = "a.empleado_id = :eid";

                $params[':eid'] = $empId;
            }

            // BÚSQUEDA DE ACTIVOS

            if ($q) {

                $where[] = "
                    (
                        LOWER(a.descripcion) LIKE :q

                        OR LOWER(a.num_inventario) LIKE :q

                        OR LOWER(a.marca) LIKE :q

                        OR LOWER(a.modelo) LIKE :q

                        OR LOWER(a.serie) LIKE :q

                        OR LOWER(
                            COALESCE(ad.nombre, '')
                        ) LIKE :q
                    )
                ";

                $params[':q'] =
                    '%' . strtolower($q) . '%';
            }

            // RESTRICCIONES DE USUARIOS NORMALES

            if (!$isSuper) {

                $myEmpId = obtenerEmpleadoIdDeUsuario(
                    $pdo,
                    (int) $current['id']
                );

                if ($myEmpId) {

                    $where[] =
                        "a.empleado_id = :my_eid";

                    $params[':my_eid'] = $myEmpId;

                } else {

                    $where[] = "1 = 0";
                }
            }

            if ($where) {

                $sql .= " WHERE "
                    . implode(' AND ', $where);
            }

            $sql .= "
                ORDER BY a.creado_en DESC, a.id DESC
            ";

            $stmt = $pdo->prepare($sql);

            $stmt->execute($params);

            json_response([
                'ok' => true,
                'data' => $stmt->fetchAll()
            ]);

            break;

        // =====================================
        // POST - CREAR NUEVO ACTIVO
        // =====================================

        case 'POST':

            $input = json_input();

            // VALIDAR INFORMACIÓN

            $r = validarActivo($input);

            if ($r !== true) {

                json_response([
                    'ok' => false,
                    'error' => $r
                ], 400);
            }

            // =================================
            // INVENTARIOS REPETIDOS PERMITIDOS
            //
            // No comprobamos si el número
            // de inventario ya existe.
            //
            // PostgreSQL identifica cada
            // activo mediante su ID.
            // =================================

            // DETERMINAR ÁREA

            $newAreaId = $isSuper

                ? (
                    isset($input['area_id']) &&
                    $input['area_id'] !== ''

                        ? (int) $input['area_id']

                        : null
                )

                : $areaId;

            // DETERMINAR EMPLEADO

            $empleadoId = normalizarEmpleadoId(
                $input['empleado_id'] ?? null
            );

            // INSERTAR ACTIVO

            $sql = "
                INSERT INTO activos (

                    descripcion,
                    num_inventario,
                    marca,
                    modelo,
                    serie,
                    material,
                    estado_fisico,
                    fecha_adq,
                    factura,
                    costo,
                    observaciones,
                    empleado_id,
                    ruta_imagen,
                    area_id,
                    usuario_id

                ) VALUES (

                    :desc,
                    :ni,
                    :mar,
                    :mod,
                    :ser,
                    :mat,
                    :estado,
                    :fec,
                    :fac,
                    :cos,
                    :obs,
                    :eid,
                    :img,
                    :aid,
                    :uid

                ) RETURNING id
            ";

            $stmt = $pdo->prepare($sql);

            $stmt->execute([

                ':desc' => $input['descripcion'],

                ':ni' => $input['num_inventario'],

                ':mar' => $input['marca'] ?: null,

                ':mod' => $input['modelo'] ?: null,

                ':ser' => $input['serie'] ?: null,

                ':mat' =>
                    ($input['material'] ?? '') ?: null,

                ':estado' => $input['estado_fisico'],

                ':fec' => $input['fecha_adq'] ?: null,

                ':fac' => $input['factura'] ?: null,

                ':cos' => $input['costo'],

                ':obs' => $input['observaciones'] ?: null,

                ':eid' => $empleadoId,

                ':img' => $input['ruta_imagen'] ?: null,

                ':aid' => $newAreaId,

                ':uid' => $current['id']

            ]);

            $newId = (int) $stmt->fetchColumn();

            // REGISTRAR ACTIVIDAD

            $log = $pdo->prepare(
                "INSERT INTO log_actividades (

                    tabla,
                    accion,
                    registro_id,
                    detalle,
                    usuario,
                    usuario_id,
                    area_id

                ) VALUES (

                    'activos',
                    'INSERT',
                    :id,
                    :det,
                    :usr,
                    :uid,
                    :aid

                )"
            );

            $log->execute([

                ':id' => $newId,

                ':det' =>
                    "Activo creado: "
                    . $input['descripcion']
                    . " (inv "
                    . $input['num_inventario']
                    . ")",

                ':usr' => $current['nombre_usuario'],

                ':uid' => $current['id'],

                ':aid' => $newAreaId

            ]);

            logger(
                "Activo creado: id={$newId} "
                . "inv={$input['num_inventario']} "
                . "por usuario_id={$current['id']}"
            );

            json_response([
                'ok' => true,
                'id' => $newId,
                'message' => 'Activo registrado correctamente'
            ], 201);

            break;

        // =====================================
        // PUT - ACTUALIZAR ACTIVO
        // =====================================

        case 'PUT':

            if (!$id) {

                json_response([
                    'ok' => false,
                    'error' => 'ID requerido'
                ], 400);
            }

            $input = json_input();

            // VALIDAR INFORMACIÓN

            $r = validarActivo($input);

            if ($r !== true) {

                json_response([
                    'ok' => false,
                    'error' => $r
                ], 400);
            }

            // VERIFICAR PERMISOS

            if (!$isSuper) {

                $own = $pdo->prepare(
                    "SELECT id
                     FROM activos
                     WHERE id = :id
                       AND area_id = :aid"
                );

                $own->execute([
                    ':id' => $id,
                    ':aid' => $areaId
                ]);

                if (!$own->fetch()) {

                    json_response([
                        'ok' => false,
                        'error' => 'No tienes permisos para editar este activo (pertenece a otra área).'
                    ], 403);
                }
            }

            // OBTENER IMAGEN ANTERIOR

            $existe = $pdo->prepare(
                "SELECT ruta_imagen
                 FROM activos
                 WHERE id = :id"
            );

            $existe->execute([
                ':id' => $id
            ]);

            $oldImg = $existe->fetchColumn();

            if ($oldImg === false) {

                json_response([
                    'ok' => false,
                    'error' => 'Activo no encontrado'
                ], 404);
            }

            // =================================
            // INVENTARIOS REPETIDOS PERMITIDOS
            //
            // Al editar tampoco se comprueba
            // si otro activo utiliza el mismo
            // número de inventario.
            //
            // Se actualiza únicamente
            // el activo con el ID recibido.
            // =================================

            $empleadoId = normalizarEmpleadoId(
                $input['empleado_id'] ?? null
            );

            $nuevaImg =
                $input['ruta_imagen'] ?? null;

            // ACTUALIZAR ACTIVO

            $sql = "
                UPDATE activos SET

                    descripcion = :desc,

                    num_inventario = :ni,

                    marca = :mar,

                    modelo = :mod,

                    serie = :ser,

                    material = :mat,

                    estado_fisico = :estado,

                    fecha_adq = :fec,

                    factura = :fac,

                    costo = :cos,

                    observaciones = :obs,

                    empleado_id = :eid,

                    ruta_imagen = :img

                WHERE id = :id
            ";

            $stmt = $pdo->prepare($sql);

            $stmt->execute([

                ':desc' => $input['descripcion'],

                ':ni' => $input['num_inventario'],

                ':mar' => $input['marca'] ?: null,

                ':mod' => $input['modelo'] ?: null,

                ':ser' => $input['serie'] ?: null,

                ':mat' =>
                    ($input['material'] ?? '') ?: null,

                ':estado' => $input['estado_fisico'],

                ':fec' => $input['fecha_adq'] ?: null,

                ':fac' => $input['factura'] ?: null,

                ':cos' => $input['costo'],

                ':obs' => $input['observaciones'] ?: null,

                ':eid' => $empleadoId,

                ':img' => $nuevaImg ?: null,

                ':id' => $id

            ]);

            // ELIMINAR IMAGEN ANTERIOR
            // CUANDO SE REEMPLAZA

            if (
                $oldImg &&
                $nuevaImg &&
                $oldImg !== $nuevaImg
            ) {

                $fullPath =
                    __DIR__ . '/../' . $oldImg;

                if (file_exists($fullPath)) {

                    @unlink($fullPath);
                }
            }

            // REGISTRAR ACTIVIDAD

            $log = $pdo->prepare(
                "INSERT INTO log_actividades (

                    tabla,
                    accion,
                    registro_id,
                    detalle,
                    usuario,
                    usuario_id,
                    area_id

                ) VALUES (

                    'activos',
                    'UPDATE',
                    :id,
                    :det,
                    :usr,
                    :uid,
                    :aid

                )"
            );

            $log->execute([

                ':id' => $id,

                ':det' =>
                    "Activo actualizado: "
                    . $input['descripcion']
                    . " (inv "
                    . $input['num_inventario']
                    . ")",

                ':usr' => $current['nombre_usuario'],

                ':uid' => $current['id'],

                ':aid' => $isSuper
                    ? null
                    : $areaId

            ]);

            logger(
                "Activo actualizado: id={$id} "
                . "inv={$input['num_inventario']} "
                . "por usuario_id={$current['id']}"
            );

            json_response([
                'ok' => true,
                'message' => 'Activo actualizado correctamente'
            ]);

            break;

        // =====================================
        // DELETE - ELIMINAR ACTIVO
        // =====================================

        case 'DELETE':

            if (!$id) {

                json_response([
                    'ok' => false,
                    'error' => 'ID requerido'
                ], 400);
            }

            // VERIFICAR PERMISOS

            if (!$isSuper) {

                $own = $pdo->prepare(
                    "SELECT id
                     FROM activos
                     WHERE id = :id
                       AND area_id = :aid"
                );

                $own->execute([
                    ':id' => $id,
                    ':aid' => $areaId
                ]);

                if (!$own->fetch()) {

                    json_response([
                        'ok' => false,
                        'error' => 'No tienes permisos para eliminar este activo.'
                    ], 403);
                }
            }

            // CONSULTAR ACTIVO

            $stmt = $pdo->prepare(
                "SELECT ruta_imagen, descripcion
                 FROM activos
                 WHERE id = :id"
            );

            $stmt->execute([
                ':id' => $id
            ]);

            $row = $stmt->fetch();

            if (!$row) {

                json_response([
                    'ok' => false,
                    'error' => 'Activo no encontrado'
                ], 404);
            }

            // ELIMINAR REGISTRO

            $del = $pdo->prepare(
                "DELETE FROM activos
                 WHERE id = :id"
            );

            $del->execute([
                ':id' => $id
            ]);

            // ELIMINAR IMAGEN DEL ACTIVO

            if (
                $row['ruta_imagen'] &&
                file_exists(
                    __DIR__ . '/../'
                    . $row['ruta_imagen']
                )
            ) {

                @unlink(
                    __DIR__ . '/../'
                    . $row['ruta_imagen']
                );
            }

            // REGISTRAR ACTIVIDAD

            $log = $pdo->prepare(
                "INSERT INTO log_actividades (

                    tabla,
                    accion,
                    registro_id,
                    detalle,
                    usuario,
                    usuario_id,
                    area_id

                ) VALUES (

                    'activos',
                    'DELETE',
                    :id,
                    :det,
                    :usr,
                    :uid,
                    :aid

                )"
            );

            $log->execute([

                ':id' => $id,

                ':det' =>
                    "Activo eliminado: "
                    . $row['descripcion'],

                ':usr' => $current['nombre_usuario'],

                ':uid' => $current['id'],

                ':aid' => $isSuper
                    ? null
                    : $areaId

            ]);

            logger(
                "Activo eliminado: id={$id} "
                . "por usuario_id={$current['id']}"
            );

            json_response([
                'ok' => true,
                'message' => 'Activo eliminado'
            ]);

            break;

        // =====================================
        // MÉTODO NO PERMITIDO
        // =====================================

        default:

            json_response([
                'ok' => false,
                'error' => 'Método no permitido'
            ], 405);
    }

} catch (Throwable $e) {

    logger(
        "Error en activos: " . $e->getMessage(),
        'ERROR'
    );

    json_response([
        'ok' => false,
        'error' => 'Error: ' . $e->getMessage()
    ], 500);
}

// ==========================================
// VALIDAR DATOS DEL ACTIVO
// ==========================================

function validarActivo(array $d)
{
    $desc = trim(
        $d['descripcion'] ?? ''
    );

    $ni = trim(
        $d['num_inventario'] ?? ''
    );

    $costo = $d['costo'] ?? null;

    // VALIDAR DESCRIPCIÓN

    if (strlen($desc) < 2) {

        return 'La descripción es obligatoria';
    }

    // VALIDAR NÚMERO DE INVENTARIO
    // Debe existir, pero puede repetirse.

    if (strlen($ni) < 1) {

        return 'El número de inventario es obligatorio';
    }

    // VALIDAR COSTO

    if ($costo === null || $costo === '') {

        return 'El costo es obligatorio';
    }

    if (!is_numeric($costo)) {

        return 'El costo debe ser numérico';
    }

    if ((float) $costo < 0) {

        return 'El costo no puede ser negativo';
    }

    // VALIDAR ESTADO FÍSICO

    $estadosPermitidos = [
        'BUENO',
        'NUEVO',
        'REGULAR',
        'INSERVIBLE'
    ];

    if (!in_array(
        $d['estado_fisico'] ?? null,
        $estadosPermitidos,
        true
    )) {

        return 'Selecciona un estado físico válido: '
            . 'BUENO, NUEVO, REGULAR o INSERVIBLE';
    }

    // VALIDAR FECHA DE ADQUISICIÓN

    if (!empty($d['fecha_adq'])) {

        $ts = strtotime(
            $d['fecha_adq']
        );

        if ($ts === false) {

            return 'Fecha de adquisición inválida';
        }

        if ($ts > time()) {

            return 'La fecha de adquisición no puede ser futura';
        }
    }

    // VALIDAR EMPLEADO

    if (!empty($d['empleado_id'])) {

        if (!filter_var(
            $d['empleado_id'],
            FILTER_VALIDATE_INT
        )) {

            return 'El empleado seleccionado no es válido';
        }
    }

    return true;
}

// ==========================================
// NORMALIZAR ID DEL EMPLEADO
// ==========================================

function normalizarEmpleadoId(mixed $valor): ?int
{
    if (
        $valor === null ||
        $valor === '' ||
        $valor === 0 ||
        $valor === '0'
    ) {

        return null;
    }

    if (!filter_var(
        $valor,
        FILTER_VALIDATE_INT
    )) {

        return null;
    }

    $n = (int) $valor;

    return $n > 0 ? $n : null;
}

// ==========================================
// OBTENER EMPLEADO VINCULADO AL USUARIO
// ==========================================

function obtenerEmpleadoIdDeUsuario(
    PDO $pdo,
    int $usuarioId
): ?int {

    $sql = "
        SELECT e.id

        FROM empleados e

        JOIN usuarios u
            ON u.nombre_usuario =
                CAST(e.numero_nomina AS VARCHAR)

        WHERE u.id = :uid

        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ':uid' => $usuarioId
    ]);

    $id = $stmt->fetchColumn();

    return $id ? (int) $id : null;
}
