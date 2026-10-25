**What this changes and why**

**Checks**
- [ ] `api/openapi.yaml` updated first (if the API changes), Redocly lint passes
- [ ] `php server/tests/run.php` passes
- [ ] `cd web && npm test && npm run check` pass
- [ ] `npm run e2e` passes (if the player changes)
- [ ] New texts translated (`web/src/i18n/en.js`, `server/lang/en.php`)
