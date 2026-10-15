=== NAS Backup Bridge ===
Contributors: chrislund
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later

Copias completas del sitio (base de datos + archivos) descargadas por un servidor de copias propio mediante una API firmada con HMAC.

== Description ==

* No sube nada a terceros: es el servidor de copias quien se conecta y descarga.
* Volcado SQL en PHP puro, por lotes y reanudable (no necesita exec ni mysqldump).
* Listado de archivos para copias incrementales: solo se descarga lo que cambia.
* Trabajo dividido en pasos cortos: compatible con hostings compartidos con límites de tiempo.
* Carpeta de trabajo privada (fuera de la raíz web si el hosting lo permite).

Ajustes › NAS Backup muestra la configuración que hay que copiar al servidor de copias.
