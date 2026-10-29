
<?php
/**
 * Encabezado principal del sistema
 * de control de activos fijos COAPATAP.
 *
 * Menú:
 * - Consulta
 * - Empleados y Áreas
 * - Activos
 * - Adscripciones (superusuario)
 * - Impresión de resguardos
 * - Importar Excel (superusuario)
 * - Usuarios (superusuario)
 * - Roles (superusuario)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$page_title = $page_title ?? 'Inicio';

$current = basename($_SERVER['PHP_SELF']);

// ==========================================
// DIRECCIÓN DE LAS API
// ==========================================

$apiBaseUrl = rtrim(
    (
        (isset($_SERVER['HTTPS']) &&
         $_SERVER['HTTPS'] === 'on')
            ? 'https'
            : 'http'
    ) . '://' . $_SERVER['HTTP_HOST'] . base_url(),
    '/'
) . '/api/';

// ==========================================
// CARPETA DE RECURSOS
// ==========================================

$assetsBase = base_url() . '/assets/';

// ==========================================
// OBTENER USUARIO ACTUAL
// ==========================================

if (!isset($user)) {
    $user = current_user();
}

// Identificar superusuario

$esSuper = $user
    ? !empty($user['es_superusuario'])
    : false;

// Permiso para acceder a impresión

$puedeImprimir = $user
    ? has_permission('activos')
    : false;

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        <?= e($page_title) ?> · <?= e(APP_NAME) ?>
    </title>

    <!-- =====================================
         ESTILOS PRINCIPALES
    ====================================== -->

    <link
        rel="stylesheet"
        href="<?= e($assetsBase) ?>css/styles.css"
    >

    <!-- =====================================
         ESTILOS ADICIONALES POR MÓDULO
    ====================================== -->

    <?php if (!empty($page_css)): ?>

        <link
            rel="stylesheet"
            href="<?= e($assetsBase) ?>css/<?= e(basename($page_css)) ?>"
        >

    <?php endif; ?>

    <!-- =====================================
         ICONO DEL SISTEMA
    ====================================== -->

    <link
        rel="icon"
        href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🏫</text></svg>"
    >

    <!-- =====================================
         CONFIGURACIÓN JAVASCRIPT
    ====================================== -->

    <script>

        window.API_BASE =
            <?= json_encode($apiBaseUrl) ?>;

        window.CSRF_TOKEN =
            <?= json_encode(csrf_token()) ?>;

    </script>

    <!-- =====================================
         GRÁFICAS DEL SISTEMA
    ====================================== -->

    <script
        src="<?= e($assetsBase) ?>js/vendor/chart.umd.min.js?v=<?= e(APP_VERSION) ?>"
    ></script>

</head>

<body>

    <!-- =====================================
         ENCABEZADO DEL SISTEMA
    ====================================== -->

    <header class="topbar">

        <div class="container topbar__inner">

            <?php if ($user): ?>

                <!-- =================================
                     MENÚ PRINCIPAL
                ================================== -->

                <nav
                    class="nav"
                    aria-label="Navegación principal"
                >

                    <!-- =============================
                         CONSULTA
                    ============================== -->

                    <a
                        class="nav__link <?= $current === 'index.php'
                            ? 'is-active'
                            : '' ?>"
                        href="index.php"
                    >
                        🔍 Consulta
                    </a>

                    <!-- =============================
                         EMPLEADOS Y ÁREAS
                    ============================== -->

                    <?php if (has_permission('empleados')): ?>

                        <a
                            class="nav__link <?= in_array(
                                $current,
                                ['empleados.php', 'areas.php'],
                                true
                            )
                                ? 'is-active'
                                : '' ?>"
                            href="empleados.php"
                        >
                            👥 Empleados y Áreas
                        </a>

                    <?php endif; ?>

                    <!-- =============================
                         ACTIVOS
                    ============================== -->

                    <?php if (has_permission('activos')): ?>

                        <a
                            class="nav__link <?= $current === 'activos.php'
                                ? 'is-active'
                                : '' ?>"
                            href="activos.php"
                        >
                            📦 Activos
                        </a>

                    <?php endif; ?>

                    <?php if ($esSuper): ?>
                        <a class="nav__link <?= $current === 'stock.php' ? 'is-active' : '' ?>" href="stock.php">🏬 Stock</a>
                    <?php endif; ?>

                    <!-- =============================
                         ADSCRIPCIONES
                         SOLO SUPERUSUARIO
                    ============================== -->

                    <?php if ($esSuper): ?>

                        <a
                            class="nav__link <?= $current === 'adscripciones.php'
                                ? 'is-active'
                                : '' ?>"
                            href="adscripciones.php"
                        >
                            🏛️ Adscripciones
                        </a>

                    <?php endif; ?>

                    <!-- =============================
                         IMPRESIÓN DE RESGUARDOS
                    ============================== -->

                    <?php if ($puedeImprimir): ?>

                        <a
                            class="nav__link <?= $current === 'vista_previa_resguardo.php'
                                ? 'is-active'
                                : '' ?>"
                            href="vista_previa_resguardo.php"
                            title="Imprimir y guardar resguardos"
                        >
                            🖨️ Impresión
                        </a>

                    <?php endif; ?>

                    <!-- =============================
                         OPCIONES DE SUPERUSUARIO
                    ============================== -->

                    <?php if ($esSuper): ?>

                        <!-- =========================
                             IMPORTAR EXCEL
                        ========================== -->

                        <a
                            class="nav__link <?= $current === 'importar_activos.php'
                                ? 'is-active'
                                : '' ?>"
                            href="importar_activos.php"
                            title="Importar empleados y activos desde Excel"
                        >
                            📥 Importar Excel
                        </a>

                        <!-- =========================
                             USUARIOS
                        ========================== -->

                        <a
                            class="nav__link <?= $current === 'usuarios.php'
                                ? 'is-active'
                                : '' ?>"
                            href="usuarios.php"
                        >
                            🔐 Usuarios
                        </a>

                        <!-- =========================
                             ROLES
                        ========================== -->

                        <a
                            class="nav__link <?= $current === 'roles.php'
                                ? 'is-active'
                                : '' ?>"
                            href="roles.php"
                        >
                            🛡️ Roles
                        </a>

                    <?php endif; ?>

                </nav>

                <!-- =================================
                     MENÚ DEL USUARIO
                ================================== -->

                <div class="user-menu">

                    <button
                        class="user-chip"
                        id="userChip"
                        type="button"
                        aria-haspopup="menu"
                        aria-expanded="false"
                    >

                        <!-- AVATAR -->

                        <span class="user-chip__avatar">

                            <?= e(
                                strtoupper(
                                    substr(
                                        $user['nombre_completo'],
                                        0,
                                        1
                                    )
                                )
                            ) ?>

                        </span>

                        <!-- INFORMACIÓN DEL USUARIO -->

                        <span class="user-chip__body">

                            <span class="user-chip__name">

                                <?= e(
                                    $user['nombre_completo']
                                ) ?>

                            </span>

                            <span class="user-chip__meta">

                                <!-- ROL -->

                                <span
                                    class="badge <?= $esSuper
                                        ? 'badge--super'
                                        : 'badge--usuario' ?>"
                                >

                                    <?= e(
                                        $user['rol_nombre']
                                    ) ?>

                                </span>

                                <!-- ÁREA -->

                                <?php if (!empty(
                                    $user['area_nombre']
                                )): ?>

                                    <span class="badge badge--area">

                                        🏢 <?= e(
                                            $user['area_nombre']
                                        ) ?>

                                    </span>

                                <?php endif; ?>

                            </span>

                        </span>

                        <!-- FLECHA -->

                        <span class="user-chip__caret">
                            ▾
                        </span>

                    </button>

                    <!-- =============================
                         PANEL DESPLEGABLE
                    ============================== -->

                    <div
                        class="user-menu__panel"
                        id="userMenu"
                        role="menu"
                        hidden
                    >

                        <!-- DATOS DEL USUARIO -->

                        <div class="user-menu__head">

                            <strong>

                                <?= e(
                                    $user['nombre_completo']
                                ) ?>

                            </strong>

                            <small>

                                @<?= e(
                                    $user['nombre_usuario']
                                ) ?>

                            </small>

                        </div>

                        <!-- MI PERFIL -->

                        <a
                            class="user-menu__item"
                            href="perfil.php"
                        >
                            👤 Mi perfil
                        </a>

                        <!-- CERRAR SESIÓN -->

                        <a
                            class="user-menu__item user-menu__item--danger"
                            href="logout.php"
                        >
                            🚪 Cerrar sesión
                        </a>

                    </div>

                </div>

                <!-- =================================
                     MENÚ RESPONSIVO
                ================================== -->

                <button
                    class="nav__toggle"
                    aria-label="Menú"
                    id="navToggle"
                    type="button"
                >
                    ☰
                </button>

            <?php endif; ?>

        </div>

    </header>

    <!-- =====================================
         CONTENIDO PRINCIPAL
    ====================================== -->

    <main class="container">
