#!/bin/sh
# Prépare le dépôt public de Rengaine dans un dossier (PUB-02) : copie du commit courant, sans la doc
# privée, puis contrôles. S'arrête au premier problème ; n'envoie rien nulle part.
#
#   sh tools/export-public.sh <dossier> [--git]
#
#   --git      crée en plus un dépôt git neuf avec un seul commit (l'historique privé n'est pas repris)
#
# Contrôles :
#   1. secrets : clés privées, jetons d'API connus, mots de passe dans des fichiers de config ;
#   2. mots interdits : un motif par ligne dans tools/export-forbidden.local (non suivi par git, car
#      la liste elle-même décrirait l'infra privée), ou dans le fichier désigné par EXPORT_FORBIDDEN.
set -eu

DEST=${1:?Usage : sh tools/export-public.sh <dossier> [--git]}
GIT=${2:-}
ROOT=$(cd "$(dirname "$0")/.." && pwd)
FORBIDDEN=${EXPORT_FORBIDDEN:-$ROOT/tools/export-forbidden.local}

# Fichiers qui restent privés (suivi du projet, détails d'infrastructure)
PRIVATE="docs/TACHES.md docs/CONCEPTION.md docs/INSTALL.md docs/NOTES-DEV.md"

if [ -e "$DEST" ] && [ -n "$(ls -A "$DEST" 2>/dev/null)" ]; then
    echo "Le dossier $DEST n'est pas vide." >&2
    exit 1
fi
mkdir -p "$DEST"
git -C "$ROOT" diff --quiet HEAD || echo "Attention : des changements ne sont pas commités, ils ne seront pas exportés." >&2
git -C "$ROOT" archive --format=tar HEAD | tar -x -C "$DEST"
for f in $PRIVATE; do rm -f "$DEST/$f"; done
rm -f "$DEST/tools/export-forbidden.local"

status=0
echo "Recherche de secrets…"
# Clés d'exemple publiques de la doc AWS (tests de la signature S3) : seules permises
if grep -rnIE -e '-----BEGIN [A-Z ]*PRIVATE KEY-----' -e 'sk-ant-[A-Za-z0-9_-]{20,}' -e 'gh[pousr]_[A-Za-z0-9]{30,}' \
        -e 'AKIA[0-9A-Z]{16}' -e 'xox[abp]-[A-Za-z0-9-]{10,}' "$DEST" | grep -v 'AKIAIOSFODNN7EXAMPLE'; then
    echo "→ secret possible ci-dessus" >&2
    status=1
fi
if grep -rnIE '^\s*(mot_de_passe|cle|s3_secret|s3_cle|cle_publication|cle_abonnement)\s*=\s*"[^".…<]{8,}"' "$DEST"; then
    echo "→ valeur de configuration secrète ci-dessus" >&2
    status=1
fi

echo "Recherche des mots interdits…"
if [ -r "$FORBIDDEN" ]; then
    # LICENSE porte le nom du titulaire des droits (choix de Rémy) : seul fichier exempté
    if grep -rnIiE --exclude=LICENSE -f "$FORBIDDEN" "$DEST"; then
        echo "→ détail d'infrastructure ci-dessus (motifs de $FORBIDDEN)" >&2
        status=1
    fi
else
    echo "Pas de liste de mots interdits ($FORBIDDEN) : contrôle impossible." >&2
    status=1
fi

if [ "$status" -ne 0 ]; then
    echo "Export refusé : corriger, commiter, recommencer dans un dossier vide." >&2
    exit 1
fi

if [ "$GIT" = "--git" ]; then
    git -C "$DEST" init -q -b main
    git -C "$DEST" add -A
    git -C "$DEST" -c user.name="${EXPORT_AUTHOR_NAME:-Rengaine}" -c user.email="${EXPORT_AUTHOR_EMAIL:-rengaine@users.noreply.github.com}" \
        commit -q -m "Rengaine : première version publique"
fi
echo "Export prêt dans $DEST ($(find "$DEST" -type f -not -path '*/.git/*' | wc -l) fichiers)."
