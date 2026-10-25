# Installation

This guide installs Rengaine on a Debian or Ubuntu server. It has been tested on Ubuntu 26.04 with
PHP 8.5, MariaDB 11.8 and Apache 2.4; other recent versions should work but are not tested.

Rengaine needs no PHP library (no Composer) and serves a pre-built web player: Node.js is only needed
to build that player (or use a release archive, which already contains it).

Paths used below: code in `/var/www/rengaine`, configuration in `/etc/rengaine/rengaine.ini`, logs in
`/var/log/rengaine`, cache in `/var/cache/rengaine`. The systemd units in `deploy/systemd/` assume the
code path; change both if you install elsewhere.

## 1. Packages

```bash
apt-get update
apt-get install -y apache2 php-fpm php-curl php-mbstring php-intl php-mysql php-apcu php-xml \
                   mariadb-server ffmpeg git
systemctl restart php*-fpm          # loads APCu (rate-limit counters)
```

Optional, for richer analysis:

```bash
# Tempo, key and beat grids (Essentia, AGPL-3.0, in its own Python environment, ~130 MB)
apt-get install -y python3-venv
python3 -m venv /opt/rengaine-analyse
/opt/rengaine-analyse/bin/pip install -r /var/www/rengaine/server/tools/requirements.txt   # after step 4
chmod -R o+rX /opt/rengaine-analyse

# Acoustic fingerprints, to find the same recording in several places (Chromaprint)
apt-get install -y libchromaprint-tools
```

Without Essentia, tracks still get loudness, silences and fades; tempo-synced transitions and the
"DJ" filters need it.

## 2. Apache with PHP-FPM

Each audio stream keeps one PHP-FPM process busy for the length of the track, so use the event MPM and
give PHP-FPM enough processes.

```bash
a2dismod mpm_prefork php* 2>/dev/null; a2enmod mpm_event proxy_fcgi setenvif rewrite remoteip headers proxy_http
a2enconf php*-fpm
```

In the FPM pool (`/etc/php/<version>/fpm/pool.d/www.conf`), for a household:

```ini
pm = dynamic
pm.max_children = 20      ; ~22 MB each; one per listener plus API requests
pm.start_servers = 3
pm.min_spare_servers = 2
pm.max_spare_servers = 6
pm.max_requests = 500
```

Then copy `config/apache-rengaine.conf.example` to `/etc/apache2/sites-available/rengaine.conf`, adapt
the server name and paths, `a2ensite rengaine`, `apachectl configtest && systemctl reload apache2`.

Nginx should work too (same layout: static files from `web/dist`, `/api/v2/*` to
`server/public/index.php`) but is not tested.

## 3. Database

```bash
PW=$(openssl rand -base64 33 | tr -d '/+=\n' | cut -c1-40)
mariadb <<SQL
CREATE DATABASE rengaine CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci;
CREATE USER 'rengaine'@'localhost' IDENTIFIED BY '$PW';
GRANT ALL PRIVILEGES ON rengaine.* TO 'rengaine'@'localhost';
SQL
echo "Put this password in [base] mot_de_passe: $PW"; unset PW
```

The collation makes searches insensitive to case and accents.

## 4. Code and configuration

```bash
git clone https://github.com/<owner>/rengaine.git /var/www/rengaine
install -d -m 750 -o root -g www-data /etc/rengaine
install -m 640 -o root -g www-data /var/www/rengaine/config/rengaine.ini.example /etc/rengaine/rengaine.ini
editor /etc/rengaine/rengaine.ini          # see configuration.md
install -d -m 2750 -o www-data -g adm /var/log/rengaine
install -d -m 750 -o www-data -g www-data /var/cache/rengaine
```

Build the web player (skip with a release archive):

```bash
apt-get install -y nodejs npm
cd /var/www/rengaine/web && npm ci && npm run build
```

Create the schema and check everything:

```bash
M='sudo -u www-data php /var/www/rengaine/server/bin/rengaine'
$M config:check        # configuration (secrets hidden), database, storage, site address
$M db:migrate          # creates the schema; later, applies pending migrations
```

Always run the command as `www-data`: it writes to the log folder.

## 5. Background jobs

Indexing, audio analysis and admin-requested jobs run from systemd timers, never inside a web request.

```bash
cp /var/www/rengaine/deploy/systemd/rengaine-*.{service,timer} /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now rengaine-index.timer rengaine-analyze.timer rengaine-jobs.timer
```

