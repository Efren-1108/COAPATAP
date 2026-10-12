<?php
$page_title = 'Iniciar sesión';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

// Si ya hay sesión, redirigir al inicio.
if (current_user() !== null) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar sesión · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(base_url()) ?>/assets/css/styles.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🏫</text></svg>">
    <script>
        window.API_BASE = <?= json_encode((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . base_url() . '/api/') ?>;
        window.CSRF_TOKEN = ''; // se asigna tras login
    </script>
</head>
<body class="login-page">

<div class="login-wrap">
    <div class="login-card">
        <div class="login-card__head">
            <div class="login-card__icon">🔐</div>
            <h1><?= e(APP_NAME) ?></h1>
            <p>Sistema de Control de Activos Fijos</p>
        </div>

        <form id="loginForm" class="login-form" autocomplete="on" novalidate>
            <div class="form-group">
                <label for="usuario">Usuario</label>
                <input type="text" id="usuario" name="usuario" class="form-control"
                       required minlength="3" autocomplete="username"
                       placeholder="ej. admin" autofocus>
            </div>

            <div class="form-group">
                <label for="password">Contraseña</label>
                <div class="password-wrapper">
                    <input type="password" id="password" name="password"
                           class="form-control"
                           required minlength="8"
                           autocomplete="current-password"
                           placeholder="••••••••">
                    <button type="button" class="password-toggle" id="togglePassword"
                            aria-label="Mostrar contraseña" title="Mostrar contraseña" aria-pressed="false">
                        <svg id="passwordEye" xmlns="http://www.w3.org/2000/svg" width="20" height="20"
                             viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                             stroke-linecap="round" stroke-linejoin="round">
                            <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="form-error" id="formError" hidden></div>

            <button type="submit" class="btn btn--primary btn--block" id="btnLogin">
                <span class="btn__label">🔑 Iniciar sesión</span>
                <span class="spinner" hidden></span>
            </button>
        </form>

        <div class="login-card__foot">
            <p class="muted">
                ¿Olvidaste tu contraseña? Contacta al <strong>administrador</strong> del sistema
                para que la restablezca.
            </p>
            <p class="muted small">v<?= e(APP_VERSION) ?> · <?= e(date('Y')) ?></p>
        </div>
    </div>
</div>

<script src="<?= e(base_url()) ?>/assets/js/app.js?v=<?= e(APP_VERSION) ?>"></script>
<script src="<?= e(base_url()) ?>/assets/js/auth.js?v=<?= e(APP_VERSION) ?>"></script>
</body>
</html>