<?php
require_once __DIR__ . '/../config/config.php';

try {
    $pdo = db();

    echo "Iniciando corrección de estructura de usuarios... \n";

    // 1. Agregar columna numero_nomina a la tabla usuarios
    // Usamos ALTER TABLE. Si la columna ya existe, PostgreSQL dará error, por eso usamos un bloque try o verificamos.
    try {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS numero_nomina VARCHAR(50)");
        echo "✓ Columna 'numero_nomina' verificada/agregada a la tabla 'usuarios'.\n";
    } catch (Exception $e) {
        echo "⚠️ Nota: " . $e->getMessage() . "\n";
    }

    // 2. Sincronizar nóminas existentes
    // Para los usuarios que ya existen, intentamos copiar el numero_nomina desde la tabla empleados
    // usando el nombre_usuario como puente (asumiendo que nombre_usuario = numero_nomina en el sistema actual)
    $sqlSync = "
        UPDATE usuarios u
        SET numero_nomina = e.numero_nomina
        FROM empleados e
        WHERE u.nombre_usuario = CAST(e.numero_nomina AS VARCHAR)
        AND u.numero_nomina IS NULL
    ";
    $affected = $pdo->exec($sqlSync);
    echo "✓ Sincronizados {$affected} usuarios con sus números de nómina desde la tabla empleados.\n";

    echo "\n🚀 Corrección de estructura completada con éxito.\n";

} catch (Exception $e) {
    echo "❌ ERROR en la corrección: " . $e->getMessage() . "\n";
    exit(1);
}