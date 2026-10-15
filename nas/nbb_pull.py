#!/usr/bin/env python3
"""
NAS Backup Bridge — cliente del NAS.

Descarga copias de los WordPress que tienen instalado el plugin "NAS Backup Bridge"
y mantiene un espejo por sitio:

    <dest>/<sitio>/current/files/<root>/...   archivos (incremental: solo lo que cambia)
    <dest>/<sitio>/current/db/*.sql.gz        volcado de la base de datos (una tabla por archivo)
    <dest>/<sitio>/current/meta.json          datos de la copia (versiones, tablas, recuentos)
    <dest>/<sitio>/current/manifest.ndjson.gz listado de archivos de la copia
    <dest>/<sitio>/last_run.json              resultado de la última ejecución

El historial (30 diarias + 12 mensuales) lo guardan los snapshots ZFS del dataset.

Solo usa la biblioteca estándar de Python (3.8+).

Uso:
    nbb_pull.py --config sites.json                 # copia todos los sitios
    nbb_pull.py --config sites.json --site NOMBRE   # solo uno
    nbb_pull.py --config sites.json check           # prueba la conexión y la firma
    nbb_pull.py --config sites.json restore --site NOMBRE --source RUTA [--no-db] [--no-files]
                [--keep-extra] [--wp-config] [--no-maintenance]
                                                    # restaura una copia (RUTA = carpeta "current")
"""

import argparse
import fcntl
import gzip
import hashlib
import hmac
import json
import os
import re
import shutil
import socket
import ssl
import stat
import sys
import tempfile
import time
import traceback
import urllib.error
import urllib.parse
import urllib.request
import zlib

PROTOCOL = 1
USER_AGENT = "nbb-pull/1.0"
CHUNK = 1024 * 1024

DEFAULTS = {
    "budget": 20,                       # segundos por paso en el servidor
    "timeout": 120,                     # timeout de red por lectura (s)
    "job_timeout": 3 * 3600,            # tiempo máximo para preparar la copia
    "pack_max_files": 1000,
    "pack_max_bytes": 32 * 1024 * 1024,
    "big_file_bytes": 32 * 1024 * 1024,  # más grande -> descarga individual reanudable
    "retries": 4,
    "healthcheck_url": None,
    "restore_body_bytes": 4 * 1024 * 1024,  # tamaño máximo de cada petición al restaurar
}


def log(msg, *args):
    text = msg % args if args else msg
    print(time.strftime("%Y-%m-%d %H:%M:%S"), text, flush=True)


class NbbError(Exception):
    pass


class HttpError(NbbError):
    def __init__(self, status, body):
        self.status = status
        self.body = body
        try:
            data = json.loads(body)
            detail = data.get("message") or data.get("code") or body[:200]
        except Exception:
            detail = body[:200]
        super().__init__("HTTP %s: %s" % (status, detail))


# --------------------------------------------------------------------------- #
# Cliente HTTP firmado
# --------------------------------------------------------------------------- #

class Client:
    def __init__(self, url, secret, timeout, retries, mode="auto"):
        if not url.endswith("/"):
            url += "/"
        self.base = url
        self.secret = secret.encode()
        self.timeout = timeout
        self.retries = retries
        self.mode = mode  # auto | pretty | query
        self.ctx = ssl.create_default_context()

    @staticmethod
    def canonical_query(params):
        items = sorted((str(k), str(v)) for k, v in params.items())
        return "&".join(
            urllib.parse.quote(k, safe="-_.~") + "=" + urllib.parse.quote(v, safe="-_.~")
            for k, v in items
        )

    def sign(self, method, route, params, body):
        ts = str(int(time.time()))
        msg = "\n".join([
            "NBB1", ts, method.upper(), route,
            self.canonical_query(params), hashlib.sha256(body or b"").hexdigest(),
        ])
        sig = hmac.new(self.secret, msg.encode(), hashlib.sha256).hexdigest()
        return ts, sig

    def build_url(self, route, params, mode):
        query = self.canonical_query(params)
        if mode == "pretty":
            url = self.base + "wp-json" + route
            return url + ("?" + query if query else "")
        url = self.base + "?rest_route=" + urllib.parse.quote(route, safe="/")
        return url + ("&" + query if query else "")

    def open(self, method, route, params=None, body=None, headers=None, content_type="application/json"):
        """Abre la petición y devuelve la respuesta (sin leer). Reintenta errores de red y 5xx."""
        params = params or {}
        modes = [self.mode] if self.mode != "auto" else ["pretty", "query"]
        last_exc = None
        for attempt in range(self.retries + 1):
            for mode in modes:
                ts, sig = self.sign(method, route, params, body)
                req = urllib.request.Request(
                    self.build_url(route, params, mode), data=body, method=method.upper()
                )
                req.add_header("User-Agent", USER_AGENT)
                req.add_header("X-NBB-Timestamp", ts)
                req.add_header("X-NBB-Signature", sig)
                req.add_header("Accept-Encoding", "identity")
                if body is not None:
                    req.add_header("Content-Type", content_type)
                for k, v in (headers or {}).items():
                    req.add_header(k, v)
                try:
                    resp = urllib.request.urlopen(req, timeout=self.timeout, context=self.ctx)
                except urllib.error.HTTPError as e:
                    text = e.read().decode("utf-8", "replace")
                    is_ours = bool(e.headers.get("X-NBB-Protocol"))
                    if self.mode == "auto" and mode == "pretty" and e.code in (404, 403, 401) and not is_ours:
                        # /wp-json no disponible o bloqueado: probar ?rest_route=
                        last_exc = HttpError(e.code, text)
                        continue
                    if e.code >= 500 or e.code == 429:
                        last_exc = HttpError(e.code, text)
                        break
                    raise HttpError(e.code, text)
                except (urllib.error.URLError, socket.timeout, ConnectionError, ssl.SSLError) as e:
                    last_exc = NbbError("Error de red: %s" % e)
                    break
                if not resp.headers.get("X-NBB-Protocol"):
                    resp.close()
                    if self.mode == "auto" and mode == "pretty":
                        continue  # /wp-json devolvió otra cosa (p. ej. la portada): probar ?rest_route=
                    raise NbbError("La respuesta no viene del plugin (¿está activo? ¿la URL es correcta?)")
                if self.mode == "auto":
                    self.mode = mode  # recordar el modo que funciona
                    modes = [mode]
                return resp
            if attempt < self.retries:
                time.sleep(min(60, 5 * (2 ** attempt)))
        raise last_exc or NbbError("Petición fallida")

    def json(self, method, route, params=None, body=None, raw=None):
        """body: objeto a enviar como JSON; raw: bytes a enviar tal cual (octet-stream)."""
        if raw is not None:
            ctx = self.open(method, route, params, raw, content_type="application/octet-stream")
        else:
            ctx = self.open(method, route, params, None if body is None else json.dumps(body).encode())
        with ctx as resp:
            data = resp.read()
        try:
            return json.loads(data.decode("utf-8"))
        except ValueError:
            raise NbbError("Respuesta no JSON en %s: %r" % (route, data[:200]))


