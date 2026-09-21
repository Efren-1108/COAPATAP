<?php
/**
 * Seeder: crea el usuario administrador inicial.
 *
 * Úsalo si tu PostgreSQL no tiene la extensión pgcrypto
 * y no puedes ejecutar database/migracion_auth.sql tal cual.
 *
 * Ejecución:
 *   php tools/seed_admin.php
 *
 * Credenciales por defecto: admin / admin123
 * Cámbialas al primer ingreso desde el módulo Usuarios.
 */

require_once __DIR__ . '/../config/config.php';

$username = 'admin';
$password = 'admin123';
$nombre   = 'Administrador General';

try {
    $pdo = db();

    // 1) Asegurar que exista el rol 'superusuario'.
    $rolId = $pdo->query("SELECT id FROM roles WHERE clave = 'superusuario'")->fetchColumn();
    if (!$rolId) {
        $pdo->exec("INSERT INTO roles (clave, nombre, descripcion) VALUES ('superusuario', 'Superusuario', 'Acceso total al sistema.')");
        $rolId = $pdo->lastInsertId('roles_id_seq');
        echo "[OK] Rol 'superusuario' creado (id={$rolId})\n";
    } else {
        echo "[--] Rol 'superusuario' ya existía (id={$rolId})\n";
    }

    // 2) Verificar si el usuario ya existe.
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE nombre_usuario = :u");
    $stmt->execute([':u' => $username]);
    if ($stmt->fetchColumn()) {
        echo "[--] El usuario '{$username}' ya existe. Nada que hacer.\n";
        exit(0);
    }

    // 3) Insertar con password_hash (bcrypt por defecto).
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("
        INSERT INTO usuarios (nombre_completo, nombre_usuario, password_hash, area_id, rol_id, estado)
        VALUES (:nom, :usr, :pwd, NULL, :rol, TRUE)
        RETURNING id
    ");
    $stmt->execute([
        ':nom' => $nombre,
        ':usr' => $username,
        ':pwd' => $hash,
        ':rol' => $rolId,
    ]);
    $newId = (int)$stmt->fetchColumn();

    echo "[OK] Usuario '{$username}' creado (id={$newId})\n";
    echo "     Contraseña: {$password}\n";
    echo "     ¡Cámbiala al primer ingreso!\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[ERROR] " . $e->getMessage() . "\n");
    exit(1);
}