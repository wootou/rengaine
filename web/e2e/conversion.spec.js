// @ts-check
// Conversion à la volée (LEC-04) : avec une qualité réduite, un FLAC arrive converti (Opus ou MP3),
// se lit, et l'avance au milieu de la piste redemande le flux à la bonne position.
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
function deck(page) {
  return page.evaluate(() => {
    const c = /** @type {any} */ (window).musique;
    const a = c.player.deck.audio;
    return { src: String(a.src), time: a.currentTime, paused: a.paused, error: a.error?.code ?? null, positionMs: c.store.getState().positionMs };
  });
}

test('qualité moyenne : le FLAC arrive converti, se lit, et on peut avancer', async ({ page }) => {
  await login(page);
  await page.goto('/reglages');
  const wifi = page.getByLabel('En wifi ou filaire');
  await wifi.selectOption('medium');
  await expect(page.getByRole('status')).toHaveText('Enregistré');

  try {
    await page.goto('/albums/3'); // Tonight : FLAC
    await page.locator('.tracklist .track-row').first().click();
    await expect.poll(async () => (await deck(page)).src).toMatch(/format=(opus|mp3)&bitrate=(160|192)$/);
    await expect.poll(async () => (await deck(page)).time, { timeout: 15000 }).toBeGreaterThan(0.2);
    expect((await deck(page)).error).toBeNull();

    // Tout de suite après le départ, la fin de la piste (193 s) n'est pas encore reçue : flux redemandé
    await page.evaluate(() => /** @type {any} */ (window).musique.seek(170000));
    await expect.poll(async () => (await deck(page)).src).toMatch(/startMs=170000$/);
    await expect.poll(async () => (await deck(page)).positionMs, { timeout: 15000 }).toBeGreaterThan(171000);

    // En arrière, avant le début de ce flux : redemandé aussi
    await page.evaluate(() => /** @type {any} */ (window).musique.seek(120000));
    await expect.poll(async () => (await deck(page)).src).toMatch(/startMs=120000$/);
    await expect.poll(async () => (await deck(page)).positionMs, { timeout: 15000 }).toBeGreaterThan(121000);
    const d = await deck(page);
    expect(d.positionMs).toBeLessThan(140000);
    expect(d.paused).toBe(false);
    await expect(page.getByRole('slider', { name: 'Position' })).toHaveAttribute('aria-valuenow', /^1[23]\d$/);
  } finally {
    await page.goto('/reglages');
    await page.getByLabel('En wifi ou filaire').selectOption('original');
    await expect(page.getByRole('status')).toHaveText('Enregistré');
  }
});
