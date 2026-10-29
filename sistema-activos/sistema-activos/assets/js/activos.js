/* =====================================================
   activos.js — Gestión de activos con vistas jerárquicas
   Jerarquía: Adscripción → Área → Empleado → Activo
   ===================================================== */

// ============ REFERENCIAS ============
const form            = document.getElementById('activoForm');
const tablaBody       = document.querySelector('#tablaActivos tbody');
const modalTitle      = document.getElementById('modalTitle');
const formError       = document.getElementById('formError');
const busqueda        = document.getElementById('busqueda');
const filtroAdsc      = document.getElementById('filtroAdscripcion');
const filtroArea      = document.getElementById('filtroArea');
const filtroEmpleado  = document.getElementById('filtroEmpleado');
const filtroSituacion = document.getElementById('filtroSituacion');
const selectEmp       = document.getElementById('empleado_id');
const hintAdscActivo  = document.getElementById('hintAdscActivo');
const imgInput        = document.getElementById('imagen');
const imgPreview      = document.getElementById('imagenPreview');
const btnNuevo        = document.getElementById('btnNuevo');
const btnExpandir     = document.getElementById('btnExpandirTodo');
const btnContraer     = document.getElementById('btnContraerTodo');

const listaAdscConActivos       = document.getElementById('listaAdscConActivos');
const listaAreasConActivos      = document.getElementById('listaAreasConActivos');
const listaEmpleadosConActivos  = document.getElementById('listaEmpleadosConActivos');
const statsResumen              = document.getElementById('statsResumen');
const statsDashboard            = document.getElementById('statsDashboard');

const countAdsc      = document.getElementById('countAdsc');
const countAreas     = document.getElementById('countAreas');
const countEmpleados = document.getElementById('countEmpleados');
const countTodos     = document.getElementById('countTodos');

// ============ ESTADO ============
let activosCache    = [];
let empleadosCache  = [];
let areasCache      = [];
let adscCache       = [];

// Sets de expansión (clave: 'adsc-5', 'area-3', 'emp-7', o '__sin_X__')
let expandidos = new Set();

// ============ INIT ============
document.addEventListener('DOMContentLoaded', init);

async function init() {
    wireTabs();
    wireEvents();
    await cargarTodo();
}

async function cargarTodo() {
    try {
        const [resAdsc, resAreas, resEmp, resAct] = await Promise.all([
            App.api('adscripciones.php'),
            App.api('areas.php'),
            App.api('empleados.php'),
            App.api('activos.php'),
        ]);
        if (!resAdsc.ok)  throw new Error(resAdsc.error);
        if (!resAreas.ok) throw new Error(resAreas.error);
        if (!resEmp.ok)   throw new Error(resEmp.error);
        if (!resAct.ok)   throw new Error(resAct.error);

        adscCache      = resAdsc.data.filter(a => a.activo);
        areasCache     = resAreas.data.filter(a => a.activo);
        empleadosCache = resEmp.data;
        activosCache   = resAct.data;

        llenarFiltros();
        llenarSelectEmpleados();
        renderTodo();
    } catch (err) {
        App.toast(err.message, 'error');
        console.error(err);
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
    btnNuevo.addEventListener('click', () => abrirModalActivo());

    busqueda.addEventListener('input', renderTodo);
    filtroAdsc.addEventListener('change', renderTodo);
    filtroArea.addEventListener('change', renderTodo);
    filtroEmpleado.addEventListener('change', renderTodo);
    filtroSituacion.addEventListener('change', renderTodo);

    btnExpandir.addEventListener('click', () => {
        // Expandir todo lo que tenga contenido
        activosCache.forEach(a => {
            const adsc = getAdscDeActivo(a);
            if (adsc) expandidos.add('adsc-' + adsc.id);
            else expandidos.add('adsc-__sin__');

            if (a.area_id) expandidos.add('area-' + a.area_id);
            else expandidos.add('area-__sin__');

            if (a.empleado_id) expandidos.add('emp-' + a.empleado_id);
            else expandidos.add('emp-__sin__');
        });
        renderAdscConActivos();
        renderAreasConActivos();
        renderEmpleadosConActivos();
    });

    btnContraer.addEventListener('click', () => {
        expandidos.clear();
        renderAdscConActivos();
        renderAreasConActivos();
        renderEmpleadosConActivos();
    });

    listaAdscConActivos.addEventListener('click', onAdscCardClick);
    listaAreasConActivos.addEventListener('click', onAreaCardClick);
    listaEmpleadosConActivos.addEventListener('click', onEmpleadoCardClick);
    // Botones de la tabla de todos los activos (sin onclick incrustado).
    tablaBody.addEventListener('click', onTablaActivoClick);

    imgInput.addEventListener('change', () => {
        const f = imgInput.files[0];
        if (!f) return;
        const reader = new FileReader();
        reader.onload = e => {
            imgPreview.innerHTML = `<img src="${e.target.result}" alt="Vista previa">`;
        };
        reader.readAsDataURL(f);
    });

    form.addEventListener('submit', onSubmitActivo);

    selectEmp.addEventListener('change', actualizarHintAdscActivo);
    document.getElementById('crear_en_stock').addEventListener('change', function() {
        if (this.checked) selectEmp.value = '';
        selectEmp.disabled = this.checked;
        actualizarHintAdscActivo();
    });

    // Autocompletar observaciones
    selectEmp.addEventListener('change', function() {
        const obs = form.querySelector('textarea[name="observaciones"]');
        if (this.value && (!obs.value || obs.value.trim() === '')) {
            const txt = this.options[this.selectedIndex].text;
            const match = txt.match(/\]\s+(.+?)\s+—/);
            if (match) obs.value = 'ASIGNADO A ' + match[1];
        }
    });
}

