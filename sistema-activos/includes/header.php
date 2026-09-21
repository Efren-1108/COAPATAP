<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$page_title = $page_title ?? 'Inicio';
$current    = basename($_SERVER['PHP_SELF']);

// URL base para llamadas a la API desde JavaScript.
// Construida con el helper base_url() para que funcione sin importar
// si la app vive en /sistema-activos/public o en la raíz.
$apiBaseUrl = rtrim((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . base_url(), '/') . '/api/';

// Ruta pública a la carpeta assets/. Usa base_url() para que sea absoluta.
$assetsBase = base_url() . '/assets/';

// Si no se ha solicitado $user, obtenerlo.
// En páginas protegidas, require_auth ya fue llamado y devolvió $user.
// En login.php y logout.php NO hacemos require_auth.
if (!isset($user)) {
    $user = current_user();
}
$esSuper = $user ? $user['es_superusuario'] : false;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title) ?> · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e($assetsBase) ?>css/styles.css">
    <link rel="icon" type="image/png" href="<?= e($assetsBase) ?>img/logo.png">
    <script>window.API_BASE = <?= json_encode($apiBaseUrl) ?>;</script>
</head>
<body>
    <header class="topbar">
        <div class="container topbar__inner">
            <a class="brand" href="index.php">
                <span class="brand__icon">🏫</span>
                <span class="brand__text">
                    <strong><?= e(APP_NAME) ?></strong>
                    <small>Control de Activos Fijos</small>
                </span>
            </a>
            <?php if ($user): ?>
            <nav class="nav" aria-label="Navegación principal">
                <a class="nav__link <?= $current === 'index.php' ? 'is-active' : '' ?>" href="index.php">🔍 Consulta</a>
                <a class="nav__link <?= $current === 'empleados.php' ? 'is-active' : '' ?>" href="empleados.php">👥 Empleados</a>
                <a class="nav__link <?= $current === 'activos.php' ? 'is-active' : '' ?>" href="activos.php">📦 Activos</a>
                <?php if ($esSuper): ?>
                    <a class="nav__link <?= $current === 'usuarios.php' ? 'is-active' : '' ?>" href="usuarios.php">🔐 Usuarios</a>
                    <a class="nav__link <?= $current === 'areas.php' ? 'is-active' : '' ?>" href="areas.php">🏢 Áreas</a>
                    <a class="nav__link <?= $current === 'roles.php' ? 'is-active' : '' ?>" href="roles.php">🛡️ Roles</a>
                <?php endif; ?>
            </nav>

            <div class="user-menu">
                <button class="user-chip" id="userChip" type="button" aria-haspopup="menu" aria-expanded="false">
                    <span class="user-chip__avatar"><?= e(strtoupper(substr($user['nombre_completo'], 0, 1))) ?></span>
                    <span class="user-chip__body">
                        <span class="user-chip__name"><?= e($user['nombre_completo']) ?></span>
                        <span class="user-chip__meta">
                            <span class="badge <?= $esSuper ? 'badge--super' : 'badge--usuario' ?>">
                                <?= e($user['rol_nombre']) ?>
                            </span>
                            <?php if (!empty($user['area_nombre'])): ?>
                                <span class="badge badge--area">🏢 <?= e($user['area_nombre']) ?></span>
                            <?php endif; ?>
                        </span>
                    </span>
                    <span class="user-chip__caret">▾</span>
                </button>
                <div class="user-menu__panel" id="userMenu" role="menu" hidden>
                    <div class="user-menu__head">
                        <strong><?= e($user['nombre_completo']) ?></strong>
                        <small>@<?= e($user['nombre_usuario']) ?></small>
                    </div>
                    <a class="user-menu__item" href="perfil.php">👤 Mi perfil</a>
                    <a class="user-menu__item user-menu__item--danger" href="logout.php">🚪 Cerrar sesión</a>
                </div>
            </div>
            <button class="nav__toggle" aria-label="Menú" id="navToggle">☰</button>
            <?php endif; ?>
        </div>
    </header>

    <main class="container">