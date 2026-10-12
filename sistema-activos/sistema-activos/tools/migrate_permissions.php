<?php
require_once __DIR__ . '/../config/config.php';

try {
    $pdo = db();

    echo "Iniciando migración de permisos... \n";

    // 1. Crear tabla de módulos
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS modulos (
            id SERIAL PRIMARY KEY,
            clave VARCHAR(50) UNIQUE NOT NULL,
            nombre VARCHAR(100) NOT NULL,
            descripcion TEXT
        );
    ");
    echo "✓ Tabla 'modulos' verificada.\n";

    // 2. Crear tabla de permisos por rol
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS rol_permisos (
            rol_id INTEGER REFERENCES roles(id) ON DELETE CASCADE,
            modulo_id INTEGER REFERENCES modulos(id) ON DELETE CASCADE,
            concedido BOOLEAN DEFAULT true,
            PRIMARY KEY (rol_id, modulo_id)
        );
    ");
    echo "✓ Tabla 'rol_permisos' verificada.\n";

    // 3. Insertar módulos básicos
    $modulos = [
        ['consulta', 'Consulta de Activos', 'Ver y buscar activos'],
        ['empleados', 'Gestión de Empleados', 'Administrar el personal'],
        ['activos', 'Gestión de Activos', 'Administrar los bienes'],
        ['usuarios', 'Gestión de Usuarios', 'Control de cuentas y accesos'],
        ['areas', 'Gestión de Áreas', 'Administrar departamentos'],
        ['roles', 'Gestión de Roles', 'Control de permisos']
    ];

    $stmtMod = $pdo->prepare("INSERT INTO modulos (clave, nombre, descripcion) VALUES (?, ?, ?) ON CONFLICT (clave) DO NOTHING");
    foreach ($modulos as $m) {
        $stmtMod->execute($m);
    }
    echo "✓ Módulos insertados.\n";

    // 4. Asignar permisos al superusuario (acceso a todo)
    // Primero buscamos el ID del rol 'superusuario'
    $stmtRol = $pdo->prepare("SELECT id FROM roles WHERE clave = 'superusuario' LIMIT 1");
    $stmtRol->execute();
    $superRol = $stmtRol->fetch();

    if ($superRol) {
        $superId = $superRol['id'];
        $stmtModIds = $pdo->query("SELECT id FROM modulos");
        $modIds = $stmtModIds->fetchAll(PDO::FETCH_COLUMN);

        $stmtPerm = $pdo->prepare("INSERT INTO rol_permisos (rol_id, modulo_id, concedido) VALUES (?, ?, true) ON CONFLICT DO NOTHING");
        foreach ($modIds as $mid) {
            $stmtPerm->execute([$superId, $mid]);
        }
        echo "✓ Permisos totales asignados al Superusuario.\n";
    } else {
        echo "⚠️ No se encontró el rol 'superusuario'.\n";
    }

    echo "\n🚀 Migración completada con éxito.\n";

} catch (Exception $e) {
    echo "❌ ERROR en la migración: " . $e->getMessage() . "\n";
    exit(1);
}