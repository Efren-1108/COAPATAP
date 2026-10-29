/* =====================================================
   consulta.js — Dashboard con gráficas + Búsqueda por nómina y nombre
   ===================================================== */

// ============ REFERENCIAS ============
const searchForm      = document.getElementById('searchForm');
const busquedaGeneralInput = document.getElementById('busquedaEmpleadoGeneral');
const listaCoincidencias = document.getElementById('listaCoincidencias');
let empleadoSeleccionadoId = null;
const resultContainer = document.getElementById('resultContainer');
const statsGlobales   = document.getElementById('statsGlobales');

// Referencias a las gráficas (solo si existen)
const filtroAdscDash  = document.getElementById('filtroAdscripcionDash');
const btnRefrescar    = document.getElementById('btnRefrescarDash');

// ============ ESTADO ============
let charts = {
    porAdscripcion: null,
    valorAdscripcion: null,
    topAreas: null,
    topEmpleados: null,
    porAnio: null,
};

// Paleta de colores para las gráficas
const COLORES = [
    '#2563eb', '#16a34a', '#dc2626', '#f59e0b', '#8b5cf6',
    '#0f766e', '#db2777', '#0891b2', '#84cc16', '#f97316',
    '#6366f1', '#14b8a6', '#eab308', '#ef4444', '#22c55e',
];

// ============ INIT ============
document.addEventListener('DOMContentLoaded', init);

async function init() {
    // Verificar que Chart.js esté cargado
    if (typeof Chart === 'undefined') {
        console.error('Chart.js no está cargado');
        App.toast('No se pudo cargar Chart.js. Revisa tu conexión a internet.', 'error');
    }

    // Configurar Chart.js globalmente
    if (typeof Chart !== 'undefined') {
        Chart.defaults.font.family = "'Segoe UI', system-ui, -apple-system, sans-serif";
        Chart.defaults.color = '#64748b';
        Chart.defaults.borderColor = '#e2e8f0';
    }

    // Cargar filtro de adscripciones (solo super)
    if (filtroAdscDash) {
        await cargarFiltroAdscripciones();
        filtroAdscDash.addEventListener('change', () => cargarDashboard());
    }
    if (btnRefrescar) {
        btnRefrescar.addEventListener('click', () => cargarDashboard());
    }

    // Cargar dashboard
    await cargarDashboard();

    // Un solo formulario: número de nómina exacto o nombre parcial.
    if (busquedaGeneralInput) busquedaGeneralInput.focus();
    if (searchForm) searchForm.addEventListener('submit', onBuscarGeneral);
    if (listaCoincidencias) {
        listaCoincidencias.addEventListener('click', onClickCoincidencia);
    }
}

// ============ FILTRO DE ADSCRIPCIONES ============
async function cargarFiltroAdscripciones() {
    try {
        const res = await App.api('adscripciones.php');
        if (!res.ok) throw new Error(res.error);
        const opciones = ['<option value="">Todas las adscripciones</option>']
            .concat(res.data
                .filter(a => a.activo)
                .sort((a, b) => a.nombre.localeCompare(b.nombre))
                .map(a => `<option value="${a.id}">${App.esc(a.nombre)}</option>`));
        filtroAdscDash.innerHTML = opciones.join('');
    } catch (err) {
        console.error('Error cargando filtro de adscripciones:', err);
    }
}

// ============ DASHBOARD ============
async function cargarDashboard() {
    // Mostrar loading
    statsGlobales.innerHTML = '<div class="stat" style="grid-column: 1/-1;"><span class="spinner"></span> Cargando datos...</div>';

    try {
        const filtro = filtroAdscDash && filtroAdscDash.value ? `?adscripcion_id=${filtroAdscDash.value}` : '';
        const res = await App.api(`dashboard.php${filtro}`);
        if (!res.ok) throw new Error(res.error);

        renderStats(res.data.totales);
        renderChartPorAdscripcion(res.data.por_adscripcion);
        renderChartValorAdscripcion(res.data.por_adscripcion);
        renderChartTopAreas(res.data.top_areas);
        renderChartTopEmpleados(res.data.top_empleados);
        renderChartPorAnio(res.data.por_anio);
    } catch (err) {
        statsGlobales.innerHTML = `<div class="stat" style="grid-column: 1/-1; color: var(--c-danger);">⚠️ ${App.esc(err.message)}</div>`;
        App.toast(err.message, 'error');
    }
}

