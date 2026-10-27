
<?php
/**
 * COAPATAP - Importación de activos desde Excel (.xlsx)
 *
 * 1. Vista previa del Excel.
 * 2. Alta confirmada de empleados, áreas y adscripciones.
 * 3. Importación confirmada de activos.
 *
 * Requiere PHP 8+, ZIP, DOM/XML y
 * activos.clave_importacion con índice único.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/importacion_celdas.php';

$usuario = require_super();
$page_title = 'Importar activos desde Excel';

// ==========================================
// FUNCIONES GENERALES
// ==========================================

function impE(mixed $v): string
{
    return htmlspecialchars(
        (string) ($v ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function impN(mixed $v): string
{
    $s = strtr(trim((string) ($v ?? '')), [
        'á' => 'a', 'é' => 'e', 'í' => 'i',
        'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
        'ñ' => 'n',
        'Á' => 'A', 'É' => 'E', 'Í' => 'I',
        'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U',
        'Ñ' => 'N'
    ]);

    return trim((string) preg_replace(
        '/[^A-Z0-9]+/',
        ' ',
        strtoupper($s)
    ));
}

// ==========================================
// LEER XML DEL ARCHIVO XLSX
// ==========================================

function impDocumento(string $xml): DOMDocument
{
    if (strlen($xml) > 30000000) {
        throw new RuntimeException(
            'Una sección del Excel es demasiado grande.'
        );
    }

    $d = new DOMDocument();

    $prev = libxml_use_internal_errors(true);

    try {
        if (!$d->loadXML(
            $xml,
            LIBXML_NONET | LIBXML_COMPACT
        )) {
            throw new RuntimeException(
                'El XLSX contiene XML inválido.'
            );
        }
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
    }

    return $d;
}

function impZip(
    ZipArchive $zip,
    string $ruta
): string {
    $stat = $zip->statName($ruta);

    if (
        !$stat ||
        ($stat['size'] ?? 0) > 30000000
    ) {
        throw new RuntimeException(
            'Falta una sección del Excel '
            . 'o es demasiado grande: ' . $ruta
        );
    }

    $s = $zip->getFromName($ruta);

    if ($s === false) {
        throw new RuntimeException(
            'No se pudo leer: ' . $ruta
        );
    }

    return $s;
}

// ==========================================
// IDENTIFICAR HOJAS DEL LIBRO
// ==========================================

function impHojasLibro(ZipArchive $zip): array
{
    $w = new DOMXPath(
        impDocumento(
            impZip($zip, 'xl/workbook.xml')
        )
    );

    $r = new DOMXPath(
        impDocumento(
            impZip(
                $zip,
                'xl/_rels/workbook.xml.rels'
            )
        )
    );

    $rutas = [];

    foreach (
        $r->query(
            '//*[local-name()="Relationship"]'
        ) ?: [] as $n
    ) {
        if (
            !$n instanceof DOMElement ||
            !str_ends_with(
                $n->getAttribute('Type'),
                '/worksheet'
            )
        ) {
            continue;
        }

        $p = ltrim(
            str_replace(
                '\\',
                '/',
                $n->getAttribute('Target')
            ),
            '/'
        );

        if (!str_starts_with($p, 'xl/')) {
            $p = 'xl/' . $p;
        }

        if (preg_match(
            '~^xl/worksheets/[A-Za-z0-9_.-]+\.xml$~',
            $p
        )) {
            $rutas[
                $n->getAttribute('Id')
            ] = $p;
        }
    }

    $hojas = [];

    foreach (
        $w->query(
            '//*[local-name()="sheet"]'
        ) ?: [] as $n
    ) {
        if (!$n instanceof DOMElement) {
            continue;
        }

        $id = $n->getAttributeNS(
            'http://schemas.openxmlformats.org/'
            . 'officeDocument/2006/relationships',
            'id'
        );

        if (isset($rutas[$id])) {
            $hojas[
                $n->getAttribute('name')
            ] = $rutas[$id];
        }
    }

    return $hojas;
}

// ==========================================
// LEER TEXTOS COMPARTIDOS
// ==========================================

function impCompartidas(ZipArchive $zip): array
{
    if (
        $zip->locateName(
            'xl/sharedStrings.xml'
        ) === false
    ) {
        return [];
    }

    $xp = new DOMXPath(
        impDocumento(
            impZip(
                $zip,
                'xl/sharedStrings.xml'
            )
        )
    );

    $textos = [];

    foreach (
        $xp->query(
            '//*[local-name()="si"]'
        ) ?: [] as $si
    ) {
        $s = '';

        foreach (
            $xp->query(
                './/*[local-name()="t"]',
                $si
            ) ?: [] as $n
        ) {
            $s .= $n->textContent;
        }

        $textos[] = $s;
    }

    return $textos;
}

// ==========================================
// LEER FILAS Y CELDAS
// ==========================================

function impFilasHoja(
    ZipArchive $zip,
    string $ruta,
    array $textos
): array {
    $xp = new DOMXPath(
        impDocumento(
            impZip($zip, $ruta)
        )
    );

    $filas = [];
    $contador = 0;

    $consulta =
        '//*[local-name()="sheetData"]'
        . '/*[local-name()="row"]';

    foreach (
        $xp->query($consulta) ?: [] as $r
    ) {
        if (!$r instanceof DOMElement) {
            continue;
        }

        $num = (int) $r->getAttribute('r');

        if ($num < 1 || $num > 20000) {
            continue;
        }

        $fila = [];

        foreach (
            $xp->query(
                './*[local-name()="c"]',
                $r
            ) ?: [] as $c
        ) {
            if (!$c instanceof DOMElement) {
                continue;
            }

            if (++$contador > 250000) {
                throw new RuntimeException(
                    'El Excel tiene demasiadas celdas.'
                );
            }

            if (!preg_match(
                '/^([A-Z]{1,3})\d+$/',
                $c->getAttribute('r'),
                $m
            )) {
                continue;
            }

            $tipo = $c->getAttribute('t');
            $s = '';

            if ($tipo === 'inlineStr') {
                foreach (
                    $xp->query(
                        './*[local-name()="is"]'
                        . '//*[local-name()="t"]',
                        $c
                    ) ?: [] as $n
                ) {
                    $s .= $n->textContent;
                }
            } else {
                $v = $xp->query(
                    './*[local-name()="v"]',
                    $c
                );

                if ($v && $v->length) {
                    $s = (string)
                        $v->item(0)->textContent;
                }

                if ($tipo === 's') {
                    $s = $textos[(int) $s] ?? '';
                }
            }

            $fila[$m[1]] = trim($s);
        }

        $filas[$num] = $fila;
    }

    ksort($filas, SORT_NUMERIC);

    return $filas;
}

// ==========================================
// RECONOCER ENCABEZADOS DE ACTIVOS
// ==========================================

function impCampo(string $s): ?string
{
    $t = impN($s);

    if (str_contains($t, 'DESCRIPCION')) {
        return 'descripcion';
    }

    if (
        str_contains($t, 'INVENT') &&
        !str_contains($t, 'NOMINA')
    ) {
        return 'inventario';
    }

    if ($t === 'MARCA') {
        return 'marca';
    }

    if (str_contains($t, 'MODELO')) {
        return 'modelo';
    }

    if (str_contains($t, 'SERIE')) {
        return 'serie';
    }

    if (str_contains($t, 'MATERIAL')) {
        return 'material';
    }

    if (
        str_contains($t, 'STATUS') ||
        $t === 'ESTADO FISICO' ||
        $t === 'ESTADO' ||
        $t === 'ESTATUS'
    ) {
        return 'estado';
    }

    if (
        str_contains($t, 'FECHA') &&
        (
            str_contains($t, 'ADQUIS') ||
            str_contains($t, 'ADQ')
        )
    ) {
        return 'fecha';
    }

    if (str_contains($t, 'FACTURA')) {
        return 'factura';
    }

    if (str_contains($t, 'COSTO')) {
        return 'costo';
    }

    if (str_contains($t, 'OBSERV')) {
        return 'observaciones';
    }

    return null;
}

function impCabecera(
    array $fila,
    array $siguiente
): ?array {
    $num = 0;

    foreach ($fila as $txt) {
        if (impCampo((string) $txt) !== null) {
            $num++;
        }
    }

    if ($num < 2) {
        return null;
    }

    $mapa = [];

    foreach ([$fila, $siguiente] as $f) {
        foreach ($f as $col => $txt) {
            $campo = impCampo((string) $txt);

            if (
                $campo !== null &&
                !isset($mapa[$campo])
            ) {
                $mapa[$campo] = $col;
            }
        }
    }

    return (
        isset(
            $mapa['descripcion'],
            $mapa['inventario'],
            $mapa['costo']
        ) &&
        count($mapa) >= 7
    ) ? $mapa : null;
}

// ==========================================
// IDENTIFICAR ÁREA Y ADSCRIPCIÓN
// ==========================================

function impDerecha(
    array $fila,
    string $col
): string {
    $cols = array_keys($fila);

    $i = array_search(
        $col,
        $cols,
        true
    );

    if ($i === false) {
        return '';
    }

    for (
        $j = $i + 1;
        $j < count($cols);
        $j++
    ) {
        $v = trim(
            (string) $fila[$cols[$j]]
        );

        if ($v === '') {
            continue;
        }

        if (str_contains(
            impN($v),
            'AREA DE ADSCRIPCION'
        )) {
            return '';
        }

        return $v;
    }

    return '';
}

// ==========================================
// IDENTIFICAR NÚMERO DE NÓMINA
// ==========================================

function impNominaFila(
    array $filas,
    int $i
): ?string {
    foreach (
        $filas[$i] ?? [] as $col => $txt
    ) {
        if (!str_contains(
            impN($txt),
            'NUMERO DE NOMINA'
        )) {
            continue;
        }

        foreach (
            [$i - 1, $i + 1, $i - 2, $i + 2]
            as $j
        ) {
            $s = trim(
                (string) (
                    $filas[$j][$col] ?? ''
                )
            );

            if (preg_match(
                '/^[1-9][0-9]{0,8}$/',
                $s
            )) {
                return $s;
            }
        }
    }

    return null;
}

// ==========================================
// CONVERTIR ESTADO FÍSICO
// ==========================================

function impEstado(string $s): ?string
{
    return match (impN($s)) {
        'B', 'BUENO', 'BUENOS' => 'BUENO',
        'N', 'NUEVO', 'NUEVOS' => 'NUEVO',
        'R', 'REGULAR' => 'REGULAR',
        'I', 'INSERVIBLE', 'INSERVIBLES' => 'INSERVIBLE',
        default => null
    };
}

// ==========================================
// CONVERTIR COSTO
// ==========================================

function impCosto(string $s): ?string
{
    $v = preg_replace(
        '/[\s$,]/u',
        '',
        trim($s)
    );

    if (
        $v === null ||
        $v === '' ||
        !is_numeric($v) ||
        (float) $v < 0 ||
        (float) $v > 9999999999.99
    ) {
        return null;
    }

    return number_format(
        (float) $v,
        2,
        '.',
        ''
    );
}

// ==========================================
// CONVERTIR FECHA
// ==========================================

function impFecha(string $s): ?string
{
    $s = trim($s);

    if (
        $s === '' ||
        impN($s) === 'S F'
    ) {
        return null;
    }

    // Fechas numéricas de Excel.

    if (
        is_numeric($s) &&
        (float) $s >= 1 &&
        (float) $s < 100000
    ) {
        return (
            new DateTimeImmutable(
                '1899-12-30',
                new DateTimeZone('UTC')
            )
        )->modify(
            '+' . (int) floor((float) $s)
            . ' days'
        )->format('Y-m-d');
    }

    // Fechas escritas como texto.

    foreach (
        ['!d/m/Y', '!Y-m-d'] as $fmt
    ) {
        $d = DateTimeImmutable::createFromFormat(
            $fmt,
            $s
        );

        if (
            $d &&
            $d->format(substr($fmt, 1)) === $s
        ) {
            return $d->format('Y-m-d');
        }
    }

    return 'INVALIDA: ' . $s;
}

// ==========================================
// EXTRAER NOMBRE Y CARGO DEL RESGUARDANTE
// ==========================================

function impFirma(
    array $filas,
    int $fila,
    string $col
): array {
    $datos = [];

    for (
        $j = $fila + 1;
        $j <= $fila + 10;
        $j++
    ) {
        $s = trim(
            (string) (
                $filas[$j][$col] ?? ''
            )
        );

        if (
            $s === '' ||
            preg_match('/^[-_.\s]+$/', $s)
        ) {
            continue;
        }

        $t = impN($s);

        if (
            str_contains($t, 'COMITE DE AGUA') ||
            str_contains($t, 'DESCRIPCION') ||
            str_contains($t, 'AUTORIZO')
        ) {
            break;
        }

        $datos[] = $s;

        if (count($datos) === 2) {
            break;
        }
    }

    return [
        $datos[0] ?? '',
        $datos[1] ?? ''
    ];
}

// ==========================================
// ANALIZAR TABLAS Y ACTIVOS
// ==========================================

function impAnalizar(array $filas, array $fusiones = []): array
{
    $bienes = [];
    $bloques = [];

    $nomina = '';
    $area = '';
    $adscripcion = '';

    $mapa = null;
    $n = 0;

    $keys = array_keys($filas);
    $max = $keys ? max($keys) : 0;

    for ($i = 1; $i <= $max; $i++) {

        $fila = $filas[$i] ?? [];

        if (!$fila) {
            continue;
        }

        // Detectar inicio de otro resguardo.

        foreach ($fila as $txt) {
            if (str_contains(
                impN($txt),
                'COMITE DE AGUA POTABLE'
            )) {
                $nomina = '';
                $area = '';
                $adscripcion = '';
                $mapa = null;
                break;
            }
        }

        // Cuando empieza un resguardo con etiqueta de nómina,
        // nunca reutilizar la nómina del resguardo anterior.
        // Un resultado de fórmula = 0 NO es una nómina válida.
        foreach ($fila as $txtNomina) {
            if (str_contains(impN($txtNomina), 'NUMERO DE NOMINA')) {
                $nomina = '';
                break;
            }
        }

        // Detectar nómina.
        $encontrada = impNominaFila(
            $filas,
            $i
        );

        if ($encontrada !== null) {
            $nomina = $encontrada;
        }

        // Detectar datos institucionales.

        foreach ($fila as $col => $txt) {

            $t = impN($txt);

            if (str_contains(
                $t,
                'BIENES CON LOS QUE CUENTA EL'
            )) {
                $area = impDerecha(
                    $fila,
                    $col
                );
            }

            if (str_contains(
                $t,
                'AREA DE ADSCRIPCION'
            )) {
                $adscripcion = impDerecha(
                    $fila,
                    $col
                );
            }

            // Firma del resguardante.

            if (
                str_starts_with($t, 'RECIBI') &&
                str_contains($t, 'RESGUARDANTE')
            ) {
                [$nombre, $cargo] = impFirma(
                    $filas,
                    $i,
                    $col
                );

                if ($n && isset($bloques[$n])) {
                    $bloques[$n]['nombre'] =
                        $nombre;

                    $bloques[$n]['cargo'] =
                        $cargo;
                }

                $mapa = null;
            }

            // Final de la tabla.

            if (
                str_contains(
                    $t,
                    'BIENES CON LOS QUE PARTICIPO'
                ) ||
                $t === 'ENTREGO' ||
                $t === 'AUTORIZO'
            ) {
                $mapa = null;
            }
        }

        // Identificar encabezados.

        $cab = impCabecera(
            $fila,
            $filas[$i + 1] ?? []
        );

        if ($cab !== null) {

            $mapa = $cab;
            $n++;

            $bloques[$n] = [
                'fila' => $i,
                'nomina' => $nomina,
                'nombre' => '',
                'cargo' => '',
                'area' => $area,
                'adscripcion' => $adscripcion,
                'cantidad' => 0
            ];

            continue;
        }

        if ($mapa === null) {
            continue;
        }

        // Obtener datos por encabezado,
        // sin depender de columnas fijas.

        $valor = static function (string $campo) use (
            $filas, $mapa, $fusiones, $i
        ): string {
            return impValorConFusion(
                $filas, $mapa, $fusiones, $i, $campo
            );
        };

        // Detectar importes globales que abarcan VARIOS bienes.
        // Nunca copiar o repartir automáticamente el monto.
        $costoGrupo = impDatosCostoCompartido(
            $filas, $mapa, $fusiones, $i
        );
        $fechaOriginal = $valor('fecha');
        $costoOriginal = $valor('costo');

        $desc = $valor('descripcion');

        if ($desc === '') {
            continue;
        }

        $t = impN($desc);

        if (
            str_contains(
                $t,
                'BIENES CON LOS QUE PARTICIPO'
            ) ||
            $t === 'ENTREGO'
        ) {
            $mapa = null;
            continue;
        }

        // Los encabezados suelen estar combinados verticalmente
        // con la fila siguiente. No confundirla con un bien ni
        // cerrar el bloque: la tabla real empieza después.
        if (str_contains($t, 'DESCRIPCION')) {
            continue;
        }

        // Evitar texto suelto.

        if (
            $valor('inventario') === '' &&
            $valor('marca') === '' &&
            $valor('modelo') === '' &&
            $valor('serie') === '' &&
            $valor('costo') === ''
        ) {
            continue;
        }

        $bienes[] = [
            'bloque' => $n,
            'fila' => $i,
            'nomina' => $nomina,
            'area' => $area,
            'adscripcion' => $adscripcion,

            'descripcion' => $desc,
            'inventario' => $valor('inventario'),
            'marca' => $valor('marca'),
            'modelo' => $valor('modelo'),
            'serie' => $valor('serie'),
            'material' => $valor('material'),

            'estado_original' => $valor('estado'),
            'estado' => impEstado(
                $valor('estado')
            ),

            'fecha_original' => $fechaOriginal,
            'fecha' => impFecha($fechaOriginal),

            'factura' => $valor('factura'),

            'costo_original' => $costoOriginal,
            // Un costo de grupo no es el costo individual del bien.
            'costo' => $costoGrupo === null
                ? impCosto($costoOriginal) : null,
            'costo_compartido' => $costoGrupo['rango'] ?? null,
            'costo_total_grupo' => $costoGrupo['valor_total'] ?? null,

            'observaciones' => $valor(
                'observaciones'
            )
        ];

        $bloques[$n]['cantidad']++;

        if (count($bienes) > 5000) {
            throw new RuntimeException(
                'Demasiados bienes. Divide el Excel '
                . 'en lotes más pequeños.'
            );
        }
    }

    // Agregar nombre y cargo a cada bien.

    foreach ($bienes as &$b) {

        $bloque = $bloques[$b['bloque']];

        $b['nombre'] = $bloque['nombre'];
        $b['cargo'] = $bloque['cargo'];
        $b['clave_responsable'] = impClaveResponsable($b);
    }

    unset($b);

    return [
        'bienes' => $bienes,
        'bloques' => $bloques
    ];
}

/**
 * Identificador provisional de resguardante dentro de una vista previa.
 * La nómina siempre gana cuando existe. Sin nómina, se agrupa por
 * firma, área y adscripción, pero NUNCA se identifica automáticamente
 * con un empleado de PostgreSQL sin la confirmación del superusuario.
 */
