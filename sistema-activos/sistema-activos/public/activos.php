<?php
$page_title = 'Gestión de Activos Fijos';
$page_js    = 'activos.js';
$page_js_version = 'diagnostico-inservibles-20261110-1';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
$user = require_permission('activos');
require_once __DIR__ . '/../includes/header.php';
?>

<section class="page-header">
    <div>
        <h1>Gestión de Activos Fijos</h1>
    </div>
    <button class="btn btn--primary" id="btnNuevo">➕ Nuevo activo</button>
</section>

<!-- ============ TABS ============ -->
<div class="tabs tabs--activos" role="tablist">
    <button class="tab is-active" data-tab="adscripciones" role="tab">
        🏛️ Por adscripción <span class="tab__count" id="countAdsc">0</span>
    </button>
    <button class="tab" data-tab="areas" role="tab">
        🏢 Por área <span class="tab__count" id="countAreas">0</span>
    </button>
    <button class="tab" data-tab="empleados" role="tab">
        👥 Por empleado <span class="tab__count" id="countEmpleados">0</span>
    </button>
    <button class="tab" data-tab="todos" role="tab">
        📦 Todos <span class="tab__count" id="countTodos">0</span>
    </button>
    <button class="tab" data-tab="inservibles" role="tab">🚫 Inservibles <span class="tab__count" id="countInservibles">0</span></button>
    <button class="tab" data-tab="stats" role="tab">
        📊 Estadísticas
    </button>
</div>

<!-- ============ FILTROS GLOBALES ============ -->
<div class="toolbar no-print" id="toolbarActivos">
    <input type="text" id="busqueda" placeholder="🔎 Buscar por descripción, inventario, marca, modelo, serie...">
    <select id="filtroAdscripcion">
        <option value="">🏛️ Todas las adscripciones</option>
    </select>
    <select id="filtroArea">
        <option value="">🏢 Todas las áreas</option>
    </select>
    <select id="filtroEmpleado">
        <option value="">👤 Todos los empleados</option>
    </select>
    <select id="filtroSituacion" aria-label="Filtrar por situación administrativa">
        <option value="">📋 Vigentes y suspendidos</option>
        <option value="vigentes">✅ Solo vigentes</option>
        <option value="suspendidos">⏸️ Solo suspendidos</option>
    </select>
    <select id="ordenPrecio" aria-label="Ordenar activos por precio" title="Ordenar los activos dentro de cada grupo">
        <option value="">💰 Orden original</option>
        <option value="desc">💰 Mayor a menor precio</option>
        <option value="asc">💰 Menor a mayor precio</option>
    </select>
    <button class="btn btn--ghost btn--sm" id="btnExpandirTodo" title="Expandir todo">⬇ Expandir</button>
    <button class="btn btn--ghost btn--sm" id="btnContraerTodo" title="Contraer todo">⬆ Contraer</button>
</div>

<!-- ============ PANEL: POR ADSCRIPCIÓN ============ -->
<div class="tab-panel is-active" id="panelAdscripciones" data-panel="adscripciones">
    <div id="listaAdscConActivos"></div>
</div>

<!-- ============ PANEL: POR ÁREA ============ -->
<div class="tab-panel" id="panelAreas" data-panel="areas">
    <div id="listaAreasConActivos"></div>
</div>

<!-- ============ PANEL: POR EMPLEADO ============ -->
<div class="tab-panel" id="panelEmpleados" data-panel="empleados">
    <div id="listaEmpleadosConActivos"></div>
</div>