// ============ UTILIDADES ============
function getAdscDeActivo(a) {
    // Prioridad: la del área del activo
    if (a.area_id) {
        const area = areasCache.find(x => x.id === a.area_id);
        if (area && area.adscripcion_id) {
            return adscCache.find(ad => ad.id === area.adscripcion_id) || null;
        }
    }
    // Fallback: la del área del empleado
    if (a.empleado_id) {
        const emp = empleadosCache.find(x => x.id === a.empleado_id);
        if (emp && emp.area_id) {
            const area = areasCache.find(x => x.id === emp.area_id);
            if (area && area.adscripcion_id) {
                return adscCache.find(ad => ad.id === area.adscripcion_id) || null;
            }
        }
    }
    return null;
}

function getAreaDeActivo(a) {
    if (a.area_id) return areasCache.find(x => x.id === a.area_id) || null;
    if (a.empleado_id) {
        const emp = empleadosCache.find(x => x.id === a.empleado_id);
        if (emp && emp.area_id) return areasCache.find(x => x.id === emp.area_id) || null;
    }
    return null;
}

// Situación administrativa: no confundir con estado_fisico.
function esSuspendido(a) {
    return a.suspendido === true || a.suspendido === 't' || a.suspendido === 1 || a.suspendido === '1';
}

function botonSituacion(a) {
    const susp = esSuspendido(a);
    const accion = susp ? 'reactivar-activo' : 'suspender-activo';
    const rotulo = susp ? '▶️ Reactivar' : '⏸️ Suspender';
    return `<button type="button" class="btn btn--ghost btn--sm" data-action="${accion}"
        data-id="${Number(a.id)}" title="${susp ? 'Reactivar este activo' : 'Suspender sin eliminar este activo'}">${rotulo}</button>`;
}

function botonStock(a) {
    if (a.situacion_inventario === 'STOCK') return '<span class="badge">📦 En stock</span>';
    return `<button type="button" class="btn btn--ghost btn--sm" data-action="enviar-stock" data-id="${Number(a.id)}">📦 Enviar a stock</button>`;
}

async function enviarActivoAStock(id) {
    if (!confirm('¿Enviar este activo al almacén general? Se quitarán su empleado y área actuales.')) return;
    const motivo = prompt('Indica el motivo de entrada al almacén:');
    if (motivo === null) return;
    try {
        const res = await App.api('stock.php', {method:'POST', body:{accion:'entrada',activo_id:id,motivo:motivo.trim()}});
        if (!res.ok) throw new Error(res.error);
        App.toast(res.message, 'success');
        await cargarTodo();
    } catch(err) { App.toast(err.message, 'error'); }
}

function etiquetaSituacion(a) {
    if (!esSuspendido(a)) return '<span class="badge">Vigente</span>';
    const motivo = a.motivo_suspension ? ` · Motivo: ${App.esc(a.motivo_suspension)}` : '';
    return `<span class="badge badge--warning" title="Suspendido${motivo}">⏸ Suspendido</span>`;
}

// ============ FILTROS ============
function llenarFiltros() {
    const optsAdsc = ['<option value="">🏛️ Todas las adscripciones</option>']
        .concat(adscCache
            .sort((a, b) => a.nombre.localeCompare(b.nombre))
            .map(a => `<option value="${a.id}">${App.esc(a.nombre)}</option>`));
    filtroAdsc.innerHTML = optsAdsc.join('');

    const optsAreas = ['<option value="">🏢 Todas las áreas</option>']
        .concat(areasCache
            .sort((a, b) => a.nombre.localeCompare(b.nombre))
            .map(a => `<option value="${a.id}">${App.esc(a.nombre)}</option>`));
    filtroArea.innerHTML = optsAreas.join('');

    const optsEmp = ['<option value="">👤 Todos los empleados</option>']
        .concat(empleadosCache
            .sort((a, b) => a.nombre.localeCompare(b.nombre))
            .map(e => `<option value="${e.id}">[${e.numero_nomina}] ${App.esc(e.nombre)}</option>`));
    filtroEmpleado.innerHTML = optsEmp.join('');
}

function llenarSelectEmpleados() {
    const opts = ['<option value="">— Sin asignar —</option>']
        .concat(empleadosCache
            .sort((a, b) => a.nombre.localeCompare(b.nombre))
            .map(e => `<option value="${e.id}">[${e.numero_nomina}] ${App.esc(e.nombre)} — ${App.esc(e.cargo)}</option>`));
    selectEmp.innerHTML = opts.join('');
}

function actualizarHintAdscActivo() {
    const empId = parseInt(selectEmp.value, 10);
    if (!empId) {
        hintAdscActivo.textContent = '';
        return;
    }
    const emp = empleadosCache.find(e => e.id === empId);
    if (emp && emp.adscripcion_nombre) {
        hintAdscActivo.textContent = `🏛️ Adscripción: ${emp.adscripcion_nombre}`;
    } else {
        hintAdscActivo.textContent = '⚠️ Este empleado no tiene adscripción asignada.';
    }
}

function getActivosFiltrados() {
    const q = (busqueda.value || '').toLowerCase().trim();
    const adscId = filtroAdsc.value ? parseInt(filtroAdsc.value, 10) : null;
    const areaId = filtroArea.value ? parseInt(filtroArea.value, 10) : null;
    const empId = filtroEmpleado.value ? parseInt(filtroEmpleado.value, 10) : null;
    const situacion = filtroSituacion.value;

    return activosCache.filter(a => {
        if (adscId !== null) {
            const adsc = getAdscDeActivo(a);
            if (!adsc || adsc.id !== adscId) return false;
        }
        if (areaId !== null) {
            const ar = getAreaDeActivo(a);
            if (!ar || ar.id !== areaId) return false;
        }
        if (empId !== null && a.empleado_id !== empId) return false;
        if (situacion === 'suspendidos' && !esSuspendido(a)) return false;
        if (situacion === 'vigentes' && esSuspendido(a)) return false;
        if (q) {
            const campos = [
                a.descripcion, a.num_inventario, a.marca, a.modelo, a.serie,
                a.material, a.empleado_nombre, a.area_nombre, a.adscripcion_nombre,
            ].map(x => (x || '').toLowerCase());
            if (!campos.some(c => c.includes(q))) return false;
        }
        return true;
    });
}

