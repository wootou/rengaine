#!/bin/sh
# Recopie la liste des licences de l'appli Android (aboutlibraries.json, généré par AboutLibraries dans le
# dépôt rengaine-android) pour la page « Licences open source » du site. À lancer à chaque version de l'appli.
#   sh website/licenses/update-android.sh v1.0.0      (étiquette, branche ou commit du dépôt de l'appli)
set -eu
ref=${1:?usage : update-android.sh <étiquette, branche ou commit>}
dir=$(cd "$(dirname "$0")" && pwd)
url="https://raw.githubusercontent.com/wootou/rengaine-android/$ref/app/src/main/res/raw/aboutlibraries.json"
tmp=$(mktemp)
trap 'rm -f "$tmp"' EXIT
curl -sSf -o "$tmp" "$url"
node -e '
const d = JSON.parse(require("fs").readFileSync(process.argv[1], "utf8"));
if (!Array.isArray(d.libraries) || !d.libraries.length || typeof d.licenses !== "object") throw new Error("format inattendu");
console.log(`${d.libraries.length} bibliothèques, ${Object.keys(d.licenses).length} licences`);
' "$tmp"
cp "$tmp" "$dir/android.json"
chmod 644 "$dir/android.json"
printf '%s\n' "${ref#v}" > "$dir/android-version.txt"
echo "SHA-256 : $(sha256sum "$dir/android.json" | cut -d' ' -f1)"
