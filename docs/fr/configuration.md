# Configuration

Rengaine lit un seul fichier INI, `/etc/rengaine/rengaine.ini` (ou le chemin donné par la variable
d'environnement `RENGAINE_CONFIG`). Partez de [`config/rengaine.ini.example`](../../config/rengaine.ini.example) :
chaque clé y figure avec sa valeur par défaut et une courte explication (en anglais).

Gardez le fichier hors de la racine web, à `root:www-data`, en mode `640` : il contient le mot de passe de
la base, les clés du stockage, les identifiants SMTP et, le cas échéant, une clé d'API d'IA.
`rengaine config:check` affiche la configuration avec chaque secret masqué, puis teste la base, le stockage
et l'adresse du site.

## Sections obligatoires

| Section | Rôle |
|---|---|
| `[base]` | Connexion à MariaDB |
| `[stockage]` | Où est la musique : un dossier local (`mode = "disque"`, `chemin`) ou un stockage compatible S3 (`mode = "s3"`, adresse, bucket, clés). Les clés des fichiers sont des chemins relatifs à la racine : changer de mode ne change rien dans la base. |
| `[site] url` | Adresse publique du site. Obligatoire : elle va dans les mails, les QR codes d'appairage et les liens de télécommande, et n'est jamais déduite de la requête (un client pourrait la falsifier). |

## Stockage S3

Le bucket doit exister avant : Rengaine ne le crée jamais. Il n'y a pas de dossier à créer : `inbox/`
(albums à ranger) et `corbeille/` (fichiers mis de côté, par exemple les doublons) ne sont que des
préfixes de clés, créés par le premier objet déposé. Les requêtes utilisent l'adressage par chemin
(`s3_url/bucket/clé`) et la signature AWS V4.

Droits nécessaires à la clé d'accès sur ce bucket :

| Opération | Sert à |
|---|---|
| `s3:ListBucket` (ListObjectsV2) | parcourir la bibliothèque et `inbox/` |
| `s3:GetObject` (GET, GET partiel, HEAD, GET pré-signé) | écoute, analyse (FFmpeg lit une adresse pré-signée), métadonnées |
| `s3:PutObject` | ranger les albums d'`inbox/`, mettre les doublons de côté ; aussi la cible d'une copie |
| `s3:DeleteObject` | retirer la source après un déplacement (copie puis suppression) |

Un déplacement est une copie côté serveur (`x-amz-copy-source`) suivie d'une suppression. Une clé en
lecture seule (liste et lecture) suffit si vous n'utilisez ni `inbox/` ni la mise de côté depuis l'admin.

## Analyse audio

`[analyse] processus` est le nombre de pistes analysées en même temps (par défaut : le nombre de cœurs).
Commencez à 2 ou 3, puis montez en surveillant la mémoire et le disque :

- **Mémoire** : comptez environ 1 Go par processus. Essentia charge tout le morceau, on ne lui en donne
  donc jamais plus de 20 minutes : les pistes plus longues (mixes, sets) sont analysées sur des extraits
  (10 minutes au milieu, la première et la dernière minute). La durée annoncée par le fichier n'est pas
  prise pour argent comptant (un MP3 à débit variable sans en-tête Xing peut annoncer 12 minutes pour
  75). Une analyse reste sous 1 Go, quelle que soit la durée de la piste. Tant qu'il reste moins de
  1,2 Go, aucune analyse de plus ne démarre en parallèle (une seule passe toujours).
- **Plafond de mémoire** : pour protéger le site, plafonnez les analyses avec `MemoryMax` dans
  `rengaine-analyze.service` (`systemctl edit rengaine-analyze.service`, puis par exemple `MemoryMax=4G`
  et `MemorySwapMax=0` sous `[Service]`). Le contrôle ci-dessus tient compte de cette limite.
- **Disque local, avec un stockage S3** : chaque piste en cours d'analyse est d'abord téléchargée en
  entier dans `[cache] dossier` (sous-dossier `tmp/`), puis effacée. Gardez libre au moins le nombre de
  processus multiplié par votre plus gros fichier (un FLAC haute résolution d'une heure dépasse 1 Go).
  Un disque plein bloque aussi PHP-FPM, et le site ne répond plus.

Si la mémoire manque quand même, le noyau tue le plus gros processus, une analyse : avec
`OOMPolicy=continue` (dans le `rengaine-analyze.service` fourni), seule cette piste est notée en erreur.
Une piste est abandonnée après 3 échecs ; `rengaine analyze --retry` remet les pistes abandonnées en
file. Les erreurs de lecture se ferment d'elles-mêmes quand le fichier est relu sans erreur, ou
supprimé ; les autres se ferment depuis **Admin > Tâches**.

## Mails

Sans `[smtp] hote` ni `expediteur`, Rengaine n'envoie aucun mail ; tout le reste fonctionne. Avec les mails :
les demandes de compte préviennent les admins, le « mot de passe oublié » fonctionne, chacun reçoit un mail
quand son compte est approuvé, et les admins reçoivent un résumé des erreurs des tâches de fond. Essai :
`rengaine mail:test vous@example.org` ; file d'envoi : `rengaine mail:status`.

## Jaquettes trouvées en ligne

Désactivé par défaut. Avec `[pochettes] recherche_en_ligne = 1`, les admins et les agents peuvent chercher la
jaquette d'un album sur iTunes, Deezer et MusicBrainz / Cover Art Archive (`GET /admin/albums/{id}/cover-candidates`,
outil MCP `find_cover`) et en proposer une par son lien dans un lot (`coverUrl`). Le serveur contacte alors ces
sites, et seulement eux : https, liste fermée de sites revérifiée à chaque redirection, adresses publiques
seulement, images JPEG / PNG / WebP de 200 à 6000 pixels, 5 Mo au plus ; une proposition refusée ne laisse aucune image. L'image est téléchargée au moment de la
proposition et montrée dans **Admin > Propositions** ; rien ne change avant que vous appliquiez le lot. Un jeton
d'agent dont l'interrupteur « seulement les sites connus » est décoché (voir [IA](ai.md)) peut proposer une
jaquette de n'importe quel site https : seule la liste des sites saute, tous les autres contrôles restent. Dans ce
mode, `[pochettes] hotes_interdits` (noms, avec leurs sous-domaines) et `ip_interdites` (adresses publiques ou
plages IPv4) ne sont jamais contactés : ajoutez-y vos propres services publics, en particulier ceux protégés par
une liste d'IP, que le serveur pourrait sinon joindre depuis son adresse publique. Ce site ([site] url) et ses
adresses sont toujours bloqués. Quel que soit l'échec (site injoignable, page d'erreur, pas une image), l'IA ne
reçoit que « image introuvable à cette adresse » ; le détail est dans le journal technique. Les
recherches sont plafonnées par compte (`[limites] jaquettes_par_10min`, 60). Limites : le serveur doit joindre ces
sites directement en IPv4 (aucun proxy sortant n'est utilisé, une machine seulement en IPv6 n'est pas prise en
charge), et un téléchargement abandonne au bout de 20 s.

