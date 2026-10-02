/* =====================================================
   activos.js — CRUD de activos fijos
   ===================================================== */

const form       = document.getElementById('activoForm');
const tablaBody  = document.querySelector('#tablaActivos tbody');
const modal      = document.getElementById('activoModal');
const btnNuevo   = document.getElementById('btnNuevo');
const modalTitle = document.getElementById('modalTitle');
const formError  = document.getElementById('formError');
const busqueda   = document.getElementById('busqueda');
const selectEmp  = document.getElementById('empleado_id');
const statsBox   = document.getElementById('stats');
const imgInput   = document.getElementById('imagen');
const imgPreview = document.getElementById('imagenPreview');

let empleadosCache = [];
let activosCache   = [];

document.addEventListener('DOMContentLoaded', init);

async function init() {
    tablaBody.innerHTML = '<tr><td colspan="8" class="loading"><span class="spinner"></span> Cargando...</td></tr>';
    try {
        await Promise.all([cargarEmpleados(), cargarActivos()]);
        cargarStats();
    } catch (err) {
        App.toast(err.message, 'error');
    }
}

async function cargarEmpleados() {
    const res = await App.api('empleados.php');
    if (!res.ok) throw new Error(res.error);
    empleadosCache = res.data;
    // Llenar select
    const options = ['<option value="">— Sin asignar —</option>'].concat(
        empleadosCache.map(e => `<option value="${e.id}">[${e.numero_nomina}] ${App.esc(e.nombre)} — ${App.esc(e.cargo)}</option>`)
    );
    selectEmp.innerHTML = options.join('');
}

async function cargarActivos() {
    const res = await App.api('activos.php');
    if (!res.ok) throw new Error(res.error);
    activosCache = res.data;
    render(activosCache);
}

function cargarStats() {
    const total = activosCache.length;
    const costo = activosCache.reduce((s, a) => s + (parseFloat(a.costo) || 0), 0);
    const asignados = activosCache.filter(a => a.empleado_id).length;
    statsBox.innerHTML = `
        <div class="stat">
            <span class="stat__icon">📦</span>
            <div>
                <div class="stat__num">${total}</div>
                <div class="stat__lbl">Activos registrados</div>
            </div>
        </div>
        <div class="stat">
            <span class="stat__icon">👤</span>
            <div>
                <div class="stat__num">${asignados}</div>
                <div class="stat__lbl">Asignados a resguardantes</div>
            </div>
        </div>
        <div class="stat">
            <span class="stat__icon">💰</span>
            <div>
                <div class="stat__num">${App.money(costo).replace('$', '$')}</div>
                <div class="stat__lbl">Valor total del inventario</div>
            </div>
        </div>
    `;
}

function render(lista) {
    if (!lista.length) {
        tablaBody.innerHTML = '<tr><td colspan="8"><div class="empty-state"><div class="empty-state__icon">📦</div>No hay activos. Pulsa "Nuevo activo" para empezar.</div></td></tr>';
        return;
    }
    tablaBody.innerHTML = lista.map(a => {
        const empTxt = a.empleado_id
            ? `<strong>${App.esc(a.empleado_nombre || '')}</strong><br><small>${App.esc(a.cargo || '')}</small>`
            : '<span class="badge badge--warning">Sin asignar</span>';

        let imgPath = a.ruta_imagen || '';
        if (imgPath.startsWith('public/')) {
            imgPath = imgPath.replace('public/', '');
        }

        return `
            <tr>
                <td><strong>${App.esc(a.descripcion)}</strong></td>
                <td>${App.esc(a.num_inventario)}</td>
                <td>${App.esc(a.marca || '—')}</td>
                <td>${App.esc(a.modelo || '—')}</td>
                <td>${App.money(a.costo)}</td>
                <td>${empTxt}</td>
                ${imgPath
                    ? `<td class="col-foto">
                        <img class="table__thumb" src="${App.esc(imgPath)}" alt="Foto" loading="lazy" onclick="viewImage('${App.esc(imgPath)}')" style="cursor: pointer;" title="Haz clic para ampliar">
                       </td>`
                    : `<td class="col-foto">
                        <div class="table__noimg">S/F</div>
                       </td>`}
                <td>
                    <div class="table__actions">
                        <button class="btn btn--ghost btn--sm" onclick="editar(${a.id})">✏️</button>
                        <button class="btn btn--danger btn--sm" onclick="eliminar(${a.id}, '${App.esc(a.descripcion).replace(/'/g, "\\'")}')">🗑️</button>
                    </div>
                </td>
            </tr>
        `;
    }).join('');
}

busqueda.addEventListener('input', () => {
    const q = busqueda.value.toLowerCase().trim();
    if (!q) { render(activosCache); return; }
    const filtrados = activosCache.filter(a =>
        (a.descripcion    || '').toLowerCase().includes(q) ||
        (a.num_inventario || '').toLowerCase().includes(q) ||
        (a.marca          || '').toLowerCase().includes(q) ||
        (a.modelo         || '').toLowerCase().includes(q) ||
        (a.serie          || '').toLowerCase().includes(q)
    );
    render(filtrados);
});

btnNuevo.addEventListener('click', () => {
    form.reset();
    form.elements['id'].value = '';
    form.elements['ruta_imagen'].value = '';
    form.elements['observaciones'].value = '';
    modalTitle.textContent = '➕ Nuevo activo';
    imgPreview.innerHTML = '<div class="image-preview__placeholder">Sin imagen</div>';
    formError.style.display = 'none';
    App.openModal('activoModal');
});

window.editar = async (id) => {
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
};

window.eliminar = async (id, desc) => {
    if (!await App.confirm(`¿Eliminar el activo "${desc}"?`)) return;
    try {
        const res = await App.api(`activos.php?id=${id}`, { method: 'DELETE' });
        if (!res.ok) throw new Error(res.error);
        App.toast(res.message, 'success');
        cargarActivos();
    } catch (err) {
        App.toast(err.message, 'error');
    }
};

window.viewImage = (src) => {
    const img = document.getElementById('viewerImage');
    if (img) {
        img.src = src;
        App.openModal('imageViewerModal');
    }
};

imgInput.addEventListener('change', () => {
    const f = imgInput.files[0];
    if (!f) return;
    const reader = new FileReader();
    reader.onload = e => {
        imgPreview.innerHTML = `<img src="${e.target.result}" alt="Vista previa">`;
    };
    reader.readAsDataURL(f);
});

form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const id = form.elements['id'].value;

    // Validaciones cliente
    const desc = form.descripcion.value.trim();
    const ni   = form.num_inventario.value.trim();
    const costo = form.costo.value;
    if (desc.length < 2)         return mostrarError('La descripción es obligatoria');
    if (!ni)                     return mostrarError('El número de inventario es obligatorio');
    if (!costo || costo < 0)     return mostrarError('El costo es obligatorio y no puede ser negativo');

    let rutaImagen = form.ruta_imagen.value || null;

    // Subir imagen si hay nueva
    if (imgInput.files && imgInput.files[0]) {
        try {
            const fd = new FormData();
            fd.append('imagen', imgInput.files[0]);
            const r = await fetch(window.API_BASE + 'upload.php', { method: 'POST', body: fd });
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
        cargarActivos();
    } catch (err) {
        mostrarError(err.message);
    }
});

function mostrarError(msg) {
    formError.textContent = msg;
    formError.style.display = 'block';
}
