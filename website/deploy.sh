#!/bin/sh
# Mise en ligne de rengaine.org : construit le site, vérifie qu'il est complet, puis le copie dans le
# conteneur du site (accès SSH « rengaine-org », dossier /var/www/rengaine). Tout ce qui est copié est public.
#
#   sh website/deploy.sh [--essai]      --essai : construit et vérifie seulement, sans rien copier
set -eu
cd "$(dirname "$0")"
DEST=${RENGAINE_DEST:-rengaine-org:/var/www/rengaine/}

[ -s ../api/site/index.html ] || sh ../api/doc.sh
REPO_URL=${REPO_URL:-https://github.com/wootou/rengaine} node build.mjs

# Rien d'inachevé ni de manquant
if grep -rl -e '\[À COMPLÉTER\]' -e '\[TO BE COMPLETED\]' dist >/dev/null; then
    echo "Mentions légales incomplètes (website/legal.json) : pas de mise en ligne." >&2
    exit 1
fi
missing=$(grep -rhoE 'images/[a-z]+/[a-z-]+-(dark|light)\.webp' dist | sort -u | while read -r f; do [ -f "$f" ] || [ -f "dist/$f" ] || echo "$f"; done)
if [ -n "$missing" ]; then
    echo "Captures manquantes (node website/captures.mjs) :" >&2
    echo "$missing" >&2
    exit 1
fi
if find dist -name '*.php' -o -name '.git' -o -name '*.md' -o -name '*.map' | grep -q .; then
    echo "Fichier qui n'a rien à faire en ligne dans dist/." >&2
    exit 1
fi

if [ "${1:-}" = "--essai" ]; then
    echo "Essai : site prêt dans website/dist ($(find dist -type f | wc -l) fichiers), rien copié."
    exit 0
fi
rsync -rlt --delete --chmod=D755,F644 dist/ "$DEST"
echo "En ligne : https://rengaine.org"
