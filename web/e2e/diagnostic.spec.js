// @ts-check
// Journal de diagnostic du son (réglage de l'appareil) : il note la lecture et les changements de page.
import { expect, test } from '@playwright/test';

test('journal de diagnostic : lecture et pages notées, copiable, puis arrêté @téléphone', async ({ page }) => {
  await page.goto('/');
  await page.getByLabel('Adresse e-mail').fill(String(process.env.E2E_EMAIL));
  await page.getByLabel('Mot de passe').fill(String(process.env.E2E_PASSWORD));
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('heading', { name: /Albums/ })).toBeVisible();

  await page.goto('/reglages');
  await page.getByRole('checkbox', { name: /Journal de diagnostic du son/ }).click();
  await page.evaluate(async () => {
    const m = /** @type {any} */ (window).musique;
    await m.restore();
    m.playTracks((await (await fetch('/api/v2/tracks?limit=3')).json()).items, 0);
  });
  // Une page visitée pendant l'écoute, puis retour aux Réglages par l'historique
  await page.getByRole('link', { name: /Albums/ }).first().click();
  await expect(page.getByRole('heading', { name: /Albums/ })).toBeVisible();
  await page.goBack();
  const log = page.locator(".diag-log");
  await expect(log).toContainText('playing', { timeout: 15_000 });
  await expect(log).toContainText(/page \/\n/);
  await expect(log).toContainText('page /reglages (retour)');
  await page.getByRole('button', { name: 'Effacer' }).click();
  await expect(page.getByText('0 ligne.')).toBeVisible();
  await page.getByRole('checkbox', { name: /Journal de diagnostic du son/ }).click();

  await page.evaluate(async () => {
    /** @type {any} */ (window).musique.reset();
    const me = await (await fetch('/api/v2/me')).json();
    const q = await fetch('/api/v2/me/queue');
    await fetch('/api/v2/me/queue', { method: 'PUT', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': me.csrfToken, 'If-Match': String(q.headers.get('ETag')) },
      body: JSON.stringify({ trackIds: [], currentIndex: null, positionMs: 0, mode: 'normal' }) });
  });
});