function impClaveResponsable(array $b): string
{
    $nomina = trim((string) ($b['nomina'] ?? ''));
    if (preg_match('/^[1-9][0-9]{0,8}$/', $nomina)) {
        return 'N' . $nomina;
    }
    $nombre = impN($b['nombre'] ?? '');
    $area = impN($b['area'] ?? '');
    $ads = impN($b['adscripcion'] ?? '');
    if ($nombre === '') {
        // No agrupar resguardos sin un nombre legible.
        return 'B' . (int) ($b['bloque'] ?? 0);
    }
    return 'S' . substr(hash('sha256', $nombre . '|' . $area . '|' . $ads), 0, 32);
}

// ==========================================
// AGRUPAR EMPLEADOS POR NÓMINA
// ==========================================

function impEmpleadosCandidatos(array $bloques): array
{
    $grupos = [];
    foreach ($bloques as $b) {
        $clave = impClaveResponsable($b);
        if (!isset($grupos[$clave])) {
            $grupos[$clave] = [
                'clave' => $clave,
                'nomina' => (string) $b['nomina'],
                'nombre' => (string) $b['nombre'],
                'cargo' => (string) $b['cargo'],
                'area' => (string) $b['area'],
                'adscripcion' => (string) $b['adscripcion'],
                'bienes' => 0,
                'problemas' => [],
            ];
        }
        $grupos[$clave]['bienes'] += (int) $b['cantidad'];
        foreach (['nombre', 'cargo', 'area', 'adscripcion'] as $campo) {
            if ($grupos[$clave][$campo] === '' && $b[$campo] !== '') {
                $grupos[$clave][$campo] = $b[$campo];
            } elseif ($b[$campo] !== '' && $grupos[$clave][$campo] !== '' &&
                impN($b[$campo]) !== impN($grupos[$clave][$campo])) {
                $grupos[$clave]['problemas'][] = 'Valores distintos para ' . $campo;
            }
        }
    }
    return $grupos;
}

