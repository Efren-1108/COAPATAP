/* =====================================================
   empleados.js — Módulo combinado: Áreas + Empleados
   Con soporte de Adscripciones (jerarquía)
   ===================================================== */

// ============ REFERENCIAS ============
const listaAreas          = document.getElementById('listaAreas');
const tablaEmpleados      = document.querySelector('#tablaEmpleados tbody');
const busquedaAreas       = document.getElementById('busquedaAreas');
const busquedaEmpleados   = document.getElementById('busquedaEmpleados');
const filtroAdscAreas     = document.getElementById('filtroAdscripcionAreas');
const filtroAdscEmp       = document.getElementById('filtroAdscripcionEmpleados');
const filtroAreaEmp       = document.getElementById('filtroAreaEmpleados');
const btnNuevaArea        = document.getElementById('btnNuevaArea');
const btnNuevoEmpleado    = document.getElementById('btnNuevoEmpleado');

const empleadoForm        = document.getElementById('empleadoForm');
const empleadoModalTitle  = document.getElementById('modalTitleEmpleado');
const formErrorEmpleado   = document.getElementById('formErrorEmpleado');
const selectAreaEmpleado  = document.getElementById('selectAreaEmpleado');
const hintAdscEmpleado    = document.getElementById('hintAdscripcionEmpleado');

const areaForm            = document.getElementById('areaForm');
const areaModalTitle      = document.getElementById('modalTitleArea');
const formErrorArea       = document.getElementById('formErrorArea');
const selectAdscArea      = document.getElementById('selectAdscripcionArea');

const areaInlineForm      = document.getElementById('areaInlineForm');
const formErrorAreaInline = document.getElementById('formErrorAreaInline');
const btnNuevaAreaInline  = document.getElementById('btnNuevaAreaInline');
const selectAdscInline    = document.getElementById('selectAdscripcionInline');

const countAreas          = document.getElementById('countAreas');
const countEmpleados      = document.getElementById('countEmpleados');

// ============ ESTADO ============
let adscripcionesCache = [];
let areasCache         = [];
let empleadosCache     = [];
let areasExpandidas    = new Set();

// ============ INIT ============
document.addEventListener('DOMContentLoaded', init);

async function init() {
    wireTabs();
    wireEvents();
    await cargarTodo();
}

async function cargarTodo() {
    try {
        const [resAdsc, resAreas, resEmp] = await Promise.all([
            App.api('adscripciones.php'),
            App.api('areas.php'),
            App.api('empleados.php'),
        ]);
        if (!resAdsc.ok)  throw new Error(resAdsc.error);
        if (!resAreas.ok) throw new Error(resAreas.error);
        if (!resEmp.ok)   throw new Error(resEmp.error);

        adscripcionesCache = resAdsc.data.filter(a => a.activo);
        areasCache         = resAreas.data.filter(a => a.activo);
        empleadosCache     = resEmp.data;

        llenarFiltros();
        renderAreas();
        renderEmpleados();
        actualizarContadores();
    } catch (err) {
        App.toast(err.message, 'error');
    }
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
    filtroAdscAreas.addEventListener('change', renderAreas);
    filtroAdscEmp.addEventListener('change', renderEmpleados);
    filtroAreaEmp.addEventListener('change', renderEmpleados);

    areaForm.addEventListener('submit', onSubmitArea);
    empleadoForm.addEventListener('submit', onSubmitEmpleado);
    areaInlineForm.addEventListener('submit', onSubmitAreaInline);

    btnNuevaAreaInline.addEventListener('click', () => {
        areaInlineForm.reset();
        formErrorAreaInline.style.display = 'none';
        llenarSelectAdscripciones(selectAdscInline, null);
        App.openModal('areaInlineModal');
    });

    // Al cambiar el área en el modal de empleado, mostrar la adscripción
    selectAreaEmpleado.addEventListener('change', actualizarHintAdscEmpleado);

    listaAreas.addEventListener('click', onAreaCardClick);
    // Eventos delegados: no generar JavaScript incrustado con nombres personales.
    tablaEmpleados.addEventListener('click', onTablaEmpleadoClick);
}

