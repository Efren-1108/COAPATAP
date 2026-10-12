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

<div class="toolbar no-print">
    <input type="text" id="busqueda" placeholder="🔎 Buscar por descripción, inventario, marca, modelo, serie...">
</div>

<div id="stats" class="stats"></div>

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
                <th>Foto</th>
                <th class="no-print" style="text-align: right;">Acciones</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>

<!-- Modal de edición -->
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
                                <span class="form-text">Formatos: JPG, PNG, GIF, WEBP. Máx. 5 MB.</span>
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

<!-- Modal Visor de Imagenes -->
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
        // Tomar solo el nombre entre [nómina] y — cargo
        const match = txt.match(/\]\s+(.+?)\s+—/);
        if (match) {
            obs.value = 'ASIGNADO A ' + match[1];
        }
    }
});
</script>
