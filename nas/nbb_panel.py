#!/usr/bin/env python3
"""
NAS Backup Bridge — panel web.

Lista las webs con copia, su último resultado y sus puntos de restauración (snapshots ZFS),
y permite hacer una copia al momento, añadir webs, descargar copias y restaurarlas.

Solo biblioteca estándar. Se ejecuta en el propio TrueNAS (como root, para poder leer las
copias y crear snapshots) y escucha en 127.0.0.1; se publica con Tailscale Serve.

    nbb_panel.py --config sites.json set-password      # fija la contraseña del panel
    nbb_panel.py --config sites.json serve [--port 8765]
"""

import argparse
import base64
import getpass
import hashlib
import hmac
import http.server
import json
import os
import re
import secrets
import shutil
import socketserver
import subprocess
import sys
import threading
import time
import traceback
import urllib.parse
import zipfile

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import nbb_pull  # noqa: E402

NAME_RE = re.compile(r"^[a-z0-9][a-z0-9._-]{0,62}$")
COOKIE = "nbb_panel"
SESSION_TTL = 12 * 3600
PBKDF2_ITER = 600_000
POINT_KEEP_DAYS = 30


def now_iso():
    return time.strftime("%Y-%m-%dT%H:%M:%S%z")


def read_json(path, default=None):
    try:
        with open(path, encoding="utf-8") as f:
            return json.load(f)
    except (OSError, ValueError):
        return default


def write_json_private(path, data):
    tmp = path + ".tmp"
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)
        f.write("\n")
    os.replace(tmp, path)


# --------------------------------------------------------------------------- #
# Configuración y contraseña
# --------------------------------------------------------------------------- #

class Config:
    def __init__(self, path):
        self.path = os.path.abspath(path)
        self.panel_path = os.path.join(os.path.dirname(self.path), "panel.json")
        self.lock = threading.Lock()

    def load(self):
        cfg, sites = nbb_pull.load_config(self.path)
        return cfg, sites

    def raw(self):
        with open(self.path, encoding="utf-8") as f:
            return json.load(f)

    def save_sites(self, mutate):
        with self.lock:
            data = self.raw()
            data["sites"] = mutate(list(data.get("sites", [])))
            write_json_private(self.path, data)

    def panel(self):
        return read_json(self.panel_path, {}) or {}

    def set_password(self, password):
        salt = secrets.token_hex(16)
        dk = hashlib.pbkdf2_hmac("sha256", password.encode(), bytes.fromhex(salt), PBKDF2_ITER).hex()
        data = self.panel()
        data["password"] = "pbkdf2_sha256$%d$%s$%s" % (PBKDF2_ITER, salt, dk)
        data["cookie_secret"] = secrets.token_hex(32)  # cierra las sesiones abiertas
        data.setdefault("cookie_secure", True)
        write_json_private(self.panel_path, data)

    def check_password(self, password):
        stored = self.panel().get("password", "")
        try:
            algo, it, salt, dk = stored.split("$")
        except ValueError:
            return False
        test = hashlib.pbkdf2_hmac("sha256", password.encode(), bytes.fromhex(salt), int(it)).hex()
        return hmac.compare_digest(test, dk)


# --------------------------------------------------------------------------- #
# Puntos de restauración: snapshots ZFS (o copias con enlaces duros si no hay ZFS)
# --------------------------------------------------------------------------- #

