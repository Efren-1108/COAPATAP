/* =====================================================
   usuarios.js — CRUD de usuarios (solo superusuario)
   ===================================================== */

const form        = document.getElementById('usuarioForm');
const tablaBody   = document.querySelector('#tablaUsuarios tbody');
const modal       = document.getElementById('usuarioModal');
const btnNuevo    = document.getElementById('btnNuevo');
const modalTitle  = document.getElementById('modalTitle');
const formError   = document.getElementById('formError');
const busqueda    = document.getElementById('busqueda');
const statsBox    = document.getElementById('stats');
const selectRol   = document.getElementById('rol_id');
const selectArea  = document.getElementById('area_id');
const passInput   = form.querySelector('input[name="password"]');
const passReq     = document.getElementById('passReq');
const areaReq     = document.getElementById('areaReq');

const resetForm   = document.getElementById('resetForm');
const resetModal  = document.getElementById('resetModal');
const resetError  = document.getElementById('resetError');
const resetName   = document.getElementById('resetUserName');

let usuariosCache = [];
let rolesCache    = [];
let areasCache    = [];

document.addEventListener('DOMContentLoaded', init);

async function init() {
    tablaBody.innerHTML = '<tr><td colspan="9" class="loading"><span class="spinner"></span> Cargando...</td></tr>';
    try {
        await Promise.all([cargarRoles(), cargarAreas(), cargarUsuarios()]);
        cargarStats();
        wireEvents();
    } catch (err) {
        tablaBody.innerHTML = `<tr><td colspan="9" class="empty-state">${App.esc(err.message)}</td></tr>`;
        App.toast(err.message, 'error');
    }
}

function wireEvents() {
    selectRol.addEventListener('change', toggleAreaRequired);

    // Evento para la lupa de búsqueda de nómina
    const btnBuscar = document.getElementById('btnBuscarNomina');
    if (btnBuscar) {
        btnBuscar.addEventListener('click', buscarEmpleadoPorNomina);
    }
}

async function buscarEmpleadoPorNomina() {
    const nominaInput = document.getElementById('user_nomina');
    const nomina = nominaInput?.value.trim();

    if (!nomina) {
        App.toast('Por favor, ingresa un número de nómina', 'warning');
        return;
    }

    try {
        // Llamada a la nueva API de autocompletado
        const res = await App.api(`buscar_empleado.php?nomina=${nomina}`);
        if (!res.ok) throw new Error(res.error);

        const emp = res.data;

        // Rellenar automáticamente los campos del formulario
        form.nombre_completo.value = emp.nombre;

        if (emp.area_id) {
            selectArea.value = emp.area_id;
            toggleAreaRequired();
        }

        App.toast(`Datos de ${emp.nombre} cargados correctamente`, 'success');
    } catch (err) {
        App.toast(err.message, 'error');
    }
}

function toggleAreaRequired() {
    const opt = selectRol.options[selectRol.selectedIndex];
    const clave = opt?.dataset.clave || '';
    if (clave === 'superusuario') {
        areaReq.style.display = 'none';
        selectArea.disabled = true;
        selectArea.value = '';
    } else {
        areaReq.style.display = '';
        selectArea.disabled = false;
    }
}

async function cargarRoles() {
    const res = await App.api('roles.php');
    if (!res.ok) throw new Error(res.error);
    rolesCache = res.data.filter(r => r.activo);
    selectRol.innerHTML = rolesCache
        .map(r => `<option value="${r.id}" data-clave="${App.esc(r.clave)}">${App.esc(r.nombre)}</option>`)
        .join('');
}

async function cargarAreas() {
    const res = await App.api('areas.php');
    if (!res.ok) throw new Error(res.error);
    areasCache = res.data.filter(a => a.activo);
    selectArea.innerHTML = '<option value="">— Selecciona un área —</option>' +
        areasCache.map(a => `<option value="${a.id}">${App.esc(a.nombre)}</option>`).join('');
}

async function cargarUsuarios() {
    const res = await App.api('usuarios.php');
    if (!res.ok) throw new Error(res.error);
    usuariosCache = res.data;
    render(usuariosCache);
}

