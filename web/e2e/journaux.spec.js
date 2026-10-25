// @ts-check
// Admin > Journal (JRN-07) : journaux techniques du serveur, filtres et détails.
import { expect, test } from '@playwright/test';

/** @param {import('@playwright/test').Page} page */
async function loginAdmin(page) {
  await page.goto('/');
  await page.getByLabel('Adresse e-mail').fill(String(process.env.E2E_ADMIN_EMAIL));
  await page.getByLabel('Mot de passe').fill(String(process.env.E2E_ADMIN_PASSWORD));
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('heading', { name: /Albums/ })).toBeVisible();
}

test('les journaux du serveur se lisent, se filtrent et se suivent par requête @téléphone', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/journal');
  await expect(page.getByRole('heading', { name: /^Journal/ })).toBeVisible(); // les actions (audit), comme avant
  await page.getByRole('link', { name: 'Tâches de fond' }).click();
  await expect(page).toHaveURL(/type=cli/);

  // Toutes les lignes : il y en a forcément (les tâches tournent chaque minute)
  await page.getByLabel('Niveau').selectOption('');
  const rows = page.locator('.log-table tbody tr:not(.details)');
  await expect(rows.first()).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth), 'rien ne déborde').toBeLessThanOrEqual(0);

  // Une ligne de requête : on ne garde que cette requête
  const withRequest = rows.filter({ has: page.getByRole('button', { name: 'Requête' }) }).first();
  await withRequest.getByRole('button', { name: 'Requête' }).click();
  await expect(page.getByRole('button', { name: 'Toutes les requêtes' })).toBeVisible();
  await expect(rows.first()).toBeVisible();
  await page.getByRole('button', { name: 'Toutes les requêtes' }).click();

  // Recherche sans résultat
  await page.getByLabel('Chercher dans le journal').fill('aucune-ligne-ne-contient-ceci');
  await expect(page.getByText(/Aucune ligne|Rien dans les lignes/)).toBeVisible();

  // Sécurité : accessible (sur téléphone, le tableau devient des cartes, sans en-tête de colonnes)
  await page.getByRole('link', { name: 'Sécurité' }).click();
  await expect(page).toHaveURL(/type=securite/);
  await expect(page.getByRole('heading', { name: /^Sécurité/ })).toBeVisible();
});