class Points:
    KINDS = (
        ("diaria-", "daily", "Diaria"),
        ("mensual-", "monthly", "Mensual"),
        ("nbb-antes-", "pre", "Antes de restaurar"),
        ("nbb-fuente-", "source", None),  # interno: oculto
    )

    def __init__(self, dest):
        self.dest = os.path.abspath(dest)
        self.zfs = None
        self.cache = {}
        self.lock = threading.Lock()
        self.detect()

    def detect(self):
        if not shutil.which("zfs"):
            return
        try:
            out = subprocess.run(["zfs", "list", "-H", "-o", "name,mountpoint", "-t", "filesystem"],
                                 capture_output=True, text=True, timeout=30, check=True).stdout
        except (subprocess.SubprocessError, OSError):
            return
        best = None
        for line in out.splitlines():
            name, mnt = line.split("\t")
            if mnt.startswith("/") and (self.dest == mnt or self.dest.startswith(mnt.rstrip("/") + "/")):
                if best is None or len(mnt) > len(best[1]):
                    best = (name, mnt)
        if best:
            self.zfs = {"dataset": best[0], "mountpoint": best[1],
                        "rel": os.path.relpath(self.dest, best[1])}

    def classify(self, name):
        for prefix, kind, label in self.KINDS:
            if name.startswith(prefix):
                return kind, label
        return "other", "Instantánea"

    def _list_raw(self):
        """[(nombre, creado_ts, carpeta_base)] — carpeta_base equivale a 'dest' dentro del punto."""
        if self.zfs:
            out = subprocess.run(["zfs", "list", "-H", "-p", "-t", "snapshot", "-o", "name,creation",
                                  "-s", "creation", "-d", "1", self.zfs["dataset"]],
                                 capture_output=True, text=True, timeout=60, check=True).stdout
            res = []
            for line in out.splitlines():
                full, created = line.split("\t")
                snap = full.split("@", 1)[1]
                base = os.path.join(self.zfs["mountpoint"], ".zfs", "snapshot", snap)
                if self.zfs["rel"] != ".":
                    base = os.path.join(base, self.zfs["rel"])
                res.append((snap, int(created), base))
            return res
        root = os.path.join(self.dest, ".nbb-points")
        res = []
        if os.path.isdir(root):
            for name in sorted(os.listdir(root)):
                p = os.path.join(root, name)
                res.append((name, int(os.stat(p).st_mtime), p))
        return res

    def list(self, site, refresh=False):
        with self.lock:
            hit = self.cache.get(site)
            if hit and not refresh and time.time() - hit[0] < 60:
                return hit[1]
        points = []
        current = os.path.join(self.dest, site, "current")
        meta = read_json(os.path.join(current, "meta.json"))
        if meta:
            points.append(self._point("current", "current", "Última copia", current, meta,
                                      int(os.stat(os.path.join(current, "meta.json")).st_mtime)))
        for name, created, base in reversed(self._list_raw()):
            kind, label = self.classify(name)
            if label is None:
                continue
            path = os.path.join(base, site, "current")
            meta = read_json(os.path.join(path, "meta.json"))
            if meta:
                points.append(self._point("p:" + name, kind, label, path, meta, created))
        head, rest = points[:1] if points and points[0]["id"] == "current" else [], points
        if head:
            rest = points[1:]
        rest.sort(key=lambda p: (p["backup_finished"] or "", p["created"]), reverse=True)
        points = head + rest
        with self.lock:
            self.cache[site] = (time.time(), points)
        return points

    @staticmethod
    def _point(pid, kind, label, path, meta, created):
        return {
            "id": pid, "kind": kind, "label": label, "path": path, "created": created,
            "backup_finished": meta.get("finished"), "wp_version": meta.get("wp_version"),
            "files": meta.get("files"), "bytes": meta.get("bytes"), "tables": len(meta.get("tables", [])),
            "rows": meta.get("rows"),
        }

    def resolve(self, site, pid):
        for p in self.list(site):
            if p["id"] == pid:
                return p
        for p in self.list(site, refresh=True):
            if p["id"] == pid:
                return p
        return None

    def create(self, name, site):
        """Crea un punto con este nombre y devuelve la ruta de la copia del sitio dentro de él."""
        if not re.match(r"^nbb-[a-z0-9._-]+$", name):
            raise ValueError("nombre de punto no válido")
        if self.zfs:
            subprocess.run(["zfs", "snapshot", "%s@%s" % (self.zfs["dataset"], name)],
                           check=True, capture_output=True, text=True, timeout=60)
            base = os.path.join(self.zfs["mountpoint"], ".zfs", "snapshot", name)
            if self.zfs["rel"] != ".":
                base = os.path.join(base, self.zfs["rel"])
        else:
            base = os.path.join(self.dest, ".nbb-points", name)
            src = os.path.join(self.dest, site, "current")
            dst = os.path.join(base, site, "current")
            os.makedirs(os.path.dirname(dst), exist_ok=True)
            shutil.copytree(src, dst, copy_function=os.link, symlinks=True)
        with self.lock:
            self.cache.pop(site, None)
        return os.path.join(base, site, "current")

    def destroy(self, name):
        if not name.startswith("nbb-"):
            return  # nunca se tocan los snapshots de las tareas programadas
        if self.zfs:
            subprocess.run(["zfs", "destroy", "%s@%s" % (self.zfs["dataset"], name)],
                           capture_output=True, text=True, timeout=120)
        else:
            shutil.rmtree(os.path.join(self.dest, ".nbb-points", name), ignore_errors=True)
        with self.lock:
            self.cache.clear()

    def prune(self):
        limit = time.time() - POINT_KEEP_DAYS * 86400
        for name, created, _ in self._list_raw():
            if name.startswith("nbb-fuente-") and created < time.time() - 86400:
                self.destroy(name)
            elif name.startswith("nbb-antes-") and created < limit:
                self.destroy(name)


