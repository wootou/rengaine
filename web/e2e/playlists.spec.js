// @ts-check
// Playlists (FUT-01) : créer, remplir depuis un album, une piste et la file, réordonner, retirer,
// partager ; un autre compte la voit, l'écoute, ne peut pas la modifier, et la copie.
import { expect, test } from '@playwright/test';

/** @param {import('@playwright/test').Page} page @param {'user' | 'admin'} [who] */
async function login(page, who = 'user') {
  await page.goto('/');
  await page.getByLabel('Adresse e-mail').fill(String(who === 'admin' ? process.env.E2E_ADMIN_EMAIL : process.env.E2E_EMAIL));
  await page.getByLabel('Mot de passe').fill(String(who === 'admin' ? process.env.E2E_ADMIN_PASSWORD : process.env.E2E_PASSWORD));
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('heading', { name: /Albums/ })).toBeVisible();
}

/** Bouton d'action d'une piste (téléphone : derrière ⋮). @param {import('@playwright/test').Locator} row @param {string} name */
async function trackAction(row, name) {
  const btn = row.getByRole('button', { name, exact: true });
  if (!(await btn.isVisible())) await row.getByRole('button', { name: /^Actions pour/ }).click();
  await btn.click();
}

/** @param {import('@playwright/test').Page} page */
const titles = (page) => page.locator('.tracklist .track-title').allInnerTexts();

test('créer, remplir, réordonner, partager, copier @téléphone', async ({ page, browser }, info) => {
  const name = `Route ${info.project.name}`;
  await login(page);
  await page.getByRole('navigation', { name: 'Navigation' }).getByRole('link', { name: 'Playlists' }).click();
  await expect(page.getByRole('heading', { name: 'Playlists', level: 1 })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Mes favoris' })).toBeVisible();

  // Nouvelle playlist, vide
  await page.getByRole('button', { name: 'Nouvelle playlist' }).click();
  await page.getByLabel('Nom de la playlist').fill(name);
  await page.getByRole('button', { name: 'Créer', exact: true }).click();
  await expect(page.getByRole('heading', { name, level: 1 })).toBeVisible();
  await expect(page.getByText('Playlist vide')).toBeVisible();
  const url = page.url();

  // Tout un album
  await page.goto('/albums/3');
  await expect(page.locator('.tracklist .track-row')).toHaveCount(12);
  const albumTitles = await titles(page);
  await page.locator('.album-tools').getByRole('button', { name: 'Ajouter à une playlist' }).click();
  const dialog = page.getByRole('dialog', { name: 'Ajouter à une playlist' });
  await dialog.getByRole('button', { name: new RegExp(`^${name}`) }).click();
  await expect(dialog.getByRole('status')).toContainText(`${albumTitles.length} pistes ajoutées à « ${name} »`);
  await expect(dialog).toBeHidden();

  // Une piste, dans une nouvelle playlist créée depuis la fenêtre
  await trackAction(page.locator('.tracklist .track-row').nth(1), 'Ajouter à une playlist');
  await dialog.getByLabel('Nom de la nouvelle playlist').fill(`${name} bis`);
  await dialog.getByRole('button', { name: 'Créer' }).click();
  await expect(dialog.getByRole('status')).toContainText(`Ajoutée à « ${name} bis »`);
  await expect(dialog).toBeHidden();

  // Réordonner et retirer
  await page.goto(url);
  await expect(page.locator('.tracklist .track-row')).toHaveCount(albumTitles.length);
  await trackAction(page.locator('.tracklist .track-row').nth(1), 'Monter');
  await expect.poll(() => titles(page)).toEqual([albumTitles[1], albumTitles[0], ...albumTitles.slice(2)]);
  await trackAction(page.locator('.tracklist .track-row').first(), 'Retirer de la playlist');
  await expect.poll(() => titles(page)).toEqual([albumTitles[0], ...albumTitles.slice(2)]);
  await page.reload();
  await expect.poll(() => titles(page)).toEqual([albumTitles[0], ...albumTitles.slice(2)]);

  // Écouter
  await page.getByRole('button', { name: 'Écouter', exact: true }).click();
  await expect(page.locator('.player-bar')).toContainText(albumTitles[0]);

  // Partager
  await page.getByLabel('Partager avec tous les comptes').check();
  await expect(page.getByRole('status').filter({ hasText: 'Partagée' })).toBeVisible();

  // Un autre compte : la voit, ne la modifie pas, la copie
  const other = await browser.newPage();
  await login(other, 'admin');
  await other.goto('/playlists');
  await expect(other.getByRole('heading', { name: 'Partagées par les autres' })).toBeVisible();
  await other.locator('.playlist-card').filter({ has: other.locator('.album-title', { hasText: new RegExp(`^${name}$`) }) }).click();
  await expect(other.getByText('Playlist de Essai navigateur')).toBeVisible();
  await expect(other.getByRole('button', { name: 'Modifier la playlist' })).toHaveCount(0);
  await expect(other.getByLabel('Partager avec tous les comptes')).toHaveCount(0);
  await other.getByRole('button', { name: 'Copier dans mes playlists' }).click();
  await expect(other.getByRole('heading', { name: `${name} (copie)`, level: 1 })).toBeVisible();
  await expect(other.getByText('Playlist privée')).toBeVisible();
  expect(await titles(other)).toEqual([albumTitles[0], ...albumTitles.slice(2)]);
  // Copie supprimée
  await other.getByRole('button', { name: 'Modifier la playlist' }).click();
  other.once('dialog', (d) => d.accept());
  await other.getByRole('button', { name: 'Supprimer la playlist' }).click();
  await expect(other).toHaveURL(/\/playlists$/);
  await other.close();

  // La file d'attente enregistrée en playlist
  await page.getByTitle("File d'attente").click();
  await page.getByRole('button', { name: 'Enregistrer en playlist' }).click();
  await dialog.getByLabel('Nom de la nouvelle playlist').fill(`${name} file`);
  await dialog.getByRole('button', { name: 'Créer' }).click();
  await expect(dialog.getByRole('status')).toContainText(`à « ${name} file »`);

  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(overflow).toBeLessThanOrEqual(0);
});
