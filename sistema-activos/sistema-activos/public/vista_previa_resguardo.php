
<?php
/**
 * SISTEMA DE CONTROL DE ACTIVOS FIJOS - COAPATAP
 *
 * IMPRESIÓN DE RESGUARDOS
 *
 * Incluye:
 * - Filtros de adscripción, área y empleado.
 * - Consulta de activos desde PostgreSQL.
 * - Firmantes seleccionables o manuales.
 * - Memoria de los últimos firmantes.
 * - Logotipos institucionales automáticos.
 * - Marca de agua con número de página.
 * - Impresión horizontal.
 */

// ==========================================
// 1. SEGURIDAD
// ==========================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$user = require_auth();

if (!has_permission('activos')) {
    http_response_code(403);
    exit('No tienes permiso para consultar resguardos.');
}

// ==========================================
// 2. FUNCIONES AUXILIARES
// ==========================================

function resguardoEsc(mixed $valor): string
{
    return htmlspecialchars(
        (string) ($valor ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function resguardoFecha(mixed $fecha): string
{
    if (!$fecha) {
        return '—';
    }

    $d = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        substr((string) $fecha, 0, 10)
    );

    return $d ? $d->format('d/m/Y') : '—';
}

function resguardoPesos(mixed $valor): string
{
    return $valor === null
        ? '—'
        : '$' . number_format(
            (float) $valor,
            2,
            '.',
            ','
        );
}

/**
 * Generar una URL únicamente para imágenes
 * guardadas por Configuración de impresión.
 *
 * Si la ruta no es válida o el archivo
 * ya no existe, devolver una cadena vacía.
 */
function resguardoUrlImagen(mixed $ruta): string
{
    $ruta = (string) ($ruta ?? '');

    $patron = '~\Aassets/img/resguardos/historial/'
        . '[a-f0-9]{32}\.(?:png|jpg|webp)\z~';

    if (!preg_match($patron, $ruta)) {
        return '';
    }

    $archivoFisico = dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $ruta
        );

    if (!is_file($archivoFisico)) {
        return '';
    }

    return rtrim(base_url(), '/') . '/' . $ruta;
}

// ==========================================
// 3. USUARIO Y FILTROS
// ==========================================

$esSuper = !empty($user['es_superusuario']);

$areaUsuario = isset($user['area_id'])
    ? (int) $user['area_id']
    : 0;

// Adscripción seleccionada

$adscripcionElegida = filter_input(
    INPUT_GET,
    'adscripcion',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

// Área seleccionada

$areaElegida = filter_input(
    INPUT_GET,
    'area',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

// Número de nómina del resguardante

$nomina = filter_input(
    INPUT_GET,
    'nomina',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$hayBusqueda = isset($_GET['nomina']);

// ==========================================
// 4. RESPONSABLES DE FIRMA
// ==========================================

$nombreEntrega = trim(
    (string) ($_GET['nombre_entrega'] ?? '')
);

$nombreAutoriza = trim(
    (string) ($_GET['nombre_autoriza'] ?? '')
);

$nombreEntrega = mb_substr(
    $nombreEntrega,
    0,
    150,
    'UTF-8'
);

$nombreAutoriza = mb_substr(
    $nombreAutoriza,
    0,
    150,
    'UTF-8'
);

// ==========================================
// 5. VARIABLES GENERALES
// ==========================================

$error = '';

$empleado = null;

$adscripciones = [];
$areas = [];
$empleados = [];
$bienes = [];

// ==========================================
// 6. CONSULTAR ACTIVOS EN POSTGRESQL
// ==========================================

try {

    $pdo = db();

    // ======================================
    // 6.1 CONSULTAR ADSCRIPCIONES
    // ======================================

    $sqlAdscripciones = "
        SELECT DISTINCT
            ad.id,
            ad.nombre
        FROM adscripciones ad
        INNER JOIN areas ar
            ON ar.adscripcion_id = ad.id
        INNER JOIN empleados e
            ON e.area_id = ar.id
        WHERE 1 = 1
    ";

    $paramsAdscripciones = [];

    if (!$esSuper) {

        $sqlAdscripciones .= "
            AND ar.id = :area_usuario
        ";

        $paramsAdscripciones[':area_usuario'] =
            $areaUsuario;
    }

    $sqlAdscripciones .= "
        ORDER BY ad.nombre
    ";

    $stmt = $pdo->prepare($sqlAdscripciones);

    $stmt->execute($paramsAdscripciones);

    $adscripciones = $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );

    // ======================================
    // 6.2 CONSULTAR ÁREAS
    // ======================================

    $sqlAreas = "
        SELECT
            ar.id,
            ar.nombre,
            ar.adscripcion_id
        FROM areas ar
        WHERE EXISTS (
            SELECT 1
            FROM empleados e
            WHERE e.area_id = ar.id
        )
    ";

    $paramsAreas = [];

    if (!$esSuper) {

        $sqlAreas .= "
            AND ar.id = :area_usuario
        ";

        $paramsAreas[':area_usuario'] =
            $areaUsuario;
    }

    $sqlAreas .= "
        ORDER BY ar.nombre
    ";

    $stmt = $pdo->prepare($sqlAreas);

    $stmt->execute($paramsAreas);

    $areas = $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );

    // ======================================
    // 6.3 CONSULTAR EMPLEADOS
    // ======================================

    $sqlEmpleados = "
        SELECT
            e.numero_nomina,
            e.nombre,
            e.area_id,
            ar.adscripcion_id
        FROM empleados e
        LEFT JOIN areas ar
            ON ar.id = e.area_id
        WHERE 1 = 1
    ";

    $paramsEmpleados = [];

    if (!$esSuper) {

        $sqlEmpleados .= "
            AND e.area_id = :area_usuario
        ";

        $paramsEmpleados[':area_usuario'] =
            $areaUsuario;
    }

    $sqlEmpleados .= "
        ORDER BY e.nombre, e.numero_nomina
    ";

    $stmt = $pdo->prepare($sqlEmpleados);

    $stmt->execute($paramsEmpleados);

    $empleados = $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );

    // ======================================
    // 6.4 CONSULTAR RESGUARDANTE
    // ======================================

    if ($hayBusqueda) {

        if (!$nomina) {

            $error =
                'Selecciona un número de nómina válido.';

        } else {

            $sql = "
                SELECT
                    e.id,
                    e.numero_nomina,
                    e.nombre,
                    e.cargo,
                    e.area_id,
                    ar.nombre AS area_nombre,
                    ar.adscripcion_id,
                    ad.nombre AS adscripcion_nombre
                FROM empleados e
                LEFT JOIN areas ar
                    ON ar.id = e.area_id
                LEFT JOIN adscripciones ad
                    ON ad.id = ar.adscripcion_id
                WHERE e.numero_nomina = :nomina
            ";

            $params = [
                ':nomina' => $nomina
            ];

            // Respetar restricciones por área

            if (!$esSuper) {

                $sql .= "
                    AND e.area_id = :area_usuario
                ";

                $params[':area_usuario'] =
                    $areaUsuario;
            }

            // Verificar adscripción seleccionada

            if ($adscripcionElegida) {

                $sql .= "
                    AND ar.adscripcion_id =
                        :adscripcion_elegida
                ";

                $params[':adscripcion_elegida'] =
                    $adscripcionElegida;
            }

            // Verificar área seleccionada

            if ($areaElegida) {

                $sql .= "
                    AND e.area_id = :area_elegida
                ";

                $params[':area_elegida'] =
                    $areaElegida;
            }

            $stmt = $pdo->prepare($sql);

            $stmt->execute($params);

            $empleado = $stmt->fetch(
                PDO::FETCH_ASSOC
            );

            if (!$empleado) {

                $error =
                    'No se encontró el resguardante '
                    . 'en la adscripción y el área '
                    . 'seleccionadas o permitidas.';

            } else {

                // ==============================
                // 6.5 CONSULTAR ACTIVOS
                // ==============================

                $sqlBienes = "
                    SELECT
                        a.descripcion,
                        a.num_inventario,
                        a.marca,
                        a.modelo,
                        a.serie,
                        a.material,
                        a.estado_fisico,
                        a.fecha_adq,
                        a.factura,
                        a.costo,
                        a.observaciones
                    FROM activos a
                    WHERE a.empleado_id = :empleado_id
                ";

                $paramsBienes = [
                    ':empleado_id' =>
                        (int) $empleado['id']
                ];

                if (!$esSuper) {

                    $sqlBienes .= "
                        AND a.area_id = :area_usuario
                    ";

                    $paramsBienes[':area_usuario'] =
                        $areaUsuario;
                }

                $sqlBienes .= "
                    ORDER BY a.num_inventario, a.id
                ";

                $stmt = $pdo->prepare(
                    $sqlBienes
                );

                $stmt->execute($paramsBienes);

                $bienes = $stmt->fetchAll(
                    PDO::FETCH_ASSOC
                );
            }
        }
    }

} catch (Throwable $ex) {

    logger(
        'Error al consultar impresión de resguardos: '
        . $ex->getMessage(),
        'ERROR'
    );

    $error =
        'No se pudo consultar la información. '
        . 'Revisa la conexión y las tablas '
        . 'de PostgreSQL.';
}

// ==========================================
// 7. CARGAR IMÁGENES INSTITUCIONALES
// ==========================================

/**
 * Este bloque es la nueva integración.
 *
 * Busca las imágenes activas en PostgreSQL.
 * No utiliza nombres fijos como image1.png.
 *
 * Si una imagen falta, no detiene
 * el funcionamiento del resguardo.
 */

$imagenesResguardo = [
    'coapatap' => '',
    'tapachula' => '',
    'inferior' => ''
];

$errorImagenes = '';

try {

    $sqlImagenes = "
        SELECT
            tipo,
            ruta_relativa
        FROM public.imagenes_resguardo
        WHERE activa = TRUE
          AND tipo IN (
              'coapatap',
              'tapachula',
              'inferior'
          )
    ";

    $stmtImagenes = db()->query($sqlImagenes);

    $registrosImagenes = $stmtImagenes->fetchAll(
        PDO::FETCH_ASSOC
    );

    foreach ($registrosImagenes as $registro) {

        $tipo = (string) $registro['tipo'];

        if (!array_key_exists(
            $tipo,
            $imagenesResguardo
        )) {
            continue;
        }

        // Validar la ruta y comprobar que
        // el archivo exista en XAMPP.

        $imagenesResguardo[$tipo] =
            resguardoUrlImagen(
                $registro['ruta_relativa']
            );
    }

} catch (Throwable $ex) {

    logger(
        'Error al cargar imágenes de resguardo: '
        . $ex->getMessage(),
        'ERROR'
    );

    $errorImagenes =
        'No fue posible consultar las imágenes '
        . 'institucionales activas.';
}

// Identificar imágenes que faltan

$etiquetasImagenes = [
    'coapatap' => 'COAPATAP',
    'tapachula' => 'Tapachula',
    'inferior' => 'Imagen inferior'
];

$imagenesFaltantes = [];

foreach ($imagenesResguardo as $tipo => $url) {

    if ($url === '') {

        $imagenesFaltantes[] =
            $etiquetasImagenes[$tipo];
    }
}

// ==========================================
// 8. ORGANIZAR PÁGINAS
// ==========================================

$paginas = array_chunk($bienes, 8);

$totalPaginas = count($paginas);

$totalCosto = array_sum(
    array_map(
        static function ($bien) {
            return (float) ($bien['costo'] ?? 0);
        },
        $bienes
    )
);

$fechaEmision = date('d/m/Y');

// ==========================================
// 9. LEYENDA DEL RESGUARDO
// ==========================================

$leyenda = 'BIENES CON LOS QUE PARTICIPO EN MI TRABAJO, '
    . 'POR LO TANTO, ME RESPONSABILIZO A CONSERVAR, '
    . 'CUIDAR, VIGILAR Y RESGUARDAR LOS BIENES MUEBLES '
    . 'QUE TENGO ASIGNADOS, EXCLUSIVAMENTE PARA EL '
    . 'DESEMPEÑO DE MIS ACTIVIDADES, Y DEVOLVER EL '
    . '"MOBILIARIO, EQUIPO, HERRAMIENTAS Y APARATOS" '
    . 'CUANDO EL ORGANISMO ASI ME LO REQUIERA, '
    . 'EN CASO DE NO HACERLO, ME COMPROMETO A PAGAR '
    . 'SU REPOSICIÓN, DAÑOS O EN SU DEFECTO EL '
    . '"COMITÉ DE AGUA POTABLE Y ALCANTARILLADO '
    . 'DEL MUNICIPIO DE TAPACHULA", TOMARA LAS '
    . 'MEDIDAS QUE CONSIDERE NECESARIAS PARA '
    . 'REQUERIRME LO QUE EN ESTE MOMENTO RECIBO '
    . 'EN RESGUARDO, CON FUNDAMENTO EN LA '
    . '(LEY FEDERAL DEL TRABAJO, LEY DEL PATRIMONIO '
    . 'DEL ESTADO DE CHIAPAS, LEY DE DESARROLLO '
    . 'CONSTITUCIONAL EN MATERIA DE GOBIERNO '
    . 'Y ADMINISTRACION MUNICIPAL DEL ESTADO '
    . 'DE CHIAPAS, CODIGO PENAL PARA EL ESTADO '
    . 'DE CHIAPAS, LEY DE RESPONSABILIDADES '
    . 'ADMINISTRATIVAS PARA EL ESTADO DE CHIAPAS).';

// ==========================================
// 10. ENCABEZADO DEL SISTEMA
// ==========================================

$page_title = 'Impresión de resguardos';

$page_css = 'resguardo-impresion.css';

require_once __DIR__ . '/../includes/header.php';

?>

<!-- ========================================
     11. FORMULARIO DE IMPRESIÓN
========================================= -->


<!-- ========================================
     MÓDULO DE IMPRESIÓN
========================================= -->

<section class="modulo-impresion no-print">

    <!-- ENCABEZADO DEL MÓDULO -->

    <div class="imp-encabezado-modulo">

        <h1>
            🖨️ Impresión de resguardos
        </h1>

        <!-- =================================
             BOTÓN DE CONFIGURACIÓN
             SOLO SUPERUSUARIO
        ================================== -->

        <?php if ($esSuper): ?>

            <a
                href="configuracion_impresion.php"
                class="imp-btn-configuracion"
                title="Administrar los logotipos institucionales"
            >
                ⚙️ Configurar impresión
            </a>

        <?php endif; ?>

    </div>

    <!-- DESCRIPCIÓN -->

    <p>
        Selecciona la adscripción, el área y el
        resguardante para generar el documento.
    </p>


    <!-- Aviso si alguna imagen no está lista -->

    <?php if (
        $errorImagenes !== '' ||
        count($imagenesFaltantes) > 0
    ): ?>

        <div class="imp-aviso">

            <strong>
                Imágenes institucionales:
            </strong>

            <?php if ($errorImagenes !== ''): ?>

                <?= resguardoEsc($errorImagenes) ?>

            <?php else: ?>

                Faltan imágenes activas o disponibles:
                <?= resguardoEsc(
                    implode(', ', $imagenesFaltantes)
                ) ?>.

            <?php endif; ?>

            <?php if ($esSuper): ?>

                <a href="configuracion_impresion.php">
                    Ir a Configuración de impresión
                </a>

            <?php endif; ?>

        </div>

    <?php endif; ?>

    <form
        method="get"
        action="vista_previa_resguardo.php"
        class="impresion-formulario"
    >

        <!-- ADSCRIPCIÓN -->

        <label>
            1. Adscripción

            <select
                name="adscripcion"
                id="impAdscripcion"
            >

                <option value="">
                    Todas las adscripciones permitidas
                </option>

                <?php foreach (
                    $adscripciones as $ads
                ): ?>

                    <option
                        value="<?= (int) $ads['id'] ?>"
                        <?= (int) $adscripcionElegida ===
                            (int) $ads['id']
                            ? 'selected'
                            : '' ?>
                    >
                        <?= resguardoEsc(
                            $ads['nombre']
                        ) ?>
                    </option>

                <?php endforeach; ?>

            </select>
        </label>

        <!-- ÁREA -->

        <label>
            2. Área

            <select
                name="area"
                id="impArea"
            >

                <option value="">
                    Todas las áreas permitidas
                </option>

                <?php foreach ($areas as $area): ?>

                    <option
                        value="<?= (int) $area['id'] ?>"
                        data-adscripcion="<?= (int) (
                            $area['adscripcion_id'] ?? 0
                        ) ?>"
                        <?= (int) $areaElegida ===
                            (int) $area['id']
                            ? 'selected'
                            : '' ?>
                    >
                        <?= resguardoEsc(
                            $area['nombre']
                        ) ?>
                    </option>

                <?php endforeach; ?>

            </select>
        </label>

        <!-- EMPLEADO -->

        <label>
            3. Empleado (número de nómina)

            <select
                name="nomina"
                id="impNomina"
                required
            >

                <option value="">
                    Selecciona un resguardante
                </option>

                <?php foreach (
                    $empleados as $opcion
                ): ?>

                    <option
                        value="<?= (int) $opcion['numero_nomina'] ?>"
                        data-area="<?= (int) (
                            $opcion['area_id'] ?? 0
                        ) ?>"
                        data-adscripcion="<?= (int) (
                            $opcion['adscripcion_id'] ?? 0
                        ) ?>"
                        <?= (int) $nomina ===
                            (int) $opcion['numero_nomina']
                            ? 'selected'
                            : '' ?>
                    >
                        <?= resguardoEsc(
                            $opcion['numero_nomina']
                            . ' — '
                            . $opcion['nombre']
                        ) ?>
                    </option>

                <?php endforeach; ?>

            </select>
        </label>

        <!-- CONSULTAR -->

        <button
            type="submit"
            class="imp-btn imp-btn-primario"
        >
            🔍 Consultar resguardo
        </button>

        <!-- ===================================
             RESPONSABLES DE FIRMA
        ==================================== -->

        <div class="imp-firmantes">

            <!-- ENTREGA -->

            <div class="imp-firmante-control">

                <label for="impBuscarEntrega">
                    Entregó — Jefe de Oficina de Activo Fijo
                </label>

                <input
                    type="text"
                    id="impBuscarEntrega"
                    list="impListaEmpleadosFirmantes"
                    placeholder="Buscar empleado o escribir nombre"
                    maxlength="150"
                    autocomplete="off"
                >

                <input
                    type="hidden"
                    id="impNombreEntrega"
                    name="nombre_entrega"
                    value="<?= resguardoEsc(
                        $nombreEntrega
                    ) ?>"
                >

                <small class="imp-firmante-ayuda">
                    Selecciona un empleado registrado
                    o escribe un nombre manualmente.
                </small>

            </div>

            <!-- AUTORIZA -->

            <div class="imp-firmante-control">

                <label for="impBuscarAutoriza">
                    Autorizó — Director General
                </label>

                <input
                    type="text"
                    id="impBuscarAutoriza"
                    list="impListaEmpleadosFirmantes"
                    placeholder="Buscar empleado o escribir nombre"
                    maxlength="150"
                    autocomplete="off"
                >

                <input
                    type="hidden"
                    id="impNombreAutoriza"
                    name="nombre_autoriza"
                    value="<?= resguardoEsc(
                        $nombreAutoriza
                    ) ?>"
                >

                <small class="imp-firmante-ayuda">
                    Selecciona un empleado registrado
                    o escribe un nombre manualmente.
                </small>

            </div>

            <!-- LISTA DE EMPLEADOS PARA FIRMAS -->

            <datalist
                id="impListaEmpleadosFirmantes"
            >

                <?php foreach (
                    $empleados as $persona
                ): ?>

                    <option
                        value="<?= resguardoEsc(
                            $persona['numero_nomina']
                            . ' — '
                            . $persona['nombre']
                        ) ?>"
                        data-nomina="<?= resguardoEsc(
                            $persona['numero_nomina']
                        ) ?>"
                        data-nombre="<?= resguardoEsc(
                            $persona['nombre']
                        ) ?>"
                    ></option>

                <?php endforeach; ?>

            </datalist>

        </div>

    </form>

    <!-- ERRORES -->

    <?php if ($error): ?>

        <p
            class="imp-aviso imp-error"
            role="alert"
        >
            <?= resguardoEsc($error) ?>
        </p>

    <?php endif; ?>

    <!-- RESUMEN -->

    <?php if ($empleado): ?>

        <div class="imp-resumen">

            <div>
                <small>Resguardante</small>
                <strong>
                    <?= resguardoEsc(
                        $empleado['nombre']
                    ) ?>
                </strong>
            </div>

            <div>
                <small>Nómina</small>
                <strong>
                    <?= resguardoEsc(
                        $empleado['numero_nomina']
                    ) ?>
                </strong>
            </div>

            <div>
                <small>Activos</small>
                <strong>
                    <?= count($bienes) ?>
                </strong>
            </div>

            <div>
                <small>Valor registrado</small>
                <strong>
                    <?= resguardoPesos(
                        $totalCosto
                    ) ?>
                </strong>
            </div>

        </div>

        <?php if (!$bienes): ?>

            <p class="imp-aviso">
                Este resguardante no tiene activos
                visibles para tu usuario.
                No se genera un documento vacío.
            </p>

        <?php else: ?>

            <div class="imp-acciones">

                <span>
                    Vista previa:
                    <?= $totalPaginas ?> página(s),
                    8 bienes máximo por página.
                </span>

                <button
                    type="button"
                    class="imp-btn imp-btn-primario"
                    onclick="window.print()"
                >
                    🖨️ Imprimir / Guardar como PDF
                </button>

            </div>

        <?php endif; ?>

    <?php endif; ?>