// ============ RENDER: TODO ============
function renderTodo() {
    const activosFiltrados = getActivosFiltrados();
    renderAdscConActivos(activosFiltrados);
    renderAreasConActivos(activosFiltrados);
    renderEmpleadosConActivos(activosFiltrados);
    renderTablaTodos(activosFiltrados);
    renderEstadisticas(activosFiltrados);
    actualizarContadores(activosFiltrados);
}

function actualizarContadores(activosFiltrados = activosCache) {
    const adscConActivos = new Set();
    const areasConActivos = new Set();
    const empConActivos = new Set();

    activosFiltrados.forEach(a => {
        const adsc = getAdscDeActivo(a);
        if (adsc) adscConActivos.add(adsc.id);
        const ar = getAreaDeActivo(a);
        if (ar) areasConActivos.add(ar.id);
        if (a.empleado_id) empConActivos.add(a.empleado_id);
    });

    countAdsc.textContent = adscConActivos.size;
    countAreas.textContent = areasConActivos.size;
    countEmpleados.textContent = empConActivos.size;
    countTodos.textContent = activosFiltrados.length;
}

// ============ RENDER: POR ADSCRIPCIÓN ============
function renderAdscConActivos(activosFiltrados = null) {
    if (!activosFiltrados) activosFiltrados = getActivosFiltrados();

    const grupos = new Map();
    adscCache.forEach(ad => grupos.set(ad.id, { adsc: ad, activos: [] }));
    const sinAdsc = { adsc: null, activos: [] };

    activosFiltrados.forEach(a => {
        const adsc = getAdscDeActivo(a);
        if (adsc && grupos.has(adsc.id)) {
            grupos.get(adsc.id).activos.push(a);
        } else {
            sinAdsc.activos.push(a);
        }
    });

    const conActivos = [...grupos.values()]
        .filter(g => g.activos.length > 0)
        .sort((a, b) => a.adsc.nombre.localeCompare(b.adsc.nombre));

    if (!conActivos.length && !sinAdsc.activos.length) {
        listaAdscConActivos.innerHTML = `
            <div class="card empty-state">
                <div class="empty-state__icon">📦</div>
                <p>No hay activos que coincidan.</p>
            </div>
        `;
        return;
    }

    let html = '';
    if (sinAdsc.activos.length > 0) {
        html += renderAdscCard(null, sinAdsc.activos);
    }
    html += conActivos.map(g => renderAdscCard(g.adsc, g.activos)).join('');
    listaAdscConActivos.innerHTML = html;
}

function renderAdscCard(adsc, activos) {
    const key = adsc ? 'adsc-' + adsc.id : 'adsc-__sin__';
    const isOpen = expandidos.has(key);
    const totalCosto = activos.reduce((s, a) => s + parseFloat(a.costo || 0), 0);
    const numAct = activos.length;

    const nombre = adsc ? adsc.nombre : '📭 Sin adscripción';
    const clave = adsc ? adsc.clave : '—';
    const descripcion = adsc && adsc.descripcion ? adsc.descripcion : '';
    const direccion = adsc && adsc.direccion ? adsc.direccion : '';

    // Agrupar por área
    const porArea = new Map();
    activos.forEach(a => {
        const ar = getAreaDeActivo(a);
        const k = ar ? ar.id : '__sin__';
        if (!porArea.has(k)) porArea.set(k, { area: ar, activos: [] });
        porArea.get(k).activos.push(a);
    });

    const areasHTML = [...porArea.values()].map(g => {
        const areaNombre = g.area ? g.area.nombre : '📭 Sin área';
        const subtotal = g.activos.reduce((s, a) => s + parseFloat(a.costo || 0), 0);
        return `
            <div class="empleado-grupo">
                <div class="empleado-grupo__header">
                    <span class="empleado-grupo__nombre">
                        🏢 ${App.esc(areaNombre)}
                        ${g.area ? `<code style="margin-left: 0.5rem;">${App.esc(g.area.clave)}</code>` : ''}
                    </span>
                    <span class="empleado-grupo__stats">${g.activos.length} · ${App.money(subtotal)}</span>
                </div>
                <div class="activo-lista">
                    ${g.activos.map(a => renderActivoFila(a, true)).join('')}
                </div>
            </div>
        `;
    }).join('');

    const descripcionHTML = descripcion
        ? `<div class="area-card__desc">${App.esc(descripcion)}</div>`
        : '';
    const direccionHTML = direccion
        ? `<div class="adsc-card__direccion">📍 ${App.esc(direccion)}</div>`
        : '';

    return `
        <div class="area-card ${isOpen ? 'is-open' : ''}" data-adsc-id="${adsc ? adsc.id : '__sin__'}">
            <div class="area-card__header" data-action="toggle-adsc">
                <div class="area-card__info">
                    <span class="area-card__icon">${adsc ? '🏛️' : '📭'}</span>
                    <div class="area-card__body">
                        <h3 class="area-card__name">${App.esc(nombre)}</h3>
                        <div class="area-card__meta">
                            ${adsc ? `<code>${App.esc(clave)}</code>` : ''}
                            <span class="badge">${numAct} activo${numAct === 1 ? '' : 's'}</span>
                            <span class="badge badge--info">${App.money(totalCosto)}</span>
                        </div>
                    </div>
                </div>
                <div class="area-card__actions" data-action="stop">
                    <button class="area-card__toggle" data-action="toggle-adsc" title="Expandir/Contraer">▾</button>
                </div>
            </div>
            <div class="area-card__content">
                ${direccionHTML}
                ${descripcionHTML}
                ${areasHTML}
            </div>
        </div>
    `;
}