function cargarStats() {
    const total = usuariosCache.length;
    const activos = usuariosCache.filter(u => u.estado).length;
    statsBox.innerHTML = `
        <div class="stat">
            <span class="stat__icon">🔐</span>
            <div>
                <div class="stat__num">${total}</div>
                <div class="stat__lbl">Usuarios totales</div>
            </div>
        </div>
        <div class="stat">
            <span class="stat__icon">✅</span>
            <div>
                <div class="stat__num">${activos}</div>
                <div class="stat__lbl">Usuarios activos</div>
            </div>
        </div>
    `;
}

function render(lista) {
    if (!lista.length) {
        tablaBody.innerHTML = '<tr><td colspan="7"><div class="empty-state"><div class="empty-state__icon">🔐</div>No hay usuarios. Pulsa "Nuevo usuario" para empezar.</div></td></tr>';
        return;
    }
    tablaBody.innerHTML = lista.map(u => {
        // Rol badge
        const rolBadge = u.rol_clave === 'superusuario'
            ? '<span class="badge badge--super">🛡️ ' + App.esc(u.rol_nombre) + '</span>'
            : '<span class="badge badge--usuario">' + App.esc(u.rol_nombre) + '</span>';

        // Área con icono
        const areaTxt = u.area_nombre
            ? '<span class="u-area">🏢 ' + App.esc(u.area_nombre) + '</span>'
            : '<span class="u-area muted">— sin área —</span>';

        // Contacto: usuario + correo
        const correo = u.correo
            ? `<span class="u-correo" title="${App.esc(u.correo)}">${App.esc(u.correo)}</span>`
            : '<span class="u-correo muted">— sin correo —</span>';

        // Estado
        const estadoBadge = u.estado
            ? '<span class="badge badge--active">Activo</span>'
            : '<span class="badge badge--inactive">Inactivo</span>';

        // Último acceso compacto
        const ultimo = u.ultimo_acceso
            ? formatFechaCorta(u.ultimo_acceso)
            : '<span class="muted">— nunca —</span>';

        return `
            <tr>
                <td class="u-id">${u.id}</td>
                <td class="u-nombre">
                    <strong title="${App.esc(u.nombre_completo)}">${App.esc(u.nombre_completo)}</strong>
                </td>
                <td class="u-contacto">
                    <div class="u-usuario">@${App.esc(u.nombre_usuario)}</div>
                    ${correo}
                </td>
                <td class="u-rolarea">
                    <div>${rolBadge}</div>
                    <div>${areaTxt}</div>
                </td>
                <td>${estadoBadge}</td>
                <td class="u-ultimo">${ultimo}</td>
                <td class="table__col-actions">
                    <div class="table__actions">
                        <button class="btn btn--ghost btn--sm" onclick="editar(${u.id})" title="Editar">✏️</button>
                        <button class="btn btn--danger-outline btn--sm" onclick="abrirReset(${u.id}, '${App.esc(u.nombre_completo).replace(/'/g, "\\'")}')" title="Restablecer contraseña">🔑</button>
                        <button class="btn btn--danger btn--sm" onclick="eliminar(${u.id}, '${App.esc(u.nombre_completo).replace(/'/g, "\\'")}')" title="Eliminar">🗑️</button>
                    </div>
                </td>
            </tr>
        `;
    }).join('');
}

/**
 * Formatea una fecha en formato compacto.
 * Ej: "13 Oct, 15:22"
 */
function formatFechaCorta(value) {
    if (!value) return '<span class="muted">—</span>';
    const d = new Date(value);
    if (isNaN(d)) return '<span class="muted">—</span>';
    const meses = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
    const dia = d.getDate();
    const mes = meses[d.getMonth()];
    const hh = String(d.getHours()).padStart(2, '0');
    const mm = String(d.getMinutes()).padStart(2, '0');
    return `<span title="${d.toLocaleString('es-MX')}">${dia} ${mes}, ${hh}:${mm}</span>`;
}

busqueda.addEventListener('input', () => {
    const q = busqueda.value.toLowerCase().trim();
    if (!q) { render(usuariosCache); return; }
    const filtrados = usuariosCache.filter(u =>
        u.nombre_completo.toLowerCase().includes(q) ||
        u.nombre_usuario.toLowerCase().includes(q) ||
        (u.correo || '').toLowerCase().includes(q)
    );
    render(filtrados);
});

