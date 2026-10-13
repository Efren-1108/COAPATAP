<?php
$page_title = 'Gestión de Activos Fijos';
$page_js    = 'activos.js';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
$user = require_auth();
require_once __DIR__ . '/../includes/header.php';
?>

<section class="page-header">
    <div>
        <h1>📦 Gestión de Activos Fijos</h1>
        <p>Administra el inventario de bienes y asígnalos a los resguardantes.</p>
    </div>
    <button class="btn btn--primary" id="btnNuevo">➕ Nuevo activo</button>
</section>

<!-- ============ TABS ============ -->
<div class="tabs" role="tablist">
    <button class="tab is-active" data-tab="areas" role="tab">
        🏢 Por área <span class="tab__count" id="countAreas">0</span>
    </button>
    <button class="tab" data-tab="empleados" role="tab">
        👥 Por empleado <span class="tab__count" id="countEmpleados">0</span>
    </button>
    <button class="tab" data-tab="todos" role="tab">
        📦 Todos <span class="tab__count" id="countTodos">0</span>
    </button>
    <button class="tab" data-tab="stats" role="tab">
        📊 Estadísticas
    </button>
</div>

<!-- ============ FILTROS GLOBALES ============ -->
<div class="toolbar no-print" id="toolbarActivos">
    <input type="text" id="busqueda" placeholder="🔎 Buscar por descripción, inventario, marca, modelo, serie...">
    <select id="filtroArea">
        <option value="">🏢 Todas las áreas</option>
    </select>
    <select id="filtroEmpleado">
        <option value="">👤 Todos los empleados</option>
    </select>
    <button class="btn btn--ghost btn--sm" id="btnExpandirTodo" title="Expandir todo">⬇ Expandir</button>
    <button class="btn btn--ghost btn--sm" id="btnContraerTodo" title="Contraer todo">⬆ Contraer</button>
</div>

<!-- ============ PANEL: POR ÁREA ============ -->
<div class="tab-panel is-active" id="panelAreas" data-panel="areas">
    <div id="listaAreasConActivos"></div>
</div>

<!-- ============ PANEL: POR EMPLEADO ============ -->
<div class="tab-panel" id="panelEmpleados" data-panel="empleados">
    <div id="listaEmpleadosConActivos"></div>
</div>

<!-- ============ PANEL: TODOS ============ -->
<div class="tab-panel" id="panelTodos" data-panel="todos">
    <div id="statsResumen" class="stats"></div>
    <div class="table-wrap">
        <table class="table" id="tablaActivos">
            <thead>
                <tr>
                    <th>Descripción</th>
                    <th>Inventario</th>
                    <th>Marca</th>
                    <th>Modelo</th>
                    <th>Costo</th>
                    <th>Asignado a</th>
                    <th>Área</th>
                    <th>Foto</th>
                    <th class="no-print" style="text-align: right;">Acciones</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<!-- ============ PANEL: ESTADÍSTICAS ============ -->
<div class="tab-panel" id="panelStats" data-panel="stats">
    <div id="statsDashboard"></div>
</div>

<!-- ============ MODAL: ACTIVO ============ -->
<div class="modal-backdrop" id="activoModal">
    <div class="modal">
        <form id="activoForm" enctype="multipart/form-data" style="display: flex; flex-direction: column; height: 100%; overflow: hidden;">
            <div class="modal__header">
                <h2 class="modal__title" id="modalTitle">➕ Nuevo activo</h2>
                <button type="button" class="modal__close" onclick="App.closeModal('activoModal')">×</button>
            </div>
            <div class="modal__body">
                <input type="hidden" name="id" value="">
                <input type="hidden" name="ruta_imagen" value="">

                <div class="form-grid">
                    <div class="form-group form-group--full">
                        <label>Descripción del bien <span class="req">*</span></label>
                        <input type="text" name="descripcion" class="form-control" maxlength="200" required
                               placeholder="Ej. RACK 4 POSTES, SILLA EJECUTIVA, NOBREAK">
                    </div>
                    <div class="form-group">
                        <label>Número de Inventario <span class="req">*</span></label>
                        <input type="text" name="num_inventario" class="form-control" maxlength="50" required
                               placeholder="Ej. ECO-0001 o ECO. 6 INVENT">
                    </div>
                    <div class="form-group">
                        <label>Marca</label>
                        <input type="text" name="marca" class="form-control" maxlength="100">
                    </div>
                    <div class="form-group">
                        <label>Modelo</label>
                        <input type="text" name="modelo" class="form-control" maxlength="100">
                    </div>
                    <div class="form-group">
                        <label>Serie (o S/S)</label>
                        <input type="text" name="serie" class="form-control" maxlength="100">
                    </div>
                    <div class="form-group">
                        <label>Material</label>
                        <input type="text" name="material" class="form-control" maxlength="100">
                    </div>
                    <div class="form-group">
                        <label>Fecha de adquisición</label>
                        <input type="date" name="fecha_adq" class="form-control" max="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label>Número de factura</label>
                        <input type="text" name="factura" class="form-control" maxlength="100">
                    </div>
                    <div class="form-group">
                        <label>Costo de adquisición (MXN) <span class="req">*</span></label>
                        <input type="number" name="costo" class="form-control" min="0" step="0.01" required placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label>Asignado a (Resguardante)</label>
                        <select name="empleado_id" id="empleado_id" class="form-control">
                            <option value="">— Sin asignar —</option>
                        </select>
                        <span class="form-text">Selecciona un empleado. Al elegir, el campo observaciones se rellenará automáticamente.</span>
                    </div>
                    <div class="form-group form-group--full">
                        <label>Observaciones</label>
                        <textarea name="observaciones" class="form-control" rows="2"
                                  placeholder="Notas adicionales..."></textarea>
                    </div>
                    <div class="form-group form-group--full">
                        <label>Fotografía del bien</label>
                        <div class="image-preview">
                            <div id="imagenPreview" class="image-preview__placeholder">Sin imagen</div>
                            <div>
                                <input type="file" name="imagen" id="imagen" accept="image/jpeg,image/png,image/gif,image/webp">
                                <span class="form-text">Formatos: JPG, PNG, GIF, WEBP. Máx. 50 MB.</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-error" id="formError" style="display:none;"></div>
            </div>
            <div class="modal__footer">
                <button type="button" class="btn btn--ghost" onclick="App.closeModal('activoModal')">Cancelar</button>
                <button type="submit" class="btn btn--primary">💾 Guardar</button>
            </div>
        </form>
    </div>
</div>

<!-- ============ MODAL: VISOR DE IMÁGENES ============ -->
<div class="modal-backdrop" id="imageViewerModal">
    <div class="modal modal--image-viewer">
        <div class="modal__header">
            <h2 class="modal__title">Vista Previa de Imagen</h2>
            <button class="modal__close" onclick="App.closeModal('imageViewerModal')">×</button>
        </div>
        <div class="modal__body modal__body--center">
            <img id="viewerImage" src="" alt="Vista ampliada" class="viewer-img">
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
// Autocompletar observaciones al elegir un resguardante
document.getElementById('empleado_id').addEventListener('change', function() {
    const obs = document.querySelector('textarea[name="observaciones"]');
    if (this.value && (!obs.value || obs.value.trim() === '')) {
        const txt = this.options[this.selectedIndex].text;
        const match = txt.match(/\]\s+(.+?)\s+—/);
        if (match) {
            obs.value = 'ASIGNADO A ' + match[1];
        }
    }
});
</script>