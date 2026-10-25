
<?php
/**
 * CONFIGURACIÓN DE IMÁGENES DE RESGUARDOS
 *
 * Funciones:
 * - Subir imágenes institucionales.
 * - Mostrar la imagen activa.
 * - Guardar un historial de versiones.
 * - Restaurar imágenes anteriores.
 * - Restringir cambios al superusuario.
 *
 * Requiere la tabla:
 * public.imagenes_resguardo
 */

// ==========================================
// 1. SEGURIDAD
// ==========================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

// Solo puede entrar el superusuario.

$user = require_super();

$page_title = 'Configuración de impresión';

// ==========================================
// 2. TIPOS DE IMÁGENES
// ==========================================

$tipos = [

    'coapatap' => [
        'titulo' => 'Logotipo de COAPATAP',
        'ubicacion' => 'Superior izquierdo'
    ],

    'tapachula' => [
        'titulo' => 'Logotipo de Tapachula',
        'ubicacion' => 'Superior derecho'
    ],

    'inferior' => [
        'titulo' => 'Imagen inferior',
        'ubicacion' => 'Debajo de las firmas'
    ]

];

// ==========================================
// 3. CARPETA DE ALMACENAMIENTO
// ==========================================

// Las imágenes se guardarán con nombres únicos.
// Nunca reemplazaremos directamente una anterior.

$carpetaRelativa =
    'assets/img/resguardos/historial/';

$carpetaFisica =
    dirname(__DIR__) . '/' . $carpetaRelativa;

// Tamaño máximo permitido: 5 MB.

$limiteBytes = 5 * 1024 * 1024;

// ==========================================
// 4. MENSAJES
// ==========================================

$error = '';

$mensaje =
    $_SESSION['mensaje_imagenes_resguardo'] ?? '';

unset($_SESSION['mensaje_imagenes_resguardo']);

// ==========================================
// 5. REDIRECCIÓN DESPUÉS DE GUARDAR
// ==========================================

function irAConfiguracion(): void
{
    header(
        'Location: '
        . base_url()
        . '/public/configuracion_impresion.php'
    );

    exit;
}

// ==========================================
// 6. OBTENER URL SEGURA DE UNA IMAGEN
// ==========================================

function urlImagenResguardo(string $ruta): string
{
    // Únicamente se aceptan las rutas que
    // genera este módulo.

    if (!preg_match(
        '~^assets/img/resguardos/historial/'
        . '[a-f0-9]{32}\.(png|jpg|webp)$~',
        $ruta
    )) {
        return '';
    }

    // Comprobar que el archivo todavía existe.

    if (!is_file(dirname(__DIR__) . '/' . $ruta)) {
        return '';
    }

    return base_url() . '/' . $ruta;
}

