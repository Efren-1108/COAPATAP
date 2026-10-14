/* =====================================================
   adscripciones.js — CRUD con vista expandible de áreas
   ===================================================== */

// ============ REFERENCIAS ============
const form            = document.getElementById('adscripcionForm');
const listaAdsc       = document.getElementById('listaAdscripciones');
const btnNuevo        = document.getElementById('btnNuevo');
const modalTitle      = document.getElementById('modalTitle');
const formError       = document.getElementById('formError');
const busqueda        = document.getElementById('busqueda');
const statsBox        = document.getElementById('stats');

const areaForm        = document.getElementById('areaForm');
const areaModalTitle  = document.getElementById('modalTitleArea');
const formErrorArea   = document.getElementById('formErrorArea');

// ============ ESTADO ============
let adscripcionesCache = [];
let areasCache         = [];  // TODAS las áreas (se cargan una vez)
let expandidas         = new Set();  // IDs de adscripciones abiertas

// ============ INIT ============
document.addEventListener('DOMContentLoaded', cargar);

async function cargar() {
    listaAdsc.innerHTML = '<div class="card loading"><span class="spinner"></span> Cargando...</div>';
    try {
        // Cargar adscripciones y áreas en paralelo
        const [resAdsc, resAreas] = await Promise.all([
            App.api('adscripciones.php'),
            App.api('areas.php'),
        ]);
        if (!resAdsc.ok) throw new Error(resAdsc.error);
        if (!resAreas.ok) throw new Error(resAreas.error);

        adscripcionesCache = resAdsc.data;
        areasCache         = resAreas.data;

        render(adscripcionesCache);
        cargarStats();
    } catch (err) {
        listaAdsc.innerHTML = `<div class="card empty-state">${App.esc(err.message)}</div>`;
        App.toast(err.message, 'error');
    }
}

async function recargar() {
    try {
        const [resAdsc, resAreas] = await Promise.all([
            App.api('adscripciones.php'),
            App.api('areas.php'),
        ]);
        if (resAdsc.ok)  adscripcionesCache = resAdsc.data;
        if (resAreas.ok) areasCache         = resAreas.data;

        render(adscripcionesCache);
        cargarStats();
    } catch (err) {
        App.toast(err.message, 'error');
    }
}

// ============ STATS ============
function cargarStats() {
    const total = adscripcionesCache.length;
    const activas = adscripcionesCache.filter(a => a.activo).length;
    const totalAreas = adscripcionesCache.reduce((s, a) => s + (parseInt(a.areas_count, 10) || 0), 0);
    const totalEmp = adscripcionesCache.reduce((s, a) => s + (parseInt(a.empleados_count, 10) || 0), 0);

    statsBox.innerHTML = `
        <div class="stat">
            <span class="stat__icon">🏛️</span>
            <div>
                <div class="stat__num">${total}</div>
                <div class="stat__lbl">Adscripciones totales</div>
            </div>
        </div>
        <div class="stat">
            <span class="stat__icon">✅</span>
            <div>
                <div class="stat__num">${activas}</div>
                <div class="stat__lbl">Activas</div>
            </div>
        </div>
        <div class="stat">
            <span class="stat__icon">🏢</span>
            <div>
                <div class="stat__num">${totalAreas}</div>
                <div class="stat__lbl">Áreas vinculadas</div>
            </div>
        </div>
        <div class="stat">
            <span class="stat__icon">👥</span>
            <div>
                <div class="stat__num">${totalEmp}</div>
                <div class="stat__lbl">Empleados totales</div>
            </div>
        </div>
    `;
}

// ============ RENDER ============
function render(lista) {
    if (!lista.length) {
        listaAdsc.innerHTML = `
            <div class="card empty-state">
                <div class="empty-state__icon">🏛️</div>
                <p>No hay adscripciones. Pulsa "Nueva adscripción" para empezar.</p>
            </div>
        `;
        return;
    }

    listaAdsc.innerHTML = lista.map(a => renderAdscripcionCard(a)).join('');
}