# --------------------------------------------------------------------------- #
# Utilidades de ficheros
# --------------------------------------------------------------------------- #

def safe_join(base, root, rel):
    """Devuelve base/root/rel garantizando que no se sale de base."""
    if not root or not all(c.isalnum() or c in "-_" for c in root):
        raise NbbError("Raíz no válida: %r" % root)
    if not rel or "\0" in rel or rel.startswith("/") or "\\" in rel:
        raise NbbError("Ruta no válida: %r" % rel)
    parts = rel.split("/")
    if any(p in ("", ".", "..") for p in parts):
        raise NbbError("Ruta no válida: %r" % rel)
    path = os.path.join(base, root, *parts)
    real_base = os.path.realpath(base)
    real = os.path.realpath(path)
    if not (real == real_base or real.startswith(real_base + os.sep)):
        raise NbbError("Ruta fuera del destino: %r" % rel)
    return path


def sha256_file(path, decompress=False):
    h = hashlib.sha256()
    opener = gzip.open if decompress else open
    with opener(path, "rb") as f:
        for chunk in iter(lambda: f.read(CHUNK), b""):
            h.update(chunk)
    return h.hexdigest()


def write_json(path, data):
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=1)
    os.replace(tmp, path)


def read_exact(stream, n, out=None):
    """Lee exactamente n bytes del stream (escribe en out si se da)."""
    remaining = n
    while remaining > 0:
        chunk = stream.read(min(CHUNK, remaining))
        if not chunk:
            raise NbbError("Conexión cortada en mitad de un archivo")
        if out is not None:
            out.write(chunk)
        remaining -= len(chunk)


# --------------------------------------------------------------------------- #
# Copia de un sitio
# --------------------------------------------------------------------------- #