// ============ STATS ============
function renderStats(t) {
    statsGlobales.innerHTML = `
        <div class="stat">
            <span class="stat__icon">📦</span>
            <div>
                <div class="stat__num">${t.total_activos}</div>
                <div class="stat__lbl">Activos totales</div>
            </div>
        </div>
        <div class="stat">
            <span class="stat__icon">💰</span>
            <div>
                <div class="stat__num">${App.money(t.costo_total)}</div>
                <div class="stat__lbl">Valor total</div>
            </div>
        </div>
        <div class="stat">
            <span class="stat__icon">👤</span>
            <div>
                <div class="stat__num">${t.asignados}</div>
                <div class="stat__lbl">Asignados</div>
            </div>
        </div>
        <div class="stat">
            <span class="stat__icon">⚠️</span>
            <div>
                <div class="stat__num">${t.sin_asignar}</div>
                <div class="stat__lbl">Sin asignar</div>
            </div>
        </div>
    `;
}

// ============ GRÁFICA 1: DONA - Activos por adscripción ============
function renderChartPorAdscripcion(datos) {
    const canvas = document.getElementById('chartPorAdscripcion');
    if (!canvas) return;

    if (charts.porAdscripcion) charts.porAdscripcion.destroy();

    if (!datos.length) {
        canvas.parentElement.innerHTML = '<div class="empty-state"><div class="empty-state__icon">📊</div><p>Sin datos</p></div>';
        return;
    }

    const labels = datos.map(d => d.adscripcion_nombre || 'Sin adscripción');
    const valores = datos.map(d => d.activos);
    const colores = labels.map((_, i) => COLORES[i % COLORES.length]);

    charts.porAdscripcion = new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: valores,
                backgroundColor: colores,
                borderWidth: 2,
                borderColor: '#fff',
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { padding: 12, boxWidth: 12 },
                },
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                            const pct = ((ctx.parsed / total) * 100).toFixed(1);
                            return ` ${ctx.label}: ${ctx.parsed} activos (${pct}%)`;
                        },
                    },
                },
            },
        },
    });
}

// ============ GRÁFICA 2: BARRAS HORIZ - Valor por adscripción ============
function renderChartValorAdscripcion(datos) {
    const canvas = document.getElementById('chartValorAdscripcion');
    if (!canvas) return;

    if (charts.valorAdscripcion) charts.valorAdscripcion.destroy();

    if (!datos.length) {
        canvas.parentElement.innerHTML = '<div class="empty-state"><div class="empty-state__icon">💰</div><p>Sin datos</p></div>';
        return;
    }

    // Ordenar por costo descendente y tomar top 10
    const ordenados = [...datos].sort((a, b) => b.costo - a.costo).slice(0, 10);

    const labels = ordenados.map(d => d.adscripcion_nombre || 'Sin adscripción');
    const valores = ordenados.map(d => d.costo);
    const colores = labels.map((_, i) => COLORES[i % COLORES.length]);

    charts.valorAdscripcion = new Chart(canvas, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Valor total',
                data: valores,
                backgroundColor: colores,
                borderRadius: 6,
            }],
        },
        options: {
            indexAxis: 'y', // Barras horizontales
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx) => ` ${App.money(ctx.parsed.x)}`,
                    },
                },
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: {
                        callback: (v) => {
                            if (v >= 1000000) return '$' + (v / 1000000).toFixed(1) + 'M';
                            if (v >= 1000) return '$' + (v / 1000).toFixed(0) + 'k';
                            return '$' + v;
                        },
                    },
                },
            },
        },
    });
}

