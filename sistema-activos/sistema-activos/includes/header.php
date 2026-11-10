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
    ? has_permission('impresion')
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
        window.IS_SUPERUSER = <?= $esSuper ? 'true' : 'false' ?>;

    </script>

    <!-- =====================================
         GRÁFICAS DEL SISTEMA
    ====================================== -->

    <script
        src="<?= e($assetsBase) ?>js/vendor/chart.umd.min.js?v=<?= e(APP_VERSION) ?>"
    ></script>

    <!-- Botones del menu: mantienen el aspecto de los enlaces sin exponer URL al pasar el cursor -->
    <style>
        button.brand,
        button.nav__link,
        button.user-menu__item {
            appearance: none;
            border: 0;
            background: transparent;
            font-family: inherit;
            line-height: inherit;
            cursor: pointer;
            text-align: left;
        }
        button.user-menu__item { width: 100%; }
    </style>

</head>

<body>

    <!-- =====================================
         ENCABEZADO DEL SISTEMA
    ====================================== -->

    <header class="topbar">

        <div class="container topbar__inner">

            <?php if ($user): ?>

                <!-- =====================================
                    IDENTIDAD INSTITUCIONAL COAPATAP
                ====================================== -->

                <button type="button"
                    class="brand brand--institucional"
                    data-navegar="<?= e(default_module_page()) ?>"
                    aria-label="Ir al panel principal de COAPATAP"
                >
                    <span class="brand__logo">
                        <img
                            src="<?= e($assetsBase) ?>img/logo-coapatap.png"
                            alt=""
                        >
                    </span>

                    <span class="brand__text">
                        <strong>Sistema de Control de Activos Fijos</strong> 
                    </span>
                </button>

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

                    <?php if (has_permission('consulta')): ?>
                        <button type="button"
                            class="nav__link nav--consulta-animada <?= $current === 'index.php' ? 'is-active' : '' ?>"
                            data-navegar="index.php"
                        >
                            <img
                                class="nav__icon"
                                src="<?= e($assetsBase) ?>img/icons/icono-consulta.png"
                                data-gif="<?= e($assetsBase) ?>img/icons/icono-consulta.gif"
                                alt=""
                            >
                            Dashboard
                        </button>

                    <?php endif; ?>

                    <!-- =============================
                         EMPLEADOS Y ÁREAS
                    ============================== -->

                    <?php if (has_permission('empleados')): ?>

                        <button type="button"
                            class="nav__link nav--icono-animado <?= in_array(
                                $current,
                                ['empleados.php', 'areas.php'],
                                true
                            ) ? 'is-active' : '' ?>"
                            data-navegar="empleados.php"
                        >
                            <img
                                src="<?= e($assetsBase) ?>img/icons/empleados-areas.png"
                                class="nav__icon"
                                alt=""
                            >
                            Empleados y Áreas
                        </button>

                    <?php endif; ?>

                    <!-- =============================
                         ACTIVOS
                    ============================== -->

                    <?php if (has_permission('activos')): ?>

                        <button type="button"
                            class="nav__link nav--icono-animado <?= $current === 'activos.php' ? 'is-active' : '' ?>"
                            data-navegar="activos.php"
                        >
                            <img
                                src="<?= e($assetsBase) ?>img/icons/activos.png"
                                class="nav__icon"
                                alt=""
                            >
                            Activos
                        </button>

                    <?php endif; ?>

                    <!-- =============================
                         STOCK                    
                    ============================== -->

                    <?php if (has_permission('stock')): ?>
                        <button type="button"
                            class="nav__link nav--icono-animado <?= $current === 'stock.php' ? 'is-active' : '' ?>"
                            data-navegar="stock.php"
                        >
                            <img
                                src="<?= e($assetsBase) ?>img/icons/stock-menu.png"
                                class="nav__icon"
                                alt=""
                            >
                            Stock
                        </button>
                    <?php endif; ?>


                    <!-- =============================
                         ADSCRIPCIONES
                         SOLO SUPERUSUARIO
                    ============================== -->

                    <?php if (has_permission('adscripciones')): ?>

                        <button type="button"
                            class="nav__link nav--icono-animado <?= $current === 'adscripciones.php'
                                ? 'is-active'
                                : '' ?>"
                            data-navegar="adscripciones.php"
                        >
                        <img
                            src="<?= e($assetsBase) ?>img/icons/adscripciones-menu.png"
                            class="nav__icon"
                            alt=""
                        >
                            Adscripciones
                        </button>

                    <?php endif; ?>

                    <!-- =============================
                         IMPRESIÓN DE RESGUARDOS
                    ============================== -->

                    <?php if ($puedeImprimir): ?>

                        <button type="button"
                            class="nav__link nav--icono-animado <?= $current === 'vista_previa_resguardo.php'
                                ? 'is-active'
                                : '' ?>"
                            data-navegar="vista_previa_resguardo.php"
                            title="Imprimir y guardar resguardos"
                        >
                            <img
                                src="<?= e($assetsBase) ?>img/icons/imprimi.png"
                                class="nav__icon"
                                alt=""
                            >
                            Impresión
                        </button>

                    <?php endif; ?>

                    <!-- =============================
                         OPCIONES DE SUPERUSUARIO
                    ============================== -->

                    <?php if (has_any_permission(['importar', 'usuarios', 'roles'])): ?>

                        <?php if (has_permission('importar')): ?>
                        <!-- =========================
                             IMPORTAR EXCEL
                        ========================== -->

                        <button type="button"
                            class="nav__link nav--icono-animado <?= $current === 'importar_activos.php'
                                ? 'is-active'
                                : '' ?>"
                            data-navegar="importar_activos.php"
                            title="Importar empleados y activos desde Excel"
                        >
                        <img
                            src="<?= e($assetsBase) ?>img/icons/importar-excel.png"
                            class="nav__icon nav__icon--blanco"
                            alt=""
                        >
                            Importar Excel
                        </button>

                        <?php endif; ?>
                        <?php if (has_permission('usuarios')): ?>
                        <!-- =========================
                             USUARIOS
                        ========================== -->

                        <button type="button"
                            class="nav__link nav--icono-animado <?= $current === 'usuarios.php'
                                ? 'is-active'
                                : '' ?>"
                            data-navegar="usuarios.php"
                        >
                        <img
                            src="<?= e($assetsBase) ?>img/icons/llave-menu.png"
                            class="nav__icon"
                            alt=""
                        >
                            Usuarios
                        </button>

                        <?php endif; ?>
                        <?php if (has_permission('roles')): ?>
                        <!-- =========================
                             ROLES
                        ========================== -->

                        <button type="button"
                            class="nav__link nav--icono-animado <?= $current === 'roles.php'
                                ? 'is-active'
                                : '' ?>"
                            data-navegar="roles.php"
                        >
                        <img
                            src="<?= e($assetsBase) ?>img/icons/Roles.png"
                            class="nav__icon"
                            alt=""
                        >
                            Roles
                        </button>

                        <?php endif; ?>
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

                        <button type="button"
                            class="user-menu__item"
                            data-navegar="perfil.php"
                        >
                            👤 Mi perfil
                        </button>

                        <!-- CERRAR SESIÓN -->

                        <button type="button"
                            class="user-menu__item user-menu__item--danger"
                            data-navegar="logout.php"
                        >
                            🚪 Cerrar sesión
                        </button>

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
