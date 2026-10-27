<?php
/**
 * COAPATAP: utilidades para leer celdas combinadas de XLSX.
 * Archivo auxiliar para includes/importacion_celdas.php
 * NO importa ni modifica datos en PostgreSQL.
 *
 * Requiere las funciones existentes del importador:
 *   impDocumento(), impZip(), impCosto().
 */

declare(strict_types=1);

function impNumeroColumna(string $letras): int
{
    $n = 0;
    foreach (str_split(strtoupper($letras)) as $ch) {
        $v = ord($ch) - 64;
        if ($v < 1 || $v > 26) {
            return 0;
        }
        $n = $n * 26 + $v;
    }
    return $n;
}

function impLetrasColumna(int $numero): string
{
    $texto = '';
    while ($numero > 0) {
        $numero--;
        $texto = chr(65 + ($numero % 26)) . $texto;
        $numero = intdiv($numero, 26);
    }
    return $texto;
}

/**
 * Índice por celda: A1 => información del rango combinado.
 * No inventa valores ni modifica el Excel original.
 *
 * @return array<string,array<string,int|string>>
 */
function impFusionesHoja(ZipArchive $zip, string $ruta): array
{
    $xpath = new DOMXPath(impDocumento(impZip($zip, $ruta)));
    $resultado = [];
    $procesadas = 0;

    foreach (
        $xpath->query('//*[local-name()="mergeCells"]/*[local-name()="mergeCell"]') ?: []
        as $nodo
    ) {
        if (!$nodo instanceof DOMElement) {
            continue;
        }

        $ref = strtoupper(trim($nodo->getAttribute('ref')));
        if (!preg_match('/^([A-Z]{1,3})([0-9]+):([A-Z]{1,3})([0-9]+)$/', $ref, $m)) {
            continue;
        }

        $inicioCol = impNumeroColumna($m[1]);
        $inicioFila = (int) $m[2];
        $finCol = impNumeroColumna($m[3]);
        $finFila = (int) $m[4];

        if (
            $inicioCol < 1 || $inicioFila < 1 ||
            $finCol < $inicioCol || $finFila < $inicioFila
        ) {
            continue;
        }

        $tamano = ($finCol - $inicioCol + 1) * ($finFila - $inicioFila + 1);
        $procesadas += $tamano;
        if ($tamano > 50000 || $procesadas > 200000) {
            throw new RuntimeException('Demasiadas celdas combinadas para analizar.');
        }

        $info = [
            'ref' => $ref,
            'inicioCol' => $inicioCol,
            'finCol' => $finCol,
            'inicioFila' => $inicioFila,
            'finFila' => $finFila,
            'columnaAncla' => $m[1],
        ];
        for ($f = $inicioFila; $f <= $finFila; $f++) {
            for ($c = $inicioCol; $c <= $finCol; $c++) {
                $resultado[impLetrasColumna($c) . $f] = $info;
            }
        }
    }

    return $resultado;
}

/**
 * Lee una celda; si está combinada VERTICALMENTE, utiliza el
 * valor del inicio. NO copia costos: podrían ser totales de un grupo.
 * Tampoco copia horizontalmente datos de un campo a otro.
 */
function impValorConFusion(
    array $filas,
    array $mapa,
    array $fusiones,
    int $numeroFila,
    string $campo
): string {
    $columna = $mapa[$campo] ?? null;
    if (!is_string($columna)) {
        return '';
    }

    $v = trim((string) ($filas[$numeroFila][$columna] ?? ''));
    if ($v !== '' || $campo === 'costo') {
        return $v;
    }

    $fusion = $fusiones[$columna . $numeroFila] ?? null;
    if (
        !$fusion ||
        $fusion['inicioCol'] !== $fusion['finCol'] ||
        $fusion['inicioFila'] >= $numeroFila
    ) {
        return '';
    }

    return trim((string) (
        $filas[$fusion['inicioFila']][$fusion['columnaAncla']] ?? ''
    ));
}

/**
 * Devuelve los metadatos de una celda COSTO verticalmente combinada.
 * El total se muestra una vez; NO se replica en los demás activos.
 */
function impDatosCostoCompartido(
    array $filas,
    array $mapa,
    array $fusiones,
    int $numeroFila
): ?array {
    $col = $mapa['costo'] ?? null;
    if (!is_string($col)) {
        return null;
    }
    $fusion = $fusiones[$col . $numeroFila] ?? null;
    if (
        !$fusion ||
        $fusion['inicioCol'] !== $fusion['finCol'] ||
        $fusion['finFila'] <= $fusion['inicioFila']
    ) {
        return null;
    }

    $valorPrincipal = (string) (
        $filas[$fusion['inicioFila']][$fusion['columnaAncla']] ?? ''
    );

    return [
        'rango' => $fusion['ref'],
        'valor_total' => impCosto($valorPrincipal),
        'fila_principal' => $fusion['inicioFila'],
        'es_principal' => $numeroFila === $fusion['inicioFila'],
    ];
}
