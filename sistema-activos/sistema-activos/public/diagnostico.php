<?php
/**
 * Página de diagnóstico del sistema.
 * Verifica: PHP, extensiones, conexión a BD, tablas, datos de ejemplo, API y permisos de uploads.
 * Acceso: http://localhost/sistema-activos/public/diagnostico.php
 */
$page_title = 'Diagnóstico del sistema';
$page_js    = ''; // sin JS de página
require_once __DIR__ . '/../config/config.php';

$checks = []; // cada elemento: ['ok'=>bool, 'label'=>string, 'detail'=>string]

// ============ 1) PHP ============
$checks[] = [
    'ok'     => version_compare(PHP_VERSION, '7.4.0', '>='),
    'label'  => 'Versión de PHP',
    'detail' => PHP_VERSION . (version_compare(PHP_VERSION, '7.4.0', '>=') ? ' (>= 7.4)' : ' — se requiere PHP 7.4 o superior'),
];

// ============ 2) Extensiones ============
$extensions = ['pdo', 'pdo_pgsql', 'fileinfo', 'json', 'curl'];
foreach ($extensions as $ext) {
    $checks[] = [
        'ok'     => extension_loaded($ext),
        'label'  => "Extensión PHP: $ext",
        'detail' => extension_loaded($ext) ? 'cargada' : 'NO cargada — revisa php.ini',
    ];
}

// ============ 3) Conexión a BD ============
$dbConnected = false;
$dbInfo      = null;
$dbError     = null;
try {
    $pdo = db();
    $dbConnected = true;
    $stmt = $pdo->query("SELECT version() AS version, current_database() AS db, current_user AS usuario");
    $dbInfo = $stmt->fetch();
    $checks[] = [
        'ok'     => true,
        'label'  => 'Conexión a PostgreSQL',
        'detail' => "BD: {$dbInfo['db']} · Usuario: {$dbInfo['usuario']}",
    ];
} catch (Exception $e) {
    $dbError = $e->getMessage();
    $checks[] = [
        'ok'     => false,
        'label'  => 'Conexión a PostgreSQL',
        'detail' => $dbError,
    ];
}

// ============ 4) Tablas esperadas ============
$expectedTables = ['empleados', 'activos', 'log_actividades'];
$existingTables = [];
$tableCounts    = [];
if ($dbConnected) {
    try {
        $rows = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname='public'")->fetchAll();
        foreach ($rows as $r) $existingTables[] = $r['tablename'];

        foreach ($expectedTables as $t) {
            $exists = in_array($t, $existingTables, true);
            $count  = 0;
            if ($exists) {
                $count = (int)$pdo->query("SELECT COUNT(*) FROM \"$t\"")->fetchColumn();
                $tableCounts[$t] = $count;
            }
            $checks[] = [
                'ok'     => $exists,
                'label'  => "Tabla: $t",
                'detail' => $exists ? "existe — $count registro(s)" : 'NO existe — ejecuta database/migracion.sql',
            ];
        }
    } catch (Exception $e) {
        $checks[] = [
            'ok'     => false,
            'label'  => 'Verificación de tablas',
            'detail' => $e->getMessage(),
        ];
    }
}

// ============ 5) Datos de ejemplo ============
if ($dbConnected && isset($tableCounts['empleados'])) {
    $checks[] = [
        'ok'     => $tableCounts['empleados'] > 0,
        'label'  => 'Datos de ejemplo (empleados)',
        'detail' => $tableCounts['empleados'] > 0
            ? "{$tableCounts['empleados']} empleado(s) registrado(s)"
            : 'tabla vacía — la migración insertó 5 de ejemplo, revisa migracion.sql',
    ];
}
if ($dbConnected && isset($tableCounts['activos'])) {
    $checks[] = [
        'ok'     => $tableCounts['activos'] > 0,
        'label'  => 'Datos de ejemplo (activos)',
        'detail' => $tableCounts['activos'] > 0
            ? "{$tableCounts['activos']} activo(s) registrado(s)"
            : 'tabla vacía — la migración insertó 6 de ejemplo',
    ];
}

