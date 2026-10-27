
<?php
/**
 * COAPATAP
 * Página principal: Consulta de Resguardo
 *
 * Orden de visualización:
 * 1. Encabezado
 * 2. Búsqueda por Nómina
 * 3. Resultados de búsqueda
 * 4. Filtro por adscripción
 * 5. Estadísticas generales
 * 6. Gráficas
 *
 * Se conserva el JavaScript consulta.js.
 */

$page_title = 'Consulta de resguardo';
$page_js    = 'consulta.js';

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$user = require_auth();

require_once __DIR__ . '/../includes/header.php';
?>

<!-- ==========================================
     ENCABEZADO DE LA PÁGINA
========================================== -->

<section class="page-header">

    <div>

        <h1>🔍 Consulta de Resguardo</h1>

        <p>
            Dashboard con estadísticas en tiempo real
            y búsqueda por nómina.
        </p>

        <?php if (!$user['es_superusuario']): ?>

            <p class="badge badge--info">

                📌 Solo verás datos de tu adscripción:

                <strong>
                    <?= e($user['area_nombre']) ?>
                </strong>

            </p>

        <?php endif; ?>

    </div>

</section>


<!-- ==========================================
     1. BÚSQUEDA POR NÓMINA
     AHORA APARECE AL PRINCIPIO
========================================== -->

<div
    class="card search-card"
    style="margin-bottom: 2rem;"
>

    <h2>🔍 Búsqueda por Nómina</h2>

    <p>
        Ingresa el número de nómina del empleado
        y pulsa <strong>Buscar</strong>.
    </p>

    <form
        id="searchForm"
        class="search-form"
        autocomplete="off"
    >

        <input
            type="number"
            id="nomina"
            name="nomina"
            min="1"
            placeholder="Ej. 1001"
            required
        >

        <button
            type="submit"
            class="btn btn--primary"
        >
            🔎 Buscar
        </button>

    </form>

</div>


<!-- ==========================================
     2. RESULTADOS DE LA BÚSQUEDA
========================================== -->

<!--
    consulta.js utiliza este contenedor
    para mostrar los datos del empleado
    y sus activos.

    Se coloca debajo del buscador para
    que no aparezca hasta el final
    de las gráficas.
-->

<div id="resultContainer"></div>


<!-- ==========================================
     3. FILTRO POR ADSCRIPCIÓN
     SOLO SUPERUSUARIO
========================================== -->

<?php if ($user['es_superusuario']): ?>

    <div
        class="toolbar no-print"
        id="filtroDashboard"
    >

        <label
            style="
                display: flex;
                align-items: center;
                gap: 0.5rem;
                flex: 1;
                min-width: 0;
            "
        >

            <span
                style="
                    font-weight: 600;
                    white-space: nowrap;
                "
            >
                🏛️ Filtrar por adscripción:
            </span>

            <select
                id="filtroAdscripcionDash"
                style="
                    flex: 1;
                    max-width: 320px;
                "
            >

                <option value="">
                    Todas las adscripciones
                </option>

            </select>

        </label>

        <button
            class="btn btn--ghost btn--sm"
            id="btnRefrescarDash"
            title="Refrescar datos"
        >
            🔄 Refrescar
        </button>

    </div>

<?php endif; ?>


<!-- ==========================================
     4. ESTADÍSTICAS GENERALES
========================================== -->

<!--
    El archivo consulta.js carga aquí
    las estadísticas del inventario.
-->

<div
    id="statsGlobales"
    class="stats"
></div>


<!-- ==========================================
     5. GRÁFICAS DEL INVENTARIO
========================================== -->

<div class="grid-2">

    <!-- ======================================
         ACTIVOS POR ADSCRIPCIÓN
    ======================================= -->

    <div class="card">

        <h2 class="card__title">
            📊 Activos por adscripción
        </h2>

        <div class="chart-wrapper">

            <canvas
                id="chartPorAdscripcion"
            ></canvas>

        </div>

    </div>


    <!-- ======================================
         VALOR POR ADSCRIPCIÓN
    ======================================= -->

    <div class="card">

        <h2 class="card__title">
            💰 Valor por adscripción
        </h2>

        <div class="chart-wrapper">

            <canvas
                id="chartValorAdscripcion"
            ></canvas>

        </div>

    </div>


    <!-- ======================================
         TOP 10 ÁREAS
    ======================================= -->

    <div class="card">

        <h2 class="card__title">
            🏢 Top 10 áreas con más activos
        </h2>

        <div class="chart-wrapper">

            <canvas
                id="chartTopAreas"
            ></canvas>

        </div>

    </div>


    <!-- ======================================
         TOP 10 EMPLEADOS
    ======================================= -->

    <div class="card">

        <h2 class="card__title">
            🏆 Top 10 empleados por valor
        </h2>

        <div class="chart-wrapper">

            <canvas
                id="chartTopEmpleados"
            ></canvas>

        </div>

    </div>

</div>


<!-- ==========================================
     6. VALOR DE ADQUISICIONES POR AÑO
========================================== -->

<div class="card">

    <h2 class="card__title">
        📈 Valor de adquisiciones por año
    </h2>

    <div
        class="chart-wrapper chart-wrapper--wide"
    >

        <canvas
            id="chartPorAnio"
        ></canvas>

    </div>

</div>


<!-- ==========================================
     MODAL DE RESGUARDO PARA IMPRESIÓN
========================================== -->

<!--
    Conservamos este modal porque es
    utilizado por las funciones
    de consulta e impresión.
-->

<div
    class="modal-backdrop"
    id="resguardoModal"
>

    <div
        class="modal"
        style="max-width: 1000px;"
    >

        <div class="modal__header">

            <h2 class="modal__title">
                📄 Resguardo
            </h2>

            <button
                class="modal__close"
                type="button"
                onclick="App.closeModal('resguardoModal')"
                aria-label="Cerrar"
            >
                ×
            </button>

        </div>

        <div
            class="modal__body"
            id="resguardoBody"
            style="padding: 0;"
        ></div>

    </div>

</div>


<!-- ==========================================
     MODAL VISOR DE IMÁGENES
========================================== -->

<div
    class="modal-backdrop"
    id="imageViewerModal"
>

    <div class="modal modal--image-viewer">

        <div class="modal__header">

            <h2 class="modal__title">
                Vista Previa de Imagen
            </h2>

            <button
                class="modal__close"
                type="button"
                onclick="App.closeModal('imageViewerModal')"
                aria-label="Cerrar"
            >
                ×
            </button>

        </div>

        <div
            class="modal__body modal__body--center"
        >

            <img
                id="viewerImage"
                src=""
                alt="Vista ampliada"
                class="viewer-img"
            >

        </div>

    </div>
    
</div>


<!-- ==========================================
     PIE DE PÁGINA
========================================== -->

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