// ==========================================
// 7. PROCESAR FORMULARIOS
// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        // ==================================
        // 7.1 PROTECCIÓN CSRF
        // ==================================

        if (!csrf_validate($_POST['_csrf'] ?? null)) {

            throw new RuntimeException(
                'La sesión expiró. '
                . 'Recarga la página e inténtalo de nuevo.'
            );
        }

        $pdo = db();

        $accion = (string) ($_POST['accion'] ?? '');

        // ==================================
        // 7.2 SUBIR UNA IMAGEN
        // ==================================

        if ($accion === 'subir') {

            $tipo = (string) ($_POST['tipo'] ?? '');

            if (!array_key_exists($tipo, $tipos)) {

                throw new RuntimeException(
                    'La posición de la imagen no es válida.'
                );
            }

            // Periodo opcional.

            $periodo = trim(
                (string) ($_POST['periodo'] ?? '')
            );

            if (strlen($periodo) > 30) {

                throw new RuntimeException(
                    'El periodo debe tener '
                    . 'como máximo 30 caracteres.'
                );
            }

            // Verificar archivo recibido.

            if (
                !isset($_FILES['imagen']) ||
                !is_array($_FILES['imagen'])
            ) {

                throw new RuntimeException(
                    'Selecciona un archivo de imagen.'
                );
            }

            $archivo = $_FILES['imagen'];

            if ($archivo['error'] !== UPLOAD_ERR_OK) {

                throw new RuntimeException(
                    'No se pudo recibir el archivo. '
                    . 'Comprueba su tamaño y '
                    . 'la configuración de PHP.'
                );
            }

            $temporal = $archivo['tmp_name'];

            // Solo aceptar archivos subidos por HTTP.

            if (!is_uploaded_file($temporal)) {

                throw new RuntimeException(
                    'El archivo recibido no es válido.'
                );
            }

            // ==================================
            // 7.3 VALIDAR TAMAÑO
            // ==================================

            $tamano = filesize($temporal);

            if (
                $tamano === false ||
                $tamano < 1 ||
                $tamano > $limiteBytes
            ) {

                throw new RuntimeException(
                    'La imagen debe pesar '
                    . 'entre 1 byte y 5 MB.'
                );
            }

            // ==================================
            // 7.4 VALIDAR FORMATO REAL
            // ==================================

            $mimeReal = (new finfo(
                FILEINFO_MIME_TYPE
            ))->file($temporal);

            $extensiones = [
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                'image/webp' => 'webp'
            ];

            if (!isset($extensiones[$mimeReal])) {

                throw new RuntimeException(
                    'Solo se permiten imágenes '
                    . 'PNG, JPG o WebP.'
                );
            }

            // Comprobar estructura de imagen.

            $datosImagen = @getimagesize($temporal);

            if (
                !$datosImagen ||
                ($datosImagen['mime'] ?? '') !== $mimeReal
            ) {

                throw new RuntimeException(
                    'El archivo no contiene '
                    . 'una imagen válida.'
                );
            }

            // Evitar dimensiones excesivas.

            if (
                $datosImagen[0] < 1 ||
                $datosImagen[1] < 1 ||
                $datosImagen[0] > 6000 ||
                $datosImagen[1] > 6000
            ) {

                throw new RuntimeException(
                    'La imagen debe tener dimensiones '
                    . 'válidas y no superar '
                    . '6000 píxeles por lado.'
                );
            }

            // ==================================
            // 7.5 PREPARAR CARPETA
            // ==================================

            if (
                !is_dir($carpetaFisica) &&
                !mkdir(
                    $carpetaFisica,
                    0755,
                    true
                ) &&
                !is_dir($carpetaFisica)
            ) {

                throw new RuntimeException(
                    'No se pudo crear '
                    . 'la carpeta de imágenes.'
                );
            }

            if (!is_writable($carpetaFisica)) {

                throw new RuntimeException(
                    'La carpeta de imágenes '
                    . 'no permite escribir.'
                );
            }

            // ==================================
            // 7.6 GENERAR NOMBRE ÚNICO
            // ==================================

            $nombreNuevo =
                bin2hex(random_bytes(16))
                . '.'
                . $extensiones[$mimeReal];

            $rutaRelativa =
                $carpetaRelativa . $nombreNuevo;

            $rutaFisica =
                $carpetaFisica . $nombreNuevo;

            // Huella digital de la imagen.

            $hash = hash_file(
                'sha256',
                $temporal
            );

            // Guardar el archivo en el servidor.

            if (
                $hash === false ||
                !move_uploaded_file(
                    $temporal,
                    $rutaFisica
                )
            ) {

                throw new RuntimeException(
                    'No se pudo guardar '
                    . 'la imagen en el servidor.'
                );
            }

            @chmod($rutaFisica, 0644);

            // ==================================
            // 7.7 GUARDAR EN POSTGRESQL
            // ==================================

            try {

                $pdo->beginTransaction();

                // Desactivar la versión actual.

                $sqlDesactivar = "
                    UPDATE public.imagenes_resguardo
                    SET
                        activa = FALSE,
                        desactivado_en =
                            CURRENT_TIMESTAMP
                    WHERE tipo = :tipo
                      AND activa = TRUE
                ";

                $stmt = $pdo->prepare(
                    $sqlDesactivar
                );

                $stmt->execute([
                    ':tipo' => $tipo
                ]);

                // Registrar la nueva imagen.

                $sqlGuardar = "
                    INSERT INTO public.imagenes_resguardo
                    (
                        tipo,
                        ruta_relativa,
                        nombre_original,
                        tipo_mime,
                        tamano_bytes,
                        sha256,
                        periodo,
                        activa,
                        usuario_id
                    )
                    VALUES
                    (
                        :tipo,
                        :ruta,
                        :nombre,
                        :mime,
                        :tamano,
                        :sha256,
                        :periodo,
                        TRUE,
                        :usuario_id
                    )
                ";

                $stmt = $pdo->prepare(
                    $sqlGuardar
                );

                $nombreOriginal = basename(
                    str_replace(
                        '\\',
                        '/',
                        (string) $archivo['name']
                    )
                );

                // La tabla permite 255 caracteres.

                $nombreOriginal = substr(
                    $nombreOriginal,
                    0,
                    255
                );

                $stmt->execute([

                    ':tipo' => $tipo,

                    ':ruta' => $rutaRelativa,

                    ':nombre' => $nombreOriginal,

                    ':mime' => $mimeReal,

                    ':tamano' => $tamano,

                    ':sha256' => $hash,

                    ':periodo' => $periodo !== ''
                        ? $periodo
                        : null,

                    ':usuario_id' =>
                        (int) $user['id']

                ]);

                $pdo->commit();

            } catch (Throwable $ex) {

                // Si falla PostgreSQL,
                // revertir los cambios en la tabla.

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                // Eliminar únicamente el archivo
                // nuevo que no pudo registrarse.

                @unlink($rutaFisica);

                throw $ex;
            }

            $_SESSION[
                'mensaje_imagenes_resguardo'
            ] = 'Imagen guardada y activada '
                . 'correctamente.';

            irAConfiguracion();

        // ==================================
        // 7.8 ACTIVAR IMAGEN DEL HISTORIAL
        // ==================================

        } elseif ($accion === 'activar') {

            $id = filter_var(
                $_POST['id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (!$id || $id < 1) {

                throw new RuntimeException(
                    'Selecciona una imagen '
                    . 'válida del historial.'
                );
            }

            $pdo->beginTransaction();

            try {

                // Buscar la imagen seleccionada.

                $sqlBuscar = "
                    SELECT
                        id,
                        tipo,
                        ruta_relativa
                    FROM public.imagenes_resguardo
                    WHERE id = :id
                    FOR UPDATE
                ";

                $stmt = $pdo->prepare($sqlBuscar);

                $stmt->execute([
                    ':id' => $id
                ]);

                $anterior = $stmt->fetch(
                    PDO::FETCH_ASSOC
                );

                if (
                    !$anterior ||
                    !array_key_exists(
                        $anterior['tipo'],
                        $tipos
                    )
                ) {

                    throw new RuntimeException(
                        'No se encontró esa imagen '
                        . 'en el historial.'
                    );
                }

                // Verificar que el archivo exista.

                if (
                    urlImagenResguardo(
                        (string) $anterior[
                            'ruta_relativa'
                        ]
                    ) === ''
                ) {

                    throw new RuntimeException(
                        'El archivo anterior '
                        . 'no está disponible '
                        . 'en el servidor.'
                    );
                }

                // Desactivar la imagen actual.

                $sqlDesactivar = "
                    UPDATE public.imagenes_resguardo
                    SET
                        activa = FALSE,
                        desactivado_en =
                            CURRENT_TIMESTAMP
                    WHERE tipo = :tipo
                      AND activa = TRUE
                ";

                $stmt = $pdo->prepare(
                    $sqlDesactivar
                );

                $stmt->execute([
                    ':tipo' => $anterior['tipo']
                ]);

                // Reactivar la imagen anterior.

                $sqlActivar = "
                    UPDATE public.imagenes_resguardo
                    SET
                        activa = TRUE,
                        desactivado_en = NULL
                    WHERE id = :id
                ";

                $stmt = $pdo->prepare(
                    $sqlActivar
                );

                $stmt->execute([
                    ':id' => $id
                ]);

                $pdo->commit();

            } catch (Throwable $ex) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $ex;
            }

            $_SESSION[
                'mensaje_imagenes_resguardo'
            ] = 'Se restauró la imagen seleccionada '
                . 'como activa.';

            irAConfiguracion();

        } else {

            throw new RuntimeException(
                'Operación desconocida.'
            );
        }

    } catch (Throwable $ex) {

        logger(
            'Configuración de imágenes: '
            . $ex->getMessage(),
            'ERROR'
        );

        $error =
            $ex instanceof RuntimeException
                ? $ex->getMessage()
                : 'Ocurrió un error al guardar '
                . 'el cambio. Revisa la tabla '
                . 'y el registro del servidor.';
    }
}

