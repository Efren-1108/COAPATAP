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
 *
 * Cambios respecto a la versión anterior:
 *   - [FIX] require_auth() ahora redirige correctamente al login usando
 *           login_url() en lugar de calcular la ruta manualmente.
 *   - [MEJORA] Se documenta mejor cada función.
 *   - [MEJORA] Se agrega require_auth_csrf() y require_super_csrf().
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
function load_current_user(int $userId): ?array{
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
            WHERE u.id = :id AND r.activo IS TRUE
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
    if (!in_array($row['estado'], [true, 1, '1', 't', 'true'], true)) {
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
 *
 * IMPORTANTE: la redirección se hace a login_url() que construye la URL
 * completa usando base_url() + '/public/login.php', funcionando sin importar
 * dónde esté instalado el proyecto.
 */
function require_auth(): array
{
    $u = current_user();
    if ($u === null) {
        if (is_ajax_request()) {
            json_response(['ok' => false, 'error' => 'No autenticado'], 401);
        }

        // Redirigir al login usando la URL absoluta correcta.
        // login_url() ya incluye host + carpeta del proyecto + /public/login.php
        header('Location: ' . login_url());
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
 * Exige sesión + token CSRF válido en métodos mutación (POST/PUT/DELETE).
 * Los GET pasan sin validar CSRF.
 *
 * Uso en APIs:
 *   $user = require_auth_csrf();
 */
function require_auth_csrf(): array
{
    $u = require_auth();

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
        csrf_require();
    }

    return $u;
}

/**
 * Exige sesión + rol superusuario + token CSRF válido en mutaciones.
 */
function require_super_csrf(): array
{
    $u = require_auth_csrf();
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

/**
 * Verifica si el usuario actual tiene permiso para acceder a un módulo específico.
 */
function has_permission(string $modulo_clave): bool
{
    $u = current_user();
    if (!$u) return false;
    if ($u['es_superusuario']) return true;
    // Importar Excel está disponible para todas las sesiones válidas.
    // Las escrituras de usuarios comunes se restringen a su propia área
    // en public/importar_activos.php (comprobación en servidor).
    if ($modulo_clave === 'importar') return true;

    try {
        $pdo = db();
        $stmt = $pdo->prepare("
            SELECT rp.concedido
            FROM rol_permisos rp
            JOIN modulos m ON m.id = rp.modulo_id
            WHERE rp.rol_id = :rol_id AND m.clave = :modulo
        ");
        $stmt->execute([
            ':rol_id' => $u['rol_id'],
            ':modulo' => $modulo_clave
        ]);
        $res = $stmt->fetch();
        // PDO_PGSQL puede devolver 't' o 'f'; (bool)'f' seria TRUE.
        return $res && in_array($res['concedido'], [true, 1, '1', 't', 'true'], true);
    } catch (Throwable $e) {
        logger("auth.has_permission error: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

/** Permite entrar si el rol tiene acceso a cualquiera de los módulos indicados. */
function has_any_permission(array $modules): bool
{
    foreach ($modules as $module) {
        if (has_permission($module)) return true;
    }
    return false;
}

/** Comprueba el permiso también contra acceso directo a la URL o API. */
function require_any_permission(array $modules): array
{
    $user = require_auth();
    if (!has_any_permission($modules)) {
        if (is_ajax_request()) {
            json_response(['ok' => false, 'error' => 'No tienes permiso para acceder a este módulo'], 403);
        }
        http_response_code(403);
        exit('<h1>403 — Sin permisos</h1><p>Tu rol no tiene acceso a esta sección.</p>');
    }
    return $user;
}

function require_permission(string $module): array
{
    return require_any_permission([$module]);
}

/** Para APIs que modifican datos: sesión, permiso y CSRF. */
function require_any_permission_csrf(array $modules): array
{
    $user = require_any_permission($modules);
    if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST','PUT','DELETE','PATCH'], true)) {
        csrf_require();
    }
    return $user;
}

function require_permission_csrf(string $module): array
{
    return require_any_permission_csrf([$module]);
}

/** Página de inicio según los módulos autorizados, sin dejar al usuario frente a un 403. */
function default_module_page(): string
{
    $pages = [
        'consulta' => 'index.php',
        'empleados' => 'empleados.php',
        'activos' => 'activos.php',
        'stock' => 'stock.php',
        'adscripciones' => 'adscripciones.php',
        'impresion' => 'vista_previa_resguardo.php',
        'usuarios' => 'usuarios.php',
        'roles' => 'roles.php',
        'importar' => 'importar_activos.php',
    ];
    foreach ($pages as $key => $filename) {
        if (has_permission($key)) return $filename;
    }
    // Siempre disponible: Mi perfil / cambiar contraseña.
    return 'perfil.php';
}


/**
 * Áreas visibles por el rol. NULL = Superusuario (todas); [] = ninguna.
 * Por defecto, los roles existentes conservan el área propia de cada usuario.
 * También delimita las operaciones de escritura cuando el rol tiene el permiso
 * CRUD correspondiente; la lectura sola nunca concede escritura.
 */
function read_area_ids(?array $user = null): ?array
{
    static $cache = [];
    $user = $user ?? current_user();
    if (!$user) return [];
    if (!empty($user['es_superusuario'])) return null;
    $key = (string)$user['rol_id'] . ':' . (string)($user['area_id'] ?? '');
    if (array_key_exists($key, $cache)) return $cache[$key];

    try {
        $pdo = db();
        $st = $pdo->prepare('SELECT alcance_areas FROM public.roles WHERE id = :id');
        $st->execute([':id' => $user['rol_id']]);
        $modo = $st->fetchColumn();
        if ($modo === 'seleccionadas') {
            $st = $pdo->prepare('SELECT ra.area_id FROM public.rol_areas ra
                JOIN public.areas a ON a.id = ra.area_id AND a.activo IS TRUE
                WHERE ra.rol_id = :id ORDER BY ra.area_id');
            $st->execute([':id' => $user['rol_id']]);
            return $cache[$key] = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        }
        if ($modo !== 'propia') return $cache[$key] = []; // Si no se ejecutó la migración, denegar.
        return $cache[$key] = ($user['area_id'] === null ? [] : [(int)$user['area_id']]);
    } catch (Throwable $e) {
        logger('auth.read_area_ids: ' . $e->getMessage(), 'ERROR');
        return $cache[$key] = [];
    }
}

/** Condición SQL sin interpolación de entradas HTTP; IDs tipados desde BD. */
function read_area_condition(string $column, ?array $user = null): string
{
    // El nombre de columna solo puede provenir del código del servidor.
    if (!preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)?$/i', $column)) {
        throw new InvalidArgumentException('Columna de área no válida');
    }
    $ids = read_area_ids($user);
    if ($ids === null) return '1=1';
    if (!$ids) return '1=0';
    return $column . ' IN (' . implode(',', array_map('intval', $ids)) . ')';
}

function can_read_area(?int $areaId, ?array $user = null): bool
{
    $ids = read_area_ids($user);
    return $ids === null || ($areaId !== null && in_array($areaId, $ids, true));
}

/** Áreas en las que puede operar: además hace falta el permiso CRUD del módulo. */
function can_write_area(?int $areaId, ?array $user = null): bool
{
    return can_read_area($areaId, $user);
}

/** Matriz de operaciones concedidas al rol actual. FALLA EN CERRADO. */
function role_action_matrix(?array $user = null): array
{
    static $cache = [];
    $user = $user ?? current_user();
    if (!$user) return [];
    $key = (int)$user['id'] . ':' . (int)$user['rol_id'];
    if (isset($cache[$key])) return $cache[$key];
    $crud = ['activos', 'empleados', 'usuarios', 'roles', 'stock'];
    if (!empty($user['es_superusuario'])) {
        $matrix = [];
        foreach ($crud as $module) $matrix[$module] = ['crear'=>true, 'modificar'=>true, 'eliminar'=>true];
        return $cache[$key] = $matrix;
    }
    try {
        $pdo = db();
        $st = $pdo->prepare('SELECT m.clave, rp.concedido, rp.puede_crear, rp.puede_modificar, rp.puede_eliminar
            FROM public.rol_permisos rp JOIN public.modulos m ON m.id=rp.modulo_id
            WHERE rp.rol_id = :rol');
        $st->execute([':rol'=>(int)$user['rol_id']]);
        $matrix = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!in_array($row['clave'], $crud, true)) continue;
            $enabled = in_array($row['concedido'], [true, 1, '1', 't', 'true'], true);
            $matrix[$row['clave']] = [];
            foreach (['crear','modificar','eliminar'] as $action) {
                $matrix[$row['clave']][$action] = $enabled &&
                    in_array($row['puede_'.$action], [true, 1, '1', 't', 'true'], true);
            }
        }
        return $cache[$key] = $matrix;
    } catch (Throwable $e) {
        logger('auth.role_action_matrix error: ' . $e->getMessage(), 'ERROR');
        return $cache[$key] = [];
    }
}

function has_action_permission(string $module, string $action): bool
{
    if (!in_array($action, ['crear','modificar','eliminar'], true)) return false;
    $u = current_user();
    if (!$u) return false;
    if (!has_permission($module)) return false;
    if (!empty($u['es_superusuario'])) return true;
    $matrix = role_action_matrix($u);
    return $matrix[$module][$action] ?? false;
}

/** Los permisos de escritura se comprueban en el servidor, no solo en botones. */
function require_action_permission(string $module, string $action): array
{
    $u = require_permission_csrf($module);
    if (!has_action_permission($module, $action)) {
        if (is_ajax_request()) {
            json_response(['ok'=>false,'error'=>"Tu rol no tiene permiso para $action en este módulo"],403);
        }
        http_response_code(403);
        exit('<h1>403 — Operación no autorizada</h1>');
    }
    return $u;
}
