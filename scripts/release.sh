#!/usr/bin/env bash
# Publica una versión del plugin: genera el zip y releases/latest.json (lo que leen las webs para actualizarse).
# Uso: scripts/release.sh "Texto del changelog"
set -euo pipefail
cd "$(dirname "$0")/.."
main=plugin/nas-backup-bridge/nas-backup-bridge.php
ver=$(sed -n "s/^ \* Version: *\([0-9.]*\).*/\1/p" "$main")
const=$(sed -n "s/^define( 'NBB_VERSION', '\([0-9.]*\)' );/\1/p" "$main")
tag=$(sed -n "s/^Stable tag: *\([0-9.]*\)/\1/p" plugin/nas-backup-bridge/readme.txt)
if [ "$ver" != "$const" ] || [ "$ver" != "$tag" ]; then
  echo "Versiones no coinciden: cabecera $ver, NBB_VERSION $const, Stable tag $tag" >&2; exit 1
fi
for f in plugin/nas-backup-bridge/*.php plugin/nas-backup-bridge/includes/*.php; do php -l "$f" >/dev/null; done
zip_name="nas-backup-bridge-$ver.zip"
mkdir -p releases
rm -f "releases/$zip_name"
(cd plugin && zip -qrX "../releases/$zip_name" nas-backup-bridge -x '*.DS_Store')
sha=$(sha256sum "releases/$zip_name" | cut -d' ' -f1)
python3 - "$ver" "$zip_name" "$sha" "${1:-}" > releases/latest.json <<'PY'
import json, sys
ver, name, sha, log = sys.argv[1:5]
print(json.dumps({
    "version": ver,
    "package": "https://raw.githubusercontent.com/Chrisimir/nas-website-backup-bridge/main/releases/" + name,
    "sha256": sha,
    "requires": "5.6",
    "requires_php": "7.4",
    "tested": "7.1",
    "changelog": log,
}, indent=2, ensure_ascii=False))
PY
echo "Publicada $ver -> releases/$zip_name ($sha). Haz commit y push de releases/."