function onAdscCardClick(e) {
    const target = e.target.closest('[data-action]');
    if (!target) return;
    const action = target.dataset.action;
    const card = e.target.closest('.area-card');
    const idRaw = card ? card.dataset.adscId : null;
    const inActions = e.target.closest('.area-card__actions');
    if (action === 'toggle-adsc' && inActions) return;

    switch (action) {
        case 'toggle-adsc':
            if (idRaw) {
                const key = idRaw === '__sin__' ? 'adsc-__sin__' : 'adsc-' + parseInt(idRaw, 10);
                if (expandidos.has(key)) expandidos.delete(key);
                else expandidos.add(key);
                renderAdscConActivos();
            }
            break;
        case 'enviar-stock':
            enviarActivoAStock(parseInt(target.dataset.id, 10));
            break;
        case 'editar-activo':
            editarActivo(parseInt(target.dataset.id, 10));
            break;
        case 'eliminar-activo':
            eliminarActivo(parseInt(target.dataset.id, 10), target.dataset.desc);
            break;
        case 'suspender-activo':
            cambiarSituacionActivo(parseInt(target.dataset.id, 10), false);
            break;
        case 'reactivar-activo':
            cambiarSituacionActivo(parseInt(target.dataset.id, 10), true);
            break;
    }
}

// ============ RENDER: POR ÁREA ============
function renderAreasConActivos(activosFiltrados = null) {
    if (!activosFiltrados) activosFiltrados = getActivosFiltrados();

    const grupos = new Map();
    areasCache.forEach(a => grupos.set(a.id, { area: a, activos: [] }));
    const sinArea = { area: null, activos: [] };

    activosFiltrados.forEach(act => {
        const ar = getAreaDeActivo(act);
        if (ar && grupos.has(ar.id)) grupos.get(ar.id).activos.push(act);
        else sinArea.activos.push(act);
    });

    const conActivos = [...grupos.values()]
        .filter(g => g.activos.length > 0)
        .sort((a, b) => a.area.nombre.localeCompare(b.area.nombre));

    if (!conActivos.length && !sinArea.activos.length) {
        listaAreasConActivos.innerHTML = `
            <div class="card empty-state">
                <div class="empty-state__icon">📦</div>
                <p>No hay activos que coincidan.</p>
            </div>
        `;
        return;
    }

    let html = '';
    if (sinArea.activos.length > 0) html += renderAreaCard(sinArea.area, sinArea.activos);
    html += conActivos.map(g => renderAreaCard(g.area, g.activos)).join('');
    listaAreasConActivos.innerHTML = html;
}

function renderAreaCard(area, activos) {
    const key = area ? 'area-' + area.id : 'area-__sin__';
    const isOpen = expandidos.has(key);
    const totalCosto = activos.reduce((s, a) => s + parseFloat(a.costo || 0), 0);
    const numAct = activos.length;

    const nombre = area ? area.nombre : '📭 Sin área';
    const clave = area ? area.clave : '—';
    const adscNombre = area && area.adscripcion_nombre ? area.adscripcion_nombre : '';
    const descripcion = area && area.descripcion ? area.descripcion : '';

    // Agrupar por empleado
    const porEmp = new Map();
    activos.forEach(a => {
        const k = a.empleado_id || '__sin__';
        if (!porEmp.has(k)) {
            porEmp.set(k, {
                nombre: a.empleado_nombre || '📭 Sin resguardante',
                cargo: a.cargo || '',
                numero_nomina: a.numero_nomina || '',
                activos: []
            });
        }
        porEmp.get(k).activos.push(a);
    });

    const empleadosHTML = [...porEmp.values()].map(g => {
        const subtotal = g.activos.reduce((s, a) => s + parseFloat(a.costo || 0), 0);
        return `
            <div class="empleado-grupo">
                <div class="empleado-grupo__header">
                    <span class="empleado-grupo__nombre">
                        ${g.numero_nomina ? `<span class="employee-row__nomina">[${App.esc(g.numero_nomina)}]</span>` : ''}
                        ${App.esc(g.nombre)}
                    </span>
                    ${g.cargo ? `<span class="empleado-grupo__cargo">${App.esc(g.cargo)}</span>` : ''}
                    <span class="empleado-grupo__stats">${g.activos.length} · ${App.money(subtotal)}</span>
                </div>
                <div class="activo-lista">
                    ${g.activos.map(a => renderActivoFila(a, true)).join('')}
                </div>
            </div>
        `;
    }).join('');

    const adscBadge = adscNombre
        ? `<span class="badge badge--adsc">🏛️ ${App.esc(adscNombre)}</span>`
        : '';

    const descripcionHTML = descripcion
        ? `<div class="area-card__desc">${App.esc(descripcion)}</div>`
        : '';

    return `
        <div class="area-card ${isOpen ? 'is-open' : ''}" data-area-id="${area ? area.id : '__sin__'}">
            <div class="area-card__header" data-action="toggle-area">
                <div class="area-card__info">
                    <span class="area-card__icon">${area ? '🏢' : '📭'}</span>
                    <div class="area-card__body">
                        <h3 class="area-card__name">${App.esc(nombre)}</h3>
                        <div class="area-card__meta">
                            ${area ? `<code>${App.esc(clave)}</code>` : ''}
                            ${adscBadge}
                            <span class="badge">${numAct} activo${numAct === 1 ? '' : 's'}</span>
                            <span class="badge badge--info">${App.money(totalCosto)}</span>
                        </div>
                    </div>
                </div>
                <div class="area-card__actions" data-action="stop">
                    <button class="area-card__toggle" data-action="toggle-area" title="Expandir/Contraer">▾</button>
                </div>
            </div>
            <div class="area-card__content">
                ${descripcionHTML}
                ${empleadosHTML}
            </div>
        </div>
    `;
}

function onAreaCardClick(e) {
    const target = e.target.closest('[data-action]');
    if (!target) return;
    const action = target.dataset.action;
    const card = e.target.closest('.area-card');
    const idRaw = card ? card.dataset.areaId : null;
    const inActions = e.target.closest('.area-card__actions');
    if (action === 'toggle-area' && inActions) return;

    switch (action) {
        case 'toggle-area':
            if (idRaw) {
                const key = idRaw === '__sin__' ? 'area-__sin__' : 'area-' + parseInt(idRaw, 10);
                if (expandidos.has(key)) expandidos.delete(key);
                else expandidos.add(key);
                renderAreasConActivos();
            }
            break;
        case 'enviar-stock':
            enviarActivoAStock(parseInt(target.dataset.id, 10));
            break;
        case 'editar-activo':
            editarActivo(parseInt(target.dataset.id, 10));
            break;
        case 'eliminar-activo':
            eliminarActivo(parseInt(target.dataset.id, 10), target.dataset.desc);
            break;
        case 'suspender-activo':
            cambiarSituacionActivo(parseInt(target.dataset.id, 10), false);
            break;
        case 'reactivar-activo':
            cambiarSituacionActivo(parseInt(target.dataset.id, 10), true);
            break;
    }
}

