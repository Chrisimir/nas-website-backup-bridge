# NAS Backup Bridge

Copias de seguridad completas de WordPress (base de datos + archivos) descargadas por el NAS de casa. Funciona en hostings que no controlo: solo hace falta poder instalar un plugin.

```
 WordPress del cliente                         NAS (TrueNAS, cron 03:00)
 ┌───────────────────────────┐   HTTPS +      ┌────────────────────────────────────────┐
 │ plugin NAS Backup Bridge  │◄── firma HMAC ─┤ nbb_pull.py                            │
 │  · vuelca la BD (PHP)     │                │  · pide la copia y la va empujando     │
 │  · lista los archivos     │── artefactos ─►│  · descarga solo archivos cambiados    │
 │  · sirve archivos/lotes   │── archivos ───►│  · espejo en tank/backups/webs/<sitio> │
 └───────────────────────────┘                └────────────────────────────────────────┘
                                               Historial: snapshots ZFS (30 diarias + 12 mensuales)
```

## Por qué así

- **El NAS tira (pull), el sitio no empuja.** El NAS no está expuesto a internet y en los hostings de clientes no hay credenciales de ningún almacenamiento: si un sitio se compromete, no da acceso a las copias.
- **Sin `exec` ni `mysqldump`.** El volcado SQL se hace en PHP puro, tabla a tabla y por lotes (paginación por clave primaria cuando existe). Vale para hostings compartidos con funciones deshabilitadas.
- **Trabajo en pasos cortos.** El NAS llama a `/step` repetidamente; cada paso dura como mucho un 60 % del `max_execution_time` del hosting. No depende de WP-Cron ni de procesos en segundo plano.
- **A prueba de cortes.** Si una petición muere a mitad (timeout, reinicio de PHP), el siguiente paso retoma desde el último punto de control y descarta lo escrito después. Probado matando el proceso 14 veces durante una copia: volcado idéntico y manifiesto sin duplicados.
- **Incremental.** El plugin genera un listado (ruta, tamaño, fecha) y el NAS descarga solo lo que cambió. Los archivos pequeños viajan en lotes (un único request para cientos de archivos) y los grandes de forma individual con reanudación (`Range`).
- **Historial con ZFS**, no con herramientas extra: el espejo `current/` se fotografía cada día. Los volcados de tablas que no cambian se conservan tal cual para no gastar espacio en los snapshots.

## Seguridad

- Todas las rutas exigen firma **HMAC-SHA256** con un secreto de 256 bits por sitio, con la marca de tiempo (±5 min) y el hash del cuerpo firmados. Sin firma válida no se revela nada.
- Tras 20 fallos en 10 minutos desde una IP se responde 429 (una firma válida siempre pasa).
- `/file` y `/pack` solo sirven archivos dentro de la instalación: se rechazan `..`, rutas absolutas, symlinks que salgan de la raíz y la propia carpeta de trabajo.
- La carpeta de trabajo (volcado SQL temporal) se crea **fuera de la raíz web** si el hosting lo permite; si no, con nombre aleatorio dentro de `uploads/` y protegida con `.htaccess`/`web.config`/`index.php`. Se borra al terminar cada copia y una tarea diaria purga restos de más de 24 h.
- La copia incluye `wp-config.php` (credenciales de BD): el destino en el NAS debe ser privado (dataset sin compartir, solo root).
- Clave: en Vaultwarden. Se puede fijar en `wp-config.php` con `define( 'NBB_SECRET', '...' );` (mín. 32 caracteres).

## Qué se copia

- **Base de datos:** todas las tablas con el prefijo del sitio (`$wpdb->base_prefix`) y sus vistas. Opción en ajustes para copiar todas las tablas de la BD.
- **Archivos:** la instalación completa (`ABSPATH`), más `wp-content` si está fuera, más `wp-config.php` si está un nivel por encima.
- **Excluido por defecto** (editable en *Ajustes › NAS Backup*): cachés (`wp-content/cache`, `et-cache`), `upgrade`, carpetas de otros plugins de copias (UpdraftPlus, All-in-One WP Migration, Duplicator, BackWPup, WPvivid…), logs (`*.log`, `error_log`), `.git`, `node_modules`.
- Los **symlinks** se registran (`current/symlinks.json`) pero no se siguen ni se recrean.

