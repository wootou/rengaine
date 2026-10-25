# Development

## Layout

| Folder | Content |
|---|---|
| `api/` | `openapi.yaml`, the contract (edit it first, then the code); `doc.sh` builds a readable HTML page |
| `server/` | PHP code (`src/`), command line (`bin/rengaine`), entry point (`public/`), tests (`tests/`), helper scripts (`tools/`) |
| `db/` | `schema.sql` and numbered migrations |
| `web/` | React player (`src/`), unit tests (`tests/`, Vitest), browser tests (`e2e/`, Playwright) |
| `deploy/systemd/` | Timers and services for background jobs |
| `config/` | Example configuration and Apache virtual host |
| `docs/` | This documentation (English), and `docs/fr/` (French) |
| `website/` | The presentation site, built from the docs |

## Server tests

```bash
php server/tests/run.php              # everything (~3 min)
php server/tests/run.php Api          # classes whose name contains "Api"
```

They need a `rengaine_test` database the configured user can drop and recreate, `ffmpeg` (a small test
library is generated), and `python3-yaml` (to read the contract). API tests start PHP's built-in server
on a free port; a fake SMTP server captures mails. Machine-specific values (S3 test credentials, a
folder of real music for the storage parity test) go in `server/tests/local.env`, not tracked by git:

```
RENGAINE_TEST_S3_ENV=/path/to/s3-credentials.env
RENGAINE_TEST_MUSIC_DIR=/path/to/test/music
```

Tests that need them are skipped otherwise.

## Web player

```bash
cd web
npm ci
npm test               # Vitest
npm run check          # regenerate API types from the contract, then type-check
npm run build          # production build in web/dist
npm run e2e            # Playwright in headless Chromium
```

`npm run e2e` runs against a real server with temporary accounts (created and deleted through
`server/bin/rengaine`). Set the address in `web/.env.e2e.local` (not tracked):

```
E2E_BASE_URL=https://music.test
E2E_HOST_RULES=MAP music.test 127.0.0.1
```

## Demo library

`sudo -u www-data php server/bin/rengaine demo:generer <folder>` writes a fully fictional library (14 albums, generated
covers, synthesised music) into `<folder>/inbox/`; `rengaine index` then files it. Useful for screenshots and
trials without any real music. Needs ffmpeg and ImageMagick.

## Conventions

- Contract first: change `api/openapi.yaml`, check it (`npx @redocly/cli lint api/openapi.yaml`), then
  the server, then the clients. A new operation without a route, or a route without the contract, fails
  `ContractTest`.
- Database changes are new files in `db/migrations/`, safe to run twice; `rengaine db:migrate` applies
  them.
- Every user-facing text in the web player goes through `tr()`; add its English translation in
  `web/src/i18n/en.js`. Server messages sent to clients have their translation in `server/lang/en.php`.
- Accessibility: AA contrast, 44 px touch targets, no horizontal scroll at 360 px.