// ==========================================
// SELECCIONAR LA HOJA CORRECTA
// ==========================================

function impSeleccionarHoja(
    string $archivo,
    string $solicitada
): array {
    $zip = new ZipArchive();

    if ($zip->open($archivo) !== true) {
        throw new RuntimeException(
            'El archivo no es XLSX válido.'
        );
    }

    try {
        $hojas = impHojasLibro($zip);

        if (!$hojas || count($hojas) > 80) {
            throw new RuntimeException(
                'No se encontraron hojas válidas.'
            );
        }

        if (
            $solicitada !== '' &&
            !isset($hojas[$solicitada])
        ) {
            throw new RuntimeException(
                'La hoja solicitada no existe: '
                . $solicitada
            );
        }

        $textos = impCompartidas($zip);

        $candidatas = $solicitada !== ''
            ? [
                $solicitada =>
                    $hojas[$solicitada]
            ]
            : $hojas;

        $mejor = -1;
        $nombreMejor = '';
        $resultado = [];

        foreach (
            $candidatas as $nombre => $ruta
        ) {
            
            $filasExcel = impFilasHoja(
                $zip,
                $ruta,
                $textos
            );

            $fusionesExcel = impFusionesHoja(
                $zip,
                $ruta
            );

            $a = impAnalizar(
                $filasExcel,
                $fusionesExcel
            );


            $puntos =
                count($a['bienes']) * 100 +
                count($a['bloques']);

            if ($puntos > $mejor) {
                $mejor = $puntos;
                $nombreMejor = $nombre;
                $resultado = $a;
            }
        }

        return [
            'hojas' => array_keys($hojas),
            'hoja' => $nombreMejor,
            'datos' => $resultado
        ];

    } finally {
        $zip->close();
    }
}

// ==========================================
// CONSULTAR EMPLEADOS EXISTENTES
// ==========================================

function impResumenBD(PDO $pdo): array
{
    $empleados = [];

    $sql = "
        SELECT
            e.id,
            e.numero_nomina,
            e.nombre,
            e.cargo,
            e.area_id,
            a.nombre AS area,
            ad.nombre AS adscripcion

        FROM empleados e

        LEFT JOIN areas a
            ON a.id = e.area_id

        LEFT JOIN adscripciones ad
            ON ad.id = a.adscripcion_id
    ";

    foreach ($pdo->query($sql) as $e) {
        if ($e['numero_nomina'] !== null) {
            $empleados[(string) $e['numero_nomina']] = $e;
        }
    }

    return $empleados;
}

/**
 * Coincidencias exactas por nombre, área y adscripción.
 * No vincula automáticamente: cada opción requiere confirmación.
 */
