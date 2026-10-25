// @ts-check
// Télécommande partagée (FUT-02) : le compte crée un lien dans les Réglages ; un invité (autre
// navigateur, sans compte) voit ce qui joue, met en pause, ajoute, retire ; puis le lien est révoqué.
// Tout passe par le vrai hub Mercure, à travers le serveur web.
import { devices, expect, test } from '@playwright/test';

/** @param {import('@playwright/test').Page} page */
async function login(page) {
  await page.goto('/');
  await page.getByLabel('Adresse e-mail').fill(String(process.env.E2E_EMAIL));
  await page.getByLabel('Mot de passe').fill(String(process.env.E2E_PASSWORD));
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('heading', { name: /Albums/ })).toBeVisible();
}

test.afterEach(async ({ page }) => {
  await page.evaluate(async () => {
    /** @type {any} */ (window).musique.reset();
    const me = await (await fetch('/api/v2/me')).json();
    const h = { 'Content-Type': 'application/json', 'X-CSRF-Token': me.csrfToken };
    for (const s of (await (await fetch('/api/v2/me/shares')).json()).items) {
      await fetch(`/api/v2/me/shares/${s.id}`, { method: 'DELETE', headers: h });
    }
    const q = await fetch('/api/v2/me/queue');
    await fetch('/api/v2/me/queue', {
      method: 'PUT', headers: { ...h, 'If-Match': String(q.headers.get('ETag')) },
      body: JSON.stringify({ trackIds: [], currentIndex: null, positionMs: 0, mode: 'normal' }),
    });
  });
});

