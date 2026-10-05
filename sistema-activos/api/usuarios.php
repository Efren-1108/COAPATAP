<?php
/**
 * API: Gestión de usuarios
 * Acceso: solo superusuario (GET lista, CRUD, reset_password)
 *        usuario normal: solo puede ver/modificar su PROPIO registro
 *        vía POST { accion: 'change_password' } (manejado en api/auth.php).
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$input  = json_input();
$accion = $input['accion'] ?? null;
$q      = isset($_GET['q']) ? trim((string)$_GET['q']) : null;

$current = require_super();     // bloquea no-supers al instante
$esSuper = true;

try {
    $pdo = db();

    switch ($method) {
        case 'GET': {
            if ($id) {
                $stmt = $pdo->prepare("
                    SELECT u.id, u.nombre_completo, u.nombre_usuario, u.correo,
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
                SELECT u.id, u.nombre_completo, u.nombre_usuario, u.correo,
                       u.area_id, u.rol_id, u.estado, u.ultimo_acceso, u.creado_en,
                       r.clave  AS rol_clave, r.nombre AS rol_nombre,
                       a.clave  AS area_clave, a.nombre AS area_nombre
                FROM usuarios u
                JOIN roles r ON r.id = u.rol_id
                LEFT JOIN areas a ON a.id = u.area_id
            ";
            $params = [];
            if ($q) {
                $sql .= " WHERE LOWER(u.nombre_completo) LIKE :q OR LOWER(u.nombre_usuario) LIKE :q OR LOWER(COALESCE(u.correo,'')) LIKE :q";
                $params[':q'] = '%' . strtolower($q) . '%';
            }
            $sql .= " ORDER BY u.estado DESC, u.nombre_completo ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            json_response(['ok' => true, 'data' => $stmt->fetchAll()]);
            break;
        }

        case 'POST': {
            // Reset de contraseña: solo super
            if ($accion === 'reset_password') {
                if (!$id) json_response(['ok' => false, 'error' => 'ID requerido'], 400);
                $pwd = (string)($input['password'] ?? '');
                if (strlen($pwd) < 8) {
                    json_response(['ok' => false, 'error' => 'La contraseña debe tener al menos 8 caracteres'], 400);
                }
                // No resetearse a sí mismo si fuera único super (cubre la regla)
                if ((int)$id === (int)$current['id']) {
                    json_response(['ok' => false, 'error' => 'No puedes resetear tu propia contraseña desde aquí. Usa Mi Perfil.'], 400);
                }
                $hash = password_hash($pwd, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE usuarios SET password_hash = :h WHERE id = :id");
                $stmt->execute([':h' => $hash, ':id' => $id]);
                logger("usuarios.reset_password id={$id}");
                json_response(['ok' => true, 'message' => 'Contraseña restablecida. Comunica la nueva clave al usuario.']);
                break;
            }

            // Crear usuario
            $nombre  = trim((string)($input['nombre_completo'] ?? ''));
            $user    = trim((string)($input['nombre_usuario']  ?? ''));
            $correo  = trim((string)($input['correo'] ?? ''));
            $pwd     = (string)($input['password'] ?? '');
            $areaId  = isset($input['area_id']) && $input['area_id'] !== '' ? (int)$input['area_id'] : null;
            $rolId   = isset($input['rol_id'])  ? (int)$input['rol_id'] : 0;
            $estado  = !empty($input['estado']);

            // Validaciones
            if ($nombre === '' || strlen($nombre) < 3) {
                json_response(['ok' => false, 'error' => 'El nombre completo es obligatorio'], 400);
            }
            if ($user === '' || strlen($user) < 3) {
                json_response(['ok' => false, 'error' => 'El nombre de usuario debe tener al menos 3 caracteres'], 400);
            }
            if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $user)) {
                json_response(['ok' => false, 'error' => 'El nombre de usuario solo puede contener letras, números, guion, guion bajo y punto'], 400);
            }
            if (strlen($pwd) < 8) {
                json_response(['ok' => false, 'error' => 'La contraseña debe tener al menos 8 caracteres'], 400);
            }
            if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                json_response(['ok' => false, 'error' => 'El correo no tiene un formato válido'], 400);
            }

            // Obtener clave del rol para validar reglas
            $rStmt = $pdo->prepare("SELECT clave FROM roles WHERE id = :id AND activo = TRUE");
            $rStmt->execute([':id' => $rolId]);
            $rolClave = $rStmt->fetchColumn();
            if (!$rolClave) {
                json_response(['ok' => false, 'error' => 'El rol seleccionado no existe o está inactivo'], 400);
            }

            // Regla 1: superusuario no debe tener área
            // Regla 2: usuario normal debe tener área
            if ($rolClave === 'superusuario') {
                $areaId = null;
            } else {
                if (!$areaId) {
                    json_response(['ok' => false, 'error' => 'Un usuario normal debe tener un área asignada'], 400);
                }
                $aStmt = $pdo->prepare("SELECT id FROM areas WHERE id = :id AND activo = TRUE");
                $aStmt->execute([':id' => $areaId]);
                if (!$aStmt->fetchColumn()) {
                    json_response(['ok' => false, 'error' => 'El área seleccionada no existe o está inactiva'], 400);
                }
            }

            // Regla 3: solo un superusuario activo
            if ($rolClave === 'superusuario' && $estado) {
                $cnt = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol_id = (SELECT id FROM roles WHERE clave='superusuario') AND estado = TRUE")->fetchColumn();
                if ($cnt > 0) {
                    json_response(['ok' => false, 'error' => 'Ya existe un superusuario activo. Desactívalo antes de crear otro.'], 409);
                }
            }

            // Username único
            $chk = $pdo->prepare("SELECT id FROM usuarios WHERE LOWER(nombre_usuario) = LOWER(:u)");
            $chk->execute([':u' => $user]);
            if ($chk->fetch()) {
                json_response(['ok' => false, 'error' => 'Ya existe un usuario con ese nombre de usuario'], 409);
            }

            $hash = password_hash($pwd, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                INSERT INTO usuarios
                  (nombre_completo, nombre_usuario, correo, password_hash,
                   area_id, rol_id, estado)
                VALUES
                  (:nom, :usr, :cor, :pwd, :aid, :rid, :est)
                RETURNING id
            ");
          $stmt = $pdo->prepare("
    INSERT INTO usuarios
      (nombre_completo, nombre_usuario, correo, password_hash,
       area_id, rol_id, estado)
    VALUES
      (:nom, :usr, :cor, :pwd, :aid, :rid, :est)
    RETURNING id
");

    $stmt->bindValue(':nom', $nombre, PDO::PARAM_STR);
    $stmt->bindValue(':usr', $user, PDO::PARAM_STR);

    if ($correo === '') {
    $stmt->bindValue(':cor', null, PDO::PARAM_NULL);
    } else {
    $stmt->bindValue(':cor', $correo, PDO::PARAM_STR);
    }

    $stmt->bindValue(':pwd', $hash, PDO::PARAM_STR);

    if ($areaId === null) {
    $stmt->bindValue(':aid', null, PDO::PARAM_NULL);
    } else {
    $stmt->bindValue(':aid', $areaId, PDO::PARAM_INT);
    }

    $stmt->bindValue(':rid', $rolId, PDO::PARAM_INT);

// IMPORTANTE
$stmt->bindValue(':est', $estado, PDO::PARAM_BOOL);

$stmt->execute();
            $newId = (int)$stmt->fetchColumn();
            logger("usuarios.creado id={$newId} usuario={$user}");
            json_response(['ok' => true, 'id' => $newId, 'message' => 'Usuario registrado correctamente'], 201);
            break;
        }

        case 'PUT': {
            if (!$id) json_response(['ok' => false, 'error' => 'ID requerido'], 400);

            // No permitir editar al único superusuario activo si se le va a desactivar
            $targetStmt = $pdo->prepare("
                SELECT u.*, r.clave AS rol_clave
                FROM usuarios u
                JOIN roles r ON r.id = u.rol_id
                WHERE u.id = :id
            ");
            $targetStmt->execute([':id' => $id]);
            $target = $targetStmt->fetch();
            if (!$target) json_response(['ok' => false, 'error' => 'Usuario no encontrado'], 404);

            $nombre  = trim((string)($input['nombre_completo'] ?? ''));
            $correo  = trim((string)($input['correo'] ?? ''));
            $pwd     = isset($input['password']) ? (string)$input['password'] : null;
            $areaId  = isset($input['area_id']) && $input['area_id'] !== '' ? (int)$input['area_id'] : null;
            $rolId   = isset($input['rol_id'])  ? (int)$input['rol_id'] : 0;
            $estado  = !empty($input['estado']);

            if ($nombre === '' || strlen($nombre) < 3) {
                json_response(['ok' => false, 'error' => 'El nombre completo es obligatorio'], 400);
            }
            if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                json_response(['ok' => false, 'error' => 'El correo no tiene un formato válido'], 400);
            }

            $rStmt = $pdo->prepare("SELECT clave FROM roles WHERE id = :id AND activo = TRUE");
            $rStmt->execute([':id' => $rolId]);
            $rolClave = $rStmt->fetchColumn();
            if (!$rolClave) {
                json_response(['ok' => false, 'error' => 'El rol seleccionado no existe o está inactivo'], 400);
            }

            if ($rolClave === 'superusuario') {
                $areaId = null;
            } else {
                if (!$areaId) {
                    json_response(['ok' => false, 'error' => 'Un usuario normal debe tener un área asignada'], 400);
                }
                $aStmt = $pdo->prepare("SELECT id FROM areas WHERE id = :id AND activo = TRUE");
                $aStmt->execute([':id' => $areaId]);
                if (!$aStmt->fetchColumn()) {
                    json_response(['ok' => false, 'error' => 'El área seleccionada no existe o está inactiva'], 400);
                }
            }

            // Regla único superusuario activo
            if ($rolClave === 'superusuario' && $estado) {
                $sqlCnt = "SELECT COUNT(*) FROM usuarios
                           WHERE rol_id = (SELECT id FROM roles WHERE clave='superusuario')
                             AND estado = TRUE AND id <> :id";
                $cStmt = $pdo->prepare($sqlCnt);
                $cStmt->execute([':id' => $id]);
                $cnt = (int)$cStmt->fetchColumn();
                if ($cnt > 0) {
                    json_response(['ok' => false, 'error' => 'Ya existe otro superusuario activo.'], 409);
                }
            }

            // Armar SQL
        $sql = "UPDATE usuarios 
        SET nombre_completo = :nom,
            correo = :cor,
            area_id = :aid,
            rol_id = :rid,
            estado = :est";

        if ($pwd !== null && $pwd !== '') {
        if (strlen($pwd) < 8) {
        json_response([
            'ok' => false,
            'error' => 'La contraseña debe tener al menos 8 caracteres'
        ], 400);
        }

        $sql .= ", password_hash = :pwd";
 }

$sql .= " WHERE id = :id";

$stmt = $pdo->prepare($sql);

// Datos normales
$stmt->bindValue(':nom', $nombre, PDO::PARAM_STR);

if ($correo === '') {
    $stmt->bindValue(':cor', null, PDO::PARAM_NULL);
} else {
    $stmt->bindValue(':cor', $correo, PDO::PARAM_STR);
}

// Área puede ser NULL
if ($areaId === null) {
    $stmt->bindValue(':aid', null, PDO::PARAM_NULL);
} else {
    $stmt->bindValue(':aid', $areaId, PDO::PARAM_INT);
}

$stmt->bindValue(':rid', $rolId, PDO::PARAM_INT);

// IMPORTANTE: PostgreSQL recibe TRUE/FALSE real
$stmt->bindValue(':est', $estado, PDO::PARAM_BOOL);

if ($pwd !== null && $pwd !== '') {
    $stmt->bindValue(
        ':pwd',
        password_hash($pwd, PASSWORD_DEFAULT),
        PDO::PARAM_STR
    );
}

$stmt->bindValue(':id', $id, PDO::PARAM_INT);

$stmt->execute();

logger("usuarios.actualizado id={$id}");

json_response([
    'ok' => true,
    'message' => 'Usuario actualizado correctamente'
]);
            logger("usuarios.actualizado id={$id}");
            json_response(['ok' => true, 'message' => 'Usuario actualizado correctamente']);
            break;
        }

        case 'DELETE':
            if (!$id) json_response(['ok' => false, 'error' => 'ID requerido'], 400);

            // No auto-eliminarse
            if ((int)$id === (int)$current['id']) {
                json_response(['ok' => false, 'error' => 'No puedes eliminar tu propio usuario.'], 400);
            }

            // No eliminar al único superusuario activo
            $tStmt = $pdo->prepare("
                SELECT r.clave AS rol_clave, u.estado
                FROM usuarios u JOIN roles r ON r.id = u.rol_id
                WHERE u.id = :id
            ");
            $tStmt->execute([':id' => $id]);
            $t = $tStmt->fetch();
            if (!$t) json_response(['ok' => false, 'error' => 'Usuario no encontrado'], 404);

            if ($t['rol_clave'] === 'superusuario') {
                $cStmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios
                    WHERE rol_id = (SELECT id FROM roles WHERE clave='superusuario') AND estado = TRUE");
                $cStmt->execute();
                if ((int)$cStmt->fetchColumn() <= 1) {
                    json_response(['ok' => false, 'error' => 'No puedes eliminar al único superusuario activo del sistema.'], 409);
                }
            }

            $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = :id");
            $stmt->execute([':id' => $id]);
            logger("usuarios.eliminado id={$id}");
            json_response(['ok' => true, 'message' => 'Usuario eliminado']);
            break;

        default:
            json_response(['ok' => false, 'error' => 'Método no permitido'], 405);
    }
} catch (Throwable $e) {
    logger("usuarios error: " . $e->getMessage(), 'ERROR');
    json_response(['ok' => false, 'error' => 'Error: ' . $e->getMessage()], 500);
}