function renderAdscripcionCard(adsc) {
    const esGeneral  = adsc.clave === 'direccion-general';
    const isOpen     = expandidas.has(adsc.id);
    const areas      = areasCache.filter(ar => ar.adscripcion_id === adsc.id);
    const numAreas   = areas.length;
    const numEmp     = parseInt(adsc.empleados_count, 10) || 0;

    const estadoBadge = adsc.activo
        ? '<span class="badge badge--active">Activa</span>'
        : '<span class="badge badge--inactive">Inactiva</span>';

    // Lista de áreas (solo si está expandida)
    const areasHTML = numAreas === 0
        ? `<div class="area-card__empty">Sin áreas asignadas a esta adscripción.</div>`
        : `<div class="areas-lista">
            ${areas.map(ar => {
                const empsArea = parseInt(ar.empleados_count, 10) || 0;
                const usersArea = parseInt(ar.usuarios_count, 10) || 0;
                return `
                    <div class="area-mini">
                        <div class="area-mini__info">
                            <span class="area-mini__icon">🏢</span>
                            <div>
                                <div class="area-mini__name">${App.esc(ar.nombre)}</div>
                                <div class="area-mini__meta">
                                    <code>${App.esc(ar.clave)}</code>
                                    <span class="badge">${empsArea} empleado${empsArea === 1 ? '' : 's'}</span>
                                    ${usersArea > 0 ? `<span class="badge badge--info">${usersArea} usuario${usersArea === 1 ? '' : 's'}</span>` : ''}
                                    ${!ar.activo ? '<span class="badge badge--inactive">Inactiva</span>' : ''}
                                </div>
                            </div>
                        </div>
                        <div class="area-mini__actions">
                            <button class="btn btn--ghost btn--sm"
                                    data-action="editar-area"
                                    data-id="${ar.id}"
                                    title="Editar área">✏️</button>
                            ${ar.clave !== 'general'
                                ? `<button class="btn btn--danger btn--sm"
                                           data-action="eliminar-area"
                                           data-id="${ar.id}"
                                           data-nombre="${App.esc(ar.nombre)}"
                                           title="Eliminar área">🗑️</button>`
                                : ''}
                        </div>
                    </div>
                `;
            }).join('')}
        </div>`;

    const descripcionHTML = adsc.descripcion
        ? `<div class="adsc-card__desc">${App.esc(adsc.descripcion)}</div>`
        : '';

    const direccionHTML = adsc.direccion
        ? `<div class="adsc-card__direccion">📍 ${App.esc(adsc.direccion)}</div>`
        : '';

    return `
        <div class="adsc-card ${isOpen ? 'is-open' : ''}" data-adsc-id="${adsc.id}">
            <div class="adsc-card__header" data-action="toggle-adsc">
                <div class="adsc-card__info">
                    <span class="adsc-card__icon">🏛️</span>
                    <div class="adsc-card__body">
                        <h3 class="adsc-card__name">
                            ${App.esc(adsc.nombre)}
                            ${esGeneral ? ' <span class="badge badge--info">por defecto</span>' : ''}
                        </h3>
                        <div class="adsc-card__meta">
                            <code>${App.esc(adsc.clave)}</code>
                            ${estadoBadge}
                            <span class="badge">${numAreas} área${numAreas === 1 ? '' : 's'}</span>
                            <span class="badge">${numEmp} empleado${numEmp === 1 ? '' : 's'}</span>
                        </div>
                    </div>
                </div>
                <div class="adsc-card__actions" data-action="stop">
                    <button class="btn btn--primary btn--sm"
                            data-action="nueva-area"
                            data-adsc-id="${adsc.id}"
                            title="Agregar área a esta adscripción">➕ Área</button>
                    <button class="btn btn--ghost btn--sm"
                            data-action="editar-adsc"
                            data-id="${adsc.id}"
                            title="Editar adscripción">✏️</button>
                    ${!esGeneral
                        ? `<button class="btn btn--danger btn--sm"
                                   data-action="eliminar-adsc"
                                   data-id="${adsc.id}"
                                   data-nombre="${App.esc(adsc.nombre)}"
                                   title="Eliminar adscripción">🗑️</button>`
                        : ''}
                    <button class="adsc-card__toggle" data-action="toggle-adsc" title="Expandir/Contraer">▾</button>
                </div>
            </div>
            <div class="adsc-card__content">
                ${direccionHTML}
                ${descripcionHTML}
                <div class="adsc-card__content-title">
                    🏢 Áreas de esta adscripción
                </div>
                ${areasHTML}
                <div class="adsc-card__footer">
                    <button class="btn btn--primary btn--sm"
                            data-action="nueva-area"
                            data-adsc-id="${adsc.id}">➕ Agregar área</button>
                </div>
            </div>
        </div>
    `;
}

// ============ DELEGACIÓN DE CLICKS ============
listaAdsc.addEventListener('click', (e) => {
    const target = e.target.closest('[data-action]');
    if (!target) return;

    const action = target.dataset.action;
    const card = e.target.closest('.adsc-card');
    const adscId = card ? parseInt(card.dataset.adscId, 10) : null;

    // Si el click fue en las acciones del header, no togglear
    const inActions = e.target.closest('.adsc-card__actions');
    if (action === 'toggle-adsc' && inActions) return;

    switch (action) {
        case 'toggle-adsc':
            if (adscId) {
                if (expandidas.has(adscId)) expandidas.delete(adscId);
                else expandidas.add(adscId);
                render(adscripcionesCache);
            }
            break;

        case 'editar-adsc':
            editarAdscripcion(parseInt(target.dataset.id, 10));
            break;

        case 'eliminar-adsc':
            eliminarAdscripcion(parseInt(target.dataset.id, 10), target.dataset.nombre);
            break;

        case 'nueva-area':
            abrirModalArea(null, parseInt(target.dataset.adscId, 10));
            break;

        case 'editar-area':
            abrirModalArea(parseInt(target.dataset.id, 10), null);
            break;

        case 'eliminar-area':
            eliminarArea(parseInt(target.dataset.id, 10), target.dataset.nombre);
            break;
    }
});

