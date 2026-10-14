<?php
$page_title = 'Empleados y Áreas';
$page_js    = 'empleados.js';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
$user = require_auth();
require_once __DIR__ . '/../includes/header.php';
?>

<section class="page-header">
    <div>
        <h1>🏢 Empleados y Áreas</h1>
        <p>Administra los departamentos y su personal. Asigna empleados a cada área.</p>
    </div>
</section>

<div class="tabs" role="tablist">
    <button class="tab is-active" data-tab="areas" role="tab">
        🏢 Áreas <span class="tab__count" id="countAreas">0</span>
    </button>
    <button class="tab" data-tab="empleados" role="tab">
        👥 Todos los empleados <span class="tab__count" id="countEmpleados">0</span>
    </button>
</div>

<!-- ============ PANEL: ÁREAS ============ -->
<div class="tab-panel is-active" id="panelAreas" data-panel="areas">
    <div class="toolbar no-print">
        <input type="text" id="busquedaAreas" placeholder="🔎 Buscar área por clave o nombre...">
        <button class="btn btn--primary" id="btnNuevaArea">➕ Nueva área</button>
    </div>

    <div id="listaAreas"></div>
</div>

<!-- ============ PANEL: EMPLEADOS ============ -->
<div class="tab-panel" id="panelEmpleados" data-panel="empleados">
    <div class="toolbar no-print">
        <input type="text" id="busquedaEmpleados" placeholder="🔎 Buscar por nómina, nombre o cargo...">
        <button class="btn btn--primary" id="btnNuevoEmpleado">➕ Nuevo empleado</button>
    </div>

    <div class="table-wrap">
        <table class="table" id="tablaEmpleados">
            <thead>
                <tr>
                    <th>Nómina</th>
                    <th>Nombre</th>
                    <th>Cargo</th>
                    <th>Área</th>
                    <th class="no-print" style="text-align: right;">Acciones</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<!-- ============ MODAL: EMPLEADO ============ -->
<div class="modal-backdrop" id="empleadoModal">
    <div class="modal" style="max-width: 560px;">
        <div class="modal__header">
            <h2 class="modal__title" id="modalTitleEmpleado">➕ Nuevo empleado</h2>
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
                    <div class="form-group">
                        <label>Área <span class="req">*</span></label>
                        <div class="input-with-button">
                            <select name="area_id" id="selectAreaEmpleado" class="form-control" required></select>
                            <button type="button" class="btn btn--ghost" id="btnNuevaAreaInline"
                                    title="Crear nueva área">➕</button>
                        </div>
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
                <div class="form-error" id="formErrorEmpleado" style="display:none;"></div>
            </div>
            <div class="modal__footer">
                <button type="button" class="btn btn--ghost" onclick="App.closeModal('empleadoModal')">Cancelar</button>
                <button type="submit" class="btn btn--primary">💾 Guardar</button>
            </div>
        </form>
    </div>
</div>

<!-- ============ MODAL: ÁREA ============ -->
<div class="modal-backdrop" id="areaModal">
    <div class="modal" style="max-width: 520px;">
        <div class="modal__header">
            <h2 class="modal__title" id="modalTitleArea">➕ Nueva área</h2>
            <button class="modal__close" onclick="App.closeModal('areaModal')">×</button>
        </div>
        <form id="areaForm">
            <div class="modal__body">
                <input type="hidden" name="id" value="">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Clave <span class="req">*</span></label>
                        <input type="text" name="clave" class="form-control" pattern="[a-zA-Z0-9_\-]+" maxlength="30" required>
                        <span class="form-text">Solo letras, números, guion y guion bajo.</span>
                    </div>
                    <div class="form-group">
                        <label>Nombre <span class="req">*</span></label>
                        <input type="text" name="nombre" class="form-control" maxlength="150" required>
                    </div>
                    <div class="form-group form-group--full">
                        <label>Descripción</label>
                        <textarea name="descripcion" class="form-control" rows="2" maxlength="500"></textarea>
                    </div>
                    <div class="form-group form-group--full">
                        <label class="checkbox">
                            <input type="checkbox" name="activo" value="1" checked>
                            <span>Área activa</span>
                        </label>
                    </div>
                </div>
                <div class="form-error" id="formErrorArea" style="display:none;"></div>
            </div>
            <div class="modal__footer">
                <button type="button" class="btn btn--ghost" onclick="App.closeModal('areaModal')">Cancelar</button>
                <button type="submit" class="btn btn--primary">💾 Guardar</button>
            </div>
        </form>
    </div>
</div>

<!-- ============ MODAL: NUEVA ÁREA INLINE (desde empleado) ============ -->
<div class="modal-backdrop" id="areaInlineModal">
    <div class="modal modal--mini">
        <div class="modal__header">
            <h2 class="modal__title">➕ Nueva área rápida</h2>
            <button class="modal__close" onclick="App.closeModal('areaInlineModal')">×</button>
        </div>
        <form id="areaInlineForm">
            <div class="modal__body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Clave <span class="req">*</span></label>
                        <input type="text" name="clave" class="form-control" pattern="[a-zA-Z0-9_\-]+" maxlength="30" required>
                    </div>
                    <div class="form-group">
                        <label>Nombre <span class="req">*</span></label>
                        <input type="text" name="nombre" class="form-control" maxlength="150" required>
                    </div>
                </div>
                <div class="form-error" id="formErrorAreaInline" style="display:none;"></div>
            </div>
            <div class="modal__footer">
                <button type="button" class="btn btn--ghost" onclick="App.closeModal('areaInlineModal')">Cancelar</button>
                <button type="submit" class="btn btn--primary">💾 Crear y asignar</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>