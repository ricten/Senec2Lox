#!/bin/bash
# Baut senec2lox-<version>.zip aus senec2lox/ (ohne tests/, .git, docs/).
#   bash build.sh              baut das ZIP
#   bash build.sh release-cfg  schreibt release.cfg (Repo-Wurzel, nicht im ZIP) fuer die Version aus plugin.cfg; erst NACH dem GitHub-Release
#                              ausfuehren und committen: die Datei darf nie auf ein noch nicht vorhandenes ZIP zeigen
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
if [ "${1:-}" = "release-cfg" ]; then
    printf '%s
'         '[AUTOUPDATE]'         '# release.cfg - wird von der LoxBerry-Plugin-Verwaltung fuer automatische Updates gelesen (RELEASECFG in plugin.cfg).'         '# Erzeugt mit "bash build.sh release-cfg" NACH dem GitHub-Release; nie auf eine Version zeigen lassen, deren ZIP noch fehlt.'         ''         '# Version des aktuellen Releases (muss neuer sein als die installierte Version)'         "VERSION=$VER"         ''         '# Direkter Download des Plugin-ZIP (Release-Anhang)'         "ARCHIVEURL=https://github.com/ricten/Senec2Lox/releases/download/v$VER/senec2lox-$VER.zip"         ''         '# Seite mit Hinweisen zu diesem Release'         "INFOURL=https://github.com/ricten/Senec2Lox/releases/tag/v$VER" > release.cfg
    echo "release.cfg fuer Version $VER geschrieben"
    exit 0
fi
if [ -f release.cfg ]; then
    RCVER="$(grep '^VERSION=' release.cfg | cut -d= -f2 | tr -d '')"
    [ "$RCVER" != "$VER" ] && echo "Hinweis: release.cfg steht auf $RCVER, plugin.cfg auf $VER (release.cfg erst nach dem GitHub-Release mit 'bash build.sh release-cfg' aktualisieren)" >&2
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
            info.external_attr = (0o755 if f.endswith(('.sh', '.php')) or f in ('daemon', 'uninstall', 'healthcheck') or f.startswith('cron.') else 0o644) << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            with open(p, 'rb') as fh:
                z.writestr(info, fh.read())
PYEOF
fi
# Zusaetzlich ein ZIP ohne Version im Namen: https://github.com/ricten/<Repo>/releases/latest/download/senec2lox.zip zeigt dann immer auf das neueste Release
cp -f "$OUT" "$PWD/senec2lox.zip"
echo "$OUT"
echo "$PWD/senec2lox.zip"
