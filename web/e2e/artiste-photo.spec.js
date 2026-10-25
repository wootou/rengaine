// @ts-check
// Photo d'artiste (CAT-04) : envoyée depuis « Corriger l'artiste », visible sur sa page et dans la
// liste, puis retirée. Les artistes du site n'ont pas de photo : l'essai remet tout en l'état.
import { expect, test } from '@playwright/test';

/** @param {import('@playwright/test').Page} page */
async function loginAdmin(page) {
  await page.goto('/');
  await page.getByLabel('Adresse e-mail').fill(String(process.env.E2E_ADMIN_EMAIL));
  await page.getByLabel('Mot de passe').fill(String(process.env.E2E_ADMIN_PASSWORD));
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('heading', { name: /Albums/ })).toBeVisible();
}

/** @type {number | null} */
let touched = null;

test.afterEach(async ({ page }) => {
  if (touched === null) return;
  const id = touched;
  touched = null;
  await page.evaluate(async (artistId) => {
    const me = await (await fetch('/api/v2/me')).json();
    await fetch(`/api/v2/admin/artists/${artistId}/image`, { method: 'DELETE', headers: { 'X-CSRF-Token': me.csrfToken } });
  }, id);
});

test('une photo d’artiste s’envoie, s’affiche puis se retire @téléphone', async ({ page }) => {
  await loginAdmin(page);
  // Un artiste sans photo, pris dans la liste
  const artist = await page.evaluate(async () => {
    const list = (await (await fetch('/api/v2/artists?limit=100')).json()).items;
    return list.find((/** @type {any} */ a) => a.imageUrl === null && a.albumCount > 0);
  });
  expect(artist, 'un artiste sans photo').toBeTruthy();
  touched = artist.id;

  await page.goto(`/artistes/${artist.id}/corriger`);
  await expect(page.getByRole('heading', { name: 'Photo' })).toBeVisible();
  const dataUrl = await page.evaluate(() => {
    const c = document.createElement('canvas');
    c.width = 1600;
    c.height = 1600;
    const ctx = /** @type {CanvasRenderingContext2D} */ (c.getContext('2d'));
    ctx.fillStyle = '#5b3b8c';
    ctx.fillRect(0, 0, 1600, 1600);
    ctx.fillStyle = '#f4c95d';
    ctx.beginPath();
    ctx.arc(800, 640, 320, 0, Math.PI * 2);
    ctx.fill();
    return c.toDataURL('image/png');
  });
  const put = page.waitForResponse((r) => r.url().endsWith(`/admin/artists/${artist.id}/image`) && r.request().method() === 'PUT');
  await page.locator('.artist-photo-edit input[type=file]').setInputFiles({ name: 'photo.png', mimeType: 'image/png', buffer: Buffer.from(dataUrl.split(',')[1], 'base64') });
  expect((await put).status()).toBe(200);
  await expect(page.getByText('Photo enregistrée.')).toBeVisible();
  await expect(page.getByRole('img', { name: `Photo de ${artist.name}` })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth), 'rien ne déborde').toBeLessThanOrEqual(0);

  // Page de l'artiste : la photo, ronde, à côté du nom
  await page.goto(`/artistes/${artist.id}`);
  const photo = page.getByRole('img', { name: `Photo de ${artist.name}` });
  await expect(photo).toBeVisible();
  await expect.poll(() => photo.evaluate((/** @type {HTMLImageElement} */ img) => img.complete && img.naturalWidth > 0)).toBe(true);

  // Liste : une vignette devant le nom
  await page.goto('/artistes');
  await page.getByLabel('Filtrer les artistes').fill(artist.name);
  await expect(page.locator('.artist-list li').filter({ hasText: artist.name }).locator('img.artist-avatar')).toBeVisible();

  // Retirée
  await page.goto(`/artistes/${artist.id}/corriger`);
  await page.getByRole('button', { name: 'Retirer' }).click();
  await expect(page.getByText('Photo retirée.')).toBeVisible();
  await expect(page.locator('.artist-photo-edit .file-button')).toContainText('Choisir une photo');
  touched = null;
});
