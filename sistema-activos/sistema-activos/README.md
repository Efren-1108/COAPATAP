 # Sistema de Control de Activos Fijos

Sistema web completo para el control de activos fijos de una escuela, con gestión de resguardantes, bienes asignados y generación de reportes de resguardo.

## 🚀 Características

- **Autenticación y RBAC**: Login con bcrypt, sesiones seguras, roles (`superusuario`, `usuario`) y filtrado por área.
- **Gestión de Usuarios, Roles y Áreas**: CRUD completo con módulo de superusuario.
- **Gestión de Resguardantes (Personal)**: CRUD completo de empleados con número de nómina, nombre, cargo y área.
- **Gestión de Activos Fijos**: CRUD de bienes con descripción, inventario, marca, modelo, serie, material, fecha, factura, costo, observaciones y foto.
- **Asignación de bienes**: Relación 1 a N (un resguardante puede tener múltiples bienes).
- **Módulo de consulta principal**: Búsqueda por número de nómina con vista detallada y generación de PDF/impresión.
- **Perfil de usuario**: Cambio de contraseña con verificación de la actual.
- **Log de actividades**: Registro automático de cambios vía triggers + log de aplicación.
- **Diagnóstico del sistema**: Página `/public/diagnostico.php` que valida PHP, extensiones, BD, tablas y permisos.
- **Diseño responsive**: Adaptable a celular, tablet y computadora.
- **Validaciones**: Nóminas únicas, costos positivos, fechas no futuras.

## 🛠️ Tecnologías

- **Frontend**: HTML5, CSS3 (diseño moderno con variables CSS), JavaScript (vanilla, sin frameworks).
- **Backend**: PHP 7.4+ (sin frameworks, PDO para PostgreSQL).
- **Base de datos**: PostgreSQL 12+.
- **Servidor web**: Apache / Nginx con PHP.
- **Generación de PDF**: HTML imprimible con CSS `@media print` (sin dependencias externas).

## 📋 Requisitos previos

1. **PHP 7.4 o superior** con extensiones:
   - `pdo_pgsql` (para PostgreSQL)
   - `gd` (opcional, para manipulación de imágenes)
   - `fileinfo` (para validación de archivos)
2. **PostgreSQL 12 o superior**.
3. **Servidor web**: Apache, Nginx o el servidor embebido de PHP para pruebas.

## 📦 Instalación paso a paso

### 1. Clonar o copiar el proyecto

Copia la carpeta `sistema-activos` en el directorio de tu servidor web. Por ejemplo:
- **XAMPP**: `C:\xampp\htdocs\sistema-activos`
- **Linux**: `/var/www/html/sistema-activos`

### 2. Crear la base de datos en PostgreSQL

Abre una terminal de PostgreSQL (`psql`) o usa pgAdmin y ejecuta:

```sql
CREATE DATABASE activos_fijos
    WITH ENCODING 'UTF8'
    LC_COLLATE = 'Spanish_Mexico.1252'
    LC_CTYPE = 'Spanish_Mexico.1252'
    TEMPLATE = template0;
```

> **Nota**: Si tu servidor PostgreSQL está en Linux, usa `es_MX.UTF-8` o `C.UTF-8` según tu configuración regional.

### 3. Ejecutar las migraciones

Conéctate a la base de datos y ejecuta **ambos** scripts en orden:

```bash
psql -U postgres -d activos_fijos -f database/migracion.sql
psql -U postgres -d activos_fijos -f database/migracion_auth.sql
```

`migracion.sql` crea las tablas base (`empleados`, `activos`, `log_actividades`) y datos de ejemplo.
`migracion_auth.sql` agrega las tablas del módulo de autenticación (`usuarios`, `roles`, `areas`) y crea el usuario inicial `admin/admin123`.

> **Alternativa sin pgcrypto:** Si tu PostgreSQL no permite crear la extensión `pgcrypto`, ejecuta en su lugar `php tools/seed_admin.php` desde la línea de comandos. Este script crea el rol `superusuario` y el usuario `admin/admin123` con `password_hash()` de PHP.

### 4. Configurar las variables de entorno

Edita el archivo `config/config.php` con tus credenciales de PostgreSQL:

```php
define('DB_HOST', 'localhost');
define('DB_PORT', '5432');
define('DB_NAME', 'activos_fijos');
define('DB_USER', 'postgres');
define('DB_PASS', 'tu_contraseña');
```

### 5. Configurar permisos de la carpeta de uploads

La carpeta `uploads/` debe tener permisos de escritura para el usuario del servidor web:

**Linux/macOS**:
```bash
chmod 755 uploads/
chown www-data:www-data uploads/
```

**Windows**: Click derecho → Propiedades → Seguridad → Agregar permisos de escritura para el usuario del servidor web (IUSR, IIS_IUSRS, etc.).

