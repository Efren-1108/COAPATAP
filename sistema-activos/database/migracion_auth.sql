-- =========================================================
-- Sistema de Control de Activos Fijos
-- Módulo de Autenticación, Usuarios, Roles y Permisos
-- =========================================================
--
-- Este script es ADITIVO: no modifica las tablas originales,
-- solo agrega tablas y columnas nuevas. Es seguro ejecutarlo
-- sobre una base de datos existente.
--
-- Antes de ejecutar, asegúrate de que PostgreSQL tenga la
-- extensión pgcrypto (para generar hashes bcrypt desde SQL):
--   CREATE EXTENSION IF NOT EXISTS pgcrypto;
--
-- Si tu PostgreSQL no permite CREATE EXTENSION, usa en su
-- lugar el seeder PHP: tools/seed_admin.php (que genera el
-- hash con password_hash() desde PHP).
-- =========================================================

-- Extensión para crypt() / gen_salt() (bcrypt)
CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- =========================================================
-- Tabla: roles
-- =========================================================
CREATE TABLE IF NOT EXISTS roles (
    id              SERIAL          PRIMARY KEY,
    clave           VARCHAR(30)     NOT NULL UNIQUE,
    nombre          VARCHAR(100)    NOT NULL,
    descripcion     TEXT,
    activo          BOOLEAN         NOT NULL DEFAULT TRUE,
    creado_en       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
);

COMMENT ON TABLE roles IS 'Roles del sistema (superusuario, usuario, etc.)';

-- =========================================================
-- Tabla: areas
-- =========================================================
CREATE TABLE IF NOT EXISTS areas (
    id              SERIAL          PRIMARY KEY,
    clave           VARCHAR(30)     NOT NULL UNIQUE,
    nombre          VARCHAR(150)    NOT NULL,
    descripcion     TEXT,
    activo          BOOLEAN         NOT NULL DEFAULT TRUE,
    creado_en       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
);

COMMENT ON TABLE areas IS 'Áreas / departamentos. Un usuario normal pertenece a una sola área.';

-- =========================================================
-- Tabla: usuarios
-- =========================================================
CREATE TABLE IF NOT EXISTS usuarios (
    id              SERIAL          PRIMARY KEY,
    nombre_completo VARCHAR(200)    NOT NULL,
    nombre_usuario  VARCHAR(60)     NOT NULL UNIQUE,
    correo          VARCHAR(150),
    password_hash   VARCHAR(255)    NOT NULL,
    area_id         INTEGER         REFERENCES areas(id)  ON DELETE SET NULL,
    rol_id          INTEGER         NOT NULL REFERENCES roles(id),
    estado          BOOLEAN         NOT NULL DEFAULT TRUE,
    ultimo_acceso   TIMESTAMP,
    creado_en       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_nombre_usuario_len CHECK (char_length(nombre_usuario) >= 3),
    CONSTRAINT chk_correo_basico CHECK (correo IS NULL OR correo ~* '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$')
);

CREATE INDEX idx_usuarios_usuario ON usuarios(nombre_usuario);
CREATE INDEX idx_usuarios_area    ON usuarios(area_id);
CREATE INDEX idx_usuarios_rol     ON usuarios(rol_id);

COMMENT ON TABLE usuarios IS 'Usuarios que operan el sistema (distintos de los empleados/resguardantes)';
COMMENT ON COLUMN usuarios.password_hash IS 'Hash bcrypt generado con password_hash() de PHP';
COMMENT ON COLUMN usuarios.area_id IS 'NULL permitido solo si rol_id = superusuario';

-- =========================================================
-- Trigger de timestamp en usuarios (reutiliza la función existente)
-- =========================================================
DROP TRIGGER IF EXISTS trg_usuarios_ts ON usuarios;
CREATE TRIGGER trg_usuarios_ts
BEFORE UPDATE ON usuarios
FOR EACH ROW
EXECUTE FUNCTION fn_actualizar_timestamp();

-- =========================================================
-- Modificaciones a tablas existentes
-- =========================================================

-- empleados: agregar area_id
ALTER TABLE empleados
    ADD COLUMN IF NOT EXISTS area_id INTEGER REFERENCES areas(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_empleados_area ON empleados(area_id);

-- activos: agregar area_id y usuario_id
ALTER TABLE activos
    ADD COLUMN IF NOT EXISTS area_id    INTEGER REFERENCES areas(id)    ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS usuario_id INTEGER REFERENCES usuarios(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_activos_area    ON activos(area_id);
CREATE INDEX IF NOT EXISTS idx_activos_usuario ON activos(usuario_id);

-- log_actividades: agregar usuario_id y area_id (se conserva la columna usuario VARCHAR)
ALTER TABLE log_actividades
    ADD COLUMN IF NOT EXISTS usuario_id INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS area_id    INTEGER REFERENCES areas(id)    ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_log_usuario ON log_actividades(usuario_id);
CREATE INDEX IF NOT EXISTS idx_log_area    ON log_actividades(area_id);

-- =========================================================
-- Datos iniciales
-- =========================================================

-- Áreas: se crea 'general' como área por defecto para los
-- registros que ya existían antes de este módulo.
INSERT INTO areas (clave, nombre, descripcion) VALUES
    ('general', 'General', 'Área por defecto para registros previos al módulo de autenticación')
ON CONFLICT (clave) DO NOTHING;

-- Roles: superusuario y usuario normal.
INSERT INTO roles (clave, nombre, descripcion) VALUES
    ('superusuario', 'Superusuario', 'Acceso total al sistema. Puede ver y editar registros de cualquier área.'),
    ('usuario',      'Usuario Normal', 'Solo puede ver y editar registros de su propia área.')
ON CONFLICT (clave) DO NOTHING;

-- Backfill: los registros existentes quedan asignados al área 'general'.
UPDATE empleados SET area_id = (SELECT id FROM areas WHERE clave='general') WHERE area_id IS NULL;
UPDATE activos   SET area_id = (SELECT id FROM areas WHERE clave='general') WHERE area_id IS NULL;

-- Superusuario inicial: admin / admin123
-- IMPORTANTE: cambia la contraseña al primer ingreso.
INSERT INTO usuarios (nombre_completo, nombre_usuario, password_hash, area_id, rol_id, estado)
SELECT
    'Administrador General',
    'admin',
    crypt('admin123', gen_salt('bf')),
    NULL,
    (SELECT id FROM roles WHERE clave='superusuario'),
    TRUE
WHERE NOT EXISTS (SELECT 1 FROM usuarios WHERE nombre_usuario = 'admin');

-- =========================================================
-- Mensaje final
-- =========================================================
SELECT 'Migración de autenticación completada. Usuario inicial: admin / admin123' AS mensaje;