// ==========================================
// 8. CONSULTAR IMÁGENES GUARDADAS
// ==========================================

$historial = [
    'coapatap' => [],
    'tapachula' => [],
    'inferior' => []
];

try {

    $sql = "
        SELECT
            id,
            tipo,
            ruta_relativa,
            nombre_original,
            tipo_mime,
            tamano_bytes,
            periodo,
            activa,
            creado_en,
            desactivado_en
        FROM public.imagenes_resguardo
        ORDER BY
            tipo,
            creado_en DESC,
            id DESC
    ";

    $stmt = db()->query($sql);

    foreach (
        $stmt->fetchAll(PDO::FETCH_ASSOC)
        as $registro
    ) {

        if (isset($historial[$registro['tipo']])) {

            $historial[$registro['tipo']][] =
                $registro;
        }
    }

} catch (Throwable $ex) {

    logger(
        'Error al consultar imágenes: '
        . $ex->getMessage(),
        'ERROR'
    );

    $error =
        'No fue posible consultar las imágenes. '
        . 'Comprueba que ejecutaste el SQL '
        . 'del paso 1 en la base de datos correcta.';
}

// ==========================================
// 9. CARGAR ENCABEZADO
// ==========================================

require_once __DIR__ . '/../includes/header.php';

?>

<!-- ========================================
     10. ESTILOS DE CONFIGURACIÓN
