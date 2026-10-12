<?php
/**
 * API: Gestión de usuarios
 * Acceso: solo superusuario (GET lista, CRUD, reset_password)
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$input  = json_input();
$accion = $input['accion'] ?? null;
$q      = isset($_GET['q']) ? trim((string)$_GET['q']) : null;

$current = require_super();
$esSuper = true;

try {
    $pdo = db();

    switch ($method) {
        case 'GET': {
            if ($id) {
                $stmt = $pdo->prepare("
                    SELECT u.id, u.nombre_completo, u.nombre_usuario, u.numero_nomina, u.correo,
                           u.area_id, u.rol_id, u.estado, u.ultimo_acceso, u.creado_en,
                           r.clave  AS rol_clave, r.nombre AS rol_nombre,
                           a.clave  AS area_clave, a.nombre AS area_nombre
                    FROM usuarios u
                    JOIN roles r ON r.id = u.rol_id
                    LEFT JOIN areas a ON a.id = u.area_id
                    WHERE u.id = :id
                ");
                $stmt->execute([':id' => $id]);
                $row = $stmt->fetch();
                if (!$row) json_response(['ok' => false, 'error' => 'Usuario no encontrado'], 404);
                json_response(['ok' => true, 'data' => $row]);
            }

            $sql = "
                SELECT u.id, u.nombre_completo, u.nombre_usuario, u.numero_nomina, u.correo,
                       u.area_id, u.rol_id, u.estado, u.ultimo_acceso, u.creado_en,
                       r.clave  AS rol_clave, r.nombre AS rol_nombre,
                       a.clave  AS area_clave, a.nombre AS area_nombre
                FROM usuarios u
                JOIN roles r ON r.id = u.rol_id
                LEFT JOIN areas a ON a.id = u.area_id
            ";
            $params = [];
            if ($q) {
                $sql .= " WHERE LOWER(u.nombre_completo) LIKE :q OR LOWER(u.nombre_usuario) LIKE :q OR LOWER(COALESCE(u.correo,'')) LIKE :q OR u.numero_nomina LIKE :q";
                $params[':q'] = '%' . strtolower($q) . '%';
            }
            $sql .= " ORDER BY u.estado DESC, u.nombre_completo ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            json_response(['ok' => true, 'data' => $stmt->fetchAll()]);
            break;
        }

        case 'POST': {
            if ($accion === 'reset_password') {
                if (!$id) json_response(['ok' => false, 'error' => 'ID requerido'], 400);
                $pwd = (string)($input['password'] ?? '');
                if (strlen($pwd) < 8) json_response(['ok' => false, 'error' => 'La contraseña debe tener al menos 8 caracteres'], 400);
                if ((int)$id === (int)$current['id']) json_response(['ok' => false, 'error' => 'Usa Mi Perfil para cambiar tu clave'], 400);
                $hash = password_hash($pwd, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE usuarios SET password_hash = :h WHERE id = :id");
                $stmt->execute([':h' => $hash, ':id' => $id]);
                json_response(['ok' => true, 'message' => 'Contraseña restablecida.']);
                break;
            }

            $nombre  = trim((string)($input['nombre_completo'] ?? ''));
            $user    = trim((string)($input['nombre_usuario']  ?? ''));
            $nomina  = trim((string)($input['numero_nomina']  ?? ''));
            $correo  = trim((string)($input['correo'] ?? ''));
            $pwd     = (string)($input['password'] ?? '');
            $areaId  = isset($input['area_id']) && $input['area_id'] !== '' ? (int)$input['area_id'] : null;
            $rolId   = isset($input['rol_id'])  ? (int)$input['rol_id'] : 0;
            $estado  = !empty($input['estado']);

            if ($nombre === '' || strlen($nombre) < 3) json_response(['ok' => false, 'error' => 'Nombre obligatorio'], 400);
            if ($user === '' || strlen($user) < 3) json_response(['ok' => false, 'error' => 'Usuario obligatorio'], 400);

            $rolCheck = $pdo->prepare("SELECT clave FROM roles WHERE id = ?");
            $rolCheck->execute([$rolId]);
            $rolClave = $rolCheck->fetchColumn();

            if ($rolClave !== 'superusuario' && $nomina === '') {
                json_response(['ok' => false, 'error' => 'La nómina es obligatoria para usuarios normales'], 400);
            }
            if (strlen($pwd) < 8) json_response(['ok' => false, 'error' => 'Contraseña muy corta'], 400);

            $hash = password_hash($pwd, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO usuarios (nombre_completo, nombre_usuario, numero_nomina, correo, password_hash, area_id, rol_id, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id");
            $stmt->execute([$nombre, $user, $nomina, $correo, $hash, $areaId, $rolId, $estado]);
            $newId = $stmt->fetchColumn();

            json_response(['ok' => true, 'id' => $newId, 'message' => 'Usuario creado']);
            break;
        }

        case 'PUT': {
            if (!$id) json_response(['ok' => false, 'error' => 'ID requerido'], 400);
            $nombre  = trim((string)($input['nombre_completo'] ?? ''));
            $nomina  = trim((string)($input['numero_nomina'] ?? ''));
            $correo  = trim((string)($input['correo'] ?? ''));
            $areaId  = isset($input['area_id']) && $input['area_id'] !== '' ? (int)$input['area_id'] : null;
            $rolId   = isset($input['rol_id'])  ? (int)$input['rol_id'] : 0;
            $estado  = !empty($input['estado']);

            $stmt = $pdo->prepare("UPDATE usuarios SET nombre_completo=?, numero_nomina=?, correo=?, area_id=?, rol_id=?, estado=? WHERE id=?");
            $stmt->execute([$nombre, $nomina, $correo, $areaId, $rolId, $estado, $id]);
            json_response(['ok' => true, 'message' => 'Usuario actualizado']);
            break;
        }

        case 'DELETE': {
            if (!$id) json_response(['ok' => false, 'error' => 'ID requerido'], 400);
            $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
            $stmt->execute([$id]);
            json_response(['ok' => true, 'message' => 'Usuario eliminado']);
            break;
        }

        default:
            json_response(['ok' => false, 'error' => 'Método no permitido'], 405);
    }
} catch (Throwable $e) {
    logger("usuarios error: " . $e->getMessage(), 'ERROR');
    json_response(['ok' => false, 'error' => 'Error: ' . $e->getMessage()], 500);
}