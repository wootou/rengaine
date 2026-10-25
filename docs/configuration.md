# Configuration

Rengaine reads a single INI file, `/etc/rengaine/rengaine.ini` (or the path in the `RENGAINE_CONFIG`
environment variable). Start from [`config/rengaine.ini.example`](../config/rengaine.ini.example): every
key is listed there with its default value and a short explanation.

Keep the file out of the web root, owned by `root:www-data`, mode `640`: it holds the database password,
storage keys, SMTP credentials and, if used, an AI API key. `rengaine config:check` prints the
configuration with every secret masked, then tests the database, the storage and the site address.

## Required sections

| Section | Purpose |
|---|---|
| `[base]` | MariaDB connection |
| `[stockage]` | Where the music lives: a local folder (`mode = "disque"`, `chemin`) or S3-compatible storage (`mode = "s3"`, endpoint, bucket, keys). Object keys are paths relative to the root, so switching modes changes nothing in the database. |
| `[site] url` | Public address of the site. Required: it goes into emails, pairing QR codes and remote-control links, and is never guessed from the request (a client could forge it). |

## S3 storage

The bucket must exist beforehand; Rengaine never creates it. There are no folders to create: `inbox/`
(albums to file) and `corbeille/` (files set aside, e.g. duplicates) are just key prefixes, created by
the first object put there. Requests use path-style addressing (`s3_url/bucket/key`) with AWS
Signature V4.

Permissions the access key needs on that bucket:

| Operation | Used for |
|---|---|
| `s3:ListBucket` (ListObjectsV2) | scanning the library and `inbox/` |
| `s3:GetObject` (GET, ranged GET, HEAD, pre-signed GET) | streaming, analysis (FFmpeg reads a pre-signed URL), metadata |
| `s3:PutObject` | filing albums from `inbox/`, setting duplicates aside; also the target of a copy |
| `s3:DeleteObject` | removing the source after a move (copy then delete) |

A move is a server-side copy (`x-amz-copy-source`) followed by a delete. A read-only key (list and get)
is enough if you never use `inbox/` nor set files aside from the admin.

## Mail

Without `[smtp] hote` and `expediteur`, Rengaine sends no mail at all; everything else works. With mail:
account requests notify the admins, password reset works, users get a mail when their account is
approved, and admins receive a digest of background-job errors. Test with
`rengaine mail:test you@example.org`, check the queue with `rengaine mail:status`.

## Retention and privacy

`[journaux]` sets the log folder and level, and how long data is kept: audit entries, finished jobs and
sent mails (`conservation_jours`), IP addresses (`conservation_ip_jours`), listening history
(`historique_ecoute_jours`, 0 = forever). The clean-up runs once a day from `rengaine-jobs`; preview it
with `rengaine journaux:purger --simuler`.

Users can download everything the server keeps about them (Settings > Account), delete their listening
history, and delete their account.

## Server settings in the admin

Some settings live in the database and are changed in **Admin > Server**: server name, site language
(French or English, one language for all accounts), whether account requests are open and how many may
wait per network, and whether the shared remote control is enabled.

## Rate limits

`[limites]` holds per-network counters (an IPv4 address, or an IPv6 /64). They need APCu
(`php-apcu`); without it, the server logs a warning and does not limit. After 5 failed sign-ins from
one network, that account is refused from that network for 15 minutes, with the same answer as a wrong
password; its owner can still sign in from elsewhere.
