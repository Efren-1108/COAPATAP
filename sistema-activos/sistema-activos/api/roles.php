<?php
/** Gestión de roles y permisos por módulo. El Superusuario siempre conserva acceso total. */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
// Acciones independientes del permiso para consultar el módulo.
if ($method === 'POST') require_action_permission('roles', 'crear');
if ($method === 'PUT') require_action_permission('roles', 'modificar');
if ($method === 'DELETE') require_action_permission('roles', 'eliminar');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
// La gestión de Usuarios necesita leer el catálogo de roles, no modificarlo.
$user = $method === 'GET'
    ? require_any_permission(['roles', 'usuarios'])
    : require_permission_csrf('roles');

/** Solo módulos mostrados en la página de Roles; 'areas' es legado y está incluido en empleados. */
function catalogo_roles(PDO $pdo): array
{
    $claves = ['consulta', 'empleados', 'activos', 'stock', 'adscripciones', 'impresion', 'importar', 'usuarios', 'roles'];
    $rows = $pdo->query("SELECT id, clave, nombre, descripcion FROM modulos ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) $map[$r['clave']] = $r;
    $list = [];
    foreach ($claves as $clave) {
        if (!isset($map[$clave])) {
            throw new RuntimeException('Falta configurar el catálogo de permisos. Ejecuta la migración SQL incluida.');
        }
        $map[$clave]['acciones_disponibles'] = acciones_disponibles_roles($clave);
        $list[] = $map[$clave];
    }
    return $list;
}

function validar_modulos_roles(array $input, array $catalogo): array
{
    if (!isset($input['permisos']) || !is_array($input['permisos'])) {
        json_response(['ok' => false, 'error' => 'Selecciona los permisos de acceso del rol'], 400);
    }
    $validos = array_column($catalogo, 'clave');
    $elegidos = [];
    foreach ($input['permisos'] as $v) {
        if (!is_string($v) || !in_array($v, $validos, true)) {
            json_response(['ok' => false, 'error' => 'Se recibió un permiso no válido'], 400);
        }
        $elegidos[$v] = true;
    }
    return array_keys($elegidos);
}

/** Acciones de escritura permitidas en módulos que disponen de CRUD delegado. */
function acciones_disponibles_roles(string $clave): array
{
    return in_array($clave, ['empleados','activos','usuarios','roles'], true)
        ? ['crear','modificar','eliminar'] : [];
}

function validar_acciones_roles(array $data, array $modulos): array
{
    $raw = $data['acciones'] ?? [];
    if (!is_array($raw)) json_response(['ok'=>false,'error'=>'Selección de acciones inválida'],400);
    $out = [];
    foreach ($raw as $clave => $acciones) {
        if (!is_string($clave) || !in_array($clave,$modulos,true) || !is_array($acciones)) {
            json_response(['ok'=>false,'error'=>'No puedes dar acciones en un módulo sin acceso'],400);
        }
        foreach ($acciones as $accion) {
            if (!is_string($accion) || !in_array($accion,acciones_disponibles_roles($clave),true)) {
                json_response(['ok'=>false,'error'=>'Acción no disponible para ese módulo'],400);
            }
            $out[$clave][$accion] = true;
        }
    }
    return $out;
}

/** Valida y determina las áreas consultables, sin tocar los permisos de módulos. */
function validar_areas_roles(PDO $pdo, array $data, array $editor): array
{
    $modo = $data['alcance_areas'] ?? 'propia';
    if (!is_string($modo) || !in_array($modo, ['propia', 'seleccionadas'], true)) {
        json_response(['ok'=>false,'error'=>'Selecciona un alcance de áreas válido'],400);
    }
    $raw = $data['areas'] ?? [];
    if (!is_array($raw)) json_response(['ok'=>false,'error'=>'Selección de áreas inválida'],400);
    if ($modo === 'propia') return [$modo, []];
    $ids = [];
    foreach ($raw as $valor) {
        if (!(is_int($valor) || (is_string($valor) && ctype_digit($valor))) || (int)$valor < 1) {
            json_response(['ok'=>false,'error'=>'Área no válida'],400);
        }
        $ids[(int)$valor] = true;
    }
    $ids = array_keys($ids);
    if (!$ids) json_response(['ok'=>false,'error'=>'Selecciona por lo menos un área'],400);
    $st = $pdo->prepare('SELECT id FROM areas WHERE activo IS TRUE');
    $st->execute();
    $validas = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    foreach ($ids as $area) {
        if (!in_array($area, $validas, true)) {
            json_response(['ok'=>false,'error'=>'Una de las áreas está inactiva o no existe'],400);
        }
    }
    $permitidas = read_area_ids($editor);
    if ($permitidas !== null) {
        foreach ($ids as $area) {
            if (!in_array($area, $permitidas, true)) {
                json_response(['ok'=>false,'error'=>'No puedes conceder acceso a áreas fuera de tu propio alcance'],403);
            }
        }
    }
    return [$modo, $ids];
}

function guardar_areas_roles(PDO $pdo, int $rolId, string $modo, array $areas): void
{
    $pdo->prepare('UPDATE roles SET alcance_areas = :modo WHERE id = :id')
        ->execute([':modo' => $modo, ':id' => $rolId]);
    $pdo->prepare('DELETE FROM rol_areas WHERE rol_id = :id')->execute([':id' => $rolId]);
    if ($modo === 'seleccionadas') {
        $ins = $pdo->prepare('INSERT INTO rol_areas(rol_id, area_id) VALUES (:rol, :area)');
        foreach ($areas as $area) $ins->execute([':rol' => $rolId, ':area' => $area]);
    }
}

function guardar_permisos_roles(PDO $pdo, int $id, array $modulos, array $catalogo, array $acciones): void
{
    // Vaciar SOLO los permisos que gestiona este formulario; no altera otras integraciones.
    $ids = array_map('intval', array_column($catalogo, 'id'));
    // Usamos una lista parametrizada nativa porque PDO no expande arrays automáticamente.
    $places = implode(',', array_fill(0, count($ids), '?'));
    $del = $pdo->prepare("DELETE FROM rol_permisos WHERE rol_id = ? AND modulo_id IN ($places)");
    $del->execute(array_merge([$id], $ids));
    $byClave = [];
    foreach ($catalogo as $m) $byClave[$m['clave']] = (int)$m['id'];
    $ins = $pdo->prepare('INSERT INTO rol_permisos
        (rol_id, modulo_id, concedido, puede_crear, puede_modificar, puede_eliminar)
        VALUES (:rol, :mod, TRUE, :crear, :modificar, :eliminar)');
    foreach ($modulos as $clave) {
        $permitidas = $acciones[$clave] ?? [];
        $ins->execute([
            ':rol'=>$id, ':mod'=>$byClave[$clave],
            ':crear'=>!empty($permitidas['crear']) ? 't' : 'f',
            ':modificar'=>!empty($permitidas['modificar']) ? 't' : 'f',
            ':eliminar'=>!empty($permitidas['eliminar']) ? 't' : 'f'
        ]);
    }
    // Compatibilidad: el módulo antiguo "areas" se gestiona ahora junto a "empleados".
    $area = $pdo->query("SELECT id FROM modulos WHERE clave = 'areas'")->fetchColumn();
    if ($area) {
        $legacy = $pdo->prepare('INSERT INTO rol_permisos (rol_id, modulo_id, concedido) VALUES (:rol, :mod, :val) ON CONFLICT (rol_id, modulo_id) DO UPDATE SET concedido = EXCLUDED.concedido');
        $legacy->execute([':rol' => $id, ':mod' => (int)$area, ':val' => in_array('empleados', $modulos, true) ? 't' : 'f']);
    }
}

try {
    $pdo = db();
    $catalogo = catalogo_roles($pdo);
    switch ($method) {
        case 'GET':
            if (isset($_GET['catalogo_areas'])) {
                if (!has_permission('roles')) json_response(['ok'=>false,'error'=>'Sin permiso para administrar roles'],403);
                $scope = read_area_condition('a.id', $user);
                $areas = $pdo->query("SELECT a.id, a.nombre, ad.nombre AS adscripcion_nombre
                    FROM areas a LEFT JOIN adscripciones ad ON ad.id=a.adscripcion_id
                    WHERE a.activo IS TRUE AND " . $scope . "
                    ORDER BY ad.nombre ASC NULLS LAST,a.nombre ASC")
                    ->fetchAll(PDO::FETCH_ASSOC);
                json_response(['ok'=>true,'data'=>$areas]);
            }
            if (isset($_GET['catalogo'])) {
                json_response(['ok' => true, 'data' => $catalogo]);
            }
            if ($id > 0) {
                $st = $pdo->prepare('SELECT * FROM roles WHERE id = :id');
                $st->execute([':id' => $id]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if (!$row) json_response(['ok' => false, 'error' => 'Rol no encontrado'], 404);
                $cnt = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE rol_id = :id');
                $cnt->execute([':id' => $id]);
                $row['usuarios_count'] = (int)$cnt->fetchColumn();
                $per = $pdo->prepare('SELECT m.clave FROM rol_permisos rp JOIN modulos m ON rp.modulo_id = m.id WHERE rp.rol_id = :id AND rp.concedido IS TRUE');
                $per->execute([':id' => $id]);
                $row['permisos'] = $row['clave'] === 'superusuario'
                    ? array_column($catalogo, 'clave')
                    : $per->fetchAll(PDO::FETCH_COLUMN);
                $row['acciones'] = [];
                $stAcc=$pdo->prepare('SELECT m.clave, rp.puede_crear, rp.puede_modificar, rp.puede_eliminar
                    FROM public.rol_permisos rp JOIN public.modulos m ON m.id=rp.modulo_id
                    WHERE rp.rol_id=:rol AND rp.concedido IS TRUE');
                $stAcc->execute([':rol'=>$id]);
                foreach ($stAcc->fetchAll(PDO::FETCH_ASSOC) as $accionRow) {
                    $habilitadas = [];
                    foreach (acciones_disponibles_roles($accionRow['clave']) as $accion) {
                        if (in_array($accionRow['puede_'.$accion], [true,1,'1','t','true'],true)) $habilitadas[]=$accion;
                    }
                    $row['acciones'][$accionRow['clave']] = $habilitadas;
                }
                $stAreas = $pdo->prepare('SELECT area_id FROM rol_areas WHERE rol_id=:id ORDER BY area_id');
                $stAreas->execute([':id' => $id]);
                $row['areas'] = array_map('intval', $stAreas->fetchAll(PDO::FETCH_COLUMN));
                json_response(['ok' => true, 'data' => $row]);
            }
            $rows = $pdo->query('SELECT r.*, COUNT(u.id)::int AS usuarios_count FROM roles r LEFT JOIN usuarios u ON u.rol_id = r.id GROUP BY r.id ORDER BY r.activo DESC, r.nombre ASC')->fetchAll(PDO::FETCH_ASSOC);
            json_response(['ok' => true, 'data' => $rows]);

        case 'POST':
            $data = json_input();
            $clave = strtolower(trim((string)($data['clave'] ?? '')));
            $nombre = trim((string)($data['nombre'] ?? ''));
            $descripcion = trim((string)($data['descripcion'] ?? ''));
            $activo = !empty($data['activo']);
            if (!preg_match('/^[a-z0-9_-]{2,30}$/', $clave) || $clave === 'superusuario' || $clave === 'usuario') {
                json_response(['ok'=>false, 'error'=>'Clave inválida o reservada'], 400);
            }
            if (strlen($nombre) < 2 || strlen($nombre) > 100) {
                json_response(['ok'=>false, 'error'=>'Nombre inválido (2 a 100 caracteres)'], 400);
            }
            if (strlen($descripcion) > 500) json_response(['ok'=>false, 'error'=>'Descripción demasiado larga'],400);
            $seleccionados = validar_modulos_roles($data, $catalogo);
            $accionesSeleccionadas = validar_acciones_roles($data, $seleccionados);
            // Un administrador delegado no puede otorgar permisos que él no posee.
            if (empty($user['es_superusuario'])) {
                foreach ($seleccionados as $module) {
                    if (!has_permission($module)) json_response(['ok'=>false,'error'=>'No puedes conceder acceso al módulo '.$module],403);
                    foreach (array_keys($accionesSeleccionadas[$module] ?? []) as $action) {
                        if (!has_action_permission($module, $action)) {
                            json_response(['ok'=>false,'error'=>'No puedes conceder la acción '.$action.' en '.$module],403);
                        }
                    }
                }
            }
            [$modoAreas, $areasSeleccionadas] = validar_areas_roles($pdo, $data, $user);
            $st = $pdo->prepare('SELECT 1 FROM roles WHERE LOWER(clave) = :clave');
            $st->execute([':clave' => $clave]);
            if ($st->fetchColumn()) json_response(['ok'=>false,'error'=>'Ya existe un rol con esa clave'],409);
            $pdo->beginTransaction();
            $st = $pdo->prepare('INSERT INTO roles (clave, nombre, descripcion, activo) VALUES (:clave,:nombre,:descripcion,:activo) RETURNING id');
            $st->execute([':clave'=>$clave, ':nombre'=>$nombre, ':descripcion'=>$descripcion ?: null, ':activo'=>$activo ? 't':'f']);
            $nuevo = (int)$st->fetchColumn();
            guardar_permisos_roles($pdo, $nuevo, $seleccionados, $catalogo, $accionesSeleccionadas);
            guardar_areas_roles($pdo, $nuevo, $modoAreas, $areasSeleccionadas);
            $pdo->commit();
            logger("rol creado: id={$nuevo} clave={$clave}; permisos=" . implode(',', $seleccionados));
            json_response(['ok'=>true,'id'=>$nuevo,'message'=>'Rol registrado con sus permisos'],201);

        case 'PUT':
            if ($id < 1) json_response(['ok'=>false,'error'=>'ID requerido'],400);
            $st = $pdo->prepare('SELECT clave FROM roles WHERE id = :id');
            $st->execute([':id'=>$id]);
            $clave = $st->fetchColumn();
            if ($clave === false) json_response(['ok'=>false,'error'=>'Rol no encontrado'],404);
            if ($clave === 'superusuario') json_response(['ok'=>false,'error'=>'No se pueden modificar los permisos del Superusuario'],403);
            $data = json_input();
            $nombre = trim((string)($data['nombre'] ?? ''));
            $descripcion = trim((string)($data['descripcion'] ?? ''));
            $activo = !empty($data['activo']);
            if (strlen($nombre) < 2 || strlen($nombre) > 100) json_response(['ok'=>false,'error'=>'Nombre inválido'],400);
            if (strlen($descripcion)>500) json_response(['ok'=>false,'error'=>'Descripción demasiado larga'],400);
            $seleccionados = validar_modulos_roles($data, $catalogo);
            $accionesSeleccionadas = validar_acciones_roles($data, $seleccionados);
            // Un administrador delegado no puede otorgar permisos que él no posee.
            if (empty($user['es_superusuario'])) {
                foreach ($seleccionados as $module) {
                    if (!has_permission($module)) json_response(['ok'=>false,'error'=>'No puedes conceder acceso al módulo '.$module],403);
                    foreach (array_keys($accionesSeleccionadas[$module] ?? []) as $action) {
                        if (!has_action_permission($module, $action)) {
                            json_response(['ok'=>false,'error'=>'No puedes conceder la acción '.$action.' en '.$module],403);
                        }
                    }
                }
            }
            [$modoAreas, $areasSeleccionadas] = validar_areas_roles($pdo, $data, $user);
            $pdo->beginTransaction();
            $st=$pdo->prepare('UPDATE roles SET nombre=:nom, descripcion=:d, activo=:activo WHERE id=:id');
            $st->execute([':nom'=>$nombre,':d'=>$descripcion ?: null,':activo'=>$activo?'t':'f',':id'=>$id]);
            guardar_permisos_roles($pdo,$id,$seleccionados,$catalogo,$accionesSeleccionadas);
            guardar_areas_roles($pdo, $id, $modoAreas, $areasSeleccionadas);
            $pdo->commit();
            logger("rol actualizado: id={$id}; permisos=" . implode(',', $seleccionados));
            json_response(['ok'=>true,'message'=>'Rol y permisos actualizados correctamente']);

        case 'DELETE':
            if ($id < 1) json_response(['ok'=>false,'error'=>'ID requerido'],400);
            $st=$pdo->prepare('SELECT clave FROM roles WHERE id=:id');
            $st->execute([':id'=>$id]);
            $clave=$st->fetchColumn();
            if ($clave===false) json_response(['ok'=>false,'error'=>'Rol no encontrado'],404);
            if (in_array($clave,['superusuario','usuario'],true)) json_response(['ok'=>false,'error'=>'Este rol pertenece al sistema y no se puede eliminar'],409);
            $st=$pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE rol_id=:id');
            $st->execute([':id'=>$id]);
            if ((int)$st->fetchColumn()>0) json_response(['ok'=>false,'error'=>'El rol tiene usuarios asignados y no puede eliminarse'],409);
            $st=$pdo->prepare('DELETE FROM roles WHERE id=:id');
            $st->execute([':id'=>$id]);
            logger("rol eliminado: id={$id}");
            json_response(['ok'=>true,'message'=>'Rol eliminado']);

        default:
            json_response(['ok'=>false,'error'=>'Método no permitido'],405);
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    logger('roles error: ' . $e->getMessage(),'ERROR');
    json_response(['ok'=>false,'error'=>'No se pudo procesar el rol. ' . $e->getMessage()],500);
}
