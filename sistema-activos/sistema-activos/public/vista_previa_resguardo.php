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
    $nombreAutoriza, 0, 150, 'UTF-8'
);
$nominaAutoriza = trim((string)($_GET['nomina_autoriza'] ?? ''));
$cargoAutoriza = '';


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
            e.cargo,
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
                // La autoridad es elegida manualmente entre los empleados
                // de la misma adscripción del resguardante.
                $autorizantesPermitidos = array_values(array_filter(
                    $empleados,
                    static fn($persona) =>
                        (int)($persona['adscripcion_id'] ?? 0) > 0
                        && (int)$persona['adscripcion_id'] === (int)$empleado['adscripcion_id']
                ));
                $autoridadSeleccionada = null;
                foreach ($autorizantesPermitidos as $persona) {
                    if ($nominaAutoriza !== '' &&
                        (string)$persona['numero_nomina'] === $nominaAutoriza) {
                        $autoridadSeleccionada = $persona;
                        break;
                    }
                }
                // No aceptar nombres arbitrarios o de otras adscripciones.
                $nombreAutoriza = $autoridadSeleccionada['nombre'] ?? '';
                $cargoAutoriza = $autoridadSeleccionada['cargo'] ?? '';

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
// 8.1 EXPORTAR RESGUARDO A EXCEL (.XLSX)
// ==========================================
/**
 * El Excel replica la vista previa / PDF:
 *  - Una hoja por cada página de la vista previa (8 bienes máximo),
 *    cada una con su encabezado, logotipos, leyenda, firmas e imagen
 *    inferior, separadas con saltos de página manuales.
 *  - Los logotipos se insertan con su tamaño real (proporción
 *    conservada) y NO se estiran a las celdas.
 *  - Mismos textos que la vista previa ("—" en campos vacíos,
 *    estado físico completo, fechas dd/mm/aaaa, costo en pesos).
 *  - Altura de cada renglón calculada según el texto para que
 *    nada se corte.
 *  - Escala de impresión calculada para que cada página
 *    quepa completa en una hoja A4 horizontal.
 */
