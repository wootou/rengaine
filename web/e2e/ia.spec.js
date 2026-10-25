// @ts-check
// Réorganisation par IA (IA-04), page Propositions. Le dev n'a pas de section [ia] : on vérifie
// l'explication, puis le formulaire et le compte rendu avec une IA simulée (réponses de l'API
// remplacées dans le navigateur, aucune tâche lancée pour de vrai).
import { expect, test } from '@playwright/test';

async function login(/** @type {import('@playwright/test').Page} */ page) {
  await page.goto('/');
  await page.getByLabel('Adresse e-mail').fill(String(process.env.E2E_ADMIN_EMAIL));
  await page.getByLabel('Mot de passe').fill(String(process.env.E2E_ADMIN_PASSWORD));
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('heading', { name: /Albums/ })).toBeVisible();
}

test('Propositions : demander une réorganisation à une IA @téléphone', async ({ page }) => {
  await login(page);
  await page.goto('/admin/lots');
  await page.getByText('Demander à une IA').click();
  await expect(page.getByText(/brancher un client MCP/)).toBeVisible();

  // IA configurée (simulée) : formulaire, envoi, compte rendu d'une tâche finie
  await page.route('**/api/v2/admin/server-settings', (route) => route.fulfill({ json: {
    serverName: 'Rengaine', language: 'fr', registrationOpen: true, pendingRequestsPerNetwork: 2, remoteControlEnabled: true,
    realtimeService: 'running', assistant: { provider: 'anthropic', model: 'claude-opus-5-5' },
  } }));
  const job = {
    id: 9001, type: 'organize', status: 'done', params: { instructions: 'Rattache les genres des tags orphelins' }, stats: { turns: 4 },
    requestedBy: null, createdAt: '2026-10-24T14:00:00.000Z', startedAt: null, finishedAt: null,
    summary: 'J’ai rattaché 12 valeurs de tags.\n\nLots proposés : n° 41',
  };
  await page.route('**/api/v2/admin/jobs?*', (route) => route.fulfill({ json: { items: [job], total: 1, limit: 3, offset: 0 } }));
  /** @type {any} */
  let sent = null;
  await page.route('**/api/v2/admin/jobs', async (route) => {
    sent = route.request().postDataJSON();
    await route.fulfill({ status: 202, json: { ...job, id: 9002, status: 'queued', summary: null } });
  });
  await page.reload();
  const box = page.locator('.ask-ai');
  await expect(box).toContainText('anthropic · claude-opus-5-5');
  await expect(box.locator('.ai-summary')).toContainText('Lots proposés : n° 41');
  await box.getByRole('textbox').fill('Repère les artistes en double');
  await box.getByRole('button', { name: 'Lancer' }).click();
  await expect(box).toContainText('Demande envoyée');
  expect(sent).toEqual({ type: 'organize', params: { instructions: 'Repère les artistes en double' } });
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth), 'rien ne déborde').toBeLessThanOrEqual(0);
});
