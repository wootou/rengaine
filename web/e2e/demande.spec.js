// @ts-check
// Lien direct vers la demande de compte : « /?demande » ouvre le formulaire, puis l'adresse est nettoyée.
import { expect, test } from '@playwright/test';

test('« /?demande » ouvre le formulaire de demande de compte @téléphone', async ({ page }) => {
  await page.goto('/?demande');
  await expect(page.getByRole('button', { name: 'Demander un compte' })).toBeVisible();
  await expect(page.getByLabel(/Votre nom|Nom/).first()).toBeVisible();
  await expect(page).toHaveURL(/\/$/);
  await page.getByRole('button', { name: 'J’ai déjà un compte' }).click();
  await expect(page.getByRole('button', { name: 'Se connecter' })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth), 'rien ne déborde').toBeLessThanOrEqual(0);
});
