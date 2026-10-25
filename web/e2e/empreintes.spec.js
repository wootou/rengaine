// @ts-check
// Même enregistrement ailleurs (BAS-03) : Admin > Doublons montre les pistes reconnues par l'empreinte
// acoustique. Sur le dev : « Glorious » et « Glorious (feat. Skylar Grey) » (Macklemore, Gemini).
import { expect, test } from '@playwright/test';

test('Admin > Doublons : même enregistrement sous deux titres, écoutable @téléphone', async ({ page }) => {
  await page.goto('/');
  await page.getByLabel('Adresse e-mail').fill(String(process.env.E2E_ADMIN_EMAIL));
  await page.getByLabel('Mot de passe').fill(String(process.env.E2E_ADMIN_PASSWORD));
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('heading', { name: /Albums/ })).toBeVisible();

  await page.goto('/admin/doublons');
  const section = page.locator('.same-recordings');
  await expect(section.getByRole('heading', { name: /Même enregistrement ailleurs/ })).toBeVisible();
  const group = section.locator('.same-group').filter({ hasText: 'Glorious (feat. Skylar Grey)' });
  await expect(group).toHaveCount(1);
  await expect(group.getByText('Identique')).toBeVisible();
  await expect(group.locator('li')).toHaveCount(2);
  await expect(section).toContainText(/\d+ pistes? analysées?/);

  // Les deux s'écoutent d'ici pour comparer
  await group.getByRole('button', { name: 'Écouter' }).nth(1).click();
  // La file commence par les deux pistes du groupe (le mode infini du compte peut la compléter), la seconde en cours
  await expect.poll(() => page.evaluate(() => {
    const q = /** @type {any} */ (window).musique.store.getState().queue;
    const titles = q.items.slice(0, 2).map((/** @type {any} */ it) => it.track.title);
    const cur = q.items.find((/** @type {any} */ it) => it.key === q.currentKey)?.track.title;
    return `${titles.join(' | ')} → ${cur}`;
  })).toMatch(/^Glorious.* \| Glorious.* → Glorious/);
  await page.getByLabel('Ressemblance').selectOption({ label: 'Identiques seulement' });
  await expect(group).toHaveCount(1);
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth), 'rien ne déborde').toBeLessThanOrEqual(0);

  await page.evaluate(async () => {
    /** @type {any} */ (window).musique.reset();
    const me = await (await fetch('/api/v2/me')).json();
    const q = await fetch('/api/v2/me/queue');
    await fetch('/api/v2/me/queue', { method: 'PUT', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': me.csrfToken, 'If-Match': String(q.headers.get('ETag')) },
      body: JSON.stringify({ trackIds: [], currentIndex: null, positionMs: 0, mode: 'normal' }) });
  });
});
