-- =========================================================
-- Sistema de Control de Activos Fijos
-- Script de migración para PostgreSQL
-- =========================================================

-- Crear extensiones (si no existen)
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- =========================================================
-- Tabla: empleados
-- =========================================================
DROP TABLE IF EXISTS log_actividades CASCADE;
DROP TABLE IF EXISTS activos CASCADE;
DROP TABLE IF EXISTS empleados CASCADE;

CREATE TABLE empleados (
    id              SERIAL          PRIMARY KEY,
    numero_nomina   INTEGER         NOT NULL UNIQUE,
    nombre          VARCHAR(200)    NOT NULL,
    cargo           VARCHAR(150)    NOT NULL,
    creado_en       TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    actualizado_en  TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_nomina_positiva CHECK (numero_nomina > 0)
);

CREATE INDEX idx_empleados_nomina ON empleados(numero_nomina);
CREATE INDEX idx_empleados_nombre ON empleados(LOWER(nombre));

COMMENT ON TABLE empleados IS 'Resguardantes / personal de la escuela';
COMMENT ON COLUMN empleados.numero_nomina IS 'Número de nómina único e identificador principal';

-- =========================================================
-- Tabla: activos
-- =========================================================
CREATE TABLE activos (
    id              SERIAL          PRIMARY KEY,
    descripcion     VARCHAR(200)    NOT NULL,
    num_inventario  VARCHAR(50)     NOT NULL UNIQUE,
    marca           VARCHAR(100),
    modelo          VARCHAR(100),
    serie           VARCHAR(100),
    material        VARCHAR(100),
    fecha_adq       DATE,
    factura         VARCHAR(100),
    costo           NUMERIC(12, 2)  DEFAULT 0,
    observaciones   TEXT,
    empleado_id     INTEGER,
    ruta_imagen     VARCHAR(255),
    creado_en       TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    actualizado_en  TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_empleado
        FOREIGN KEY (empleado_id)
        REFERENCES empleados(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    CONSTRAINT chk_costo_no_negativo CHECK (costo IS NULL OR costo >= 0),
    CONSTRAINT chk_fecha_valida CHECK (fecha_adq IS NULL OR fecha_adq <= CURRENT_DATE)
);

CREATE INDEX idx_activos_inventario ON activos(num_inventario);
CREATE INDEX idx_activos_empleado   ON activos(empleado_id);
CREATE INDEX idx_activos_desc       ON activos(LOWER(descripcion));

COMMENT ON TABLE activos IS 'Bienes / activos fijos de la escuela';
COMMENT ON COLUMN activos.observaciones IS 'Notas; el campo empleado_id es la relación formal de asignación';

-- =========================================================
-- Tabla: log_actividades
-- =========================================================
CREATE TABLE log_actividades (
    id              SERIAL          PRIMARY KEY,
    tabla           VARCHAR(50)     NOT NULL,
    accion          VARCHAR(20)     NOT NULL,
    registro_id     INTEGER,
    detalle         TEXT,
    usuario         VARCHAR(100)    DEFAULT 'sistema',
    creado_en       TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_accion_valida CHECK (accion IN ('INSERT','UPDATE','DELETE','SEARCH'))
);

CREATE INDEX idx_log_tabla   ON log_actividades(tabla);
CREATE INDEX idx_log_fecha   ON log_actividades(creado_en DESC);

COMMENT ON TABLE log_actividades IS 'Bitácora de cambios en el sistema';

-- =========================================================
-- Trigger: actualizar timestamp en empleados
-- =========================================================
CREATE OR REPLACE FUNCTION fn_actualizar_timestamp()
RETURNS TRIGGER AS $$
BEGIN
    NEW.actualizado_en = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_empleados_ts ON empleados;
CREATE TRIGGER trg_empleados_ts
BEFORE UPDATE ON empleados
FOR EACH ROW
EXECUTE FUNCTION fn_actualizar_timestamp();

DROP TRIGGER IF EXISTS trg_activos_ts ON activos;
CREATE TRIGGER trg_activos_ts
BEFORE UPDATE ON activos
FOR EACH ROW
EXECUTE FUNCTION fn_actualizar_timestamp();

-- =========================================================
-- Trigger: bitácora automática
-- =========================================================
CREATE OR REPLACE FUNCTION fn_log_actividades()
RETURNS TRIGGER AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        INSERT INTO log_actividades (tabla, accion, registro_id, detalle)
        VALUES (TG_TABLE_NAME, 'INSERT', NEW.id, 'Registro creado');
        RETURN NEW;
    ELSIF TG_OP = 'UPDATE' THEN
        INSERT INTO log_actividades (tabla, accion, registro_id, detalle)
        VALUES (TG_TABLE_NAME, 'UPDATE', NEW.id, 'Registro actualizado');
        RETURN NEW;
    ELSIF TG_OP = 'DELETE' THEN
        INSERT INTO log_actividades (tabla, accion, registro_id, detalle)
        VALUES (TG_TABLE_NAME, 'DELETE', OLD.id, 'Registro eliminado: ' || COALESCE(OLD.nombre, OLD.descripcion, ''));
        RETURN OLD;
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_log_empleados ON empleados;
CREATE TRIGGER trg_log_empleados
AFTER INSERT OR UPDATE OR DELETE ON empleados
FOR EACH ROW
EXECUTE FUNCTION fn_log_actividades();

DROP TRIGGER IF EXISTS trg_log_activos ON activos;
CREATE TRIGGER trg_log_activos
AFTER INSERT OR UPDATE OR DELETE ON activos
FOR EACH ROW
EXECUTE FUNCTION fn_log_actividades();

-- =========================================================
-- Datos de ejemplo (opcional, comentar si no se desea)
-- =========================================================
INSERT INTO empleados (numero_nomina, nombre, cargo) VALUES
    (1001, 'ING. JOSE AGUSTIN PAZ MONTALVO', 'COORDINADOR INFORMATICO'),
    (1002, 'LIC. MARIA FERNANDA LOPEZ HERNANDEZ', 'AUXILIAR INFORMATICO'),
    (1003, 'MTRO. CARLOS ALBERTO RUIZ SANCHEZ', 'SUBDIRECTOR ADMINISTRATIVO'),
    (1004, 'ING. LAURA PATRICIA MENDEZ GOMEZ', 'JEFE DE MANTENIMIENTO'),
    (1005, 'C.P. ROBERTO DANIEL TORRES VEGA', 'AUXILIAR ADMINISTRATIVO');

INSERT INTO activos (
    descripcion, num_inventario, marca, modelo, serie, material,
    fecha_adq, factura, costo, observaciones, empleado_id
) VALUES
    ('RACK 4 POSTES', 'ECO-0001', 'HPE', 'AR3100', 'SN12345', 'ACERO',
     '2023-01-15', 'F-2023-001', 12500.00, 'ASIGNADO A COORDINADOR DE INFORMATICA', 1),
    ('NOBREAK', 'ECO-0002', 'APC', 'SMX1500', 'AS123456789', 'PLASTICO/METAL',
     '2023-02-10', 'F-2023-002', 4800.50, 'ASIGNADO A COORDINADOR DE INFORMATICA', 1),
    ('SILLA EJECUTIVA', 'ECO-0003', 'OFFICE DEPOT', 'EJ-200', 'S/S', 'TELA/METAL',
     '2023-03-05', 'F-2023-003', 2350.00, 'ASIGNADO A COORDINADOR DE INFORMATICA', 1),
    ('LAPTOP', 'ECO-0004', 'DELL', 'LATITUDE 5420', 'DLL789012', 'METAL',
     '2023-04-20', 'F-2023-004', 18999.99, 'ASIGNADO A AUXILIAR INFORMATICO', 2),
    ('MONITOR', 'ECO-0005', 'LG', '24MK600M', 'LG456123', 'PLASTICO',
     '2023-04-20', 'F-2023-004', 3200.00, 'ASIGNADO A AUXILIAR INFORMATICO', 2),
    ('ESCRITORIO', 'ECO-0006', 'SIN MARCA', 'ESTANDAR', 'S/S', 'MADERA/METAL',
     '2022-11-12', 'F-2022-189', 4500.00, 'ASIGNADO A SUBDIRECTOR ADMINISTRATIVO', 3);

-- =========================================================
-- Vistas de utilidad
-- =========================================================
CREATE OR REPLACE VIEW v_resguardo AS
SELECT
    e.id              AS empleado_pk,
    e.numero_nomina,
    e.nombre          AS nombre_empleado,
    e.cargo,
    a.id              AS activo_pk,
    a.descripcion,
    a.num_inventario,
    a.marca,
    a.modelo,
    a.serie,
    a.material,
    a.fecha_adq,
    a.factura,
    a.costo,
    a.observaciones,
    a.ruta_imagen
FROM empleados e
LEFT JOIN activos a ON a.empleado_id = e.id
ORDER BY e.numero_nomina, a.num_inventario;

-- =========================================================
-- Fin del script
-- =========================================================
SELECT 'Migración completada con éxito' AS mensaje;
