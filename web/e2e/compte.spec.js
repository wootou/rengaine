// @ts-check
// Exporter ses données (CPT-10), puis supprimer son compte (CPT-09) : 15 s pour annuler, puis tout part.
import { expect, test } from '@playwright/test';

test('exporter ses données, puis supprimer son compte : annuler pendant le délai, puis pour de bon', async ({ page }) => {
  test.setTimeout(90_000);
  const email = String(process.env.E2E_DELETE_EMAIL);
  const password = String(process.env.E2E_DELETE_PASSWORD);
  await page.goto('/');
  await page.getByLabel('Adresse e-mail').fill(email);
  await page.getByLabel('Mot de passe').fill(password);
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('heading', { name: /Albums/ })).toBeVisible();
  await page.getByTitle('Réglages').click();

  // Export des données (CPT-10) : un fichier JSON avec le compte
  const [download] = await Promise.all([
    page.waitForEvent('download'),
    page.getByRole('button', { name: 'Télécharger mes données' }).click(),
  ]);
  expect(download.suggestedFilename()).toMatch(/^rengaine-export-\d{8}\.json$/);
  const exported = JSON.parse(await (await download.createReadStream()).toArray().then((c) => Buffer.concat(c).toString('utf8')));
  expect([exported.format, exported.account.email]).toEqual(['rengaine-export', email]);
  await expect(page.getByRole('status').filter({ hasText: 'Fichier téléchargé.' })).toBeVisible();

  // Mauvais mot de passe : refusé après le délai, le compte reste (délai écourté pour l'essai)
  await page.getByRole('button', { name: 'Supprimer mon compte…' }).click();
  await page.getByLabel(/Votre mot de passe/).fill('pas le bon mot de passe');
  await page.clock.install();
  await page.getByRole('button', { name: 'Supprimer définitivement' }).click();
  await expect(page.locator('.delete-countdown')).toContainText('15 s');
  await expect(page.getByRole('button', { name: 'Annuler' })).toBeFocused();

  // Annuler à 1 s de la fin : rien n'est supprimé
  await page.clock.runFor(14_000);
  await expect(page.locator('.delete-countdown')).toContainText('1 s');
  await page.getByRole('button', { name: 'Annuler' }).click();
  await page.clock.runFor(5_000);
  await expect(page.getByRole('button', { name: 'Supprimer mon compte…' })).toBeVisible();

  await page.getByRole('button', { name: 'Supprimer mon compte…' }).click();
  await page.getByLabel(/Votre mot de passe/).fill('pas le bon mot de passe');
  await page.getByRole('button', { name: 'Supprimer définitivement' }).click();
  await page.clock.runFor(16_000);
  await expect(page.locator('.delete-account .form-error')).toContainText('Mot de passe incorrect');

  // Le bon : au bout de 15 s, retour à la connexion
  await page.getByLabel(/Votre mot de passe/).fill(password);
  await page.getByRole('button', { name: 'Supprimer définitivement' }).click();
  await page.clock.runFor(16_000);
  await expect(page.locator('.login-notice')).toHaveText('Votre compte a été supprimé, avec toutes ses données.');
  await page.getByLabel('Adresse e-mail').fill(email);
  await page.getByLabel('Mot de passe').fill(password);
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.locator('.login-card .form-error')).toBeVisible();
});
