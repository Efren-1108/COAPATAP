
<?php
/**
 * Encabezado principal del sistema
 * de control de activos fijos.
 *
 * Incluye el acceso al módulo de impresión.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$page_title = $page_title ?? 'Inicio';
$current = basename($_SERVER['PHP_SELF']);

// Dirección de las API
$apiBaseUrl = rtrim(
    (
        (isset($_SERVER['HTTPS']) &&
         $_SERVER['HTTPS'] === 'on')
            ? 'https'
            : 'http'
    ) . '://' . $_SERVER['HTTP_HOST'] . base_url(),
    '/'
) . '/api/';

// Carpeta de recursos
$assetsBase = base_url() . '/assets/';

// Obtener usuario actual
if (!isset($user)) {
    $user = current_user();
}

$esSuper = $user
    ? $user['es_superusuario']
    : false;

// Permiso para consultar activos e imprimir
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

    <link
        rel="stylesheet"
        href="<?= e($assetsBase) ?>css/styles.css"
    >
 
    <?php if (!empty($page_css)): ?>

    <link
        rel="stylesheet"
        href="<?= e($assetsBase) ?>css/<?= e(basename($page_css)) ?>"
    >

    <?php endif; ?>


    <link
        rel="icon"
        href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🏫</text></svg>"
    >

    <script>
        window.API_BASE =
            <?= json_encode($apiBaseUrl) ?>;

        window.CSRF_TOKEN =
            <?= json_encode(csrf_token()) ?>;
    </script>

    <!-- Gráficas del sistema -->

    <script
        src="<?= e($assetsBase) ?>js/vendor/chart.umd.min.js?v=<?= e(APP_VERSION) ?>"
    ></script>

</head>

<body>

    <header class="topbar">

        <div class="container topbar__inner">

            <?php if ($user): ?>

                <!-- MENÚ PRINCIPAL -->

                <nav
                    class="nav"
                    aria-label="Navegación principal"
                >

                    <!-- CONSULTA -->

                    <a
                        class="nav__link <?= $current === 'index.php' ? 'is-active' : '' ?>"
                        href="index.php"
                    >
                        🔍 Consulta
                    </a>

                    <!-- EMPLEADOS Y ÁREAS -->

                    <?php if (has_permission('empleados')): ?>

                        <a
                            class="nav__link <?= in_array($current, ['empleados.php', 'areas.php']) ? 'is-active' : '' ?>"
                            href="empleados.php"
                        >
                            👥 Empleados y Áreas
                        </a>

                    <?php endif; ?>

                    <!-- ACTIVOS -->

                    <?php if (has_permission('activos')): ?>

                        <a
                            class="nav__link <?= $current === 'activos.php' ? 'is-active' : '' ?>"
                            href="activos.php"
                        >
                            📦 Activos
                        </a>

                    <?php endif; ?>


                    <!-- ==========================
                         NUEVO MÓDULO DE IMPRESIÓN
                    =========================== -->

                    <?php if ($puedeImprimir): ?>

                        <a
                            class="nav__link <?= $current === 'vista_previa_resguardo.php' ? 'is-active' : '' ?>"
                            href="vista_previa_resguardo.php"
                            title="Imprimir y guardar resguardos"
                        >
                            🖨️ Impresión
                        </a>

                    <?php endif; ?>


                    <!-- OPCIONES DE SUPERUSUARIO -->

                    <?php if ($esSuper): ?>

                        <a
                            class="nav__link <?= $current === 'adscripciones.php' ? 'is-active' : '' ?>"
                            href="adscripciones.php"
                        >
                            🏛️ Adscripciones
                        </a>

                        <a
                            class="nav__link <?= $current === 'usuarios.php' ? 'is-active' : '' ?>"
                            href="usuarios.php"
                        >
                            🔐 Usuarios
                        </a>

                        <a
                            class="nav__link <?= $current === 'roles.php' ? 'is-active' : '' ?>"
                            href="roles.php"
                        >
                            🛡️ Roles
                        </a>

                    <?php endif; ?>

                </nav>

                <!-- MENÚ DE USUARIO -->

                <div class="user-menu">

                    <button
                        class="user-chip"
                        id="userChip"
                        type="button"
                        aria-haspopup="menu"
                        aria-expanded="false"
                    >

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

                        <span class="user-chip__body">

                            <span class="user-chip__name">
                                <?= e($user['nombre_completo']) ?>
                            </span>

                            <span class="user-chip__meta">

                                <span class="badge <?= $esSuper ? 'badge--super' : 'badge--usuario' ?>">

                                    <?= e($user['rol_nombre']) ?>

                                </span>

                                <?php if (!empty($user['area_nombre'])): ?>

                                    <span class="badge badge--area">

                                        🏢 <?= e($user['area_nombre']) ?>

                                    </span>

                                <?php endif; ?>

                            </span>

                        </span>

                        <span class="user-chip__caret">
                            ▾
                        </span>

                    </button>

                    <!-- PANEL DESPLEGABLE -->

                    <div
                        class="user-menu__panel"
                        id="userMenu"
                        role="menu"
                        hidden
                    >

                        <div class="user-menu__head">

                            <strong>
                                <?= e($user['nombre_completo']) ?>
                            </strong>

                            <small>
                                @<?= e($user['nombre_usuario']) ?>
                            </small>

                        </div>

                        <a
                            class="user-menu__item"
                            href="perfil.php"
                        >
                            👤 Mi perfil
                        </a>

                        <a
                            class="user-menu__item user-menu__item--danger"
                            href="logout.php"
                        >
                            🚪 Cerrar sesión
                        </a>

                    </div>

                </div>

                <!-- MENÚ RESPONSIVO -->

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

    <!-- CONTENIDO PRINCIPAL -->

    <main class="container">