// ============ MODAL: ADSCRIPCIÓN ============
btnNuevo.addEventListener('click', () => {
    form.reset();
    form.elements['id'].value = '';
    form.elements['activo'].checked = true;
    modalTitle.textContent = '➕ Nueva adscripción';
    formError.style.display = 'none';
    App.openModal('adscripcionModal');
});

async function editarAdscripcion(id) {
    try {
        const res = await App.api(`adscripciones.php?id=${id}`);
        if (!res.ok) throw new Error(res.error);
        const a = res.data;
        App.fillForm(form, a);
        form.elements['id'].value = a.id;
        form.elements['activo'].checked = !!a.activo;
        modalTitle.textContent = '✏️ Editar adscripción';
        formError.style.display = 'none';
        App.openModal('adscripcionModal');
    } catch (err) {
        App.toast(err.message, 'error');
    }
}

form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const id = form.elements['id'].value;
    const data = {
        clave:       form.clave.value.trim(),
        nombre:      form.nombre.value.trim(),
        descripcion: form.descripcion.value.trim(),
        direccion:   form.direccion.value.trim(),
        activo:      form.activo.checked,
    };

    if (data.clave.length < 2)  return mostrarError('La clave es obligatoria (mín. 2 caracteres)');
    if (data.nombre.length < 2) return mostrarError('El nombre es obligatorio');

    const method = id ? 'PUT' : 'POST';
    const url    = id ? `adscripciones.php?id=${id}` : 'adscripciones.php';

    try {
        const res = await App.api(url, { method, body: data });
        if (!res.ok) throw new Error(res.error);
        App.toast(res.message, 'success');
        App.closeModal('adscripcionModal');
        await recargar();
    } catch (err) {
        mostrarError(err.message);
    }
});

async function eliminarAdscripcion(id, nombre) {
    if (!await App.confirm(`¿Eliminar la adscripción "${nombre}"?\nSolo es posible si no tiene áreas asignadas.`)) return;
    try {
        const res = await App.api(`adscripciones.php?id=${id}`, { method: 'DELETE' });
        if (!res.ok) throw new Error(res.error);
        App.toast(res.message, 'success');
        expandidas.delete(id);
        await recargar();
    } catch (err) {
        App.toast(err.message, 'error');
    }
}

function mostrarError(msg) {
    formError.textContent = msg;
    formError.style.display = 'block';
}

// ============ MODAL: ÁREA ============
function abrirModalArea(idArea, adscId) {
    areaForm.reset();
    areaForm.elements['id'].value = '';
    areaForm.elements['adscripcion_id'].value = adscId || '';
    areaForm.elements['activo'].checked = true;
    formErrorArea.style.display = 'none';

    if (idArea) {
        areaModalTitle.textContent = '✏️ Editar área';
        cargarAreaEnForm(idArea);
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
        areaForm.elements['adscripcion_id'].value = a.adscripcion_id || '';
        areaForm.elements['activo'].checked = !!a.activo;
    } catch (err) {
        App.toast(err.message, 'error');
        App.closeModal('areaModal');
    }
}

areaForm.addEventListener('submit', async (e) => {
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

    const method = id ? 'PUT' : 'POST';
    const url    = id ? `areas.php?id=${id}` : 'areas.php';

    try {
        const res = await App.api(url, { method, body: data });
        if (!res.ok) throw new Error(res.error);
        App.toast(res.message, 'success');
        App.closeModal('areaModal');
        await recargar();
    } catch (err) {
        mostrarErrorArea(err.message);
    }
});

async function eliminarArea(id, nombre) {
    if (!await App.confirm(`¿Eliminar el área "${nombre}"?`)) return;
    try {
        const res = await App.api(`areas.php?id=${id}`, { method: 'DELETE' });
        if (!res.ok) throw new Error(res.error);
        App.toast(res.message, 'success');
        await recargar();
    } catch (err) {
        App.toast(err.message, 'error');
    }
}

function mostrarErrorArea(msg) {
    formErrorArea.textContent = msg;
    formErrorArea.style.display = 'block';
}

// ============ BÚSQUEDA ============
busqueda.addEventListener('input', () => {
    const q = busqueda.value.toLowerCase().trim();
    if (!q) { render(adscripcionesCache); return; }
    render(adscripcionesCache.filter(a =>
        a.clave.toLowerCase().includes(q) ||
        a.nombre.toLowerCase().includes(q) ||
        (a.descripcion || '').toLowerCase().includes(q) ||
        (a.direccion || '').toLowerCase().includes(q)
    ));
});