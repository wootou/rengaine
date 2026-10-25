// @ts-check
// Page « Aléatoire » et réglage du mode infini, dans le vrai navigateur, sur la vraie bibliothèque.
import { expect, test } from '@playwright/test';

/** @param {import('@playwright/test').Page} page */
async function login(page) {
  await page.goto('/');
  await page.getByLabel('Adresse e-mail').fill(String(process.env.E2E_EMAIL));
  await page.getByLabel('Mot de passe').fill(String(process.env.E2E_PASSWORD));
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('heading', { name: /Albums/ })).toBeVisible();
}

/** @param {import('@playwright/test').Page} page */
function watchErrors(page) {
  /** @type {string[]} */
  const errors = [];
  page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));
  page.on('pageerror', (e) => errors.push(e.message));
  return errors;
}

test('tirage filtré par genre, sans un album, retrouvé après rechargement', async ({ page }) => {
  const errors = watchErrors(page);
  await login(page);
  await page.getByRole('link', { name: 'Aléatoire' }).click();
  await expect(page.locator('.tracklist li')).toHaveCount(100); // 100 par défaut, bien plus de pistes en tout

  // Seulement « Pop », sans ses autres albums : il reste les 51 pistes de Cabrel
  await page.getByRole('button', { name: /Sélection/ }).click();
  await page.getByRole('button', { name: 'Tout décocher' }).click();
  await page.getByLabel('Pop', { exact: true }).check();
  await page.getByTitle('Albums de Pop').click();
  const others = page.locator('.genre-group-albums label').filter({ hasNotText: /L'essentiel 1977-2017/ });
  await expect(page.locator('.genre-group-albums label').filter({ hasText: /L'essentiel 1977-2017/ })).toBeVisible();
  await expect(page.locator('.genre-group-albums label').filter({ hasText: /100 Greatest Hits/ })).toBeVisible();
  for (const box of await others.locator('input').all()) await box.uncheck();
  await page.getByLabel('Nombre de pistes').selectOption('200');
  await page.getByRole('button', { name: /Générer une nouvelle sélection/ }).click();
  await expect(page.locator('.tracklist li')).toHaveCount(51);
  await expect(page.locator('.tracklist .track-sub').filter({ hasNotText: /Cabrel/ })).toHaveCount(0);

  await page.getByRole('button', { name: 'Tout écouter' }).click();
  await expect.poll(() => page.evaluate(() => /** @type {any} */ (window).musique.store.getState().queue.items.length)).toBeGreaterThanOrEqual(51);

  // Le filtre est dans les réglages du compte : il revient après rechargement (et dans l'appli)
  await page.reload();
  await expect(page.locator('.tracklist li')).toHaveCount(51);
  await expect(page.getByRole('button', { name: /Sélection/ })).toContainText(/Pop · \d+ albums? de côté/);
  expect(errors).toEqual([]);
});

test('mode infini : réglage du compte, modifiable dans Réglages et sur le lecteur', async ({ page }) => {
  await login(page);
  const infinite = () => page.evaluate(() => /** @type {any} */ (window).musique.store.getState().infinite.enabled);
  await expect.poll(infinite).toBe(true); // activé par défaut
  await page.goto('/reglages');
  const box = page.getByLabel(/Mode infini : quand la file se termine/);
  await box.uncheck();
  await expect(page.getByRole('status')).toHaveText('Enregistré');
  await expect.poll(infinite).toBe(false);
  await page.reload();
  await expect(page.getByLabel(/Mode infini : quand la file se termine/)).not.toBeChecked();
  // Bouton du lecteur : enregistré aussi
  await page.getByRole('button', { name: 'Activer le mode infini' }).click();
  await page.reload();
  await expect(page.getByLabel(/Mode infini : quand la file se termine/)).toBeChecked();
});

test('page Aléatoire sur téléphone : rien ne déborde @téléphone', async ({ page }) => {
  await login(page);
  await page.goto('/aleatoire');
  await page.getByRole('button', { name: /Sélection/ }).click();
  await page.getByTitle('Albums de Pop').click();
  await expect(page.locator('.genre-group-albums li').first()).toBeVisible();
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(overflow).toBeLessThanOrEqual(0);
});
