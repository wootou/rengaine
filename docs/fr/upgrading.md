# Mise à jour, sauvegardes

## Mettre à jour

```bash
cd /var/www/rengaine
git pull                                   # ou décompresser la nouvelle version par-dessus l'ancienne
cd web && npm ci && npm run build && cd .. # inutile avec une archive de version
M='sudo -u www-data php /var/www/rengaine/server/bin/rengaine'
$M db:status                               # migrations en attente
$M db:migrate                              # sauvegardez la base avant (voir plus bas)
systemctl reload php*-fpm
```

Les migrations n'ajoutent ou ne changent que ce dont elles ont besoin, et le disent en tête de chaque
fichier de `db/migrations/`. Lisez les notes de version pour les changements de configuration, et comparez
votre `rengaine.ini` à `config/rengaine.ini.example`.

## Quoi sauvegarder

| Quoi | Pourquoi |
|---|---|
| La base | Comptes, réglages, playlists, favoris, historique, corrections, genres : impossible à reconstruire. |
| `/etc/rengaine/rengaine.ini` | Votre configuration et vos secrets. |
| Votre musique | Rengaine ne modifie jamais un fichier de musique, mais le dépôt déplace les nouveaux albums. |

Le cache (`/var/cache/rengaine`) et les journaux peuvent être perdus : pochettes, vignettes et conversions
sont refaites au besoin.

```bash
mariadb-dump --single-transaction --routines --triggers rengaine | gzip > rengaine-$(date +%F).sql.gz
chmod 600 rengaine-*.sql.gz      # contient des empreintes de mots de passe et des données personnelles
```

Restauration : créer une base vide, `gunzip -c rengaine-AAAA-MM-JJ.sql.gz | mariadb rengaine`, puis
`rengaine db:migrate` si le code est plus récent que la sauvegarde.