class SiteBackup:
    def __init__(self, site, cfg, dest):
        self.name = site["name"]
        if not self.name or not all(c.isalnum() or c in "-_." for c in self.name):
            raise NbbError("Nombre de sitio no válido: %r" % self.name)
        self.cfg = cfg
        self.root = os.path.join(dest, self.name)
        self.current = os.path.join(self.root, "current")
        self.files_dir = os.path.join(self.current, "files")
        self.meta_dir = os.path.join(self.root, ".nbb")
        self.index_path = os.path.join(self.meta_dir, "index.json")
        self.client = Client(
            site["url"], site["secret"], cfg["timeout"], cfg["retries"], site.get("rest_mode", "auto")
        )
        self.stats = {
            "downloaded_files": 0, "downloaded_bytes": 0, "deleted_files": 0,
            "unchanged_files": 0, "skipped": [], "warnings": [],
        }
        self.index = {}

    # ---- índice local --------------------------------------------------- #
    @staticmethod
    def key(root, path):
        return root + "\0" + path

    def load_index(self):
        try:
            with open(self.index_path, encoding="utf-8") as f:
                raw = json.load(f)
            self.index = {k: tuple(v) for k, v in raw.items()}
        except (FileNotFoundError, ValueError):
            self.index = {}

    def save_index(self):
        write_json(self.index_path, {k: list(v) for k, v in self.index.items()})

    # ---- fases ---------------------------------------------------------- #
    def check(self):
        st = self.client.json("GET", "/nbb/v1/status")
        if st.get("protocol") != PROTOCOL:
            raise NbbError("Versión de protocolo incompatible: %s" % st.get("protocol"))
        try:
            os.makedirs(self.meta_dir, exist_ok=True)
            write_json(os.path.join(self.meta_dir, "status.json"), dict(st, checked=int(time.time())))
        except OSError:
            pass
        skew = abs(int(st.get("server_time", time.time())) - int(time.time()))
        if skew > 120:
            self.stats["warnings"].append("Diferencia horaria con el servidor: %ss" % skew)
        return st

    def prepare(self, skip_db=False):
        params = {"force": "1"}
        if skip_db:
            params["skip_db"] = "1"
        job = self.client.json("POST", "/nbb/v1/jobs", params)
        job_id = job["id"]
        log("[%s] copia %s creada; preparando en el servidor…", self.name, job_id)
        deadline = time.time() + self.cfg["job_timeout"]
        last_report = 0
        while True:
            if time.time() > deadline:
                raise NbbError("La preparación de la copia superó el tiempo máximo")
            try:
                st = self.client.json(
                    "POST", "/nbb/v1/jobs/%s/step" % job_id, {"budget": str(self.cfg["budget"])}
                )
            except HttpError as e:
                if e.status == 409:  # otro paso aún en ejecución
                    time.sleep(5)
                    continue
                raise
            if st["phase"] == "error":
                raise NbbError("El servidor falló preparando la copia: %s" % st.get("error"))
            if st["phase"] == "done":
                log("[%s] preparada: %s tablas, %s filas, %s archivos (%.1f MB) en %s pasos",
                    self.name, st["db"]["tables_total"], st["db"]["rows"], st["scan"]["files"],
                    st["scan"]["bytes"] / 1e6, st["steps"])
                return job_id, st
            if time.time() - last_report > 30:
                log("[%s]   fase %s · tablas %s/%s · archivos %s", self.name, st["phase"],
                    st["db"]["tables_done"], st["db"]["tables_total"], st["scan"]["files"])
                last_report = time.time()

    def download_artifacts(self, job_id, artifacts, staging):
        for art in artifacts:
            name = art["name"]
            target = os.path.join(staging, *name.split("/"))
            os.makedirs(os.path.dirname(target), exist_ok=True)
            for attempt in range(3):
                with self.client.open("GET", "/nbb/v1/jobs/%s/artifact" % job_id, {"name": name}) as resp, \
                        open(target, "wb") as out:
                    shutil.copyfileobj(resp, out, CHUNK)
                if os.path.getsize(target) == art["size"] and sha256_file(target) == art["sha256"]:
                    break
                log("[%s] checksum incorrecto en %s, reintentando", self.name, name)
            else:
                raise NbbError("No se pudo descargar %s íntegro" % name)

    @staticmethod
    def read_manifest(path):
        entries = {}
        with gzip.open(path, "rt", encoding="utf-8") as f:
            for line in f:
                line = line.strip()
                if line:
                    e = json.loads(line)
                    entries[(e["t"], e["r"], e["p"])] = e  # el último gana (duplicados tras reanudar)
        files, dirs, links = {}, [], []
        for (t, r, p), e in entries.items():
            if t == "f":
                files[SiteBackup.key(r, p)] = e
            elif t == "d":
                dirs.append(e)
            elif t == "l":
                links.append(e)
        return files, dirs, links

    def plan(self, files):
        todo = []
        for k, e in files.items():
            have = self.index.get(k)
            local = safe_join(self.files_dir, e["r"], e["p"])
            if have and have[0] == e["s"] and have[1] == e["m"]:
                try:
                    if os.path.getsize(local) == e["s"]:
                        self.stats["unchanged_files"] += 1
                        continue
                except OSError:
                    pass
            todo.append(e)
        return todo

    def store(self, e, tmp_path, size, mtime):
        dest = safe_join(self.files_dir, e["r"], e["p"])
        os.makedirs(os.path.dirname(dest), exist_ok=True)
        if os.path.isdir(dest) and not os.path.islink(dest):
            shutil.rmtree(dest)
        os.replace(tmp_path, dest)
        os.chmod(dest, 0o640)
        os.utime(dest, (mtime, mtime))
        self.index[self.key(e["r"], e["p"])] = (size, mtime)
        self.stats["downloaded_files"] += 1
        self.stats["downloaded_bytes"] += size

    def fetch_big(self, e):
        dest = safe_join(self.files_dir, e["r"], e["p"])
        os.makedirs(os.path.dirname(dest), exist_ok=True)
        part = dest + ".nbbpart"
        for attempt in range(self.cfg["retries"] + 1):
            offset = os.path.getsize(part) if os.path.exists(part) else 0
            headers = {"Range": "bytes=%d-" % offset} if offset else {}
            try:
                with self.client.open("GET", "/nbb/v1/file", {"root": e["r"], "path": e["p"]},
                                      headers=headers) as resp:
                    size = int(resp.headers.get("X-NBB-Size", "-1"))
                    mtime = int(resp.headers.get("X-NBB-Mtime", e["m"]))
                    mode = "ab" if resp.status == 206 else "wb"
                    with open(part, mode) as out:
                        shutil.copyfileobj(resp, out, CHUNK)
                if os.path.getsize(part) == size:
                    self.store(e, part, size, mtime)
                    return True
            except HttpError as err:
                if err.status == 404:
                    self.stats["skipped"].append("%s:%s (borrado durante la copia)" % (e["r"], e["p"]))
                    if os.path.exists(part):
                        os.remove(part)
                    return False
                if attempt >= self.cfg["retries"]:
                    raise
            except NbbError:
                if attempt >= self.cfg["retries"]:
                    raise
            time.sleep(3)
        raise NbbError("No se pudo descargar %s:%s" % (e["r"], e["p"]))

    def fetch_pack(self, batch):
        """Descarga un lote. Devuelve las entradas que hay que reintentar individualmente."""
        retry = []
        by_key = {self.key(e["r"], e["p"]): e for e in batch}
        body = json.dumps({"files": [{"r": e["r"], "p": e["p"]} for e in batch]}).encode()
        with self.client.open("POST", "/nbb/v1/pack", body=body) as resp:
            magic = resp.readline()
            if magic != b"NBBPACK1\n":
                raise NbbError("Respuesta de /pack inesperada: %r" % magic[:80])
            seen = set()
            while True:
                line = resp.readline()
                if not line:
                    raise NbbError("Lote cortado antes de terminar")
                if line == b"END\n":
                    break
                hdr = json.loads(line.decode("utf-8"))
                k = self.key(hdr["r"], hdr["p"])
                e = by_key.get(k)
                if e is None:
                    raise NbbError("El servidor devolvió un archivo no pedido: %r" % hdr["p"])
                seen.add(k)
                if not hdr.get("ok"):
                    if hdr.get("e") == "nbb_not_found":
                        self.stats["skipped"].append("%s:%s (borrado durante la copia)" % (e["r"], e["p"]))
                    else:
                        self.stats["warnings"].append("%s:%s → %s" % (e["r"], e["p"], hdr.get("e")))
                    continue
                dest = safe_join(self.files_dir, e["r"], e["p"])
                os.makedirs(os.path.dirname(dest), exist_ok=True)
                fd, tmp = tempfile.mkstemp(prefix=".nbb-", dir=os.path.dirname(dest))
                try:
                    with os.fdopen(fd, "wb") as out:
                        read_exact(resp, hdr["s"], out)
                    trailer = resp.read(1) + resp.readline()
                    if trailer == b"\nOK\n":
                        self.store(e, tmp, hdr["s"], hdr["m"])
                        tmp = None
                    elif trailer == b"\nTRUNC\n":
                        retry.append(e)  # cambió mientras se leía
                    else:
                        raise NbbError("Lote desalineado en %s" % e["p"])
                finally:
                    if tmp and os.path.exists(tmp):
                        os.remove(tmp)
        missing = [by_key[k] for k in by_key if k not in seen]
        if missing:
            raise NbbError("Faltan %d archivos en la respuesta del lote" % len(missing))
        return retry

    def sync_files(self, files):
        todo = self.plan(files)
        total = sum(e["s"] for e in todo)
        log("[%s] archivos: %d sin cambios, %d a descargar (%.1f MB)",
            self.name, self.stats["unchanged_files"], len(todo), total / 1e6)
        big = [e for e in todo if e["s"] >= self.cfg["big_file_bytes"]]
        small = sorted((e for e in todo if e["s"] < self.cfg["big_file_bytes"]), key=lambda e: (e["r"], e["p"]))

        batch, size, done_bytes, last_report = [], 0, 0, time.time()
        pending_retry = []

        def flush(b):
            for attempt in range(self.cfg["retries"] + 1):
                try:
                    pending_retry.extend(self.fetch_pack(b))
                    self.save_index()
                    return
                except NbbError as err:
                    if attempt >= self.cfg["retries"]:
                        raise
                    log("[%s] lote fallido (%s), reintentando", self.name, err)
                    self.save_index()
                    b = [e for e in b if self.index.get(self.key(e["r"], e["p"])) != (e["s"], e["m"])]
                    time.sleep(5)

        for e in small:
            if batch and (len(batch) >= self.cfg["pack_max_files"] or size + e["s"] > self.cfg["pack_max_bytes"]):
                flush(batch)
                done_bytes += size
                batch, size = [], 0
                if time.time() - last_report > 30:
                    log("[%s]   %.1f/%.1f MB", self.name, done_bytes / 1e6, total / 1e6)
                    last_report = time.time()
            batch.append(e)
            size += e["s"]
        if batch:
            flush(batch)
        for e in big + pending_retry:
            self.fetch_big(e)
            self.save_index()

    def prune(self, files, dirs):
        """Borra del espejo lo que ya no existe en el sitio."""
        wanted_dirs = {safe_join(self.files_dir, d["r"], d["p"]) for d in dirs}
        for k in list(self.index):
            if k not in files:
                r, p = k.split("\0", 1)
                try:
                    path = safe_join(self.files_dir, r, p)
                    if os.path.isfile(path):
                        os.remove(path)
                        self.stats["deleted_files"] += 1
                except (OSError, NbbError):
                    pass
                del self.index[k]
        if os.path.isdir(self.files_dir):
            # Ficheros sueltos que no están en el índice (restos de ejecuciones cortadas).
            known = {safe_join(self.files_dir, *k.split("\0", 1)) for k in files}
            for dirpath, dirnames, filenames in os.walk(self.files_dir, topdown=False):
                for fn in filenames:
                    p = os.path.join(dirpath, fn)
                    if p not in known:
                        os.remove(p)
                for dn in dirnames:
                    p = os.path.join(dirpath, dn)
                    if p not in wanted_dirs and not os.listdir(p):
                        os.rmdir(p)
        for d in sorted(wanted_dirs):
            os.makedirs(d, exist_ok=True)

    def install_db(self, staging):
        """Sustituye current/db por el nuevo volcado, conservando los ficheros idénticos (menos espacio en snapshots)."""
        new_db = os.path.join(staging, "db")
        cur_db = os.path.join(self.current, "db")
        os.makedirs(new_db, exist_ok=True)
        if os.path.isdir(cur_db):
            # Emparejar por nombre de tabla (el prefijo NNNN- cambia si se añaden tablas).
            old_by_table = {fn.split("-", 1)[-1]: fn for fn in os.listdir(cur_db)}
            for fn in os.listdir(new_db):
                old_fn = old_by_table.get(fn.split("-", 1)[-1])
                if not old_fn:
                    continue
                old = os.path.join(cur_db, old_fn)
                new = os.path.join(new_db, fn)
                try:
                    if os.path.isfile(old) and sha256_file(old, True) == sha256_file(new, True):
                        os.replace(old, new)
                except (OSError, zlib.error, EOFError):
                    pass
        old_dir = os.path.join(self.meta_dir, "db.old")
        if os.path.isdir(old_dir):
            shutil.rmtree(old_dir)
        if os.path.isdir(cur_db):
            os.replace(cur_db, old_dir)
        os.replace(new_db, cur_db)
        shutil.rmtree(old_dir, ignore_errors=True)

    def run(self):
        started = time.time()
        os.makedirs(self.meta_dir, exist_ok=True)
        os.makedirs(self.files_dir, exist_ok=True)
        lock = open(os.path.join(self.meta_dir, "lock"), "w")
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError:
            raise NbbError("Ya hay otra ejecución en curso para este sitio")
        result = {"site": self.name, "ok": False, "started": time.strftime("%Y-%m-%dT%H:%M:%S%z")}
        try:
            st = self.check()
            log("[%s] conectado: WordPress %s, PHP %s, plugin %s", self.name,
                st["wp_version"], st["php_version"], st["plugin_version"])
            if st.get("storage_public"):
                self.stats["warnings"].append("La carpeta de trabajo del plugin está dentro de la raíz web")
            job_id, job = self.prepare()

            staging = os.path.join(self.meta_dir, "staging")
            shutil.rmtree(staging, ignore_errors=True)
            os.makedirs(staging)
            self.download_artifacts(job_id, job["artifacts"], staging)
            files, dirs, links = self.read_manifest(os.path.join(staging, "manifest.ndjson.gz"))

            self.load_index()
            self.sync_files(files)
            self.prune(files, dirs)
            self.save_index()
            self.install_db(staging)
            for name in ("meta.json", "manifest.ndjson.gz"):
                os.replace(os.path.join(staging, name), os.path.join(self.current, name))
            write_json(os.path.join(self.current, "symlinks.json"), links)
            shutil.rmtree(staging, ignore_errors=True)

            try:
                self.client.json("POST", "/nbb/v1/jobs/%s/complete" % job_id)
            except NbbError as err:
                self.stats["warnings"].append("No se pudo cerrar la copia en el servidor: %s" % err)

            with open(os.path.join(self.current, "meta.json"), encoding="utf-8") as f:
                meta = json.load(f)
            result.update({
                "ok": True,
                "job": job_id,
                "wp_version": meta.get("wp_version"),
                "tables": len(meta.get("tables", [])),
                "rows": meta.get("rows"),
                "files_total": len(files),
                "bytes_total": meta.get("bytes"),
                "scan_errors": meta.get("scan_errors", []),
            })
        except Exception as err:  # noqa: BLE001 — queremos registrar cualquier fallo
            result["error"] = str(err)
            result["trace"] = traceback.format_exc(limit=5)
            raise
        finally:
            result.update(self.stats)
            result["skipped"] = result["skipped"][:200]
            result["finished"] = time.strftime("%Y-%m-%dT%H:%M:%S%z")
            result["duration_s"] = round(time.time() - started, 1)
            write_json(os.path.join(self.root, "last_run.json"), result)
            fcntl.flock(lock, fcntl.LOCK_UN)
            lock.close()
        return result