// ============ LLENAR FILTROS Y SELECTS ============
function llenarFiltros() {
    // Filtro de adscripciones para áreas
    const optsAdsc = ['<option value="">🏛️ Todas las adscripciones</option>']
        .concat(adscripcionesCache
            .sort((a, b) => a.nombre.localeCompare(b.nombre))
            .map(a => `<option value="${a.id}">${App.esc(a.nombre)}</option>`));
    filtroAdscAreas.innerHTML = optsAdsc.join('');
    filtroAdscEmp.innerHTML = optsAdsc.join('');

    // Filtro de áreas para empleados
    const optsAreas = ['<option value="">🏢 Todas las áreas</option>']
        .concat(areasCache
            .sort((a, b) => a.nombre.localeCompare(b.nombre))
            .map(a => `<option value="${a.id}">${App.esc(a.nombre)}</option>`));
    filtroAreaEmp.innerHTML = optsAreas.join('');
}

function llenarSelectAdscripciones(select, selectedId = null) {
    const opts = ['<option value="">— Selecciona una adscripción —</option>']
        .concat(adscripcionesCache
            .sort((a, b) => a.nombre.localeCompare(b.nombre))
            .map(a => `<option value="${a.id}" ${selectedId === a.id ? 'selected' : ''}>${App.esc(a.nombre)}</option>`));
    select.innerHTML = opts.join('');
}

