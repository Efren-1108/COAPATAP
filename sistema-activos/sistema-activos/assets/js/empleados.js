/* =====================================================
   empleados.js — Módulo combinado: Áreas + Empleados
   ===================================================== */

// ============ REFERENCIAS GLOBALES ============
const listaAreas          = document.getElementById('listaAreas');
const tablaEmpleados      = document.querySelector('#tablaEmpleados tbody');
const busquedaAreas       = document.getElementById('busquedaAreas');
const busquedaEmpleados   = document.getElementById('busquedaEmpleados');
const btnNuevaArea        = document.getElementById('btnNuevaArea');
const btnNuevoEmpleado    = document.getElementById('btnNuevoEmpleado');

const empleadoForm        = document.getElementById('empleadoForm');
const empleadoModalTitle  = document.getElementById('modalTitleEmpleado');
const formErrorEmpleado   = document.getElementById('formErrorEmpleado');
const selectAreaEmpleado  = document.getElementById('selectAreaEmpleado');

const areaForm            = document.getElementById('areaForm');
const areaModalTitle      = document.getElementById('modalTitleArea');
const formErrorArea       = document.getElementById('formErrorArea');

const areaInlineForm      = document.getElementById('areaInlineForm');
const formErrorAreaInline = document.getElementById('formErrorAreaInline');
const btnNuevaAreaInline  = document.getElementById('btnNuevaAreaInline');

const countAreas          = document.getElementById('countAreas');
const countEmpleados      = document.getElementById('countEmpleados');

// ============ ESTADO ============
let areasCache      = [];
let empleadosCache  = [];
let areasExpandidas = new Set(); // IDs de áreas abiertas

// ============ INIT ============
document.addEventListener('DOMContentLoaded', init);

async function init() {
    wireTabs();
    wireEvents();
    await cargarTodo();
}

async function cargarTodo() {
    try {
        await Promise.all([cargarAreas(), cargarEmpleados()]);
        renderAreas();
        renderEmpleados();
        actualizarContadores();
    } catch (err) {
        App.toast(err.message, 'error');
    }
}

async function cargarAreas() {
    const res = await App.api('areas.php');
    if (!res.ok) throw new Error(res.error);
    areasCache = res.data.filter(a => a.activo);
}

async function cargarEmpleados() {
    const res = await App.api('empleados.php');
    if (!res.ok) throw new Error(res.error);
    empleadosCache = res.data;
}

// ============ TABS ============
function wireTabs() {
    document.querySelectorAll('.tab').forEach(tab => {
        tab.addEventListener('click', () => activarTab(tab.dataset.tab));
    });
}

function activarTab(nombre) {
    document.querySelectorAll('.tab').forEach(t => {
        t.classList.toggle('is-active', t.dataset.tab === nombre);
    });
    document.querySelectorAll('.tab-panel').forEach(p => {
        p.classList.toggle('is-active', p.dataset.panel === nombre);
    });
}

// ============ EVENTOS ============
function wireEvents() {
    btnNuevaArea.addEventListener('click', () => abrirModalArea());
    btnNuevoEmpleado.addEventListener('click', () => abrirModalEmpleado());

    busquedaAreas.addEventListener('input', renderAreas);
    busquedaEmpleados.addEventListener('input', renderEmpleados);

    areaForm.addEventListener('submit', onSubmitArea);
    empleadoForm.addEventListener('submit', onSubmitEmpleado);
    areaInlineForm.addEventListener('submit', onSubmitAreaInline);

    btnNuevaAreaInline.addEventListener('click', () => {
        areaInlineForm.reset();
        formErrorAreaInline.style.display = 'none';
        App.openModal('areaInlineModal');
    });

    // Delegación de clicks en los cards de áreas
    listaAreas.addEventListener('click', onAreaCardClick);
}

// ============ RENDER: ÁREAS ============
function renderAreas() {
    const q = (busquedaAreas.value || '').toLowerCase().trim();
    const filtradas = q
        ? areasCache.filter(a =>
            a.clave.toLowerCase().includes(q) ||
            a.nombre.toLowerCase().includes(q))
        : areasCache;

    if (!filtradas.length) {
        listaAreas.innerHTML = `
            <div class="card empty-state">
                <div class="empty-state__icon">🏢</div>
                <p>No hay áreas registradas. Pulsa "Nueva área" para empezar.</p>
            </div>
        `;
        return;
    }

    listaAreas.innerHTML = filtradas.map(a => renderAreaCard(a)).join('');
}

