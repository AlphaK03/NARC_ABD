# CRAN – Multi Buffer Cache Monitors (DBLINK + MySQL)

Separación en archivos sin cambiar la lógica original.

## Estructura
```
/cran-monitor/
├─ index.php          # UI (HTML+JS)
├─ api.php            # Endpoints AJAX (?action=list|create|delete|data)
├─ config.php         # Configs Oracle/MySQL y max_points
├─ lib/
│  └─ helpers.php     # Funciones comunes (PDO, OCI8, JSON, etc.)
└─ sql/
   └─ schema.sql      # Tablas mínimas + SP de alertas (ejemplo)
```

## Requisitos
- PHP 8+ con extensiones `oci8` y `pdo_mysql` habilitadas
- WAMP/XAMPP o similar
- Oracle 21c XE (local) con DBLINK hacia el cliente remoto
- MySQL local con base `mybd_gobierno`

## Despliegue
1. Copia la carpeta `cran-monitor` al directorio web (por ejemplo, `C:\wamp64\www\cran-monitor`).
2. Edita `config.php` si necesitas ajustar credenciales/DSN.
3. Crea las tablas y SP ejecutando `sql/schema.sql` en MySQL (opcional si ya existen).
4. Abre `http://localhost/cran-monitor/index.php`.

## Notas
- La UI llama a `api.php` con `action=list|create|delete|data`.
- El SP `sp_create_sga_alert` se invoca tal cual en el código; el archivo SQL incluye una versión de ejemplo.
