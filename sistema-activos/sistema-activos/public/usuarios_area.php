<?php
$page_title = 'Usuarios por Área';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
$user = require_auth();
require_once __DIR__ . '/../includes/header.php';
?>

<section class="page-header">
    <div style="display: flex; align-items: center; gap: 20px;">
        <a href="areas.php" class="btn btn--ghost" style="text-decoration: none; font-size: 0.9rem;">← Volver a Áreas</a>
        <div>
            <h1 id="areaTitle">Cargando área...</h1>
            <p>Listado de usuarios y personal asignado a este departamento.</p>
        </div>
    </div>
</section>

<div class="toolbar no-print">
    <input type="text" id="busquedaLocal" placeholder="🔎 Filtrar usuarios en esta área..." style="width: 300px;">
</div>

<div class="table-wrap">
    <table class="table" id="tablaUsuariosArea">
        <thead>
            <tr>
                <th>Nombre Completo</th>
                <th>Nómina</th>
                <th>Usuario</th>
                <th>Rol</th>
                <th>Estado</th>
                <th style="text-align: right;">Acciones</th>
            </tr>
        </thead>
        <tbody>
            <!-- Cargado via JS -->
        </tbody>
    </table>
</div>

<script>
document.addEventListener('DOMContentLoaded', async () => {
    const urlParams = new URLSearchParams(window.location.search);
    const areaId = urlParams.get('id');
    const tablaBody = document.querySelector('#tablaUsuariosArea tbody');
    const areaTitle = document.getElementById('areaTitle');
    const busquedaLocal = document.getElementById('busquedaLocal');
    let usuariosArea = [];

    if (!areaId) {
        areaTitle.textContent = 'Error';
        tablaBody.innerHTML = '<tr><td colspan="6" class="empty-state">No se proporcionó un ID de área válido.</td></tr>';
        return;
    }

    try {
        const res = await App.api(`usuarios_area.php?area_id=${areaId}`);
        if (!res.ok) throw new Error(res.error);

        areaTitle.textContent = '🏢 Área: ' + res.area_nombre;
        usuariosArea = res.usuarios;
        render(usuariosArea);
    } catch (err) {
        areaTitle.textContent = 'Error';
        tablaBody.innerHTML = `<tr><td colspan="6" class="empty-state">${err.message}</td></tr>`;
    }

    function render(lista) {
        if (!lista.length) {
            tablaBody.innerHTML = '<tr><td colspan="6" class="empty-state">No hay usuarios asignados a esta área.</td></tr>';
            return;
        }
        tablaBody.innerHTML = lista.map(u => `
            <tr>
                <td><strong>${App.esc(u.nombre_completo)}</strong></td>
                <td><code>${App.esc(u.numero_nomina || '—')}</code></td>
                <td>@${App.esc(u.nombre_usuario)}</td>
                <td><span class="badge">${App.esc(u.rol_nombre)}</span></td>
                <td>${u.estado ? '<span class="badge badge--active">Activo</span>' : '<span class="badge badge--inactive">Inactivo</span>'}</td>
                <td style="text-align: right;">
                    <button class="btn btn--ghost btn--sm" onclick="window.location.href='usuarios.php?id=${u.id}'">✏️</button>
                </td>
            </tr>
        `).join('');
    }

    busquedaLocal.addEventListener('input', () => {
        const q = busquedaLocal.value.toLowerCase().trim();
        const filtrados = usuariosArea.filter(u =>
            u.nombre_completo.toLowerCase().includes(q) ||
            u.nombre_usuario.toLowerCase().includes(q) ||
            (u.numero_nomina || '').toLowerCase().includes(q)
        );
        render(filtrados);
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