Limitaciones conocidas:
- El volcado no es una instantánea transaccional: dura varios pasos, así que un pedido que entre a mitad de la copia puede quedar a medias. Para sitios de contenido es irrelevante; para tiendas con mucho movimiento, programar la copia de madrugada.
- No compatible con sitios sobre SQLite (drop-in) — solo MySQL/MariaDB.
- Multisite: copia todas las tablas de la red (`base_prefix`) y todos los archivos; no hay copia por subsitio.

## API (`/wp-json/nbb/v1/…` o `?rest_route=/nbb/v1/…`)

| Método | Ruta | Para qué |
|---|---|---|
| GET | `/status` | Versión, límites PHP, raíces, última copia |
| POST | `/jobs?force=1` | Crea una copia (sustituye cualquier otra en curso) |
| POST | `/jobs/{id}/step?budget=20` | Avanza: `db` → `scan` → `finalize` → `done` |
| GET | `/jobs/{id}` | Estado |
| GET | `/jobs/{id}/artifact?name=…` | `meta.json`, `manifest.ndjson.gz`, `db/NNNN-tabla.sql.gz` (con sha256) |
| GET | `/file?root=site&path=…` | Un archivo (admite `Range: bytes=N-`) |
| POST | `/pack` `{"files":[{"r","p"}]}` | Muchos archivos en una respuesta (formato `NBBPACK1`) |
| POST | `/jobs/{id}/complete` | Borra la carpeta de trabajo y registra la copia como completada |

Firma: ver `includes/class-nbb-auth.php` (string `NBB1\n<ts>\n<METHOD>\n<route>\n<query canónica>\n<sha256(body)>`). Todas las respuestas llevan la cabecera `X-NBB-Protocol: 1`; el cliente la usa para detectar si `/wp-json` está roto o bloqueado y pasar a `?rest_route=`.

## Instalación

### 1. En cada WordPress

1. *Plugins › Añadir nuevo › Subir plugin* → `nas-backup-bridge-1.0.0.zip` → Activar.
2. *Ajustes › NAS Backup* → **Mostrar configuración** → copiar el bloque JSON (nombre, URL, clave).
3. Guardar la clave en Vaultwarden (carpeta *Clientes*), nunca por correo/chat.

### 2. En el NAS (una sola vez)

1. **Dataset de destino:** Datasets › `tank/backups` › Add Dataset `webs`, preset **Generic** (sensible a mayúsculas). No se comparte por SMB.
2. **Dataset para el script:** Datasets › `apps/config` › Add Dataset `nbb`, preset **Generic**. Permisos: propietario `root:root`, `700`.
3. Copiar `nbb_pull.py` y `sites.json` a `/mnt/apps/config/nbb/` (por SMB a una carpeta temporal y `sudo mv`, o con `scp`). Luego:
   ```bash
   sudo chown root:root /mnt/apps/config/nbb/*
   sudo chmod 700 /mnt/apps/config/nbb/nbb_pull.py
   sudo chmod 600 /mnt/apps/config/nbb/sites.json
   ```
4. Editar `sites.json` (ver `sites.example.json`): `dest` = `/mnt/tank/backups/webs` y un bloque por sitio.
5. Probar la conexión y lanzar la primera copia a mano:
   ```bash
   sudo python3 /mnt/apps/config/nbb/nbb_pull.py --config /mnt/apps/config/nbb/sites.json check
   sudo python3 /mnt/apps/config/nbb/nbb_pull.py --config /mnt/apps/config/nbb/sites.json
   ```
6. **Cron Job** (System › Advanced Settings › Cron Jobs › Add):
   - Command: `/usr/bin/python3 /mnt/apps/config/nbb/nbb_pull.py --config /mnt/apps/config/nbb/sites.json`
   - Run As User: `root` · Schedule: `0 3 * * *` (todos los días a las 03:00)
   - *Hide Standard Output*: marcado · *Hide Standard Error*: **desmarcado** → si algo falla llega un email.
7. **Retención (snapshots ZFS de `tank/backups/webs`)**, en Data Protection › Periodic Snapshot Tasks:
   - Diaria: todos los días a las 05:00, conservar **30 días**, naming schema `diaria-%Y-%m-%d_%H-%M`.
   - Mensual: día 1 a las 05:30, conservar **12 meses**, naming schema `mensual-%Y-%m-%d_%H-%M`.
   - Recursive marcado en ambas. Sin snapshots vacíos.
8. Opcional: `healthcheck_url` (p. ej. un *push monitor* de Uptime Kuma) para recibir aviso también si el cron ni siquiera se ejecuta.