if (($_GET['exportar'] ?? '') === 'excel') {
    if (!$empleado || !$bienes || $error !== '') {
        http_response_code(400);
        exit('No hay un resguardo válido con activos para descargar.');
    }
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        exit('Habilita la extensión ZIP de PHP en XAMPP.');
    }
    @set_time_limit(60);
    @ini_set('memory_limit', '256M');

    // ---------- Helpers ----------
    $escXml = static function ($v): string {
        $texto = (string)($v ?? '');
        if (function_exists('mb_convert_encoding')) {
            $texto = mb_convert_encoding($texto, 'UTF-8', 'UTF-8');
        } elseif (function_exists('iconv')) {
            $texto = iconv('UTF-8', 'UTF-8//IGNORE', $texto) ?: '';
        }
        $texto = preg_replace(
            '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{FFFE}\x{FFFF}]/u',
            '',
            $texto
        ) ?? '';
        return htmlspecialchars($texto, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };
    $refCol = static function (int $n): string {
        $s = '';
        while ($n > 0) { $s = chr(65 + ($n - 1) % 26) . $s; $n = intdiv($n - 1, 26); }
        return $s;
    };

    // ---------- Estilos (nombre => [fuente, relleno, borde, horiz, vert, ajustar, formato]) ----------
    // Fuentes: 0 normal | 1 azul bold | 2 azul normal | 3 azul bold 14 | 4 rojo bold 16
    //          5 bold | 6 blanco bold 9 | 7 normal 8 | 8 normal 7.5 | 9 bold 9 | 10 rojo bold 9 | 11 bold 8
    $styleDefs = [
        'base'      => [0, 0, 0, 'left',    'center', 0, 0],
        'inst'      => [1, 0, 0, 'left',    'center', 0, 0],
        'inst2'     => [2, 0, 0, 'left',    'center', 0, 0],
        'nomina'    => [4, 0, 0, 'center',  'center', 0, 0],
        'rotulo'    => [5, 0, 0, 'center',  'center', 0, 0],
        'fecha'     => [0, 0, 0, 'center',  'center', 0, 0],
        'titulo'    => [3, 0, 0, 'center',  'center', 0, 0],
        'pagina'    => [1, 0, 0, 'center',  'center', 0, 0],
        'uma'       => [10, 0, 0, 'right',  'center', 0, 0],
        'etq'       => [1, 0, 0, 'left',    'center', 1, 0],
        'val'       => [1, 0, 0, 'center',  'center', 1, 0],
        'th'        => [6, 2, 1, 'center',  'center', 1, 0],
        'tdL'       => [7, 0, 1, 'left',    'center', 1, 0],
        'tdC'       => [7, 0, 1, 'center',  'center', 1, 0],
        'tdM'       => [7, 0, 1, 'center',  'center', 1, 164],
        'ley'       => [8, 0, 0, 'justify', 'center', 1, 0],
        'fTit'      => [9, 0, 0, 'center',  'center', 0, 0],
        'fNom'      => [9, 0, 2, 'center',  'top',    1, 0],
        'fCargo'    => [7, 0, 0, 'center',  'top',    1, 0],
        'totLbl'    => [9, 0, 0, 'right',   'center', 0, 0],
        'totVal'    => [5, 0, 1, 'center',  'center', 0, 164],
    ];
    $S = array_flip(array_keys($styleDefs));

    // ---------- Anchos de columna (A y M: márgenes) ----------
    $cols = [4.29, 42.86, 12.14, 12.14, 13.00, 15.00, 20.00, 9.00, 12.00, 11.00, 11.00, 28.00, 4.29];
    $anchoCol = static function (int $c) use ($cols): float { return $cols[$c - 1]; };
    $pxCol    = static function (int $c) use ($cols): float { return $cols[$c - 1] * 7 + 5; };

    // ---------- Construcción de filas ----------
    $filas = []; $alturas = []; $combinadas = [];
    $celda = static function (int $c, int $r, mixed $v, int $style, bool $num = false) use ($escXml, $refCol): string {
        $p = $refCol($c) . $r;
        if ($num && $v !== null && is_numeric($v)) {
            return '<c r="' . $p . '" s="' . $style . '"><v>' . $escXml((float)$v) . '</v></c>';
        }
        if ($v === null || $v === '') {
            return '<c r="' . $p . '" s="' . $style . '"/>';
        }
        return '<c r="' . $p . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">'
            . $escXml($v) . '</t></is></c>';
    };
    // Celda (posiblemente combinada): escribe el valor en la primera columna
    // y rellena el resto con el mismo estilo para que bordes y fondo salgan completos.
    $rango = static function (int $r, int $c1, int $c2, mixed $v, string $est, bool $num = false)
        use ($celda, $S, &$combinadas, $refCol): array {
        $out = [$c1 => $celda($c1, $r, $v, $S[$est], $num)];
        for ($c = $c1 + 1; $c <= $c2; $c++) { $out[$c] = $celda($c, $r, null, $S[$est]); }
        if ($c2 > $c1) { $combinadas[] = '<mergeCell ref="' . $refCol($c1) . $r . ':' . $refCol($c2) . $r . '"/>'; }
        return $out;
    };
    $fila = static function (int $r, array $porCol, float $alto) use (&$filas, &$alturas): void {
        ksort($porCol);
        $filas[$r] = '<row r="' . $r . '" ht="' . $alto . '" customHeight="1">' . implode('', $porCol) . '</row>';
        $alturas[$r] = $alto;
    };
    // Número de renglones que ocupa un texto en una celda (para la altura de fila).
    $lineas = static function (string $txt, float $ancho): int {
        $txt = trim($txt);
        if ($txt === '') { return 1; }
        $porLinea = max(1, (int)floor($ancho * 1.25));
        $n = 0;
        foreach (preg_split('/\R/u', $txt) as $parte) {
            $n += max(1, (int)ceil(mb_strlen($parte, 'UTF-8') / $porLinea));
        }
        return $n;
    };

    // ---------- Imágenes institucionales (con su tamaño real) ----------
    $imgs = []; $logoParts = []; $drawingRels = [];
    $baseProyecto = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
    foreach (['coapatap', 'tapachula', 'inferior'] as $tipo) {
        $url = (string)($imagenesResguardo[$tipo] ?? '');
        if ($url === '') continue;
        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        if (!preg_match('~(/?assets/img/resguardos/historial/[a-f0-9]{32}\.(png|jpg|webp))$~i', $path, $m)) {
            logger('Logo descartado por ruta no válida: ' . $url, 'ERROR');
            continue;
        }
        $rel = ltrim($m[1], '/');
        $candidatos = [
            $baseProyecto . '/' . $rel,
            dirname(__DIR__) . '/' . $rel,
            __DIR__ . '/../' . $rel,
            ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/' . $rel,
        ];
        $physical = '';
        foreach ($candidatos as $cand) {
            if ($cand !== '' && is_file($cand)) { $physical = $cand; break; }
        }
        if ($physical === '') { logger('Logo no encontrado en disco: ' . $rel, 'ERROR'); continue; }
        $ext  = strtolower($m[2]);
        $data = @file_get_contents($physical);
        if ($data === false) continue;
        if ($ext === 'webp') {
            if (!function_exists('imagecreatefromwebp') || !function_exists('imagepng')) continue;
            $im = @imagecreatefromwebp($physical);
            if (!$im) continue;
            ob_start(); imagepng($im); $data = ob_get_clean(); imagedestroy($im);
            $ext = 'png';
        }
        $tam = @getimagesizefromstring($data);
        if (!$tam || $tam[0] < 1 || $tam[1] < 1) continue;
        $id = count($logoParts) + 1;
        $name = 'logo' . $id . '.' . $ext;
        $logoParts[] = ['name' => $name, 'ext' => $ext, 'bytes' => $data];
        $drawingRels[] = '<Relationship Id="rId' . $id . '" '
            . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" '
            . 'Target="../media/' . $name . '"/>';
        $imgs[$tipo] = ['rid' => $id, 'w' => (int)$tam[0], 'h' => (int)$tam[1]];
    }
    $drawingAnchors = []; $picId = 1;
    // Coloca una imagen dentro de una caja (px) sin deformarla, anclada a una celda.
    $ponerImagen = static function (string $tipo, int $col0, int $fila0, float $cajaW, float $cajaH,
                                    float $offX, float $offY) use (&$drawingAnchors, &$picId, $imgs): void {
        if (!isset($imgs[$tipo])) return;
        $i = $imgs[$tipo];
        $esc = min($cajaW / $i['w'], $cajaH / $i['h']);
        $w = max(1, (int)round($i['w'] * $esc));
        $h = max(1, (int)round($i['h'] * $esc));
        $offX += ($cajaW - $w) / 2;   // centrada dentro de su caja
        $offY += ($cajaH - $h) / 2;
        $drawingAnchors[] = '<xdr:oneCellAnchor>'
            . '<xdr:from><xdr:col>' . $col0 . '</xdr:col><xdr:colOff>' . (int)round($offX * 9525) . '</xdr:colOff>'
            . '<xdr:row>' . $fila0 . '</xdr:row><xdr:rowOff>' . (int)round($offY * 9525) . '</xdr:rowOff></xdr:from>'
            . '<xdr:ext cx="' . ($w * 9525) . '" cy="' . ($h * 9525) . '"/>'
            . '<xdr:pic>'
            . '<xdr:nvPicPr><xdr:cNvPr id="' . ($picId + 1) . '" name="Imagen' . $picId . '"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>'
            . '<xdr:blipFill><a:blip r:embed="rId' . $i['rid'] . '"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
            . '<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . ($w * 9525) . '" cy="' . ($h * 9525) . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr>'
            . '</xdr:pic><xdr:clientData/></xdr:oneCellAnchor>';
        $picId++;
    };
    $ptAPx = static function (float $pt): float { return $pt / 0.75; };

    // ---------- Textos fijos ----------
    $leyendaExcel = 'BIENES CON LOS QUE PARTICIPO EN MI TRABAJO, POR LO TANTO, ME '
        . 'RESPONSABILIZO A CONSERVAR, CUIDAR, VIGILAR Y RESGUARDAR LOS BIENES MUEBLES '
        . 'QUE TENGO ASIGNADOS, EXCLUSIVAMENTE PARA EL DESEMPEÑO DE MIS ACTIVIDADES, '
        . 'Y DEVOLVER EL "MOBILIARIO, EQUIPO, HERRAMIENTAS Y APARATOS" CUANDO EL '
        . 'ORGANISMO ASI ME LO REQUIERA, EN CASO DE NO HACERLO, ME COMPROMETO A PAGAR '
        . 'SU REPOSICIÓN, DAÑOS O EN SU DEFECTO EL "COMITÉ DE AGUA POTABLE Y '
        . 'ALCANTARILLADO DEL MUNICIPIO DE TAPACHULA", TOMARA LAS MEDIDAS QUE CONSIDERE '
        . 'NECESARIAS PARA REQUERIRME LO QUE EN ESTE MOMENTO RECIBO EN RESGUARDO, '
        . 'CON FUNDAMENTO EN LA (LEY FEDERAL DEL TRABAJO, LEY DEL PATRIMONIO DEL ESTADO '
        . 'DE CHIAPAS, LEY DE DESARROLLO CONSTITUCIONAL EN MATERIA DE GOBIERNO Y '
        . 'ADMINISTRACION MUNICIPAL DEL ESTADO DE CHIAPAS, CODIGO PENAL PARA EL ESTADO '
        . 'DE CHIAPAS, LEY DE RESPONSABILIDADES ADMINISTRATIVAS PARA EL ESTADO DE CHIAPAS).';

    $cabeceras = [
        'DESCRIPCIÓN', 'ECO. O INVENT.', 'MARCA', 'MODELO', 'SERIE', 'MATERIAL',
        'STATUS', 'FECHA ADQUIS.', 'NÚMERO FACTURA', 'COSTO ADQUIS.', 'OBSERVACIONES',
    ];
    $guion = static function ($v): string {
        $v = trim((string)($v ?? ''));
        return $v !== '' ? $v : '—';
    };
    $estadosPermitidos = ['BUENO', 'NUEVO', 'REGULAR', 'INSERVIBLE'];

    // ---------- Una hoja (bloque) por cada página de la vista previa ----------
    $r = 1; $saltos = []; $maxBloque = 0.0; $totalPag = count($paginas);
    foreach ($paginas as $indicePag => $paginaBienes) {
        $inicio = $r;

        // --- Encabezado: logo izquierdo | texto | logo Tapachula | nómina y fecha ---
        $filaEnc = $r;
        $fila($r, $rango($r, 12, 12, $empleado['numero_nomina'] ?? '', 'nomina'), 24); $r++;
        $c = $rango($r, 3, 9, 'COMITÉ DE AGUA POTABLE Y ALCANTARILLADO DEL MUNICIPIO DE TAPACHULA, CHIAPAS.', 'inst');
        $c += $rango($r, 12, 12, 'NÚMERO DE NÓMINA:', 'rotulo');
        $fila($r, $c, 18); $r++;
        $fila($r, $rango($r, 3, 9, 'DIRECCIÓN ADMINISTRATIVA.- DEPARTAMENTO DE RECURSOS MATERIALES', 'inst2'), 18); $r++;
        $fila($r, $rango($r, 3, 9, 'Y SERVICIOS.- OFICINA DE ACTIVO FIJO Y SERVICIOS GENERALES.', 'inst2'), 18); $r++;
        $c = $rango($r, 3, 9, 'RESGUARDO: MOBILIARIO, EQUIPO, HERRAMIENTAS Y APARATOS.', 'inst2');
        $c += $rango($r, 12, 12, 'FECHA DE EMISIÓN:', 'rotulo');
        $fila($r, $c, 18); $r++;
        $fila($r, $rango($r, 12, 12, $fechaEmision, 'fecha'), 18); $r++;
        $altoEnc = array_sum(array_slice($alturas, $filaEnc - 1, 6, true));
        $altoEncPx = $ptAPx((float)$altoEnc);
        // Logo COAPATAP: columna B. Logo Tapachula: columnas J-K.
        $ponerImagen('coapatap', 1, $filaEnc - 1, $pxCol(2) - 14, $altoEncPx - 8, 7, 4);
        $ponerImagen('tapachula', 9, $filaEnc - 1, $pxCol(10) + $pxCol(11) - 14, $altoEncPx - 24, 7, 8);

        $fila($r, [], 6); $r++;

        // --- Título y número de página ---
        $c = $rango($r, 2, 11, 'RESGUARDO TOTAL DE BIENES', 'titulo');
        $c += $rango($r, 12, 12, 'Página ' . ($indicePag + 1) . ' de ' . $totalPag, 'pagina');
        $fila($r, $c, 24); $r++;
        $fila($r, $rango($r, 9, 12, 'PERTENECE AL ACTIVO (IGUAL O MENOS A 70 UMA)', 'uma'), 16); $r++;
        $fila($r, [], 6); $r++;

        // --- Área / adscripción / resguardante ---
        $c = $rango($r, 2, 4, 'BIENES CON LOS QUE CUENTA EL:', 'etq');
        $c += $rango($r, 5, 7, $guion($empleado['area_nombre'] ?? '') === '—' ? 'SIN ÁREA' : $empleado['area_nombre'], 'val');
        $c += $rango($r, 8, 10, 'AREA DE ADSCRIPCIÓN:', 'etq');
        $c += $rango($r, 11, 12, $guion($empleado['adscripcion_nombre'] ?? '') === '—' ? 'SIN ADSCRIPCIÓN' : $empleado['adscripcion_nombre'], 'val');
        $areaTxt = (string)($empleado['area_nombre'] ?? '');
        $adsTxt  = (string)($empleado['adscripcion_nombre'] ?? '');
        $altoArea = max(
            $lineas($areaTxt, $anchoCol(5) + $anchoCol(6) + $anchoCol(7)),
            $lineas($adsTxt, $anchoCol(11) + $anchoCol(12))
        ) * 13 + 8;
        $fila($r, $c, max(22, $altoArea)); $r++;
        $c = $rango($r, 2, 3, 'RESGUARDANTE:', 'etq');
        $c += $rango($r, 4, 12, $empleado['nombre'] ?? '', 'etq');
        $fila($r, $c, 18); $r++;
        $fila($r, [], 6); $r++;

        // --- Cabecera de tabla ---
        $c = [];
        foreach ($cabeceras as $i => $v) { $c += $rango($r, $i + 2, $i + 2, $v, 'th'); }
        $fila($r, $c, 30); $r++;

        // --- Bienes de la página ---
        foreach ($paginaBienes as $bien) {
            $estado = strtoupper(trim((string)($bien['estado_fisico'] ?? '')));
            $valores = [
                $bien['descripcion'] ?? '',
                $bien['num_inventario'] ?? '',
                $guion($bien['marca'] ?? ''),
                $guion($bien['modelo'] ?? ''),
                $guion($bien['serie'] ?? ''),
                $guion($bien['material'] ?? ''),
                in_array($estado, $estadosPermitidos, true) ? $estado : '—',
                resguardoFecha($bien['fecha_adq'] ?? null),
                $guion($bien['factura'] ?? ''),
                $bien['costo'] ?? null,
                $guion($bien['observaciones'] ?? ''),
            ];
            $c = []; $maxLin = 1;
            foreach ($valores as $i => $v) {
                $esCosto = ($i === 9);
                $esNum = $esCosto && $v !== null && is_numeric($v);
                if ($esCosto && !$esNum) { $v = '—'; }
                $est = $i === 0 ? 'tdL' : ($esNum ? 'tdM' : 'tdC');
                $c += $rango($r, $i + 2, $i + 2, $v, $est, $esNum);
                if (!$esNum) { $maxLin = max($maxLin, $lineas((string)$v, $anchoCol($i + 2) - 1)); }
            }
            $fila($r, $c, max(24.0, $maxLin * 11.0 + 6));
            $r++;
        }

        // --- Leyenda legal ---
        $fila($r, [], 6); $r++;
        $fila($r, $rango($r, 2, 12, $leyendaExcel, 'ley'), 48); $r++;
        $fila($r, [], 6); $r++;

        // --- Firmas: dejar la columna E libre para separar las líneas ---
        $c = $rango($r, 2, 4, 'ENTREGÓ', 'fTit');
        $c += $rango($r, 6, 8, 'RECIBÍ (RESGUARDANTE)', 'fTit');
        $c += $rango($r, 10, 12, 'AUTORIZÓ', 'fTit');
        $fila($r, $c, 16); $r++;
        $fila($r, [], 34); $r++;                         // espacio para firmar
        $c = $rango($r, 2, 4, $nombreEntrega, 'fNom');
        $c += $rango($r, 6, 8, $empleado['nombre'] ?? '', 'fNom');
        $c += $rango($r, 10, 12, $nombreAutoriza, 'fNom');
        $fila($r, $c, 20); $r++;
        $c = $rango($r, 2, 4, 'JEFE DE OFICINA DE ACTIVO FIJO', 'fCargo');
        $c += $rango($r, 6, 8, $empleado['cargo'] ?? '', 'fCargo');
        $c += $rango($r, 10, 12, $cargoAutoriza, 'fCargo');
        $fila($r, $c, 16); $r++;

        // --- Imagen inferior; no mostrar total registrado en Excel ---
        $filaPie = $r;
        $fila($r, [], 20); $r++;
        $fila($r, [], 17); $r++;
        $fila($r, [], 17); $r++;
        $anchoEH = 0.0; for ($k = 6; $k <= 8; $k++) { $anchoEH += $pxCol($k); }
        $ponerImagen('inferior', 5, $filaPie - 1, min($anchoEH - 10, 162), 52, 5 + max(0, ($anchoEH - 10 - 162) / 2), 4);

        $fin = $r - 1;
        $maxBloque = max($maxBloque, (float)array_sum(array_slice($alturas, $inicio - 1, $fin - $inicio + 1, true)));
        if ($indicePag < $totalPag - 1) { $saltos[] = $fin; }
    }
    $ultimaFila = $r - 1;
    ksort($filas);

    // ---------- Escala de impresión: cada página cabe completa en A4 horizontal ----------
    $anchoTotalPt = 0.0; foreach ($cols as $w) { $anchoTotalPt += ($w * 7 + 5) * 0.75; }
    $utilAncho = 841.9 - 2 * 0.25 * 72;     // márgenes izq/der 0.25"
    $utilAlto  = 595.3 - 2 * 0.35 * 72;     // márgenes sup/inf 0.35"
    $escala = (int)floor(min($utilAncho / $anchoTotalPt, $utilAlto / max(1.0, $maxBloque)) * 100);
    $escala = max(30, min(100, $escala - 1));

    $colXml = '';
    foreach ($cols as $i => $width) {
        $colXml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $width . '" customWidth="1"/>';
    }
    $brk = '';
    foreach ($saltos as $s) { $brk .= '<brk id="' . $s . '" max="16383" man="1"/>'; }

    // ---------- sheet1.xml ----------
    $hoja = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetViews><sheetView workbookViewId="0" showGridLines="0" zoomScale="85" zoomScaleNormal="85"/></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>'
        . '<cols>' . $colXml . '</cols>'
        . '<sheetData>' . implode('', $filas) . '</sheetData>'
        . '<mergeCells count="' . count($combinadas) . '">' . implode('', $combinadas) . '</mergeCells>'
        . '<printOptions horizontalCentered="1"/>'
        . '<pageMargins left="0.25" right="0.25" top="0.35" bottom="0.35" header="0.1" footer="0.1"/>'
        . '<pageSetup paperSize="9" scale="' . $escala . '" orientation="landscape"/>'
        . ($saltos ? '<rowBreaks count="' . count($saltos) . '" manualBreakCount="' . count($saltos) . '">' . $brk . '</rowBreaks>' : '')
        . ($drawingAnchors ? '<drawing r:id="rId1"/>' : '')
        . '</worksheet>';

    // ---------- styles.xml ----------
    $xfs = '';
    foreach ($styleDefs as $d) {
        [$font, $fill, $border, $h, $v, $wrap, $num] = $d;
        $xfs .= '<xf numFmtId="' . $num . '" fontId="' . $font . '" fillId="' . $fill
            . '" borderId="' . $border . '" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"'
            . ($num ? ' applyNumberFormat="1"' : '') . '>'
            . '<alignment horizontal="' . $h . '" vertical="' . $v . '"' . ($wrap ? ' wrapText="1"' : '') . '/>'
            . '</xf>';
    }
    $f = static fn(string $extra, string $sz, string $color = '') =>
        '<font>' . $extra . '<sz val="' . $sz . '"/>' . ($color ? '<color rgb="' . $color . '"/>' : '') . '<name val="Arial"/></font>';
    $estilos = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="&quot;$&quot;#,##0.00"/></numFmts>'
        . '<fonts count="12">'
        . $f('', '10')                              // 0 normal
        . $f('<b/>', '10', 'FF14376D')              // 1 azul bold
        . $f('', '10', 'FF14376D')                  // 2 azul normal
        . $f('<b/>', '14', 'FF14376D')              // 3 azul bold 14 (título)
        . $f('<b/>', '16', 'FFC00000')              // 4 rojo bold 16 (nómina)
        . $f('<b/>', '10')                          // 5 bold
        . $f('<b/>', '9', 'FFFFFFFF')               // 6 blanco bold 9 (cabecera)
        . $f('', '8')                               // 7 datos 8
        . $f('', '7.5')                             // 8 leyenda
        . $f('<b/>', '9')                           // 9 bold 9
        . $f('<b/>', '9', 'FFC00000')               // 10 rojo bold 9 (UMA)
        . $f('<b/>', '8')                           // 11 bold 8
        . '</fonts>'
        . '<fills count="3">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF8A190F"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="3">'
        . '<border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"><color rgb="FF595959"/></left><right style="thin"><color rgb="FF595959"/></right>'
        . '<top style="thin"><color rgb="FF595959"/></top><bottom style="thin"><color rgb="FF595959"/></bottom><diagonal/></border>'
        . '<border><left/><right/><top style="thin"><color rgb="FF000000"/></top><bottom/><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="' . count($styleDefs) . '">' . $xfs . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    // ---------- Empaquetar XLSX ----------
    $temp = tempnam(sys_get_temp_dir(), 'coapatap_xlsx_');
    if ($temp === false) { http_response_code(500); exit('No se pudo crear el archivo temporal.'); }
    $zip = new ZipArchive();
    if ($zip->open($temp, ZipArchive::OVERWRITE) !== true) {
        @unlink($temp); http_response_code(500); exit('No se pudo preparar el Excel.');
    }
    $contentTypes = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . ($drawingAnchors ? '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>' : '');
    foreach (array_unique(array_column($logoParts, 'ext')) as $ext) {
        $contentTypes .= '<Default Extension="' . $ext . '" ContentType="image/' . ($ext === 'jpg' ? 'jpeg' : $ext) . '"/>';
    }
    $contentTypes .= '</Types>';

    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Resguardo" sheetId="1" r:id="rId1"/></sheets>'
        . '<definedNames><definedName name="_xlnm.Print_Area" localSheetId="0">Resguardo!$A$1:$M$' . $ultimaFila . '</definedName></definedNames>'
        . '</workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $hoja);
    $zip->addFromString('xl/styles.xml', $estilos);
    if ($drawingAnchors) {
        $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/drawings/drawing1.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" '
            . 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . implode('', $drawingAnchors) . '</xdr:wsDr>');
        $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . implode('', $drawingRels) . '</Relationships>');
        foreach ($logoParts as $p) {
            $zip->addFromString('xl/media/' . $p['name'], $p['bytes']);
        }
    }
    $zip->close();

    $filename = 'COAPATAP_Resguardo_Nomina_'
        . preg_replace('/[^0-9]/', '', (string)($empleado['numero_nomina'] ?? ''))
        . '_' . date('Ymd') . '.xlsx';

    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($temp));
    header('Cache-Control: private, no-store, max-age=0');
    readfile($temp);
    @unlink($temp);
    exit;
}

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

