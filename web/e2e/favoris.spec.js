// @ts-check
// Mes favoris (WEB-08) : un album et une piste marqués d'un cœur se retrouvent sur la page, qu'on
// écoute d'un clic. Le compte d'essai est neuf : il n'a aucun favori au départ.
import { expect, test } from '@playwright/test';

/** @param {import('@playwright/test').Page} page */
async function login(page) {
  await page.goto('/');
  await page.getByLabel('Adresse e-mail').fill(String(process.env.E2E_EMAIL));
  await page.getByLabel('Mot de passe').fill(String(process.env.E2E_PASSWORD));
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('heading', { name: /Albums/ })).toBeVisible();
}

test('cœur sur un album et une piste, retrouvés dans Mes favoris @téléphone', async ({ page }) => {
  await login(page);
  await page.goto('/favoris');
  await expect(page.getByRole('heading', { name: 'Mes favoris' })).toBeVisible();
  await expect(page.getByText('Aucune piste en favori')).toBeVisible();

  await page.goto('/albums/3');
  const albumTitle = await page.locator('.album-info h1').innerText();
  await page.locator('.album-tools').getByRole('button', { name: 'Ajouter aux favoris' }).click();
  await expect(page.locator('.album-tools').getByRole('button', { name: 'Retirer des favoris' })).toBeVisible();
  const row = page.locator('.tracklist .track-row').nth(1);
  const trackTitle = (await row.locator('.track-title').innerText()).trim();
  const heart = row.getByRole('button', { name: 'Ajouter aux favoris' });
  if (!(await heart.isVisible())) await row.getByRole('button', { name: /^Actions pour/ }).click(); // téléphone : menu ⋮
  await row.getByRole('button', { name: 'Ajouter aux favoris' }).click();
  await expect(row.getByRole('button', { name: 'Retirer des favoris' })).toBeAttached();

  // Navigation : Favoris est dans le menu ; sur téléphone, en tête de la page Playlists
  const nav = page.getByRole('navigation', { name: 'Navigation' });
  if (await nav.getByRole('link', { name: 'Favoris' }).isVisible()) await nav.getByRole('link', { name: 'Favoris' }).click();
  else {
    await nav.getByRole('link', { name: 'Playlists' }).click();
    await page.getByRole('link', { name: /^Mes favoris/ }).click();
  }
  await expect(page.locator('.favorites .tracklist')).toContainText(trackTitle);
  await page.getByRole('navigation', { name: 'Type de favoris' }).getByRole('link', { name: /Albums/ }).click();
  await expect(page).toHaveURL(/\/favoris\?type=albums$/);
  await expect(page.locator('.favorites')).toContainText(albumTitle);
  await page.getByRole('navigation', { name: 'Type de favoris' }).getByRole('link', { name: /Artistes/ }).click();
  await expect(page.getByText('Aucun artiste en favori')).toBeVisible();

  // Écouter les pistes favorites
  await page.getByRole('navigation', { name: 'Type de favoris' }).getByRole('link', { name: /Pistes/ }).click();
  await page.getByRole('button', { name: 'Tout écouter' }).click();
  // La file commence par l'unique piste favorite (le mode infini peut la compléter ensuite)
  await expect.poll(() => page.evaluate(() => /** @type {any} */ (window).musique.store.getState().queue.items[0]?.track.title)).toBe(trackTitle);
  await expect(page.locator('.player-bar')).toContainText(trackTitle);
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(overflow).toBeLessThanOrEqual(0);

  // On retire les cœurs : la page les garde affichés jusqu'au rechargement, puis plus rien
  const fav = page.locator('.favorites .track-row').first();
  if (!(await fav.getByRole('button', { name: 'Retirer des favoris' }).isVisible())) await fav.getByRole('button', { name: /^Actions pour/ }).click();
  await fav.getByRole('button', { name: 'Retirer des favoris' }).click();
  await expect(fav.getByRole('button', { name: 'Ajouter aux favoris' })).toBeAttached();
  await page.goto('/albums/3');
  await page.locator('.album-tools').getByRole('button', { name: 'Retirer des favoris' }).click();
  await expect(page.locator('.album-tools').getByRole('button', { name: 'Ajouter aux favoris' })).toBeVisible();
  await page.goto('/favoris');
  await expect(page.getByText('Aucune piste en favori')).toBeVisible();
});
