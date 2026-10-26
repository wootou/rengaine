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

- **Mémoire** : comptez environ 1 Go par processus. Essentia charge tout le morceau ; les pistes de plus
  de 20 minutes (mixes, sets) sont donc analysées sur des extraits (10 minutes au milieu, la première
  et la dernière minute).
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