// ============ RENDER: POR EMPLEADO ============
function renderEmpleadosConActivos(activosFiltrados = null) {
    if (!activosFiltrados) activosFiltrados = getActivosFiltrados();

    const grupos = new Map();
    empleadosCache.forEach(e => grupos.set(e.id, { empleado: e, activos: [] }));
    const sinEmp = { empleado: null, activos: [] };

    activosFiltrados.forEach(a => {
        if (a.empleado_id && grupos.has(a.empleado_id)) grupos.get(a.empleado_id).activos.push(a);
        else sinEmp.activos.push(a);
    });

    const conActivos = [...grupos.values()]
        .filter(g => g.activos.length > 0)
        .sort((a, b) => a.empleado.nombre.localeCompare(b.empleado.nombre));

    if (!conActivos.length && !sinEmp.activos.length) {
        listaEmpleadosConActivos.innerHTML = `
            <div class="card empty-state">
                <div class="empty-state__icon">👥</div>
                <p>No hay empleados con activos que coincidan.</p>
            </div>
        `;
        return;
    }

    let html = '';
    if (sinEmp.activos.length > 0) html += renderEmpleadoCard(null, sinEmp.activos);
    html += conActivos.map(g => renderEmpleadoCard(g.empleado, g.activos)).join('');
    listaEmpleadosConActivos.innerHTML = html;
}

function renderEmpleadoCard(empleado, activos) {
    const key = empleado ? 'emp-' + empleado.id : 'emp-__sin__';
    const isOpen = expandidos.has(key);
    const totalCosto = activos.reduce((s, a) => s + parseFloat(a.costo || 0), 0);

    const nombre = empleado ? empleado.nombre : '📭 Sin resguardante';
    const nomina = empleado ? empleado.numero_nomina : '';
    const cargo = empleado ? empleado.cargo : '';
    const adscNombre = empleado && empleado.adscripcion_nombre ? empleado.adscripcion_nombre : '';
    const areaNombre = empleado && empleado.area_nombre ? empleado.area_nombre : '';

    const badges = [];
    if (adscNombre) badges.push(`<span class="badge badge--adsc">🏛️ ${App.esc(adscNombre)}</span>`);
    if (areaNombre) badges.push(`<span class="badge">🏢 ${App.esc(areaNombre)}</span>`);
    badges.push(`<span class="badge">${activos.length} activo${activos.length === 1 ? '' : 's'}</span>`);
    badges.push(`<span class="badge badge--info">${App.money(totalCosto)}</span>`);

    return `
        <div class="area-card ${isOpen ? 'is-open' : ''}" data-emp-id="${empleado ? empleado.id : '__sin__'}">
            <div class="area-card__header" data-action="toggle-emp">
                <div class="area-card__info">
                    <span class="area-card__icon">${empleado ? '👤' : '📭'}</span>
                    <div class="area-card__body">
                        <h3 class="area-card__name">
                            ${nomina ? `<span class="employee-row__nomina">[${App.esc(nomina)}]</span>` : ''}
                            ${App.esc(nombre)}
                        </h3>
                        <div class="area-card__meta">
                            ${cargo ? `<span>${App.esc(cargo)}</span>` : ''}
                            ${badges.join('')}
                        </div>
                    </div>
                </div>
                <div class="area-card__actions" data-action="stop">
                    <button class="area-card__toggle" data-action="toggle-emp" title="Expandir/Contraer">▾</button>
                </div>
            </div>
            <div class="area-card__content">
                <div class="activo-lista">
                    ${activos.map(a => renderActivoFila(a, true)).join('')}
                </div>
            </div>
        </div>
    `;
}

function onEmpleadoCardClick(e) {
    const target = e.target.closest('[data-action]');
    if (!target) return;
    const action = target.dataset.action;
    const card = e.target.closest('.area-card');
    const idRaw = card ? card.dataset.empId : null;
    const inActions = e.target.closest('.area-card__actions');
    if (action === 'toggle-emp' && inActions) return;

    switch (action) {
        case 'toggle-emp':
            if (idRaw) {
                const key = idRaw === '__sin__' ? 'emp-__sin__' : 'emp-' + parseInt(idRaw, 10);
                if (expandidos.has(key)) expandidos.delete(key);
                else expandidos.add(key);
                renderEmpleadosConActivos();
            }
            break;
        case 'enviar-stock':
            enviarActivoAStock(parseInt(target.dataset.id, 10));
            break;
        case 'editar-activo':
            editarActivo(parseInt(target.dataset.id, 10));
            break;
        case 'eliminar-activo':
            eliminarActivo(parseInt(target.dataset.id, 10), target.dataset.desc);
            break;
        case 'suspender-activo':
            cambiarSituacionActivo(parseInt(target.dataset.id, 10), false);
            break;
        case 'reactivar-activo':
            cambiarSituacionActivo(parseInt(target.dataset.id, 10), true);
            break;
    }
}

// ============ FILA DE ACTIVO (reutilizable) ============
function renderActivoFila(a, mostrarAdsc = false) {
    let imgPath = a.ruta_imagen || '';
    if (imgPath.startsWith('public/')) imgPath = imgPath.replace('public/', '');

    const thumb = imgPath
        ? `<img class="activo-thumb" src="${App.esc(imgPath)}" alt="Foto" loading="lazy" onclick="viewImage('${App.esc(imgPath)}')" style="cursor: pointer;">`
        : `<div class="activo-noimg">S/F</div>`;

    return `
        <div class="activo-fila" ${esSuspendido(a) ? 'style="background:rgba(217,119,6,0.07)"' : ''}>
            <div class="activo-fila__thumb">${thumb}</div>
            <div class="activo-fila__info">
                <div class="activo-fila__desc">${App.esc(a.descripcion)} ${esSuspendido(a) ? etiquetaSituacion(a) : ''}</div>
                <div class="activo-fila__meta">
                    <code>${App.esc(a.num_inventario)}</code>
                    ${a.marca ? ` · ${App.esc(a.marca)}` : ''}
                    ${a.modelo ? ` · ${App.esc(a.modelo)}` : ''}
                </div>
            </div>
            <div class="activo-fila__costo">${App.money(a.costo)}</div>
            <div class="activo-fila__acciones">
                ${botonSituacion(a)}
                ${botonStock(a)}
                <button class="btn btn--ghost btn--sm" data-action="editar-activo" data-id="${a.id}" title="Editar">✏️</button>
                <button class="btn btn--danger btn--sm" data-action="eliminar-activo" data-id="${a.id}" data-desc="${App.esc(a.descripcion)}" title="Eliminar">🗑️</button>
            </div>
        </div>
    `;
}

