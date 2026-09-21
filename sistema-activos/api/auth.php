<?php
/**
 * API: Autenticación
 * Métodos:
 *   GET                              → usuario actual (o 401)
 *   POST { usuario, password }       → login
 *   POST { accion: 'logout' }        → cerrar sesión
 *   POST { accion: 'change_password', actual, nueva, confirmar }
 *                                     → cambio de contraseña del usuario actual
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$input  = json_input();

try {
    $pdo = db();

    switch ($method) {
        case 'GET': {
            $u = require_auth();
            json_response(['ok' => true, 'data' => sanitize_user($u)]);
            break;
        }

        case 'POST': {
            $accion = $input['accion'] ?? 'login';

            if ($accion === 'logout') {
                logout_and_destroy();
                json_response(['ok' => true, 'message' => 'Sesión cerrada']);
                break;
            }

            if ($accion === 'change_password') {
                $u = require_auth();
                $actual     = (string)($input['actual']     ?? '');
                $nueva      = (string)($input['nueva']      ?? '');
                $confirmar  = (string)($input['confirmar']  ?? '');

                if (strlen($nueva) < 8) {
                    json_response(['ok' => false, 'error' => 'La nueva contraseña debe tener al menos 8 caracteres'], 400);
                }
                if ($nueva !== $confirmar) {
                    json_response(['ok' => false, 'error' => 'La confirmación no coincide con la nueva contraseña'], 400);
                }
                // Traer hash actual
                $stmt = $pdo->prepare("SELECT password_hash FROM usuarios WHERE id = :id");
                $stmt->execute([':id' => $u['id']]);
                $hashActual = $stmt->fetchColumn();
                if (!$hashActual || !password_verify($actual, $hashActual)) {
                    json_response(['ok' => false, 'error' => 'La contraseña actual es incorrecta'], 400);
                }
                $nuevoHash = password_hash($nueva, PASSWORD_DEFAULT);
                $upd = $pdo->prepare("UPDATE usuarios SET password_hash = :h WHERE id = :id");
                $upd->execute([':h' => $nuevoHash, ':id' => $u['id']]);
                logger("auth.change_password usuario_id={$u['id']}");
                json_response(['ok' => true, 'message' => 'Contraseña actualizada']);
                break;
            }

            // ---- LOGIN ----
            $usuario  = trim((string)($input['usuario']  ?? ''));
            $password = (string)($input['password'] ?? '');

            if ($usuario === '' || $password === '') {
                json_response(['ok' => false, 'error' => 'Usuario y contraseña son obligatorios'], 400);
            }

            $stmt = $pdo->prepare("
                SELECT u.*, r.clave AS rol_clave, r.nombre AS rol_nombre,
                       a.clave AS area_clave, a.nombre AS area_nombre
                FROM usuarios u
                JOIN roles r ON r.id = u.rol_id
                LEFT JOIN areas a ON a.id = u.area_id
                WHERE u.nombre_usuario = :u
                LIMIT 1
            ");
            $stmt->execute([':u' => $usuario]);
            $row = $stmt->fetch();

            if (!$row) {
                // Pequeño delay para mitigar timing attacks
                usleep(300000);
                json_response(['ok' => false, 'error' => 'Usuario o contraseña incorrectos'], 401);
            }
            if (!$row['estado']) {
                json_response(['ok' => false, 'error' => 'El usuario está inactivo. Contacta al administrador.'], 403);
            }
            if (!password_verify($password, $row['password_hash'])) {
                usleep(300000);
                json_response(['ok' => false, 'error' => 'Usuario o contraseña incorrectos'], 401);
            }

            // Login OK → regenerar id de sesión (anti session fixation)
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$row['id'];

            // Actualizar último acceso
            $upd = $pdo->prepare("UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = :id");
            $upd->execute([':id' => $row['id']]);

            $row['es_superusuario'] = ($row['rol_clave'] === 'superusuario');
            logger("auth.login usuario={$usuario} rol={$row['rol_clave']}");

            json_response([
                'ok'      => true,
                'message' => 'Bienvenido ' . $row['nombre_completo'],
                'data'    => sanitize_user($row),
            ]);
            break;
        }

        default:
            json_response(['ok' => false, 'error' => 'Método no permitido'], 405);
    }
} catch (Throwable $e) {
    logger("auth error: " . $e->getMessage(), 'ERROR');
    json_response(['ok' => false, 'error' => 'Error: ' . $e->getMessage()], 500);
}

/**
 * Quita campos sensibles antes de devolver el usuario al cliente.
 */
function sanitize_user(array $u): array
{
    unset($u['password_hash']);
    // Renombrar area_nombre a null si está vacía (superusuario)
    $u['area_id']    = $u['area_id']    !== null ? (int)$u['area_id']    : null;
    $u['rol_id']     = (int)$u['rol_id'];
    $u['id']         = (int)$u['id'];
    $u['estado']     = (bool)$u['estado'];
    return $u;
}