### 6. Iniciar el servidor

#### Opción A: Servidor embebido de PHP (sólo desarrollo)

```bash
cd sistema-activos
php -S localhost:8000
```

Luego abre en tu navegador: http://localhost:8000

#### Opción B: XAMPP / WAMP / MAMP

Coloca la carpeta en el directorio `htdocs` o `www` y accede a:
http://localhost/sistema-activos

#### Opción C: Apache / Nginx (producción)

Configura un virtual host apuntando a la carpeta del proyecto. Ejemplo para Apache:

```apache
<VirtualHost *:80>
    ServerName activos.local
    DocumentRoot "C:/xampp/htdocs/sistema-activos/public"
    <Directory "C:/xampp/htdocs/sistema-activos/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

## 📂 Estructura del proyecto

```
sistema-activos/
├── api/                      # Endpoints REST (PHP puro)
│   ├── auth.php              # Login, logout, cambio de contraseña, sesión actual
│   ├── usuarios.php          # CRUD usuarios + reset_password
│   ├── roles.php             # CRUD roles
│   ├── areas.php             # CRUD áreas
│   ├── empleados.php         # CRUD empleados/resguardantes
│   ├── activos.php           # CRUD activos fijos
│   ├── consulta.php          # Búsqueda pública por nómina
│   └── upload.php            # Subida de imágenes (requiere sesión)
├── assets/                   # Recursos estáticos
│   ├── css/
│   │   └── styles.css
│   ├── js/
│   │   ├── app.js            # Helpers globales (App.api, toast, modal, money, date, esc)
│   │   ├── auth.js           # Manejo del formulario de login
│   │   ├── empleados.js      # CRUD empleados
│   │   ├── activos.js        # CRUD activos + subida de foto
│   │   ├── consulta.js       # Búsqueda por nómina
│   │   ├── usuarios.js       # CRUD usuarios
│   │   ├── roles.js          # CRUD roles
│   │   ├── areas.js          # CRUD áreas
│   │   └── perfil.js         # Cambio de contraseña
│   └── img/
│       └── logo.png          # Favicon y logo (opcional)
├── config/                   # Configuración
│   └── config.php
├── database/                 # Scripts SQL
│   ├── migracion.sql         # Tablas base (empleados, activos, log_actividades)
│   └── migracion_auth.sql    # Módulo auth (usuarios, roles, areas)
├── includes/                 # Plantillas y helpers compartidos
│   ├── auth.php              # require_auth, require_super, current_user, RBAC
│   ├── header.php            # Layout común (topbar + navegación)
│   └── footer.php            # Cierre + carga scripts
├── public/                   # Punto de entrada público
│   ├── index.php             # Consulta por nómina
│   ├── login.php             # Login
│   ├── logout.php            # Cierre de sesión
│   ├── empleados.php         # CRUD empleados
│   ├── activos.php           # CRUD activos
│   ├── usuarios.php          # CRUD usuarios (solo super)
│   ├── roles.php             # CRUD roles (solo super)
│   ├── areas.php             # CRUD áreas (solo super)
│   ├── perfil.php            # Mi perfil / cambio de contraseña
│   └── diagnostico.php       # Diagnóstico del sistema (PHP/BD/extensiones)
├── tools/                    # Scripts de utilidad CLI
│   └── seed_admin.php        # Seeder admin (alternativa sin pgcrypto)
├── uploads/                  # Imágenes subidas por los usuarios
├── logs/                     # Log de actividades
└── README.md
```

## 🗄️ Modelo de base de datos

### Tablas del módulo base (`migracion.sql`)

**`empleados`** — Resguardantes / personal de la escuela

| Campo | Tipo | Restricciones |
|---|---|---|
| `id` | SERIAL | PK |
| `numero_nomina` | INTEGER | UNIQUE, NOT NULL, > 0 |
| `nombre` | VARCHAR(200) | NOT NULL |
| `cargo` | VARCHAR(150) | NOT NULL |
| `area_id` | INTEGER | FK → areas.id ON DELETE SET NULL |
| `creado_en` | TIMESTAMP | DEFAULT NOW() |
| `actualizado_en` | TIMESTAMP | DEFAULT NOW() |

**`activos`** — Bienes / activos fijos

| Campo | Tipo | Restricciones |
|---|---|---|
| `id` | SERIAL | PK |
| `descripcion` | VARCHAR(200) | NOT NULL |
| `num_inventario` | VARCHAR(50) | UNIQUE, NOT NULL |
| `marca` | VARCHAR(100) | |
| `modelo` | VARCHAR(100) | |
| `serie` | VARCHAR(100) | |
| `material` | VARCHAR(100) | |
| `fecha_adq` | DATE | CHECK (≤ CURRENT_DATE) |
| `factura` | VARCHAR(100) | |
| `costo` | NUMERIC(12,2) | DEFAULT 0, CHECK (≥ 0) |
| `observaciones` | TEXT | |
| `empleado_id` | INTEGER | FK → empleados.id ON DELETE SET NULL |
| `area_id` | INTEGER | FK → areas.id ON DELETE SET NULL |
| `usuario_id` | INTEGER | FK → usuarios.id ON DELETE SET NULL |
| `ruta_imagen` | VARCHAR(255) | |
| `creado_en` | TIMESTAMP | DEFAULT NOW() |
| `actualizado_en` | TIMESTAMP | DEFAULT NOW() |

**`log_actividades`** — Bitácora

| Campo | Tipo | Restricciones |
|---|---|---|
| `id` | SERIAL | PK |
| `tabla` | VARCHAR(50) | NOT NULL |
| `accion` | VARCHAR(20) | CHECK (INSERT/UPDATE/DELETE/SEARCH) |
| `registro_id` | INTEGER | |
| `detalle` | TEXT | |
| `usuario` | VARCHAR(100) | DEFAULT 'sistema' |
| `usuario_id` | INTEGER | FK → usuarios.id ON DELETE SET NULL |
| `area_id` | INTEGER | FK → areas.id ON DELETE SET NULL |
| `creado_en` | TIMESTAMP | DEFAULT NOW() |

### Tablas del módulo de autenticación (`migracion_auth.sql`)

**`roles`** — Roles del sistema (`superusuario`, `usuario`, etc.)

**`areas`** — Departamentos. Un usuario normal pertenece a un área.

**`usuarios`** — Usuarios que operan el sistema (NO son empleados/resguardantes).

| Campo | Tipo | Restricciones |
|---|---|---|
| `id` | SERIAL | PK |
| `nombre_completo` | VARCHAR(200) | NOT NULL |
| `nombre_usuario` | VARCHAR(60) | UNIQUE, NOT NULL, ≥ 3 chars |
| `correo` | VARCHAR(150) | formato básico de email |
| `password_hash` | VARCHAR(255) | NOT NULL (bcrypt) |
| `area_id` | INTEGER | FK → areas.id, NULL solo si es super |
| `rol_id` | INTEGER | NOT NULL, FK → roles.id |
| `estado` | BOOLEAN | DEFAULT TRUE |
| `ultimo_acceso` | TIMESTAMP | |
| `creado_en` / `actualizado_en` | TIMESTAMP | DEFAULT NOW() |

### Vista de utilidad

`v_resguardo` — JOIN empleado + activos para generar el PDF/impresión de resguardo.

## 🎯 Uso del sistema

### Pantalla principal (Consulta)
1. Captura el **número de nómina** del resguardante.
2. Pulsa **"Buscar"**.
3. Se muestran los datos del empleado y todos sus bienes asignados.
4. Pulsa **"Imprimir / Guardar PDF"** para generar el resguardo.

### Gestión de empleados
- Accede al menú **"Empleados"** o navega a `/empleados.php`.
- Crea, edita o elimina resguardantes. El número de nómina es único y obligatorio.

### Gestión de activos
- Accede al menú **"Activos"** o navega a `/activos.php`.
- Crea, edita o elimina bienes. El campo **"Asignado a"** es un selector con los empleados registrados.

### Gestión de usuarios, roles y áreas (solo superusuario)
- Menú **"Usuarios"**: alta, baja, edición y reseteo de contraseñas.
- Menú **"Áreas"**: departamentos que agrupan usuarios y empleados.
- Menú **"Roles"**: `superusuario` y `usuario` están protegidos contra eliminación.

### Mi perfil
- Click en tu nombre (esquina superior derecha) → **"Mi perfil"**.
- Cambia tu contraseña ingresando la actual, la nueva y su confirmación.

## 🔐 Notas de seguridad

- El sistema **sí incluye autenticación** (sesiones seguras con cookies `httponly` + `SameSite=Strict`, contraseñas bcrypt, expiración por inactividad de 30 min).
- Los endpoints JSON validan la sesión vía `require_auth()`; `api/upload.php` exige sesión activa.
- Los superusuarios tienen acceso total; los usuarios normales solo ven/gestionan registros de su área.
- Antes de pasar a producción:
  - Cambia la contraseña del usuario `admin` (creado por defecto como `admin123`).
  - Restringe el acceso a `public/diagnostico.php` (filtra IPs o protégelo con autenticación).
  - Considera mover las credenciales de BD a variables de entorno.
- Valida y sanea todas las entradas (ya se hace en backend, pero considera CSRF tokens en producción).
- Limita el tamaño y tipo de los archivos subidos (ya configurado: 5 MB máx., JPG/PNG/GIF/WEBP).

## 📝 Licencia

Proyecto educativo / demostrativo. Úsalo libremente.
