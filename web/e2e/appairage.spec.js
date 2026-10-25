// @ts-check
// Appairer l'appli (WEB-14) : Réglages › Appareils connectés affiche un QR, le code en clair et
// l'adresse du serveur. Quand l'appli échange le code (POST /auth/pair), la page l'annonce.
import { expect, test } from '@playwright/test';

test('afficher le QR d’appairage, puis voir l’appareil arriver', async ({ page }) => {
  test.setTimeout(60_000);
  await page.goto('/');
  await page.getByLabel('Adresse e-mail').fill(String(process.env.E2E_EMAIL));
  await page.getByLabel('Mot de passe').fill(String(process.env.E2E_PASSWORD));
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('heading', { name: /Albums/ })).toBeVisible();
  await page.getByTitle('Réglages').click();

  await page.getByRole('button', { name: 'Appairer un appareil' }).click();
  const qr = page.getByRole('img', { name: 'QR code d’appairage' });
  await expect(qr).toBeVisible();
  const code = (await page.locator('.pair-code').textContent()) ?? '';
  expect(code).toMatch(/^[A-Z0-9]{4}-[A-Z0-9]{4}$/);
  await expect(page.locator('.pair-server')).toHaveText(/^https?:\/\//);
  await expect(page.locator('.pair-info time')).toHaveText(/^[45]:\d\d$/);
  if (process.env.E2E_QR_FILE) await qr.screenshot({ path: process.env.E2E_QR_FILE });

  // L'appli échange le code contre un jeton : la page l'annonce et la liste s'actualise
  const name = `Appli e2e ${Date.now()}`;
  // Depuis la page (le navigateur d'essai passe par le même chemin que le site), sans cookie
  const status = await page.evaluate(async ([c, n]) => (await fetch('/api/v2/auth/pair', {
    method: 'POST', credentials: 'omit', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ code: c, deviceName: n }),
  })).status, [code, name]);
  expect(status).toBe(200);
  await expect(page.getByText(`« ${name} » est appairé.`)).toBeVisible({ timeout: 10_000 });
  await expect(qr).toHaveCount(0);
  const row = page.locator('.devices li', { hasText: name });
  await expect(row).toBeVisible();

  // Ménage : déconnecter l'appareil de test
  await row.getByRole('button', { name: 'Déconnecter' }).click();
  await expect(row).toHaveCount(0);
});
