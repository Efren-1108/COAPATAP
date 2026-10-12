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

document.addEventListener('DOMContentLoaded', cargar);

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
            <span class="stat__icon">🛡️</span>
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
        const estadoBadge = r.activo
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
                        <button class="btn btn--ghost btn--sm" onclick="editar(${r.id})">✏️</button>
                        ${esSistema ? '' : `<button class="btn btn--danger btn--sm" onclick="eliminar(${r.id}, '${App.esc(r.nombre).replace(/'/g, "\\'")}')">🗑️</button>`}
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

btnNuevo.addEventListener('click', () => {
    form.reset();
    form.id.value = '';
    form.activo.checked = true;
    modalTitle.textContent = '➕ Nuevo rol';
    formError.style.display = 'none';
    App.openModal('rolModal');
});

window.editar = async (id) => {
    try {
        const res = await App.api(`roles.php?id=${id}`);
        if (!res.ok) throw new Error(res.error);
        App.fillForm(form, res.data);
        form.id.value = id;
        form.activo.checked = !!res.data.activo;
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