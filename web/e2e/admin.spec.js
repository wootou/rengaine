// @ts-check
// Écrans d'admin sur le vrai site : demande de compte approuvée, correction d'album annulée,
// onglets. Tout ce que ces essais modifient est défait ici, et les comptes sont supprimés à la fin.
import { expect, test } from '@playwright/test';

/** @param {import('@playwright/test').Page} page */
function watchErrors(page) {
  /** @type {string[]} */
  const errors = [];
  page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));
  page.on('pageerror', (e) => errors.push(e.message));
  return errors;
}

/** @param {import('@playwright/test').Page} page @param {'user' | 'admin'} who */
async function login(page, who) {
  await page.goto('/');
  await page.getByLabel('Adresse e-mail').fill(String(who === 'admin' ? process.env.E2E_ADMIN_EMAIL : process.env.E2E_EMAIL));
  await page.getByLabel('Mot de passe').fill(String(who === 'admin' ? process.env.E2E_ADMIN_PASSWORD : process.env.E2E_PASSWORD));
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('heading', { name: /Albums/ })).toBeVisible();
}

test('un utilisateur ordinaire n’a pas d’admin', async ({ page }) => {
  await login(page, 'user');
  await expect(page.getByRole('link', { name: /^Admin/ })).toHaveCount(0);
  await page.goto('/admin');
  await expect(page.getByText('Cette page est réservée à l’admin.')).toBeVisible();
  await page.goto('/albums/1');
  await expect(page.getByTitle('Corriger l’album')).toHaveCount(0);
});

test('demande de compte, puis approbation par l’admin', async ({ page, browser }) => {
  const email = String(process.env.E2E_REQUEST_EMAIL);
  // Le visiteur fait sa demande (autre navigateur, sans session)
  const visitor = await browser.newContext();
  const v = await visitor.newPage();
  await v.goto('/');
  await v.getByRole('button', { name: /Faire une demande/ }).click();
  await v.getByLabel('Adresse e-mail').fill(email);
  await v.getByLabel('Votre nom').fill('Essai demande');
  await v.getByLabel('Mot de passe').fill('un-mot-de-passe-d-essai-' + Math.random().toString(36).slice(2));
  await v.getByLabel(/Un mot pour l’administrateur/).fill('Bonjour, c’est un essai automatique');
  await v.getByRole('button', { name: 'Demander un compte' }).click();
  await expect(v.getByText(/Demande envoyée/)).toBeVisible();
  await visitor.close();

  const errors = watchErrors(page);
  await login(page, 'admin');
  const adminLink = page.getByRole('link', { name: /^Admin/ });
  await expect(adminLink.locator('.count')).toBeVisible(); // badge des demandes en attente
  await adminLink.click();
  await page.getByLabel('Chercher un compte').fill(email);
  const row = page.getByRole('row', { name: new RegExp(email.replace(/[.]/g, '\\.')) });
  await expect(row).toContainText('En attente');
  await expect(row).toContainText('c’est un essai automatique');
  await row.getByRole('button', { name: 'Approuver' }).click();
  await expect(page.getByText('Essai demande : compte activé.')).toBeVisible();
  await expect(row).toContainText('Actif');

  // Fiche du compte, puis suspension (avec confirmation)
  await row.getByRole('link', { name: 'Essai demande' }).click();
  await expect(page.getByRole('heading', { name: /Essai demande/ })).toBeVisible();
  page.once('dialog', (d) => void d.accept());
  await page.getByRole('button', { name: 'Suspendre' }).click();
  await expect(page.getByRole('heading', { name: /Essai demande/ })).toContainText('Suspendu');
  expect(errors).toEqual([]);
});