# --------------------------------------------------------------------------- #
# Restauración
# --------------------------------------------------------------------------- #

TMP_PREFIX = "nbbr_"
MIN_BODY = 64 * 1024


def temp_table_name(table):
    return TMP_PREFIX + hashlib.sha1(table.encode()).hexdigest()[:12]


def quote_ident(name):
    return b"`" + name.encode().replace(b"`", b"``") + b"`"


def iter_sql_statements(path):
    """Sentencias de un volcado del plugin (una por línea salvo CREATE TABLE, que ocupa varias)."""
    buf = []
    with gzip.open(path, "rb") as f:
        for line in f:
            if not buf and (line.startswith(b"-- ") or not line.strip()):
                continue
            buf.append(line)
            if line.rstrip(b"\r\n").endswith(b";"):
                stmt = b"".join(buf).rstrip(b"\r\n")
                buf = []
                yield stmt[:-1]
    if buf and b"".join(buf).strip():
        raise NbbError("Volcado incompleto: %s" % os.path.basename(path))


def table_of_dump(path):
    with gzip.open(path, "rb") as f:
        for _ in range(5):
            line = f.readline()
            if line.startswith(b"-- Table: "):
                return line[10:].rstrip(b"\r\n").decode("utf-8")
    raise NbbError("No se reconoce la tabla de %s" % os.path.basename(path))


