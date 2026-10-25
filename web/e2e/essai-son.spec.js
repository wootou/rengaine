// @ts-check
// Page d'essai du son (diagnostic) : liste les pistes et joue le fichier d'origine ou une conversion.
import { expect, test } from '@playwright/test';

test('page d’essai du son : pistes proposées, lecture du fichier d’origine et d’une conversion', async ({ page }) => {
  await page.goto('/');
  await page.getByLabel('Adresse e-mail').fill(String(process.env.E2E_EMAIL));
  await page.getByLabel('Mot de passe').fill(String(process.env.E2E_PASSWORD));
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('heading', { name: /Albums/ })).toBeVisible();

  await page.goto('/essai-son.html');
  await expect(page.getByRole('log')).toContainText('pistes ; navigateur');
  expect(await page.locator('#track option').count()).toBeGreaterThan(50);
  await page.locator('#track').selectOption({ index: 0 });
  await page.getByRole('button', { name: 'Lecture' }).click();
  await expect(page.getByRole('log')).toContainText('playing', { timeout: 15_000 });
  await page.getByLabel('Format').selectOption({ label: 'Converti en Opus 256' });
  await page.getByRole('button', { name: 'Lecture' }).click();
  await expect(page.getByRole('log')).toContainText('Converti en Opus 256');
  await expect.poll(() => page.locator('#log').textContent().then((t) => (t ?? '').split('Converti en Opus 256')[1] ?? ''), { timeout: 15_000 }).toContain('playing');
  await page.getByRole('button', { name: 'Arrêt' }).click();
});
