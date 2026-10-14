<?php
/**
 * API: Gestión de usuarios
 * Acceso: solo superusuario (GET lista, CRUD, reset_password)
 *
 * Métodos:
 *   GET    ?id=5             → detalle de un usuario
 *   GET    ?q=texto          → listar/buscar
 *   POST   { ... }           → crear usuario
 *   POST   ?id=5 {accion:reset_password, password} → resetear contraseña
 *   PUT    ?id=5 { ... }     → actualizar usuario
 *   DELETE ?id=5             → eliminar usuario
 *
 * Cambios respecto a la versión anterior:
 *   - [FIX]    POST valida unicidad de nombre_usuario (antes daba error crudo de BD).
 *   - [FIX]    PUT ahora SÍ actualiza nombre_usuario (antes lo ignoraba).
 *   - [FIX]    PUT valida que rol_id exista y esté activo.
 *   - [FIX]    Validación de area_id (existe y está activa).
 *   - [FIX]    Superusuario NO puede tener area_id (se fuerza a NULL).
 *   - [FIX]    DELETE no permite eliminar al usuario actual ni al último superusuario.
 *   - [MEJORA] reset_password acepta id en el body (sigue aceptando en query por compatibilidad).
 *   - [MEJORA] Validaciones centralizadas en validarUsuario().
 *   - [MEJORA] Validación de nombre_usuario sin espacios.
 *   - [MEJORA] Log de auditoría en INSERT/UPDATE/DELETE/reset_password.
 *   - [MEJORA] Respuestas normalizadas (ids como int, estado como bool).
 *   - [MEJORA] POST crea con password_hash; PUT ignora password (solo reset_password lo cambia).
 *   - [FIX]    Detección defensiva: si la columna usuarios.numero_nomina no existe,
 *              se ignora sin romper.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$input  = json_input();
$accion = $input['accion'] ?? null;
$q      = isset($_GET['q']) ? trim((string)$_GET['q']) : null;

$current = require_super_csrf();
$esSuper = true;

try {
    $pdo = db();

    // Detectar si la columna numero_nomina existe en usuarios (una sola vez)
    $tieneNumeroNomina = columnaExiste($pdo, 'usuarios', 'numero_nomina');

    switch ($method) {
        // ============================================================
        // GET — Detalle o listado
        // ============================================================
        case 'GET': {
            if ($id) {
                $stmt = $pdo->prepare("
                    SELECT u.id, u.nombre_completo, u.nombre_usuario,
                           " . ($tieneNumeroNomina ? "u.numero_nomina," : "NULL AS numero_nomina,") . "
                           u.correo, u.area_id, u.rol_id, u.estado,
                           u.ultimo_acceso, u.creado_en,
                           r.clave  AS rol_clave,  r.nombre AS rol_nombre,
                           a.clave  AS area_clave, a.nombre AS area_nombre
                    FROM usuarios u
                    JOIN roles r ON r.id = u.rol_id
                    LEFT JOIN areas a ON a.id = u.area_id
                    WHERE u.id = :id
                ");
                $stmt->execute([':id' => $id]);
                $row = $stmt->fetch();
                if (!$row) {
                    json_response(['ok' => false, 'error' => 'Usuario no encontrado'], 404);
                }
                $row = normalizarUsuario($row);
                json_response(['ok' => true, 'data' => $row]);
            }

            $sql = "
                SELECT u.id, u.nombre_completo, u.nombre_usuario,
                       " . ($tieneNumeroNomina ? "u.numero_nomina," : "NULL AS numero_nomina,") . "
                       u.correo, u.area_id, u.rol_id, u.estado,
                       u.ultimo_acceso, u.creado_en,
                       r.clave  AS rol_clave,  r.nombre AS rol_nombre,
                       a.clave  AS area_clave, a.nombre AS area_nombre
                FROM usuarios u
                JOIN roles r ON r.id = u.rol_id
                LEFT JOIN areas a ON a.id = u.area_id
            ";
            $params = [];
            if ($q) {
                $sql .= " WHERE LOWER(u.nombre_completo) LIKE :q
                          OR LOWER(u.nombre_usuario)  LIKE :q
                          OR LOWER(COALESCE(u.correo,'')) LIKE :q";
                $params[':q'] = '%' . strtolower($q) . '%';
                if ($tieneNumeroNomina) {
                    $sql .= " OR CAST(u.numero_nomina AS TEXT) LIKE :q";
                }
            }
            $sql .= " ORDER BY u.estado DESC, u.nombre_completo ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = array_map('normalizarUsuario', $stmt->fetchAll());
            json_response(['ok' => true, 'data' => $rows]);
            break;
        }

        // ============================================================
        // POST — Crear usuario o resetear contraseña
        // ============================================================
        case 'POST': {
            // ---- Reset password ----
            if ($accion === 'reset_password') {
                // Aceptar id desde body (nuevo) o desde query (compatibilidad)
                $resetId = isset($input['id']) && $input['id'] !== ''
                    ? (int)$input['id']
                    : ($id ?: 0);

                if ($resetId <= 0) {
                    json_response(['ok' => false, 'error' => 'ID de usuario requerido'], 400);
                }
                if ($resetId === (int)$current['id']) {
                    json_response(['ok' => false, 'error' => 'Usa Mi Perfil para cambiar tu propia clave'], 400);
                }

                $pwd = (string)($input['password'] ?? '');
                if (strlen($pwd) < 8) {
                    json_response(['ok' => false, 'error' => 'La contraseña debe tener al menos 8 caracteres'], 400);
                }

                // Verificar que el usuario exista
                $chk = $pdo->prepare("SELECT nombre_completo FROM usuarios WHERE id = :id");
                $chk->execute([':id' => $resetId]);
                $target = $chk->fetch();
                if (!$target) {
                    json_response(['ok' => false, 'error' => 'Usuario no encontrado'], 404);
                }

                $hash = password_hash($pwd, PASSWORD_DEFAULT);
                $upd = $pdo->prepare("UPDATE usuarios SET password_hash = :h WHERE id = :id");
                $upd->execute([':h' => $hash, ':id' => $resetId]);

                logUsuario($pdo, 'UPDATE', $resetId,
                    "Contraseña restablecida para {$target['nombre_completo']}",
                    $current);

                logger("usuarios.reset_password: id={$resetId} por usuario_id={$current['id']}");
                json_response(['ok' => true, 'message' => 'Contraseña restablecida.']);
            }

            // ---- Crear usuario ----
            $nombre  = trim((string)($input['nombre_completo'] ?? ''));
            $user    = trim((string)($input['nombre_usuario']  ?? ''));
            $nomina  = trim((string)($input['numero_nomina']   ?? ''));
            $correo  = trim((string)($input['correo']          ?? ''));
            $pwd     = (string)($input['password'] ?? '');
            $areaId  = isset($input['area_id']) && $input['area_id'] !== ''
                ? (int)$input['area_id']
                : null;
            $rolId   = isset($input['rol_id']) ? (int)$input['rol_id'] : 0;
            $estado  = !empty($input['estado']);

            // Validar rol
            $rol = validarRol($pdo, $rolId);
            if ($rol === null) {
                json_response(['ok' => false, 'error' => 'El rol seleccionado no existe o está inactivo'], 400);
            }

            // Validaciones generales
            $err = validarUsuario($nombre, $user, $rol['clave'], $nomina, $pwd, true);
            if ($err !== true) {
                json_response(['ok' => false, 'error' => $err], 400);
            }

            // Superusuario no tiene área
            if ($rol['clave'] === 'superusuario') {
                $areaId = null;
            } else {
                if ($areaId === null || !validarArea($pdo, $areaId)) {
                    json_response(['ok' => false, 'error' => 'El área seleccionada no existe o está inactiva'], 400);
                }
            }

            // Unicidad de nombre_usuario
            $chk = $pdo->prepare("SELECT id FROM usuarios WHERE LOWER(nombre_usuario) = LOWER(:u)");
            $chk->execute([':u' => $user]);
            if ($chk->fetch()) {
                json_response(['ok' => false, 'error' => 'Ese nombre de usuario ya existe'], 409);
            }

            // Unicidad de nómina (si aplica y la columna existe)
            if ($tieneNumeroNomina && $nomina !== '') {
                $chk = $pdo->prepare("SELECT id FROM usuarios WHERE numero_nomina = :n");
                $chk->execute([':n' => $nomina]);
                if ($chk->fetch()) {
                    json_response(['ok' => false, 'error' => 'Ese número de nómina ya está asignado a otro usuario'], 409);
                }
            }

            $hash = password_hash($pwd, PASSWORD_DEFAULT);

            // Insertar
            if ($tieneNumeroNomina) {
                $sql = "INSERT INTO usuarios
                            (nombre_completo, nombre_usuario, numero_nomina, correo,
                             password_hash, area_id, rol_id, estado)
                        VALUES
                            (:nom, :usr, :num, :cor, :pwd, :aid, :rol, :est)
                        RETURNING id";
                $params = [
                    ':nom' => $nombre,
                    ':usr' => $user,
                    ':num' => $nomina !== '' ? $nomina : null,
                    ':cor' => $correo !== '' ? $correo : null,
                    ':pwd' => $hash,
                    ':aid' => $areaId,
                    ':rol' => $rolId,
                    ':est' => $estado,
                ];
            } else {
                $sql = "INSERT INTO usuarios
                            (nombre_completo, nombre_usuario, correo,
                             password_hash, area_id, rol_id, estado)
                        VALUES
                            (:nom, :usr, :cor, :pwd, :aid, :rol, :est)
                        RETURNING id";
                $params = [
                    ':nom' => $nombre,
                    ':usr' => $user,
                    ':cor' => $correo !== '' ? $correo : null,
                    ':pwd' => $hash,
                    ':aid' => $areaId,
                    ':rol' => $rolId,
                    ':est' => $estado,
                ];
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $newId = (int)$stmt->fetchColumn();

            logUsuario($pdo, 'INSERT', $newId,
                "Usuario creado: {$nombre} (@{$user})",
                $current, $areaId);

            logger("usuarios.INSERT: id={$newId} usuario={$user} por usuario_id={$current['id']}");
            json_response(['ok' => true, 'id' => $newId, 'message' => 'Usuario creado'], 201);
            break;
        }

        // ============================================================
        // PUT — Actualizar usuario
        // ============================================================
        case 'PUT': {
            if (!$id) {
                json_response(['ok' => false, 'error' => 'ID requerido'], 400);
            }

            // Verificar que el usuario exista
            $chk = $pdo->prepare("SELECT id, nombre_usuario FROM usuarios WHERE id = :id");
            $chk->execute([':id' => $id]);
            $actual = $chk->fetch();
            if (!$actual) {
                json_response(['ok' => false, 'error' => 'Usuario no encontrado'], 404);
            }

            $nombre  = trim((string)($input['nombre_completo'] ?? ''));
            $user    = trim((string)($input['nombre_usuario']  ?? $actual['nombre_usuario']));
            $nomina  = trim((string)($input['numero_nomina']   ?? ''));
            $correo  = trim((string)($input['correo']          ?? ''));
            $areaId  = isset($input['area_id']) && $input['area_id'] !== ''
                ? (int)$input['area_id']
                : null;
            $rolId   = isset($input['rol_id']) ? (int)$input['rol_id'] : 0;
            $estado  = !empty($input['estado']);

            // Validar rol
            $rol = validarRol($pdo, $rolId);
            if ($rol === null) {
                json_response(['ok' => false, 'error' => 'El rol seleccionado no existe o está inactivo'], 400);
            }

            // Validaciones generales (sin password obligatoria en PUT)
            $err = validarUsuario($nombre, $user, $rol['clave'], $nomina, null, false);
            if ($err !== true) {
                json_response(['ok' => false, 'error' => $err], 400);
            }

            // Superusuario no tiene área
            if ($rol['clave'] === 'superusuario') {
                $areaId = null;
            } else {
                if ($areaId === null || !validarArea($pdo, $areaId)) {
                    json_response(['ok' => false, 'error' => 'El área seleccionada no existe o está inactiva'], 400);
                }
            }

            // Unicidad de nombre_usuario (excepto el mismo)
            $chk = $pdo->prepare("SELECT id FROM usuarios WHERE LOWER(nombre_usuario) = LOWER(:u) AND id <> :id");
            $chk->execute([':u' => $user, ':id' => $id]);
            if ($chk->fetch()) {
                json_response(['ok' => false, 'error' => 'Ese nombre de usuario ya existe'], 409);
            }

            // Unicidad de nómina (excepto el mismo)
            if ($tieneNumeroNomina && $nomina !== '') {
                $chk = $pdo->prepare("SELECT id FROM usuarios WHERE numero_nomina = :n AND id <> :id");
                $chk->execute([':n' => $nomina, ':id' => $id]);
                if ($chk->fetch()) {
                    json_response(['ok' => false, 'error' => 'Ese número de nómina ya está asignado a otro usuario'], 409);
                }
            }

            // Proteger al último superusuario activo
            if ($rol['clave'] !== 'superusuario') {
                if (esUltimoSuperusuario($pdo, $id)) {
                    json_response(['ok' => false, 'error' => 'No puedes quitar el rol de superusuario al último superusuario activo'], 400);
                }
            }
            if (!$estado && esUltimoSuperusuario($pdo, $id)) {
                json_response(['ok' => false, 'error' => 'No puedes desactivar al último superusuario activo'], 400);
            }

            // ---- UPDATE (ahora incluye nombre_usuario y numero_nomina) ----
            if ($tieneNumeroNomina) {
                $sql = "UPDATE usuarios SET
                            nombre_completo = :nom,
                            nombre_usuario  = :usr,
                            numero_nomina   = :num,
                            correo          = :cor,
                            area_id         = :aid,
                            rol_id          = :rol,
                            estado          = :est
                        WHERE id = :id";
                $params = [
                    ':nom' => $nombre,
                    ':usr' => $user,
                    ':num' => $nomina !== '' ? $nomina : null,
                    ':cor' => $correo !== '' ? $correo : null,
                    ':aid' => $areaId,
                    ':rol' => $rolId,
                    ':est' => $estado,
                    ':id'  => $id,
                ];
            } else {
                $sql = "UPDATE usuarios SET
                            nombre_completo = :nom,
                            nombre_usuario  = :usr,
                            correo          = :cor,
                            area_id         = :aid,
                            rol_id          = :rol,
                            estado          = :est
                        WHERE id = :id";
                $params = [
                    ':nom' => $nombre,
                    ':usr' => $user,
                    ':cor' => $correo !== '' ? $correo : null,
                    ':aid' => $areaId,
                    ':rol' => $rolId,
                    ':est' => $estado,
                    ':id'  => $id,
                ];
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            logUsuario($pdo, 'UPDATE', $id,
                "Usuario actualizado: {$nombre} (@{$user})",
                $current, $areaId);

            logger("usuarios.UPDATE: id={$id} por usuario_id={$current['id']}");
            json_response(['ok' => true, 'message' => 'Usuario actualizado']);
            break;
        }

        // ============================================================
        // DELETE — Eliminar usuario
        // ============================================================
        case 'DELETE': {
            if (!$id) {
                json_response(['ok' => false, 'error' => 'ID requerido'], 400);
            }

            // No eliminar al usuario actual
            if ($id === (int)$current['id']) {
                json_response(['ok' => false, 'error' => 'No puedes eliminar tu propio usuario'], 400);
            }

            // Verificar que exista
            $chk = $pdo->prepare("SELECT nombre_completo, nombre_usuario FROM usuarios WHERE id = :id");
            $chk->execute([':id' => $id]);
            $target = $chk->fetch();
            if (!$target) {
                json_response(['ok' => false, 'error' => 'Usuario no encontrado'], 404);
            }

            // No eliminar al último superusuario
            if (esUltimoSuperusuario($pdo, $id)) {
                json_response(['ok' => false, 'error' => 'No puedes eliminar al último superusuario del sistema'], 400);
            }

            $del = $pdo->prepare("DELETE FROM usuarios WHERE id = :id");
            $del->execute([':id' => $id]);

            logUsuario($pdo, 'DELETE', $id,
                "Usuario eliminado: {$target['nombre_completo']} (@{$target['nombre_usuario']})",
                $current);

            logger("usuarios.DELETE: id={$id} por usuario_id={$current['id']}");
            json_response(['ok' => true, 'message' => 'Usuario eliminado']);
            break;
        }

        default:
            json_response(['ok' => false, 'error' => 'Método no permitido'], 405);
    }

} catch (Throwable $e) {
    logger("usuarios error: " . $e->getMessage(), 'ERROR');
    json_response(['ok' => false, 'error' => 'Error: ' . $e->getMessage()], 500);
}

/* ============================================================
 * FUNCIONES AUXILIARES
 * ============================================================ */