// ============ GRÁFICA 3: BARRAS VERT - Top áreas ============
function renderChartTopAreas(datos) {
    const canvas = document.getElementById('chartTopAreas');
    if (!canvas) return;

    if (charts.topAreas) charts.topAreas.destroy();

    if (!datos.length) {
        canvas.parentElement.innerHTML = '<div class="empty-state"><div class="empty-state__icon">🏢</div><p>Sin datos</p></div>';
        return;
    }

    const labels = datos.map(d => d.area_nombre || 'Sin área');
    const valores = datos.map(d => d.activos);
    const colores = labels.map((_, i) => COLORES[(i + 3) % COLORES.length]);

    charts.topAreas = new Chart(canvas, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Activos',
                data: valores,
                backgroundColor: colores,
                borderRadius: 6,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx) => ` ${ctx.parsed.y} activos`,
                    },
                },
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { stepSize: 1, precision: 0 },
                },
                x: {
                    ticks: {
                        maxRotation: 45,
                        minRotation: 0,
                        autoSkip: false,
                        callback: function(val) {
                            const label = this.getLabelForValue(val);
                            return label.length > 15 ? label.substring(0, 15) + '…' : label;
                        },
                    },
                },
            },
        },
    });
}

// ============ GRÁFICA 4: BARRAS HORIZ - Top empleados ============
function renderChartTopEmpleados(datos) {
    const canvas = document.getElementById('chartTopEmpleados');
    if (!canvas) return;

    if (charts.topEmpleados) charts.topEmpleados.destroy();

    if (!datos.length) {
        canvas.parentElement.innerHTML = '<div class="empty-state"><div class="empty-state__icon">🏆</div><p>Sin datos</p></div>';
        return;
    }

    const labels = datos.map(d => d.empleado_nombre || 'Sin nombre');
    const valores = datos.map(d => d.costo);
    const colores = labels.map((_, i) => COLORES[(i + 5) % COLORES.length]);

    charts.topEmpleados = new Chart(canvas, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Valor asignado',
                data: valores,
                backgroundColor: colores,
                borderRadius: 6,
            }],
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            const item = datos[ctx.dataIndex];
                            return [
                                ` ${App.money(ctx.parsed.x)}`,
                                ` ${item.activos} activo${item.activos === 1 ? '' : 's'}`,
                            ];
                        },
                    },
                },
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: {
                        callback: (v) => {
                            if (v >= 1000000) return '$' + (v / 1000000).toFixed(1) + 'M';
                            if (v >= 1000) return '$' + (v / 1000).toFixed(0) + 'k';
                            return '$' + v;
                        },
                    },
                },
                y: {
                    ticks: {
                        callback: function(val) {
                            const label = this.getLabelForValue(val);
                            return label.length > 22 ? label.substring(0, 22) + '…' : label;
                        },
                    },
                },
            },
        },
    });
}

// ============ GRÁFICA 5: LÍNEA - Valor por año ============
function renderChartPorAnio(datos) {
    const canvas = document.getElementById('chartPorAnio');
    if (!canvas) return;

    if (charts.porAnio) charts.porAnio.destroy();

    if (!datos.length) {
        canvas.parentElement.innerHTML = '<div class="empty-state"><div class="empty-state__icon">📈</div><p>Sin datos de años disponibles</p></div>';
        return;
    }

    const labels = datos.map(d => String(d.anio));
    const valoresCosto = datos.map(d => d.costo);
    const valoresActivos = datos.map(d => d.activos);

    charts.porAnio = new Chart(canvas, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Valor total (MXN)',
                    data: valoresCosto,
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37, 99, 235, 0.1)',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 5,
                    pointBackgroundColor: '#2563eb',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    yAxisID: 'y',
                },
                {
                    label: 'Cantidad de activos',
                    data: valoresActivos,
                    borderColor: '#16a34a',
                    backgroundColor: 'rgba(22, 163, 74, 0.1)',
                    fill: false,
                    tension: 0.35,
                    pointRadius: 5,
                    pointBackgroundColor: '#16a34a',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    yAxisID: 'y1',
                    borderDash: [5, 5],
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { padding: 12, boxWidth: 12 },
                },
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            if (ctx.datasetIndex === 0) return ` 💰 ${App.money(ctx.parsed.y)}`;
                            return ` 📦 ${ctx.parsed.y} activos`;
                        },
                    },
                },
            },
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    beginAtZero: true,
                    ticks: {
                        callback: (v) => {
                            if (v >= 1000000) return '$' + (v / 1000000).toFixed(1) + 'M';
                            if (v >= 1000) return '$' + (v / 1000).toFixed(0) + 'k';
                            return '$' + v;
                        },
                    },
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    beginAtZero: true,
                    grid: { drawOnChartArea: false },
                    ticks: { precision: 0 },
                },
            },
        },
    });
}

