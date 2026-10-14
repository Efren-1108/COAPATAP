/* =====================================================
   activos.js — Gestión de activos con vistas jerárquicas
   ===================================================== */

// ============ REFERENCIAS ============
const form          = document.getElementById('activoForm');
const tablaBody     = document.querySelector('#tablaActivos tbody');
const modalTitle    = document.getElementById('modalTitle');
const formError     = document.getElementById('formError');
const busqueda      = document.getElementById('busqueda');
const filtroArea    = document.getElementById('filtroArea');
const filtroEmpleado= document.getElementById('filtroEmpleado');
const selectEmp     = document.getElementById('empleado_id');
const imgInput      = document.getElementById('imagen');
const imgPreview    = document.getElementById('imagenPreview');
const btnNuevo      = document.getElementById('btnNuevo');
const btnExpandir   = document.getElementById('btnExpandirTodo');
const btnContraer   = document.getElementById('btnContraerTodo');

const listaAreasConActivos      = document.getElementById('listaAreasConActivos');
const listaEmpleadosConActivos  = document.getElementById('listaEmpleadosConActivos');
const statsResumen              = document.getElementById('statsResumen');
const statsDashboard            = document.getElementById('statsDashboard');

const countAreas     = document.getElementById('countAreas');
const countEmpleados = document.getElementById('countEmpleados');
const countTodos     = document.getElementById('countTodos');

// ============ ESTADO ============
let activosCache    = [];
let empleadosCache  = [];
let areasCache      = [];
let areasExpandidas = new Set();
let empleadosExpandidos = new Set();

// ============ INIT ============
document.addEventListener('DOMContentLoaded', init);

async function init() {
    wireTabs();
    wireEvents();
    await cargarTodo();
}