<style>
/* Tabla Todos: un único botón visible por activo; acciones completas al desplegar. */
#tablaActivos { min-width: 0; width: 100%; }
#tablaActivos th:last-child, #tablaActivos td:last-child { width: 190px; min-width: 190px; text-align: center; }
#tablaActivos .acciones-celda { overflow: visible; }
#tablaActivos .acciones-menu { position: relative; display: inline-block; vertical-align: top; }
#tablaActivos .acciones-menu > summary { display: inline-flex; align-items: center; justify-content: center; cursor: pointer; list-style: none; white-space: nowrap; min-width: 105px; }
#tablaActivos .acciones-menu > summary::-webkit-details-marker { display: none; }
#tablaActivos .acciones-menu__opciones {
    display: flex; flex-direction: column; align-items: stretch; gap: 6px;
    position: static; z-index: auto; margin-top: 8px;
    min-width: 170px; padding: 10px; background: var(--surface, #fff);
    color: #222; border: 1px solid #cbd5e1; border-radius: 10px;
    box-shadow: 0 8px 22px rgba(0,0,0,.18);
}
#tablaActivos .acciones-menu:not([open]) .acciones-menu__opciones { display: none; }
#tablaActivos .acciones-menu__opciones > button { width: 100%; min-width: 0; white-space: nowrap; text-align: center; justify-content: center; }
#panelTodos .table-wrap { overflow-x: auto; }
</style>
<style>
.coapatap-dialog-overlay{position:fixed;inset:0;background:rgba(15,23,42,.58);z-index:99999;display:flex;align-items:center;justify-content:center;padding:18px}
.coapatap-dialog{background:#fff;color:#17243b;width:min(100%,530px);max-height:90vh;overflow:auto;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.25);font-family:inherit}
.coapatap-dialog-header{display:flex;align-items:center;justify-content:space-between;gap:15px;padding:20px 24px;border-bottom:1px solid #e2e8f0}
.coapatap-dialog-header h2{font-size:1.2rem;margin:0;color:#13284b}
.coapatap-dialog-x{background:none;border:0;font-size:27px;cursor:pointer;color:#64748b}
.coapatap-dialog-body{padding:20px 24px}
.coapatap-dialog-body p{margin:0 0 16px;line-height:1.55}
.coapatap-dialog-label{display:block;font-weight:600;margin-bottom:8px}
.coapatap-dialog-input{box-sizing:border-box;width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:9px;background:#fff;color:#17243b;font:inherit}
.coapatap-dialog-input:focus{outline:2px solid #2563eb;border-color:#2563eb}
.coapatap-dialog-invalid{border-color:#dc2626}
.coapatap-dialog-footer{display:flex;justify-content:flex-end;gap:10px;padding:15px 24px 20px;border-top:1px solid #e2e8f0}
.coapatap-dialog-history{max-height:350px;overflow:auto}
.coapatap-dialog-history-row{padding:12px 0;border-bottom:1px solid #e2e8f0}
.coapatap-dialog-history-row strong{color:#2563eb}
.coapatap-dialog-history-row small{display:block;color:#64748b;margin-top:3px}
.coapatap-dialog-history-row p{margin:6px 0 0}
</style>


<!-- ============ PANEL: TODOS ============ -->
<div class="tab-panel" id="panelTodos" data-panel="todos">
    <div id="statsResumen" class="stats"></div>
    <div class="table-wrap">
        <table class="table" id="tablaActivos">
            <thead>
                <tr>
                    <th>Descripción</th>
                    <th>Estado físico</th>
                    <th>Adscripción</th>
                    <th>Área</th>
                    <th>Asignado a</th>
                    <th>Foto</th>
                    <th class="no-print" style="text-align: right;">Acciones</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<div class="tab-panel" id="panelInservibles" data-panel="inservibles">
  <div class="card"><h2 class="card__title">🚫 Bienes declarados inservibles</h2>
  <p class="muted">Estos bienes se conservan en la base de datos, sin asignación activa.</p>
  <div class="table-wrap"><table class="table"><thead><tr><th>Activo</th><th>Último responsable</th><th>Última área</th><th>Motivo</th><th>Fecha</th><th>Acciones</th></tr></thead><tbody id="tablaInservibles"></tbody></table></div></div>
</div>
<!-- ============ PANEL: ESTADÍSTICAS ============ -->
<div class="tab-panel" id="panelStats" data-panel="stats">
    <div id="statsDashboard"></div>
</div>

<style>#grupoMotivoInservible{padding:12px;border:1px solid #fca5a5;border-radius:10px;background:rgba(220,38,38,.04);margin-top:0}</style>
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
                    
                    <!-- ==========================================
                        ESTADO FÍSICO DEL ACTIVO
                    ========================================== -->

                    <div class="form-group">

                        <label for="estado_fisico">
                            Estado físico
                            <span class="req">*</span>
                        </label>

                        <select
                            name="estado_fisico"
                            id="estado_fisico"
                            class="form-control"
                            required
                        >
                            <option value="">
                                — Selecciona el estado físico —
                            </option>

                            <option value="BUENO">
                                BUENO
                            </option>

                            <option value="NUEVO">
                                NUEVO
                            </option>

                            <option value="REGULAR">
                                REGULAR
                            </option>

                            <option value="INSERVIBLE">
                                INSERVIBLE
                            </option>
                        </select>

                        <span class="form-text">
                            Selecciona la condición física actual del bien.
                            Si el Excel dejó el estado pendiente, al editar puedes conservarlo vacío.
                        </span>

                    </div>

                    <!-- Motivo a todo el ancho, en una fila independiente. -->
                    <div id="grupoMotivoInservible" class="form-group form-group--full" style="display:none">
                        <label for="motivo_inservible">Motivo de declaración de inservibilidad <span class="req">*</span></label>
                        <textarea class="form-control" id="motivo_inservible" name="motivo_inservible" maxlength="1000" rows="3" placeholder="Explica por qué se declaró inservible"></textarea>
                        <small>Se retirará la asignación actual y se conservará el último responsable.</small>
                        <!-- Solo visible al editar un bien que YA estaba declarado inservible. -->
                        <div id="grupoNumeroDiagnostico" style="display:none; margin-top:12px; max-width:360px;">
                            <label for="numero_diagnostico">N° de diagnóstico</label>
                            <input type="text" id="numero_diagnostico" name="numero_diagnostico" class="form-control"
                                   maxlength="100" placeholder="Ej. DIAG-2026-001" autocomplete="off">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Fecha de adquisición (opcional)</label>
                        <input type="date" name="fecha_adq" class="form-control">
                        <span class="form-text">Si el Excel no indica una fecha válida, puedes dejarla pendiente.</span>
                    </div>
                    <div class="form-group">
                        <label>Número de factura</label>
                        <input type="text" name="factura" class="form-control" maxlength="100">
                    </div>
                    <div class="form-group">
                        <label>Costo de adquisición (MXN) <span class="req">*</span></label>
                        <input type="number" name="costo" class="form-control" min="0" step="0.01" required placeholder="0.00">
                        <span class="form-text">En activos importados con costo pendiente, puedes editar otros datos sin inventar un importe.</span>
                    </div>
                    <div class="form-group form-group--full" id="grupoCrearStock">
                        <label><input type="checkbox" id="crear_en_stock" name="crear_en_stock" value="1"> 📦 Registrar directamente en stock (Almacén general)</label>
                        <span class="form-text">Solo para activos nuevos. No se asignará empleado ni área.</span>
                    </div>
                    <div class="form-group">
                        <label>Asignado a (Resguardante)</label>
                        <select name="empleado_id" id="empleado_id" class="form-control">
                            <option value="">— Sin asignar —</option>
                        </select>
                        <span class="form-text" id="hintAdscActivo"></span>
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