test('un invité pilote la file par un lien, jusqu’à la révocation', async ({ page, browser }) => {
  test.setTimeout(90_000);
  await login(page);

  // Le compte écoute trois pistes
  const items = await page.evaluate(async () => {
    await /** @type {any} */ (window).musique.restore();
    /** @type {any} */ (window).musique.setInfinite(false); // la file ne doit pas se compléter toute seule
    const list = (await (await fetch('/api/v2/tracks?limit=60')).json()).items.filter((/** @type {any} */ t) => t.available);
    /** @type {any} */ (window).musique.playTracks(list.slice(0, 3), 0);
    return list.map((/** @type {any} */ t) => ({ id: t.id, title: t.title }));
  });
  const playing = () => page.evaluate(() => /** @type {any} */ (window).musique.state.playing);
  await expect.poll(playing).toBe(true);

  // Réglages (sans recharger la page : la musique continue) → lien pour « Essai »
  await page.getByRole('link', { name: 'Réglages' }).click();
  await page.getByLabel('Pour qui').fill('Essai');
  await page.getByRole('button', { name: 'Créer le lien' }).click();
  const field = page.getByLabel(/Lien pour « Essai »/);
  await expect(field).toHaveValue(/\/telecommande#mr_/);
  const url = await field.inputValue();
  await expect(page.getByRole('button', { name: 'Révoquer le lien « Essai »' })).toBeVisible();

  // L'invité, sur un téléphone, sans compte
  const guestCtx = await browser.newContext({ ...devices['Pixel 7'], locale: 'fr-FR' });
  const guest = await guestCtx.newPage();
  await guest.goto(url);
  await expect(guest.getByRole('heading', { name: 'Télécommande' })).toBeVisible();
  await expect(guest.getByText('File d’attente de Essai navigateur')).toBeVisible();
  await expect(guest).toHaveURL(/\/telecommande$/); // le secret ne reste pas dans l'adresse
  await expect(guest.getByRole('status')).toContainText('Lecture en cours');
  await expect(guest.locator('.remote-now strong')).toHaveText(items[0].title);
  await expect(guest.locator('.remote-queue li')).toHaveCount(2);
  expect(await guest.evaluate(() => document.documentElement.scrollWidth - window.innerWidth), 'rien ne déborde').toBeLessThanOrEqual(0);
  // Prénom demandé une fois (plusieurs personnes peuvent avoir le même lien)
  await guest.getByLabel('Votre prénom').fill('Paul');
  await guest.getByRole('button', { name: 'OK' }).click();
  await expect(guest.getByText('Vous êtes Paul')).toBeVisible();

  // Pause depuis le téléphone : le lecteur du compte s'arrête
  await guest.getByRole('button', { name: 'Pause' }).click();
  await expect.poll(playing, { timeout: 5000 }).toBe(false);
  await expect(guest.getByRole('status')).toContainText('En pause');
  await page.getByTitle("File d'attente").first().click();
  await expect(page.locator('.queue-panel .remote-note')).toHaveText('Paul : pause');
  await page.getByTitle('Fermer').click();
  await guest.getByRole('button', { name: 'Lecture' }).click();
  await expect.poll(playing, { timeout: 5000 }).toBe(true);

  // Volume réglé depuis le téléphone : le lecteur suit, et l'invité voit la valeur confirmée
  const volume = () => page.evaluate(() => /** @type {any} */ (window).musique.store.getState().volume);
  await expect(guest.getByLabel('Volume du lecteur')).toHaveValue(String(Math.round((await volume()) * 100)));
  await guest.getByLabel('Volume du lecteur').fill('40');
  await expect.poll(volume, { timeout: 5000 }).toBe(0.4);
  await guest.getByRole('button', { name: 'Baisser le volume' }).click();
  await expect.poll(volume, { timeout: 5000 }).toBe(0.3);
  await expect(guest.locator('.remote-volume-value')).toHaveText('30 %');

  // Changé sur le lecteur lui-même : le téléphone suit
  await page.evaluate(() => /** @type {any} */ (window).musique.setVolume(0.7));
  await expect(guest.getByLabel('Volume du lecteur')).toHaveValue('70', { timeout: 5000 });

  // Ajout « ensuite » d'une piste trouvée par la recherche
  const extra = items.slice(3).find((t) => t.title.length >= 4 && !items.slice(0, 3).some((u) => u.title === t.title));
  expect(extra, 'une quatrième piste dans la bibliothèque').toBeTruthy();
  const { id: extraId, title: extraTitle } = /** @type {{ id: number, title: string }} */ (extra);
  await guest.getByLabel('Ajouter des morceaux').fill(extraTitle);
  await guest.getByRole('button', { name: `Lire « ${extraTitle} » ensuite` }).first().click();
  await expect.poll(() => page.evaluate(() => /** @type {any} */ (window).musique.state.queue.items.map((/** @type {any} */ it) => it.track.id)), { timeout: 5000 })
    .toEqual([items[0].id, extraId, items[1].id, items[2].id]);
  await expect(guest.locator('.remote-queue li').first()).toContainText(extraTitle);
  // Retrait de la dernière
  await guest.getByRole('button', { name: `Retirer « ${items[2].title} »` }).click();
  await expect.poll(() => page.evaluate(() => /** @type {any} */ (window).musique.state.queue.items.length), { timeout: 5000 }).toBe(3);
  await expect(guest.locator('.remote-queue li')).toHaveCount(2);

  // Lire maintenant une piste de la file : elle joue, l'ordre ne change pas
  await guest.getByRole('button', { name: `Lire « ${items[1].title} » maintenant` }).click();
  await expect.poll(() => page.evaluate(() => { const q = /** @type {any} */ (window).musique.state.queue; return q.items.findIndex((/** @type {any} */ it) => it.key === q.currentKey); }), { timeout: 5000 }).toBe(2);
  expect(await page.evaluate(() => /** @type {any} */ (window).musique.state.queue.items.map((/** @type {any} */ it) => it.track.id)))
    .toEqual([items[0].id, extraId, items[1].id]);
  await expect(guest.locator('.remote-now strong')).toHaveText(items[1].title);

  // Révocation : l'invité est prévenu aussitôt
  await page.getByRole('button', { name: 'Révoquer le lien « Essai »' }).click();
  await expect(guest.getByRole('alert')).toHaveText('Ce lien vient d’être révoqué.');
  await guest.reload();
  await expect(guest.getByRole('alert')).toContainText('n’est pas (ou plus) valable');
  await guestCtx.close();
});

test('lien sans le droit de commander : boutons absents, recherche absente', async ({ page, browser }) => {
  await login(page);
  const url = await page.evaluate(async () => {
    const me = await (await fetch('/api/v2/me')).json();
    const r = await fetch('/api/v2/me/shares', {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': me.csrfToken },
      body: JSON.stringify({ label: 'Lecture seule', hours: 1, rights: { add: false, edit: false, control: false } }),
    });
    return (await r.json()).url;
  });
  const guestCtx = await browser.newContext({ locale: 'fr-FR' });
  const guest = await guestCtx.newPage();
  await guest.goto(url);
  await expect(guest.getByRole('heading', { name: 'Télécommande' })).toBeVisible();
  await expect(guest.getByRole('status')).toContainText('lien valable jusqu’au');
  await expect(guest.getByRole('button', { name: /Pause|Lecture/ })).toHaveCount(0);
  await expect(guest.getByLabel('Volume du lecteur')).toHaveCount(0);
  await expect(guest.getByLabel('Ajouter des morceaux')).toHaveCount(0);
  await guestCtx.close();
});