class SiteRestore(SiteBackup):
    """Restaura en la web una copia guardada en el NAS (carpeta con files/, db/, manifest, meta)."""

    def __init__(self, site, cfg, dest):
        super().__init__(site, cfg, dest)
        self.body_limit = cfg["restore_body_bytes"]
        self.session = None
        self.protected = None
        self.wp_config = False
        self.rstats = {"tables": 0, "statements": 0, "uploaded_files": 0, "uploaded_bytes": 0,
                       "deleted_files": 0, "created_dirs": 0, "deleted_dirs": 0, "errors": []}

    # ---- utilidades ------------------------------------------------------ #
    def post(self, route, params=None, body=None, raw=None):
        return self.client.json("POST", "/nbb/v1/restore/%s/%s" % (self.session, route), params, body, raw)

    def adaptive(self, send, items, size_of, label):
        """Envía 'items' en lotes de hasta self.body_limit bytes; si el servidor responde 413, reduce el lote."""
        i = 0
        while i < len(items):
            batch, size = [], 0
            while i + len(batch) < len(items):
                s = size_of(items[i + len(batch)])
                if batch and size + s > self.body_limit:
                    break
                batch.append(items[i + len(batch)])
                size += s
            try:
                send(batch)
            except HttpError as e:
                if e.status == 413 and (self.body_limit > MIN_BODY or len(batch) > 1):
                    self.body_limit = max(MIN_BODY, self.body_limit // 2)
                    log("[%s]   el servidor rechaza peticiones grandes; bajando a %d KB", self.name, self.body_limit // 1024)
                    continue
                raise HttpError(e.status, json.dumps({"message": "%s: %s" % (label, e)}))
            i += len(batch)

    def want(self, e):
        r, p = e["r"], e["p"]
        if self.protected and r == self.protected["r"] and (p == self.protected["p"] or p.startswith(self.protected["p"] + "/")):
            return False
        if not self.wp_config and ((r == "site" and p == "wp-config.php") or r == "parent"):
            return False
        return True

    # ---- base de datos --------------------------------------------------- #
    def import_db(self, source):
        db_dir = os.path.join(source, "db")
        files = sorted(fn for fn in os.listdir(db_dir) if fn.endswith(".sql.gz"))
        tables, views = [], None
        for fn in files:
            path = os.path.join(db_dir, fn)
            if fn.startswith("9999-"):
                views = path
                continue
            table = table_of_dump(path)
            for attempt in range(3):
                try:
                    n = self.import_table(path, table)
                    break
                except NbbError as err:
                    if attempt == 2 or (isinstance(err, HttpError) and 400 <= err.status < 500):
                        raise NbbError("No se pudo importar %s: %s" % (table, err))
                    log("[%s]   reintentando %s (%s)", self.name, table, err)
                    time.sleep(3)
            tables.append(table)
            self.rstats["tables"] += 1
            self.rstats["statements"] += n
            log("[%s]   tabla %s importada (%d sentencias)", self.name, table, n)
        return tables, views

    def import_table(self, path, table):
        q = quote_ident(table)
        tmp = b"`" + temp_table_name(table).encode() + b"`"
        prefixes = (b"DROP TABLE IF EXISTS ", b"CREATE TABLE ", b"INSERT INTO ")
        stmts = []
        for stmt in iter_sql_statements(path):
            for pfx in prefixes:
                if stmt.startswith(pfx + q):
                    stmt = pfx + tmp + stmt[len(pfx) + len(q):]
                    if pfx == b"CREATE TABLE ":
                        stmt = re.sub(rb"CONSTRAINT `(?:[^`]|``)+` FOREIGN KEY", b"FOREIGN KEY", stmt)
                    break
            else:
                if not stmt.startswith(b"SET "):
                    raise NbbError("Sentencia inesperada en el volcado de %s" % table)
            stmts.append(stmt)
        self.send_statements(stmts, "Importando %s" % table)
        return len(stmts)

    def send_statements(self, stmts, label):
        def send(batch):
            raw = b"".join(str(len(s)).encode() + b"\n" + s for s in batch)
            self.post("sql", raw=raw)
        self.adaptive(send, stmts, lambda s: len(s) + 12, label)

    # ---- archivos -------------------------------------------------------- #
    def live_manifest(self):
        job_id, job = self.prepare(skip_db=True)
        staging = os.path.join(self.meta_dir, "restore-staging")
        shutil.rmtree(staging, ignore_errors=True)
        os.makedirs(staging)
        try:
            arts = [a for a in job["artifacts"] if a["name"] == "manifest.ndjson.gz"]
            self.download_artifacts(job_id, arts, staging)
            files, dirs, _ = self.read_manifest(os.path.join(staging, "manifest.ndjson.gz"))
        finally:
            shutil.rmtree(staging, ignore_errors=True)
            try:
                self.client.json("POST", "/nbb/v1/jobs/%s/complete" % job_id)
            except NbbError:
                pass
        return files, dirs

    def restore_files(self, source, live_files, live_dirs, delete_extra):
        src_files, src_dirs, _ = self.read_manifest(os.path.join(source, "manifest.ndjson.gz"))
        src_root = os.path.join(source, "files")
        k = self.key
        src_files = {key: e for key, e in src_files.items() if self.want(e)}
        live_files = {key: e for key, e in live_files.items() if self.want(e)}
        src_dir_keys = {k(d["r"], d["p"]) for d in src_dirs if self.want(d)}
        live_dir_keys = {k(d["r"], d["p"]) for d in live_dirs if self.want(d)}

        upload = [e for key, e in src_files.items()
                  if key not in live_files or live_files[key]["s"] != e["s"] or live_files[key]["m"] != e["m"]]
        extra = [e for key, e in live_files.items() if key not in src_files] if delete_extra else []
        new_dirs = sorted((d for d in src_dirs if self.want(d) and k(d["r"], d["p"]) not in live_dir_keys),
                          key=lambda d: d["p"].count("/"))
        old_dirs = sorted((d for d in live_dirs if self.want(d) and k(d["r"], d["p"]) not in src_dir_keys),
                          key=lambda d: -d["p"].count("/")) if delete_extra else []
        log("[%s] archivos: %d a subir (%.1f MB), %d a borrar, %d iguales", self.name, len(upload),
            sum(e["s"] for e in upload) / 1e6, len(extra), len(src_files) - len(upload))

        def collect(res):
            for err in res.get("errors", []):
                self.rstats["errors"].append("%s: %s" % (err.get("p"), err.get("e")))

        # 1. borrar lo que sobra (deja sitio a cambios archivo↔carpeta)
        def send_delete(batch):
            res = self.post("delete", body={"files": [{"r": e["r"], "p": e["p"]} for e in batch]})
            self.rstats["deleted_files"] += res.get("deleted", 0)
            collect(res)
        for i in range(0, len(extra), 500):
            send_delete(extra[i:i + 500])

        # 2. carpetas nuevas (incluidas las vacías)
        for i in range(0, len(new_dirs), 500):
            res = self.post("mkdir", body={"dirs": [{"r": d["r"], "p": d["p"]} for d in new_dirs[i:i + 500]]})
            self.rstats["created_dirs"] += res.get("created", 0)
            collect(res)

        # 3. archivos: pequeños en lotes, grandes por trozos
        big = [e for e in upload if e["s"] > self.body_limit // 2]
        small = sorted((e for e in upload if e["s"] <= self.body_limit // 2), key=lambda e: (e["r"], e["p"]))
        done, total, last = 0, sum(e["s"] for e in upload), time.time()

        def send_pack(batch):
            nonlocal done, last
            parts = [b"NBBUP1\n"]
            for e in batch:
                with open(safe_join(src_root, e["r"], e["p"]), "rb") as f:
                    data = f.read()
                hdr = {"r": e["r"], "p": e["p"], "s": len(data), "m": e["m"]}
                parts.append(json.dumps(hdr, ensure_ascii=False).encode() + b"\n" + data)
            parts.append(b"END\n")
            res = self.post("pack", raw=b"".join(parts))
            self.rstats["uploaded_files"] += res.get("written", 0)
            self.rstats["uploaded_bytes"] += sum(e["s"] for e in batch)
            collect(res)
            done += sum(e["s"] for e in batch)
            if time.time() - last > 15:
                log("[%s]   %.1f/%.1f MB subidos", self.name, done / 1e6, total / 1e6)
                last = time.time()

        self.adaptive(send_pack, small, lambda e: e["s"] + len(e["p"]) + 80, "Subiendo archivos")
        for e in big:
            self.upload_big(src_root, e)
            done += e["s"]

        # 4. carpetas que sobran (solo si quedan vacías), de la más profunda a la más alta
        for i in range(0, len(old_dirs), 500):
            res = self.post("delete", body={"dirs": [{"r": d["r"], "p": d["p"]} for d in old_dirs[i:i + 500]]})
            self.rstats["deleted_dirs"] += res.get("deleted", 0)

    def upload_big(self, src_root, e):
        path = safe_join(src_root, e["r"], e["p"])
        size = os.path.getsize(path)
        offset, attempts = 0, 0
        with open(path, "rb") as f:
            while True:
                f.seek(offset)
                data = f.read(self.body_limit)
                final = offset + len(data) >= size
                params = {"r": e["r"], "p": e["p"], "offset": str(offset)}
                if final:
                    params.update({"final": "1", "mtime": str(e["m"])})
                try:
                    self.post("chunk", params, raw=data)
                except HttpError as err:
                    attempts += 1
                    if err.status == 413 and self.body_limit > MIN_BODY:
                        self.body_limit = max(MIN_BODY, self.body_limit // 2)
                        continue
                    if err.status == 409 and attempts < 5:
                        try:
                            offset = int(json.loads(err.body).get("data", {}).get("size", 0))
                        except (ValueError, AttributeError):
                            offset = 0
                        continue
                    raise NbbError("Subiendo %s: %s" % (e["p"], err))
                offset += len(data)
                if final:
                    break
        self.rstats["uploaded_files"] += 1
        self.rstats["uploaded_bytes"] += size

    # ---- orquestación ---------------------------------------------------- #
    def restore(self, source, do_db=True, do_files=True, delete_extra=True, wp_config=False,
                maintenance=True, label=""):
        started = time.time()
        source = os.path.abspath(source)
        if not os.path.isfile(os.path.join(source, "meta.json")):
            raise NbbError("La carpeta de origen no es una copia válida: %s" % source)
        if not (do_db or do_files):
            raise NbbError("No hay nada que restaurar (ni base de datos ni archivos)")
        with open(os.path.join(source, "meta.json"), encoding="utf-8") as f:
            meta = json.load(f)
        self.wp_config = wp_config
        os.makedirs(self.meta_dir, exist_ok=True)
        lock = open(os.path.join(self.meta_dir, "lock"), "w")
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError:
            raise NbbError("Hay una copia o restauración en curso para este sitio")
        result = {"site": self.name, "ok": False, "source": label or source,
                  "backup_date": meta.get("finished"), "db": do_db, "files": do_files,
                  "started": time.strftime("%Y-%m-%dT%H:%M:%S%z")}
        try:
            st = self.check()
            if not st.get("restore_allowed"):
                raise NbbError("La web no permite restauraciones. Actívalas en Ajustes › NAS Backup "
                               "(plugin 1.1 o superior).")
            if st.get("site_url", "").rstrip("/") != (meta.get("site_url") or "").rstrip("/"):
                raise NbbError("La copia es de %s y la web es %s: no se restaura en otra web"
                               % (meta.get("site_url"), st.get("site_url")))
            self.protected = st.get("plugin_path")
            if st.get("max_body"):
                self.body_limit = max(MIN_BODY, min(self.body_limit, int(st["max_body"] * 0.7)))
            log("[%s] restaurando copia del %s (%s%s)", self.name, meta.get("finished"),
                "base de datos" if do_db else "", " + archivos" if do_db and do_files else ("archivos" if do_files else ""))

            live = self.live_manifest() if do_files else None
            sess = self.client.json("POST", "/nbb/v1/restore", {"maintenance": "1"} if maintenance else {})
            self.session = sess["id"]
            try:
                tables, views = ([], None)
                if do_db:
                    log("[%s] importando la base de datos en tablas temporales…", self.name)
                    tables, views = self.import_db(source)
                if do_files:
                    log("[%s] restaurando archivos…", self.name)
                    self.restore_files(source, live[0], live[1], delete_extra)
                if do_db:
                    log("[%s] activando la base de datos restaurada (%d tablas)…", self.name, len(tables))
                    self.post("swap", body={"tables": tables})
                    if views:
                        self.send_statements(list(iter_sql_statements(views)), "Vistas")
                self.post("finish", body={}, params={"source": label or meta.get("finished", "")})
            except BaseException:
                try:
                    self.post("abort", body={})
                except Exception:  # noqa: BLE001
                    pass
                raise
            result["ok"] = True
            result["site_http"] = self.probe_home(st.get("home_url") or st.get("site_url"))
        except Exception as err:  # noqa: BLE001
            result["error"] = str(err)
            raise
        finally:
            result.update(self.rstats)
            result["errors"] = result["errors"][:200]
            result["finished"] = time.strftime("%Y-%m-%dT%H:%M:%S%z")
            result["duration_s"] = round(time.time() - started, 1)
            write_json(os.path.join(self.root, "last_restore.json"), result)
            fcntl.flock(lock, fcntl.LOCK_UN)
            lock.close()
        return result

    def probe_home(self, url):
        if not url:
            return None
        try:
            req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
            with urllib.request.urlopen(req, timeout=30, context=self.client.ctx) as r:
                return r.status
        except urllib.error.HTTPError as e:
            return e.code
        except Exception:  # noqa: BLE001
            return None


# --------------------------------------------------------------------------- #

def ping(url, ok, message):
    if not url:
        return
    try:
        target = url if ok else url.rstrip("/") + "/fail"
        req = urllib.request.Request(target, data=message.encode()[:10000], method="POST")
        urllib.request.urlopen(req, timeout=15).read()
    except Exception as err:  # noqa: BLE001
        log("Aviso: no se pudo notificar al healthcheck: %s", err)


def load_config(path):
    st = os.stat(path)
    if st.st_mode & (stat.S_IRWXG | stat.S_IRWXO):
        log("AVISO: %s es legible por otros usuarios; usa chmod 600 (contiene claves).", path)
    with open(path, encoding="utf-8") as f:
        cfg = json.load(f)
    merged = dict(DEFAULTS)
    merged.update({k: v for k, v in cfg.items() if k != "sites"})
    if not merged.get("dest"):
        raise NbbError("Falta 'dest' en la configuración")
    return merged, cfg.get("sites", [])


def main():
    ap = argparse.ArgumentParser(description="Copias de WordPress hacia el NAS (NAS Backup Bridge)")
    ap.add_argument("command", nargs="?", default="run", choices=["run", "check", "restore"])
    ap.add_argument("--config", required=True)
    ap.add_argument("--site", action="append", help="Solo este sitio (se puede repetir)")
    ap.add_argument("--source", help="restore: carpeta de la copia (la que contiene meta.json)")
    ap.add_argument("--label", default="", help="restore: descripción del origen para el registro")
    ap.add_argument("--no-db", action="store_true", help="restore: no restaurar la base de datos")
    ap.add_argument("--no-files", action="store_true", help="restore: no restaurar archivos")
    ap.add_argument("--keep-extra", action="store_true", help="restore: no borrar archivos que no estén en la copia")
    ap.add_argument("--wp-config", action="store_true", help="restore: restaurar también wp-config.php")
    ap.add_argument("--no-maintenance", action="store_true", help="restore: no mostrar página de mantenimiento")
    args = ap.parse_args()

    cfg, sites = load_config(args.config)
    if args.command == "restore":
        if not args.site or len(args.site) != 1 or not args.source:
            print("restore necesita exactamente un --site y --source", file=sys.stderr)
            return 2
        site = next((s for s in sites if s["name"] == args.site[0]), None)
        if not site:
            print("Sitio no encontrado: %s" % args.site[0], file=sys.stderr)
            return 2
        try:
            res = SiteRestore(site, cfg, cfg["dest"]).restore(
                args.source, do_db=not args.no_db, do_files=not args.no_files,
                delete_extra=not args.keep_extra, wp_config=args.wp_config,
                maintenance=not args.no_maintenance, label=args.label)
        except Exception as err:  # noqa: BLE001
            print("[%s] ERROR en la restauración: %s" % (args.site[0], err), file=sys.stderr, flush=True)
            return 1
        log("[%s] restauración completada en %ss — %d tablas, %d archivos subidos (%.1f MB), %d borrados; la web responde %s",
            args.site[0], res["duration_s"], res["tables"], res["uploaded_files"], res["uploaded_bytes"] / 1e6,
            res["deleted_files"], res.get("site_http"))
        for e in res["errors"][:20]:
            log("    aviso: %s", e)
        return 0
    if args.site:
        sites = [s for s in sites if s["name"] in args.site]
        if not sites:
            print("Ningún sitio coincide con --site", file=sys.stderr)
            return 2
    sites = [s for s in sites if not s.get("disabled")]

    failures = []
    summary = []
    for site in sites:
        try:
            job = SiteBackup(site, cfg, cfg["dest"])
            if args.command == "check":
                st = job.check()
                log("[%s] OK — WordPress %s, PHP %s, plugin %s, raíces %s, max_execution_time %s, última copia %s",
                    job.name, st["wp_version"], st["php_version"], st["plugin_version"],
                    ",".join(st["roots"]), st["max_execution_time"],
                    (st.get("last_job") or {}).get("completed"))
                continue
            res = job.run()
            line = "[%s] OK en %ss — %s archivos (%s descargados, %.1f MB), %s borrados, %s tablas" % (
                job.name, res["duration_s"], res["files_total"], res["downloaded_files"],
                res["downloaded_bytes"] / 1e6, res["deleted_files"], res["tables"])
            for w in res["warnings"]:
                line += "\n    aviso: " + w
            log(line)
            summary.append(line)
        except Exception as err:  # noqa: BLE001
            msg = "[%s] ERROR: %s" % (site.get("name"), err)
            print(msg, file=sys.stderr, flush=True)
            failures.append(msg)

    if args.command == "run":
        ping(cfg.get("healthcheck_url"), not failures, "\n".join(summary + failures))
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
