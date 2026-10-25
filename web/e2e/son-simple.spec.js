// @ts-check
// « Son simplifié sur cet appareil » : sans Web Audio (pour un navigateur dont le son saute),
// retenu par l'appareil, la lecture marche quand même.
import { expect, test } from '@playwright/test';

test('son simplifié : la lecture passe sans Web Audio, réglage retenu par l’appareil @téléphone', async ({ page }) => {
  await page.goto('/');
  await page.getByLabel('Adresse e-mail').fill(String(process.env.E2E_EMAIL));
  await page.getByLabel('Mot de passe').fill(String(process.env.E2E_PASSWORD));
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('heading', { name: /Albums/ })).toBeVisible();

  await page.goto('/reglages');
  const box = page.getByRole('checkbox', { name: /Son simplifié sur cet appareil/ });
  await expect(box).not.toBeChecked();
  await box.click();
  await expect(box).toBeChecked();
  await page.reload();
  await expect(page.getByRole('checkbox', { name: /Son simplifié sur cet appareil/ })).toBeChecked();

  const play = () => page.evaluate(async () => {
    const m = /** @type {any} */ (window).musique;
    await m.restore();
    const items = (await (await fetch('/api/v2/tracks?limit=2')).json()).items;
    m.playTracks(items, 0);
  });
  await play();
  await expect.poll(() => page.evaluate(() => /** @type {any} */ (window).musique.player.positionMs), { timeout: 15_000 }).toBeGreaterThan(500);
  expect(await page.evaluate(() => /** @type {any} */ (window).musique.player.ctx)).toBeNull();

  // Décoché : Web Audio revient après rechargement
  await page.getByRole('checkbox', { name: /Son simplifié sur cet appareil/ }).click();
  await expect(page.getByText('Prend effet au rechargement de la page.')).toBeVisible();
  await page.getByRole('button', { name: 'Recharger' }).click();
  await play();
  await expect.poll(() => page.evaluate(() => /** @type {any} */ (window).musique.player.ctx !== null), { timeout: 15_000 }).toBe(true);

  // Rien laissé dans la file du compte (reprise à la connexion suivante)
  await page.evaluate(async () => {
    /** @type {any} */ (window).musique.reset();
    const me = await (await fetch('/api/v2/me')).json();
    const q = await fetch('/api/v2/me/queue');
    await fetch('/api/v2/me/queue', { method: 'PUT', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': me.csrfToken, 'If-Match': String(q.headers.get('ETag')) },
      body: JSON.stringify({ trackIds: [], currentIndex: null, positionMs: 0, mode: 'normal' }) });
  });
});

test.describe('Firefox Android', () => {
  test.use({ userAgent: 'Mozilla/5.0 (Android 16; Mobile; rv:158.0) Gecko/158.0 Firefox/158.0' });

  test('son simplifié actif d’office, sans Web Audio', async ({ page }) => {
    await page.goto('/');
    await page.getByLabel('Adresse e-mail').fill(String(process.env.E2E_EMAIL));
    await page.getByLabel('Mot de passe').fill(String(process.env.E2E_PASSWORD));
    await page.getByRole('button', { name: 'Se connecter' }).click();
    await expect(page.getByRole('heading', { name: /Albums/ })).toBeVisible();
    await page.goto('/reglages');
    await expect(page.getByRole('checkbox', { name: /Son simplifié sur cet appareil/ })).toBeChecked();
    await expect(page.getByText(/activé d’office sur Firefox Android/)).toBeVisible();
    await page.evaluate(async () => {
      const m = /** @type {any} */ (window).musique;
      await m.restore();
      m.playTracks((await (await fetch('/api/v2/tracks?limit=2')).json()).items, 0);
    });
    await expect.poll(() => page.evaluate(() => /** @type {any} */ (window).musique.player.positionMs), { timeout: 15_000 }).toBeGreaterThan(500);
    expect(await page.evaluate(() => /** @type {any} */ (window).musique.player.ctx)).toBeNull();
    await page.evaluate(async () => {
      /** @type {any} */ (window).musique.reset();
      const me = await (await fetch('/api/v2/me')).json();
      const q = await fetch('/api/v2/me/queue');
      await fetch('/api/v2/me/queue', { method: 'PUT', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': me.csrfToken, 'If-Match': String(q.headers.get('ETag')) },
        body: JSON.stringify({ trackIds: [], currentIndex: null, positionMs: 0, mode: 'normal' }) });
    });
  });
});