# --------------------------------------------------------------------------- #
# Tareas (copias y restauraciones) en segundo plano
# --------------------------------------------------------------------------- #

class Tasks:
    def __init__(self, folder):
        self.folder = folder
        os.makedirs(folder, mode=0o700, exist_ok=True)
        self.lock = threading.Lock()
        self.items = {}
        for fn in sorted(os.listdir(folder))[-200:]:
            if fn.endswith(".json"):
                t = read_json(os.path.join(folder, fn))
                if t:
                    if t.get("status") == "running":
                        t["status"] = "error"
                        t["error"] = "El panel se reinició durante la tarea"
                    self.items[t["id"]] = t

    def running_for(self, site):
        with self.lock:
            for t in self.items.values():
                if t["site"] == site and t["status"] == "running":
                    return t
        return None

    def recent(self, limit=30):
        with self.lock:
            items = sorted(self.items.values(), key=lambda t: t["started"], reverse=True)
        return [self.public(t) for t in items[:limit]]

    def public(self, t):
        return {k: t.get(k) for k in ("id", "site", "kind", "title", "status", "started", "finished", "error", "params")}

    def log_path(self, tid):
        return os.path.join(self.folder, tid + ".log")

    def save(self, t):
        with self.lock:
            data = dict(t)
        write_json_private(os.path.join(self.folder, t["id"] + ".json"), data)

    def get(self, tid, tail=400):
        with self.lock:
            t = self.items.get(tid)
        if not t:
            return None
        out = self.public(t)
        try:
            with open(self.log_path(tid), encoding="utf-8", errors="replace") as f:
                out["log"] = f.readlines()[-tail:]
        except OSError:
            out["log"] = []
        return out

    def start(self, site, kind, title, fn, params=None):
        if self.running_for(site):
            raise ValueError("Ya hay una tarea en curso para %s" % site)
        tid = time.strftime("%Y%m%d-%H%M%S-") + secrets.token_hex(3)
        t = {"id": tid, "site": site, "kind": kind, "title": title, "status": "running",
             "started": int(time.time()), "finished": None, "error": None, "params": params or {}}
        with self.lock:
            self.items[tid] = t
        self.save(t)

        def runner():
            logf = open(self.log_path(tid), "a", encoding="utf-8")

            def say(msg):
                logf.write(time.strftime("%Y-%m-%d %H:%M:%S ") + msg + "\n")
                logf.flush()
            try:
                fn(say, logf)
                t["status"] = "ok"
            except Exception as err:  # noqa: BLE001
                t["status"] = "error"
                t["error"] = str(err)
                say("ERROR: %s" % err)
            finally:
                t["finished"] = int(time.time())
                self.save(t)
                logf.close()

        threading.Thread(target=runner, daemon=True, name="task-" + tid).start()
        return t


# --------------------------------------------------------------------------- #
# Aplicación
# --------------------------------------------------------------------------- #

