// @ts-check
// Curseur de volume du lecteur : il multiplie le gain de normalisation (Web Audio) et chaque
// appareil garde le sien d'une visite à l'autre.
import { expect, test } from '@playwright/test';

/** @param {import('@playwright/test').Page} page */
async function login(page) {
  await page.goto('/');
  await page.getByLabel('Adresse e-mail').fill(String(process.env.E2E_EMAIL));
  await page.getByLabel('Mot de passe').fill(String(process.env.E2E_PASSWORD));
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('heading', { name: /Albums/ })).toBeVisible();
}

/** Volume demandé et gain réellement appliqué par le dernier nœud Web Audio. @param {import('@playwright/test').Page} page */
function volume(page) {
  return page.evaluate(() => {
    const c = /** @type {any} */ (window).musique;
    const s = c.store.getState();
    return { volume: s.volume, muted: s.muted, master: c.player.master ? Math.round(c.player.master.gain.value * 100) / 100 : null };
  });
}

test('le curseur règle le volume, le bouton coupe le son, le réglage survit au rechargement', async ({ page }) => {
  await login(page);
  await page.evaluate(() => localStorage.removeItem('musique.volume'));
  await page.goto('/albums/3');
  await page.locator('.tracklist .track-row').first().click();
  await expect.poll(async () => (await volume(page)).master).toBe(1);

  const slider = page.getByRole('slider', { name: 'Volume' });
  await slider.fill('40');
  await expect.poll(() => volume(page)).toEqual({ volume: 0.4, muted: false, master: 0.4 });

  await page.getByRole('button', { name: 'Couper le son' }).click();
  await expect.poll(() => volume(page)).toEqual({ volume: 0.4, muted: true, master: 0 });
  await expect(slider).toHaveValue('0');
  await page.getByRole('button', { name: 'Remettre le son' }).click();
  await expect.poll(async () => (await volume(page)).master).toBe(0.4);

  await page.reload();
  await expect(page.getByRole('slider', { name: 'Volume' })).toHaveValue('40');
  await page.evaluate(() => localStorage.removeItem('musique.volume'));
});
