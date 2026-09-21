/* =====================================================
   empleados.js — CRUD de empleados
   ===================================================== */

const form       = document.getElementById('empleadoForm');
const tablaBody  = document.querySelector('#tablaEmpleados tbody');
const modal      = document.getElementById('empleadoModal');
const btnNuevo   = document.getElementById('btnNuevo');
const modalTitle = document.getElementById('modalTitle');
const formError  = document.getElementById('formError');
const busqueda   = document.getElementById('busqueda');
const statsBox   = document.getElementById('stats');

let empleadosCache = [];

document.addEventListener('DOMContentLoaded', cargar);

async function cargar() {
    tablaBody.innerHTML = '<tr><td colspan="6" class="loading"><span class="spinner"></span> Cargando...</td></tr>';
    try {
        // Cargamos empleados y activos en paralelo para poder contar los bienes
        // asignados por empleado (la columna "Bienes").
        const [resEmp, resAct] = await Promise.all([
            App.api('empleados.php'),
            App.api('activos.php'),
        ]);
        if (!resEmp.ok) throw new Error(resEmp.error);
        if (!resAct.ok) throw new Error(resAct.error);
        empleadosCache = resEmp.data;
        // Cache expuesto a render() para contar bienes por empleado.
        window._activosCache = resAct.data;
        render(empleadosCache);
        cargarStats();
    } catch (err) {
        tablaBody.innerHTML = `<tr><td colspan="6" class="empty-state">${App.esc(err.message)}</td></tr>`;
        App.toast(err.message, 'error');
    }
}

async function cargarStats() {
    try {
        const res = await App.api('empleados.php');
        const data = res.data || [];
        statsBox.innerHTML = `
            <div class="stat">
                <span class="stat__icon">👥</span>
                <div>
                    <div class="stat__num">${data.length}</div>
                    <div class="stat__lbl">Empleados registrados</div>
                </div>
            </div>
        `;
    } catch (e) { /* noop */ }
}

function render(lista) {
    if (!lista.length) {
        tablaBody.innerHTML = '<tr><td colspan="6"><div class="empty-state"><div class="empty-state__icon">📭</div>No hay empleados. Pulsa "Nuevo empleado" para empezar.</div></td></tr>';
        return;
    }
    // Para mostrar el número de bienes por empleado, cruzamos con activosCache
    // (cargado en cargarEmpleados() / o se hace un conteo local).
    tablaBody.innerHTML = lista.map(e => {
        const bienesCount = (window._activosCache || []).filter(a => a.empleado_id === e.id).length;
        return `
        <tr>
            <td>${e.id}</td>
            <td><strong>${App.esc(e.numero_nomina)}</strong></td>
            <td>${App.esc(e.nombre)}</td>
            <td>${App.esc(e.cargo)}</td>
            <td><span class="badge">${bienesCount}</span></td>
            <td>
                <div class="table__actions">
                    <button class="btn btn--ghost btn--sm" onclick="editar(${e.id})">✏️ Editar</button>
                    <button class="btn btn--danger btn--sm" onclick="eliminar(${e.id}, '${App.esc(e.nombre).replace(/'/g, "\\'")}')">🗑️ Eliminar</button>
                </div>
            </td>
        </tr>
    `;}).join('');
}

busqueda.addEventListener('input', () => {
    const q = busqueda.value.toLowerCase().trim();
    if (!q) { render(empleadosCache); return; }
    const filtrados = empleadosCache.filter(e =>
        String(e.numero_nomina).includes(q) ||
        e.nombre.toLowerCase().includes(q) ||
        e.cargo.toLowerCase().includes(q)
    );
    render(filtrados);
});

btnNuevo.addEventListener('click', () => {
    form.reset();
    form.id.value = '';
    modalTitle.textContent = '➕ Nuevo empleado';
    formError.style.display = 'none';
    App.openModal('empleadoModal');
});

window.editar = async (id) => {
    try {
        const res = await App.api(`empleados.php?id=${id}`);
        if (!res.ok) throw new Error(res.error);
        App.fillForm(form, res.data);
        modalTitle.textContent = '✏️ Editar empleado';
        formError.style.display = 'none';
        App.openModal('empleadoModal');
    } catch (err) {
        App.toast(err.message, 'error');
    }
};

window.eliminar = async (id, nombre) => {
    if (!await App.confirm(`¿Eliminar a "${nombre}"?\nLos bienes asignados quedarán sin resguardante.`)) return;
    try {
        const res = await App.api(`empleados.php?id=${id}`, { method: 'DELETE' });
        if (!res.ok) throw new Error(res.error);
        App.toast(res.message, 'success');
        cargar();
    } catch (err) {
        App.toast(err.message, 'error');
    }
};

form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const data = App.formToObject(form);
    const id   = data.id;
    delete data.id;

    // Validaciones cliente
    if (!data.numero_nomina || data.numero_nomina <= 0) return mostrarError('El número de nómina debe ser positivo');
    if (data.nombre.length < 3) return mostrarError('El nombre es obligatorio (mín. 3 caracteres)');
    if (data.cargo.length < 2)  return mostrarError('El cargo es obligatorio');

    const method = id ? 'PUT' : 'POST';
    const url    = id ? `empleados.php?id=${id}` : 'empleados.php';

    try {
        const res = await App.api(url, { method, body: data });
        if (!res.ok) throw new Error(res.error);
        App.toast(res.message, 'success');
        App.closeModal('empleadoModal');
        cargar();
    } catch (err) {
        mostrarError(err.message);
    }
});

function mostrarError(msg) {
    formError.textContent = msg;
    formError.style.display = 'block';
}
