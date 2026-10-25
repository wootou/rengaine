// @ts-check
// Historique d'écoute (2.26.0) : liste par jour, effacer une écoute, tout effacer. Les réponses de
// l'API sont simulées dans le navigateur : rien n'est effacé pour de vrai.
import { expect, test } from '@playwright/test';

test('Historique : écoutes par jour, en effacer une, puis tout @téléphone', async ({ page }) => {
  await page.goto('/');
  await page.getByLabel('Adresse e-mail').fill(String(process.env.E2E_ADMIN_EMAIL));
  await page.getByLabel('Mot de passe').fill(String(process.env.E2E_ADMIN_PASSWORD));
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('heading', { name: /Albums/ })).toBeVisible();

  // Une vraie piste du catalogue, pour que la ligne ressemble à une vraie écoute
  const track = await page.evaluate(async () => (await (await fetch('/api/v2/tracks?limit=1', { credentials: 'include' })).json()).items[0]);
  const now = new Date();
  const items = [
    { id: 11, startedAt: now.toISOString(), playedMs: 200000, completed: true, skipped: false, context: 'queue', device: 'Firefox sur Linux', track },
    { id: 10, startedAt: new Date(now.getTime() - 86_400_000).toISOString(), playedMs: 42000, completed: false, skipped: true, context: 'queue', device: null, track },
  ];
  /** @type {string[]} */
  const deletes = [];
  await page.route('**/api/v2/me/history**', async (route) => {
    const req = route.request();
    if (req.method() === 'DELETE') {
      deletes.push(new URL(req.url()).pathname);
      return req.url().endsWith('/me/history') ? route.fulfill({ json: { deleted: 1 } }) : route.fulfill({ status: 204 });
    }
    return route.fulfill({ json: { items, total: 2, limit: 100, offset: 0 } });
  });

  await page.goto('/favoris');
  await page.getByRole('link', { name: 'Historique d’écoute' }).click();
  await expect(page.getByRole('heading', { name: 'Aujourd’hui' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Hier' })).toBeVisible();
  await expect(page.getByText('passée à 0:42')).toBeVisible();
  await expect(page.locator('.meta')).toHaveText('2 écoutes');
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth), 'rien ne déborde').toBeLessThanOrEqual(0);

  await page.getByRole('button', { name: /Effacer l’écoute/ }).first().click();
  await expect(page.locator('.meta')).toHaveText('1 écoute');
  page.once('dialog', (d) => void d.accept());
  await page.getByRole('button', { name: 'Tout effacer' }).click();
  await expect(page.getByText('Historique effacé.')).toBeVisible();
  expect(deletes).toEqual(['/api/v2/me/history/11', '/api/v2/me/history']);
});