function renderAreaCard(area) {
    const empleadosArea = empleadosCache.filter(e => e.area_id === area.id);
    const isOpen = areasExpandidas.has(area.id);
    const numEmp = empleadosArea.length;

    const empleadosHTML = numEmp === 0
        ? `<div class="area-card__empty">Sin empleados asignados a esta área.</div>`
        : `<div class="area-card__employees">
            ${empleadosArea.map(e => `
                <div class="employee-row">
                    <div class="employee-row__info">
                        <span class="employee-row__nomina">[${App.esc(e.numero_nomina)}]</span>
                        <span class="employee-row__name">${App.esc(e.nombre)}</span>
                        <span class="employee-row__cargo">${App.esc(e.cargo)}</span>
                    </div>
                    <div class="employee-row__actions">
                        <button class="btn btn--ghost btn--sm" data-action="editar-empleado" data-id="${e.id}" title="Editar">✏️</button>
                        <button class="btn btn--danger btn--sm" data-action="eliminar-empleado" data-id="${e.id}" data-nombre="${App.esc(e.nombre)}" title="Eliminar">🗑️</button>
                    </div>
                </div>
            `).join('')}
        </div>`;

    const descripcionHTML = area.descripcion
        ? `<div class="area-card__desc">${App.esc(area.descripcion)}</div>`
        : '';

    return `
        <div class="area-card ${isOpen ? 'is-open' : ''}" data-area-id="${area.id}">
            <div class="area-card__header" data-action="toggle-area">
                <div class="area-card__info">
                    <span class="area-card__icon">🏢</span>
                    <div class="area-card__body">
                        <h3 class="area-card__name">${App.esc(area.nombre)}</h3>
                        <div class="area-card__meta">
                            <code>${App.esc(area.clave)}</code>
                            <span class="badge">${numEmp} empleado${numEmp === 1 ? '' : 's'}</span>
                        </div>
                    </div>
                </div>
                <div class="area-card__actions" data-action="stop">
                    <button class="btn btn--primary btn--sm" data-action="nuevo-empleado-area" data-area-id="${area.id}" title="Agregar empleado a esta área">➕ Empleado</button>
                    <button class="btn btn--ghost btn--sm" data-action="editar-area" data-id="${area.id}" title="Editar área">✏️</button>
                    ${area.clave !== 'general'
                        ? `<button class="btn btn--danger btn--sm" data-action="eliminar-area" data-id="${area.id}" data-nombre="${App.esc(area.nombre)}" title="Eliminar área">🗑️</button>`
                        : ''}
                    <button class="area-card__toggle" data-action="toggle-area" title="Expandir/Contraer">▾</button>
                </div>
            </div>
            <div class="area-card__content">
                ${descripcionHTML}
                ${empleadosHTML}
                <div class="area-card__footer">
                    <button class="btn btn--primary btn--sm" data-action="nuevo-empleado-area" data-area-id="${area.id}">➕ Agregar empleado</button>
                </div>
            </div>
        </div>
    `;
}

function onAreaCardClick(e) {
    const target = e.target.closest('[data-action]');
    if (!target) return;

    const action = target.dataset.action;
    const areaCard = e.target.closest('.area-card');
    const areaId = areaCard ? parseInt(areaCard.dataset.areaId, 10) : null;

    // Si el click fue en el header pero dentro de .area-card__actions, ignorar toggle
    const inActions = e.target.closest('.area-card__actions');
    if (action === 'toggle-area' && inActions) return;

    switch (action) {
        case 'toggle-area':
            if (areaId) {
                if (areasExpandidas.has(areaId)) {
                    areasExpandidas.delete(areaId);
                } else {
                    areasExpandidas.add(areaId);
                }
                renderAreas();
            }
            break;

        case 'nuevo-empleado-area':
            abrirModalEmpleado(null, parseInt(target.dataset.areaId, 10));
            break;

        case 'editar-area':
            editarArea(parseInt(target.dataset.id, 10));
            break;

        case 'eliminar-area':
            eliminarArea(parseInt(target.dataset.id, 10), target.dataset.nombre);
            break;

        case 'editar-empleado':
            editarEmpleado(parseInt(target.dataset.id, 10));
            break;

        case 'eliminar-empleado':
            eliminarEmpleado(parseInt(target.dataset.id, 10), target.dataset.nombre);
            break;
    }
}

