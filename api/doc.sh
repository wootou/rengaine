#!/bin/sh
# Doc lisible du contrat (page HTML autonome, dans api/site/).
# À relancer après chaque modification de api/openapi.yaml. Rien n'est chargé hors du site :
# Redoc est servi d'ici (paquet npm redoc, même version que la page générée), pas de police externe.
set -eu
cd "$(dirname "$0")"
REDOC=2.5.4
npx --yes @redocly/cli@2.64.1 build-docs openapi.yaml -o site/index.html
if [ ! -s site/redoc.standalone.js ] || ! grep -q "$REDOC" site/.redoc-version 2>/dev/null; then
  tmp=$(mktemp -d)
  (cd "$tmp" && npm pack "redoc@$REDOC" --silent >/dev/null && tar xzf "redoc-$REDOC.tgz")
  cp "$tmp/package/bundles/redoc.standalone.js" site/redoc.standalone.js
  echo "$REDOC" > site/.redoc-version
  rm -rf "$tmp"
fi
sed -i -e "s#https://cdn.redocly.com/redoc/v$REDOC/bundles/redoc.standalone.js#redoc.standalone.js#" \
       -e 's#<link href="https://fonts.googleapis.com/[^>]*>##' site/index.html
if grep -q 'cdn.redocly\|fonts.googleapis' site/index.html; then
  echo "doc.sh : lien externe restant dans site/index.html" >&2; exit 1
fi
cp openapi.yaml site/openapi.yaml
echo "Doc à jour : site/index.html"