// ============ 6) Endpoints de la API ============
$apiBase = $apiBaseUrl ?? rtrim((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']), '/');
$endpoints = [
    ['empleados.php',          'API empleados (GET)'],
    ['activos.php',            'API activos (GET)'],
    ['consulta.php?nomina=1001', 'API consulta por nómina'],
];

// Para consulta.php, si no hay empleados registrados, saltamos la verificación de contenido útil
$hasNominaExample = $dbConnected && isset($tableCounts['empleados']) && $tableCounts['empleados'] > 0;
$sampleNomina     = null;
if ($hasNominaExample) {
    $sampleNomina = (int)$pdo->query("SELECT numero_nomina FROM empleados ORDER BY numero_nomina ASC LIMIT 1")->fetchColumn();
    if ($sampleNomina > 0) {
        $endpoints[2][0] = "consulta.php?nomina={$sampleNomina}";
    }
}

foreach ($endpoints as [$url, $label]) {
    $full = $apiBase . '/' . $url;
    $http = 0;
    $body = null;
    $err  = 'cURL no disponible';
    $contentType = '';
    $debugInfo   = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($full);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => false,
        ]);
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err  = curl_error($ch) ?: '';
        $errNo = curl_errno($ch);
        curl_close($ch);
        $debugInfo = $errNo ? "cURL errno $errNo" : '';
    }

    $ok = false;
    $detail = '';
    if (!function_exists('curl_init')) {
        $detail = 'Extensión cURL no cargada — habilítala en php.ini (extension=curl)';
    } elseif ($body === false || $body === null) {
        $detail = trim("No se pudo conectar: $err $debugInfo");
    } else {
        $json = json_decode($body, true);
        if (!is_array($json)) {
            $snippet = trim(substr((string)$body, 0, 200));
            $detail = "HTTP $http — respuesta no es JSON. Content-Type: $contentType";
            $checks[] = [
                'ok'     => false,
                'label'  => $label,
                'detail' => $detail,
                'extra'  => $snippet,
            ];
            continue;
        }
        if (empty($json['ok'])) {
            $detail = "HTTP $http — " . ($json['error'] ?? 'error desconocido');
        } else {
            $ok = true;
            if (isset($json['data']) && is_array($json['data'])) {
                $detail = "HTTP $http — " . count($json['data']) . ' registro(s)';
            } elseif (isset($json['empleado'])) {
                $detail = "HTTP $http — empleado: {$json['empleado']['nombre']} · {$json['total']} bien(es)";
            } else {
                $detail = "HTTP $http — OK";
            }
        }
    }
    $checks[] = [
        'ok'     => $ok,
        'label'  => $label,
        'detail' => $detail,
    ];
}

// ============ 7) Permisos de uploads/ y logs/ ============
foreach ([
    ['UPLOAD_DIR', 'Carpeta uploads/ (escritura)'],
    ['LOG_DIR',    'Carpeta logs/ (escritura)'],
] as [$const, $label]) {
    $path  = constant($const);
    $isDir = is_dir($path);
    $writable = $isDir && is_writable($path);
    $checks[] = [
        'ok'     => $writable,
        'label'  => $label,
        'detail' => $writable
            ? "OK — $path"
            : ($isDir ? "Existe pero no es escribible — $path" : "NO existe — $path"),
    ];
}