### Añadir otra web

Instalar el plugin, pegar su bloque en `sites.json`, `check`, primera copia a mano. Nada más: el cron y los snapshots ya cubren todo `tank/backups/webs`.

## Estructura en el NAS

```
/mnt/tank/backups/webs/<sitio>/
├── current/
│   ├── files/site/…              instalación completa (fecha de modificación original)
│   ├── db/0000-wp_options.sql.gz …  una tabla por archivo
│   ├── meta.json                 versiones, tablas, recuentos, errores de lectura
│   ├── manifest.ndjson.gz        listado de archivos de esta copia
│   └── symlinks.json
├── last_run.json                 resultado de la última ejecución
└── .nbb/                         índice local y estado interno (no tocar)
```

Copias anteriores: `/mnt/tank/backups/webs/.zfs/snapshot/<diaria-…|mensual-…>/<sitio>/current/`.

## Restaurar

**Un archivo concreto:** copiarlo desde `current/files/site/…` (o desde el snapshot del día que toque) y subirlo por FTP/gestor de archivos del hosting.

**Base de datos completa:**
```bash
zcat current/db/*.sql.gz > restore.sql      # un único .sql, en orden
# phpMyAdmin del hosting → Importar restore.sql   (o: mysql -u USER -p DB < restore.sql)
```
Cada tabla empieza con `DROP TABLE IF EXISTS` + `CREATE TABLE`. Para restaurar solo una tabla: `zcat current/db/0005-wp_options.sql.gz > options.sql`.

**Sitio entero en otro servidor:** subir `current/files/site/` a la raíz web, crear la BD, importar `restore.sql`, ajustar credenciales en `wp-config.php` y, si cambia el dominio, `wp search-replace 'https://viejo' 'https://nuevo'`.

## Problemas habituales

| Síntoma | Causa / solución |
|---|---|
| `HTTP 401 … Marca de tiempo fuera de rango` | Hora del NAS o del servidor desajustada (>5 min). Revisar NTP. |
| `HTTP 401 Firma no válida` | Clave equivocada o regenerada en el sitio: actualizar `sites.json`. |
| `HTTP 429` | Muchos intentos fallidos desde esa IP: esperar 10 min y corregir la clave. |
| `HTTP 403` sin mensaje del plugin / `La respuesta no viene del plugin` | Plugin desactivado, WAF del hosting (ModSecurity) o Cloudflare (*Bot Fight Mode*, reglas de seguridad) bloqueando. Crear excepción para `/wp-json/nbb/` o `rest_route=/nbb/`. Si un plugin de seguridad desactiva la REST API para anónimos, permitir la ruta `nbb/v1`. |
| Preparación muy lenta / muchos pasos | `max_execution_time` bajo en el hosting (se ve con `check`). Funciona igual, solo tarda más. |
| `scan_errors` en `meta.json` | Carpetas/archivos sin permiso de lectura para PHP: corregir permisos en el hosting o excluirlos. |
| Aviso "carpeta de trabajo dentro de la raíz web" | El hosting no permite escribir fuera de la web. Con Apache/LiteSpeed está protegida por `.htaccess`; con Nginx, comprobar que `/wp-content/uploads/nbb-*/` no se sirve. |

## Desarrollo y pruebas

Probado con WordPress 7.1.3 / PHP 8.3 / MariaDB (requisitos mínimos declarados: WP 5.6, PHP 7.4), con `max_execution_time=15`:
- Copia completa y restauración: `CHECKSUM TABLE` idéntico en todas las tablas (incluidas BLOB binarias, claves compuestas, PK de texto, UTF-8 con emojis, `NULL`, cadenas vacías) y `diff -r` idéntico en archivos (nombres con espacios y Unicode, carpetas vacías, archivo de 41 MB por la vía de descarga individual).
- Incremental: solo se descargan los archivos cambiados; los borrados en el sitio se borran del espejo.
- Reanudación tras matar el proceso PHP 14 veces durante una copia con una tabla de 214.000 filas.
- Seguridad: firma incorrecta, marca de tiempo antigua, `..`, rutas absolutas, symlink hacia `/etc`, raíz inexistente, nombre de artefacto manipulado → todo rechazado; bloqueo 429 tras 20 fallos.
- Permalinks desactivados (`/wp-json` devuelve la portada) → el cliente pasa solo a `?rest_route=`.