// ============ RENDER: ÁREAS ============
function renderAreas() {
    const q = (busquedaAreas.value || '').toLowerCase().trim();
    const filtroAdsc = filtroAdscAreas.value ? parseInt(filtroAdscAreas.value, 10) : null;

    let filtradas = areasCache;
    if (filtroAdsc !== null) {
        filtradas = filtradas.filter(a => a.adscripcion_id === filtroAdsc);
    }
    if (q) {
        filtradas = filtradas.filter(a =>
            a.clave.toLowerCase().includes(q) ||
            a.nombre.toLowerCase().includes(q)
        );
    }

    if (!filtradas.length) {
        listaAreas.innerHTML = `
            <div class="card empty-state">
                <div class="empty-state__icon">🏢</div>
                <p>No hay áreas que coincidan.</p>
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

    const adscBadge = area.adscripcion_nombre
        ? `<span class="badge badge--adsc">🏛️ ${App.esc(area.adscripcion_nombre)}</span>`
        : '<span class="badge badge--warning">Sin adscripción</span>';

    const empleadosHTML = numEmp === 0
        ? `<div class="area-card__empty">Sin empleados asignados a esta área.</div>`
        : `<div class="area-card__employees">
            ${empleadosArea.map(e => `
                <div class="employee-row">
                    <div class="employee-row__info">
                        <span class="employee-row__nomina">[${e.numero_nomina == null ? 'Sin nómina' : App.esc(e.numero_nomina)}]</span>
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
                            ${adscBadge}
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

    const inActions = e.target.closest('.area-card__actions');
    if (action === 'toggle-area' && inActions) return;

    switch (action) {
        case 'toggle-area':
            if (areaId) {
                if (areasExpandidas.has(areaId)) areasExpandidas.delete(areaId);
                else areasExpandidas.add(areaId);
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

// Botones de la tabla: leer ID y nombre desde data-* evita errores con apóstrofes.
function onTablaEmpleadoClick(evento) {
    const boton = evento.target.closest('button[data-action]');
    if (!boton || !tablaEmpleados.contains(boton)) return;
    const id = Number(boton.dataset.id);
    if (!Number.isSafeInteger(id) || id <= 0) {
        App.toast('Identificador del empleado inválido', 'error');
        return;
    }
    if (boton.dataset.action === 'editar-empleado') editarEmpleado(id);
    if (boton.dataset.action === 'eliminar-empleado') {
        eliminarEmpleado(id, boton.dataset.nombre || 'este empleado');
    }
}

// ============ RENDER: EMPLEADOS (tabla plana) ============
function renderEmpleados() {
    const q = (busquedaEmpleados.value || '').toLowerCase().trim();
    const filtroAdsc = filtroAdscEmp.value ? parseInt(filtroAdscEmp.value, 10) : null;
    const filtroArea = filtroAreaEmp.value ? parseInt(filtroAreaEmp.value, 10) : null;

    let filtrados = empleadosCache;

    if (filtroAdsc !== null) {
        filtrados = filtrados.filter(e => {
            const area = areasCache.find(a => a.id === e.area_id);
            return area && area.adscripcion_id === filtroAdsc;
        });
    }
    if (filtroArea !== null) {
        filtrados = filtrados.filter(e => e.area_id === filtroArea);
    }
    if (q) {
        filtrados = filtrados.filter(e =>
            (e.numero_nomina == null ? 'sin nómina' : String(e.numero_nomina)).includes(q) ||
            e.nombre.toLowerCase().includes(q) ||
            e.cargo.toLowerCase().includes(q) ||
            (e.area_nombre || '').toLowerCase().includes(q) ||
            (e.adscripcion_nombre || '').toLowerCase().includes(q)
        );
    }

    if (!filtrados.length) {
        tablaEmpleados.innerHTML = `
            <tr><td colspan="6">
                <div class="empty-state">
                    <div class="empty-state__icon">📭</div>
                    No hay empleados que coincidan.
                </div>
            </td></tr>
        `;
        return;
    }

    tablaEmpleados.innerHTML = filtrados.map(e => {
        const adscTxt = e.adscripcion_nombre
            ? `🏛️ ${App.esc(e.adscripcion_nombre)}`
            : '<span class="muted">— sin adscripción —</span>';
        const areaTxt = e.area_nombre
            ? `🏢 ${App.esc(e.area_nombre)}`
            : '<span class="muted">— sin área —</span>';

        return `
            <tr>
                <td><strong>${e.numero_nomina == null ? 'Sin nómina' : App.esc(e.numero_nomina)}</strong></td>
                <td>${App.esc(e.nombre)}</td>
                <td>${App.esc(e.cargo)}</td>
                <td>${adscTxt}</td>
                <td>${areaTxt}</td>
                <td style="text-align: right;">
                    <div class="table__actions">
                        <button type="button" class="btn btn--ghost btn--sm" data-action="editar-empleado" data-id="${e.id}" title="Editar empleado">✏️</button>
                        <button type="button" class="btn btn--danger btn--sm" data-action="eliminar-empleado" data-id="${e.id}" data-nombre="${App.esc(e.nombre)}" title="Eliminar empleado">🗑️</button>
                    </div>
                </td>
            </tr>
        `;
    }).join('');
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
    hintAdscEmpleado.textContent = '';

    llenarSelectAreas(areaIdPredefinida);

    if (id) {
        empleadoModalTitle.textContent = '✏️ Editar empleado';
        cargarEmpleadoEnForm(id);
    } else {
        empleadoModalTitle.textContent = '➕ Nuevo empleado';
        actualizarHintAdscEmpleado();
    }

    App.openModal('empleadoModal');
}

function llenarSelectAreas(selectedId = null) {
    const opts = ['<option value="">— Selecciona un área —</option>']
        .concat(areasCache
            .sort((a, b) => a.nombre.localeCompare(b.nombre))
            .map(a => {
                const adsc = a.adscripcion_nombre ? ` (${a.adscripcion_nombre})` : '';
                return `<option value="${a.id}" ${selectedId === a.id ? 'selected' : ''}>${App.esc(a.nombre)}${adsc}</option>`;
            }));
    selectAreaEmpleado.innerHTML = opts.join('');
}

function actualizarHintAdscEmpleado() {
    const areaId = parseInt(selectAreaEmpleado.value, 10);
    if (!areaId) {
        hintAdscEmpleado.textContent = '';
        return;
    }
    const area = areasCache.find(a => a.id === areaId);
    if (area && area.adscripcion_nombre) {
        hintAdscEmpleado.textContent = `🏛️ Adscripción: ${area.adscripcion_nombre}`;
    } else {
        hintAdscEmpleado.textContent = '⚠️ Esta área no tiene adscripción asignada.';
    }
}

async function cargarEmpleadoEnForm(id) {
    try {
        const res = await App.api(`empleados.php?id=${id}`);
        if (!res.ok) throw new Error(res.error);
        const e = res.data;

        empleadoForm.elements['id'].value = e.id;
        empleadoForm.elements['numero_nomina'].value = e.numero_nomina ?? '';
        empleadoForm.elements['nombre'].value = e.nombre;
        empleadoForm.elements['cargo'].value = e.cargo;

        // Asegurar que el área esté en el select
        if (e.area_id) {
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

        actualizarHintAdscEmpleado();
    } catch (err) {
        App.toast(err.message, 'error');
        App.closeModal('empleadoModal');
    }
}

async function onSubmitEmpleado(e) {
    e.preventDefault();
    const id = empleadoForm.elements['id'].value;
    const data = {
        numero_nomina: empleadoForm.elements['numero_nomina'].value.trim() === ''
            ? null
            : Number(empleadoForm.elements['numero_nomina'].value),
        nombre:        empleadoForm.elements['nombre'].value.trim(),
        cargo:         empleadoForm.elements['cargo'].value.trim(),
        area_id:       empleadoForm.elements['area_id'].value || null,
    };

    if (data.numero_nomina !== null && (
        !Number.isInteger(data.numero_nomina) ||
        data.numero_nomina < 1 ||
        data.numero_nomina > 2147483647
    )) return mostrarErrorEmpleado('La nómina debe ser un número entero positivo o dejarse vacía');
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
        await cargarTodo();
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

    // Pre-seleccionar la adscripción por defecto si solo hay una
    const defaultAdsc = adscripcionesCache.length === 1 ? adscripcionesCache[0].id : null;
    llenarSelectAdscripciones(selectAdscArea, defaultAdsc);

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
        // Seleccionar adscripción
        llenarSelectAdscripciones(selectAdscArea, a.adscripcion_id);
    } catch (err) {
        App.toast(err.message, 'error');
        App.closeModal('areaModal');
    }
}

async function onSubmitArea(e) {
    e.preventDefault();
    const id = areaForm.elements['id'].value;
    const data = {
        clave:          areaForm.elements['clave'].value.trim(),
        nombre:         areaForm.elements['nombre'].value.trim(),
        descripcion:    areaForm.elements['descripcion'].value.trim(),
        adscripcion_id: areaForm.elements['adscripcion_id'].value || null,
        activo:         areaForm.elements['activo'].checked,
    };

    if (data.clave.length < 2)  return mostrarErrorArea('La clave es obligatoria');
    if (data.nombre.length < 2) return mostrarErrorArea('El nombre es obligatorio');
    if (!data.adscripcion_id)   return mostrarErrorArea('Selecciona una adscripción');

    const method = id ? 'PUT' : 'POST';
    const url    = id ? `areas.php?id=${id}` : 'areas.php';

    try {
        const res = await App.api(url, { method, body: data });
        if (!res.ok) throw new Error(res.error);
        App.toast(res.message, 'success');
        App.closeModal('areaModal');
        await cargarTodo();
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
        clave:          areaInlineForm.elements['clave'].value.trim(),
        nombre:         areaInlineForm.elements['nombre'].value.trim(),
        adscripcion_id: areaInlineForm.elements['adscripcion_id'].value || null,
        activo:         true,
    };

    if (data.clave.length < 2)  return mostrarErrorAreaInline('La clave es obligatoria');
    if (data.nombre.length < 2) return mostrarErrorAreaInline('El nombre es obligatorio');
    if (!data.adscripcion_id)   return mostrarErrorAreaInline('Selecciona una adscripción');

    try {
        const res = await App.api('areas.php', { method: 'POST', body: data });
        if (!res.ok) throw new Error(res.error);

        App.toast('Área creada. Ahora puedes asignarla.', 'success');
        App.closeModal('areaInlineModal');

        await cargarTodo();
        // Seleccionar el área nueva en el modal de empleado
        llenarSelectAreas(res.id);
        actualizarHintAdscEmpleado();
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
    if (!Number.isSafeInteger(Number(id)) || Number(id) < 1) {
        App.toast('Identificador del empleado inválido', 'error');
        return;
    }
    if (!await App.confirm(
        `¿Eliminar a "${nombre}"?\n\n` +
        'Sus activos NO se borrarán: quedarán sin empleado responsable.\n' +
        'Esta acción no se puede deshacer.'
    )) return;
    try {
        const res = await App.api(`empleados.php?id=${encodeURIComponent(id)}`, { method: 'DELETE' });
        if (!res?.ok) throw new Error(res?.error || 'El servidor no confirmó la eliminación');
        App.toast(res.message || 'Empleado eliminado correctamente', 'success');
        await cargarTodo();
    } catch (err) {
        console.error('No fue posible eliminar el empleado:', err);
        App.toast('No se pudo eliminar: ' + err.message, 'error', 7500);
    }
}

// ============ EXPONER AL WINDOW ============
window.editarEmpleado = editarEmpleado;
window.eliminarEmpleado = eliminarEmpleado;