========================================= -->

<style>

.config-imagenes {
    margin: 24px 0 40px;
}

.config-imagenes__intro {
    color: var(--c-muted, #64748b);
    max-width: 850px;
}

.config-imagenes__grid {
    display: grid;

    grid-template-columns:
        repeat(auto-fit, minmax(275px, 1fr));

    gap: 18px;
    margin: 22px 0;
}

.config-imagenes__tarjeta {
    background: var(--c-surface, #ffffff);

    border: 1px solid var(--c-border, #e2e8f0);
    border-radius: 12px;

    padding: 18px;
}

.config-imagenes__tarjeta h2 {
    margin: 0 0 4px;
    font-size: 1.12rem;
}

.config-imagenes__tarjeta p {
    margin: 0 0 12px;
    color: var(--c-muted, #64748b);
}

.config-imagenes__preview {
    height: 145px;

    border: 1px dashed #a3b0c4;
    border-radius: 8px;

    background: #ffffff;

    display: flex;
    justify-content: center;
    align-items: center;

    overflow: hidden;

    padding: 12px;
    margin-bottom: 14px;

    color: #475569;
    text-align: center;
}

.config-imagenes__preview img {
    max-width: 100%;
    max-height: 100%;

    width: auto;
    height: auto;

    object-fit: contain;
}

.config-imagenes__campo {
    display: block;

    margin: 12px 0;
    font-weight: 600;
}

.config-imagenes__campo input {
    display: block;

    margin-top: 6px;

    width: 100%;
    padding: 9px;

    border: 1px solid #cbd5e1;
    border-radius: 7px;

    background: #ffffff;
    color: #111827;
}

.config-imagenes__boton {
    border: 0;
    border-radius: 8px;

    background: #2563eb;
    color: #ffffff;

    font-weight: 700;
    padding: 10px 14px;

    cursor: pointer;
}

.config-imagenes__boton--secundario {
    background: #334155;
}

.config-imagenes__boton:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.config-imagenes__aviso {
    padding: 12px 14px;
    border-radius: 8px;

    margin: 15px 0;

    background: #eff6ff;
    border: 1px solid #93c5fd;
}

.config-imagenes__aviso--error {
    background: #fff1f2;
    border-color: #fda4af;
}

.config-imagenes__historial {
    background: var(--c-surface, #ffffff);

    border: 1px solid var(--c-border, #e2e8f0);
    border-radius: 12px;

    padding: 18px;
    margin: 18px 0;
}

.config-imagenes__historial h3 {
    margin: 0 0 10px;
}

.config-imagenes__version {
    display: flex;
    align-items: center;

    gap: 14px;
    flex-wrap: wrap;

    padding: 12px 0;
    border-top: 1px solid #e2e8f0;
}

.config-imagenes__miniatura {
    background: #ffffff;

    border: 1px solid #e2e8f0;
    border-radius: 7px;

    width: 92px;
    height: 65px;

    display: flex;
    align-items: center;
    justify-content: center;

    padding: 5px;
}

.config-imagenes__miniatura img {
    max-width: 100%;
    max-height: 100%;

    object-fit: contain;
}

.config-imagenes__datos {
    flex: 1;
    min-width: 170px;

    overflow-wrap: anywhere;
}

.config-imagenes__datos small {
    display: block;
    color: var(--c-muted, #64748b);
}

.config-imagenes__activo {
    font-weight: 700;
    color: #15803d;
}

/* Evitar imprimir esta pantalla */

@media print {

    .config-imagenes {
        display: none !important;
    }

}

</style>

<!-- ========================================
     11. PANTALLA DE CONFIGURACIÓN
========================================= -->

<section class="config-imagenes">

    <h1>🖼️ Configuración de impresión</h1>

    <p class="config-imagenes__intro">
        Sube una imagen solamente cuando necesites
        cambiarla. El sistema conservará las
        versiones anteriores para que puedas
        reutilizarlas cuando sea necesario.
        Esta sección es exclusiva del superusuario.
    </p>

    <!-- =====================================
         MENSAJES
    ====================================== -->

    <?php if ($mensaje): ?>

        <div
            class="config-imagenes__aviso"
            role="status"
        >
            <?= e($mensaje) ?>
        </div>

    <?php endif; ?>

    <?php if ($error): ?>

        <div
            class="config-imagenes__aviso
                   config-imagenes__aviso--error"
            role="alert"
        >
            <?= e($error) ?>
        </div>

    <?php endif; ?>

    <!-- =====================================
         TARJETAS DE IMÁGENES
    ====================================== -->

    <div class="config-imagenes__grid">

        <?php foreach (
            $tipos as $tipo => $informacion
        ): ?>

            <?php

            // Buscar la versión activa.

            $actual = null;

            foreach ($historial[$tipo] as $imagen) {

                if ($imagen['activa']) {

                    $actual = $imagen;
                    break;
                }
            }

            // Obtener su URL.

            $urlActual = $actual
                ? urlImagenResguardo(
                    (string) $actual['ruta_relativa']
                )
                : '';

            ?>

            <div class="config-imagenes__tarjeta">

                <h2>
                    <?= e($informacion['titulo']) ?>
                </h2>

                <p>
                    <?= e($informacion['ubicacion']) ?>
                </p>

                <!-- VISTA PREVIA -->

                <div class="config-imagenes__preview">

                    <?php if ($urlActual): ?>

                        <img
                            src="<?= e($urlActual) ?>"
                            alt="<?= e(
                                $informacion['titulo']
                            ) ?>"
                        >

                    <?php else: ?>

                        <span>

                            <?= $actual
                                ? 'El archivo activo no '
                                  . 'se encuentra en el servidor'
                                : 'Todavía no hay imagen activa'
                            ?>

                        </span>

                    <?php endif; ?>

                </div>

                <!-- DATOS DE LA IMAGEN ACTUAL -->

                <?php if ($actual): ?>

                    <p>

                        <strong>
                            Imagen actual:
                        </strong>

                        <?= e(
                            $actual['nombre_original']
                        ) ?>

                        <?php if ($actual['periodo']): ?>

                            · <?= e(
                                $actual['periodo']
                            ) ?>

                        <?php endif; ?>

                    </p>

                <?php endif; ?>

                <!-- FORMULARIO PARA SUBIR -->

                <form
                    method="post"
                    enctype="multipart/form-data"
                >

                    <input
                        type="hidden"
                        name="_csrf"
                        value="<?= e(csrf_token()) ?>"
                    >

                    <input
                        type="hidden"
                        name="accion"
                        value="subir"
                    >

                    <input
                        type="hidden"
                        name="tipo"
                        value="<?= e($tipo) ?>"
                    >

                    <label class="config-imagenes__campo">

                        Nueva imagen
                        (PNG, JPG o WebP; máximo 5 MB)

                        <input
                            type="file"
                            name="imagen"
                            accept="image/png,image/jpeg,image/webp"
                            required
                        >

                    </label>

                    <label class="config-imagenes__campo">

                        Año o periodo (opcional)

                        <input
                            type="text"
                            name="periodo"
                            maxlength="30"
                            placeholder="Ej. 2026-2027"
                        >

                    </label>

                    <button
                        type="submit"
                        class="config-imagenes__boton"
                    >
                        💾 Guardar y activar imagen
                    </button>

                </form>

            </div>

        <?php endforeach; ?>

    </div>

    <!-- =====================================
         12. HISTORIAL DE IMÁGENES
    ====================================== -->

    <h2>📚 Historial de imágenes</h2>

    <?php foreach (
        $tipos as $tipo => $informacion
    ): ?>

        <section class="config-imagenes__historial">

            <h3>
                <?= e($informacion['titulo']) ?>
            </h3>

            <?php if (!$historial[$tipo]): ?>

                <p>
                    Aún no hay versiones guardadas.
                </p>

            <?php endif; ?>

            <?php foreach (
                $historial[$tipo] as $imagen
            ): ?>

                <?php

                $urlImagen = urlImagenResguardo(
                    (string) $imagen['ruta_relativa']
                );

                ?>

                <div class="config-imagenes__version">

                    <!-- MINIATURA -->

                    <div class="config-imagenes__miniatura">

                        <?php if ($urlImagen): ?>

                            <img
                                src="<?= e($urlImagen) ?>"
                                alt="Vista previa"
                            >

                        <?php else: ?>

                            <span>Sin archivo</span>

                        <?php endif; ?>

                    </div>

                    <!-- INFORMACIÓN -->

                    <div class="config-imagenes__datos">

                        <strong>
                            <?= e(
                                $imagen['nombre_original']
                            ) ?>
                        </strong>

                        <small>
                            Periodo:
                            <?= e(
                                $imagen['periodo']
                                ?: 'Sin especificar'
                            ) ?>
                        </small>

                        <small>
                            Registrada:
                            <?= e(
                                $imagen['creado_en']
                            ) ?>
                        </small>

                        <?php if ($imagen['activa']): ?>

                            <span class="config-imagenes__activo">
                                ● Imagen activa
                            </span>

                        <?php endif; ?>

                    </div>

                    <!-- ACTIVAR VERSIÓN ANTERIOR -->

                    <?php if (
                        !$imagen['activa'] &&
                        $urlImagen
                    ): ?>

                        <form
                            method="post"
                            onsubmit="return confirm(
                                '¿Deseas volver a utilizar '
                                + 'esta imagen en los '
                                + 'próximos resguardos?'
                            );"
                        >

                            <input
                                type="hidden"
                                name="_csrf"
                                value="<?= e(
                                    csrf_token()
                                ) ?>"
                            >

                            <input
                                type="hidden"
                                name="accion"
                                value="activar"
                            >

                            <input
                                type="hidden"
                                name="id"
                                value="<?= (int) $imagen['id'] ?>"
                            >

                            <button
                                type="submit"
                                class="config-imagenes__boton
                                config-imagenes__boton--secundario"
                            >
                                ↩️ Usar esta imagen
                            </button>

                        </form>

                    <?php endif; ?>

                </div>

            <?php endforeach; ?>

        </section>

    <?php endforeach; ?>

    <p>
        <a href="vista_previa_resguardo.php">
            ← Volver a Impresión de resguardos
        </a>
    </p>

</section>

<?php

// ==========================================
// 13. CARGAR PIE DE PÁGINA
// ==========================================

require_once __DIR__ . '/../includes/footer.php';

?>
