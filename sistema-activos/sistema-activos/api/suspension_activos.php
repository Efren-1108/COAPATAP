<?php
/**
 * COAPATAP — Suspender / reactivar activos fijos (sin eliminarlos).
 * POST api/suspension_activos.php?id=123
 * JSON: { "accion": "suspender", "motivo": "Equipo en revisión" }
 *    o { "accion": "reactivar" }
 *
 * Requiere migración sql/01_agregar_suspension_activos.sql.
 * No modifica empleado_id, area_id, costo, estado_fisico, ni clave_importacion.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    json_response(['ok' => false, 'error' => 'Método no permitido'], 405);
}

$usuario = require_auth_csrf();
if (!has_permission('activos')) {
    json_response(['ok' => false, 'error' => 'No tienes permiso para administrar activos.'], 403);
}

$textoId = trim((string) ($_GET['id'] ?? ''));
if (!ctype_digit($textoId) || (int) $textoId < 1 || (float) $textoId > 2147483647) {
    json_response(['ok' => false, 'error' => 'ID de activo inválido.'], 400);
}
$id = (int) $textoId;

$entrada = json_input();
$accion = trim((string) ($entrada['accion'] ?? ''));
if (!in_array($accion, ['suspender', 'reactivar'], true)) {
    json_response(['ok' => false, 'error' => 'Acción no permitida.'], 400);
}

$motivo = trim((string) ($entrada['motivo'] ?? ''));
if ($accion === 'suspender') {
    $longitud = function_exists('mb_strlen')
        ? mb_strlen($motivo, 'UTF-8')
        : strlen($motivo);
    if ($longitud < 1 || $longitud > 500) {
        json_response(['ok' => false, 'error' => 'Indica un motivo de suspensión de 1 a 500 caracteres.'], 400);
    }
}

$pdo = null;
try {
    $pdo = db();
    $pdo->beginTransaction();

    // Bloquear fila para evitar dos cambios simultáneos de situación.
    $consulta = $pdo->prepare(
        'SELECT id, descripcion, num_inventario, area_id, suspendido
         FROM public.activos WHERE id = :id FOR UPDATE'
    );
    $consulta->execute([':id' => $id]);
    $activo = $consulta->fetch(PDO::FETCH_ASSOC);
    if (!$activo) {
        $pdo->rollBack();
        json_response(['ok' => false, 'error' => 'El activo ya no existe.'], 404);
    }

    // La administración ordinaria solo puede modificar activos de su propia área.
    if (empty($usuario['es_superusuario']) &&
        ($usuario['area_id'] === null ||
         (int) $activo['area_id'] !== (int) $usuario['area_id'])) {
        $pdo->rollBack();
        json_response(['ok' => false, 'error' => 'Este activo no pertenece a tu área.'], 403);
    }

    $suspendido = in_array($activo['suspendido'], [true, 't', '1', 1], true);
    if (($accion === 'suspender' && $suspendido) ||
        ($accion === 'reactivar' && !$suspendido)) {
        $pdo->rollBack();
        json_response([
            'ok' => false,
            'error' => $suspendido
                ? 'Este activo ya está suspendido. Actualiza la lista.'
                : 'Este activo ya está vigente. Actualiza la lista.'
        ], 409);
    }

    if ($accion === 'suspender') {
        $sql = 'UPDATE public.activos
                SET suspendido = TRUE,
                    motivo_suspension = :motivo,
                    suspendido_en = NOW()
                WHERE id = :id';
        $guardar = $pdo->prepare($sql);
        $guardar->execute([':motivo' => $motivo, ':id' => $id]);
        $detalle = 'Activo suspendido: ' . $activo['descripcion']
            . ' (inventario ' . $activo['num_inventario'] . '). Motivo: ' . $motivo;
        $mensaje = 'Activo suspendido correctamente. No fue eliminado.';
    } else {
        // Se limpia el estado vigente; los movimientos quedan en log_actividades.
        $sql = 'UPDATE public.activos
                SET suspendido = FALSE,
                    motivo_suspension = NULL,
                    suspendido_en = NULL
                WHERE id = :id';
        $guardar = $pdo->prepare($sql);
        $guardar->execute([':id' => $id]);
        $detalle = 'Activo reactivado: ' . $activo['descripcion']
            . ' (inventario ' . $activo['num_inventario'] . ').';
        $mensaje = 'Activo reactivado correctamente.';
    }

    // Auditamos con el esquema de log que ya utiliza COAPATAP.
    $log = $pdo->prepare(
        'INSERT INTO public.log_actividades
         (tabla, accion, registro_id, detalle, usuario, usuario_id, area_id)
         VALUES (\'activos\', \'UPDATE\', :id, :detalle, :usuario, :usuario_id, :area_id)'
    );
    $log->execute([
        ':id' => $id,
        ':detalle' => $detalle,
        ':usuario' => $usuario['nombre_usuario'],
        ':usuario_id' => (int) $usuario['id'],
        ':area_id' => $activo['area_id'] !== null ? (int) $activo['area_id'] : null
    ]);

    $pdo->commit();
    logger("Cambio de situación: id={$id}, accion={$accion}, usuario_id={$usuario['id']}");
    json_response(['ok' => true, 'message' => $mensaje]);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logger('Error en suspensión de activos: ' . $e->getMessage(), 'ERROR');
    json_response([
        'ok' => false,
        'error' => 'No se pudo actualizar la situación del activo. Verifica que ejecutaste el SQL de instalación.'
    ], 500);
}