test('corriger l’année d’un album, puis annuler la correction', async ({ page }) => {
  const errors = watchErrors(page);
  await login(page, 'admin');
  await page.getByRole('link', { name: /L'essentiel 1977-2017/ }).click();
  const meta = page.locator('.album-meta');
  const before = (await meta.textContent()) ?? '';
  await page.getByTitle('Corriger l’album').click();
  await expect(page.getByText('Corriger l’album')).toBeVisible();
  const year = page.getByLabel('Année');
  const original = await year.inputValue();
  await year.fill('1999');
  await page.getByRole('button', { name: 'Enregistrer l’album' }).click();
  await expect(page.getByText('Album corrigé.')).toBeVisible();
  await expect(page.locator('.corrections li')).toHaveCount(1);
  await expect(page.locator('.corrections li')).toContainText(`Année ${original || '—'} → 1999`);

  // La page publique de l'album montre la correction
  await page.getByRole('link', { name: '← Retour à l’album' }).click();
  await expect(meta).toContainText('1999');

  // Annulation : la valeur des tags revient
  await page.getByTitle('Corriger l’album').click();
  await page.locator('.corrections li').getByRole('button', { name: 'Annuler' }).click();
  await expect(page.getByText(/Correction annulée/)).toBeVisible();
  await expect(page.locator('.corrections li')).toHaveCount(0);
  await expect(page.getByLabel('Année')).toHaveValue(original);
  await page.getByRole('link', { name: '← Retour à l’album' }).click();
  await expect(meta).toHaveText(before);

  // L'onglet Corrections et le journal la montrent
  await page.goto('/admin/corrections');
  await page.getByLabel('Statut').selectOption('reverted');
  await expect(page.getByRole('row', { name: /Année/ }).first()).toContainText('Annulée');
  await page.goto('/admin/journal');
  await expect(page.getByRole('row', { name: /Correction annulée/ }).first()).toContainText('Essai admin');
  expect(errors).toEqual([]);
});

test('fusionner deux albums, puis annuler le lot depuis Propositions', async ({ page }) => {
  const errors = watchErrors(page);
  await login(page, 'admin');
  await page.goto('/albums/5/corriger');
  await expect(page.getByRole('heading', { name: 'custom' })).toBeVisible();
  await page.getByText('Fusionner cet album dans un autre').click();
  await page.getByLabel('Chercher l’album qui reçoit les pistes').fill('Gemini');
  page.once('dialog', (d) => void d.accept());
  await page.locator('.pick-list li', { hasText: 'Gemini' }).getByRole('button', { name: 'Fusionner ici' }).click();
  await expect(page).toHaveURL(/\/albums\/4\/corriger$/);
  await expect(page.locator('.album-meta')).toContainText('22 pistes');

  // Le lot est dans Propositions (statut Appliqué) ; l'annuler remet les deux albums
  await page.goto('/admin/lots');
  await page.getByLabel('Statut du lot').selectOption('applied');
  await page.getByRole('link', { name: 'Fusion de l’album « custom » dans « Gemini »' }).first().click();
  await expect(page.getByRole('row', { name: /Album/ }).first()).toContainText('Active');
  page.once('dialog', (d) => void d.accept());
  await page.getByRole('button', { name: 'Annuler le lot' }).click();
  await expect(page.getByText(/Lot annulé/)).toBeVisible();
  await expect(page.getByRole('heading', { level: 2 })).toContainText('Annulé');
  await page.goto('/albums/5/corriger');
  await expect(page.locator('.album-meta')).toContainText('5 pistes');
  await page.goto('/albums/4/corriger');
  await expect(page.locator('.album-meta')).toContainText('17 pistes');
  expect(errors).toEqual([]);
});

test('chaque onglet s’affiche sans erreur', async ({ page }) => {
  const errors = watchErrors(page);
  await login(page, 'admin');
  await page.getByRole('link', { name: /^Admin/ }).click();
  const tabs = page.getByRole('navigation', { name: 'Administration' });
  for (const [tab, heading] of [['Genres', /^Genres/], ['Tags', /^Tags de genre/], ['Corrections', /^Corrections/], ['Tâches', /^Tâches/],
    ['Propositions', /^Propositions et lots/], ['Doublons', /^MP3 en double/], ['Journal', /^Journal/], ['Serveur', /^Serveur/], ['Comptes', /^Comptes/]]) {
    await tabs.getByRole('link', { name: String(tab) }).click();
    await expect(page.getByRole('heading', { name: heading, level: 2 })).toBeVisible();
  }
  // Tâches : l'historique des passages est là (scans et analyses faits depuis le 18/10)
  await tabs.getByRole('link', { name: 'Tâches' }).click();
  await expect(page.locator('.admin-table').first().locator('tbody tr').first()).toBeVisible();
  // Serveur : le formulaire reprend les réglages, rien à enregistrer tant qu'on ne change rien
  await tabs.getByRole('link', { name: 'Serveur' }).click();
  await expect(page.getByLabel('Nom du serveur')).not.toHaveValue('');
  await expect(page.getByRole('button', { name: 'Enregistrer' })).toBeDisabled();
  // Télécommande : autorisée, service en marche (on ne la coupe pas ici : ce serait pour tout le site de dev)
  await expect(page.getByLabel('Liens de télécommande autorisés')).toBeChecked();
  await expect(page.getByText('Service temps réel :')).toContainText('en marche');
  // Doublons : Tonight a des MP3 gardés (durées différentes du FLAC) ; on regarde sans rien retirer
  await tabs.getByRole('link', { name: 'Doublons' }).click();
  const row = page.locator('.admin-table tbody tr', { hasText: 'Tonight' });
  await row.getByRole('button', { name: 'Détail' }).click();
  await expect(page.locator('.dup-files li.kept').first()).toContainText('durées différentes');
  expect(errors).toEqual([]);
});

test('admin sur téléphone : rien ne déborde @téléphone', async ({ page }) => {
  await login(page, 'admin');
  for (const path of ['/admin', '/admin/taches', '/admin/doublons', '/admin/journal', '/admin/lots', '/albums/1/corriger', '/artistes/1/corriger']) {
    await page.goto(path);
    await expect(page.locator('.admin, .album-edit, .artist-edit')).toBeVisible();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow, path).toBeLessThanOrEqual(0);
  }
  await page.screenshot({ path: 'test-results/admin-telephone.png', fullPage: true });
});
