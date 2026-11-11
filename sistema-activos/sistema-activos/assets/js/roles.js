/* =====================================================
   roles.js — CRUD de roles
   ===================================================== */

const form       = document.getElementById('rolForm');
const tablaBody  = document.querySelector('#tablaRoles tbody');
const modal      = document.getElementById('rolModal');
const btnNuevo   = document.getElementById('btnNuevo');
const modalTitle = document.getElementById('modalTitle');
const formError  = document.getElementById('formError');
const busqueda   = document.getElementById('busqueda');
const statsBox   = document.getElementById('stats');

let rolesCache = [];
let modulosCache = [];
const permisosBox = document.getElementById('rolesPermisos');
const areasBox = document.getElementById('rolesAreas');
const areasWrap = document.getElementById('rolesAreasWrap');
let areasCache = [];
function modoAreas() {
    return form.querySelector('input[name="alcance_areas"]:checked')?.value || 'propia';
}
function actualizarVisibilidadAreas() {
    areasWrap.style.display = modoAreas() === 'seleccionadas' ? '' : 'none';
}
function areasSeleccionadas() {
    return [...areasBox.querySelectorAll('input[name="areas_rol"]:checked')].map(input => Number(input.value));
}
function pintarAreas(seleccionadas = []) {
    const ids = seleccionadas.map(Number);
    areasBox.innerHTML = areasCache.map(a => `
        <label class="roles-area">
            <input type="checkbox" name="areas_rol" value="${Number(a.id)}" ${ids.includes(Number(a.id)) ? 'checked' : ''}>
            <span>${App.esc(a.nombre)}<small>${App.esc(a.adscripcion_nombre || 'Sin adscripción')}</small></span>
        </label>`).join('') || '<span class="muted">No hay áreas activas.</span>';
}
form.querySelectorAll('input[name="alcance_areas"]').forEach(el =>
    el.addEventListener('change', actualizarVisibilidadAreas));

function permisosSeleccionados() {
    return [...permisosBox.querySelectorAll('input[name="modulos"]:checked')].map(el => el.value);
}
function accionesSeleccionadas() {
    const data = {};
    permisosBox.querySelectorAll('[data-modulo]').forEach(container => {
        const clave = container.dataset.modulo;
        if (!container.querySelector('input[name="modulos"]')?.checked) return;
        data[clave] = [...container.querySelectorAll('input[name="acciones_rol"]:checked')].map(el => el.value);
    });
    return data;
}
function actualizarAccionesModulo(container) {
    const acceso = container.querySelector('input[name="modulos"]').checked;
    container.querySelectorAll('input[name="acciones_rol"]').forEach(el => {
        el.disabled = !acceso;
        if (!acceso) el.checked = false;
    });
}
permisosBox.addEventListener('change', e => {
    const container = e.target.closest('[data-modulo]');
    if (container && e.target.name === 'modulos') actualizarAccionesModulo(container);
});
function pintarPermisos(seleccionados = [], acciones = {}) {
    // Importar Excel está disponible para todos: no se configura por rol.
    permisosBox.innerHTML = modulosCache.filter(m => m.clave !== 'importar').map(m => {
        const disponible = Array.isArray(m.acciones_disponibles) ? m.acciones_disponibles : [];
        const permitido = seleccionados.includes(m.clave);
        return `<div class="roles-permiso" data-modulo="${App.esc(m.clave)}">
            <label class="roles-permiso__titulo">
                <input type="checkbox" name="modulos" value="${App.esc(m.clave)}" ${permitido ? 'checked' : ''}>
                <span><strong>${App.esc(m.nombre)}</strong><small>${App.esc(m.descripcion || '')}</small></span>
            </label>
            ${disponible.length ? `<div class="roles-permiso__acciones">${disponible.map(a => `
                <label><input type="checkbox" name="acciones_rol" value="${App.esc(a)}"
                    ${permitido && (acciones[m.clave] || []).includes(a) ? 'checked' : ''} ${permitido ? '' : 'disabled'}>
                    ${a === 'crear' ? 'Crear' : a === 'modificar' ? 'Modificar' : 'Eliminar'}</label>`).join('')}</div>`
                : `<small style="display:block;margin:7px 0 0 28px">Módulo de consulta o sin operaciones de escritura delegables.</small>`}
        </div>`;
    }).join('');
}
async function cargarCatalogo() {
    const res = await App.api('roles.php?catalogo=1');
    if (!res.ok) throw new Error(res.error);
    modulosCache = res.data;
    pintarPermisos();
    const rAreas = await App.api('roles.php?catalogo_areas=1');
    if (!rAreas.ok) throw new Error(rAreas.error);
    areasCache = rAreas.data;
    pintarAreas();
}


document.addEventListener('DOMContentLoaded', async () => {
    try { await cargarCatalogo(); await cargar(); } catch (err) { App.toast(err.message, 'error'); btnNuevo.disabled = true; }
});

async function cargar() {
    tablaBody.innerHTML = '<tr><td colspan="7" class="loading"><span class="spinner"></span> Cargando...</td></tr>';
    try {
        const res = await App.api('roles.php');
        if (!res.ok) throw new Error(res.error);
        rolesCache = res.data;
        render(rolesCache);
        cargarStats();
    } catch (err) {
        tablaBody.innerHTML = `<tr><td colspan="7" class="empty-state">${App.esc(err.message)}</td></tr>`;
        App.toast(err.message, 'error');
    }
}

