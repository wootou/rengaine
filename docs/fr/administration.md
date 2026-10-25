# Administration

Le menu **Admin** apparaît pour les comptes admin. La plupart des tâches se font aussi en ligne de
commande : `sudo -u www-data php server/bin/rengaine aide` liste les commandes.

## Comptes

On demande un compte depuis la page de connexion (le lien direct `https://<site>/?demande` ouvre le
formulaire ; **Admin > Serveur** l'affiche). Les admins reçoivent un mail, puis approuvent ou refusent dans
**Admin > Comptes**. Les demandes peuvent être fermées, et le nombre de demandes en attente par réseau est
limité.

Rôles : **utilisateur**, **admin**, et **agent** (une IA qui travaille sur la bibliothèque : elle lit le
catalogue et propose des corrections, rien d'autre ; elle n'a pas de mot de passe, seulement des jetons
créés par un admin). Suspendre, réactiver, changer de rôle, voir les appareils et les déconnecter : depuis
la fiche du compte.

## Bibliothèque

- **Genres**, **Tags** : créer des genres et sous-genres, rattacher des valeurs de tags à des genres,
  fusionner des genres.
- **Collections** : des groupes d'albums faits à la main, affichés dans Explorer.
- **Corrections** : tout ce qui a été changé à la main ou par une IA, annulable.
- **Propositions** : les lots proposés par un agent ; appliquer, refuser, ou annuler après application.
- **Doublons** : copies MP3 de pistes sans perte (déplacées dans une corbeille sur demande, restaurables
  avec `rengaine corbeille:restaurer`), mêmes enregistrements trouvés par empreinte, fichiers abîmés.
- **Tâches** : scans, analyse, état du dépôt, erreurs par fichier ; lancer un scan ou une analyse.
- **Fonds** : images affichées derrière le lecteur, une au hasard par visite.

## Serveur

**Admin > Serveur** : nom du serveur, langue du site (français ou anglais), demandes de compte,
interrupteur de la télécommande partagée, et le modèle d'IA configuré dans `[ia]` (s'il y en a un).

## Journaux et audit

- **Admin > Journal** : journaux de l'application (API, ligne de commande, sécurité), filtrables.
- L'audit garde les connexions, les changements de compte, les corrections, les tâches et les réglages,
  avec qui et depuis quelle IP. Les IP sont effacées après `conservation_ip_jours`.
- `securite.log` (connexions ratées, jetons invalides) est fait pour fail2ban.