/**
 * Valida los campos de un usuario.
 *
 * @param string      $nombre     Nombre completo
 * @param string      $user       Nombre de usuario
 * @param string      $rolClave   Clave del rol (superusuario | usuario | ...)
 * @param string      $nomina     Número de nómina (solo si no es super)
 * @param string|null $pwd        Contraseña (solo en POST)
 * @param bool        $esCreacion Si es true, exige password y nómina
 *
 * @return true|string
 */
function validarUsuario(string $nombre, string $user, string $rolClave, string $nomina, ?string $pwd, bool $esCreacion)
{
    if (strlen($nombre) < 3)  return 'El nombre completo es obligatorio (mín. 3 caracteres)';
    if (strlen($nombre) > 200) return 'El nombre completo no puede exceder 200 caracteres';

    if (strlen($user) < 3)  return 'El nombre de usuario es obligatorio (mín. 3 caracteres)';
    if (strlen($user) > 60) return 'El nombre de usuario no puede exceder 60 caracteres';
    if (preg_match('/\s/', $user)) {
        return 'El nombre de usuario no puede contener espacios';
    }
    if (!preg_match('/^[a-zA-Z0-9_.\-]+$/', $user)) {
        return 'El nombre de usuario solo puede contener letras, números, guion, guion bajo y punto';
    }

    // Nómina obligatoria solo si NO es superusuario
    if ($rolClave !== 'superusuario') {
        if ($nomina === '') {
            return 'La nómina es obligatoria para usuarios que no son superusuario';
        }
        if (!ctype_digit($nomina)) {
            return 'La nómina debe ser numérica';
        }
    }

    if ($esCreacion) {
        if ($pwd === null || strlen($pwd) < 8) {
            return 'La contraseña es obligatoria (mín. 8 caracteres)';
        }
    }

    return true;
}