class Panel:
    def __init__(self, config_path):
        self.config = Config(config_path)
        cfg, _ = self.config.load()
        self.dest = cfg["dest"]
        self.points = Points(self.dest)
        self.tasks = Tasks(os.path.join(self.dest, ".nbb-panel", "tasks"))
        self.fail_times = []
        self.fail_lock = threading.Lock()

    # ---- utilidades -------------------------------------------------------- #
    def site_cfg(self, name):
        cfg, sites = self.config.load()
        site = next((s for s in sites if s["name"] == name), None)
        return cfg, site

    def run_cli(self, args, say, logf):
        cmd = [sys.executable, "-u", os.path.join(HERE, "nbb_pull.py"), "--config", self.config.path] + args
        say("$ nbb_pull.py " + " ".join(a for a in args))
        proc = subprocess.run(cmd, stdout=logf, stderr=subprocess.STDOUT, timeout=6 * 3600)
        logf.flush()
        return proc.returncode

    # ---- vistas ------------------------------------------------------------ #
    def site_summary(self, site):
        name = site["name"]
        root = os.path.join(self.dest, name)
        last = read_json(os.path.join(root, "last_run.json")) or {}
        status = read_json(os.path.join(root, ".nbb", "status.json")) or {}
        last_restore = read_json(os.path.join(root, "last_restore.json"))
        try:
            points = self.points.list(name)
        except (subprocess.SubprocessError, OSError):
            points = []
        running = self.tasks.running_for(name)
        return {
            "name": name,
            "url": site.get("url"),
            "disabled": bool(site.get("disabled")),
            "last_run": {k: last.get(k) for k in (
                "ok", "started", "finished", "duration_s", "files_total", "bytes_total", "tables", "rows",
                "downloaded_files", "downloaded_bytes", "deleted_files", "warnings", "error", "scan_errors",
                "wp_version")} if last else None,
            "status": {k: status.get(k) for k in (
                "wp_version", "php_version", "plugin_version", "restore_allowed", "checked",
                "max_execution_time", "memory_limit", "storage_public")} if status else None,
            "last_restore": last_restore,
            "points": [{k: p[k] for k in ("id", "kind", "label", "created", "backup_finished")} for p in points],
            "running": self.tasks.public(running) if running else None,
        }

    def state(self):
        cfg, sites = self.config.load()
        return {
            "sites": [self.site_summary(s) for s in sites],
            "tasks": self.tasks.recent(),
            "zfs": bool(self.points.zfs),
            "dest": self.dest,
            "now": int(time.time()),
        }

    # ---- acciones ---------------------------------------------------------- #
    def add_site(self, body):
        block = body.get("block")
        if block:
            try:
                data = json.loads(block)
            except ValueError:
                raise ValueError("El bloque pegado no es JSON válido. Cópialo entero desde Ajustes › NAS Backup.")
            body = dict(body, **{k: data.get(k) for k in ("name", "url", "secret")})
        name = (body.get("name") or "").strip().lower()
        url = (body.get("url") or "").strip()
        secret = (body.get("secret") or "").strip()
        if not NAME_RE.match(name):
            raise ValueError("Nombre no válido: usa minúsculas, números, puntos y guiones (p. ej. ejemplo-com).")
        if not re.match(r"^https?://[^\s/]+", url):
            raise ValueError("La dirección debe empezar por https://")
        if len(secret) < 32:
            raise ValueError("La clave es demasiado corta: cópiala de Ajustes › NAS Backup en la web.")
        _, sites = self.config.load()
        if any(s["name"] == name for s in sites):
            raise ValueError("Ya existe una web con el nombre %s" % name)
        site = {"name": name, "url": url, "secret": secret}
        cfg, _ = self.config.load()
        st = nbb_pull.SiteBackup(site, cfg, cfg["dest"]).check()  # lanza error si no conecta
        self.config.save_sites(lambda lst: lst + [site])
        if body.get("first_backup"):
            self.backup(name)
        return {"name": name, "wp_version": st.get("wp_version"), "plugin_version": st.get("plugin_version")}

    def remove_site(self, name):
        if self.tasks.running_for(name):
            raise ValueError("Hay una tarea en curso para esta web")
        found = []

        def mutate(lst):
            found.extend(s for s in lst if s["name"] == name)
            return [s for s in lst if s["name"] != name]
        self.config.save_sites(mutate)
        if not found:
            raise LookupError("Web no encontrada")
        return {"removed": name, "note": "Las copias guardadas no se han borrado."}

    def set_disabled(self, name, disabled):
        def mutate(lst):
            for s in lst:
                if s["name"] == name:
                    if disabled:
                        s["disabled"] = True
                    else:
                        s.pop("disabled", None)
            return lst
        self.config.save_sites(mutate)
        return {"name": name, "disabled": disabled}

    def check(self, name):
        cfg, site = self.site_cfg(name)
        if not site:
            raise LookupError("Web no encontrada")
        st = nbb_pull.SiteBackup(site, cfg, cfg["dest"]).check()
        return {k: st.get(k) for k in ("wp_version", "php_version", "plugin_version", "restore_allowed")}

    def backup(self, name):
        _, site = self.site_cfg(name)
        if not site:
            raise LookupError("Web no encontrada")

        def job(say, logf):
            rc = self.run_cli(["--site", name], say, logf)
            with self.points.lock:
                self.points.cache.pop(name, None)
            if rc != 0:
                raise RuntimeError("La copia terminó con errores (código %s)" % rc)
            say("Copia terminada.")
        return self.tasks.public(self.tasks.start(name, "backup", "Copia de %s" % name, job))

    def restore(self, name, body):
        _, site = self.site_cfg(name)
        if not site:
            raise LookupError("Web no encontrada")
        if (body.get("confirm") or "").strip() != name:
            raise ValueError("Escribe el nombre de la web para confirmar la restauración.")
        do_db, do_files = bool(body.get("db")), bool(body.get("files"))
        if not (do_db or do_files):
            raise ValueError("Elige qué restaurar: base de datos, archivos o ambos.")
        point = self.points.resolve(name, body.get("point") or "")
        if not point:
            raise LookupError("Punto de restauración no encontrado")
        status = read_json(os.path.join(self.dest, name, ".nbb", "status.json")) or {}
        if status and not status.get("restore_allowed"):
            raise ValueError("La web no permite restauraciones. Actívalas en Ajustes › NAS Backup y pulsa «Probar conexión».")
        pre_backup = bool(body.get("pre_backup", True))
        opts = {"db": do_db, "files": do_files, "delete_extra": bool(body.get("delete_extra", True)),
                "wp_config": bool(body.get("wp_config")), "maintenance": bool(body.get("maintenance", True)),
                "pre_backup": pre_backup, "point": point["id"], "point_label": point["label"],
                "backup_finished": point["backup_finished"]}

        def job(say, logf):
            stamp = time.strftime("%Y%m%d-%H%M%S")
            source, source_snap = point["path"], None
            if point["id"] == "current":
                source_snap = "nbb-fuente-%s-%s" % (name, stamp)
                say("Congelando la copia elegida (%s)…" % source_snap)
                source = self.points.create(source_snap, name)
            try:
                if pre_backup:
                    say("Copia de seguridad del estado actual de la web antes de restaurar…")
                    rc = self.run_cli(["--site", name], say, logf)
                    if rc != 0:
                        raise RuntimeError("La copia previa falló, así que no se ha restaurado nada. "
                                           "Si la web no responde, desmarca «Hacer copia antes» y vuelve a intentarlo.")
                    undo = "nbb-antes-%s-%s" % (name, stamp)
                    self.points.create(undo, name)
                    say("Punto para deshacer creado: «Antes de restaurar» (%s)." % undo)
                args = ["restore", "--site", name, "--source", source,
                        "--label", "%s del %s" % (point["label"], point["backup_finished"])]
                if not do_db:
                    args.append("--no-db")
                if not do_files:
                    args.append("--no-files")
                if not opts["delete_extra"]:
                    args.append("--keep-extra")
                if opts["wp_config"]:
                    args.append("--wp-config")
                if not opts["maintenance"]:
                    args.append("--no-maintenance")
                rc = self.run_cli(args, say, logf)
                if rc != 0:
                    raise RuntimeError("La restauración falló (código %s). La web conserva la base de datos anterior "
                                       "si el fallo fue antes de activarla; revisa el registro." % rc)
                say("Restauración terminada.")
            finally:
                if source_snap:
                    self.points.destroy(source_snap)
                with self.points.lock:
                    self.points.cache.pop(name, None)

        title = "Restauración de %s (%s)" % (name, point["label"].lower())
        return self.tasks.public(self.tasks.start(name, "restore", title, job, opts))

    # ---- descargas --------------------------------------------------------- #
    def stream_zip(self, name, pid, part, out):
        point = self.points.resolve(name, pid)
        if not point:
            raise LookupError("Punto de restauración no encontrado")
        base = point["path"]
        with zipfile.ZipFile(out, "w", compression=zipfile.ZIP_STORED, allowZip64=True) as zf:
            zf.write(os.path.join(base, "meta.json"), "meta.json")
            if part in ("db", "all"):
                with zf.open("restore.sql", "w", force_zip64=True) as dst:
                    for fn in sorted(os.listdir(os.path.join(base, "db"))):
                        if fn.endswith(".sql.gz"):
                            with nbb_pull.gzip.open(os.path.join(base, "db", fn), "rb") as src:
                                shutil.copyfileobj(src, dst, 1 << 20)
            if part in ("files", "all"):
                if os.path.isfile(os.path.join(base, "symlinks.json")):
                    zf.write(os.path.join(base, "symlinks.json"), "symlinks.json")
                files_root = os.path.join(base, "files")
                for dirpath, dirnames, filenames in os.walk(files_root):
                    dirnames.sort()
                    rel_dir = os.path.relpath(dirpath, files_root)
                    if not filenames and not dirnames:
                        zf.writestr(zipfile.ZipInfo(os.path.join("files", rel_dir) + "/"), b"")
                    for fn in sorted(filenames):
                        full = os.path.join(dirpath, fn)
                        if os.path.islink(full):
                            continue
                        zf.write(full, os.path.join("files", rel_dir, fn) if rel_dir != "." else os.path.join("files", fn))

    # ---- autenticación ----------------------------------------------------- #
    def make_token(self):
        secret = self.config.panel().get("cookie_secret", "").encode()
        exp = str(int(time.time()) + SESSION_TTL)
        sig = hmac.new(secret, exp.encode(), hashlib.sha256).hexdigest()
        return exp + "." + sig

    def token_ok(self, token):
        secret = self.config.panel().get("cookie_secret", "").encode()
        if not secret or not token or "." not in token:
            return False
        exp, sig = token.split(".", 1)
        good = hmac.new(secret, exp.encode(), hashlib.sha256).hexdigest()
        return hmac.compare_digest(good, sig) and exp.isdigit() and int(exp) > time.time()

    def login_allowed(self):
        with self.fail_lock:
            self.fail_times = [t for t in self.fail_times if t > time.time() - 600]
            return len(self.fail_times) < 10

    def login_failed(self):
        with self.fail_lock:
            self.fail_times.append(time.time())


