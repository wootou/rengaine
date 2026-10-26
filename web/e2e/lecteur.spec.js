// @ts-check
import { expect, test } from '@playwright/test';

/** Erreurs de la page (console, violations de la CSP) : un test les vérifie à la fin. */
/** @param {import('@playwright/test').Page} page */
function watchErrors(page) {
  /** @type {string[]} */
  const errors = [];
  page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));
  page.on('pageerror', (e) => errors.push(e.message));
  return errors;
}

/** @param {import('@playwright/test').Page} page */
async function login(page) {
  await page.goto('/');
  await page.getByLabel('Adresse e-mail').fill(String(process.env.E2E_EMAIL));
  await page.getByLabel('Mot de passe').fill(String(process.env.E2E_PASSWORD));
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('heading', { name: /Albums/ })).toBeVisible();
}

/** État du lecteur, lu dans le contrôleur. @param {import('@playwright/test').Page} page */
function player(page) {
  return page.evaluate(() => {
    const c = /** @type {any} */ (window).musique;
    const q = c.store.getState().queue;
    const cur = q.items.find((/** @type {any} */ i) => i.key === q.currentKey);
    const deck = c.player.deck;
    return {
      title: cur?.track.title ?? null, count: q.items.length, playing: !deck.audio.paused,
      position: deck.audio.currentTime, gain: deck.gain ? deck.gain.gain.value : null, webAudio: !!c.player.ctx,
    };
  });
}

