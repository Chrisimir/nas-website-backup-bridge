# NAS Website Backup Bridge — notas para Claude

Copias diarias de WordPress hacia un NAS propio (TrueNAS), con panel web, restauración y actualizaciones automáticas del plugin desde este repo.
Toda la documentación de usuario está en `README.md`; esto es el contexto para seguir desarrollando.

## Piezas

| Ruta | Qué es | Dónde corre |
|---|---|---|
| `plugin/nas-backup-bridge/` | Plugin WordPress (PHP ≥ 7.4, WP ≥ 5.6). API REST `nbb/v1` firmada con HMAC | En cada web de cliente |
| `nas/nbb_pull.py` | Cliente: copia (`run`), prueba (`check`) y restauración (`restore`). Solo stdlib | TrueNAS, Cron Job 03:00 como root |
| `nas/nbb_panel.py` + `nas/panel.html` | Panel web (stdlib `http.server`), escucha en 127.0.0.1:8765 | TrueNAS, servicio `nbb-panel` (systemd-run) |
| `dist/` | Zip del plugin para instalar (no versionado: se genera) | — |

En producción: todo en `/mnt/apps/config/nbb/` (dataset root-only), `sites.json` y `panel.json` con las claves (**nunca al repo**), copias en `/mnt/tank/backups/webs/<sitio>/current`, historial = snapshots ZFS (`diaria-*` 30 días, `mensual-*` 12 meses, `nbb-antes-*` creados por el panel). Panel publicado con `tailscale serve --https=8443`.

## Decisiones que no hay que romper

- **El NAS tira (pull), la web nunca empuja.** El plugin no guarda credenciales de almacenamiento.
- **Sin `exec`/`mysqldump`**: el volcado es PHP puro, por lotes, reanudable con checkpoints (`state.json`) y truncado al último checkpoint si una petición muere. Hostings compartidos con `max_execution_time` de 30 s.
- **Protocolo 1**: cambios solo aditivos. Toda respuesta del plugin lleva `X-NBB-Protocol`; el cliente lo usa para saber si `/wp-json` funciona o pasar a `?rest_route=`.
- **Firma**: `NBB1\n<ts>\n<METHOD>\n<route>\n<query canónica sin rest_route>\n<sha256(body)>`, ±300 s. Cambiar esto rompe todos los sitios instalados.
- **Restauración**: tablas a `nbbr_<sha1[:12]>`, un único `RENAME TABLE` al final; el plugin solo acepta sentencias sobre esas tablas (y vistas). Ajustes `nbb_*` se conservan en el swap. Desactivada por defecto (`nbb_allow_restore`). Nunca escribir en el propio plugin ni en su carpeta de trabajo; nunca seguir symlinks.
- Los volcados son una sentencia por línea salvo `CREATE TABLE` (los valores escapan `\n`). `iter_sql_statements` depende de ello.
- Archivos restaurados conservan `mtime` (touch) para que la copia incremental siguiente no los vuelva a bajar.
- El panel solo crea/destruye snapshots `nbb-*`.

## Probar en local

```bash
# MariaDB + WordPress en /srv/wp, servidor PHP limitado como un hosting compartido
php -d max_execution_time=15 -d post_max_size=2M -S 127.0.0.1:8080 router.php
ln -s $PWD/plugin/nas-backup-bridge /srv/wp/wp-content/plugins/
python3 nas/nbb_pull.py --config sites.json check|run
python3 nas/nbb_pull.py --config sites.json restore --site X --source <dest>/X/current
python3 nas/nbb_panel.py --config sites.json   # sin ZFS usa puntos con enlaces duros en <dest>/.nbb-points
```
Verificar restauraciones con `CHECKSUM TABLE` y `diff -r`. Pruebas hechas: ver «Desarrollo y pruebas» en el README.

## Publicar una versión

1. Subir `Version:` y `NBB_VERSION` en `nas-backup-bridge.php` y `Stable tag` en `readme.txt`.
2. `scripts/release.sh "changelog"` → genera `releases/nas-backup-bridge-X.Y.Z.zip` y `releases/latest.json` (con SHA-256).
3. Commit + push a `main`. **El repo es público**: las webs leen `latest.json` por raw.githubusercontent.com cada 6 h (`NBB_Updater`) y verifican el hash antes de instalar. No borrar zips antiguos de `releases/`. (Los tags no pasan por el proxy de las sesiones de Claude; una release de GitHub es opcional y se crea desde la web.)
4. En el NAS: copiar `nas/*` a `/mnt/apps/config/nbb/` y `systemctl restart nbb-panel`.

**Repo público: nunca subir claves, `sites.json`, `panel.json`, datos de clientes ni nombres de dominios de clientes** (usar `ejemplo.com`).

