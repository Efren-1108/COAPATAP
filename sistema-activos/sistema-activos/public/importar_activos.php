
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
        $t === 'ESTADO'
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
        'I', 'INSERVIBLE' => 'INSERVIBLE',
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

function impAnalizar(array $filas): array
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

        $valor = static fn(
            string $campo
        ): string => trim(
            (string) (
                $fila[
                    $mapa[$campo] ?? ''
                ] ?? ''
            )
        );

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
            $t === 'ENTREGO' ||
            str_contains($t, 'DESCRIPCION')
        ) {
            $mapa = null;
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

            'fecha_original' => $valor('fecha'),
            'fecha' => impFecha(
                $valor('fecha')
            ),

            'factura' => $valor('factura'),

            'costo_original' => $valor('costo'),
            'costo' => impCosto(
                $valor('costo')
            ),

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
    }

    unset($b);

    return [
        'bienes' => $bienes,
        'bloques' => $bloques
    ];
}

// ==========================================
// AGRUPAR EMPLEADOS POR NÓMINA
// ==========================================

function impEmpleadosCandidatos(
    array $bloques
): array {
    $grupos = [];

    foreach ($bloques as $b) {

        $n = trim($b['nomina']);

        if ($n === '') {
            continue;
        }

        if (!isset($grupos[$n])) {
            $grupos[$n] = [
                'nomina' => $n,
                'nombre' => $b['nombre'],
                'cargo' => $b['cargo'],
                'area' => $b['area'],
                'adscripcion' => $b['adscripcion'],
                'bienes' => 0,
                'problemas' => []
            ];
        }

        $grupos[$n]['bienes'] +=
            $b['cantidad'];

        foreach (
            [
                'nombre',
                'cargo',
                'area',
                'adscripcion'
            ] as $campo
        ) {
            if (
                $grupos[$n][$campo] === '' &&
                $b[$campo] !== ''
            ) {
                $grupos[$n][$campo] =
                    $b[$campo];

            } elseif (
                $b[$campo] !== '' &&
                $grupos[$n][$campo] !== '' &&
                impN($b[$campo]) !==
                impN($grupos[$n][$campo])
            ) {
                $grupos[$n]['problemas'][] =
                    'Valores distintos para '
                    . $campo;
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
            $a = impAnalizar(
                impFilasHoja(
                    $zip,
                    $ruta,
                    $textos
                )
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
        $empleados[
            (string) $e['numero_nomina']
        ] = $e;
    }

    return $empleados;
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
    array $usuario
): array {
    if (!$candidatos) {
        throw new RuntimeException(
            'No hay empleados identificados.'
        );
    }

    // Validar antes de modificar PostgreSQL.

    foreach ($candidatos as $e) {

        if (
            !preg_match(
                '/^[1-9][0-9]{0,8}$/',
                (string) $e['nomina']
            ) ||
            !empty($e['problemas'])
        ) {
            throw new RuntimeException(
                'Nómina inválida o contradictoria: '
                . ($e['nomina'] ?? '')
            );
        }

        foreach (
            [
                'nombre' => 200,
                'cargo' => 150,
                'area' => 150,
                'adscripcion' => 150
            ] as $campo => $limite
        ) {
            $s = (string) (
                $e[$campo] ?? ''
            );

            $largo = function_exists('mb_strlen')
                ? mb_strlen($s, 'UTF-8')
                : strlen($s);

            if (
                $s === '' ||
                $largo > $limite
            ) {
                throw new RuntimeException(
                    'Dato faltante o demasiado largo '
                    . '(' . $campo . ') en nómina '
                    . $e['nomina']
                );
            }
        }
    }

    $pdo->beginTransaction();

    try {

        $creados = 0;
        $existentes = 0;
        $areas = 0;
        $ads = 0;

        foreach ($candidatos as $e) {

            // ADSCRIPCIÓN

            $antes = (int) $pdo->query(
                'SELECT COUNT(*)
                 FROM adscripciones'
            )->fetchColumn();

            $adsId = impEncontrarOcrearAds(
                $pdo,
                $e['adscripcion']
            );

            $ads +=
                (int) $pdo->query(
                    'SELECT COUNT(*)
                     FROM adscripciones'
                )->fetchColumn()
                - $antes;

            // ÁREA

            $antes = (int) $pdo->query(
                'SELECT COUNT(*)
                 FROM areas'
            )->fetchColumn();

            $areaId = impEncontrarOcrearArea(
                $pdo,
                $e['area'],
                $adsId
            );

            $areas +=
                (int) $pdo->query(
                    'SELECT COUNT(*)
                     FROM areas'
                )->fetchColumn()
                - $antes;

            // BUSCAR EMPLEADO POR NÓMINA

            $st = $pdo->prepare(
                'SELECT id, nombre, area_id
                 FROM empleados
                 WHERE numero_nomina = :n
                 FOR UPDATE'
            );

            $st->execute([
                ':n' => (int) $e['nomina']
            ]);

            $actual = $st->fetch(
                PDO::FETCH_ASSOC
            );

            if ($actual) {

                if (
                    impN($actual['nombre']) !==
                        impN($e['nombre']) ||
                    (int) $actual['area_id'] !==
                        $areaId
                ) {
                    throw new RuntimeException(
                        'Nómina ' . $e['nomina']
                        . ' ya existe con nombre '
                        . 'o área distintos.'
                    );
                }

                $existentes++;
                continue;
            }

            // CREAR EMPLEADO NUEVO

            $st = $pdo->prepare(
                'INSERT INTO empleados
                    (numero_nomina, nombre, cargo, area_id)
                 VALUES
                    (:n, :nom, :c, :a)
                 RETURNING id'
            );

            $st->execute([
                ':n' => (int) $e['nomina'],
                ':nom' => $e['nombre'],
                ':c' => $e['cargo'],
                ':a' => $areaId
            ]);

            $nuevoId = (int)
                $st->fetchColumn();

            // REGISTRAR ACTIVIDAD

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
                        'empleados',
                        'INSERT',
                        :id,
                        :d,
                        :u,
                        :uid,
                        :aid
                    )"
            );

            $log->execute([
                ':id' => $nuevoId,
                ':d' =>
                    'Empleado creado desde Excel: '
                    . $e['nombre']
                    . ' (nómina '
                    . $e['nomina']
                    . ')',
                ':u' =>
                    $usuario['nombre_usuario'],
                ':uid' =>
                    (int) $usuario['id'],
                ':aid' => $areaId
            ]);

            $creados++;
        }

        $pdo->commit();

        return compact(
            'creados',
            'existentes',
            'areas',
            'ads'
        );

    } catch (Throwable $ex) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $ex;
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

        // NÓMINA

        if (
            $f < 1 ||
            !preg_match(
                '/^[1-9][0-9]{0,8}$/',
                (string) (
                    $b['nomina'] ?? ''
                )
            )
        ) {
            $errores[] =
                $pref . 'nómina inválida.';
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

        // ESTADO FÍSICO

        if (!in_array(
            $b['estado'] ?? null,
            [
                'BUENO',
                'NUEVO',
                'REGULAR',
                'INSERVIBLE'
            ],
            true
        )) {
            $errores[] =
                $pref
                . 'estado físico sin reconocer.';
        }

        // COSTO

        $costo = $b['costo'] ?? null;

        if (
            $costo === null ||
            !is_numeric($costo) ||
            (float) $costo < 0 ||
            (float) $costo > 9999999999.99
        ) {
            $errores[] =
                $pref . 'costo inválido.';
        }

        // FECHA

        $fecha = $b['fecha'] ?? null;

        if (
            $fecha !== null &&
            $fecha !== ''
        ) {
            $d = is_string($fecha)
                ? DateTimeImmutable::createFromFormat(
                    '!Y-m-d',
                    $fecha
                )
                : false;

            if (
                !$d ||
                $d->format('Y-m-d') !== $fecha ||
                $fecha > date('Y-m-d')
            ) {
                $errores[] =
                    $pref . 'fecha inválida.';
            }
        }

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

// ==========================================
// VERIFICAR EMPLEADOS, ÁREAS Y ADSCRIPCIONES
// ==========================================

function impRevisarRelaciones(
    PDO $pdo,
    array $bienes
): array {
    $st = $pdo->prepare(
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

    $cache = [];
    $errores = [];

    foreach ($bienes as $b) {

        $nomina = (string) $b['nomina'];

        if (!array_key_exists(
            $nomina,
            $cache
        )) {
            $st->execute([
                ':n' => (int) $nomina
            ]);

            $cache[$nomina] = $st->fetch(
                PDO::FETCH_ASSOC
            ) ?: null;
        }

        $e = $cache[$nomina];

        if (!$e) {

            $errores[] =
                'Nómina ' . $nomina
                . ': empleado no registrado '
                . 'o sin área/adscripción.';

        } elseif (
            impN($e['area_nombre']) !==
                impN($b['area']) ||
            impN($e['adscripcion_nombre']) !==
                impN($b['adscripcion'])
        ) {

            $errores[] =
                'Fila ' . $b['fila']
                . ': área o adscripción distinta '
                . 'de la nómina '
                . $nomina . '.';
        }

        if (count($errores) >= 30) {
            break;
        }
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
    array $usuario
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

    // VERIFICAR ESTRUCTURA

    impRevisarColumna($pdo);

    // VERIFICAR EMPLEADOS Y ÁREAS

    $errores = impRevisarRelaciones(
        $pdo,
        $bienes
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

            $nomina = (string) $b['nomina'];

            // BUSCAR EMPLEADO

            if (!isset($empleados[$nomina])) {

                $buscarEmpleado->execute([
                    ':n' => (int) $nomina
                ]);

                $empleados[$nomina] =
                    $buscarEmpleado->fetch(
                        PDO::FETCH_ASSOC
                    ) ?: null;
            }

            $emp = $empleados[$nomina];

            // VALIDAR ASIGNACIÓN

            if (
                !$emp ||
                impN($emp['area_nombre']) !==
                    impN($b['area']) ||
                impN($emp['adscripcion_nombre']) !==
                    impN($b['adscripcion'])
            ) {
                throw new RuntimeException(
                    'Asignación incorrecta: '
                    . 'nómina ' . $nomina
                    . ', fila ' . $b['fila']
                );
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
                    $b['fecha'] ?: null,

                ':fac' =>
                    trim(
                        (string) $b['factura']
                    ) ?: null,

                ':cos' =>
                    $b['costo'],

                ':obs' =>
                    trim(
                        (string) $b['observaciones']
                    ) ?: null,

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
                    . ', nómina: ' . $nomina,

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

$tokenActivos = '';
$tokenEmpleados = '';

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

            // VALIDACIONES

            $erroresBienes = impValidarBienes(
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

                'bienes' => $datos['bienes']
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
                    $usuario
                );

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
                    . 'Los activos todavía no '
                    . 'se importaron. Analiza '
                    . 'nuevamente el Excel '
                    . 'para continuar.';

            // ==================================
            // IMPORTAR ACTIVOS
            // ==================================

            } else {

                $r = impGuardarActivos(
                    db(),
                    $lote,
                    $usuario
                );

                // IMPEDIR REUTILIZAR CONFIRMACIÓN

                unset($_SESSION[$clave]);

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
                ]
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
            Empleados identificados por nómina
        </h3>

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

                        $bd = $empleadosBD[
                            $c['nomina']
                        ] ?? null;

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
                                    $c['nomina']
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
                                    $b['nomina']
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
            Revisa los once campos de cada bien
            y las advertencias.
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
                            $b['nomina'] === '' ||
                            !isset(
                                $candidatos[
                                    $b['nomina']
                                ]
                            )
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

                        if (
                            $b['costo'] === null
                        ) {
                            $alertas[] =
                                'Costo inválido';
                        }

                        if (
                            $b['fecha'] !== null &&
                            str_starts_with(
                                $b['fecha'],
                                'INVALIDA:'
                            )
                        ) {
                            $alertas[] =
                                'Fecha inválida';
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
                                <?= impE($b['nomina']) ?>
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
                                <?= impE(
                                    $b['costo']
                                    ?? $b['costo_original']
                                ) ?>
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

        <!-- ==================================
             REGISTRAR EMPLEADOS
        =================================== -->

        <h3>
            1. Registrar empleados, áreas y
            adscripciones faltantes
        </h3>

        <?php if ($puedeCrear): ?>

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

                <button type="submit">
                    ✅ Confirmar alta de empleados
                    y catálogos
                </button>

            </form>

        <?php else: ?>

            <p style="color:#9a3412">
                No se permite el alta:
                revisa los datos contradictorios
                o incompletos.
            </p>

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