function onTablaActivoClick(evento) {
    const boton = evento.target.closest('button[data-action]');
    if (!boton || !tablaBody.contains(boton)) return;
    const id = Number(boton.dataset.id);
    if (!Number.isSafeInteger(id) || id <= 0) {
        App.toast('Identificador del activo inválido', 'error');
        return;
    }
    if (boton.dataset.action === 'enviar-stock') enviarActivoAStock(id);
    if (boton.dataset.action === 'editar-activo') editarActivo(id);
    if (boton.dataset.action === 'suspender-activo') cambiarSituacionActivo(id, false);
    if (boton.dataset.action === 'reactivar-activo') cambiarSituacionActivo(id, true);
    if (boton.dataset.action === 'eliminar-activo') {
        eliminarActivo(id, boton.dataset.desc || 'este activo');
    }
}

// ============ RENDER: TABLA TODOS ============
function renderTablaTodos(activosFiltrados = null) {
    if (!activosFiltrados) activosFiltrados = getActivosFiltrados();

    const totalCosto = activosFiltrados.reduce((s, a) => s + parseFloat(a.costo || 0), 0);
    statsResumen.innerHTML = `
        <div class="stat">
            <span class="stat__icon">📦</span>
            <div>
                <div class="stat__num">${activosFiltrados.length}</div>
                <div class="stat__lbl">Activos mostrados</div>
            </div>
        </div>
        <div class="stat">
            <span class="stat__icon">⏸️</span>
            <div>
                <div class="stat__num">${activosFiltrados.filter(esSuspendido).length}</div>
                <div class="stat__lbl">Suspendidos</div>
            </div>
        </div>
        <div class="stat">
            <span class="stat__icon">💰</span>
            <div>
                <div class="stat__num">${App.money(totalCosto)}</div>
                <div class="stat__lbl">Valor total</div>
            </div>
        </div>
    `;

    if (!activosFiltrados.length) {
        tablaBody.innerHTML = '<tr><td colspan="7"><div class="empty-state"><div class="empty-state__icon">📦</div>No hay activos que coincidan.</div></td></tr>';
        return;
    }

    tablaBody.innerHTML = activosFiltrados.map(a => {
        let imgPath = a.ruta_imagen || '';
        if (imgPath.startsWith('public/')) imgPath = imgPath.replace('public/', '');

        const empTxt = a.empleado_id
            ? `<strong>${App.esc(a.empleado_nombre || '')}</strong><br><small>${App.esc(a.cargo || '')}</small>`
            : a.situacion_inventario === 'STOCK' ? '<span class="badge">📦 En stock</span>' : '<span class="badge badge--warning">Sin asignar</span>';

        const adsc = getAdscDeActivo(a);
        const adscTxt = adsc
            ? `🏛️ ${App.esc(adsc.nombre)}`
            : '<span class="muted">—</span>';

        const areaTxt = a.area_nombre
            ? '🏢 ' + App.esc(a.area_nombre)
            : '<span class="muted">—</span>';

        return `
            <tr ${esSuspendido(a) ? 'style="background:rgba(217,119,6,0.07)"' : ''}>
                <td><strong>${App.esc(a.descripcion)}</strong></td>
                <td>${App.esc(a.estado_fisico || '—')}</td>
                <td>${adscTxt}</td>
                <td>${areaTxt}</td>
                <td>${empTxt}</td>
                <td class="col-foto">
                    ${imgPath
                        ? `<img class="table__thumb" src="${App.esc(imgPath)}" alt="Foto" loading="lazy" onclick="viewImage('${App.esc(imgPath)}')" style="cursor: pointer;">`
                        : `<div class="table__noimg">S/F</div>`}
                </td>
                <td class="acciones-celda">
                    <details class="acciones-menu">
                        <summary class="btn btn--ghost btn--sm" title="Mostrar todas las acciones">⚙️ Acciones ▾</summary>
                        <div class="acciones-menu__opciones">
                            ${botonSituacion(a)}
                            ${botonStock(a)}
                            <button type="button" class="btn btn--ghost btn--sm" data-action="editar-activo" data-id="${a.id}" title="Editar activo">✏️ Editar</button>
                            <button type="button" class="btn btn--danger btn--sm" data-action="eliminar-activo" data-id="${a.id}" data-desc="${App.esc(a.descripcion)}" title="Eliminar activo">🗑️ Eliminar</button>
                        </div>
                    </details>
                </td>
            </tr>
        `;
    }).join('');
}

