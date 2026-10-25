# Architecture

```
  Stockage (dossier ou S3)  ── octets audio et images
        │                    ▲
        │ liste, ffprobe,    │ une piste ou une image à la fois
        │ analyse            │
        ▼                    │
  Tâches de fond (systemd) ──▶ MariaDB ◀── API (PHP-FPM) ◀── lecteur web (React)
  indexeur, analyse, tâches                     ▲         ◀── appli Android
                                                └──────── ◀── agents d'IA (MCP)
```

**Aucune requête web ne parcourt le stockage.** Navigation, recherche, aléatoire et genres viennent de
MariaDB ; le stockage n'est lu que pour la piste qui joue ou pour une image. Un scan demandé depuis l'admin
ne fait que mettre une tâche en file, qu'un minuteur systemd exécute.

## Serveur

- PHP 8, aucune bibliothèque externe. Un seul point d'entrée (`server/public/index.php`), un petit
  routeur, des contrôleurs par domaine (`server/src/Api/Controllers`), du SQL simple par PDO.
- **Le contrat d'abord** : `api/openapi.yaml` décrit chaque opération, schéma, rôle et code d'erreur. Un
  test vérifie que la table des routes et le contrat concordent, et les tests de l'API valident chaque
  réponse contre lui. Les types du lecteur web en sont générés.
- Stockage : une interface, deux pilotes (dossier local, S3 avec signature V4) ; la même suite de tests
  passe contre les deux.
- Catalogue : les colonnes contiennent la valeur *effective* (ce que l'indexeur a lu, recouvert par les
  corrections actives). Un scan n'écrase jamais une correction ; annuler une correction remet l'ancienne
  valeur.
- Identifiants stables : un fichier inchangé n'est pas relu ; un fichier renommé ou déplacé garde sa
  piste ; un fichier disparu est marqué, pas supprimé. Favoris et historique sont préservés.
- Analyse : des processus prennent leur travail dans une file avec `FOR UPDATE SKIP LOCKED` ; ffmpeg
  mesure le volume (EBU R128), les silences et la forme d'onde ; Essentia donne tempo, tonalité et grilles
  des temps au début et à la fin de chaque piste ; Chromaprint donne les empreintes.
- Conversion à la volée en Opus ou MP3 par ffmpeg, gardée en cache ; avancer dans une conversion encore
  en cours la relance à la position demandée.
- Temps réel (télécommande partagée) : l'API publie vers un hub Mercure ; les navigateurs s'y abonnent avec
  des jetons de courte durée limités à leurs sujets. Un invité n'écrit jamais la file : le serveur vérifie
  sa commande et la relaie au lecteur qui joue, qui l'applique et l'enregistre.

## Sécurité

- Mots de passe : argon2id. Sessions web : cookie HttpOnly, Secure, SameSite=Strict, plus un en-tête
  anti-CSRF. Applis et agents : jetons Bearer, un par appareil, seule leur empreinte est gardée.
- Refus par défaut : une route sans rôle indiqué est pour les utilisateurs et les admins ; les agents
  n'ont que les routes qui les citent.
- Les échecs de connexion sont comptés par compte et par réseau ; un compte inconnu et un mauvais mot de
  passe reçoivent la même réponse, dans le même temps.
- Limitation du débit par adresse IPv4 ou par /64 en IPv6 (APCu). Un avertissement PHP ne devient jamais
  une erreur 500 ; chaque erreur a un code stable et un identifiant de requête.
- Journaux en JSON, une ligne par évènement ; les IP sont effacées après un délai réglable ; chacun peut
  exporter ou effacer ses données.

## Lecteur web

React 19 en JavaScript (vérifié par `// @ts-check`), une seule feuille de style, aucun framework CSS, aucun
CDN. Deux éléments audio s'enchaînent par Web Audio ; le gain vient des mesures de volume du serveur.
L'interface se traduit en prenant le texte français comme clé (`web/src/i18n/en.js`) ; un test échoue si
un texte n'a pas de traduction.