# --------------------------------------------------------------------------- #
# HTTP
# --------------------------------------------------------------------------- #

class ChunkedWriter:
    """Escritor para respuestas con Transfer-Encoding: chunked (zip en streaming)."""

    def __init__(self, wfile):
        self.wfile = wfile
        self.buf = bytearray()

    def write(self, data):
        self.buf += data
        if len(self.buf) >= 1 << 20:
            self.flush()
        return len(data)

    def flush(self):
        if self.buf:
            self.wfile.write(b"%x\r\n" % len(self.buf) + bytes(self.buf) + b"\r\n")
            self.buf = bytearray()

    def close(self):
        self.flush()
        self.wfile.write(b"0\r\n\r\n")


def make_handler(panel):
    html_path = os.path.join(HERE, "panel.html")

    class Handler(http.server.BaseHTTPRequestHandler):
        server_version = "nbb-panel"
        protocol_version = "HTTP/1.1"

        def log_message(self, fmt, *args):
            pass

        # -- respuestas --
        def send_json(self, code, data, headers=None):
            body = json.dumps(data, ensure_ascii=False).encode()
            self.send_response(code)
            self.send_header("Content-Type", "application/json; charset=utf-8")
            self.send_header("Content-Length", str(len(body)))
            self.send_header("Cache-Control", "no-store")
            for k, v in (headers or {}).items():
                self.send_header(k, v)
            self.end_headers()
            self.wfile.write(body)

        def send_error_json(self, code, message):
            self.send_json(code, {"error": message})

        def security_headers(self):
            self.send_header("X-Frame-Options", "DENY")
            self.send_header("X-Content-Type-Options", "nosniff")
            self.send_header("Referrer-Policy", "no-referrer")

        def cookie(self):
            raw = self.headers.get("Cookie", "")
            for part in raw.split(";"):
                k, _, v = part.strip().partition("=")
                if k == COOKIE:
                    return v
            return None

        def authed(self):
            return panel.token_ok(self.cookie())

        def body_json(self):
            n = int(self.headers.get("Content-Length") or 0)
            if n > 1_000_000:
                raise ValueError("Petición demasiado grande")
            raw = self.rfile.read(n) if n else b"{}"
            try:
                data = json.loads(raw.decode("utf-8") or "{}")
            except ValueError:
                raise ValueError("JSON no válido")
            if not isinstance(data, dict):
                raise ValueError("JSON no válido")
            return data

        # -- rutas --
        def do_GET(self):
            url = urllib.parse.urlsplit(self.path)
            path = url.path
            try:
                if path in ("/", "/index.html"):
                    with open(html_path, "rb") as f:
                        body = f.read()
                    self.send_response(200)
                    self.send_header("Content-Type", "text/html; charset=utf-8")
                    self.send_header("Content-Length", str(len(body)))
                    self.send_header("Cache-Control", "no-store")
                    self.send_header("Content-Security-Policy",
                                     "default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
                                     "font-src https://fonts.gstatic.com; script-src 'self' 'unsafe-inline'; "
                                     "connect-src 'self'; img-src 'self' data:; frame-ancestors 'none'")
                    self.security_headers()
                    self.end_headers()
                    self.wfile.write(body)
                    return
                if path == "/api/session":
                    has_pw = bool(panel.config.panel().get("password"))
                    return self.send_json(200, {"authenticated": self.authed(), "configured": has_pw})
                if not self.authed():
                    return self.send_error_json(401, "Inicia sesión")
                if path == "/api/state":
                    return self.send_json(200, panel.state())
                m = re.match(r"^/api/tasks/([0-9a-f-]+)$", path)
                if m:
                    t = panel.tasks.get(m.group(1))
                    return self.send_json(200, t) if t else self.send_error_json(404, "Tarea no encontrada")
                m = re.match(r"^/api/sites/([a-z0-9._-]+)/points$", path)
                if m:
                    pts = panel.points.list(m.group(1), refresh=True)
                    return self.send_json(200, [{k: v for k, v in p.items() if k != "path"} for p in pts])
                m = re.match(r"^/api/sites/([a-z0-9._-]+)/download$", path)
                if m:
                    q = urllib.parse.parse_qs(url.query)
                    pid = (q.get("point") or [""])[0]
                    part = (q.get("part") or ["all"])[0]
                    if part not in ("db", "files", "all"):
                        return self.send_error_json(400, "Parte no válida")
                    point = panel.points.resolve(m.group(1), pid)
                    if not point:
                        return self.send_error_json(404, "Punto de restauración no encontrado")
                    date = (point.get("backup_finished") or "")[:10] or "copia"
                    fname = "%s-%s-%s.zip" % (m.group(1), date, {"db": "base-de-datos", "files": "archivos", "all": "completa"}[part])
                    self.send_response(200)
                    self.send_header("Content-Type", "application/zip")
                    self.send_header("Content-Disposition", 'attachment; filename="%s"' % fname)
                    self.send_header("Transfer-Encoding", "chunked")
                    self.send_header("Cache-Control", "no-store")
                    self.end_headers()
                    w = ChunkedWriter(self.wfile)
                    try:
                        panel.stream_zip(m.group(1), pid, part, w)
                        w.close()
                    except Exception:  # noqa: BLE001 — la cabecera ya salió; cortar la conexión
                        traceback.print_exc()
                        self.close_connection = True
                    return
                return self.send_error_json(404, "No encontrado")
            except (BrokenPipeError, ConnectionResetError):
                return
            except LookupError as e:
                return self.send_error_json(404, str(e))
            except Exception as e:  # noqa: BLE001
                traceback.print_exc()
                return self.send_error_json(500, str(e))

        def do_POST(self):
            self.handle_write("POST")

        def do_DELETE(self):
            self.handle_write("DELETE")

        def handle_write(self, method):
            path = urllib.parse.urlsplit(self.path).path
            # Protección CSRF: el navegador no puede añadir esta cabecera desde otra web sin CORS.
            if self.headers.get("X-NBB-Panel") != "1":
                return self.send_error_json(403, "Petición no permitida")
            try:
                body = self.body_json()
                if path == "/api/login" and method == "POST":
                    if not panel.config.panel().get("password"):
                        return self.send_error_json(409, "Falta fijar la contraseña: ejecuta nbb_panel.py set-password en el NAS.")
                    if not panel.login_allowed():
                        return self.send_error_json(429, "Demasiados intentos. Espera unos minutos.")
                    if not panel.config.check_password(str(body.get("password", ""))):
                        panel.login_failed()
                        time.sleep(1)
                        return self.send_error_json(401, "Contraseña incorrecta")
                    secure = "; Secure" if panel.config.panel().get("cookie_secure", True) else ""
                    cookie = "%s=%s; Path=/; HttpOnly; SameSite=Strict; Max-Age=%d%s" % (
                        COOKIE, panel.make_token(), SESSION_TTL, secure)
                    return self.send_json(200, {"ok": True}, {"Set-Cookie": cookie})
                if path == "/api/logout" and method == "POST":
                    return self.send_json(200, {"ok": True}, {"Set-Cookie": "%s=; Path=/; Max-Age=0" % COOKIE})
                if not self.authed():
                    return self.send_error_json(401, "Inicia sesión")
                if path == "/api/sites" and method == "POST":
                    return self.send_json(200, panel.add_site(body))
                m = re.match(r"^/api/sites/([a-z0-9._-]+)(?:/(backup|check|restore|disable|enable))?$", path)
                if m:
                    name, action = m.group(1), m.group(2)
                    if method == "DELETE" and not action:
                        return self.send_json(200, panel.remove_site(name))
                    if method == "POST" and action == "backup":
                        return self.send_json(200, panel.backup(name))
                    if method == "POST" and action == "check":
                        return self.send_json(200, panel.check(name))
                    if method == "POST" and action == "restore":
                        return self.send_json(200, panel.restore(name, body))
                    if method == "POST" and action in ("disable", "enable"):
                        return self.send_json(200, panel.set_disabled(name, action == "disable"))
                return self.send_error_json(404, "No encontrado")
            except (BrokenPipeError, ConnectionResetError):
                return
            except LookupError as e:
                return self.send_error_json(404, str(e))
            except (ValueError, nbb_pull.NbbError) as e:
                return self.send_error_json(400, str(e))
            except Exception as e:  # noqa: BLE001
                traceback.print_exc()
                return self.send_error_json(500, str(e))

    return Handler