// ============ RENDER: ESTADÍSTICAS ============
function renderEstadisticas(activosFiltrados = null) {
    if (!activosFiltrados) activosFiltrados = getActivosFiltrados();

    const total = activosFiltrados.length;
    const totalCosto = activosFiltrados.reduce((s, a) => s + parseFloat(a.costo || 0), 0);
    const asignados = activosFiltrados.filter(a => a.empleado_id).length;
    const sinAsignar = total - asignados;
    const suspendidos = activosFiltrados.filter(esSuspendido).length;

    // Distribución por adscripción
    const porAdsc = new Map();
    activosFiltrados.forEach(a => {
        const adsc = getAdscDeActivo(a);
        const k = adsc ? adsc.id : '__sin__';
        if (!porAdsc.has(k)) porAdsc.set(k, { nombre: adsc ? adsc.nombre : '📭 Sin adscripción', activos: 0, costo: 0 });
        const g = porAdsc.get(k);
        g.activos++;
        g.costo += parseFloat(a.costo || 0);
    });
    const adscOrdenadas = [...porAdsc.values()].sort((a, b) => b.costo - a.costo);

    // Top empleados por valor
    const porEmp = new Map();
    activosFiltrados.forEach(a => {
        if (!a.empleado_id) return;
        const k = a.empleado_id;
        if (!porEmp.has(k)) porEmp.set(k, { nombre: a.empleado_nombre, cargo: a.cargo, activos: 0, costo: 0 });
        const g = porEmp.get(k);
        g.activos++;
        g.costo += parseFloat(a.costo || 0);
    });
    const topEmp = [...porEmp.values()].sort((a, b) => b.costo - a.costo).slice(0, 10);
    const maxCostoEmp = topEmp.length ? topEmp[0].costo : 1;

    const html = `
        <div class="stats">
            <div class="stat">
                <span class="stat__icon">📦</span>
                <div>
                    <div class="stat__num">${total}</div>
                    <div class="stat__lbl">Activos en inventario (incluye suspendidos)</div>
                </div>
            </div>
            <div class="stat">
                <span class="stat__icon">💰</span>
                <div>
                    <div class="stat__num">${App.money(totalCosto)}</div>
                    <div class="stat__lbl">Valor total</div>
                </div>
            </div>
            <div class="stat">
                <span class="stat__icon">👤</span>
                <div>
                    <div class="stat__num">${asignados}</div>
                    <div class="stat__lbl">Asignados</div>
                </div>
            </div>
            <div class="stat">
                <span class="stat__icon">⚠️</span>
                <div>
                    <div class="stat__num">${sinAsignar}</div>
                    <div class="stat__lbl">Sin asignar</div>
                </div>
            </div>
        </div>

        <p class="muted">⏸️ ${suspendidos} suspendido(s) dentro del inventario. Su valor continúa en el total hasta su baja contable.</p>

        <div class="grid-2">
            <div class="card">
                <h2 class="card__title">🏛️ Distribución por adscripción</h2>
                ${adscOrdenadas.length === 0
                    ? '<p class="muted">No hay datos suficientes.</p>'
                    : adscOrdenadas.map(a => `
                        <div class="rank-item">
                            <span class="rank-item__pos">🏛️</span>
                            <div class="rank-item__info">
                                <div class="rank-item__name">${App.esc(a.nombre)}</div>
                                <div class="rank-item__meta">${a.activos} activo${a.activos === 1 ? '' : 's'}</div>
                            </div>
                            <div class="rank-item__value">${App.money(a.costo)}</div>
                        </div>
                    `).join('')
                }
            </div>

            <div class="card">
                <h2 class="card__title">🏆 Top empleados por valor</h2>
                ${topEmp.length === 0
                    ? '<p class="muted">No hay datos suficientes.</p>'
                    : topEmp.map((e, i) => `
                        <div class="rank-item">
                            <span class="rank-item__pos">${i + 1}</span>
                            <div class="rank-item__info">
                                <div class="rank-item__name">${App.esc(e.nombre)}</div>
                                <div class="rank-item__meta">${e.activos} activo${e.activos === 1 ? '' : 's'} · ${App.esc(e.cargo || '')}</div>
                                <div class="rank-item__bar">
                                    <div class="rank-item__bar-fill" style="width: ${(e.costo / maxCostoEmp * 100).toFixed(1)}%"></div>
                                </div>
                            </div>
                            <div class="rank-item__value">${App.money(e.costo)}</div>
                        </div>
                    `).join('')
                }
            </div>
        </div>
    `;

    statsDashboard.innerHTML = html;
}

// ============ MODAL ACTIVO ============
function abrirModalActivo() {
    form.reset();
    form.elements['id'].value = '';
    document.getElementById('grupoCrearStock').style.display = '';
    document.getElementById('crear_en_stock').checked = false;
    selectEmp.disabled = false;
    form.elements['ruta_imagen'].value = '';
    form.elements['observaciones'].value = '';
    form.elements['costo'].required = true;
    form.elements['estado_fisico'].required = true;
    if (imgInput) imgInput.value = '';
    modalTitle.textContent = '➕ Nuevo activo';
    imgPreview.innerHTML = '<div class="image-preview__placeholder">Sin imagen</div>';
    formError.style.display = 'none';
    hintAdscActivo.textContent = '';
    App.openModal('activoModal');
}

async function editarActivo(id) {
    try {
        const res = await App.api(`activos.php?id=${id}`);
        if (!res.ok) throw new Error(res.error);
        const a = res.data;
        App.fillForm(form, a);
        document.getElementById('grupoCrearStock').style.display = 'none';
        document.getElementById('crear_en_stock').checked = false;
        selectEmp.disabled = a.situacion_inventario === 'STOCK';
        // Estos dos datos pueden ser NULL si proceden de un Excel incompleto.
        // No obligamos a inventarlos para modificar otra propiedad del activo.
        form.elements['costo'].required = false;
        form.elements['estado_fisico'].required = false;
        if (imgInput) imgInput.value = '';
        modalTitle.textContent = '✏️ Editar activo';
        if (a.ruta_imagen) {
            imgPreview.innerHTML = `<img src="${App.esc(a.ruta_imagen)}" alt="Foto actual">`;
        } else {
            imgPreview.innerHTML = '<div class="image-preview__placeholder">Sin imagen</div>';
        }
        formError.style.display = 'none';
        actualizarHintAdscActivo();
        App.openModal('activoModal');
    } catch (err) {
        App.toast(err.message, 'error');
    }
}

