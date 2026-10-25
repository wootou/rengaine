// @ts-check
// Albums en liste (bascule propre à l'appareil, gardée dans le navigateur) : ligne d'album, choix
// conservé au rechargement, retour à la grille.
import { expect, test } from '@playwright/test';

test('Albums : bascule grille / liste gardée sur l’appareil @téléphone', async ({ page }) => {
  await page.goto('/');
  await page.getByLabel('Adresse e-mail').fill(String(process.env.E2E_ADMIN_EMAIL));
  await page.getByLabel('Mot de passe').fill(String(process.env.E2E_ADMIN_PASSWORD));
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.locator('.album-grid .album-card').first()).toBeVisible();

  await page.getByRole('button', { name: 'Afficher en liste' }).click();
  const row = page.locator('.album-list .album-row').first();
  await expect(row).toBeVisible();
  await expect(row.locator('.album-row-meta')).toContainText(/piste/);
  await expect(page.locator('.album-grid')).toHaveCount(0);
  expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);

  await page.reload();
  await expect(page.locator('.album-list .album-row').first()).toBeVisible();
  await page.getByRole('button', { name: 'Afficher en grille' }).click();
  await expect(page.locator('.album-grid .album-card').first()).toBeVisible();
  expect(await page.evaluate(() => localStorage.getItem('rengaine.vueAlbums'))).toBeNull();
});
