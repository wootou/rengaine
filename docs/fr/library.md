# Votre bibliothèque

## Où vont les fichiers

Rengaine lit la musique dans un dossier ou un bucket S3 (`[stockage]`). Il ne modifie jamais un fichier
de musique. Il reconnaît MP3, FLAC, M4A/AAC, Ogg/Opus, WAV, AIFF, WMA, APE et WavPack.

**Un dossier, un album.** Les sous-dossiers de disque (`CD1/`, `Disc 2/`) et de format (`flac/`, `mp3/`)
appartiennent à leur parent. Titre, artiste et année viennent d'un vote majoritaire sur les tags des
pistes de l'album, et du nom du dossier (`Artiste - Album`) quand les tags manquent. Un dossier dont les
pistes n'ont pas d'album commun devient un dossier « en vrac » : ses pistes restent trouvables et jouables.

Quand une même piste existe en MP3 et en FLAC dans un album, c'est une seule piste avec deux fichiers : la
version sans perte est jouée, et **Admin > Doublons** peut déplacer les MP3 en trop dans une corbeille.

Pochettes : d'abord les images `cover`, `folder` ou `front`, puis l'image unique du dossier, puis l'image
intégrée aux fichiers.

## Le dépôt

Déposez un nouvel album dans `inbox/`, à la racine du stockage (copie, rsync, rclone, dossier
synchronisé…). Quand plus rien n'a bougé dans ce dossier depuis 10 minutes, le scan suivant le range sous
un nom propre tiré de ses tags :

```
Artiste/Album (année)/01 - Titre.flac
Artiste/Album (année)/CD2/01 - Titre.flac
Compilations/Album (année)/01 - Artiste - Titre.mp3
```

Rien n'est jamais écrasé. Les cas douteux (plusieurs albums mélangés, artiste introuvable, fichier déjà
présent) restent dans `inbox/` avec leur raison, visible dans **Admin > Tâches**. Aperçu sans rien
déplacer : `rengaine inbox --essai`.

## Les scans

`rengaine-index.timer` passe toutes les 15 minutes et ne relit que ce qui a changé (taille, date, ETag). Un
fichier renommé ou déplacé garde sa piste, ses favoris et son historique (reconnu par sa taille et son
SHA-256). Un fichier disparu est marqué, pas supprimé. Lancez un scan depuis **Admin > Tâches** ou avec
`rengaine index` (`--force` relit tout).

## L'analyse

Chaque nouvelle piste est analysée en arrière-plan : volume (EBU R128, pour égaliser le niveau), silences
de début et de fin, forme d'onde, et avec les outils facultatifs, tempo, tonalité, grilles des temps,
énergie (Essentia) et empreinte acoustique (Chromaprint). Le lecteur s'en sert pour égaliser le volume,
sauter les silences, faire des fondus calés sur le tempo, et proposer les filtres « DJ », les ambiances et
la lecture continue.

Les fichiers qui ont des trames illisibles sont listés dans **Admin > Doublons > Fichiers abîmés**, avec
l'endroit des accrocs.

## Les genres

Un tag ne crée jamais un genre à lui seul. Chaque valeur de genre lue dans les tags est notée ; si elle
correspond à un genre existant (sans tenir compte de la casse ni des accents), elle y est rattachée
d'office, sinon elle attend dans **Admin > Tags** que vous la rattachiez (« Hip-Hop/Rap » → « Rap »). Les
genres peuvent avoir des sous-genres. Un album prend les genres choisis pour lui, sinon ceux de la majorité
des tags de ses pistes ; une piste hérite des genres de son album, sauf si elle a les siens (compilations).

## Les corrections

Corrigez un titre, une année, un artiste d'album, des genres, une pochette, séparez ou fusionnez des
albums, renommez ou fusionnez des artistes, depuis la page de l'album ou de l'artiste (**Corriger**) ou
depuis l'admin. Chaque changement est une correction : gardée avec son auteur et l'ancienne valeur,
réappliquée après chaque scan, et annulable depuis **Admin > Corrections**. Une IA peut proposer des lots
de corrections que vous appliquez ou refusez (voir [IA](ai.md)).