// ============ BÚSQUEDA POR NÓMINA O NOMBRE ============
async function onBuscarGeneral(evento) {
    evento.preventDefault();
    const texto = (busquedaGeneralInput?.value || '').trim().replace(/\s+/g, ' ');
    if (!texto) {
        App.toast('Escribe un nombre o un número de nómina', 'error');
        return;
    }

    empleadoSeleccionadoId = null;
    listaCoincidencias.innerHTML = '';
    resultContainer.innerHTML = '';

    // Solo dígitos: número de nómina exacto.
    if (/^[0-9]+$/.test(texto)) {
        if (!/^[1-9][0-9]*$/.test(texto) || Number(texto) > 2147483647) {
            App.toast('Introduce un número de nómina válido', 'error');
            return;
        }
        await consultarResguardo(`consulta.php?nomina=${encodeURIComponent(texto)}`);
        return;
    }

    // Cualquier otra búsqueda se interpreta como nombre parcial.
    if (texto.length < 2 || texto.length > 100) {
        App.toast('Escribe al menos dos caracteres del nombre', 'error');
        return;
    }
    await buscarEmpleadosPorNombre(texto);
}

async function buscarEmpleadosPorNombre(texto) {
    empleadoSeleccionadoId = null;
    resultContainer.innerHTML = '';
    listaCoincidencias.innerHTML = '<p><span class="spinner"></span> Buscando empleados...</p>';

    try {
        const res = await App.api(`buscar_empleados_nombre.php?q=${encodeURIComponent(texto)}`);
        if (!res.ok) throw new Error(res.error || 'No fue posible buscar empleados');
        const encontrados = Array.isArray(res.data) ? res.data : [];
        if (!encontrados.length) {
            listaCoincidencias.innerHTML = '<div class="empty-state"><p>No se encontraron empleados con ese nombre.</p></div>';
            return;
        }
        listaCoincidencias.innerHTML = `
            <h3 style="margin:.6rem 0">Selecciona el empleado correcto (${encontrados.length} coincidencia(s))</h3>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Nombre</th><th>Nómina</th><th>Cargo</th><th>Área</th><th>Adscripción</th><th>Acción</th></tr></thead>
                    <tbody>${encontrados.map(emp => `
                        <tr>
                            <td><strong>${App.esc(emp.nombre)}</strong><br><small>ID interno: ${Number(emp.id)}</small></td>
                            <td>${emp.numero_nomina == null ? 'Sin nómina' : App.esc(emp.numero_nomina)}</td>
                            <td>${App.esc(emp.cargo || '—')}</td>
                            <td>${App.esc(emp.area_nombre || '—')}</td>
                            <td>${App.esc(emp.adscripcion_nombre || '—')}</td>
                            <td><button type="button" class="btn btn--primary btn--sm" data-empleado-id="${Number(emp.id)}">Ver activos</button></td>
                        </tr>`).join('')}
                    </tbody>
                </table>
            </div>
            ${res.hay_mas ? '<p>Hay más resultados. Escribe un nombre más completo para reducir la lista.</p>' : ''}
        `;
    } catch (err) {
        listaCoincidencias.innerHTML = `<p style="color:var(--c-danger)">⚠️ ${App.esc(err.message)}</p>`;
        App.toast(err.message, 'error');
    }
}

function onClickCoincidencia(evento) {
    const boton = evento.target.closest('button[data-empleado-id]');
    if (!boton || !listaCoincidencias.contains(boton)) return;
    const id = Number(boton.dataset.empleadoId);
    if (!Number.isSafeInteger(id) || id < 1) return;
    consultarResguardo(`consulta.php?id=${id}`);
}