$page_css = 'impresion.css';

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
        <div class="imp-encabezado-modulo__titulo">
            <!-- <span class="imp-encabezado-modulo__etiqueta">COAPATAP · Control de activos</span> -->
            <h1><span class="imp-encabezado-modulo__icono" aria-hidden="true">
                <img src="<?= e($assetsBase) ?>img/icons/imprimir.png" 
                class="imp-encabezado-modulo__imagen"
                alt="">
            </span> Impresión de resguardos</h1>
            <!-- <p class="imp-descripcion">Selecciona la adscripción, el área y el resguardante para generar el documento.</p> -->
        </div>

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
                ⚙️ Configurar de logos
            </a>

        <?php endif; ?>

    </div>

    <ol class="imp-pasos" aria-label="Etapas de impresión">
        <li class="imp-pasos__activo"><span>01</span> Elegir resguardante</li>
        <li><span>02</span> Revisar datos y firmantes</li>
        <li><span>03</span> Imprimir resguardo</li>
    </ol>


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

        <label class="imp-filtro-card imp-filtro-card--adscripcion">
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

        <label class="imp-filtro-card imp-filtro-card--area">
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

        <label class="imp-filtro-card imp-filtro-card--nomina">
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

        <div class="imp-accion-card">
            <span class="imp-accion-card__icono" aria-hidden="true">⌕</span>
            <button type="submit" class="imp-btn imp-btn-primario">
                Consultar resguardo
            </button>
        </div>

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
                    Autorizó — Seleccionar empleado de la adscripción
                </label>
                <select id="impBuscarAutoriza" name="nomina_autoriza"
                        class="form-control">
                    <option value="">Seleccionar quién autoriza</option>
                    <?php foreach ($empleados as $persona): ?>
                    <option
                        value="<?= resguardoEsc($persona['numero_nomina']) ?>"
                        data-adscripcion="<?= (int)($persona['adscripcion_id'] ?? 0) ?>"
                        data-nombre="<?= resguardoEsc($persona['nombre']) ?>"
                        data-cargo="<?= resguardoEsc($persona['cargo'] ?? '') ?>"
                        <?= $nominaAutoriza !== '' && $nominaAutoriza === (string)$persona['numero_nomina'] ? 'selected' : '' ?>
                    ><?= resguardoEsc($persona['numero_nomina'] . ' — ' . $persona['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" id="impNombreAutoriza" name="nombre_autoriza"
                       value="<?= resguardoEsc($nombreAutoriza) ?>">
                <small class="imp-firmante-ayuda">
                    El nombre y cargo se toman del empleado seleccionado.
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

                <a
                    class="imp-btn imp-btn-primario"
                    id="impDescargarExcel"
                    href="<?= resguardoEsc('vista_previa_resguardo.php?' . http_build_query(array_merge($_GET, ['exportar' => 'excel']))) ?>"
                    style="text-decoration:none; display:inline-flex; align-items:center; justify-content:center;"
                >
                    Descargar Excel (.xlsx)
                </a>

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

                        <small data-cargo-autoriza>
                            <?= resguardoEsc($cargoAutoriza) ?>
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

        const empleadoAutoriza = null;

        const preferencias = {

            entrega: {
                texto: buscarEntrega.value.trim(),
                nomina: empleadoEntrega
                    ? empleadoEntrega.nomina
                    : null
            },

            autoriza: null

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

        // La autoridad no se restaura desde otra adscripción.
        buscarAutoriza.value = <?= json_encode($nominaAutoriza) ?>;
    }

    // ======================================
    // ACTUALIZAR FIRMAS
    // ======================================

    function actualizarFirmas() {

        const entrega =
            obtenerNombre(buscarEntrega.value);

        const seleccion = buscarAutoriza.selectedOptions[0];
        const autoriza = seleccion && seleccion.value
            ? (seleccion.dataset.nombre || '') : '';
        const cargo = seleccion && seleccion.value
            ? (seleccion.dataset.cargo || '') : '';

        nombreEntrega.value = entrega;
        nombreAutoriza.value = autoriza;
        document.querySelectorAll('[data-cargo-autoriza]').forEach(function (elemento) {
            elemento.textContent = cargo || '\\u00A0';
        });
        const enlaceExcel = document.getElementById('impDescargarExcel');
        if (enlaceExcel) {
            const url = new URL(enlaceExcel.href);
            if (seleccion && seleccion.value) {
                url.searchParams.set('nomina_autoriza', seleccion.value);
            } else {
                url.searchParams.delete('nomina_autoriza');
            }
            enlaceExcel.href = url.toString();
        }

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

    // Sincronizar el autorizante con la adscripción elegida en el filtro,
    // incluso antes de consultar el resguardo.
    const filtroAdscripcion = document.getElementById('impAdscripcion');
    const filtroArea = document.getElementById('impArea');
    const filtroNomina = document.getElementById('impNomina');

    function actualizarAutorizantes() {
        let adscripcionActual = filtroAdscripcion ? filtroAdscripcion.value : '';
        if (!adscripcionActual && filtroArea && filtroArea.selectedOptions[0]) {
            adscripcionActual = filtroArea.selectedOptions[0].dataset.adscripcion || '';
        }
        if (!adscripcionActual && filtroNomina && filtroNomina.selectedOptions[0]) {
            adscripcionActual = filtroNomina.selectedOptions[0].dataset.adscripcion || '';
        }
        // Si no se ha elegido una adscripción, no se muestran autorizantes.
        Array.from(buscarAutoriza.options).forEach(function (opcion) {
            if (!opcion.value) return;
            const visible = Boolean(adscripcionActual) &&
                opcion.dataset.adscripcion === adscripcionActual;
            opcion.hidden = !visible;
            opcion.disabled = !visible;
        });
        if (buscarAutoriza.selectedOptions[0]?.disabled) {
            buscarAutoriza.value = '';
        }
        actualizarFirmas();
    }

    if (filtroAdscripcion) {
        filtroAdscripcion.addEventListener('change', actualizarAutorizantes);
    }
    if (filtroArea) {
        filtroArea.addEventListener('change', actualizarAutorizantes);
    }
    if (filtroNomina) {
        filtroNomina.addEventListener('change', actualizarAutorizantes);
    }

    // ======================================
    // INICIAR
    // ======================================

    restaurarPreferencias();
    actualizarAutorizantes();

})();
</script>

<?php

// ==========================================
// 15. PIE DE PÁGINA DEL SISTEMA
// ==========================================

require_once __DIR__ . '/../includes/footer.php';

?>
