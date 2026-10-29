<?php
$page_title = 'Consulta de resguardo';
$page_js    = 'consulta.js';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
$user = require_auth();
require_once __DIR__ . '/../includes/header.php';
?>

<section class="page-header">
    <div>
        <h1>🔍 Consulta de Resguardo</h1>
        <p>Busca empleados por nombre o nómina y consulta sus bienes; las estadísticas aparecen debajo.</p>
        <?php if (!$user['es_superusuario']): ?>
            <p class="badge badge--info">📌 Solo verás datos de tu adscripción: <strong><?= e($user['area_nombre']) ?></strong></p>
        <?php endif; ?>
    </div>
</section>

<!-- ============ BUSCADOR ÚNICO POR NÓMINA O NOMBRE ============ -->
<section class="card search-card" id="buscadorResguardos" style="margin-bottom: 2rem; margin-top: 1rem;">
    <h2>🔍 Búsqueda de empleados y activos</h2>
    <p>Escribe el número de nómina o parte del nombre del empleado. Si encuentras varias coincidencias, selecciona al responsable correcto.</p>
    <form id="searchForm" class="search-form" autocomplete="off" role="search">
        <input type="search" id="busquedaEmpleadoGeneral" name="busqueda"
               maxlength="100" placeholder="Ej. 572 o Esteban Díaz"
               aria-label="Número de nómina o nombre del empleado" required>
        <button type="submit" class="btn btn--primary">🔎 Buscar</button>
    </form>
    <div id="listaCoincidencias" aria-live="polite" style="margin-top: 1rem;"></div>
</section>

<div id="resultContainer" aria-live="polite"></div>

<!-- ============ FILTRO (solo super) ============ -->
<?php if ($user['es_superusuario']): ?>
<div class="toolbar no-print" id="filtroDashboard">
    <label style="display: flex; align-items: center; gap: 0.5rem; flex: 1; min-width: 0;">
        <span style="font-weight: 600; white-space: nowrap;">🏛️ Filtrar por adscripción:</span>
        <select id="filtroAdscripcionDash" style="flex: 1; max-width: 320px;">
            <option value="">Todas las adscripciones</option>
        </select>
    </label>
    <button class="btn btn--ghost btn--sm" id="btnRefrescarDash" title="Refrescar datos">🔄 Refrescar</button>
</div>
<?php endif; ?>

<!-- ============ STATS GLOBALES ============ -->
<div id="statsGlobales" class="stats"></div>

<!-- ============ GRÁFICAS ============ -->
<div class="grid-2">
    <div class="card">
        <h2 class="card__title">📊 Activos por adscripción</h2>
        <div class="chart-wrapper">
            <canvas id="chartPorAdscripcion"></canvas>
        </div>
    </div>

    <div class="card">
        <h2 class="card__title">💰 Valor por adscripción</h2>
        <div class="chart-wrapper">
            <canvas id="chartValorAdscripcion"></canvas>
        </div>
    </div>

    <div class="card">
        <h2 class="card__title">🏢 Top 10 áreas con más activos</h2>
        <div class="chart-wrapper">
            <canvas id="chartTopAreas"></canvas>
        </div>
    </div>

    <div class="card">
        <h2 class="card__title">🏆 Top 10 empleados por valor</h2>
        <div class="chart-wrapper">
            <canvas id="chartTopEmpleados"></canvas>
        </div>
    </div>
</div>

<!-- ============ GRÁFICA DE LÍNEA (ancho completo) ============ -->
<div class="card">
    <h2 class="card__title">📈 Valor de adquisiciones por año</h2>
    <div class="chart-wrapper chart-wrapper--wide">
        <canvas id="chartPorAnio"></canvas>
    </div>
</div>


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

<!-- Modal Visor de Imagenes -->
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