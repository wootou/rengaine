// @ts-check
// Langue du serveur (WEB-13) : quand /server annonce « en », tout le site passe en anglais, dès la
// page de connexion. La réponse est modifiée dans le navigateur : le réglage du serveur ne bouge pas.
import { expect, test } from '@playwright/test';

test('site en anglais quand le serveur l’annonce @téléphone', async ({ page }) => {
  // Infos du serveur lues par la page elle-même (le contexte de requêtes de Playwright ne suit pas les règles de résolution du navigateur)
  await page.goto('/');
  const info = await page.evaluate(async () => (await fetch('/api/v2/server')).json());
  expect(info.language).toBe('fr');
  await page.route('**/api/v2/server', (route) => route.fulfill({ json: { ...info, language: 'en' } }));
  await page.reload();
  await expect(page.locator('html')).toHaveAttribute('lang', 'en');
  await expect(page.getByText('Your music, on repeat.')).toBeVisible();
  await page.getByLabel('Email address').fill(String(process.env.E2E_EMAIL));
  await page.getByLabel('Password').fill(String(process.env.E2E_PASSWORD));
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByRole('heading', { name: /^Albums/ })).toBeVisible();
  for (const label of ['Artists', 'Explore', 'Shuffle', 'Playlists']) {
    await expect(page.locator('.app-nav').getByText(label, { exact: true })).toBeVisible();
  }
  await page.getByTitle('Settings').click();
  await expect(page.getByRole('heading', { name: 'Settings', exact: true })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Connected devices' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Pair a device' })).toBeVisible();
  // Aucun texte français resté en dur sur la page (accents typiques du français)
  const text = await page.locator('main').innerText();
  expect(text).not.toMatch(/\b(Réglages|Appareils|Enregistr|Lecture|piste)/);
});