function cargarStats() {
    statsBox.innerHTML = `
        <div class="stat">
            <span class="stat__icon">
            <img src="../assets/img/icons/Roles.png" class="stat__img" alt=""></span>
            <div>
                <div class="stat__num">${rolesCache.length}</div>
                <div class="stat__lbl">Roles definidos</div>
            </div>
        </div>
    `;
}

function render(lista) {
    if (!lista.length) {
        tablaBody.innerHTML = '<tr><td colspan="7"><div class="empty-state"><div class="empty-state__icon">🛡️</div>No hay roles definidos.</div></td></tr>';
        return;
    }
    tablaBody.innerHTML = lista.map(r => {
        const esSistema = (r.clave === 'superusuario' || r.clave === 'usuario');
        const estadoBadge = [true,'t','1',1].includes(r.activo)
            ? '<span class="badge badge--active">Activo</span>'
            : '<span class="badge badge--inactive">Inactivo</span>';
        const badgeClave = esSistema
            ? `<span class="badge badge--super">${App.esc(r.clave)}</span>`
            : `<code>${App.esc(r.clave)}</code>`;
        return `
            <tr>
                <td>${r.id}</td>
                <td>${badgeClave}</td>
                <td><strong>${App.esc(r.nombre)}</strong></td>
                <td>${r.descripcion ? App.esc(r.descripcion) : '<span class="muted">—</span>'}</td>
                <td><span class="badge">${r.usuarios_count}</span></td>
                <td>${estadoBadge}</td>
                <td>
                    <div class="table__actions">
                        ${r.clave === 'superusuario' ? '<span class="muted">Acceso total</span>' : window.canRoleAction('roles','modificar') ? `<button class="btn btn--ghost btn--sm" onclick="editar(${r.id})" title="Editar rol y permisos">✏️</button>` : ''}
                        ${esSistema || !window.canRoleAction('roles','eliminar') ? '' : `<button class="btn btn--danger btn--sm" onclick="eliminar(${r.id}, '${App.esc(r.nombre).replace(/'/g, "\\'")}')">🗑️</button>`}
                    </div>
                </td>
            </tr>
        `;
    }).join('');
}

busqueda.addEventListener('input', () => {
    const q = busqueda.value.toLowerCase().trim();
    if (!q) { render(rolesCache); return; }
    render(rolesCache.filter(r =>
        r.clave.toLowerCase().includes(q) || r.nombre.toLowerCase().includes(q)
    ));
});

if (!window.canRoleAction('roles','crear')) btnNuevo.style.display = 'none';
btnNuevo.addEventListener('click', () => {
    form.reset();
    form.elements['id'].value = '';
    form.activo.checked = true;
    pintarPermisos();
    pintarAreas();
    actualizarVisibilidadAreas();
    modalTitle.textContent = '➕ Nuevo rol';
    formError.style.display = 'none';
    App.openModal('rolModal');
});

window.editar = async (id) => {
    try {
        const res = await App.api(`roles.php?id=${id}`);
        if (!res.ok) throw new Error(res.error);
        // No usar fillForm: también sobrescribe el value de los radios de alcance.
        form.elements['clave'].value = res.data.clave || '';
        form.elements['nombre'].value = res.data.nombre || '';
        form.elements['descripcion'].value = res.data.descripcion || '';
        form.elements['id'].value = id;
        form.activo.checked = [true, 't', '1', 1].includes(res.data.activo);
        pintarPermisos(res.data.permisos || [], res.data.acciones || {});
        const modo = res.data.alcance_areas === 'seleccionadas' ? 'seleccionadas' : 'propia';
        const radio = form.querySelector(`input[name="alcance_areas"][value="${modo}"]`);
        if (radio) radio.checked = true;
        pintarAreas(res.data.areas || []);
        actualizarVisibilidadAreas();
        modalTitle.textContent = '✏️ Editar rol';
        formError.style.display = 'none';
        App.openModal('rolModal');
    } catch (err) {
        App.toast(err.message, 'error');
    }
};

window.eliminar = async (id, nombre) => {
    if (!await App.confirm(`¿Eliminar el rol "${nombre}"?\nSolo es posible si no hay usuarios con este rol.`)) return;
    try {
        const res = await App.api(`roles.php?id=${id}`, { method: 'DELETE' });
        if (!res.ok) throw new Error(res.error);
        App.toast(res.message, 'success');
        cargar();
    } catch (err) {
        App.toast(err.message, 'error');
    }
};

form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const id = form.elements['id'].value;
    const data = {
        clave:       form.clave.value.trim(),
        nombre:      form.nombre.value.trim(),
        descripcion: form.descripcion.value.trim(),
        activo:      form.activo.checked,
        permisos:    permisosSeleccionados(),
        acciones:    accionesSeleccionadas(),
        alcance_areas: modoAreas(),
        areas:       areasSeleccionadas(),
    };
    if (data.clave.length < 2)  return mostrarError('La clave es obligatoria (mín. 2 caracteres)');
    if (data.nombre.length < 2) return mostrarError('El nombre es obligatorio');
    if (data.alcance_areas === 'seleccionadas' && data.areas.length === 0)
        return mostrarError('Selecciona al menos un área para este rol');

    const method = id ? 'PUT' : 'POST';
    const url    = id ? `roles.php?id=${id}` : 'roles.php';

    try {
        const res = await App.api(url, { method, body: data });
        if (!res.ok) throw new Error(res.error);
        App.toast(res.message, 'success');
        App.closeModal('rolModal');
        cargar();
    } catch (err) {
        mostrarError(err.message);
    }
});

function mostrarError(msg) {
    formError.textContent = msg;
    formError.style.display = 'block';
}