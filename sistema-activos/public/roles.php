<?php
$page_title = 'Gestión de Roles';
$page_js    = 'roles.js';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
$user = require_super();
require_once __DIR__ . '/../includes/header.php';
?>

<section class="page-header">
    <div>
        <h1>🛡️ Gestión de Roles</h1>
        <p>Define los roles que pueden asignarse a los usuarios del sistema.</p>
    </div>
    <button class="btn btn--primary" id="btnNuevo">➕ Nuevo rol</button>
</section>

<div class="toolbar no-print">
    <input type="text" id="busqueda" placeholder="🔎 Buscar por clave o nombre...">
</div>

<div id="stats" class="stats"></div>

<div class="table-wrap">
    <table class="table" id="tablaRoles">
        <thead>
            <tr>
                <th>ID</th>
                <th>Clave</th>
                <th>Nombre</th>
                <th>Descripción</th>
                <th>Usuarios</th>
                <th>Estado</th>
                <th class="no-print" style="text-align: right;">Acciones</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>

<!-- Modal -->
<div class="modal-backdrop" id="rolModal">
    <div class="modal" style="max-width: 520px;">
        <div class="modal__header">
            <h2 class="modal__title" id="modalTitle">➕ Nuevo rol</h2>
            <button class="modal__close" onclick="App.closeModal('rolModal')">×</button>
        </div>
        <form id="rolForm">
            <div class="modal__body">
                <input type="hidden" name="id" value="">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Clave <span class="req">*</span></label>
                        <input type="text" name="clave" class="form-control" pattern="[a-zA-Z0-9_\-]+" maxlength="30" required>
                        <span class="form-text">Identificador único. Solo letras, números, guion y guion bajo.</span>
                    </div>
                    <div class="form-group">
                        <label>Nombre <span class="req">*</span></label>
                        <input type="text" name="nombre" class="form-control" maxlength="100" required>
                    </div>
                    <div class="form-group form-group--full">
                        <label>Descripción</label>
                        <textarea name="descripcion" class="form-control" rows="2" maxlength="500"></textarea>
                    </div>
                    <div class="form-group form-group--full">
                        <label class="checkbox">
                            <input type="checkbox" name="activo" value="1" checked>
                            <span>Rol activo</span>
                        </label>
                    </div>
                </div>
                <div class="form-error" id="formError" style="display:none;"></div>
            </div>
            <div class="modal__footer">
                <button type="button" class="btn btn--ghost" onclick="App.closeModal('rolModal')">Cancelar</button>
                <button type="submit" class="btn btn--primary">💾 Guardar</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>