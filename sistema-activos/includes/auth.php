<?php
/**
 * Helpers de autenticación y RBAC.
 *
 * Patrón de uso en páginas PHP:
 *   require_once __DIR__ . '/../config/config.php';
 *   require_once __DIR__ . '/../includes/auth.php';
 *   $user = require_auth();           // bloquea si no hay sesión
 *   $user = require_super();          // además exige rol superusuario
 *
 * Patrón de uso en APIs JSON:
 *   require_once __DIR__ . '/../config/config.php';
 *   require_once __DIR__ . '/../includes/auth.php';
 *   $user = require_auth();           // devuelve 401 JSON si no hay sesión
 *   $user = require_super();          // devuelve 403 JSON si no es super
 */

require_once __DIR__ . '/../config/config.php';

if (!function_exists('is_ajax_request')) {
    /**
     * Detecta si la petición espera JSON (API) o HTML (página).
     */
    function is_ajax_request(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $xrw    = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        $uri    = $_SERVER['REQUEST_URI'] ?? '';
        if (strcasecmp($xrw, 'XMLHttpRequest') === 0) return true;
        if (stripos($accept, 'application/json') !== false) return true;
        if (preg_match('#/api/#', $uri)) return true;
        return false;
    }
}

/**
 * Carga (o recarga desde BD) los datos del usuario actualmente logueado.
 * Devuelve null si no hay sesión o el usuario ya no existe / está inactivo.
 */
function load_current_user(int $userId): ?array
{
    static $cache = null;
    $cacheKey = $userId . ':' . ($_SESSION['_loaded_at'] ?? '');
    if ($cache !== null && $cacheKey === ($cache['__k'] ?? '')) {
        return $cache['data'];
    }

    try {
        $pdo = db();
        $stmt = $pdo->prepare("
            SELECT u.id,
                   u.nombre_completo,
                   u.nombre_usuario,
                   u.correo,
                   u.area_id,
                   u.rol_id,
                   u.estado,
                   u.ultimo_acceso,
                   u.creado_en,
                   r.clave  AS rol_clave,
                   r.nombre AS rol_nombre,
                   a.clave  AS area_clave,
                   a.nombre AS area_nombre
            FROM usuarios u
            JOIN roles r ON r.id = u.rol_id
            LEFT JOIN areas a ON a.id = u.area_id
            WHERE u.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        logger("auth.load_current_user error: " . $e->getMessage(), 'ERROR');
        $row = false;
    }

    if (!$row) {
        // Sesión huérfana: limpiarla.
        $_SESSION = [];
        return null;
    }
    if (!$row['estado']) {
        // Usuario inactivo: cerrarle la sesión.
        $_SESSION = [];
        return null;
    }

    $row['es_superusuario'] = ($row['rol_clave'] === 'superusuario');
    $cache = ['__k' => $cacheKey, 'data' => $row];
    return $row;
}

/**
 * Devuelve el usuario actual o null si no hay sesión.
 * NO redirige ni responde, solo lee.
 */
function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) return null;
    return load_current_user((int)$_SESSION['user_id']);
}

/**
 * Indica si el usuario actual es superusuario.
 */
function is_super(): bool
{
    $u = current_user();
    return $u !== null && $u['es_superusuario'];
}

/**
 * Exige sesión. Si no hay, devuelve 401 JSON (API) o redirige a login.php (página).
 * Devuelve siempre el array del usuario actual si la sesión es válida.
 */
function require_auth(): array
{
    $u = current_user();
    if ($u === null) {
        if (is_ajax_request()) {
            json_response(['ok' => false, 'error' => 'No autenticado'], 401);
        }
        // Detectar si estamos en /public o en raíz para no romper la redirección.
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        $loginUrl = (basename($base) === 'public' ? '' : '/public') . '/login.php';
        header('Location: ' . $loginUrl);
        exit;
    }
    return $u;
}

/**
 * Exige sesión + rol de superusuario.
 */
function require_super(): array
{
    $u = require_auth();
    if (!$u['es_superusuario']) {
        if (is_ajax_request()) {
            json_response(['ok' => false, 'error' => 'Sin permisos (se requiere superusuario)'], 403);
        }
        http_response_code(403);
        echo '<h1>403 — Sin permisos</h1><p>Esta sección es solo para el superusuario.</p>';
        exit;
    }
    return $u;
}

/**
 * Cierra la sesión de forma segura.
 */
function logout_and_destroy(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}