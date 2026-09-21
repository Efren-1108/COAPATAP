<?php
$page_title = 'Gestión de Empleados';
$page_js    = 'empleados.js';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
$user = require_auth();
require_once __DIR__ . '/../includes/header.php';
?>

<section class="page-header">
    <div>
        <h1>👥 Gestión de Empleados (Resguardantes)</h1>
        <p>Administra el catálogo de personal de la escuela.</p>
    </div>
    <button class="btn btn--primary" id="btnNuevo">➕ Nuevo empleado</button>
</section>

<div class="toolbar no-print">
    <input type="text" id="busqueda" placeholder="🔎 Buscar por nómina, nombre o cargo...">
</div>

<div id="stats" class="stats"></div>

<div class="table-wrap">
    <table class="table" id="tablaEmpleados">
        <thead>
            <tr>
                <th>ID</th>
                <th>Nómina</th>
                <th>Nombre</th>
                <th>Cargo</th>
                <th>Bienes</th>
                <th class="no-print" style="text-align: right;">Acciones</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>

<!-- Modal de edición -->
<div class="modal-backdrop" id="empleadoModal">
    <div class="modal" style="max-width: 500px;">
        <div class="modal__header">
            <h2 class="modal__title" id="modalTitle">➕ Nuevo empleado</h2>
            <button class="modal__close" onclick="App.closeModal('empleadoModal')">×</button>
        </div>
        <form id="empleadoForm">
            <div class="modal__body">
                <input type="hidden" name="id" value="">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Número de Nómina <span class="req">*</span></label>
                        <input type="number" name="numero_nomina" class="form-control" min="1" required>
                        <span class="form-text">Identificador único del empleado.</span>
                    </div>
                    <div class="form-group form-group--full">
                        <label>Nombre completo <span class="req">*</span></label>
                        <input type="text" name="nombre" class="form-control" maxlength="200" required placeholder="Ej. ING. JOSE AGUSTIN PAZ MONTALVO">
                    </div>
                    <div class="form-group form-group--full">
                        <label>Cargo <span class="req">*</span></label>
                        <input type="text" name="cargo" class="form-control" maxlength="150" required placeholder="Ej. AUXILIAR INFORMATICO">
                    </div>
                </div>
                <div class="form-error" id="formError" style="display:none;"></div>
            </div>
            <div class="modal__footer">
                <button type="button" class="btn btn--ghost" onclick="App.closeModal('empleadoModal')">Cancelar</button>
                <button type="submit" class="btn btn--primary">💾 Guardar</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