// ============ Resumen ============
$total    = count($checks);
$passed   = count(array_filter($checks, fn($c) => $c['ok']));
$failed   = $total - $passed;
$allOk    = $failed === 0;
$severity = $allOk ? 'success' : ($passed > 0 ? 'warning' : 'danger');

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.diag-summary {
    text-align: center;
    padding: 2rem 1.5rem;
    border-radius: var(--radius);
    color: #fff;
    margin-bottom: 1.5rem;
}
.diag-summary--success { background: linear-gradient(135deg, #16a34a, #15803d); }
.diag-summary--warning { background: linear-gradient(135deg, #d97706, #b45309); }
.diag-summary--danger  { background: linear-gradient(135deg, #dc2626, #b91c1c); }
.diag-summary__icon  { font-size: 3.5rem; margin-bottom: 0.5rem; }
.diag-summary__title { font-size: 1.4rem; margin: 0 0 0.25rem; }
.diag-summary__num   { font-size: 2.4rem; font-weight: 700; margin: 0.5rem 0; }
.diag-summary__sub   { opacity: 0.9; margin: 0; }

.diag-list { list-style: none; padding: 0; margin: 0; }
.diag-item {
    display: grid;
    grid-template-columns: auto 1fr auto;
    align-items: center;
    gap: 1rem;
    padding: 0.85rem 1rem;
    border-bottom: 1px solid var(--c-border);
}
.diag-item:last-child { border-bottom: none; }
.diag-item__icon {
    width: 32px; height: 32px;
    border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 1rem;
    flex-shrink: 0;
}
.diag-item__icon--ok    { background: #dcfce7; color: #166534; }
.diag-item__icon--fail  { background: #fee2e2; color: #991b1b; }
.diag-item__label { font-weight: 600; color: var(--c-text); }
.diag-item__detail {
    font-size: 0.88rem;
    color: var(--c-muted);
    word-break: break-word;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}

.diag-actions {
    display: flex; gap: 0.75rem; flex-wrap: wrap; margin-top: 1.5rem;
}
</style>

<section class="page-header">
    <div>
        <h1>🩺 Diagnóstico del sistema</h1>
        <p>Verificación rápida de conexión, base de datos, API y permisos.</p>
    </div>
    <a class="btn btn--ghost" href="index.php">← Volver</a>
</section>

<div class="diag-summary diag-summary--<?= $severity ?>">
    <div class="diag-summary__icon"><?= $allOk ? '✅' : ($passed > 0 ? '⚠️' : '❌') ?></div>
    <h2 class="diag-summary__title">
        <?= $allOk ? 'Todo funciona correctamente' : ($passed > 0 ? 'Hay observaciones' : 'El sistema no está operativo') ?>
    </h2>
    <div class="diag-summary__num"><?= $passed ?> / <?= $total ?></div>
    <p class="diag-summary__sub">
        <?= $passed ?> verificación(es) exitosa(s) · <?= $failed ?> con observación(es)
    </p>
</div>

<div class="card">
    <h2 class="card__title">🔎 Detalle de verificaciones</h2>
    <ul class="diag-list">
        <?php foreach ($checks as $c): ?>
            <li class="diag-item">
                <span class="diag-item__icon diag-item__icon--<?= $c['ok'] ? 'ok' : 'fail' ?>">
                    <?= $c['ok'] ? '✓' : '✗' ?>
                </span>
                <div>
                    <div class="diag-item__label"><?= e($c['label']) ?></div>
                    <div class="diag-item__detail"><?= e($c['detail']) ?></div>
                    <?php if (!empty($c['extra'])): ?>
                        <details style="margin-top: 0.5rem;">
                            <summary style="cursor: pointer; color: var(--c-muted); font-size: 0.85rem;">
                                Ver respuesta cruda
                            </summary>
                            <pre style="background: var(--c-bg); padding: 0.75rem; border-radius: 6px; margin-top: 0.5rem; overflow-x: auto; font-size: 0.8rem; max-height: 240px;"><?= e($c['extra']) ?></pre>
                        </details>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="diag-actions">
        <button class="btn btn--primary" onclick="location.reload()">🔄 Volver a verificar</button>
        <a class="btn btn--secondary" href="index.php">🏠 Ir a la consulta</a>
        <a class="btn btn--ghost"   href="empleados.php">👥 Ver empleados</a>
    </div>
</div>

<div class="card">
    <h2 class="card__title">ℹ️ Información del entorno</h2>
    <table class="table" style="margin: 0;">
        <tbody>
            <tr><th>APP_NAME</th><td><?= e(APP_NAME) ?></td></tr>
            <tr><th>APP_VERSION</th><td><?= e(APP_VERSION) ?></td></tr>
            <tr><th>APP_ENV</th><td><?= e(APP_ENV) ?></td></tr>
            <tr><th>PHP</th><td><?= e(PHP_VERSION) ?> · SAPI: <?= e(PHP_SAPI) ?></td></tr>
            <tr><th>Servidor</th><td><?= e($_SERVER['SERVER_SOFTWARE'] ?? 'desconocido') ?></td></tr>
            <tr><th>DB_HOST</th><td><?= e(DB_HOST) ?>:<?= e(DB_PORT) ?></td></tr>
            <tr><th>DB_NAME</th><td><?= e(DB_NAME) ?></td></tr>
            <?php if ($dbInfo): ?>
                <tr><th>PostgreSQL</th><td><?= e($dbInfo['version']) ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