</section>

<!-- ========================================
     12. DOCUMENTOS DE IMPRESIÓN
========================================= -->

<?php if ($empleado && $bienes): ?>

<section class="imp-documentos">

    <?php foreach (
        $paginas as $indice => $paginaBienes
    ): ?>

        <article class="resguardo-hoja">

            <!-- =================================
                 DECORACIÓN INSTITUCIONAL
            ================================== -->

            <div
                class="resguardo-graficos"
                aria-hidden="true"
            >

                <!-- LOGO SUPERIOR IZQUIERDO -->

                <?php if (
                    $imagenesResguardo['coapatap'] !== ''
                ): ?>

                    <img
                        src="<?= resguardoEsc(
                            $imagenesResguardo['coapatap']
                        ) ?>"
                        alt=""
                        class="resguardo-logo resguardo-logo--izquierda"
                    >

                <?php endif; ?>

                <!-- LOGO SUPERIOR DERECHO -->

                <?php if (
                    $imagenesResguardo['tapachula'] !== ''
                ): ?>

                    <img
                        src="<?= resguardoEsc(
                            $imagenesResguardo['tapachula']
                        ) ?>"
                        alt=""
                        class="resguardo-logo resguardo-logo--derecha"
                    >

                <?php endif; ?>

                <!-- MARCA DE AGUA DE PÁGINA -->

                <div class="resguardo-marca-pagina">
                    <?= $indice + 1 ?>
                </div>

            </div>

            <!-- =================================
                 ENCABEZADO INSTITUCIONAL
            ================================== -->

            <header class="imp-encabezado">

                <div>

                    <strong>
                        COMITÉ DE AGUA POTABLE Y
                        ALCANTARILLADO DEL MUNICIPIO
                        DE TAPACHULA, CHIAPAS.
                    </strong>

                    <div>
                        DIRECCIÓN ADMINISTRATIVA.-
                        DEPARTAMENTO DE RECURSOS MATERIALES
                    </div>

                    <div>
                        Y SERVICIOS.- OFICINA DE ACTIVO FIJO
                        Y SERVICIOS GENERALES.
                    </div>

                    <div>
                        RESGUARDO: MOBILIARIO, EQUIPO,
                        HERRAMIENTAS Y APARATOS.
                    </div>

                </div>

                <div class="imp-identificador">

                    <strong class="imp-numero">
                        <?= resguardoEsc(
                            $empleado['numero_nomina']
                        ) ?>
                    </strong>

                    <span>
                        NÚMERO DE NÓMINA
                    </span>

                    <span class="imp-fecha">
                        FECHA DE EMISIÓN:
                        <?= resguardoEsc(
                            $fechaEmision
                        ) ?>
                    </span>

                </div>

            </header>

            <!-- TÍTULO -->

            <div class="imp-titulo">

                <strong>
                    RESGUARDO TOTAL DE BIENES
                </strong>

                <span>
                    Página <?= $indice + 1 ?>
                    de <?= $totalPaginas ?>
                </span>

            </div>

            <!-- ÁREA Y ADSCRIPCIÓN -->

            <div class="imp-area">

                <span>
                    BIENES CON LOS QUE CUENTA:
                </span>

                <strong>
                    <?= resguardoEsc(
                        $empleado['area_nombre']
                        ?: 'SIN ÁREA'
                    ) ?>
                </strong>

                <span>
                    ÁREA DE ADSCRIPCIÓN:
                </span>

                <strong>
                    <?= resguardoEsc(
                        $empleado['adscripcion_nombre']
                        ?: 'SIN ADSCRIPCIÓN'
                    ) ?>
                </strong>

            </div>

            <!-- RESGUARDANTE -->

            <div class="imp-resguardante">

                RESGUARDANTE:

                <strong>
                    <?= resguardoEsc(
                        $empleado['nombre']
                    ) ?>
                </strong>

            </div>

            <!-- =================================
                 TABLA DE ACTIVOS
            ================================== -->

            <table class="imp-tabla">

                
                <colgroup>
                    <col style="width:19%">
                    <col style="width:7%">
                    <col style="width:7%">
                    <col style="width:7%">
                    <col style="width:9%">
                    <col style="width:10%">
                    <col style="width:8%">
                    <col style="width:7%">
                    <col style="width:8%">
                    <col style="width:7%">
                    <col style="width:11%">
                </colgroup>


                <thead>

                    <tr>
                        <th>DESCRIPCIÓN</th>
                        <th>ECO. O INVENT.</th>
                        <th>MARCA</th>
                        <th>MODELO</th>
                        <th>SERIE</th>
                        <th>MATERIAL</th>
                        <th>STATUS</th>
                        <th>FECHA ADQUIS.</th>
                        <th>NÚMERO FACTURA</th>
                        <th>COSTO ADQUIS.</th>
                        <th>OBSERVACIONES</th>
                    </tr>

                </thead>

                <tbody>

                    <?php foreach (
                        $paginaBienes as $bien
                    ): ?>

                        <tr>

                            <td>
                                <?= resguardoEsc(
                                    $bien['descripcion']
                                ) ?>
                            </td>

                            <td>
                                <?= resguardoEsc(
                                    $bien['num_inventario']
                                ) ?>
                            </td>

                            <td>
                                <?= resguardoEsc(
                                    $bien['marca'] ?: '—'
                                ) ?>
                            </td>

                            <td>
                                <?= resguardoEsc(
                                    $bien['modelo'] ?: '—'
                                ) ?>
                            </td>

                            <td>
                                <?= resguardoEsc(
                                    $bien['serie'] ?: '—'
                                ) ?>
                            </td>

                            <td>
                                <?= resguardoEsc(
                                    $bien['material'] ?: '—'
                                ) ?>
                            </td>

                            
                            <!-- ======================================
                                ESTADO FÍSICO DEL ACTIVO
                            ======================================= -->

                            <td>

                                <?php

                                $estadoActual = strtoupper(
                                    trim(
                                        (string) (
                                            $bien['estado_fisico'] ?? ''
                                        )
                                    )
                                );

                                $estadosPermitidos = [
                                    'BUENO',
                                    'NUEVO',
                                    'REGULAR',
                                    'INSERVIBLE'
                                ];

                                $estadoImpreso = in_array(
                                    $estadoActual,
                                    $estadosPermitidos,
                                    true
                                )
                                    ? $estadoActual
                                    : '—';

                                ?>

                                <?= resguardoEsc($estadoImpreso) ?>

                            </td>


                            <td>
                                <?= resguardoFecha(
                                    $bien['fecha_adq']
                                ) ?>
                            </td>

                            <td>
                                <?= resguardoEsc(
                                    $bien['factura'] ?: '—'
                                ) ?>
                            </td>

                            <td>
                                <?= resguardoPesos(
                                    $bien['costo']
                                ) ?>
                            </td>

                            <td>
                                <?= resguardoEsc(
                                    $bien['observaciones']
                                    ?: '—'
                                ) ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

            <!-- =================================
                 LEYENDA Y FIRMAS
            ================================== -->

            <footer class="imp-pie">

                <p class="imp-leyenda">
                    <?= resguardoEsc(
                        $leyenda
                    ) ?>
                </p>

                <div class="imp-firmas">

                    <!-- ENTREGÓ -->

                    <div>

                        <strong>
                            ENTREGÓ
                        </strong>

                        <span class="imp-linea"></span>

                        <b
                            class="imp-nombre-firma"
                            data-firma-entrega
                        >
                            <?= $nombreEntrega !== ''
                                ? resguardoEsc(
                                    $nombreEntrega
                                )
                                : '&nbsp;' ?>
                        </b>

                        <small>
                            JEFE DE OFICINA DE ACTIVO FIJO
                        </small>

                    </div>

                    <!-- RECIBÍ -->

                    <div>

                        <strong>
                            RECIBÍ (RESGUARDANTE)
                        </strong>

                        <span class="imp-linea"></span>

                        <b class="imp-nombre-firma">
                            <?= resguardoEsc(
                                $empleado['nombre']
                            ) ?>
                        </b>

                        <small>
                            <?= resguardoEsc(
                                $empleado['cargo']
                            ) ?>
                        </small>

                    </div>

                    <!-- AUTORIZÓ -->

                    <div>

                        <strong>
                            AUTORIZÓ
                        </strong>

                        <span class="imp-linea"></span>

                        <b
                            class="imp-nombre-firma"
                            data-firma-autoriza
                        >
                            <?= $nombreAutoriza !== ''
                                ? resguardoEsc(
                                    $nombreAutoriza
                                )
                                : '&nbsp;' ?>
                        </b>

                        <small>
                            DIRECTOR GENERAL
                        </small>

                    </div>

                </div>

            </footer>

            <!-- =================================
                 IMAGEN INFERIOR
            ================================== -->

            <?php if (
                $imagenesResguardo['inferior'] !== ''
            ): ?>

                <div
                    class="resguardo-imagen-inferior"
                    aria-hidden="true"
                >

                    <img
                        src="<?= resguardoEsc(
                            $imagenesResguardo['inferior']
                        ) ?>"
                        alt=""
                    >

                </div>

            <?php endif; ?>

        </article>

    <?php endforeach; ?>