- `rengaine-index`: files the albums dropped in `inbox/`, then scans the library for changes (every 15 min).
- `rengaine-analyze`: loudness, silences, tempo, key, fingerprints, in the background, low priority.
- `rengaine-jobs`: jobs started from the admin, outgoing mail, daily clean-up (every minute).

Run a first scan by hand to see it work: `$M index`, then `$M library:stats`.

## 6. First admin account

```bash
$M user:create you@example.org "Your name" --role admin     # asks for the password
```

Open the site, sign in, and manage the other accounts from **Admin > Accounts**.

## 7. Log rotation

```bash
cat > /etc/logrotate.d/rengaine <<'EOF'
/var/log/rengaine/*.log {
    daily
    rotate 30
    compress
    delaycompress
    missingok
    notifempty
    create 0640 www-data adm
    su www-data adm
}
EOF
```

`securite.log` lists failed sign-ins and invalid tokens with the client IP, one line each: it can feed
fail2ban.

## 8. Optional: real-time hub (shared remote control)

The shared remote control lets a guest steer the queue of a player from their phone. It uses
[Mercure](https://mercure.rocks/) (AGPL-3.0), a separate program.

```bash
V=1.1.4; cd "$(mktemp -d)"
curl -sLO https://github.com/dunglas/mercure/releases/download/v$V/checksums.txt
curl -sLO https://github.com/dunglas/mercure/releases/download/v$V/mercure_Linux_x86_64.tar.gz
grep Linux_x86_64 checksums.txt | sha256sum -c -
tar -xzf mercure_Linux_x86_64.tar.gz && install -m 755 mercure /usr/local/bin/mercure
useradd --system --no-create-home --home-dir /var/lib/mercure --shell /usr/sbin/nologin mercure
install -d -m 750 -o root -g mercure /etc/mercure
PK=$(head -c 48 /dev/urandom | base64 | tr -d '\n/+='); SK=$(head -c 48 /dev/urandom | base64 | tr -d '\n/+=')
cat > /etc/mercure/mercure.env <<EOF
MERCURE_ISSUER=https://music.example.org
MERCURE_RESOURCE=https://music.example.org/.well-known/mercure
MERCURE_PUBLISHER_JWT_KEY=$PK
MERCURE_SUBSCRIBER_JWT_KEY=$SK
EOF
chown root:mercure /etc/mercure/mercure.env && chmod 640 /etc/mercure/mercure.env
# Copy the same four values into the [mercure] section of rengaine.ini, then: unset PK SK
```

`/etc/mercure/Caddyfile` (the hub listens on localhost only):

```caddyfile
{
	auto_https off
	admin off
	persist_config off
	grace_period 3s
}
http://127.0.0.1:3700 {
	bind 127.0.0.1
	mercure {
		issuer {$MERCURE_ISSUER} {
			publisher {
				jwt {env.MERCURE_PUBLISHER_JWT_KEY} HS256
			}
			subscriber {
				jwt {env.MERCURE_SUBSCRIBER_JWT_KEY} HS256
			}
		}
		resource_identifier {$MERCURE_RESOURCE}
	}
	respond "Not Found" 404
}
```

`/etc/systemd/system/mercure.service`:

```ini
[Unit]
Description=Mercure hub (Rengaine real time)
After=network.target

[Service]
User=mercure
Group=mercure
EnvironmentFile=/etc/mercure/mercure.env
Environment=XDG_CONFIG_HOME=/var/lib/mercure XDG_DATA_HOME=/var/lib/mercure
StateDirectory=mercure
ExecStart=/usr/local/bin/mercure run --config /etc/mercure/Caddyfile --adapter caddyfile
Restart=on-failure
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes

[Install]
WantedBy=multi-user.target
```

`systemctl daemon-reload && systemctl enable --now mercure`. The Apache example already proxies
`/.well-known/mercure` (subscriptions only). A reverse proxy in front must not buffer
`text/event-stream` and must keep connections open for at least a minute.

## 9. Checks

```bash
systemctl is-active apache2 php*-fpm mariadb
curl -s https://music.example.org/api/v2/server        # JSON with the API version
$M config:check
systemctl list-timers 'rengaine-*'
```

Next: [configuration](configuration.md), [library](library.md), [upgrading](upgrading.md).
