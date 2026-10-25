#!/bin/sh
# Archive de version de Rengaine (PUB-08) : l'export public (tools/export-public.sh, avec ses contrôles)
# et le lecteur web déjà construit (web/dist), pour installer sans Node.js.
#
#   sh tools/release.sh <version> [dossier de sortie]      → rengaine-<version>.tar.gz et sa somme SHA-256
set -eu

VERSION=${1:?Usage : sh tools/release.sh <version> [dossier]}
OUT=${2:-$(pwd)}
ROOT=$(cd "$(dirname "$0")/.." && pwd)
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

NAME="rengaine-$VERSION"
sh "$ROOT/tools/export-public.sh" "$WORK/$NAME"
(cd "$WORK/$NAME/web" && npm ci --silent && npm run build --silent >/dev/null && rm -rf node_modules)
test -s "$WORK/$NAME/web/dist/index.html" || { echo "Lecteur web non construit" >&2; exit 1; }
echo "$VERSION" > "$WORK/$NAME/VERSION"
mkdir -p "$OUT"
tar -C "$WORK" --owner=0 --group=0 --numeric-owner -czf "$OUT/$NAME.tar.gz" "$NAME"
(cd "$OUT" && sha256sum "$NAME.tar.gz" > "$NAME.tar.gz.sha256")
echo "Version prête : $OUT/$NAME.tar.gz"
