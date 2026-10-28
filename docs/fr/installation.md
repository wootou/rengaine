# Installation

Ce guide installe Rengaine sur un serveur Debian ou Ubuntu. Il a été testé sur Ubuntu 26.04 avec PHP 8.5,
MariaDB 11.8 et Apache 2.4 ; d'autres versions récentes devraient convenir, mais ne sont pas testées.
La détection des fichiers abîmés lit les messages de FFmpeg 7 ou plus récent : avec un FFmpeg plus ancien,
tout le reste fonctionne, mais les fichiers abîmés ne sont pas signalés.

Rengaine n'utilise aucune bibliothèque PHP (pas de Composer). Le lecteur web se construit une fois sur le
serveur avec Node.js (20.19 ou plus récent, ou 22.12 ou plus récent) : il n'existe pas encore d'archive
déjà construite.

Chemins utilisés : le code dans `/var/www/rengaine`, la configuration dans `/etc/rengaine/rengaine.ini`, les
journaux dans `/var/log/rengaine`, le cache dans `/var/cache/rengaine`. Les unités systemd de
`deploy/systemd/` supposent ce chemin pour le code ; changez les deux si vous installez ailleurs.

## 1. Paquets

```bash
apt-get update
apt-get install -y apache2 php-fpm php-curl php-mbstring php-intl php-mysql php-apcu php-xml \
                   mariadb-server ffmpeg git
systemctl restart php*-fpm          # charge APCu (compteurs de la limitation du débit)
```

Facultatif, pour une analyse plus riche :

```bash
# Tempo, tonalité et grilles des temps (Essentia, AGPL-3.0, dans son propre environnement Python, ~130 Mo)
apt-get install -y python3-venv
python3 -m venv /opt/rengaine-analyse
/opt/rengaine-analyse/bin/pip install -r /var/www/rengaine/server/tools/requirements.txt   # après l'étape 4
chmod -R o+rX /opt/rengaine-analyse

# Empreintes acoustiques, pour retrouver un même enregistrement à plusieurs endroits (Chromaprint)
apt-get install -y libchromaprint-tools
```

Sans Essentia, les pistes ont tout de même leur volume, leurs silences et leurs fondus ; les fondus calés
sur le tempo et les filtres « DJ » en ont besoin.

## 2. Apache avec PHP-FPM

Chaque écoute occupe un processus PHP-FPM pendant toute la piste : utilisez le MPM event et donnez assez de
processus à PHP-FPM.

```bash
a2dismod mpm_prefork php* 2>/dev/null; a2enmod mpm_event proxy_fcgi setenvif rewrite remoteip headers proxy_http
a2enconf php*-fpm
```

Dans le pool FPM (`/etc/php/<version>/fpm/pool.d/www.conf`), pour un foyer :

```ini
pm = dynamic
pm.max_children = 20      ; ~22 Mo chacun ; un par écoute en cours, plus les requêtes de l'API
pm.start_servers = 3
pm.min_spare_servers = 2
pm.max_spare_servers = 6
pm.max_requests = 500
```

Copiez ensuite `config/apache-rengaine.conf.example` dans `/etc/apache2/sites-available/rengaine.conf`,
adaptez le nom du site et les chemins, puis `a2ensite rengaine`, `apachectl configtest && systemctl reload apache2`.

Nginx devrait fonctionner aussi (même organisation : fichiers statiques de `web/dist`, `/api/v2/*` vers
`server/public/index.php`), mais n'est pas testé.

## 3. Base de données

```bash
PW=$(openssl rand -base64 33 | tr -d '/+=\n' | cut -c1-40)
mariadb <<SQL
CREATE DATABASE rengaine CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci;
CREATE USER 'rengaine'@'localhost' IDENTIFIED BY '$PW';
GRANT ALL PRIVILEGES ON rengaine.* TO 'rengaine'@'localhost';
SQL
echo "Mettez ce mot de passe dans [base] mot_de_passe : $PW"; unset PW
```

La collation rend les recherches insensibles à la casse et aux accents.

## 4. Code et configuration

```bash
git clone https://github.com/wootou/rengaine.git /var/www/rengaine
git -C /var/www/rengaine checkout v1.0.2   # une version publiée (la branche main continue d'avancer)
install -d -m 750 -o root -g www-data /etc/rengaine
install -m 640 -o root -g www-data /var/www/rengaine/config/rengaine.ini.example /etc/rengaine/rengaine.ini
editor /etc/rengaine/rengaine.ini          # voir configuration.md
install -d -m 2750 -o www-data -g adm /var/log/rengaine
install -d -m 750 -o www-data -g www-data /var/cache/rengaine
```

