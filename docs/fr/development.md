# Développement

## Organisation

| Dossier | Contenu |
|---|---|
| `api/` | `openapi.yaml`, le contrat (on le modifie d'abord, le code suit) ; `doc.sh` en fait une page HTML lisible |
| `server/` | Code PHP (`src/`), ligne de commande (`bin/rengaine`), point d'entrée (`public/`), tests (`tests/`), scripts (`tools/`) |
| `db/` | `schema.sql` et migrations numérotées |
| `web/` | Lecteur React (`src/`), tests unitaires (`tests/`, Vitest), essais navigateur (`e2e/`, Playwright) |
| `deploy/systemd/` | Minuteurs et services des tâches de fond |
| `config/` | Configuration et vhost Apache d'exemple |
| `docs/` | Cette documentation (anglais), et `docs/fr/` (français) |
| `website/` | Le site de présentation, construit depuis la doc |

## Tests du serveur

```bash
php server/tests/run.php              # tout (~3 min)
php server/tests/run.php Api          # les classes dont le nom contient « Api »
```

Il leur faut une base `rengaine_test` que le compte configuré peut vider et recréer, `ffmpeg` (une petite
bibliothèque d'essai est fabriquée), et `python3-yaml` (pour lire le contrat). Les tests de l'API lancent le
serveur intégré de PHP sur un port libre ; un faux serveur SMTP capture les mails. Les valeurs propres à une
machine (identifiants S3 d'essai, dossier de vraie musique pour le test de parité du stockage) vont dans
`server/tests/local.env`, non suivi par git :

```
RENGAINE_TEST_S3_ENV=/chemin/vers/identifiants-s3.env
RENGAINE_TEST_MUSIC_DIR=/chemin/vers/musique/de/test
```

Sans elles, les tests concernés sont sautés.

## Lecteur web

```bash
cd web
npm ci
npm test               # Vitest
npm run check          # régénère les types depuis le contrat, puis vérifie les types
npm run build          # construction de production dans web/dist
npm run e2e            # Playwright dans Chromium sans affichage
```

`npm run e2e` tourne contre un vrai serveur, avec des comptes temporaires (créés et supprimés par
`server/bin/rengaine`). Donnez l'adresse dans `web/.env.e2e.local` (non suivi) :

```
E2E_BASE_URL=https://music.test
E2E_HOST_RULES=MAP music.test 127.0.0.1
```

## Bibliothèque de démonstration

`sudo -u www-data php server/bin/rengaine demo:generer <dossier>` écrit une bibliothèque entièrement fictive
(14 albums, pochettes générées, musique synthétisée) dans `<dossier>/inbox/` ; `rengaine index` la range ensuite.
Pratique pour des captures ou des essais sans vraie musique. Il faut ffmpeg et ImageMagick.

## Conventions

- Le contrat d'abord : modifier `api/openapi.yaml`, le vérifier (`npx @redocly/cli lint api/openapi.yaml`),
  puis le serveur, puis les clients. Une opération sans route, ou une route absente du contrat, fait
  échouer `ContractTest`.
- Un changement de base est un nouveau fichier dans `db/migrations/`, relançable sans effet ;
  `rengaine db:migrate` l'applique.
- Chaque texte du lecteur web passe par `tr()` ; ajoutez sa traduction anglaise dans `web/src/i18n/en.js`.
  Les messages du serveur envoyés aux clients ont leur traduction dans `server/lang/en.php`.
- Accessibilité : contraste AA, cibles tactiles de 44 px, aucun défilement horizontal à 360 px.