function impCoincidenciasSinNomina(PDO $pdo, array $c): array
{
    $st = $pdo->query('SELECT e.id, e.numero_nomina, e.nombre, e.cargo,
        a.nombre AS area, ad.nombre AS adscripcion
        FROM empleados e
        JOIN areas a ON a.id = e.area_id
        JOIN adscripciones ad ON ad.id = a.adscripcion_id');
    $r = [];
    foreach ($st as $e) {
        if (impN($e['nombre']) === impN($c['nombre']) &&
            impN($e['area']) === impN($c['area']) &&
            impN($e['adscripcion']) === impN($c['adscripcion'])) {
            $r[] = $e;
        }
    }
    return $r;
}

// ==========================================
// GENERAR CLAVE DE CATÁLOGO
// ==========================================

function impClaveCatalogo(
    PDO $pdo,
    string $tabla,
    string $texto
): string {
    if (!in_array(
        $tabla,
        ['areas', 'adscripciones'],
        true
    )) {
        throw new RuntimeException(
            'Catálogo inválido.'
        );
    }

    $clave = 'xls-' . substr(
        hash(
            'sha256',
            $tabla . '|' . impN($texto)
        ),
        0,
        22
    );

    $st = $pdo->prepare(
        "SELECT id
         FROM {$tabla}
         WHERE clave = :c"
    );

    $st->execute([
        ':c' => $clave
    ]);

    if ($st->fetchColumn() !== false) {
        throw new RuntimeException(
            'Conflicto en clave de catálogo.'
        );
    }

    return $clave;
}

// ==========================================
// BUSCAR O CREAR ADSCRIPCIÓN
// ==========================================

function impEncontrarOcrearAds(
    PDO $pdo,
    string $nombre
): int {
    $ids = [];

    foreach (
        $pdo->query(
            'SELECT id, nombre FROM adscripciones'
        ) as $r
    ) {
        if (
            impN($r['nombre']) ===
            impN($nombre)
        ) {
            $ids[] = (int) $r['id'];
        }
    }

    if (count($ids) > 1) {
        throw new RuntimeException(
            'Adscripción ambigua: ' . $nombre
        );
    }

    if ($ids) {
        return $ids[0];
    }

    $clave = impClaveCatalogo(
        $pdo,
        'adscripciones',
        $nombre
    );

    $st = $pdo->prepare(
        'INSERT INTO adscripciones
            (clave, nombre, activo)
         VALUES
            (:c, :n, TRUE)
         RETURNING id'
    );

    $st->execute([
        ':c' => $clave,
        ':n' => $nombre
    ]);

    return (int) $st->fetchColumn();
}

// ==========================================
// BUSCAR O CREAR ÁREA
// ==========================================

function impEncontrarOcrearArea(
    PDO $pdo,
    string $nombre,
    int $adsId
): int {
    $st = $pdo->prepare(
        'SELECT id, nombre
         FROM areas
         WHERE adscripcion_id = :a'
    );

    $st->execute([
        ':a' => $adsId
    ]);

    $ids = [];

    foreach ($st as $r) {
        if (
            impN($r['nombre']) ===
            impN($nombre)
        ) {
            $ids[] = (int) $r['id'];
        }
    }

    if (count($ids) > 1) {
        throw new RuntimeException(
            'Área ambigua: ' . $nombre
        );
    }

    if ($ids) {
        return $ids[0];
    }

    $clave = impClaveCatalogo(
        $pdo,
        'areas',
        $adsId . '|' . $nombre
    );

    $st = $pdo->prepare(
        'INSERT INTO areas
            (clave, nombre, activo, adscripcion_id)
         VALUES
            (:c, :n, TRUE, :a)
         RETURNING id'
    );

    $st->execute([
        ':c' => $clave,
        ':n' => $nombre,
        ':a' => $adsId
    ]);

    return (int) $st->fetchColumn();
}

// ==========================================
// CREAR EMPLEADOS FALTANTES
// ==========================================

function impCrearEmpleados(
    PDO $pdo,
    array $candidatos,
    array $usuario,
    array $decisiones = []
): array {
    if (!$candidatos) {
        throw new RuntimeException('No hay responsables identificados.');
    }
    foreach ($candidatos as $e) {
        $n = trim((string) $e['nomina']);
        if ($n !== '' && !preg_match('/^[1-9][0-9]{0,8}$/', $n)) {
            throw new RuntimeException('Nómina inválida del resguardante: ' . $e['nombre']);
        }
        if (!empty($e['problemas'])) {
            throw new RuntimeException('Datos contradictorios del resguardante: ' . $e['nombre']);
        }
        foreach (['nombre'=>200,'cargo'=>150,'area'=>150,'adscripcion'=>150] as $campo=>$limite) {
            $valor = trim((string) ($e[$campo] ?? ''));
            $largo = function_exists('mb_strlen') ? mb_strlen($valor, 'UTF-8') : strlen($valor);
            if ($valor === '' || $largo > $limite) {
                throw new RuntimeException('Falta ' . $campo . ' o es demasiado largo: ' . $e['nombre']);
            }
        }
        if ($n === '') {
            $decision = (string) ($decisiones[$e['clave']] ?? '');
            if (!preg_match('/^(nuevo|id:[1-9][0-9]*)$/', $decision)) {
                throw new RuntimeException('Debes elegir responsable para ' . $e['nombre']);
            }
        }
    }
    $pdo->beginTransaction();
    try {
        $creados = 0; $existentes = 0; $areas = 0; $ads = 0;
        $resueltos = [];
        $stNomina = $pdo->prepare('SELECT id, nombre, area_id FROM empleados WHERE numero_nomina = :n FOR UPDATE');
        $stId = $pdo->prepare('SELECT e.id, e.nombre, a.nombre AS area, ad.nombre AS adscripcion
            FROM empleados e JOIN areas a ON a.id = e.area_id
            JOIN adscripciones ad ON ad.id = a.adscripcion_id WHERE e.id = :id FOR UPDATE OF e');
        $insertar = $pdo->prepare('INSERT INTO empleados (numero_nomina, nombre, cargo, area_id)
            VALUES (:n, :nom, :c, :a) RETURNING id');
        $log = $pdo->prepare("INSERT INTO log_actividades
            (tabla, accion, registro_id, detalle, usuario, usuario_id, area_id)
            VALUES ('empleados', 'INSERT', :id, :d, :u, :uid, :aid)");
        foreach ($candidatos as $e) {
            $prevAds = (int)$pdo->query('SELECT COUNT(*) FROM adscripciones')->fetchColumn();
            $adsId = impEncontrarOcrearAds($pdo, $e['adscripcion']);
            $ads += (int)$pdo->query('SELECT COUNT(*) FROM adscripciones')->fetchColumn() - $prevAds;
            $prevAreas = (int)$pdo->query('SELECT COUNT(*) FROM areas')->fetchColumn();
            $areaId = impEncontrarOcrearArea($pdo, $e['area'], $adsId);
            $areas += (int)$pdo->query('SELECT COUNT(*) FROM areas')->fetchColumn() - $prevAreas;
            $nomina = trim((string) $e['nomina']);
            if ($nomina !== '') {
                $stNomina->execute([':n'=>(int)$nomina]);
                $actual = $stNomina->fetch(PDO::FETCH_ASSOC);
                if ($actual) {
                    if (impN($actual['nombre']) !== impN($e['nombre']) ||
                        (int)$actual['area_id'] !== $areaId) {
                        throw new RuntimeException('Nómina ' . $nomina . ' ya existe con datos distintos.');
                    }
                    $resueltos[$e['clave']] = (int)$actual['id'];
                    $existentes++;
                    continue;
                }
            } else {
                $decision = (string) $decisiones[$e['clave']];
                $coincidencias = impCoincidenciasSinNomina($pdo, $e);
                if (str_starts_with($decision, 'id:')) {
                    $idSolicitado = (int)substr($decision, 3);
                    $stId->execute([':id'=>$idSolicitado]);
                    $actual = $stId->fetch(PDO::FETCH_ASSOC);
                    if (!$actual || impN($actual['nombre']) !== impN($e['nombre']) ||
                        impN($actual['area']) !== impN($e['area']) ||
                        impN($actual['adscripcion']) !== impN($e['adscripcion'])) {
                        throw new RuntimeException('La selección de empleado no coincide con el Excel: ' . $e['nombre']);
                    }
                    $resueltos[$e['clave']] = $idSolicitado;
                    $existentes++;
                    continue;
                }
                // Si hay un empleado homónimo en esa área, elegirlo explícitamente;
                // no duplicarlo de manera silenciosa.
                if ($coincidencias) {
                    throw new RuntimeException('Ya hay coincidencias para ' . $e['nombre']
                        . '. Selecciona el empleado existente o revísalo manualmente.');
                }
            }
            $insertar->execute([
                ':n' => $nomina === '' ? null : (int)$nomina,
                ':nom' => $e['nombre'], ':c' => $e['cargo'], ':a' => $areaId
            ]);
            $nuevoId = (int)$insertar->fetchColumn();
            $resueltos[$e['clave']] = $nuevoId;
            $log->execute([
                ':id'=>$nuevoId,
                ':d'=>'Empleado desde Excel: ' . $e['nombre']
                    . ($nomina !== '' ? ' (nómina ' . $nomina . ')' : ' (sin nómina)'),
                ':u'=>$usuario['nombre_usuario'], ':uid'=>(int)$usuario['id'], ':aid'=>$areaId
            ]);
            $creados++;
        }
        $pdo->commit();
        return compact('creados', 'existentes', 'areas', 'ads', 'resueltos');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

// ==========================================
// VALIDAR LOS BIENES
// ==========================================

function impValidarBienes(array $bienes): array
{
    $errores = [];

    if (
        !$bienes ||
        count($bienes) > 5000
    ) {
        return [
            'El lote está vacío o es demasiado grande.'
        ];
    }

    foreach ($bienes as $b) {

        $f = (int) ($b['fila'] ?? 0);
        $pref = 'Fila ' . $f . ': ';

        // La nómina es opcional; NO lo son nombre y ubicación
        // cuando se trata de un resguardante sin nómina.
        $n = trim((string) ($b['nomina'] ?? ''));
        if ($f < 1 || ($n !== '' && !preg_match('/^[1-9][0-9]{0,8}$/', $n))) {
            $errores[] = $pref . 'nómina inválida.';
        }
        if ($n === '' && (impN($b['nombre'] ?? '') === '' ||
            trim((string)($b['cargo'] ?? '')) === '' ||
            empty($b['clave_responsable']))) {
            $errores[] = $pref . 'faltan nombre o cargo del responsable sin nómina.';
        }

        // LONGITUDES Y CAMPOS OBLIGATORIOS

        $limites = [
            'descripcion' => 200,
            'inventario' => 50,
            'marca' => 100,
            'modelo' => 100,
            'serie' => 100,
            'material' => 100,
            'factura' => 100
        ];

        foreach (
            $limites as $campo => $limite
        ) {
            $v = trim(
                (string) (
                    $b[$campo] ?? ''
                )
            );

            $largo = function_exists('mb_strlen')
                ? mb_strlen($v, 'UTF-8')
                : strlen($v);

            if (
                $largo > $limite ||
                (
                    in_array(
                        $campo,
                        [
                            'descripcion',
                            'inventario'
                        ],
                        true
                    ) &&
                    $v === ''
                )
            ) {
                $errores[] =
                    $pref . $campo
                    . ' faltante o excede '
                    . $limite
                    . ' caracteres.';
            }
        }

        // El estado, costo y fecha admiten NULL en PostgreSQL.
        // Si no se pueden interpretar, se registran como pendientes
        // SOLO después de confirmación explícita, sin inventar datos.
        // Sus valores originales se conservan en observaciones.
        // Véase impAdvertenciasBienes().

        // ÁREA Y ADSCRIPCIÓN

        if (
            trim(
                (string) (
                    $b['area'] ?? ''
                )
            ) === '' ||
            trim(
                (string) (
                    $b['adscripcion'] ?? ''
                )
            ) === ''
        ) {
            $errores[] =
                $pref
                . 'área o adscripción sin identificar.';
        }

        if (count($errores) >= 50) {
            $errores[] =
                'Hay más errores; se muestran '
                . 'los primeros 50.';
            break;
        }
    }

    return $errores;
}

/**
 * Advertencias que no pueden convertirse automáticamente a datos fiables.
 * Requieren consentimiento expreso al confirmar el lote.
 */
function impAdvertenciasBienes(array $bienes): array
{
    $avisos = [];
    $grupos = [];
    foreach ($bienes as $b) {
        $fila = (int) ($b['fila'] ?? 0);
        $pref = 'Fila ' . $fila . ': ';
        $grupo = (string) ($b['costo_compartido'] ?? '');
        if ($grupo !== '') {
            if (!isset($grupos[$grupo])) {
                $grupos[$grupo] = true;
                $monto = $b['costo_total_grupo'] ?? null;
                $avisos[] = $pref . 'costo COMPARTIDO ' . $grupo
                    . ', total ' . ($monto !== null ? '$' . $monto : 'desconocido')
                    . '. No se duplicará ni repartirá; costo individual NULL.';
            }
        } elseif (($b['costo'] ?? null) === null) {
            $avisos[] = $pref . 'costo individual ausente o no interpretable ('
                . (string) ($b['costo_original'] ?? '') . '); se guardará NULL.';
        }
        if (($b['estado'] ?? null) === null) {
            $avisos[] = $pref . 'estado físico no reconocido ('
                . (string) ($b['estado_original'] ?? '') . '); se guardará NULL.';
        }
        $fecha = $b['fecha'] ?? null;
        if ($fecha !== null && impFechaParaBD($fecha) === null) {
            $avisos[] = $pref . 'fecha no válida o no interpretable ('
                . (string) ($b['fecha_original'] ?? '') . '); se guardará NULL.';
        }
    }
    return $avisos;
}

/**
 * NUNCA pasar un texto como «DONADAS POR C.N.A.» a una columna DATE.
 */
function impFechaParaBD(mixed $fecha): ?string
{
    if (!is_string($fecha) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
    return $d && $d->format('Y-m-d') === $fecha && $fecha <= date('Y-m-d')
        ? $fecha : null;
}

/**
 * Preserva en el activo las notas y montos pendientes de valoración.
 * Observaciones es TEXT en PostgreSQL: no requiere una migración nueva.
 */
function impObservacionesParaBD(array $b): ?string
{
    $notas = [];
    $obs = trim((string) ($b['observaciones'] ?? ''));
    if ($obs !== '') {
        $notas[] = $obs;
    }
    if (!empty($b['costo_compartido'])) {
        $notas[] = '[Excel: costo conjunto en ' . $b['costo_compartido']
            . '; total del conjunto: '
            . ($b['costo_total_grupo'] === null ? 'no interpretable' : '$' . $b['costo_total_grupo'])
            . '; costo individual no determinado]';
    } elseif (($b['costo'] ?? null) === null) {
        $notas[] = '[Excel: costo original sin valor individual válido: '
            . ($b['costo_original'] ?? '') . ']';
    }
    if (($b['estado'] ?? null) === null) {
        $notas[] = '[Excel: estado físico original pendiente: '
            . ($b['estado_original'] ?? '') . ']';
    }
    $fecha = $b['fecha'] ?? null;
    if ($fecha !== null && impFechaParaBD($fecha) === null) {
        $notas[] = '[Excel: fecha original pendiente de verificar: '
            . ($b['fecha_original'] ?? '') . ']';
    }
    return $notas ? implode(' | ', $notas) : null;
}

// ==========================================
// VERIFICAR EMPLEADOS, ÁREAS Y ADSCRIPCIONES
// ==========================================

function impRevisarRelaciones(PDO $pdo, array $bienes, array $resueltos = []): array
{
    $porNomina = $pdo->prepare('SELECT e.id AS empleado_id, e.nombre,
        a.nombre AS area_nombre, ad.nombre AS adscripcion_nombre
        FROM empleados e JOIN areas a ON a.id = e.area_id
        JOIN adscripciones ad ON ad.id = a.adscripcion_id
        WHERE e.numero_nomina = :n');
    $porId = $pdo->prepare('SELECT e.id AS empleado_id, e.nombre,
        a.nombre AS area_nombre, ad.nombre AS adscripcion_nombre
        FROM empleados e JOIN areas a ON a.id = e.area_id
        JOIN adscripciones ad ON ad.id = a.adscripcion_id
        WHERE e.id = :id');
    $cache = []; $errores = [];
    foreach ($bienes as $b) {
        $n = trim((string) ($b['nomina'] ?? ''));
        $clave = (string) ($b['clave_responsable'] ?? '');
        if ($n !== '') {
            $ck = 'N:' . $n;
            if (!array_key_exists($ck, $cache)) {
                $porNomina->execute([':n'=>(int)$n]);
                $cache[$ck] = $porNomina->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        } else {
            $id = (int) ($resueltos[$clave] ?? 0);
            if ($id < 1) {
                $errores[] = 'Fila ' . $b['fila'] . ': confirmar empleado SIN nómina: ' . ($b['nombre'] ?? '(sin nombre)');
                if (count($errores) >= 30) break;
                continue;
            }
            $ck = 'ID:' . $id;
            if (!array_key_exists($ck, $cache)) {
                $porId->execute([':id'=>$id]);
                $cache[$ck] = $porId->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        }
        $emp = $cache[$ck];
        if (!$emp) {
            $errores[] = 'Fila ' . $b['fila'] . ': empleado no existe o no tiene área/adscripción.';
        } elseif (impN($emp['area_nombre']) !== impN($b['area']) ||
            impN($emp['adscripcion_nombre']) !== impN($b['adscripcion']) ||
            ($n === '' && impN($emp['nombre']) !== impN($b['nombre']))) {
            $errores[] = 'Fila ' . $b['fila'] . ': responsable/área/adscripción no coincide.';
        }
        if (count($errores) >= 30) break;
    }
    return $errores;
}

// ==========================================
// VERIFICAR COLUMNA DE IMPORTACIÓN
// ==========================================

function impRevisarColumna(PDO $pdo): void
{
    $st = $pdo->query(
        "SELECT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = 'activos'
              AND column_name = 'clave_importacion'
        )"
    );

    if (!$st->fetchColumn()) {
        throw new RuntimeException(
            'Falta la columna '
            . 'activos.clave_importacion. '
            . 'Ejecuta el SQL indicado antes '
            . 'de importar.'
        );
    }
}

// ==========================================
// GUARDAR ACTIVOS EN POSTGRESQL
// ==========================================

function impGuardarActivos(
    PDO $pdo,
    array $lote,
    array $usuario,
    bool $aceptaPendientes = false
): array {
    $bienes = $lote['bienes'] ?? [];

    $huella = (string) (
        $lote['huella'] ?? ''
    );

    $hoja = (string) (
        $lote['hoja'] ?? ''
    );

    if (
        !is_array($bienes) ||
        !preg_match(
            '/^[a-f0-9]{64}$/',
            $huella
        ) ||
        $hoja === ''
    ) {
        throw new RuntimeException(
            'Lote inválido. Analiza de nuevo el Excel.'
        );
    }

    // VALIDAR TODOS LOS BIENES

    $errores = impValidarBienes($bienes);

    if ($errores) {
        throw new RuntimeException(
            'Importación detenida: '
            . implode(
                ' | ',
                array_slice(
                    $errores,
                    0,
                    5
                )
            )
        );
    }

    // Una casilla en el formulario no basta: validar de nuevo
    // los datos de sesión en el servidor antes de importar.
    $avisos = impAdvertenciasBienes($bienes);
    if ($avisos && !$aceptaPendientes) {
        throw new RuntimeException(
            'Hay datos pendientes. Debes aceptar explícitamente '
            . 'que se registrarán como NULL, con el texto original '
            . 'conservado en observaciones.'
        );
    }

    // VERIFICAR ESTRUCTURA
    impRevisarColumna($pdo);

    // VERIFICAR EMPLEADOS Y ÁREAS

    $errores = impRevisarRelaciones(
        $pdo,
        $bienes,
        $lote['resueltos'] ?? []
    );

    if ($errores) {
        throw new RuntimeException(
            'Importación detenida: '
            . implode(
                ' | ',
                array_slice(
                    $errores,
                    0,
                    5
                )
            )
        );
    }

    // INICIAR TRANSACCIÓN

    $pdo->beginTransaction();

    try {

        $empleados = [];

        // BUSCAR RESPONSABLE

        $buscarEmpleado = $pdo->prepare(
            'SELECT
                e.id AS empleado_id,
                a.id AS area_id,
                a.nombre AS area_nombre,
                ad.nombre AS adscripcion_nombre

             FROM empleados e

             JOIN areas a
                ON a.id = e.area_id

             JOIN adscripciones ad
                ON ad.id = a.adscripcion_id

             WHERE e.numero_nomina = :n'
        );

        $buscarPorId = $pdo->prepare(
            'SELECT e.id AS empleado_id, e.nombre, a.id AS area_id,
                a.nombre AS area_nombre, ad.nombre AS adscripcion_nombre
            FROM empleados e JOIN areas a ON a.id = e.area_id
            JOIN adscripciones ad ON ad.id = a.adscripcion_id WHERE e.id = :id'
        );

        // INSERTAR ACTIVO

        $insertar = $pdo->prepare(
            'INSERT INTO activos
                (
                    descripcion,
                    num_inventario,
                    marca,
                    modelo,
                    serie,
                    material,
                    estado_fisico,
                    fecha_adq,
                    factura,
                    costo,
                    observaciones,
                    empleado_id,
                    area_id,
                    usuario_id,
                    clave_importacion
                )
             VALUES
                (
                    :d,
                    :inv,
                    :mar,
                    :mod,
                    :ser,
                    :mat,
                    :est,
                    :fec,
                    :fac,
                    :cos,
                    :obs,
                    :emp,
                    :area,
                    :uid,
                    :clave
                )

             ON CONFLICT (clave_importacion)
             WHERE clave_importacion IS NOT NULL

             DO NOTHING

             RETURNING id'
        );

        // REGISTRO DE ACTIVIDADES

        $log = $pdo->prepare(
            "INSERT INTO log_actividades
                (
                    tabla,
                    accion,
                    registro_id,
                    detalle,
                    usuario,
                    usuario_id,
                    area_id
                )

             VALUES
                (
                    'activos',
                    'INSERT',
                    :id,
                    :d,
                    :u,
                    :uid,
                    :a
                )"
        );

        $creados = 0;
        $omitidos = 0;

        foreach ($bienes as $b) {

            $nomina = trim((string) $b['nomina']);
            $claveResponsable = (string) ($b['clave_responsable'] ?? '');
            if ($nomina !== '') {
                $ck = 'N' . $nomina;
                if (!array_key_exists($ck, $empleados)) {
                    $buscarEmpleado->execute([':n'=>(int)$nomina]);
                    $empleados[$ck] = $buscarEmpleado->fetch(PDO::FETCH_ASSOC) ?: null;
                }
            } else {
                $id = (int) ($lote['resueltos'][$claveResponsable] ?? 0);
                if ($id < 1) {
                    throw new RuntimeException('Sin confirmar identidad del empleado sin nómina, fila ' . $b['fila']);
                }
                $ck = 'I' . $id;
                if (!array_key_exists($ck, $empleados)) {
                    $buscarPorId->execute([':id'=>$id]);
                    $empleados[$ck] = $buscarPorId->fetch(PDO::FETCH_ASSOC) ?: null;
                }
            }
            $emp = $empleados[$ck];
            if (!$emp ||
                impN($emp['area_nombre']) !== impN($b['area']) ||
                impN($emp['adscripcion_nombre']) !== impN($b['adscripcion']) ||
                ($nomina === '' && impN($emp['nombre']) !== impN($b['nombre']))) {
                throw new RuntimeException('Asignación incorrecta de la fila ' . $b['fila']
                    . ' al responsable ' . ($b['nombre'] ?? $nomina));
            }

            // IDENTIFICAR EL RENGLÓN DEL EXCEL

            $clave = hash(
                'sha256',
                $huella
                . '|' . $hoja
                . '|' . (int) $b['fila']
            );

            // GUARDAR ACTIVO

            $insertar->execute([

                ':d' =>
                    trim($b['descripcion']),

                ':inv' =>
                    trim($b['inventario']),

                ':mar' =>
                    trim(
                        (string) $b['marca']
                    ) ?: null,

                ':mod' =>
                    trim(
                        (string) $b['modelo']
                    ) ?: null,

                ':ser' =>
                    trim(
                        (string) $b['serie']
                    ) ?: null,

                ':mat' =>
                    trim(
                        (string) $b['material']
                    ) ?: null,

                ':est' =>
                    $b['estado'],

                ':fec' =>
                    impFechaParaBD($b['fecha'] ?? null),

                ':fac' =>
                    trim(
                        (string) $b['factura']
                    ) ?: null,

                ':cos' =>
                    $b['costo'],

                ':obs' =>
                    impObservacionesParaBD($b),

                ':emp' =>
                    (int) $emp['empleado_id'],

                ':area' =>
                    (int) $emp['area_id'],

                ':uid' =>
                    (int) $usuario['id'],

                ':clave' =>
                    $clave
            ]);

            $nuevoId = $insertar->fetchColumn();

            // YA IMPORTADO DESDE EL MISMO XLSX

            if ($nuevoId === false) {
                $omitidos++;
                continue;
            }

            // REGISTRAR ACTIVIDAD

            $log->execute([

                ':id' =>
                    (int) $nuevoId,

                ':d' =>
                    'Importación desde Excel. '
                    . 'Hoja: ' . $hoja
                    . ', fila: ' . $b['fila']
                    . ', responsable: ' . ($nomina !== '' ? 'nómina ' . $nomina : $b['nombre']),

                ':u' =>
                    $usuario['nombre_usuario'],

                ':uid' =>
                    (int) $usuario['id'],

                ':a' =>
                    (int) $emp['area_id']
            ]);

            $creados++;
        }

        // CONFIRMAR TODOS LOS CAMBIOS

        $pdo->commit();

        return compact(
            'creados',
            'omitidos'
        );

    } catch (Throwable $ex) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $ex;
    }
}

// ==========================================
// VARIABLES DE LA PÁGINA
// ==========================================

$error = '';
$mensaje = '';

$datos = [];
$hojas = [];
$hoja = '';

$candidatos = [];
$empleadosBD = [];

$erroresBienes = [];
$erroresRelaciones = [];
$avisosBienes = [];

$tokenActivos = '';
$tokenEmpleados = '';
$coincidencias = [];
$resueltosVista = [];

// ==========================================
// PROCESAR FORMULARIOS
// ==========================================

if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET')
    === 'POST'
) {

    try {

        // VERIFICAR CSRF

        if (!csrf_validate(
            $_POST['_csrf'] ?? null
        )) {
            throw new RuntimeException(
                'Sesión expirada. Actualiza la página.'
            );
        }

        $accion = (string) (
            $_POST['accion'] ?? 'analizar'
        );

        // ==================================
        // ACCIÓN: ANALIZAR EXCEL
        // ==================================

        if ($accion === 'analizar') {

            $file = $_FILES['excel'] ?? null;

            if (
                !is_array($file) ||
                ($file['error'] ?? -1) !==
                    UPLOAD_ERR_OK ||
                !is_uploaded_file(
                    $file['tmp_name'] ?? ''
                )
            ) {
                throw new RuntimeException(
                    'Selecciona un XLSX válido.'
                );
            }

            // VALIDAR ARCHIVO

            if (
                strtolower(
                    pathinfo(
                        (string) $file['name'],
                        PATHINFO_EXTENSION
                    )
                ) !== 'xlsx' ||

                ($file['size'] ?? 0) < 1 ||

                ($file['size'] ?? 0) >
                    10 * 1024 * 1024
            ) {
                throw new RuntimeException(
                    'Solo se permiten archivos XLSX '
                    . 'de hasta 10 MB.'
                );
            }

            // EXTENSIONES PHP

            if (
                !class_exists(ZipArchive::class) ||
                !class_exists(DOMDocument::class)
            ) {
                throw new RuntimeException(
                    'Habilita las extensiones ZIP '
                    . 'y DOM/XML en XAMPP.'
                );
            }

            // SELECCIONAR HOJA

            $res = impSeleccionarHoja(
                $file['tmp_name'],
                trim(
                    (string) (
                        $_POST['hoja'] ?? ''
                    )
                )
            );

            $datos = $res['datos'];
            $hojas = $res['hojas'];
            $hoja = $res['hoja'];

            if (
                empty($datos['bloques']) ||
                empty($datos['bienes'])
            ) {
                throw new RuntimeException(
                    'No se identificaron bienes '
                    . 'en la hoja seleccionada.'
                );
            }

            // IDENTIFICAR EMPLEADOS

            $candidatos = impEmpleadosCandidatos(
                $datos['bloques']
            );

            $empleadosBD = impResumenBD(db());
            foreach ($candidatos as $claveC => $cand) {
                if ($cand['nomina'] === '' && $cand['nombre'] !== '') {
                    $coincidencias[$claveC] = impCoincidenciasSinNomina(db(), $cand);
                }
            }

            // VALIDACIONES

            $erroresBienes = impValidarBienes(
                $datos['bienes']
            );
            $avisosBienes = impAdvertenciasBienes(
                $datos['bienes']
            );

            if (!$erroresBienes) {
                $erroresRelaciones =
                    impRevisarRelaciones(
                        db(),
                        $datos['bienes']
                    );
            }

            // IDENTIFICAR EL ARCHIVO EXACTO

            $huella = hash_file(
                'sha256',
                $file['tmp_name']
            );

            if (!is_string($huella)) {
                throw new RuntimeException(
                    'No se pudo calcular la huella '
                    . 'del Excel.'
                );
            }

            // TOKENS DE CONFIRMACIÓN

            $tokenActivos = bin2hex(
                random_bytes(20)
            );

            $tokenEmpleados = bin2hex(
                random_bytes(20)
            );

            // GUARDAR LOTE DE BIENES EN SESIÓN

            $_SESSION[
                'coapatap_importacion_bienes'
            ] = [
                'usuario_id' =>
                    (int) $usuario['id'],

                'hasta' => time() + 1800,

                'token' => $tokenActivos,

                'huella' => $huella,

                'hoja' => $hoja,

                'bienes' => $datos['bienes'],
                'resueltos' => []
            ];
            $_SESSION['coapatap_importacion_previa'] = [
                'hojas' => $hojas, 'hoja' => $hoja,
                'datos' => $datos, 'candidatos' => $candidatos
            ];

            // GUARDAR CANDIDATOS A EMPLEADOS

            $_SESSION[
                'coapatap_importacion_empleados'
            ] = [
                'usuario_id' =>
                    (int) $usuario['id'],

                'hasta' => time() + 1800,

                'token' => $tokenEmpleados,

                'candidatos' => $candidatos
            ];

        // ==================================
        // ACCIÓN: CONFIRMAR IMPORTACIÓN
        // ==================================

        } elseif (
            $accion === 'confirmar_empleados' ||
            $accion === 'confirmar_activos'
        ) {

            $clave =
                $accion === 'confirmar_empleados'
                    ? 'coapatap_importacion_empleados'
                    : 'coapatap_importacion_bienes';

            $lote = $_SESSION[$clave] ?? null;

            // VERIFICAR CONFIRMACIÓN

            if (
                !is_array($lote) ||

                (int) (
                    $lote['usuario_id'] ?? 0
                ) !== (int) $usuario['id'] ||

                (int) (
                    $lote['hasta'] ?? 0
                ) < time() ||

                !is_string(
                    $lote['token'] ?? null
                ) ||

                !hash_equals(
                    $lote['token'],
                    (string) (
                        $_POST['token'] ?? ''
                    )
                )
            ) {
                throw new RuntimeException(
                    'La vista previa caducó. '
                    . 'Analiza nuevamente el Excel.'
                );
            }

            // ==================================
            // REGISTRAR EMPLEADOS
            // ==================================

            if (
                $accion === 'confirmar_empleados'
            ) {

                $r = impCrearEmpleados(
                    db(),
                    $lote['candidatos'],
                    $usuario,
                    is_array($_POST['vincular'] ?? null) ? $_POST['vincular'] : []
                );
                if (isset($_SESSION['coapatap_importacion_bienes'])) {
                    $_SESSION['coapatap_importacion_bienes']['resueltos'] = $r['resueltos'];
                }

                unset($_SESSION[$clave]);

                $mensaje =
                    'Alta finalizada: '
                    . $r['creados']
                    . ' empleados nuevos, '
                    . $r['existentes']
                    . ' ya existentes, '
                    . $r['areas']
                    . ' áreas nuevas y '
                    . $r['ads']
                    . ' adscripciones nuevas. '
                    . 'Los activos todavía no se han importado. '
                    . 'Revisa la vista previa y confirma la importación.';
                // Restaurar la vista previa sin volver a subir el archivo.
                $vista = $_SESSION['coapatap_importacion_previa'] ?? [];
                $datos = $vista['datos'] ?? [];
                $hojas = $vista['hojas'] ?? [];
                $hoja = $vista['hoja'] ?? '';
                $candidatos = $vista['candidatos'] ?? [];
                $empleadosBD = impResumenBD(db());
                $resueltosVista = $r['resueltos'];
                $erroresBienes = impValidarBienes($datos['bienes'] ?? []);
                $avisosBienes = impAdvertenciasBienes($datos['bienes'] ?? []);
                if (!$erroresBienes) {
                    $erroresRelaciones = impRevisarRelaciones(db(), $datos['bienes'], $resueltosVista);
                }
                $tokenActivos = $_SESSION['coapatap_importacion_bienes']['token'] ?? '';
                foreach ($candidatos as $claveC => $cand) {
                    if ($cand['nomina'] === '' && $cand['nombre'] !== '') {
                        $coincidencias[$claveC] = impCoincidenciasSinNomina(db(), $cand);
                    }
                }

            // ==================================
            // IMPORTAR ACTIVOS
            // ==================================

            } else {

                $r = impGuardarActivos(
                    db(),
                    $lote,
                    $usuario,
                    ($_POST['aceptar_pendientes'] ?? '') === '1'
                );

                // IMPEDIR REUTILIZAR CONFIRMACIÓN

                unset($_SESSION[$clave], $_SESSION['coapatap_importacion_previa']);

                $mensaje =
                    'Importación finalizada: '
                    . $r['creados']
                    . ' activos registrados y '
                    . $r['omitidos']
                    . ' omitidos por haber sido '
                    . 'importados antes desde '
                    . 'el mismo Excel.';
            }

        } else {
            throw new RuntimeException(
                'Acción desconocida.'
            );
        }

    } catch (Throwable $ex) {

        logger(
            'Importación Excel: '
            . $ex->getMessage(),
            'ERROR'
        );

        $error = $ex instanceof RuntimeException
            ? $ex->getMessage()
            : 'La operación no se pudo completar. '
                . 'Revisa el registro del servidor.';

        // No conservar cargas fallidas.

        if (
            ($accion ?? '') === 'analizar'
        ) {
            unset(
                $_SESSION[
                    'coapatap_importacion_bienes'
                ],
                $_SESSION[
                    'coapatap_importacion_empleados'
                ],
                $_SESSION['coapatap_importacion_previa']
            );
        }
    }
}

// ==========================================
// INTERFAZ DEL MÓDULO
// ==========================================

require_once __DIR__ . '/../includes/header.php';

?>

<section
    style="
        max-width:1400px;
        margin:24px auto 48px;
        padding:0 12px;
    "
>

    <h1>📥 Importación de Excel</h1>

    <p>
        Analiza los bienes, verifica sus datos y
        confirma por separado el registro de
        empleados y la importación de activos.
    </p>

    <!-- FORMULARIO DE ANÁLISIS -->

    <form
        method="post"
        enctype="multipart/form-data"
        style="
            display:flex;
            gap:14px;
            flex-wrap:wrap;
            align-items:end;
            padding:16px;
            border:1px solid #ddd;
            border-radius:10px;
        "
    >

        <input
            type="hidden"
            name="_csrf"
            value="<?= impE(csrf_token()) ?>"
        >

        <input
            type="hidden"
            name="accion"
            value="analizar"
        >

        <label>
            Archivo Excel (.xlsx, máx. 10 MB)

            <input
                type="file"
                name="excel"
                accept=".xlsx"
                required
            >
        </label>

        <label>
            Nombre de la hoja (opcional)

            <input
                type="text"
                name="hoja"
                placeholder="Vacío = detección automática"
                value="<?= impE(
                    $_POST['hoja'] ?? ''
                ) ?>"
                style="min-width:240px"
            >
        </label>

        <button type="submit">
            🔎 Analizar sin guardar
        </button>

    </form>

    <!-- MENSAJES -->

    <?php if ($error !== ''): ?>

        <p
            role="alert"
            style="
                color:#991b1b;
                font-weight:bold;
            "
        >
            <?= impE($error) ?>
        </p>

    <?php endif; ?>

    <?php if ($mensaje !== ''): ?>

        <p
            role="status"
            style="
                color:#166534;
                font-weight:bold;
            "
        >
            <?= impE($mensaje) ?>
        </p>

    <?php endif; ?>

    <?php if ($datos): ?>

        <!-- RESUMEN -->

        <h2>
            Hoja analizada:
            <?= impE($hoja) ?>
        </h2>

        <p>
            <strong>
                <?= count($datos['bienes']) ?>
                bienes
            </strong>

            en

            <strong>
                <?= count($datos['bloques']) ?>
                tablas
            </strong>.
        </p>

        <details>
            <summary>
                Ver las
                <?= count($hojas) ?>
                hojas del archivo
            </summary>

            <p>
                <?= impE(
                    implode(' · ', $hojas)
                ) ?>
            </p>
        </details>

        <!-- EMPLEADOS -->

        <h3>
            Responsables identificados (con o sin nómina)
        </h3>
        <p>Cuando falte la nómina, confirma la identidad mediante el selector
        del formulario inferior. No se vincula automáticamente por el nombre.</p>

        <?php
        $puedeCrear = !empty($candidatos);
        ?>

        <div style="overflow-x:auto">

            <table
                style="
                    width:100%;
                    min-width:980px;
                "
            >

                <thead>
                    <tr>
                        <th>Nómina</th>
                        <th>Nombre</th>
                        <th>Cargo</th>
                        <th>Área</th>
                        <th>Adscripción</th>
                        <th>Bienes</th>
                        <th>Situación</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach (
                        $candidatos as $c
                    ): ?>

                        <?php

                        $bd = $c['nomina'] !== ''
                            ? ($empleadosBD[$c['nomina']] ?? null)
                            : null;
                        $opciones = $coincidencias[$c['clave']] ?? [];
                        $vinculado = isset($resueltosVista[$c['clave']]);

                        $faltan =
                            $c['nombre'] === '' ||
                            $c['cargo'] === '' ||
                            $c['area'] === '' ||
                            $c['adscripcion'] === '' ||
                            !empty($c['problemas']);

                        $conflicto = $bd && (
                            impN($bd['nombre']) !==
                                impN($c['nombre']) ||

                            impN(
                                $bd['area'] ?? ''
                            ) !== impN($c['area']) ||

                            impN(
                                $bd['adscripcion'] ?? ''
                            ) !== impN(
                                $c['adscripcion']
                            )
                        );

                        if (
                            $faltan ||
                            $conflicto
                        ) {
                            $puedeCrear = false;
                        }

                        ?>

                        <tr>

                            <td>
                                <?= impE(
                                    $c['nomina'] ?: 'Sin nómina'
                                ) ?>
                            </td>

                            <td>
                                <?= impE(
                                    $c['nombre']
                                ) ?>
                            </td>

                            <td>
                                <?= impE(
                                    $c['cargo']
                                ) ?>
                            </td>

                            <td>
                                <?= impE(
                                    $c['area']
                                ) ?>
                            </td>

                            <td>
                                <?= impE(
                                    $c['adscripcion']
                                ) ?>
                            </td>

                            <td>
                                <?= (int) $c['bienes'] ?>
                            </td>

                            <td>

                                <?php if ($faltan): ?>

                                    ⚠️ Datos incompletos
                                    o contradictorios

                                <?php elseif ($conflicto): ?>

                                    ⚠️ Conflicto con la BD

                                <?php elseif ($vinculado): ?>
                                    ✅ Responsable sin nómina confirmado

                                <?php elseif ($c['nomina'] === ''): ?>
                                    ⚠️ Sin nómina: seleccionar responsable (<?= count($opciones) ?> coincidencias)

                                <?php elseif ($bd): ?>

                                    Ya existe

                                <?php else: ?>

                                    Pendiente de crear

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>

        <!-- RESGUARDOS DETECTADOS -->

        <h3>Resguardos detectados</h3>

        <div style="overflow-x:auto">

            <table
                style="
                    width:100%;
                    min-width:1000px;
                "
            >

                <thead>
                    <tr>
                        <th>Fila</th>
                        <th>Nómina</th>
                        <th>Resguardante</th>
                        <th>Cargo</th>
                        <th>Área</th>
                        <th>Adscripción</th>
                        <th>Bienes</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach (
                        $datos['bloques'] as $b
                    ): ?>

                        <tr>

                            <td>
                                <?= (int) $b['fila'] ?>
                            </td>

                            <td>
                                <?= impE(
                                    $b['nomina'] ?: 'Sin nómina'
                                ) ?>
                            </td>

                            <td>
                                <?= impE(
                                    $b['nombre']
                                ) ?>
                            </td>

                            <td>
                                <?= impE(
                                    $b['cargo']
                                ) ?>
                            </td>

                            <td>
                                <?= impE(
                                    $b['area']
                                ) ?>
                            </td>

                            <td>
                                <?= impE(
                                    $b['adscripcion']
                                ) ?>
                            </td>

                            <td>
                                <?= (int) $b['cantidad'] ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>

        <!-- DETALLE COMPLETO -->

        <h3>Detalle completo de activos</h3>

        <p>
            El número de inventario puede repetirse.
            Revisa los once campos de cada bien. Los importes
            combinados de varios bienes se muestran como pendientes,
            sin copiar un mismo total a todos los renglones.
        </p>

        <div
            style="
                overflow:auto;
                max-height:580px;
                border:1px solid #ddd;
            "
        >

            <table
                style="
                    min-width:2100px;
                    width:100%;
                    font-size:12px;
                    border-collapse:collapse;
                "
            >

                <thead>
                    <tr>
                        <th>Fila</th>
                        <th>Nómina</th>
                        <th>Área</th>
                        <th>Adscripción</th>
                        <th>Descripción</th>
                        <th>Inventario</th>
                        <th>Marca</th>
                        <th>Modelo</th>
                        <th>Serie</th>
                        <th>Material</th>
                        <th>Estado</th>
                        <th>Fecha</th>
                        <th>Factura</th>
                        <th>Costo</th>
                        <th>Observaciones</th>
                        <th>Alertas</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach (
                        $datos['bienes'] as $b
                    ): ?>

                        <?php

                        $alertas = [];

                        if (
                            !isset($candidatos[$b['clave_responsable'] ?? ''])
                        ) {
                            $alertas[] =
                                'Nómina no identificada';
                        }

                        if (
                            $b['inventario'] === ''
                        ) {
                            $alertas[] =
                                'Inventario vacío';
                        }

                        if (
                            $b['estado'] === null
                        ) {
                            $alertas[] =
                                'Estado no reconocido';
                        }

                        if (!empty($b['costo_compartido'])) {
                            $alertas[] = 'Costo compartido: ' . $b['costo_compartido']
                                . ' (total $' . ($b['costo_total_grupo'] ?? '?') . ')';
                        } elseif ($b['costo'] === null) {
                            $alertas[] = 'Costo individual pendiente';
                        }

                        if (
                            $b['fecha'] !== null &&
                            impFechaParaBD($b['fecha']) === null
                        ) {
                            $alertas[] =
                                'Fecha pendiente';
                        }

                        ?>

                        <tr
                            style="
                                border-bottom:1px solid #ddd;
                            "
                        >

                            <td>
                                <?= (int) $b['fila'] ?>
                            </td>

                            <td>
                                <?= impE($b['nomina'] ?: 'Sin nómina') ?>
                            </td>

                            <td>
                                <?= impE($b['area']) ?>
                            </td>

                            <td>
                                <?= impE(
                                    $b['adscripcion']
                                ) ?>
                            </td>

                            <td>
                                <?= impE(
                                    $b['descripcion']
                                ) ?>
                            </td>

                            <td>
                                <?= impE(
                                    $b['inventario']
                                ) ?>
                            </td>

                            <td>
                                <?= impE($b['marca']) ?>
                            </td>

                            <td>
                                <?= impE($b['modelo']) ?>
                            </td>

                            <td>
                                <?= impE($b['serie']) ?>
                            </td>

                            <td>
                                <?= impE(
                                    $b['material']
                                ) ?>
                            </td>

                            <td>
                                <?= impE(
                                    $b['estado']
                                    ?? $b['estado_original']
                                ) ?>
                            </td>

                            <td>
                                <?= impE(
                                    $b['fecha']
                                    ?? $b['fecha_original']
                                ) ?>
                            </td>

                            <td>
                                <?= impE($b['factura']) ?>
                            </td>

                            <td>
                                <?php if (!empty($b['costo_compartido'])): ?>
                                    <?= impE('PENDIENTE (grupo ' . $b['costo_compartido'] . ')') ?>
                                <?php else: ?>
                                    <?= impE($b['costo'] ?? $b['costo_original']) ?>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= impE(
                                    $b['observaciones']
                                ) ?>
                            </td>

                            <td
                                style="
                                    color:<?= $alertas
                                        ? '#9a3412'
                                        : '#166534'
                                    ?>;
                                "
                            >
                                <?= impE(
                                    $alertas
                                        ? implode(
                                            '; ',
                                            $alertas
                                        )
                                        : 'Sin alertas'
                                ) ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>

        <!-- ERRORES DEL ANÁLISIS -->

        <?php if (
            $erroresBienes ||
            $erroresRelaciones
        ): ?>

            <div
                role="alert"
                style="
                    padding:12px;
                    margin:14px 0;
                    border:1px solid #f59e0b;
                    border-radius:8px;
                "
            >

                <strong>
                    Hay datos pendientes de revisar
                    antes de importar.
                </strong>

                <ul>

                    <?php foreach (
                        array_slice(
                            array_merge(
                                $erroresBienes,
                                $erroresRelaciones
                            ),
                            0,
                            12
                        ) as $err
                    ): ?>

                        <li>
                            <?= impE($err) ?>
                        </li>

                    <?php endforeach; ?>

                </ul>

            </div>

        <?php endif; ?>

        <!-- DATOS QUE PUEDEN IMPORTARSE SIN INVENTAR VALORES -->
        <?php if ($avisosBienes): ?>
            <div role="status" style="padding:14px; margin:16px 0; border:1px solid #d97706; border-radius:8px;">
                <strong>Datos incompletos o compartidos: <?= count($avisosBienes) ?> advertencias</strong>
                <p>Estos valores NO se adivinan. Podrás importarlos con costo,
                estado físico o fecha en NULL, conservando los textos y
                los importes totales originales en observaciones.
                Los totales financieros no incluirán esos importes pendientes.</p>
                <details>
                    <summary>Ver advertencias y renglones</summary>
                    <ul>
                        <?php foreach (array_slice($avisosBienes, 0, 100) as $aviso): ?>
                            <li><?= impE($aviso) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            </div>
        <?php endif; ?>

        <!-- ==================================
             REGISTRAR EMPLEADOS
        =================================== -->

        <h3>
            1. Registrar empleados, áreas y
            adscripciones faltantes
        </h3>

        <?php if ($puedeCrear && $tokenEmpleados !== ''): ?>

            <form
                method="post"
                onsubmit="return confirm('¿Confirmas el alta de empleados y catálogos faltantes? NO se importarán activos todavía.');"
            >

                <input
                    type="hidden"
                    name="_csrf"
                    value="<?= impE(csrf_token()) ?>"
                >

                <input
                    type="hidden"
                    name="accion"
                    value="confirmar_empleados"
                >

                <input
                    type="hidden"
                    name="token"
                    value="<?= impE(
                        $tokenEmpleados
                    ) ?>"
                >

                <?php foreach ($candidatos as $cand): ?>
                    <?php if ($cand['nomina'] === ''): ?>
                        <?php $opciones = $coincidencias[$cand['clave']] ?? []; ?>
                        <div style="padding:12px; margin:12px 0; border:1px solid #aaa; border-radius:8px;">
                            <label for="resolver_<?= impE($cand['clave']) ?>">
                                <strong><?= impE($cand['nombre']) ?></strong>
                                — <?= impE($cand['area']) ?> (sin nómina)
                            </label>
                            <select id="resolver_<?= impE($cand['clave']) ?>"
                                name="vincular[<?= impE($cand['clave']) ?>]" required
                                style="display:block; width:100%; padding:8px; margin-top:8px;">
                                <option value="">Seleccionar acción para este responsable...</option>
                                <?php foreach ($opciones as $op): ?>
                                    <option value="id:<?= (int)$op['id'] ?>">
                                        Reutilizar ID <?= (int)$op['id'] ?>:
                                        <?= impE($op['nombre']) ?>
                                        (<?= impE($op['numero_nomina'] ?? 'sin nómina') ?>)
                                    </option>
                                <?php endforeach; ?>
                                <?php if (!$opciones): ?>
                                    <option value="nuevo">Crear nuevo empleado SIN nómina</option>
                                <?php endif; ?>
                            </select>
                            <small>Confirma la identidad; dos empleados pueden tener el mismo nombre.</small>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
                <button type="submit">
                    ✅ Confirmar responsables y catálogos
                </button>

            </form>

        <?php elseif ($puedeCrear): ?>
            <p style="color:#166534">Responsables confirmados para este lote.
                Puedes continuar con la importación si no hay errores bloqueantes.</p>
        <?php else: ?>
            <p style="color:#9a3412">No se permite el alta: revisa
                datos contradictorios o incompletos.</p>
        <?php endif; ?>

        <!-- ==================================
             IMPORTAR ACTIVOS
        =================================== -->

        <h3>
            2. Importar activos a PostgreSQL
        </h3>

        <?php if (
            !$erroresBienes &&
            !$erroresRelaciones &&
            $candidatos
        ): ?>

            <p>
                Los empleados y sus áreas coinciden
                con PostgreSQL. Al confirmar,
                se guardarán los bienes de este Excel.
            </p>

            <form
                method="post"
                onsubmit="return confirm('¿Confirmas que revisaste los datos y deseas IMPORTAR los activos a PostgreSQL?');"
            >

                <input
                    type="hidden"
                    name="_csrf"
                    value="<?= impE(csrf_token()) ?>"
                >

                <input
                    type="hidden"
                    name="accion"
                    value="confirmar_activos"
                >

                <input
                    type="hidden"
                    name="token"
                    value="<?= impE(
                        $tokenActivos
                    ) ?>"
                >

                <?php if ($avisosBienes): ?>
                    <label style="display:block; margin:14px 0; padding:12px; border:1px solid #d97706;">
                        <input type="checkbox" name="aceptar_pendientes" value="1" required>
                        Comprendo que <?= count($avisosBienes) ?> advertencias
                        corresponden a costos, estados o fechas pendientes.
                        Se guardarán como NULL sin suponer ni repartir valores;
                        la información original se conservará en observaciones.
                    </label>
                <?php endif; ?>

                <button
                    type="submit"
                    style="
                        background:#166534;
                        color:white;
                        padding:12px 18px;
                        border:0;
                        border-radius:8px;
                        font-weight:bold;
                        cursor:pointer;
                    "
                >
                    📦 Importar
                    <?= count($datos['bienes']) ?>
                    activos a PostgreSQL
                </button>

            </form>

        <?php else: ?>

            <p style="color:#9a3412">
                No se puede importar todavía.
                Corrige las advertencias o registra
                primero los empleados y analiza
                nuevamente el Excel.
            </p>

        <?php endif; ?>

        <p>
            <strong>Nota:</strong>
            la prevención automática de repetición
            identifica el mismo archivo XLSX y la
            misma fila. Un archivo modificado o
            un activo registrado manualmente
            requiere comprobación adicional.
        </p>

    <?php endif; ?>

</section>

<?php

require_once __DIR__ . '/../includes/footer.php';

?>
