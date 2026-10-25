// @ts-check
// Lecture continue intelligente (LEC-03) : réglée dans les Réglages, la file se complète par le
// serveur (POST /playback/next) avec des pistes qui s'enchaînent.
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
    const s = /** @type {any} */ (window).musique.store.getState();
    const i = s.queue.items.findIndex((/** @type {any} */ it) => it.key === s.queue.currentKey);
    return { count: s.queue.items.length, index: i, infinite: s.infinite.enabled, ids: s.queue.items.map((/** @type {any} */ it) => it.track.id) };
  });
}

/** Coche ou décoche une case des Réglages et attend l'enregistrement. @param {import('@playwright/test').Page} page @param {RegExp} name @param {boolean} on */
async function setCheck(page, name, on) {
  const box = page.getByRole('checkbox', { name });
  if ((await box.isChecked()) !== on) {
    await box.click();
    await expect(page.getByRole('status')).toHaveText('Enregistré');
  }
  await expect(box).toBeChecked({ checked: on });
}

test('les règles se règlent, puis la file se complète par le serveur @téléphone', async ({ page }) => {
  await login(page);
  await page.goto('/reglages');
  await setCheck(page, /Mode infini/, true);
  await setCheck(page, /s’enchaînent bien/, true);
  await expect(page.getByLabel('Tonalités compatibles', { exact: false })).toBeVisible();
  await page.getByLabel('Écart de tempo avec la piste précédente').selectOption('15');
  await expect(page.getByRole('status')).toHaveText('Enregistré');
  await page.getByLabel(/Entre deux pistes d’un même artiste/).selectOption('2');
  await expect(page.getByRole('status')).toHaveText('Enregistré');
  await page.getByLabel('Énergie d’une piste à l’autre').selectOption('rising');
  await expect(page.getByRole('status')).toHaveText('Enregistré');
  // Un genre exclu, puis remis
  await page.getByText(/Genres à ne jamais proposer/).click();
  const genre = page.locator('.genre-checks input[type=checkbox]').first();
  await genre.check();
  await expect(page.getByRole('status')).toHaveText('Enregistré');
  await expect(page.locator('.excluded-genres summary')).not.toContainText('aucun');
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth), 'rien ne déborde').toBeLessThanOrEqual(0);
  await genre.uncheck();
  await expect(page.locator('.excluded-genres summary')).toContainText('aucun');

  // Réglages relus depuis le serveur
  await page.reload();
  await expect(page.getByLabel('Écart de tempo avec la piste précédente')).toHaveValue('15');

  // Fin d'album : la suite vient de POST /playback/next
  const next = page.waitForResponse((r) => r.url().endsWith('/api/v2/playback/next') && r.request().method() === 'POST');
  await page.goto('/albums/3');
  await page.locator('.tracklist .track-row').nth(11).click(); // dernière piste
  const res = await next;
  expect(res.status()).toBe(200);
  await expect.poll(async () => (await state(page)).count, { timeout: 10_000 }).toBeGreaterThan(12);
  const { ids } = await state(page);
  expect(new Set(ids).size, 'pas de piste en double').toBe(ids.length);

  // On remet le réglage d'origine (le compte d'essai sert aux autres essais)
  await page.goto('/reglages');
  await page.getByLabel('Énergie d’une piste à l’autre').selectOption('any');
  await expect(page.getByRole('status')).toHaveText('Enregistré');
  await setCheck(page, /s’enchaînent bien/, false);
});

test('le lecteur affiche le BPM et la tonalité de la piste en cours', async ({ page }) => {
  await login(page);
  await page.goto('/albums/3');
  await page.locator('.tracklist .track-row').first().click();
  await expect(page.locator('.player-bar .np-dj')).toHaveText(/^\d+ BPM · (Do|Ré|Mi|Fa|Sol|La|Si)[♯♭]? (majeur|mineur) \(\d{1,2}[AB]\)$/);
});