// ============ RENDER: EMPLEADOS (tabla plana) ============
function renderEmpleados() {
    const q = (busquedaEmpleados.value || '').toLowerCase().trim();
    const filtrados = q
        ? empleadosCache.filter(e =>
            String(e.numero_nomina).includes(q) ||
            e.nombre.toLowerCase().includes(q) ||
            e.cargo.toLowerCase().includes(q) ||
            (e.area_nombre || '').toLowerCase().includes(q))
        : empleadosCache;

    if (!filtrados.length) {
        tablaEmpleados.innerHTML = `
            <tr><td colspan="5">
                <div class="empty-state">
                    <div class="empty-state__icon">📭</div>
                    No hay empleados. Pulsa "Nuevo empleado" para empezar.
                </div>
            </td></tr>
        `;
        return;
    }

    tablaEmpleados.innerHTML = filtrados.map(e => `
        <tr>
            <td><strong>${App.esc(e.numero_nomina)}</strong></td>
            <td>${App.esc(e.nombre)}</td>
            <td>${App.esc(e.cargo)}</td>
            <td>${e.area_nombre ? '🏢 ' + App.esc(e.area_nombre) : '<span class="muted">— sin área —</span>'}</td>
            <td style="text-align: right;">
                <div class="table__actions">
                    <button class="btn btn--ghost btn--sm" onclick="editarEmpleado(${e.id})">✏️</button>
                    <button class="btn btn--danger btn--sm" onclick="eliminarEmpleado(${e.id}, '${App.esc(e.nombre).replace(/'/g, "\\'")}')">🗑️</button>
                </div>
            </td>
        </tr>
    `).join('');
}

function actualizarContadores() {
    countAreas.textContent = areasCache.length;
    countEmpleados.textContent = empleadosCache.length;
}

// ============ MODAL: EMPLEADO ============
function abrirModalEmpleado(id = null, areaIdPredefinida = null) {
    empleadoForm.reset();
    empleadoForm.elements['id'].value = '';
    formErrorEmpleado.style.display = 'none';

    llenarSelectAreas(areaIdPredefinida);

    if (id) {
        empleadoModalTitle.textContent = '✏️ Editar empleado';
        cargarEmpleadoEnForm(id);
    } else {
        empleadoModalTitle.textContent = '➕ Nuevo empleado';
    }

    App.openModal('empleadoModal');
}

function llenarSelectAreas(selectedId = null) {
    const opciones = ['<option value="">— Selecciona un área —</option>']
        .concat(areasCache.map(a =>
            `<option value="${a.id}" ${selectedId === a.id ? 'selected' : ''}>${App.esc(a.nombre)}</option>`
        ));
    selectAreaEmpleado.innerHTML = opciones.join('');
}

async function cargarEmpleadoEnForm(id) {
    try {
        const res = await App.api(`empleados.php?id=${id}`);
        if (!res.ok) throw new Error(res.error);
        const e = res.data;

        empleadoForm.elements['id'].value = e.id;
        empleadoForm.elements['numero_nomina'].value = e.numero_nomina;
        empleadoForm.elements['nombre'].value = e.nombre;
        empleadoForm.elements['cargo'].value = e.cargo;

        // Asegurar que el área esté en el select
        if (e.area_id) {
            // Si el área no está en el select (ej. inactiva), agregarla
            if (!areasCache.find(a => a.id === e.area_id)) {
                const opt = document.createElement('option');
                opt.value = e.area_id;
                opt.textContent = e.area_nombre || ('Área #' + e.area_id);
                selectAreaEmpleado.appendChild(opt);
            }
            selectAreaEmpleado.value = e.area_id;
        } else {
            selectAreaEmpleado.value = '';
        }
    } catch (err) {
        App.toast(err.message, 'error');
        App.closeModal('empleadoModal');
    }
}

async function onSubmitEmpleado(e) {
    e.preventDefault();
    const id = empleadoForm.elements['id'].value;
    const data = {
        numero_nomina: parseInt(empleadoForm.elements['numero_nomina'].value, 10),
        nombre:        empleadoForm.elements['nombre'].value.trim(),
        cargo:         empleadoForm.elements['cargo'].value.trim(),
        area_id:       empleadoForm.elements['area_id'].value || null,
    };

    if (!data.numero_nomina || data.numero_nomina <= 0) return mostrarErrorEmpleado('La nómina debe ser positiva');
    if (data.nombre.length < 3) return mostrarErrorEmpleado('El nombre es obligatorio (mín. 3 caracteres)');
    if (data.cargo.length < 2)  return mostrarErrorEmpleado('El cargo es obligatorio');
    if (!data.area_id)          return mostrarErrorEmpleado('Selecciona un área');

    const method = id ? 'PUT' : 'POST';
    const url    = id ? `empleados.php?id=${id}` : 'empleados.php';

    try {
        const res = await App.api(url, { method, body: data });
        if (!res.ok) throw new Error(res.error);
        App.toast(res.message, 'success');
        App.closeModal('empleadoModal');
        await cargarEmpleados();
        renderAreas();
        renderEmpleados();
        actualizarContadores();
    } catch (err) {
        mostrarErrorEmpleado(err.message);
    }
}

function mostrarErrorEmpleado(msg) {
    formErrorEmpleado.textContent = msg;
    formErrorEmpleado.style.display = 'block';
}

