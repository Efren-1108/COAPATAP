<?php
$page_title = 'Mi perfil';
$page_js    = 'perfil.js';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
$user = require_auth();
require_once __DIR__ . '/../includes/header.php';
?>

<section class="page-header">
    <div>
        <h1>👤 Mi perfil</h1>
        <p>Información de tu cuenta y cambio de contraseña.</p>
    </div>
</section>

<div class="grid-2">
    <!-- Tarjeta de información -->
    <div class="card">
        <h2>Datos de la cuenta</h2>
        <dl class="profile-info">
            <dt>Nombre completo</dt>
            <dd><?= e($user['nombre_completo']) ?></dd>
            <dt>Nombre de usuario</dt>
            <dd><code>@<?= e($user['nombre_usuario']) ?></code></dd>
            <dt>Correo</dt>
            <dd><?= $user['correo'] ? e($user['correo']) : '<span class="muted">— sin correo —</span>' ?></dd>
            <dt>Rol</dt>
            <dd><span class="badge <?= $user['es_superusuario'] ? 'badge--super' : 'badge--usuario' ?>"><?= e($user['rol_nombre']) ?></span></dd>
            <dt>Área</dt>
            <dd><?= $user['area_nombre'] ? '🏢 ' . e($user['area_nombre']) : '<span class="muted">— sin área (superusuario) —</span>' ?></dd>
            <dt>Último acceso</dt>
            <dd><?= $user['ultimo_acceso'] ? e(date('Y-m-d H:i', strtotime($user['ultimo_acceso']))) : '<span class="muted">—</span>' ?></dd>
            <dt>Cuenta creada</dt>
            <dd><?= e(date('Y-m-d H:i', strtotime($user['creado_en']))) ?></dd>
        </dl>
    </div>

    <!-- Tarjeta de cambio de contraseña -->
    <div class="card">
        <h2>🔑 Cambiar mi contraseña</h2>
        <form id="perfilForm" autocomplete="off">
            <div class="form-group">
                <label>Contraseña actual <span class="req">*</span></label>
                <input type="password" name="actual" class="form-control" required minlength="8" autocomplete="current-password">
            </div>
            <div class="form-group">
                <label>Nueva contraseña <span class="req">*</span></label>
                <input type="password" name="nueva" class="form-control" required minlength="8" autocomplete="new-password">
                <span class="form-text">Mínimo 8 caracteres.</span>
            </div>
            <div class="form-group">
                <label>Confirmar nueva contraseña <span class="req">*</span></label>
                <input type="password" name="confirmar" class="form-control" required minlength="8" autocomplete="new-password">
            </div>
            <div class="form-error" id="formError" hidden></div>
            <div class="form-actions">
                <button type="submit" class="btn btn--primary" id="btnGuardar">💾 Actualizar contraseña</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>