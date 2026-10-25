// @ts-check
// Fichiers abîmés : Admin > Doublons liste les pistes dont le fichier servi a des trames illisibles,
// avec l'endroit de l'accroc. Sur le dev : 6 FLAC de « Franz Ferdinand - Tonight ».
import { expect, test } from '@playwright/test';

test('Admin > Doublons : fichiers abîmés, avec l’endroit des accrocs @téléphone', async ({ page }) => {
  await page.goto('/');
  await page.getByLabel('Adresse e-mail').fill(String(process.env.E2E_ADMIN_EMAIL));
  await page.getByLabel('Mot de passe').fill(String(process.env.E2E_ADMIN_PASSWORD));
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('heading', { name: /Albums/ })).toBeVisible();

  await page.goto('/admin/doublons');
  const section = page.locator('.damaged-tracks');
  await expect(section.getByRole('heading', { name: /Fichiers abîmés/ })).toContainText('6 pistes');
  await expect(section.locator('li')).toHaveCount(6);
  const ulysses = section.locator('li').filter({ hasText: 'Ulysses' });
  await expect(ulysses).toContainText('2 trames à 0:35 et 2:08 : flac : invalid subframe padding');
  await expect(ulysses).toContainText('Franz Ferdinand - Tonight/flac/01 - Ulysses.flac');
  await expect(section).toContainText(/\d+ pistes? vérifiées?/);
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth), 'rien ne déborde').toBeLessThanOrEqual(0);
});
