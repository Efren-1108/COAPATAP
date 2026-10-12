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

// ============================================================
// CONSTANTES DE RATE LIMITING
// (definidas al inicio para que estén disponibles siempre)
// ============================================================
if (!defined('RATE_LIMIT_MAX_INTENTOS')) {
    define('RATE_LIMIT_MAX_INTENTOS', 5);
}
if (!defined('RATE_LIMIT_VENTANA_SEG')) {
    define('RATE_LIMIT_VENTANA_SEG', 300); // 5 minutos
}

$method = $_SERVER['REQUEST_METHOD'];
$input  = json_input();

try {
    $pdo = db();

    switch ($method) {
        case 'GET': {
            $u = require_auth();
            json_response([
                'ok'         => true,
                'data'       => sanitize_user($u),
                'csrf_token' => csrf_token(),
            ]);
            break;
        }

        case 'POST': {
            $accion = $input['accion'] ?? 'login';

            // ------------------------------------------------------------
            // LOGOUT
            // ------------------------------------------------------------
            if ($accion === 'logout') {
                if (current_user() !== null) {
                    csrf_require();
                    $u = current_user();
                    logger("auth.logout usuario={$u['nombre_usuario']} id={$u['id']}");
                }
                logout_and_destroy();
                json_response(['ok' => true, 'message' => 'Sesión cerrada']);
            }

            // ------------------------------------------------------------
            // CHANGE PASSWORD
            // ------------------------------------------------------------
            if ($accion === 'change_password') {
                $u = require_auth_csrf();

                $actual    = (string)($input['actual']    ?? '');
                $nueva     = (string)($input['nueva']     ?? '');
                $confirmar = (string)($input['confirmar'] ?? '');

                if (strlen($nueva) < 8) {
                    json_response(['ok' => false, 'error' => 'La nueva contraseña debe tener al menos 8 caracteres'], 400);
                }
                if ($nueva === $actual) {
                    json_response(['ok' => false, 'error' => 'La nueva contraseña debe ser distinta de la actual'], 400);
                }
                if ($nueva !== $confirmar) {
                    json_response(['ok' => false, 'error' => 'La confirmación no coincide con la nueva contraseña'], 400);
                }

                $stmt = $pdo->prepare("SELECT password_hash FROM usuarios WHERE id = :id");
                $stmt->execute([':id' => $u['id']]);
                $hashActual = $stmt->fetchColumn();
                if (!$hashActual || !password_verify($actual, $hashActual)) {
                    json_response(['ok' => false, 'error' => 'La contraseña actual es incorrecta'], 400);
                }

                $nuevoHash = password_hash($nueva, PASSWORD_DEFAULT);
                $upd = $pdo->prepare("UPDATE usuarios SET password_hash = :h WHERE id = :id");
                $upd->execute([':h' => $nuevoHash, ':id' => $u['id']]);

                $log = $pdo->prepare("INSERT INTO log_actividades
                                        (tabla, accion, registro_id, detalle, usuario, usuario_id, area_id)
                                      VALUES
                                        ('usuarios', 'UPDATE', :id, :det, :usr, :uid, :aid)");
                $log->execute([
                    ':id'  => $u['id'],
                    ':det' => "Cambio de contraseña propio",
                    ':usr' => $u['nombre_usuario'],
                    ':uid' => $u['id'],
                    ':aid' => $u['area_id'],
                ]);

                logger("auth.change_password usuario_id={$u['id']}");
                $newToken = csrf_rotate();

                json_response([
                    'ok'         => true,
                    'message'    => 'Contraseña actualizada',
                    'csrf_token' => $newToken,
                ]);
            }

            // ------------------------------------------------------------
            // LOGIN
            // ------------------------------------------------------------
            $usuario  = trim((string)($input['usuario']  ?? ''));
            $password = (string)($input['password'] ?? '');

            if ($usuario === '' || $password === '') {
                json_response(['ok' => false, 'error' => 'Usuario y contraseña son obligatorios'], 400);
            }

            // Rate limiting
            if (!rate_limit_login_ok($usuario)) {
                json_response([
                    'ok'    => false,
                    'error' => 'Demasiados intentos fallidos. Espera unos minutos e intenta de nuevo.',
                ], 429);
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
                usleep(300000);
                rate_limit_login_fail($usuario);
                json_response(['ok' => false, 'error' => 'Usuario o contraseña incorrectos'], 401);
            }
            if (!$row['estado']) {
                json_response(['ok' => false, 'error' => 'El usuario está inactivo. Contacta al administrador.'], 403);
            }
            if (!password_verify($password, $row['password_hash'])) {
                usleep(300000);
                rate_limit_login_fail($usuario);
                json_response(['ok' => false, 'error' => 'Usuario o contraseña incorrectos'], 401);
            }

            rate_limit_login_reset($usuario);

            session_regenerate_id(true);
            $_SESSION['_loaded_at'] = microtime(true); // invalida cache
            $_SESSION['user_id'] = (int)$row['id'];

            $csrfToken = csrf_rotate();

            $upd = $pdo->prepare("UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = :id");
            $upd->execute([':id' => $row['id']]);

            $row['es_superusuario'] = ($row['rol_clave'] === 'superusuario');
            logger("auth.login usuario={$usuario} rol={$row['rol_clave']}");

            json_response([
                'ok'         => true,
                'message'    => 'Bienvenido ' . $row['nombre_completo'],
                'data'       => sanitize_user($row),
                'csrf_token' => $csrfToken,
            ]);
        }

        default:
            json_response(['ok' => false, 'error' => 'Método no permitido'], 405);
    }
} catch (Throwable $e) {
    logger("auth error: " . $e->getMessage(), 'ERROR');
    json_response(['ok' => false, 'error' => 'Error: ' . $e->getMessage()], 500);
}

/* ============================================================
 * FUNCIONES AUXILIARES
 * ============================================================ */

function sanitize_user(array $u): array
{
    unset($u['password_hash']);
    $u['id']     = (int)$u['id'];
    $u['rol_id'] = (int)$u['rol_id'];
    $u['area_id']= $u['area_id'] !== null ? (int)$u['area_id'] : null;
    $u['estado'] = (bool)$u['estado'];
    if (array_key_exists('numero_nomina', $u) && $u['numero_nomina'] !== null) {
        $u['numero_nomina'] = (string)$u['numero_nomina'];
    }
    return $u;
}

/* ============================================================
 * RATE LIMITING
 * ============================================================ */

function rate_limit_key(string $usuario): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return 'rl_login_' . md5(strtolower($usuario) . '|' . $ip);
}

function rate_limit_login_ok(string $usuario): bool
{
    $key = rate_limit_key($usuario);
    $now = time();
    $intentos = $_SESSION[$key] ?? [];
    $intentos = array_values(array_filter($intentos, fn($t) => ($now - $t) < RATE_LIMIT_VENTANA_SEG));
    $_SESSION[$key] = $intentos;
    return count($intentos) < RATE_LIMIT_MAX_INTENTOS;
}

function rate_limit_login_fail(string $usuario): void
{
    $key = rate_limit_key($usuario);
    $intentos = $_SESSION[$key] ?? [];
    $intentos[] = time();
    $_SESSION[$key] = $intentos;
}

function rate_limit_login_reset(string $usuario): void
{
    $key = rate_limit_key($usuario);
    unset($_SESSION[$key]);
}