btnNuevo.addEventListener('click', () => {
    form.reset();
    form.id.value = '';
    passInput.required = true;
    passReq.style.display = '';
    passInput.value = '';
    form.estado.checked = true;
    modalTitle.textContent = '➕ Nuevo usuario';
    formError.style.display = 'none';
    // Resetear selects
    selectRol.value = rolesCache[0]?.id || '';
    selectArea.value = '';
    toggleAreaRequired();
    App.openModal('usuarioModal');
});

window.editar = async (id) => {
    try {
        const res = await App.api(`usuarios.php?id=${id}`);
        if (!res.ok) throw new Error(res.error);
        const u = res.data;
        form.reset();
        App.fillForm(form, u);
        form.id.value = u.id;
        // La contraseña no se devuelve
        passInput.value = '';
        passInput.required = false;
        passReq.style.display = 'none';
        form.estado.checked = !!u.estado;
        modalTitle.textContent = '✏️ Editar usuario';
        toggleAreaRequired();
        formError.style.display = 'none';
        App.openModal('usuarioModal');
    } catch (err) {
        App.toast(err.message, 'error');
    }
};

window.eliminar = async (id, nombre) => {
    if (!await App.confirm(`¿Eliminar al usuario "${nombre}"?\nEsta acción no se puede deshacer.`)) return;
    try {
        const res = await App.api(`usuarios.php?id=${id}`, { method: 'DELETE' });
        if (!res.ok) throw new Error(res.error);
        App.toast(res.message, 'success');
        cargarUsuarios();
    } catch (err) {
        App.toast(err.message, 'error');
    }
};

window.abrirReset = (id, nombre) => {
    resetForm.reset();
    resetForm.id.value = id;
    resetForm.password.value = '';
    resetName.textContent = nombre;
    resetError.style.display = 'none';
    App.openModal('resetModal');
};

resetForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const id = resetForm.id.value;
    const pwd = resetForm.password.value;
    if (pwd.length < 8) return mostrarResetError('La contraseña debe tener al menos 8 caracteres');
    try {
        const res = await App.api(`usuarios.php?id=${id}`, {
            method: 'POST',
            body: { accion: 'reset_password', password: pwd }
        });
        if (!res.ok) throw new Error(res.error);
        App.toast(res.message, 'success');
        App.closeModal('resetModal');
    } catch (err) {
        mostrarResetError(err.message);
    }
});

form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const id = form.id.value;

    const data = {
        nombre_completo: form.nombre_completo.value.trim(),
        nombre_usuario:  form.nombre_usuario.value.trim(),
        numero_nomina:   form.numero_nomina.value.trim(),
        correo:          form.correo.value.trim(),
        password:        form.password.value,
        area_id:         form.area_id.value || '',
        rol_id:          parseInt(form.rol_id.value, 10),
        estado:          form.estado.checked,
    };

    // Validaciones cliente
    if (data.nombre_completo.length < 3) return mostrarError('El nombre completo es obligatorio');
    if (data.nombre_usuario.length < 3)  return mostrarError('El nombre de usuario debe tener al menos 3 caracteres');
    if (!id && (!data.password || data.password.length < 8)) return mostrarError('La contraseña es obligatoria (mín. 8 caracteres)');
    if (id && data.password && data.password.length < 8) return mostrarError('La contraseña debe tener al menos 8 caracteres');

    const method = id ? 'PUT' : 'POST';
    const url    = id ? `usuarios.php?id=${id}` : 'usuarios.php';

    try {
        const res = await App.api(url, { method, body: data });
        if (!res.ok) throw new Error(res.error);
        App.toast(res.message, 'success');
        App.closeModal('usuarioModal');
        cargarUsuarios();
    } catch (err) {
        mostrarError(err.message);
    }
});

function mostrarError(msg) {
    formError.textContent = msg;
    formError.style.display = 'block';
}
function mostrarResetError(msg) {
    resetError.textContent = msg;
    resetError.style.display = 'block';
}