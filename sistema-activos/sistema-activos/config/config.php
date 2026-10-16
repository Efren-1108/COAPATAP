<?php
/**
 * Configuración del Sistema de Control de Activos Fijos
 *
 * Variables de entorno — edita según tu instalación.
 * En producción, considera mover estos valores a variables de entorno del SO
 * o a un archivo .env fuera del document root.
 *
 * Cambios respecto a la versión anterior:
 *   - [FIX] base_url() ahora maneja correctamente rutas dobles y archivos .php.
 *   - [MEJORA] Se agrega login_url() para construir la URL completa del login.
 *   - [MEJORA] Se agrega app_url() para construir URLs absolutas al proyecto.
 *   - [MEJORA] Helpers CSRF documentados.
 */

// ============ BASE DE DATOS ============
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '5432');
define('DB_NAME', getenv('DB_NAME') ?: 'activos_fijos');
define('DB_USER', getenv('DB_USER') ?: 'postgres');
define('DB_PASS', getenv('DB_PASS') ?: '54321');

// ============ APLICACIÓN ============
define('APP_NAME', 'Sistema de Control de Activos Fijos');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'http://localhost:8000');

/**
 * Devuelve la URL base del sistema (carpeta del proyecto) en el navegador.
 *
 * Ejemplos:
 *   SCRIPT_NAME = /sistema-activos/sistema-activos/public/index.php
 *     → devuelve: /sistema-activos/sistema-activos
 *   SCRIPT_NAME = /sistema-activos/sistema-activos/api/auth.php
 *     → devuelve: /sistema-activos/sistema-activos
 *   SCRIPT_NAME = /public/login.php
 *     → devuelve: ''
 *   SCRIPT_NAME = /index.php
 *     → devuelve: ''
 *
 * Funciona sin importar dónde esté instalado el proyecto.
 */
function base_url(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '';

    // 1) Quitar /public/... o /api/... (y todo lo que venga después)
    $base = preg_replace('#/(public|api)(/.*)?$#', '', $script);

    // 2) Quitar cualquier archivo .php final (por si es /index.php, /login.php, etc.)
    $base = preg_replace('#/[^/]+\.php$#', '', $base);

    // 3) Normalizar sin slash final
    return rtrim($base, '/');
}

/**
 * Devuelve la URL ABSOLUTA del proyecto (con host y esquema).
 * Ej: http://localhost/sistema-activos/sistema-activos
 */
function app_url(): string
{
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . base_url();
}

/**
 * Devuelve la URL ABSOLUTA al login.php.
 * Ej: http://localhost/sistema-activos/sistema-activos/public/login.php
 */
function login_url(): string
{
    return app_url() . '/public/login.php';
}

/**
 * Devuelve la URL ABSOLUTA a la carpeta public/.
 * Ej: http://localhost/sistema-activos/sistema-activos/public
 */
function public_url(): string
{
    return app_url() . '/public';
}

/**
 * Devuelve la URL ABSOLUTA a la API.
 * Ej: http://localhost/sistema-activos/sistema-activos/api/
 */
function api_url(): string
{
    return app_url() . '/api/';
}

// ============ UPLOADS ============
define('UPLOAD_DIR', __DIR__ . '/../public/uploads/');
define('UPLOAD_URL', 'uploads/');           // ruta pública relativa
define('MAX_UPLOAD_SIZE', 50 * 1024 * 1024);  // 50 MB
define('ALLOWED_MIME', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
define('ALLOWED_EXT',  ['jpg', 'jpeg', 'png', 'gif', 'webp']);

// ============ LOG ============
define('LOG_DIR', __DIR__ . '/../logs/');
define('LOG_FILE', LOG_DIR . 'app.log');

// ============ ZONA HORARIA ============
date_default_timezone_set('America/Mexico_City');

// ============ ENTORNO ============
define('APP_ENV', getenv('APP_ENV') ?: 'development'); // development | production
define('APP_DEBUG', APP_ENV === 'development');

// ============ CONEXIÓN A BD (singleton) ============
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            DB_HOST, DB_PORT, DB_NAME
        );
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_PERSISTENT         => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            if (APP_DEBUG) {
                echo json_encode([
                    'ok'    => false,
                    'error' => 'Error de conexión: ' . $e->getMessage(),
                ]);
            } else {
                echo json_encode([
                    'ok'    => false,
                    'error' => 'No se pudo conectar a la base de datos',
                ]);
            }
            exit;
        }
    }
    return $pdo;
}

// ============ HELPERS ============
function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_input(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function logger(string $msg, string $level = 'INFO'): void
{
    if (!is_dir(LOG_DIR)) {
        @mkdir(LOG_DIR, 0755, true);
    }
    $line = sprintf(
        "[%s] [%s] %s%s",
        date('Y-m-d H:i:s'),
        $level,
        $msg,
        PHP_EOL
    );
    @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

function e(string $str): string
{
    return htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// Auto-crear directorios necesarios
foreach ([UPLOAD_DIR, LOG_DIR] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

// ============ SESIÓN SEGURA ============
// Se inicia automáticamente al cargar config. Todas las páginas
// y APIs que incluyan config.php ya tendrán $_SESSION disponible.
function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // PHP >= 7.3 soporta SameSite en el array de opciones.
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    // Nombre de sesión distinto al default PHPSESSID (dificulta fingerprinting)
    session_name('SACT_SESSID');
    session_start();

    // Expiración por inactividad: 30 minutos
    $timeout = 30 * 60;
    if (!empty($_SESSION['_last_activity'])
        && (time() - $_SESSION['_last_activity']) > $timeout) {
        $_SESSION = [];
        session_destroy();
        session_start();
    }
    $_SESSION['_last_activity'] = time();
}
start_secure_session();

// ============ CSRF PROTECTION ============
/**
 * Genera (o devuelve) el token CSRF de la sesión actual.
 * Si no existe, lo crea con random_bytes (criptográficamente seguro).
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // 64 chars hex
    }
    return $_SESSION['csrf_token'];
}

/**
 * Valida un token CSRF contra el de la sesión.
 * Usa hash_equals para evitar timing attacks.
 */
function csrf_validate(?string $token): bool
{
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Obtiene el token CSRF enviado por el cliente.
 * Busca en: header X-CSRF-Token, header X-CSRFToken, o en el body JSON.
 */
function csrf_from_request(): ?string
{
    // 1) Header X-CSRF-Token (recomendado)
    $h = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_SERVER['HTTP_X_CSRFTOKEN'] ?? null;
    if (is_string($h) && $h !== '') {
        return $h;
    }
    // 2) Body JSON (fallback)
    $raw = file_get_contents('php://input');
    if ($raw) {
        $data = json_decode($raw, true);
        if (is_array($data) && !empty($data['_csrf'])) {
            return (string)$data['_csrf'];
        }
    }
    return null;
}

/**
 * Exige un token CSRF válido. Si no, responde 403 JSON y termina.
 * Solo se debe llamar en métodos mutación (POST/PUT/DELETE).
 */
function csrf_require(): void
{
    $token = csrf_from_request();
    if (!csrf_validate($token)) {
        json_response([
            'ok'    => false,
            'error' => 'Token CSRF inválido o expirado. Recarga la página e intenta de nuevo.',
        ], 403);
    }
}

/**
 * Rota el token CSRF (útil tras login para evitar fixation).
 */
function csrf_rotate(): string
{
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}