</section>

<?php endif; ?>

<!-- ========================================
     13. FILTRAR ADSCRIPCIÓN, ÁREA Y EMPLEADO
========================================= -->

<script>
(function () {

    "use strict";

    const adscripcion =
        document.getElementById('impAdscripcion');

    const area =
        document.getElementById('impArea');

    const nomina =
        document.getElementById('impNomina');

    if (!adscripcion || !area || !nomina) {
        return;
    }

    function filtrar() {

        const adscripcionSeleccionada =
            adscripcion.value;

        // Filtrar las áreas.

        Array.from(area.options).forEach(
            function (opcion) {

                if (!opcion.value) {
                    return;
                }

                const visible =
                    !adscripcionSeleccionada ||
                    opcion.dataset.adscripcion ===
                        adscripcionSeleccionada;

                opcion.hidden = !visible;
                opcion.disabled = !visible;

                if (!visible && opcion.selected) {
                    area.value = '';
                }
            }
        );

        const areaSeleccionada = area.value;

        // Filtrar los empleados.

        Array.from(nomina.options).forEach(
            function (opcion) {

                if (!opcion.value) {
                    return;
                }

                const coincideAdscripcion =
                    !adscripcionSeleccionada ||
                    opcion.dataset.adscripcion ===
                        adscripcionSeleccionada;

                const coincideArea =
                    !areaSeleccionada ||
                    opcion.dataset.area ===
                        areaSeleccionada;

                const visible =
                    coincideAdscripcion &&
                    coincideArea;

                opcion.hidden = !visible;
                opcion.disabled = !visible;

                if (!visible && opcion.selected) {
                    nomina.value = '';
                }
            }
        );
    }

    adscripcion.addEventListener(
        'change',
        filtrar
    );

    area.addEventListener(
        'change',
        filtrar
    );

    filtrar();

})();
</script>

