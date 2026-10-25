// @ts-check
// Mode infini (WEB-05) dans le vrai navigateur : la file se complète toute seule.
import { expect, test } from '@playwright/test';

/** @param {import('@playwright/test').Page} page */
async function login(page) {
  await page.goto('/');
  await page.getByLabel('Adresse e-mail').fill(String(process.env.E2E_EMAIL));
  await page.getByLabel('Mot de passe').fill(String(process.env.E2E_PASSWORD));
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('heading', { name: /Albums/ })).toBeVisible();
}

/** @param {import('@playwright/test').Page} page */
function state(page) {
  return page.evaluate(() => {
    const c = /** @type {any} */ (window).musique;
    const s = c.store.getState();
    const i = s.queue.items.findIndex((/** @type {any} */ it) => it.key === s.queue.currentKey);
    return { count: s.queue.items.length, index: i, infinite: s.infinite, playing: s.playing };
  });
}

/**
 * Met le mode infini dans l'état voulu par le bouton du lecteur (c'est un réglage du compte : il
 * garde la valeur laissée par un essai précédent).
 * @param {import('@playwright/test').Page} page @param {boolean} on
 */
async function ensureInfinite(page, on) {
  await expect.poll(async () => (await state(page)).infinite).toBeTruthy();
  if ((await state(page)).infinite.enabled !== on) await page.getByRole('button', { name: /mode infini/i }).click();
  await expect.poll(async () => (await state(page)).infinite.enabled).toBe(on);
}

/** @param {import('@playwright/test').Page} page */
function watch(page) {
  /** @type {string[]} */
  const log = [];
  page.on('console', (m) => (m.type() === 'error' || m.type() === 'warning') && log.push(`console ${m.type()} : ${m.text()}`));
  page.on('pageerror', (e) => log.push(`pageerror : ${e.message}`));
  page.on('response', (r) => r.url().includes('/random/') && log.push(`${r.status()} ${r.url().replace(/^.*\/api\/v2/, '')}`));
  return log;
}

test('activer le mode infini près de la fin d’un album complète la file', async ({ page }) => {
  const log = watch(page);
  await login(page);
  await ensureInfinite(page, false);
  await page.goto('/albums/3');
  await page.locator('.tracklist .track-row').nth(10).click(); // 11e piste sur 12
  await expect.poll(async () => (await state(page)).index).toBe(10);
  await expect.poll(async () => (await state(page)).count).toBe(12); // éteint : la file reste l'album
  await ensureInfinite(page, true);
  await expect.poll(async () => (await state(page)).count, { timeout: 10_000, message: log.join('\n') }).toBeGreaterThan(12);
});

test('« Tout, au hasard » puis passage à la fin de la file', async ({ page }) => {
  const log = watch(page);
  await login(page);
  await page.goto('/genres');
  await page.getByRole('button', { name: /Tout, au hasard/ }).click();
  await expect.poll(async () => (await state(page)).count, { message: log.join('\n') }).toBe(20);
  // On saute jusqu'à l'avant-dernière : la file doit se compléter
  for (let i = 0; i < 18; i++) await page.getByRole('button', { name: 'Suivante' }).click();
  await expect.poll(async () => (await state(page)).count, { timeout: 10_000, message: log.join('\n') }).toBeGreaterThan(20);
});

test('le mode infini enchaîne quand la dernière piste se termine', async ({ page }) => {
  const log = watch(page);
  await login(page);
  await page.goto('/albums/3');
  await page.locator('.tracklist .track-row').nth(11).click(); // dernière piste
  await ensureInfinite(page, true);
  await expect.poll(async () => (await state(page)).count, { timeout: 10_000, message: log.join('\n') }).toBeGreaterThan(12);
  // Fin de la piste : on avance la lecture tout près de la fin (une fois la durée connue)
  await expect.poll(() => page.evaluate(() => Number.isFinite(/** @type {any} */ (window).musique.player.deck.audio.duration))).toBe(true);
  await page.evaluate(() => { const a = /** @type {any} */ (window).musique.player.deck.audio; a.currentTime = Math.max(0, a.duration - 1.5); });
  await expect.poll(async () => (await state(page)).index, { timeout: 15_000, message: log.join('\n') }).toBe(12);
  expect((await state(page)).playing).toBe(true);
});
