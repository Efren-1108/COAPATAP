<?php
$page_title = 'Gestión de Usuarios';
$page_js    = 'usuarios.js';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
$user = require_super();        // solo superusuario
require_once __DIR__ . '/../includes/header.php';
?>

<section class="page-header">
    <div>
        <h1>🔐 Gestión de Usuarios</h1>
        <p>Administra los usuarios que pueden operar el sistema y sus permisos.</p>
    </div>
    <button class="btn btn--primary" id="btnNuevo">➕ Nuevo usuario</button>
</section>

<div class="toolbar no-print">
    <input type="text" id="busqueda" placeholder="🔎 Buscar por nombre, usuario o correo...">
</div>

<div id="stats" class="stats"></div>

<div class="table-wrap">
    <table class="table" id="tablaUsuarios">
        <thead>
            <tr>
                <th>ID</th>
                <th>Nombre completo</th>
                <th>Usuario</th>
                <th>Correo</th>
                <th>Rol</th>
                <th>Área</th>
                <th>Estado</th>
                <th>Último acceso</th>
                <th class="no-print" style="text-align: right;">Acciones</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>

<!-- Modal de edición -->
<div class="modal-backdrop" id="usuarioModal">
    <div class="modal" style="max-width: 640px;">
        <div class="modal__header">
            <h2 class="modal__title" id="modalTitle">➕ Nuevo usuario</h2>
            <button class="modal__close" onclick="App.closeModal('usuarioModal')">×</button>
        </div>
        <form id="usuarioForm">
            <div class="modal__body">
                <input type="hidden" name="id" value="">
                <div class="form-grid">
                    <div class="form-group form-group--full">
                        <label>Nombre completo <span class="req">*</span></label>
                        <input type="text" name="nombre_completo" class="form-control" maxlength="200" required>
                    </div>
                    <div class="form-group">
                        <label>Nombre de usuario <span class="req">*</span></label>
                        <input type="text" name="nombre_usuario" class="form-control"
                               pattern="[a-zA-Z0-9_.\-]+" maxlength="60" required>
                        <span class="form-text">Solo letras, números, guion, guion bajo y punto.</span>
                    </div>
                    <div class="form-group">
                        <label>Correo (opcional)</label>
                        <input type="email" name="correo" class="form-control" maxlength="150">
                    </div>
                    <div class="form-group">
                        <label>Rol <span class="req">*</span></label>
                        <select name="rol_id" id="rol_id" class="form-control" required></select>
                    </div>
                    <div class="form-group">
                        <label>Área <span class="req" id="areaReq">*</span></label>
                        <select name="area_id" id="area_id" class="form-control"></select>
                        <span class="form-text" id="areaHint">Para usuarios normales, es obligatoria. Para superusuarios, se ignora.</span>
                    </div>
                    <div class="form-group">
                        <label>Contraseña <span class="req" id="passReq">*</span></label>
                        <input type="password" name="password" class="form-control" minlength="8" autocomplete="new-password">
                        <span class="form-text" id="passHint">Mínimo 8 caracteres. Al editar, deja vacío para no cambiar.</span>
                    </div>
                    <div class="form-group form-group--full">
                        <label class="checkbox">
                            <input type="checkbox" name="estado" value="1" checked>
                            <span>Usuario activo</span>
                        </label>
                    </div>
                </div>
                <div class="form-error" id="formError" style="display:none;"></div>
            </div>
            <div class="modal__footer">
                <button type="button" class="btn btn--ghost" onclick="App.closeModal('usuarioModal')">Cancelar</button>
                <button type="submit" class="btn btn--primary">💾 Guardar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: resetear contraseña -->
<div class="modal-backdrop" id="resetModal">
    <div class="modal" style="max-width: 460px;">
        <div class="modal__header">
            <h2 class="modal__title">🔑 Restablecer contraseña</h2>
            <button class="modal__close" onclick="App.closeModal('resetModal')">×</button>
        </div>
        <form id="resetForm">
            <div class="modal__body">
                <input type="hidden" name="id" value="">
                <p>Vas a restablecer la contraseña de <strong id="resetUserName"></strong>.</p>
                <div class="form-group">
                    <label>Nueva contraseña <span class="req">*</span></label>
                    <input type="password" name="password" class="form-control" minlength="8" required>
                    <span class="form-text">Mínimo 8 caracteres. Comunícala al usuario por un canal seguro.</span>
                </div>
                <div class="form-error" id="resetError" style="display:none;"></div>
            </div>
            <div class="modal__footer">
                <button type="button" class="btn btn--ghost" onclick="App.closeModal('resetModal')">Cancelar</button>
                <button type="submit" class="btn btn--danger-outline">🔑 Restablecer</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>