/* =====================================================
   areas.js — CRUD de áreas / departamentos
   ===================================================== */

const form       = document.getElementById('areaForm');
const tablaBody  = document.querySelector('#tablaAreas tbody');
const modal      = document.getElementById('areaModal');
const btnNuevo   = document.getElementById('btnNuevo');
const modalTitle = document.getElementById('modalTitle');
const formError  = document.getElementById('formError');
const busqueda   = document.getElementById('busqueda');
const statsBox   = document.getElementById('stats');

let areasCache = [];

document.addEventListener('DOMContentLoaded', cargar);

async function cargar() {
    tablaBody.innerHTML = '<tr><td colspan="7" class="loading"><span class="spinner"></span> Cargando...</td></tr>';
    try {
        const res = await App.api('areas.php');
        if (!res.ok) throw new Error(res.error);
        areasCache = res.data;
        render(areasCache);
        cargarStats();
    } catch (err) {
        tablaBody.innerHTML = `<tr><td colspan="7" class="empty-state">${App.esc(err.message)}</td></tr>`;
        App.toast(err.message, 'error');
    }
}

function cargarStats() {
    statsBox.innerHTML = `
        <div class="stat">
            <span class="stat__icon">🏢</span>
            <div>
                <div class="stat__num">${areasCache.length}</div>
                <div class="stat__lbl">Áreas registradas</div>
            </div>
        </div>
    `;
}

function render(lista) {
    if (!lista.length) {
        tablaBody.innerHTML = '<tr><td colspan="7"><div class="empty-state"><div class="empty-state__icon">🏢</div>No hay áreas registradas.</div></td></tr>';
        return;
    }
    tablaBody.innerHTML = lista.map(a => {
        const esGeneral = a.clave === 'general';
        const estadoBadge = a.activo
            ? '<span class="badge badge--active">Activo</span>'
            : '<span class="badge badge--inactive">Inactivo</span>';
        return `
            <tr>
                <td>${a.id}</td>
                <td><code>${App.esc(a.clave)}</code>${esGeneral ? ' <span class="badge badge--info">por defecto</span>' : ''}</td>
                <td><strong>${App.esc(a.nombre)}</strong></td>
                <td>${a.descripcion ? App.esc(a.descripcion) : '<span class="muted">—</span>'}</td>
                <td><span class="badge">${a.usuarios_count}</span></td>
                <td>${estadoBadge}</td>
                <td>
                    <div class="table__actions">
                        <button class="btn btn--ghost btn--sm" onclick="editar(${a.id})">✏️</button>
                        ${esGeneral ? '' : `<button class="btn btn--danger btn--sm" onclick="eliminar(${a.id}, '${App.esc(a.nombre).replace(/'/g, "\\'")}')">🗑️</button>`}
                    </div>
                </td>
            </tr>
        `;
    }).join('');
}

busqueda.addEventListener('input', () => {
    const q = busqueda.value.toLowerCase().trim();
    if (!q) { render(areasCache); return; }
    render(areasCache.filter(a =>
        a.clave.toLowerCase().includes(q) || a.nombre.toLowerCase().includes(q)
    ));
});

btnNuevo.addEventListener('click', () => {
    form.reset();
    form.id.value = '';
    form.activo.checked = true;
    modalTitle.textContent = '➕ Nueva área';
    formError.style.display = 'none';
    App.openModal('areaModal');
});

window.editar = async (id) => {
    try {
        const res = await App.api(`areas.php?id=${id}`);
        if (!res.ok) throw new Error(res.error);
        App.fillForm(form, res.data);
        form.id.value = id;
        form.activo.checked = !!res.data.activo;
        modalTitle.textContent = '✏️ Editar área';
        formError.style.display = 'none';
        App.openModal('areaModal');
    } catch (err) {
        App.toast(err.message, 'error');
    }
};

window.eliminar = async (id, nombre) => {
    if (!await App.confirm(`¿Eliminar el área "${nombre}"?\nSolo es posible si no hay usuarios asignados.`)) return;
    try {
        const res = await App.api(`areas.php?id=${id}`, { method: 'DELETE' });
        if (!res.ok) throw new Error(res.error);
        App.toast(res.message, 'success');
        cargar();
    } catch (err) {
        App.toast(err.message, 'error');
    }
};

form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const id = form.id.value;
    const data = {
        clave:       form.clave.value.trim(),
        nombre:      form.nombre.value.trim(),
        descripcion: form.descripcion.value.trim(),
        activo:      form.activo.checked,
    };
    if (data.clave.length < 2)  return mostrarError('La clave es obligatoria (mín. 2 caracteres)');
    if (data.nombre.length < 2) return mostrarError('El nombre es obligatorio');

    const method = id ? 'PUT' : 'POST';
    const url    = id ? `areas.php?id=${id}` : 'areas.php';

    try {
        const res = await App.api(url, { method, body: data });
        if (!res.ok) throw new Error(res.error);
        App.toast(res.message, 'success');
        App.closeModal('areaModal');
        cargar();
    } catch (err) {
        mostrarError(err.message);
    }
});

function mostrarError(msg) {
    formError.textContent = msg;
    formError.style.display = 'block';
}