<!-- ========================================
     14. RESPONSABLES DE FIRMA
========================================= -->

<script>
(function () {

    "use strict";

    // Campos de búsqueda.

    const buscarEntrega =
        document.getElementById('impBuscarEntrega');

    const buscarAutoriza =
        document.getElementById('impBuscarAutoriza');

    // Campos que se envían a PHP.

    const nombreEntrega =
        document.getElementById('impNombreEntrega');

    const nombreAutoriza =
        document.getElementById('impNombreAutoriza');

    // Lista de sugerencias.

    const listaEmpleados =
        document.getElementById(
            'impListaEmpleadosFirmantes'
        );

    if (
        !buscarEntrega ||
        !buscarAutoriza ||
        !nombreEntrega ||
        !nombreAutoriza ||
        !listaEmpleados
    ) {
        return;
    }

    // ======================================
    // OBTENER EMPLEADOS
    // ======================================

    const empleados = Array.from(
        listaEmpleados.options
    ).map(function (opcion) {

        return {
            texto: opcion.value,
            nomina: opcion.dataset.nomina || '',
            nombre: opcion.dataset.nombre || ''
        };

    });

    // ======================================
    // BUSCAR EMPLEADO
    // ======================================

    function buscarEmpleado(valor) {

        const texto = String(valor || '')
            .trim()
            .toLocaleLowerCase('es');

        if (!texto) {
            return null;
        }

        return empleados.find(function (empleado) {

            return (
                empleado.texto
                    .toLocaleLowerCase('es') === texto
                ||
                empleado.nomina
                    .toLocaleLowerCase('es') === texto
            );

        }) || null;
    }

    // ======================================
    // OBTENER NOMBRE
    // ======================================

    function obtenerNombre(valor) {

        const texto = String(valor || '').trim();

        const empleado = buscarEmpleado(texto);

        return empleado
            ? empleado.nombre
            : texto;
    }

    // ======================================
    // PREFERENCIAS POR USUARIO
    // ======================================

    const usuarioSistema =
        <?= json_encode(
            (string) (
                $user['id']
                ?? $user['nombre_usuario']
                ?? 'usuario'
            ),
            JSON_HEX_TAG |
            JSON_HEX_AMP |
            JSON_HEX_APOS |
            JSON_HEX_QUOT
        ) ?>;

    const claveStorage =
        'coapatap_firmantes_v1_' + usuarioSistema;

    function leerPreferencias() {

        try {

            const datos =
                localStorage.getItem(claveStorage);

            return datos
                ? JSON.parse(datos)
                : {};

        } catch (error) {

            return {};
        }
    }

    // ======================================
    // GUARDAR PREFERENCIAS
    // ======================================

    function guardarPreferencias() {

        const empleadoEntrega =
            buscarEmpleado(buscarEntrega.value);

        const empleadoAutoriza =
            buscarEmpleado(buscarAutoriza.value);

        const preferencias = {

            entrega: {
                texto: buscarEntrega.value.trim(),
                nomina: empleadoEntrega
                    ? empleadoEntrega.nomina
                    : null
            },

            autoriza: {
                texto: buscarAutoriza.value.trim(),
                nomina: empleadoAutoriza
                    ? empleadoAutoriza.nomina
                    : null
            }

        };

        try {

            localStorage.setItem(
                claveStorage,
                JSON.stringify(preferencias)
            );

        } catch (error) {

            // Si el navegador bloquea el
            // almacenamiento, el módulo
            // puede seguir imprimiendo.
        }
    }

    // ======================================
    // RECUPERAR UNA SELECCIÓN
    // ======================================

    function recuperarSeleccion(
        preferencia,
        respaldo
    ) {

        if (
            !preferencia ||
            typeof preferencia !== 'object'
        ) {
            return respaldo || '';
        }

        if (preferencia.nomina) {

            const empleado = buscarEmpleado(
                String(preferencia.nomina)
            );

            if (empleado) {
                return empleado.texto;
            }
        }

        if (
            typeof preferencia.texto === 'string'
        ) {
            return preferencia.texto;
        }

        return respaldo || '';
    }

    // ======================================
    // RESTAURAR RESPONSABLES
    // ======================================

    function restaurarPreferencias() {

        const preferencias = leerPreferencias();

        buscarEntrega.value = recuperarSeleccion(
            preferencias.entrega,
            nombreEntrega.value
        );

        buscarAutoriza.value = recuperarSeleccion(
            preferencias.autoriza,
            nombreAutoriza.value
        );
    }

    // ======================================
    // ACTUALIZAR FIRMAS
    // ======================================

    function actualizarFirmas() {

        const entrega =
            obtenerNombre(buscarEntrega.value);

        const autoriza =
            obtenerNombre(buscarAutoriza.value);

        nombreEntrega.value = entrega;
        nombreAutoriza.value = autoriza;

        document.querySelectorAll(
            '[data-firma-entrega]'
        ).forEach(function (elemento) {

            elemento.textContent =
                entrega || '\u00A0';
        });

        document.querySelectorAll(
            '[data-firma-autoriza]'
        ).forEach(function (elemento) {

            elemento.textContent =
                autoriza || '\u00A0';
        });
    }

    // ======================================
    // CAMBIOS EN LOS CAMPOS
    // ======================================

    function procesarCambio() {

        actualizarFirmas();
        guardarPreferencias();
    }

    buscarEntrega.addEventListener(
        'input',
        procesarCambio
    );

    buscarEntrega.addEventListener(
        'change',
        procesarCambio
    );

    buscarAutoriza.addEventListener(
        'input',
        procesarCambio
    );

    buscarAutoriza.addEventListener(
        'change',
        procesarCambio
    );

    // ======================================
    // CONSERVAR AL CONSULTAR RESGUARDO
    // ======================================

    const formulario =
        buscarEntrega.closest('form');

    if (formulario) {

        formulario.addEventListener(
            'submit',
            function () {

                actualizarFirmas();
                guardarPreferencias();
            }
        );
    }

    // ======================================
    // INICIAR
    // ======================================

    restaurarPreferencias();
    actualizarFirmas();

})();
</script>

<?php

// ==========================================
// 15. PIE DE PÁGINA DEL SISTEMA
// ==========================================

require_once __DIR__ . '/../includes/footer.php';

?>