/**
 * Valida que un rol exista y esté activo.
 * Devuelve ['id'=>int, 'clave'=>string] o null.
 */
function validarRol(PDO $pdo, int $rolId): ?array
{
    if ($rolId <= 0) return null;
    $stmt = $pdo->prepare("SELECT id, clave FROM roles WHERE id = :id AND activo = TRUE LIMIT 1");
    $stmt->execute([':id' => $rolId]);
    $row = $stmt->fetch();
    return $row ? ['id' => (int)$row['id'], 'clave' => $row['clave']] : null;
}

/**
 * Valida que un área exista y esté activa.
 */
function validarArea(PDO $pdo, int $areaId): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM areas WHERE id = :id AND activo = TRUE LIMIT 1");
    $stmt->execute([':id' => $areaId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Verifica si un usuario es el último superusuario activo.
 */
function esUltimoSuperusuario(PDO $pdo, int $usuarioId): bool
{
    // ¿El usuario objetivo es superusuario activo?
    $stmt = $pdo->prepare("
        SELECT 1
        FROM usuarios u
        JOIN roles r ON r.id = u.rol_id
        WHERE u.id = :id AND r.clave = 'superusuario' AND u.estado = TRUE
        LIMIT 1
    ");
    $stmt->execute([':id' => $usuarioId]);
    if (!$stmt->fetchColumn()) {
        return false; // No es super activo, no aplica
    }

    // ¿Cuántos super activos hay en total?
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM usuarios u
        JOIN roles r ON r.id = u.rol_id
        WHERE r.clave = 'superusuario' AND u.estado = TRUE
    ");
    return ((int)$stmt->fetchColumn()) <= 1;
}

/**
 * Comprueba si una columna existe en una tabla.
 */
function columnaExiste(PDO $pdo, string $tabla, string $columna): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.columns
        WHERE table_name = :t AND column_name = :c
        LIMIT 1
    ");
    $stmt->execute([':t' => $tabla, ':c' => $columna]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Normaliza tipos de un usuario para la respuesta JSON.
 */
function normalizarUsuario(array $u): array
{
    $u['id']     = (int)$u['id'];
    $u['rol_id'] = (int)$u['rol_id'];
    $u['area_id']= $u['area_id'] !== null ? (int)$u['area_id'] : null;
    $u['estado'] = (bool)$u['estado'];
    return $u;
}

/**
 * Registra una acción en log_actividades.
 */
function logUsuario(PDO $pdo, string $accion, int $registroId, string $detalle, array $current, ?int $areaId = null): void
{
    $stmt = $pdo->prepare("
        INSERT INTO log_actividades
            (tabla, accion, registro_id, detalle, usuario, usuario_id, area_id)
        VALUES
            ('usuarios', :acc, :rid, :det, :usr, :uid, :aid)
    ");
    $stmt->execute([
        ':acc' => $accion,
        ':rid' => $registroId,
        ':det' => $detalle,
        ':usr' => $current['nombre_usuario'],
        ':uid' => $current['id'],
        ':aid' => $areaId,
    ]);
}