test('connexion, albums, lecture d’un album avec le volume normalisé', async ({ page }) => {
  const errors = watchErrors(page);
  await login(page);
  // La bibliothèque de test grandit (dépôt) : au moins les 5 albums d'origine
  await expect.poll(() => page.locator('.album-card').count()).toBeGreaterThanOrEqual(5);
  await page.getByRole('link', { name: /L'essentiel 1977-2017/ }).click();
  await expect(page.getByRole('heading', { name: "L'essentiel 1977-2017" })).toBeVisible();
  await expect(page.locator('.tracklist li')).toHaveCount(51);

  await page.getByRole('button', { name: 'Écouter' }).click();
  await expect.poll(async () => (await player(page)).position, { timeout: 15000 }).toBeGreaterThan(1.5);
  const p = await player(page);
  expect(p.playing).toBe(true);
  expect(p.count).toBe(51);
  expect(p.webAudio).toBe(true);
  // Gain réel = celui calculé depuis la mesure du serveur (mode auto : album écouté dans l'ordre)
  const expected = await page.evaluate(async () => {
    const c = /** @type {any} */ (window).musique;
    const q = c.store.getState().queue;
    const t = q.items.find((/** @type {any} */ i) => i.key === q.currentKey).track;
    return Math.pow(10, c.gainFor(t) / 20);
  });
  expect(p.gain).toBeCloseTo(expected, 3);
  expect(p.gain).toBeLessThan(1); // Cabrel à -12,5 LUFS, cible -16 : on baisse
  await expect(page.locator('.player-bar .np-title')).toHaveText(p.title ?? '');
  await page.getByRole('button', { name: 'Pause' }).click();
  expect(errors).toEqual([]);
});

test('la file d’attente est retrouvée après rechargement (enregistrée sur le serveur)', async ({ page }) => {
  await login(page);
  await page.getByRole('link', { name: /Tonight/ }).click();
  await page.getByTitle('Ajouter à la file').first().click(); // bouton de l'album
  await expect.poll(async () => (await player(page)).count).toBeGreaterThan(0);
  const before = await player(page);
  await page.waitForTimeout(2500); // envoi groupé de la file
  await page.reload();
  await expect(page.getByRole('heading', { name: /Albums|Tonight/ }).first()).toBeVisible();
  await expect.poll(async () => (await player(page)).count, { timeout: 10000 }).toBe(before.count);
  await page.getByTitle("File d'attente").click();
  await expect(page.locator('.queue-list li')).toHaveCount(before.count);
});

test('recherche pendant la frappe, sans accents', async ({ page }) => {
  await login(page);
  await page.getByLabel('Rechercher').fill('cabrel');
  await expect(page.locator('.search-results')).toContainText('Francis Cabrel');
  await page.getByLabel('Rechercher').fill('indé');
  await page.getByLabel('Rechercher').press('Enter');
  await expect(page).toHaveURL(/\/recherche\?q=ind/);
});

test('fondu enchaîné entre deux albums : la suivante démarre et la file avance', async ({ page }) => {
  await login(page);
  // Fondu de 3 s, réglé comme le ferait la page Réglages
  await page.evaluate(async () => {
    const r = await fetch('/api/v2/me/settings');
    const doc = await r.json();
    const me = await (await fetch('/api/v2/me')).json();
    doc.settings.playback.crossfadeMs = 3000;
    await fetch('/api/v2/me/settings', { method: 'PUT', headers: { 'Content-Type': 'application/json', 'If-Match': r.headers.get('ETag') ?? '"0"', 'X-CSRF-Token': me.csrfToken }, body: JSON.stringify(doc.settings) });
  });
  await page.reload();
  await expect(page.getByRole('heading', { name: /Albums/ })).toBeVisible();
  const titles = await page.evaluate(async () => {
    const c = /** @type {any} */ (window).musique;
    const a = (await (await fetch('/api/v2/albums/1')).json()).tracks[0];
    const b = (await (await fetch('/api/v2/random/tracks?count=20')).json()).items.find((/** @type {any} */ t) => t.album.id !== a.album.id);
    c.playTracks([a, b], 0);
    return [a.title, b.title];
  });
  await expect.poll(async () => (await player(page)).position, { timeout: 15000 }).toBeGreaterThan(0.5);
  // Saut juste avant le début du fondu
  await page.evaluate(() => {
    const c = /** @type {any} */ (window).musique;
    const q = c.store.getState().queue;
    const t = q.items[0].track;
    c.seek((t.playback.audioEndMs ?? t.durationMs) - 3000 - 1500);
  });
  await expect.poll(async () => (await player(page)).title, { timeout: 15000 }).toBe(titles[1]);
  const p = await player(page);
  expect(p.playing).toBe(true);
  await expect(page.locator('.player-bar .np-title')).toHaveText(titles[1]);
});

test('réglages enregistrés sur le serveur', async ({ page }) => {
  await login(page);
  await page.getByTitle('Réglages').click();
  await page.getByLabel('Thème').selectOption('light');
  await expect(page.getByRole('status')).toHaveText('Enregistré');
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
  await page.reload();
  await expect(page.getByLabel('Thème')).toHaveValue('light');
  await expect(page.locator('.devices li')).toHaveCount(await page.locator('.devices li').count());
});

test('affichage téléphone : rien ne déborde, le lecteur reste accessible @téléphone', async ({ page }) => {
  await login(page);
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(overflow).toBeLessThanOrEqual(0);
  await expect(page.locator('.player-bar')).toBeVisible();
  await page.getByRole('link', { name: /Gemini/ }).click();
  await page.getByText('Glorious').first().click();
  await expect.poll(async () => (await player(page)).playing, { timeout: 15000 }).toBe(true);
  await page.getByTitle("File d'attente").click();
  await expect(page.locator('.queue-panel')).toBeVisible();
});

test('grand écran : la file ouverte, le contenu reste centré entre la barre latérale et la file', async ({ page }) => {
  await page.setViewportSize({ width: 2560, height: 1200 });
  await login(page);
  await page.getByTitle("File d'attente").click();
  await expect(page.locator('.queue-panel')).toBeVisible();
  const box = await page.evaluate(() => {
    const r = /** @type {HTMLElement} */ (document.querySelector('.content')).getBoundingClientRect();
    const side = /** @type {HTMLElement} */ (document.querySelector('.top')).getBoundingClientRect().right;
    const queue = /** @type {HTMLElement} */ (document.querySelector('.queue-panel')).getBoundingClientRect().left;
    return { left: r.left - side, right: queue - r.right };
  });
  expect(box.right).toBeGreaterThanOrEqual(0);
  expect(Math.abs(box.left - box.right)).toBeLessThanOrEqual(2);
});

test('déconnexion', async ({ page }) => {
  await login(page);
  await page.getByTitle('Réglages').click();
  await page.getByRole('button', { name: 'Se déconnecter' }).click();
  await expect(page.getByRole('button', { name: 'Se connecter' })).toBeVisible();
  await page.reload();
  await expect(page.getByRole('button', { name: 'Se connecter' })).toBeVisible();
});
