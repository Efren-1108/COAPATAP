<?php
$page_title = 'Gestión de Adscripciones';
$page_js    = 'adscripciones.js';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
$user = require_super();
require_once __DIR__ . '/../includes/header.php';
?>

<section class="page-header">
    <div>
        <h1>🏛️ Gestión de Adscripciones</h1>
        <p>Administra las sucursales, planteles o direcciones. Cada área pertenece a una adscripción.</p>
    </div>
    <button class="btn btn--primary" id="btnNuevo">➕ Nueva adscripción</button>
</section>

<div class="toolbar no-print">
    <input type="text" id="busqueda" placeholder="🔎 Buscar por clave, nombre o dirección...">
</div>

<div id="stats" class="stats"></div>

<div id="listaAdscripciones"></div>

<!-- ============ MODAL: ADSCRIPCIÓN ============ -->
<div class="modal-backdrop" id="adscripcionModal">
    <div class="modal" style="max-width: 560px;">
        <div class="modal__header">
            <h2 class="modal__title" id="modalTitle">➕ Nueva adscripción</h2>
            <button class="modal__close" onclick="App.closeModal('adscripcionModal')">×</button>
        </div>
        <form id="adscripcionForm">
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
                        <input type="text" name="nombre" class="form-control" maxlength="150" required placeholder="Ej. Dirección General, Plantel Norte">
                    </div>
                    <div class="form-group form-group--full">
                        <label>Dirección física</label>
                        <input type="text" name="direccion" class="form-control" maxlength="255" placeholder="Ej. Av. Universidad 123, Col. Centro">
                    </div>
                    <div class="form-group form-group--full">
                        <label>Descripción</label>
                        <textarea name="descripcion" class="form-control" rows="2" maxlength="500" placeholder="Notas adicionales..."></textarea>
                    </div>
                    <div class="form-group form-group--full">
                        <label class="checkbox">
                            <input type="checkbox" name="activo" value="1" checked>
                            <span>Adscripción activa</span>
                        </label>
                    </div>
                </div>
                <div class="form-error" id="formError" style="display:none;"></div>
            </div>
            <div class="modal__footer">
                <button type="button" class="btn btn--ghost" onclick="App.closeModal('adscripcionModal')">Cancelar</button>
                <button type="submit" class="btn btn--primary">💾 Guardar</button>
            </div>
        </form>
    </div>
</div>

<!-- ============ MODAL: ÁREA (crear/editar desde adscripción) ============ -->
<div class="modal-backdrop" id="areaModal">
    <div class="modal" style="max-width: 520px;">
        <div class="modal__header">
            <h2 class="modal__title" id="modalTitleArea">➕ Nueva área</h2>
            <button class="modal__close" onclick="App.closeModal('areaModal')">×</button>
        </div>
        <form id="areaForm">
            <div class="modal__body">
                <input type="hidden" name="id" value="">
                <input type="hidden" name="adscripcion_id" value="">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Clave <span class="req">*</span></label>
                        <input type="text" name="clave" class="form-control" pattern="[a-zA-Z0-9_\-]+" maxlength="30" required>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>