class Server(socketserver.ThreadingMixIn, http.server.HTTPServer):
    daemon_threads = True
    allow_reuse_address = True


def main():
    ap = argparse.ArgumentParser(description="Panel web de NAS Backup Bridge")
    ap.add_argument("command", nargs="?", default="serve", choices=["serve", "set-password"])
    ap.add_argument("--config", required=True)
    ap.add_argument("--bind", default="127.0.0.1")
    ap.add_argument("--port", type=int, default=8765)
    args = ap.parse_args()

    if args.command == "set-password":
        cfgobj = Config(args.config)
        pw = os.environ.get("NBB_PANEL_PASSWORD") or getpass.getpass("Nueva contraseña del panel: ")
        if not os.environ.get("NBB_PANEL_PASSWORD") and pw != getpass.getpass("Repítela: "):
            print("No coinciden.", file=sys.stderr)
            return 1
        if len(pw) < 10:
            print("Usa al menos 10 caracteres.", file=sys.stderr)
            return 1
        cfgobj.set_password(pw)
        print("Contraseña guardada en", cfgobj.panel_path)
        return 0

    panel = Panel(args.config)
    try:
        panel.points.prune()
    except Exception as err:  # noqa: BLE001
        print("Aviso al limpiar puntos antiguos:", err, file=sys.stderr)

    def pruner():
        while True:
            time.sleep(6 * 3600)
            try:
                panel.points.prune()
            except Exception:  # noqa: BLE001
                pass
    threading.Thread(target=pruner, daemon=True).start()

    srv = Server((args.bind, args.port), make_handler(panel))
    print("Panel en http://%s:%d (ZFS: %s)" % (args.bind, args.port,
                                               panel.points.zfs["dataset"] if panel.points.zfs else "no"), flush=True)
    srv.serve_forever()


if __name__ == "__main__":
    sys.exit(main())
