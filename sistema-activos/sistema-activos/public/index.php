<?php
$page_title = 'Consulta de resguardo';
$page_js    = 'consulta.js';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
$user = require_permission('consulta');
require_once __DIR__ . '/../includes/header.php';
?>

<?php if (!$user['es_superusuario']): ?>
<section class="page-header">
    <div>
        <p class="badge badge--info">📌 Solo verás datos de tu adscripción: <strong><?= e($user['area_nombre']) ?></strong></p>
    </div>
</section>
<?php endif; ?>

<!-- ============ BUSCADOR ÚNICO POR NÓMINA O NOMBRE ============ -->
<section class="card search-card" id="buscadorResguardos" style="margin-bottom: 1.5rem; margin-top: 1rem;">
    <h2>Búsqueda de empleados y activos</h2>
    <form id="searchForm" class="search-form" autocomplete="off" role="search">
        <input type="search" id="busquedaEmpleadoGeneral" name="busqueda"
               maxlength="100" placeholder="Ej. 572 o Esteban Díaz"
               aria-label="Número de nómina o nombre del empleado" required>
        <button type="submit" class="btn btn--primary">🔎 Buscar</button>
    </form>
    <div id="listaCoincidencias" aria-live="polite" style="margin-top: 1rem;"></div>
</section>

<div id="resultContainer" aria-live="polite"></div>

<!-- ============ STATS GLOBALES: AHORA ANTES DEL FILTRO ============ -->
<div id="statsGlobales" class="stats"></div>

<!-- ============ PROPUESTA 4 REAL: UN SOLO PANEL PARA FILTRO Y GRÁFICAS ============ -->
<section class="dashboard-charts-panel" aria-labelledby="dashboardChartsTitle">
    <!-- Cabecera integrada dentro del mismo panel de las gráficas -->
    <div class="dashboard-charts-panel__header">
        <h2 class="dashboard-charts-panel__title" id="dashboardChartsTitle">
            <span aria-hidden="true"></span> Resumen gráfico
        </h2>

        <?php if ($user['es_superusuario']): ?>
        <!-- Mantener ID: consulta.js lee este select y carga automáticamente los datos -->
        <div class="dashboard-charts-panel__filter no-print" id="filtroDashboard">
            <svg class="dashboard-charts-panel__filter-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M3.5 5h17l-6.8 7.8v5.4l-3.4 1.8v-7.2L3.5 5z"
                      fill="none" stroke="currentColor" stroke-width="2"
                      stroke-linejoin="round" stroke-linecap="round"/>
            </svg>
            <label for="filtroAdscripcionDash">Adscripción:</label>
            <select id="filtroAdscripcionDash" aria-label="Filtrar estadísticas por adscripción">
                <option value="">Todas las adscripciones</option>
            </select>
        </div>
        <?php endif; ?>
    </div>

    <!-- Las cinco gráficas dentro del MISMO panel, no debajo de una barra aparte. -->
    <div class="dashboard-charts-panel__grid">
        <div class="card dashboard-chart-card">
            <h2 class="card__title">Activos por adscripción</h2>
            <div class="chart-wrapper">
                <canvas id="chartPorAdscripcion"></canvas>
            </div>
        </div>

        <div class="card dashboard-chart-card">
            <h2 class="card__title">Valor por adscripción</h2>
            <div class="chart-wrapper">
                <canvas id="chartValorAdscripcion"></canvas>
            </div>
        </div>

        <div class="card dashboard-chart-card">
            <h2 class="card__title">Áreas con más activos</h2>
            <div class="chart-wrapper">
                <canvas id="chartTopAreas"></canvas>
            </div>
        </div>

        <div class="card dashboard-chart-card">
            <h2 class="card__title">Empleados con mayor activos</h2>
            <div class="chart-wrapper">
                <canvas id="chartTopEmpleados"></canvas>
            </div>
        </div>

        <!-- La gráfica de línea ocupa dos columnas en escritorio. -->
        <div class="card dashboard-chart-card dashboard-chart-card--wide">
            <h2 class="card__title">Valor de adquisiciones por año</h2>
            <div class="chart-wrapper chart-wrapper--wide">
                <canvas id="chartPorAnio"></canvas>
            </div>
        </div>
    </div>
</section>

<!-- Modal de vista de resguardo para impresión -->
<div class="modal-backdrop" id="resguardoModal">
    <div class="modal" style="max-width: 1000px;">
        <div class="modal__header">
            <h2 class="modal__title">📄 Resguardo</h2>
            <button class="modal__close" onclick="App.closeModal('resguardoModal')" aria-label="Cerrar">×</button>
        </div>
        <div class="modal__body" id="resguardoBody" style="padding: 0;"></div>
    </div>
</div>

<!-- Modal Visor de Imágenes -->
<div class="modal-backdrop" id="imageViewerModal">
    <div class="modal modal--image-viewer">
        <div class="modal__header">
            <h2 class="modal__title">Vista Previa de Imagen</h2>
            <button class="modal__close" onclick="App.closeModal('imageViewerModal')">×</button>
        </div>
        <div class="modal__body modal__body--center">
            <img id="viewerImage" src="" alt="Vista ampliada" class="viewer-img">
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
