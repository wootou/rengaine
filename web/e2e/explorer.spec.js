// @ts-check
// Explorer (CAT-08) : ambiances, décennies, et une collection créée puis supprimée par l'admin.
import { expect, test } from '@playwright/test';

/** @param {import('@playwright/test').Page} page @param {'user' | 'admin'} who */
async function login(page, who) {
  await page.goto('/');
  await page.getByLabel('Adresse e-mail').fill(String(who === 'admin' ? process.env.E2E_ADMIN_EMAIL : process.env.E2E_EMAIL));
  await page.getByLabel('Mot de passe').fill(String(who === 'admin' ? process.env.E2E_ADMIN_PASSWORD : process.env.E2E_PASSWORD));
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('heading', { name: /Albums/ })).toBeVisible();
}

/** @param {import('@playwright/test').Page} page */
function queue(page) {
  return page.evaluate(() => {
    const s = /** @type {any} */ (window).musique.store.getState();
    return { count: s.queue.items.length, filter: s.infinite.filter, enabled: s.infinite.enabled };
  });
}

const NAME = `Essai e2e ${Date.now()}`;

test.afterEach(async ({ page }) => {
  // La collection d'essai disparaît, même si l'essai échoue
  await page.evaluate(async (name) => {
    const me = await (await fetch('/api/v2/me')).json();
    if (me.user?.role !== 'admin') return;
    const list = (await (await fetch('/api/v2/collections?kind=collection')).json()).items;
    for (const c of list.filter((/** @type {any} */ x) => x.name === name)) {
      await fetch(`/api/v2/admin/collections/${c.id}`, { method: 'DELETE', headers: { 'X-CSRF-Token': me.csrfToken } });
    }
  }, NAME).catch(() => {});
});

test('ambiances et décennies s’écoutent en mode infini @téléphone', async ({ page }) => {
  await login(page, 'user');
  await page.goto('/genres');
  await expect(page.getByRole('heading', { name: 'Explorer' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Ambiances' })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth), 'rien ne déborde').toBeLessThanOrEqual(0);

  await page.getByTitle('Écouter « Calme » en continu').click();
  await expect.poll(async () => (await queue(page)).count).toBeGreaterThan(0);
  expect((await queue(page)).filter).toEqual({ mood: 'calme' });

  // Décennie : la page Albums filtrée
  await page.goto('/genres');
  const decade = page.locator('.decade-list .genre-name').first();
  const label = (await decade.textContent()) ?? '';
  await decade.click();
  await expect(page).toHaveURL(/decennie=\d{4}/);
  await expect(page.getByLabel('Décennie')).toHaveValue(/\d{4}/);
  expect(label).toMatch(/^Années \d{2,4}$/);
});

test('une collection se crée, se remplit et s’écoute, puis se supprime', async ({ page }) => {
  await login(page, 'admin');
  await page.goto('/admin/collections');
  await page.getByLabel('Nom de la nouvelle collection').fill(NAME);
  await page.getByRole('button', { name: 'Créer' }).click();
  await expect(page).toHaveURL(/\/admin\/collections\?id=\d+/);

  for (const q of ['Tonight', 'Gemini']) {
    await page.getByPlaceholder('Titre ou artiste…').fill(q);
    const add = page.locator('.pick-list li').filter({ hasText: q }).getByRole('button', { name: 'Ajouter' }).first();
    await add.click();
    await expect(page.getByText(/ajouté\.$/)).toBeVisible();
  }
  await expect(page.locator('.collection-albums li')).toHaveCount(2);
  // Ordre : la deuxième remonte en tête
  await page.locator('.collection-albums li').nth(1).getByRole('button', { name: 'Monter' }).click();
  await expect(page.locator('.collection-albums li').first()).toContainText('Gemini');

  // Visible dans Explorer, avec sa page
  await page.goto('/genres');
  await page.getByRole('link', { name: new RegExp(NAME) }).click();
  await expect(page.getByRole('heading', { name: NAME })).toBeVisible();
  await expect(page.locator('.album-grid .album-card').first()).toContainText('Gemini');
  await page.getByRole('button', { name: 'Au hasard' }).click();
  await expect.poll(async () => (await queue(page)).count).toBeGreaterThan(0);

  // Suppression
  await page.getByTitle('Modifier la collection').click();
  page.once('dialog', (d) => void d.accept());
  await page.getByRole('button', { name: 'Supprimer' }).click();
  await expect(page.getByText(`Collection « ${NAME} » supprimée.`)).toBeVisible();
  await expect(page.getByRole('link', { name: new RegExp(NAME) })).toHaveCount(0);
});