Construire le lecteur web (obligatoire). Il faut Node.js 20.19+ ou 22.12+ : vérifiez `node --version`,
certaines distributions en livrent un plus ancien (Debian 12, Ubuntu 24.04) ; installez alors un Node.js
récent depuis [nodejs.org](https://nodejs.org/) ou NodeSource.

```bash
apt-get install -y nodejs npm              # si la version de la distribution est assez récente
cd /var/www/rengaine/web && npm ci && npm run build
```

Créer le schéma et tout vérifier :

```bash
M='sudo -u www-data php /var/www/rengaine/server/bin/rengaine'
$M config:check        # configuration (secrets masqués), base, stockage, adresse du site
$M db:migrate          # crée le schéma ; plus tard, applique les migrations en attente
```

Lancez toujours la commande en `www-data` : elle écrit dans le dossier des journaux.

## 5. Tâches de fond

L'indexation, l'analyse audio et les tâches demandées depuis l'admin tournent par des minuteurs systemd,
jamais pendant une requête web.

```bash
cp /var/www/rengaine/deploy/systemd/rengaine-*.{service,timer} /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now rengaine-index.timer rengaine-analyze.timer rengaine-jobs.timer
```

- `rengaine-index` : range les albums déposés dans `inbox/`, puis cherche ce qui a changé dans la bibliothèque (toutes les 15 min).
- `rengaine-analyze` : volume, silences, tempo, tonalité, empreintes, en arrière-plan, en basse priorité.
- `rengaine-jobs` : tâches lancées depuis l'admin, envoi des mails, nettoyage quotidien (chaque minute).

Lancez un premier scan à la main pour le voir tourner : `$M index`, puis `$M library:stats`.

## 6. Premier compte admin

```bash
$M user:create vous@example.org "Votre nom" --role admin     # demande le mot de passe
```

Ouvrez le site, connectez-vous, et gérez les autres comptes dans **Admin > Comptes**.

## 7. Rotation des journaux

```bash
cat > /etc/logrotate.d/rengaine <<'EOF2'
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
EOF2
```

`securite.log` liste les connexions ratées et les jetons invalides avec l'IP du client, une ligne chacun :
il peut alimenter fail2ban.

## 8. Facultatif : hub temps réel (télécommande partagée)

La télécommande partagée permet à un invité de piloter la file d'un lecteur depuis son téléphone. Elle
utilise [Mercure](https://mercure.rocks/) (AGPL-3.0), un programme à part.

```bash
V=1.1.4; cd "$(mktemp -d)"
curl -sLO https://github.com/dunglas/mercure/releases/download/v$V/checksums.txt
curl -sLO https://github.com/dunglas/mercure/releases/download/v$V/mercure_Linux_x86_64.tar.gz
grep Linux_x86_64 checksums.txt | sha256sum -c -
tar -xzf mercure_Linux_x86_64.tar.gz && install -m 755 mercure /usr/local/bin/mercure
useradd --system --no-create-home --home-dir /var/lib/mercure --shell /usr/sbin/nologin mercure
install -d -m 750 -o root -g mercure /etc/mercure
PK=$(head -c 48 /dev/urandom | base64 | tr -d '\n/+='); SK=$(head -c 48 /dev/urandom | base64 | tr -d '\n/+=')
cat > /etc/mercure/mercure.env <<EOF2
MERCURE_ISSUER=https://music.example.org
MERCURE_RESOURCE=https://music.example.org/.well-known/mercure
MERCURE_PUBLISHER_JWT_KEY=$PK
MERCURE_SUBSCRIBER_JWT_KEY=$SK
EOF2
chown root:mercure /etc/mercure/mercure.env && chmod 640 /etc/mercure/mercure.env
# Recopiez les quatre mêmes valeurs dans la section [mercure] de rengaine.ini, puis : unset PK SK
```

`/etc/mercure/Caddyfile` (le hub n'écoute que sur la machine elle-même) :

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

`/etc/systemd/system/mercure.service` :

```ini
[Unit]
Description=Hub Mercure (temps réel de Rengaine)
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

Ensuite `systemctl daemon-reload && systemctl enable --now mercure`. L'exemple de vhost relaie déjà
`/.well-known/mercure` (abonnements seulement). Un proxy placé devant ne doit pas retenir
`text/event-stream` et doit garder les connexions ouvertes au moins une minute.

## 9. Vérifications

```bash
systemctl is-active apache2 php*-fpm mariadb
curl -s https://music.example.org/api/v2/server        # JSON avec la version de l'API
$M config:check
systemctl list-timers 'rengaine-*'
```

Ensuite : [configuration](configuration.md), [bibliothèque](library.md), [mise à jour](upgrading.md).
