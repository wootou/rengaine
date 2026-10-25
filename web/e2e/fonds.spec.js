// @ts-check
// Images de fond (WEB-12) : envoi depuis Admin > Fonds, réduite par le navigateur, désactivée,
// supprimée. Les images déjà choisies sur le site ne sont pas touchées.
import { expect, test } from '@playwright/test';

/** @param {import('@playwright/test').Page} page */
async function loginAdmin(page) {
  await page.goto('/');
  await page.getByLabel('Adresse e-mail').fill(String(process.env.E2E_ADMIN_EMAIL));
  await page.getByLabel('Mot de passe').fill(String(process.env.E2E_ADMIN_PASSWORD));
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('heading', { name: /Albums/ })).toBeVisible();
}

/** Noms des images envoyées par cet essai : retirées à la fin, même s'il échoue. */
const sent = /** @type {string[]} */ ([]);

test.afterEach(async ({ page }) => {
  for (const name of sent.splice(0)) {
    await page.evaluate(async (n) => {
      const me = await (await fetch('/api/v2/me')).json();
      const list = (await (await fetch('/api/v2/admin/backgrounds')).json()).items;
      for (const b of list.filter((/** @type {any} */ x) => x.name === n)) {
        await fetch(`/api/v2/admin/backgrounds/${b.id}`, { method: 'DELETE', headers: { 'X-CSRF-Token': me.csrfToken } });
      }
    }, name);
  }
});

test('une photo de 3000 × 2000 px arrive réduite, puis se désactive et se supprime', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('/admin/fonds');
  await expect(page.getByRole('heading', { name: /Images de fond/ })).toBeVisible();

  // Une « photo » dessinée dans le navigateur : dégradé et bandes, en PNG
  const dataUrl = await page.evaluate(() => {
    const c = document.createElement('canvas');
    c.width = 3000;
    c.height = 2000;
    const ctx = /** @type {CanvasRenderingContext2D} */ (c.getContext('2d'));
    const g = ctx.createLinearGradient(0, 0, 3000, 2000);
    g.addColorStop(0, '#1d4e89');
    g.addColorStop(1, '#f5a65b');
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, 3000, 2000);
    ctx.fillStyle = 'rgba(255,255,255,.25)';
    for (let x = 0; x < 3000; x += 200) ctx.fillRect(x, 0, 60, 2000);
    return c.toDataURL('image/png');
  });
  const name = `essai-e2e-${Date.now()}`;
  sent.push(name);
  const upload = page.waitForResponse((r) => r.url().includes('/api/v2/admin/backgrounds') && r.request().method() === 'POST');
  await page.locator('.file-button input[type=file]').setInputFiles({
    name: `${name}.png`, mimeType: 'image/png', buffer: Buffer.from(dataUrl.split(',')[1], 'base64'),
  });
  expect((await upload).status()).toBe(201);
  await expect(page.getByText('Image ajoutée.')).toBeVisible();

  const card = page.locator('.bg-card').filter({ has: page.getByLabel(`Nom de l’image ${name}`) });
  await expect(card).toHaveCount(1);
  await expect(card.locator('small')).toContainText('2400 × 1600 px'); // 1600 px de haut au plus
  await expect.poll(() => card.locator('img').evaluate((/** @type {HTMLImageElement} */ img) => img.complete && img.naturalWidth)).toBe(2400);

  // Désactivée : elle n'est plus proposée aux visiteurs. La case suit la réponse du serveur : on clique, puis on attend le nouvel état
  const active = card.getByRole('checkbox', { name: 'Active' });
  await active.click();
  await expect(page.getByText('Image désactivée.')).toBeVisible();
  await expect(active).not.toBeChecked();
  await expect(card).toHaveClass(/inactive/);
  const ids = await page.evaluate(async (n) => {
    const admin = (await (await fetch('/api/v2/admin/backgrounds')).json()).items;
    const pub = (await (await fetch('/api/v2/backgrounds')).json()).items;
    return { mine: admin.find((/** @type {any} */ x) => x.name === n)?.id, shown: pub.map((/** @type {any} */ x) => x.id) };
  }, name);
  expect(ids.mine).toBeGreaterThan(0);
  expect(ids.shown).not.toContain(ids.mine);

  // Supprimée (la fenêtre de confirmation est acceptée)
  page.once('dialog', (d) => void d.accept());
  await card.getByRole('button', { name: 'Supprimer' }).click();
  await expect(page.getByText('Image supprimée.')).toBeVisible();
  await expect(card).toHaveCount(0);
});