## Conservation et vie privée

`[journaux]` fixe le dossier et le niveau des journaux, et la durée de conservation : entrées d'audit, tâches
terminées et mails envoyés (`conservation_jours`), adresses IP (`conservation_ip_jours`), historique d'écoute
(`historique_ecoute_jours`, 0 = sans limite). Le nettoyage passe une fois par jour avec `rengaine-jobs` ;
aperçu avec `rengaine journaux:purger --simuler`.

Chacun peut télécharger tout ce que le serveur garde sur lui (Réglages > Compte), effacer son historique
d'écoute, et supprimer son compte.

## Réglages du serveur dans l'admin

Certains réglages sont en base et se changent dans **Admin > Serveur** : nom du serveur, langue du site
(français ou anglais, une langue pour tous les comptes), demandes de compte ouvertes ou non et combien
peuvent attendre par réseau, télécommande partagée activée ou non.

## Limitation du débit

`[limites]` contient des compteurs par réseau (une adresse IPv4, ou un /64 en IPv6). Ils ont besoin d'APCu
(`php-apcu`) ; sans lui, le serveur le signale dans le journal et ne limite rien. Après 5 échecs de
connexion depuis un même réseau, le compte y est refusé 15 minutes, avec la même réponse qu'un mauvais mot
de passe ; son titulaire peut toujours se connecter d'ailleurs.