async function consultarResguardo(ruta) {
    empleadoSeleccionadoId = null;
    resultContainer.innerHTML = '<div class="card loading"><span class="spinner"></span> Consultando activos...</div>';
    try {
        const res = await App.api(ruta);
        if (!res.ok) throw new Error(res.error || 'No fue posible consultar los activos');
        renderResultado(res);
        resultContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (err) {
        resultContainer.innerHTML = `
            <div class="card empty-state">
                <div class="empty-state__icon">⚠️</div>
                <p>${App.esc(err.message)}</p>
            </div>`;
        App.toast(err.message, 'error');
    }
}

function renderResultado(res) {
    const e = res.empleado;
    empleadoSeleccionadoId = Number(e.id);
    const activos = res.activos;
    const total = res.total;
    const costoTotal = res.costo_total;

    let tablaActivos = '';
    if (total > 0) {
        tablaActivos = `
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Descripción</th>
                            <th>Inventario</th>
                            <th>Marca</th>
                            <th>Modelo</th>
                            <th>Serie</th>
                            <th>Material</th>
                            <th>Fecha adq.</th>
                            <th>Costo</th>
                            <th>Foto</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${activos.map((a, i) => `
                            <tr>
                                <td>${i + 1}</td>
                                <td><strong>${App.esc(a.descripcion)}</strong></td>
                                <td>${App.esc(a.num_inventario)}</td>
                                <td>${App.esc(a.marca || '—')}</td>
                                <td>${App.esc(a.modelo || '—')}</td>
                                <td>${App.esc(a.serie || '—')}</td>
                                <td>${App.esc(a.material || '—')}</td>
                                <td>${App.date(a.fecha_adq)}</td>
                                <td>${App.money(a.costo)}</td>
                                <td>
                                    ${a.ruta_imagen
                                        ? `<img class="table__thumb" src="${App.esc(a.ruta_imagen)}" alt="Foto" loading="lazy" onclick="viewImage('${App.esc(a.ruta_imagen)}')" style="cursor: pointer;" title="Haz clic para ampliar">`
                                        : `<div class="table__noimg">S/F</div>`}
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
    } else {
        tablaActivos = `
            <div class="empty-state">
                <div class="empty-state__icon">📭</div>
                <p>Este resguardante aún no tiene bienes asignados.</p>
            </div>
        `;
    }

    resultContainer.innerHTML = `
        <div class="card">
            <div class="page-header" style="margin: 0 0 1rem;">
                <div>
                    <h2>👤 Datos del Resguardante</h2>
                </div>
                <button class="btn btn--success" onclick="imprimirResguardo()">
                    🖨️ Imprimir / Guardar PDF
                </button>
            </div>
            <div class="empleado-info">
                <div class="info-item">
                    <span class="info-item__label">Nómina</span>
                    <span class="info-item__value">${e.numero_nomina == null ? 'Sin nómina asignada' : App.esc(e.numero_nomina)}</span>
                </div>
                <div class="info-item" style="border-left-color: var(--c-secondary);">
                    <span class="info-item__label">Nombre completo</span>
                    <span class="info-item__value">${App.esc(e.nombre)}</span>
                </div>
                <div class="info-item" style="border-left-color: var(--c-accent);">
                    <span class="info-item__label">Cargo</span>
                    <span class="info-item__value">${App.esc(e.cargo)}</span>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="page-header" style="margin: 0 0 1rem;">
                <div>
                    <h2>📦 Bienes Asignados</h2>
                    <p>${total} bien(es) · Costo total: <strong>${App.money(costoTotal)}</strong></p>
                </div>
            </div>
            ${tablaActivos}
        </div>
    `;
}

// ============ IMPRIMIR RESGUARDO ============
async function imprimirResguardo() {
    if (!Number.isSafeInteger(empleadoSeleccionadoId) || empleadoSeleccionadoId < 1) {
        App.toast('Primero consulta a un empleado', 'error');
        return;
    }
    try {
        const res = await App.api(`consulta.php?id=${empleadoSeleccionadoId}`);
        if (!res.ok) throw new Error(res.error);
        renderResguardoImprimible(res);
        setTimeout(() => window.print(), 400);
    } catch (err) {
        App.toast(err.message, 'error');
    }
}

function renderResguardoImprimible(res) {
    const e = res.empleado;
    const activos = res.activos;
    const costoTotal = res.costo_total;
    const fecha = new Date().toLocaleDateString('es-MX', { year: 'numeric', month: 'long', day: 'numeric' });

    const html = `
        <div style="padding: 1.5rem 2rem;">
            <div style="text-align: center; margin-bottom: 1.5rem; border-bottom: 2px solid #000; padding-bottom: 1rem;">
                <h1 style="margin: 0;">RESGUARDO DE BIENES</h1>
                <p style="margin: 0.25rem 0 0; font-size: 0.9rem;">Sistema de Control de Activos Fijos</p>
                <p style="margin: 0; font-size: 0.85rem; color: #666;">Fecha de emisión: ${fecha}</p>
            </div>

            <h3 style="margin: 0 0 0.5rem; border-bottom: 1px solid #ccc; padding-bottom: 0.25rem;">Datos del Resguardante</h3>
            <table style="width: 100%; font-size: 0.95rem; margin-bottom: 1.5rem;">
                <tr><td style="padding: 0.25rem; width: 25%; font-weight: bold;">Nómina:</td><td style="padding: 0.25rem;">${e.numero_nomina == null ? 'Sin nómina asignada' : App.esc(e.numero_nomina)}</td></tr>
                <tr><td style="padding: 0.25rem; font-weight: bold;">Nombre:</td><td style="padding: 0.25rem;">${App.esc(e.nombre)}</td></tr>
                <tr><td style="padding: 0.25rem; font-weight: bold;">Cargo:</td><td style="padding: 0.25rem;">${App.esc(e.cargo)}</td></tr>
            </table>

            <h3 style="margin: 1.5rem 0 0.5rem; border-bottom: 1px solid #ccc; padding-bottom: 0.25rem;">Bienes Asignados (${activos.length})</h3>
            <table style="width: 100%; border-collapse: collapse; font-size: 0.83rem;">
                <thead>
                    <tr style="background: #f1f5f9;">
                        <th style="border: 1px solid #ccc; padding: 0.4rem; text-align: left;">#</th>
                        <th style="border: 1px solid #ccc; padding: 0.4rem; text-align: left;">Descripción</th>
                        <th style="border: 1px solid #ccc; padding: 0.4rem; text-align: left;">Inventario</th>
                        <th style="border: 1px solid #ccc; padding: 0.4rem; text-align: left;">Marca/Modelo</th>
                        <th style="border: 1px solid #ccc; padding: 0.4rem; text-align: left;">Serie</th>
                        <th style="border: 1px solid #ccc; padding: 0.4rem; text-align: left;">Material</th>
                        <th style="border: 1px solid #ccc; padding: 0.4rem; text-align: right;">Costo</th>
                    </tr>
                </thead>
                <tbody>
                    ${activos.map((a, i) => `
                        <tr>
                            <td style="border: 1px solid #ccc; padding: 0.4rem;">${i + 1}</td>
                            <td style="border: 1px solid #ccc; padding: 0.4rem;">${App.esc(a.descripcion)}</td>
                            <td style="border: 1px solid #ccc; padding: 0.4rem;">${App.esc(a.num_inventario)}</td>
                            <td style="border: 1px solid #ccc; padding: 0.4rem;">${App.esc(a.marca || '—')} / ${App.esc(a.modelo || '—')}</td>
                            <td style="border: 1px solid #ccc; padding: 0.4rem;">${App.esc(a.serie || '—')}</td>
                            <td style="border: 1px solid #ccc; padding: 0.4rem;">${App.esc(a.material || '—')}</td>
                            <td style="border: 1px solid #ccc; padding: 0.4rem; text-align: right;">${App.money(a.costo)}</td>
                        </tr>
                    `).join('')}
                    <tr style="font-weight: bold; background: #f8fafc;">
                        <td colspan="6" style="border: 1px solid #ccc; padding: 0.4rem; text-align: right;">Costo total:</td>
                        <td style="border: 1px solid #ccc; padding: 0.4rem; text-align: right;">${App.money(costoTotal)}</td>
                    </tr>
                </tbody>
            </table>

            <div style="margin-top: 3rem; display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                <div style="text-align: center;">
                    <div style="border-top: 1px solid #000; padding-top: 0.4rem; margin-top: 3rem;">
                        <strong>${App.esc(e.nombre)}</strong><br>
                        <small>Resguardante</small>
                    </div>
                </div>
                <div style="text-align: center;">
                    <div style="border-top: 1px solid #000; padding-top: 0.4rem; margin-top: 3rem;">
                        <strong>Vo. Bo.</strong><br>
                        <small>Responsable de Activos</small>
                    </div>
                </div>
            </div>
        </div>
    `;
    document.getElementById('resguardoBody').innerHTML = html;
    App.openModal('resguardoModal');
}