// ============ MODAL: ÁREA ============
function abrirModalArea(id = null) {
    areaForm.reset();
    areaForm.elements['id'].value = '';
    areaForm.elements['activo'].checked = true;
    formErrorArea.style.display = 'none';

    if (id) {
        areaModalTitle.textContent = '✏️ Editar área';
        cargarAreaEnForm(id);
    } else {
        areaModalTitle.textContent = '➕ Nueva área';
    }

    App.openModal('areaModal');
}

async function cargarAreaEnForm(id) {
    try {
        const res = await App.api(`areas.php?id=${id}`);
        if (!res.ok) throw new Error(res.error);
        const a = res.data;
        areaForm.elements['id'].value = a.id;
        areaForm.elements['clave'].value = a.clave;
        areaForm.elements['nombre'].value = a.nombre;
        areaForm.elements['descripcion'].value = a.descripcion || '';
        areaForm.elements['activo'].checked = !!a.activo;
    } catch (err) {
        App.toast(err.message, 'error');
        App.closeModal('areaModal');
    }
}

async function onSubmitArea(e) {
    e.preventDefault();
    const id = areaForm.elements['id'].value;
    const data = {
        clave:       areaForm.elements['clave'].value.trim(),
        nombre:      areaForm.elements['nombre'].value.trim(),
        descripcion: areaForm.elements['descripcion'].value.trim(),
        activo:      areaForm.elements['activo'].checked,
    };

    if (data.clave.length < 2)  return mostrarErrorArea('La clave es obligatoria');
    if (data.nombre.length < 2) return mostrarErrorArea('El nombre es obligatorio');

    const method = id ? 'PUT' : 'POST';
    const url    = id ? `areas.php?id=${id}` : 'areas.php';

    try {
        const res = await App.api(url, { method, body: data });
        if (!res.ok) throw new Error(res.error);
        App.toast(res.message, 'success');
        App.closeModal('areaModal');
        await cargarAreas();
        renderAreas();
        actualizarContadores();
    } catch (err) {
        mostrarErrorArea(err.message);
    }
}

function mostrarErrorArea(msg) {
    formErrorArea.textContent = msg;
    formErrorArea.style.display = 'block';
}

// ============ MODAL: ÁREA INLINE (desde empleado) ============
async function onSubmitAreaInline(e) {
    e.preventDefault();
    const data = {
        clave:  areaInlineForm.elements['clave'].value.trim(),
        nombre: areaInlineForm.elements['nombre'].value.trim(),
        activo: true,
    };

    if (data.clave.length < 2)  return mostrarErrorAreaInline('La clave es obligatoria');
    if (data.nombre.length < 2) return mostrarErrorAreaInline('El nombre es obligatorio');

    try {
        const res = await App.api('areas.php', { method: 'POST', body: data });
        if (!res.ok) throw new Error(res.error);

        App.toast('Área creada. Ahora puedes asignarla.', 'success');
        App.closeModal('areaInlineModal');

        await cargarAreas();
        // Seleccionar la nueva área en el select del empleado        selectAreaEmpleado.value = res.id;
        renderAreas();
        actualizarContadores();
    } catch (err) {
        mostrarErrorAreaInline(err.message);
    }
}

function mostrarErrorAreaInline(msg) {
    formErrorAreaInline.textContent = msg;
    formErrorAreaInline.style.display = 'block';
}

// ============ ACCIONES: ÁREA ============
function editarArea(id) {
    abrirModalArea(id);
}

async function eliminarArea(id, nombre) {
    if (!await App.confirm(`¿Eliminar el área "${nombre}"?\nSolo es posible si no tiene empleados ni usuarios asignados.`)) return;
    try {
        const res = await App.api(`areas.php?id=${id}`, { method: 'DELETE' });
        if (!res.ok) throw new Error(res.error);
        App.toast(res.message, 'success');
        await cargarTodo();
    } catch (err) {
        App.toast(err.message, 'error');
    }
}

// ============ ACCIONES: EMPLEADO ============
function editarEmpleado(id) {
    abrirModalEmpleado(id);
}

async function eliminarEmpleado(id, nombre) {
    if (!await App.confirm(`¿Eliminar a "${nombre}"?\nLos bienes asignados quedarán sin resguardante.`)) return;
    try {
        const res = await App.api(`empleados.php?id=${id}`, { method: 'DELETE' });
        if (!res.ok) throw new Error(res.error);
        App.toast(res.message, 'success');
        await cargarEmpleados();
        renderAreas();
        renderEmpleados();
        actualizarContadores();
    } catch (err) {
        App.toast(err.message, 'error');
    }
}

// ============ EXPONER AL WINDOW (para onclick inline de la tabla) ============
window.editarEmpleado = editarEmpleado;
window.eliminarEmpleado = eliminarEmpleado;