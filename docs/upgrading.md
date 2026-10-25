# Upgrading, backups

## Upgrading

```bash
cd /var/www/rengaine
git fetch --tags && git checkout vX.Y.Z    # the new version (see CHANGELOG.md)
cd web && npm ci && npm run build && cd .. # rebuild the web player
M='sudo -u www-data php /var/www/rengaine/server/bin/rengaine'
$M db:status                               # pending migrations
$M db:migrate                              # back up the database first (below)
systemctl reload php*-fpm
```

Migrations only add or change what they need, and say so at the top of each file in `db/migrations/`.
Check the release notes for configuration changes, and compare your `rengaine.ini` with
`config/rengaine.ini.example`.

## What to back up

| What | Why |
|---|---|
| The database | Accounts, settings, playlists, favourites, history, corrections, genres: impossible to rebuild. |
| `/etc/rengaine/rengaine.ini` | Your configuration and secrets. |
| Your music | Rengaine never modifies music files, but the inbox moves new albums into place. |

The cache (`/var/cache/rengaine`) and the logs can be lost: covers, thumbnails and conversions are
rebuilt as needed.

```bash
mariadb-dump --single-transaction --routines --triggers rengaine | gzip > rengaine-$(date +%F).sql.gz
chmod 600 rengaine-*.sql.gz      # contains password hashes and personal data
```

Restore: create an empty database, `gunzip -c rengaine-YYYY-MM-DD.sql.gz | mariadb rengaine`, then
`rengaine db:migrate` if the code is newer than the dump.
