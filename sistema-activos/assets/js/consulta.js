/* =====================================================
   consulta.js — Búsqueda principal por número de nómina
   ===================================================== */
document.addEventListener('DOMContentLoaded', () => {
    const searchForm = document.getElementById('searchForm');
    const nominaInput = document.getElementById('nomina');
    const resultContainer = document.getElementById('resultContainer');

    if (nominaInput) nominaInput.focus();

    if (searchForm) {
        searchForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const nomina = parseInt(nominaInput.value, 10);
            if (!nomina) return;

            resultContainer.innerHTML = '<div class="card loading"><span class="spinner"></span> Buscando...</div>';

            try {
                const res = await App.api(`consulta.php?nomina=${nomina}`);
                if (!res.ok) throw new Error(res.error);
                renderResultado(res);
            } catch (err) {
                resultContainer.innerHTML = `
                    <div class="card empty-state">
                        <div class="empty-state__icon">⚠️</div>
                        <p>${App.esc(err.message)}</p>
                    </div>
                `;
                App.toast(err.message, 'error');
            }
        });
    }
});

function renderResultado(res) {
    const e = res.empleado;
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
                                        ? `<img class="table__thumb" src="${App.esc(a.ruta_imagen)}" alt="Foto" loading="lazy">`
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
                    <span class="info-item__value">${App.esc(e.numero_nomina)}</span>
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

async function imprimirResguardo() {
    const nomina = parseInt(document.getElementById('nomina').value, 10);
    if (!nomina) return;
    try {
        const res = await App.api(`consulta.php?nomina=${nomina}`);
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
                <tr><td style="padding: 0.25rem; width: 25%; font-weight: bold;">Nómina:</td><td style="padding: 0.25rem;">${App.esc(e.numero_nomina)}</td></tr>
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