async function cargarTodo() {
    try {
        await Promise.all([cargarAreas(), cargarEmpleados(), cargarActivos()]);
        llenarFiltros();
        renderTodo();
    } catch (err) {
        App.toast(err.message, 'error');
        console.error(err);
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

async function cargarActivos() {
    const res = await App.api('activos.php');
    if (!res.ok) throw new Error(res.error);
    activosCache = res.data;
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
    filtroArea.addEventListener('change', renderTodo);
    filtroEmpleado.addEventListener('change', renderTodo);

    btnExpandir.addEventListener('click', () => {
        areasCache.forEach(a => areasExpandidas.add(a.id));
        empleadosCache.forEach(e => empleadosExpandidos.add(e.id));
        renderAreasConActivos();
        renderEmpleadosConActivos();
    });

    btnContraer.addEventListener('click', () => {
        areasExpandidas.clear();
        empleadosExpandidos.clear();
        renderAreasConActivos();
        renderEmpleadosConActivos();
    });

    // Delegación de clicks en cards de áreas
    listaAreasConActivos.addEventListener('click', onAreaCardClick);
    listaEmpleadosConActivos.addEventListener('click', onEmpleadoCardClick);

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

    // Autocompletar observaciones
    selectEmp.addEventListener('change', function() {
        const obs = form.querySelector('textarea[name="observaciones"]');
        if (this.value && (!obs.value || obs.value.trim() === '')) {
            const txt = this.options[this.selectedIndex].text;
            const match = txt.match(/\]\s+(.+?)\s+—/);
            if (match) {
                obs.value = 'ASIGNADO A ' + match[1];
            }
        }
    });
}

// ============ FILTROS ============
function llenarFiltros() {
    const areasUnicas = [...new Map(areasCache.map(a => [a.id, a])).values()]
        .sort((a, b) => a.nombre.localeCompare(b.nombre));
    filtroArea.innerHTML = '<option value="">🏢 Todas las áreas</option>' +
        areasUnicas.map(a => `<option value="${a.id}">${App.esc(a.nombre)}</option>`).join('');

    const empOrdenados = [...empleadosCache].sort((a, b) => a.nombre.localeCompare(b.nombre));
    filtroEmpleado.innerHTML = '<option value="">👤 Todos los empleados</option>' +
        empOrdenados.map(e => `<option value="${e.id}">[${e.numero_nomina}] ${App.esc(e.nombre)}</option>`).join('');
}

// ============ FILTRADO ============
function getActivosFiltrados() {
    const q = (busqueda.value || '').toLowerCase().trim();
    const areaId = filtroArea.value ? parseInt(filtroArea.value, 10) : null;
    const empId = filtroEmpleado.value ? parseInt(filtroEmpleado.value, 10) : null;

    return activosCache.filter(a => {
        // Filtro por área
        if (areaId !== null && a.area_id !== areaId) return false;
        // Filtro por empleado
        if (empId !== null && a.empleado_id !== empId) return false;
        // Filtro por búsqueda
        if (q) {
            const campos = [
                a.descripcion, a.num_inventario, a.marca, a.modelo, a.serie,
                a.material, a.empleado_nombre, a.area_nombre
            ].map(x => (x || '').toLowerCase());
            if (!campos.some(c => c.includes(q))) return false;
        }
        return true;
    });
}

// ============ RENDER: TODO ============
function renderTodo() {
    const activosFiltrados = getActivosFiltrados();

    renderAreasConActivos(activosFiltrados);
    renderEmpleadosConActivos(activosFiltrados);
    renderTablaTodos(activosFiltrados);
    renderEstadisticas(activosFiltrados);
    actualizarContadores(activosFiltrados);
}

function actualizarContadores(activosFiltrados = activosCache) {
    const areasConActivos = new Set(activosFiltrados.filter(a => a.area_id).map(a => a.area_id));
    const empConActivos = new Set(activosFiltrados.filter(a => a.empleado_id).map(a => a.empleado_id));

    countAreas.textContent = areasConActivos.size;
    countEmpleados.textContent = empConActivos.size;
    countTodos.textContent = activosFiltrados.length;
}

// ============ RENDER: POR ÁREA ============
function renderAreasConActivos(activosFiltrados = null) {
    if (!activosFiltrados) activosFiltrados = getActivosFiltrados();

    // Agrupar por área
    const grupos = new Map();
    areasCache.forEach(a => grupos.set(a.id, { area: a, activos: [] }));

    const sinArea = { area: null, activos: [] };

    activosFiltrados.forEach(act => {
        if (act.area_id && grupos.has(act.area_id)) {
            grupos.get(act.area_id).activos.push(act);
        } else {
            sinArea.activos.push(act);
        }
    });

    // Ordenar áreas por nombre
    const gruposArray = [...grupos.values()].sort((a, b) => a.area.nombre.localeCompare(b.area.nombre));

    // Filtrar las que tienen activos (para no mostrar vacías)
    const conActivos = gruposArray.filter(g => g.activos.length > 0);

    if (!conActivos.length && !sinArea.activos.length) {
        listaAreasConActivos.innerHTML = `
            <div class="card empty-state">
                <div class="empty-state__icon">📦</div>
                <p>No hay activos que coincidan con la búsqueda.</p>
            </div>
        `;
        return;
    }

    let html = '';

    // Card de "Sin área asignada" primero
    if (sinArea.activos.length > 0) {
        html += renderAreaCard(sinArea.area, sinArea.activos, true);
    }

    // Resto de áreas
    html += conActivos.map(g => renderAreaCard(g.area, g.activos, false)).join('');

    listaAreasConActivos.innerHTML = html;
}

function renderAreaCard(area, activos, esSinArea) {
    const areaId = area ? area.id : '__sin_area__';
    const isOpen = areasExpandidas.has(areaId);
    const totalCosto = activos.reduce((s, a) => s + parseFloat(a.costo || 0), 0);
    const numAct = activos.length;

    const nombre = area ? area.nombre : '📭 Sin área asignada';
    const clave = area ? area.clave : '—';
    const descripcion = area && area.descripcion ? area.descripcion : '';

    // Agrupar activos por empleado
    const porEmpleado = new Map();
    activos.forEach(a => {
        const key = a.empleado_id || '__sin_emp__';
        if (!porEmpleado.has(key)) {
            porEmpleado.set(key, {
                empleado_id: a.empleado_id,
                nombre: a.empleado_nombre || '📭 Sin resguardante',
                cargo: a.cargo || '',
                numero_nomina: a.numero_nomina || '',
                activos: []
            });
        }
        porEmpleado.get(key).activos.push(a);
    });

    const empleadosHTML = [...porEmpleado.values()].map(g => {
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
                    ${g.activos.map(a => renderActivoFila(a)).join('')}
                </div>
            </div>
        `;
    }).join('');

    const descripcionHTML = descripcion
        ? `<div class="area-card__desc">${App.esc(descripcion)}</div>`
        : '';

    return `
        <div class="area-card ${isOpen ? 'is-open' : ''}" data-area-id="${areaId}">
            <div class="area-card__header" data-action="toggle-area">
                <div class="area-card__info">
                    <span class="area-card__icon">${area ? '🏢' : '📭'}</span>
                    <div class="area-card__body">
                        <h3 class="area-card__name">${App.esc(nombre)}</h3>
                        <div class="area-card__meta">
                            ${area ? `<code>${App.esc(clave)}</code>` : ''}
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

function renderActivoFila(a) {
    let imgPath = a.ruta_imagen || '';
    if (imgPath.startsWith('public/')) {
        imgPath = imgPath.replace('public/', '');
    }
    const thumb = imgPath
        ? `<img class="activo-thumb" src="${App.esc(imgPath)}" alt="Foto" loading="lazy" onclick="viewImage('${App.esc(imgPath)}')" style="cursor: pointer;">`
        : `<div class="activo-noimg">S/F</div>`;

    return `
        <div class="activo-fila">
            <div class="activo-fila__thumb">${thumb}</div>
            <div class="activo-fila__info">
                <div class="activo-fila__desc">${App.esc(a.descripcion)}</div>
                <div class="activo-fila__meta">
                    <code>${App.esc(a.num_inventario)}</code>
                    ${a.marca ? ` · ${App.esc(a.marca)}` : ''}
                    ${a.modelo ? ` · ${App.esc(a.modelo)}` : ''}
                </div>
            </div>
            <div class="activo-fila__costo">${App.money(a.costo)}</div>
            <div class="activo-fila__acciones">
                <button class="btn btn--ghost btn--sm" data-action="editar-activo" data-id="${a.id}" title="Editar">✏️</button>
                <button class="btn btn--danger btn--sm" data-action="eliminar-activo" data-id="${a.id}" data-desc="${App.esc(a.descripcion)}" title="Eliminar">🗑️</button>
            </div>
        </div>
    `;
}

function onAreaCardClick(e) {
    const target = e.target.closest('[data-action]');
    if (!target) return;

    const action = target.dataset.action;
    const areaCard = e.target.closest('.area-card');
    const areaIdRaw = areaCard ? areaCard.dataset.areaId : null;

    const inActions = e.target.closest('.area-card__actions');
    if (action === 'toggle-area' && inActions) return;

    switch (action) {
        case 'toggle-area':
            if (areaIdRaw) {
                const areaId = areaIdRaw === '__sin_area__' ? '__sin_area__' : parseInt(areaIdRaw, 10);
                if (areasExpandidas.has(areaId)) areasExpandidas.delete(areaId);
                else areasExpandidas.add(areaId);
                renderAreasConActivos();
            }
            break;
        case 'editar-activo':
            editarActivo(parseInt(target.dataset.id, 10));
            break;
        case 'eliminar-activo':
            eliminarActivo(parseInt(target.dataset.id, 10), target.dataset.desc);
            break;
    }
}

// ============ RENDER: POR EMPLEADO ============
function renderEmpleadosConActivos(activosFiltrados = null) {
    if (!activosFiltrados) activosFiltrados = getActivosFiltrados();

    // Agrupar por empleado
    const grupos = new Map();
    empleadosCache.forEach(e => grupos.set(e.id, { empleado: e, activos: [] }));
    const sinEmp = { empleado: null, activos: [] };

    activosFiltrados.forEach(a => {
        if (a.empleado_id && grupos.has(a.empleado_id)) {
            grupos.get(a.empleado_id).activos.push(a);
        } else {
            sinEmp.activos.push(a);
        }
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
    if (sinEmp.activos.length > 0) {
        html += renderEmpleadoCard(null, sinEmp.activos, true);
    }
    html += conActivos.map(g => renderEmpleadoCard(g.empleado, g.activos, false)).join('');

    listaEmpleadosConActivos.innerHTML = html;
}

function renderEmpleadoCard(empleado, activos, esSinEmp) {
    const empId = empleado ? empleado.id : '__sin_emp__';
    const isOpen = empleadosExpandidos.has(empId);
    const totalCosto = activos.reduce((s, a) => s + parseFloat(a.costo || 0), 0);

    const nombre = empleado ? empleado.nombre : '📭 Sin resguardante';
    const nomina = empleado ? empleado.numero_nomina : '';
    const cargo = empleado ? empleado.cargo : '';
    const areaNombre = empleado && empleado.area_nombre ? empleado.area_nombre : '';

    return `
        <div class="area-card ${isOpen ? 'is-open' : ''}" data-emp-id="${empId}">
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
                            ${areaNombre ? `<span class="badge">🏢 ${App.esc(areaNombre)}</span>` : ''}
                            <span class="badge">${activos.length} activo${activos.length === 1 ? '' : 's'}</span>
                            <span class="badge badge--info">${App.money(totalCosto)}</span>
                        </div>
                    </div>
                </div>
                <div class="area-card__actions" data-action="stop">
                    <button class="area-card__toggle" data-action="toggle-emp" title="Expandir/Contraer">▾</button>
                </div>
            </div>
            <div class="area-card__content">
                <div class="activo-lista">
                    ${activos.map(a => renderActivoFila(a)).join('')}
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
    const empIdRaw = card ? card.dataset.empId : null;

    const inActions = e.target.closest('.area-card__actions');
    if (action === 'toggle-emp' && inActions) return;

    switch (action) {
        case 'toggle-emp':
            if (empIdRaw) {
                const empId = empIdRaw === '__sin_emp__' ? '__sin_emp__' : parseInt(empIdRaw, 10);
                if (empleadosExpandidos.has(empId)) empleadosExpandidos.delete(empId);
                else empleadosExpandidos.add(empId);
                renderEmpleadosConActivos();
            }
            break;
        case 'editar-activo':
            editarActivo(parseInt(target.dataset.id, 10));
            break;
        case 'eliminar-activo':
            eliminarActivo(parseInt(target.dataset.id, 10), target.dataset.desc);
            break;
    }
}

// ============ RENDER: TABLA TODOS ============
function renderTablaTodos(activosFiltrados = null) {
    if (!activosFiltrados) activosFiltrados = getActivosFiltrados();

    // Stats resumen
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
            <span class="stat__icon">💰</span>
            <div>
                <div class="stat__num">${App.money(totalCosto)}</div>
                <div class="stat__lbl">Valor total</div>
            </div>
        </div>
    `;

    if (!activosFiltrados.length) {
        tablaBody.innerHTML = '<tr><td colspan="9"><div class="empty-state"><div class="empty-state__icon">📦</div>No hay activos que coincidan.</div></td></tr>';
        return;
    }

    tablaBody.innerHTML = activosFiltrados.map(a => {
        let imgPath = a.ruta_imagen || '';
        if (imgPath.startsWith('public/')) imgPath = imgPath.replace('public/', '');

        const empTxt = a.empleado_id
            ? `<strong>${App.esc(a.empleado_nombre || '')}</strong><br><small>${App.esc(a.cargo || '')}</small>`
            : '<span class="badge badge--warning">Sin asignar</span>';

        const areaTxt = a.area_nombre
            ? '🏢 ' + App.esc(a.area_nombre)
            : '<span class="muted">—</span>';

        return `
            <tr>
                <td><strong>${App.esc(a.descripcion)}</strong></td>
                <td>${App.esc(a.num_inventario)}</td>
                <td>${App.esc(a.marca || '—')}</td>
                <td>${App.esc(a.modelo || '—')}</td>
                <td>${App.money(a.costo)}</td>
                <td>${empTxt}</td>
                <td>${areaTxt}</td>
                <td class="col-foto">
                    ${imgPath
                        ? `<img class="table__thumb" src="${App.esc(imgPath)}" alt="Foto" loading="lazy" onclick="viewImage('${App.esc(imgPath)}')" style="cursor: pointer;">`
                        : `<div class="table__noimg">S/F</div>`}
                </td>
                <td>
                    <div class="table__actions">
                        <button class="btn btn--ghost btn--sm" onclick="editarActivo(${a.id})">✏️</button>
                        <button class="btn btn--danger btn--sm" onclick="eliminarActivo(${a.id}, '${App.esc(a.descripcion).replace(/'/g, "\\'")}')">🗑️</button>
                    </div>
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
    const areasConActivos = new Set(activosFiltrados.filter(a => a.area_id).map(a => a.area_id)).size;

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

    // Distribución por área
    const porArea = new Map();
    activosFiltrados.forEach(a => {
        if (!a.area_id) return;
        const k = a.area_id;
        if (!porArea.has(k)) porArea.set(k, { nombre: a.area_nombre, activos: 0, costo: 0 });
        const g = porArea.get(k);
        g.activos++;
        g.costo += parseFloat(a.costo || 0);
    });
    const areasOrdenadas = [...porArea.values()].sort((a, b) => b.costo - a.costo);

    const html = `
        <div class="stats">
            <div class="stat">
                <span class="stat__icon">📦</span>
                <div>
                    <div class="stat__num">${total}</div>
                    <div class="stat__lbl">Activos totales</div>
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

        <div class="grid-2">
            <div class="card">
                <h2 class="card__title">🏆 Top empleados por valor de activos</h2>
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

            <div class="card">
                <h2 class="card__title">🏢 Distribución por área</h2>
                ${areasOrdenadas.length === 0
                    ? '<p class="muted">No hay datos suficientes.</p>'
                    : areasOrdenadas.map(a => `
                        <div class="rank-item">
                            <span class="rank-item__pos">🏢</span>
                            <div class="rank-item__info">
                                <div class="rank-item__name">${App.esc(a.nombre)}</div>
                                <div class="rank-item__meta">${a.activos} activo${a.activos === 1 ? '' : 's'}</div>
                            </div>
                            <div class="rank-item__value">${App.money(a.costo)}</div>
                        </div>
                    `).join('')
                }
                ${sinAsignar > 0 ? `
                    <div class="rank-item" style="margin-top: 0.5rem; padding-top: 0.75rem; border-top: 1px dashed var(--c-border);">
                        <span class="rank-item__pos">📭</span>
                        <div class="rank-item__info">
                            <div class="rank-item__name">Sin área asignada</div>
                            <div class="rank-item__meta">${sinAsignar} activo${sinAsignar === 1 ? '' : 's'}</div>
                        </div>
                        <div class="rank-item__value">
                            ${App.money(activosFiltrados.filter(a => !a.area_id).reduce((s, a) => s + parseFloat(a.costo || 0), 0))}
                        </div>
                    </div>
                ` : ''}
            </div>
        </div>
    `;

    statsDashboard.innerHTML = html;
}

// ============ MODAL ACTIVO ============
function abrirModalActivo() {
    form.reset();
    form.elements['id'].value = '';
    form.elements['ruta_imagen'].value = '';
    form.elements['observaciones'].value = '';
    modalTitle.textContent = '➕ Nuevo activo';
    imgPreview.innerHTML = '<div class="image-preview__placeholder">Sin imagen</div>';
    formError.style.display = 'none';
    App.openModal('activoModal');
}

async function editarActivo(id) {
    try {
        const res = await App.api(`activos.php?id=${id}`);
        if (!res.ok) throw new Error(res.error);
        const a = res.data;
        App.fillForm(form, a);
        modalTitle.textContent = '✏️ Editar activo';
        if (a.ruta_imagen) {
            imgPreview.innerHTML = `<img src="${App.esc(a.ruta_imagen)}" alt="Foto actual">`;
        } else {
            imgPreview.innerHTML = '<div class="image-preview__placeholder">Sin imagen</div>';
        }
        formError.style.display = 'none';
        App.openModal('activoModal');
    } catch (err) {
        App.toast(err.message, 'error');
    }
}

async function eliminarActivo(id, desc) {
    if (!await App.confirm(`¿Eliminar el activo "${desc}"?`)) return;
    try {
        const res = await App.api(`activos.php?id=${id}`, { method: 'DELETE' });
        if (!res.ok) throw new Error(res.error);
        App.toast(res.message, 'success');
        await cargarActivos();
        renderTodo();
    } catch (err) {
        App.toast(err.message, 'error');
    }
}

async function onSubmitActivo(e) {
    e.preventDefault();
    const id = form.elements['id'].value;

    const desc = form.descripcion.value.trim();
    const ni   = form.num_inventario.value.trim();
    const costo = form.costo.value;

    if (desc.length < 2)         return mostrarError('La descripción es obligatoria');
    if (!ni)                     return mostrarError('El número de inventario es obligatorio');
    if (!costo || costo < 0)     return mostrarError('El costo es obligatorio y no puede ser negativo');

    let rutaImagen = form.elements['ruta_imagen'].value || null;

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
            if (!j.ok) throw new Error(j.error);
            rutaImagen = j.ruta;
        } catch (err) {
            return mostrarError('Error al subir imagen: ' + err.message);
        }
    }

    const data = {
        descripcion:    desc,
        num_inventario: ni,
        marca:          form.marca.value.trim(),
        modelo:         form.modelo.value.trim(),
        serie:          form.serie.value.trim() || 'S/S',
        material:       form.material.value.trim(),
        fecha_adq:      form.fecha_adq.value,
        factura:        form.factura.value.trim(),
        costo:          parseFloat(costo),
        observaciones:  form.observaciones.value.trim(),
        empleado_id:    form.empleado_id.value ? parseInt(form.empleado_id.value, 10) : null,
        ruta_imagen:    rutaImagen,
    };

    const method = id ? 'PUT' : 'POST';
    const url    = id ? `activos.php?id=${id}` : 'activos.php';

    try {
        const res = await App.api(url, { method, body: data });
        if (!res.ok) throw new Error(res.error);
        App.toast(res.message, 'success');
        App.closeModal('activoModal');
        await cargarActivos();
        renderTodo();
    } catch (err) {
        mostrarError(err.message);
    }
}

function mostrarError(msg) {
    formError.textContent = msg;
    formError.style.display = 'block';
}

// ============ LLENAR SELECT DE EMPLEADOS ============
function llenarSelectEmpleados() {
    const opciones = ['<option value="">— Sin asignar —</option>'].concat(
        empleadosCache.map(e => `<option value="${e.id}">[${e.numero_nomina}] ${App.esc(e.nombre)} — ${App.esc(e.cargo)}</option>`)
    );
    selectEmp.innerHTML = opciones.join('');
}

// Llamar al llenar filtros también
const _originalLlenarFiltros = llenarFiltros;
llenarFiltros = function() {
    _originalLlenarFiltros();
    llenarSelectEmpleados();
};

// ============ EXPONER AL WINDOW ============
window.editarActivo = editarActivo;
window.eliminarActivo = eliminarActivo;