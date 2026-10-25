# Contributing to Rengaine

Thank you for helping! Bug reports, ideas, translations and pull requests are all welcome.

## Reporting a bug

Open an issue with what you did, what you expected and what happened, your versions (PHP, MariaDB,
browser or Android version) and the `requestId` shown in the error message if there is one. Never paste
tokens, passwords or your `rengaine.ini`. Security issues: see [SECURITY.md](SECURITY.md).

## Before a pull request

1. **Contract first.** If the API changes, edit `api/openapi.yaml` first and check it:
   `npx @redocly/cli lint api/openapi.yaml`. Bump `info.version` (minor for additions, major for breaking
   changes) and `Kernel::VERSION` with it.
2. **Database changes** are new, numbered files in `db/migrations/`, safe to run twice.
3. **Run the tests** (see [docs/development.md](docs/development.md)):
   - `php server/tests/run.php`
   - `cd web && npm test && npm run check`
   - `cd web && npm run e2e` when the change touches the player
4. **Texts**: every text of the web player goes through `tr()` with its English translation in
   `web/src/i18n/en.js`; server messages sent to clients need theirs in `server/lang/en.php`. Tests fail
   when one is missing.
5. **Accessibility**: AA contrast, 44 px touch targets, nothing overflows at 360 px wide.
6. Keep dependencies at zero on the server side (no Composer) and minimal in the web player.

Code comments and commit messages may be in French or English. One topic per pull request, please.

By contributing, you agree that your contribution is released under the MIT License.
