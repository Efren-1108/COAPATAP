<?php
/**
 * Configuración del Sistema de Control de Activos Fijos
 *
 * Variables de entorno — edita según tu instalación.
 * En producción, considera mover estos valores a variables de entorno del SO
 * o a un archivo .env fuera del document root.
 */

// ============ BASE DE DATOS ============
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '5432');
define('DB_NAME', getenv('DB_NAME') ?: 'activos_fijos');
define('DB_USER', getenv('DB_USER') ?: 'postgres');
define('DB_PASS', getenv('DB_PASS') ?: '12345');

// ============ APLICACIÓN ============
define('APP_NAME', 'Sistema de Control de Activos Fijos');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'http://localhost:8000');

/**
 * Devuelve la URL base del sistema en el navegador.
 * Se calcula a partir de SCRIPT_NAME: si el sistema vive en
 * /sistema-activos/public/ -> devuelve /sistema-activos
 * Si vive en la raíz         -> devuelve ''
 *
 * Útil para construir URLs absolutas a assets y al API sin
 * depender de la ruta donde está instalado XAMPP.
 */
function base_url(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    // Quitar /public/login.php, /index.php, /api/auth.php -> dejar la carpeta del proyecto
    $base = preg_replace('#/(public|api)(/.*)?$#', '', $script);
    return $base;
}

// ============ UPLOADS ============
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
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
