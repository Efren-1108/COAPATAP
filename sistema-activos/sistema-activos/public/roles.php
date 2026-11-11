<?php
$page_title = 'Gestión de Roles';
$page_js    = 'roles.js';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
$user = require_permission('roles');
require_once __DIR__ . '/../includes/header.php';
?>

<section class="page-header">
    <div>
        <h1>Gestión de Roles</h1>
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
    <div class="modal" style="max-width: 760px;">
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
                        <label>Permisos de acceso a módulos</label>
                        <p class="form-text" style="margin:0 0 8px">Para cada módulo, permite la consulta y, cuando corresponda, Crear, Modificar o Eliminar. En «Empleados y Áreas», las tres acciones se aplican a empleados. La gestión de áreas, los movimientos globales de Stock, Adscripciones e Importar Excel siguen reservados al Superusuario.</p>
                        <div class="roles-permisos" id="rolesPermisos" role="group" aria-label="Permisos de acceso">
                            Cargando módulos...
                        </div>
                    </div>
                    <div class="form-group form-group--full">
                        <label>Áreas que podrá consultar este rol</label>
                        <p class="form-text" style="margin:0 0 10px">Elige el alcance de lectura. Los permisos para modificar información continúan limitados al área propia del usuario.</p>
                        <label class="roles-modo-area"><input type="radio" name="alcance_areas" value="propia" checked> Solo el área asignada a cada usuario</label>
                        <label class="roles-modo-area"><input type="radio" name="alcance_areas" value="seleccionadas"> Áreas específicas para todos los usuarios de este rol</label>
                        <div id="rolesAreasWrap" style="display:none" class="roles-areas-wrap">
                            <div class="roles-areas" id="rolesAreas" role="group" aria-label="Áreas permitidas">Cargando áreas...</div>
                        </div>
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

<style>
.roles-permisos {display:grid;grid-template-columns:1fr;gap:10px;}
.roles-permiso {display:flex;align-items:flex-start;gap:10px;padding:12px;border:1px solid var(--c-border);border-radius:10px;cursor:pointer;background:var(--c-surface);}
.roles-permiso:hover {border-color:var(--c-primary,#2563eb);}
.roles-permiso input {width:18px;height:18px;flex:none;margin-top:2px;accent-color:#2563eb;}
.roles-permiso strong {display:block;font-size:.92rem;}
.roles-permiso small {display:block;color:var(--c-muted);font-size:.8rem;line-height:1.4;margin-top:4px;}
.roles-modo-area {display:flex;align-items:center;gap:8px;margin:9px 0;cursor:pointer;font-size:.92rem;}
.roles-modo-area input {width:17px;height:17px;accent-color:#2563eb;}
.roles-areas-wrap {margin-top:12px;padding:12px;background:var(--c-surface);border:1px solid var(--c-border);border-radius:10px;max-height:280px;overflow-y:auto;}
.roles-areas {display:grid;grid-template-columns:repeat(auto-fit,minmax(235px,1fr));gap:8px;}
.roles-area {display:flex;gap:8px;align-items:flex-start;font-size:.9rem;cursor:pointer;}
.roles-area input {flex-shrink:0;width:17px;height:17px;margin-top:2px;accent-color:#2563eb;}
.roles-area small {display:block;color:var(--c-muted);font-size:.8rem;}

.roles-permiso {display:block; cursor:default;}
.roles-permiso__titulo {display:flex;align-items:flex-start;gap:10px;cursor:pointer;}
.roles-permiso__acciones {display:flex;flex-wrap:wrap;gap:8px 17px;margin:10px 0 0 28px;border-top:1px solid var(--c-border);padding-top:10px;}
.roles-permiso__acciones label {display:flex;align-items:center;gap:6px;font-size:.86rem;cursor:pointer;}
.roles-permiso__acciones input {width:16px;height:16px;margin:0;}
.roles-permiso__acciones input:disabled {opacity:.45;cursor:not-allowed;}
</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
