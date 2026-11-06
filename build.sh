#!/bin/bash
# Baut senec2lox-<version>.zip aus senec2lox/ (ohne tests/, .git, docs/).
cd "$(dirname "$0")" || exit 1
VER="$(grep '^VERSION=' senec2lox/plugin.cfg | cut -d= -f2 | tr -d '\r')"
CODEVER="$(grep -o "CURRENT = '[^']*'" senec2lox/bin/lib/AppVersion.php | cut -d"'" -f2)"
if [ "$VER" != "$CODEVER" ]; then
    echo "Versionen passen nicht: plugin.cfg=$VER, AppVersion.php=$CODEVER" >&2
    exit 1
fi
if ! grep -q "^## \[$VER\]" CHANGELOG.md; then
    echo "CHANGELOG.md hat keinen Eintrag fuer Version $VER" >&2
    exit 1
fi
OUT="$PWD/senec2lox-$VER.zip"
rm -f "$OUT"
if command -v zip >/dev/null 2>&1; then
    (cd senec2lox && zip -r -q "$OUT" . -x '*.git*')
else
    PY="$(command -v python3 || command -v python)" || { echo "weder zip noch python gefunden"; exit 1; }
    "$PY" - "$OUT" <<'PYEOF'
import os, sys, zipfile
out = sys.argv[1]
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk('senec2lox'):
        dirs[:] = [d for d in dirs if d != '.git']
        for f in files:
            p = os.path.join(root, f)
            info = zipfile.ZipInfo.from_file(p, os.path.relpath(p, 'senec2lox').replace(os.sep, '/'))
            info.external_attr = (0o755 if f.endswith(('.sh', '.php')) or f in ('daemon', 'uninstall') or f.startswith('cron.') else 0o644) << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            with open(p, 'rb') as fh:
                z.writestr(info, fh.read())
PYEOF
fi
echo "$OUT"