// Suspender y reactivar NO eliminan el bien, su responsable ni su foto.
async function cambiarSituacionActivo(id, reactivar) {
    const a = activosCache.find(x => Number(x.id) === Number(id));
    if (!a || !Number.isSafeInteger(Number(id)) || Number(id) < 1) {
        App.toast('Activo no encontrado. Actualiza la página.', 'error');
        return;
    }
    if (reactivar !== esSuspendido(a)) {
        App.toast('La situación del activo ya cambió. Actualiza la lista.', 'error');
        return;
    }

    let motivo = '';
    if (reactivar) {
        const ok = await App.confirm(`¿Reactivar el activo "${a.descripcion}"?\nConservará su responsable y sus demás datos.`);
        if (!ok) return;
    } else {
        const entrada = window.prompt(
            `Motivo de suspensión del activo "${a.descripcion}" (obligatorio):`, ''
        );
        if (entrada === null) return;
        motivo = entrada.trim();
        if (!motivo || motivo.length > 500) {
            App.toast('Escribe un motivo de 1 a 500 caracteres.', 'error', 6000);
            return;
        }
        const ok = await App.confirm(`¿Suspender "${a.descripcion}"?\nNo se eliminará ni se quitará su responsable.`);
        if (!ok) return;
    }

    try {
        const res = await App.api(`suspension_activos.php?id=${encodeURIComponent(id)}`, {
            method: 'POST',
            body: { accion: reactivar ? 'reactivar' : 'suspender', motivo }
        });
        if (!res?.ok) throw new Error(res?.error || 'No se pudo cambiar la situación.');
        App.toast(res.message || (reactivar ? 'Activo reactivado' : 'Activo suspendido'), 'success');
        await cargarTodo();
    } catch (err) {
        console.error('Error al cambiar la situación del activo:', err);
        App.toast('No se pudo cambiar la situación: ' + err.message, 'error', 7500);
    }
}

async function eliminarActivo(id, desc) {
    if (!Number.isSafeInteger(Number(id)) || Number(id) < 1) {
        App.toast('Identificador del activo inválido', 'error');
        return;
    }
    if (!await App.confirm(`¿Eliminar definitivamente el activo "${desc}"?\nEsta acción no se puede deshacer.`)) return;
    try {
        const res = await App.api(`activos.php?id=${encodeURIComponent(id)}`, { method: 'DELETE' });
        if (!res?.ok) throw new Error(res?.error || 'El servidor no confirmó la eliminación');
        App.toast(res.message || 'Activo eliminado', 'success');
        await cargarTodo();
    } catch (err) {
        console.error('No fue posible eliminar el activo:', err);
        App.toast('No se pudo eliminar: ' + err.message, 'error', 7500);
    }
}

async function onSubmitActivo(e) {
    e.preventDefault();

    // Leer SOLO los controles existentes en el formulario.
    const campo = nombre => form.elements.namedItem(nombre);
    const texto = nombre => (campo(nombre)?.value ?? '').trim();
    const id = texto('id');
    const esEdicion = id !== '';
    const desc = texto('descripcion');
    const ni = texto('num_inventario');
    const costoTexto = texto('costo');
    const estadoFisico = texto('estado_fisico');

    if (desc.length < 2) return mostrarError('La descripción es obligatoria.');
    if (!ni) return mostrarError('El número de inventario es obligatorio.');
    if (ni.length > 50) return mostrarError('El número de inventario supera 50 caracteres.');

    // Un bien importado puede tener costo o estado pendientes (NULL).
    // Crear un bien nuevo sigue requiriendo ambos valores.
    if (!esEdicion && costoTexto === '') {
        return mostrarError('Para crear un activo indica el costo.');
    }
    const costo = costoTexto === '' ? null : Number(costoTexto);
    if (costo !== null && (!Number.isFinite(costo) || costo < 0 || costo > 9999999999.99)) {
        return mostrarError('El costo debe ser un número válido no negativo.');
    }

    const estadosPermitidos = ['BUENO', 'NUEVO', 'REGULAR', 'INSERVIBLE'];
    if (!esEdicion && !estadoFisico) {
        return mostrarError('Selecciona el estado físico para el nuevo activo.');
    }
    if (estadoFisico !== '' && !estadosPermitidos.includes(estadoFisico)) {
        return mostrarError('Selecciona un estado físico válido.');
    }

    let rutaImagen = texto('ruta_imagen') || null;
    if (imgInput.files && imgInput.files[0]) {
        try {
            const fd = new FormData();
            fd.append('imagen', imgInput.files[0]);
            const r = await fetch(window.API_BASE + 'upload.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-CSRF-Token': window.CSRF_TOKEN || '' },
                body: fd
            });
            const j = await r.json();
            if (!j.ok) throw new Error(j.error || 'No se pudo subir la imagen.');
            rutaImagen = j.ruta;
        } catch (err) {
            return mostrarError('Error al subir imagen: ' + err.message);
        }
    }

    const empleadoTexto = texto('empleado_id');
    const data = {
        descripcion: desc,
        num_inventario: ni,
        marca: texto('marca'),
        modelo: texto('modelo'),
        serie: texto('serie') || 'S/S',
        material: texto('material'),
        estado_fisico: estadoFisico || null,
        fecha_adq: texto('fecha_adq') || null,
        factura: texto('factura'),
        costo: costo,
        observaciones: texto('observaciones'),
        empleado_id: empleadoTexto && !document.getElementById('crear_en_stock').checked ? Number(empleadoTexto) : null,
        crear_en_stock: !esEdicion && document.getElementById('crear_en_stock').checked,
        ruta_imagen: rutaImagen
    };

    try {
        const url = esEdicion ? `activos.php?id=${encodeURIComponent(id)}` : 'activos.php';
        const method = esEdicion ? 'PUT' : 'POST';
        const res = await App.api(url, { method, body: data });
        if (!res?.ok) throw new Error(res?.error || 'El servidor no confirmó el guardado.');
        App.toast(res.message || 'Activo guardado correctamente.', 'success');
        App.closeModal('activoModal');
        await cargarTodo();
    } catch (err) {
        console.error('No se pudo guardar el activo:', err);
        mostrarError('No se pudo actualizar el activo: ' + err.message);
    }
}

function mostrarError(msg) {
    formError.textContent = msg;
    formError.style.display = 'block';
}

// ============ EXPONER AL WINDOW ============
window.editarActivo = editarActivo;
window.eliminarActivo = eliminarActivo;