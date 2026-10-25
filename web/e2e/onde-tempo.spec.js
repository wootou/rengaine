// @ts-check
// Forme d'onde dans le lecteur (AUD-07) et fondu enchaîné calé sur le tempo (LEC-06), avec de vraies
// pistes du dev : leur forme d'onde et leurs grilles des temps viennent de l'analyse.
import { expect, test } from '@playwright/test';

/** @param {import('@playwright/test').Page} page */
async function login(page) {
  await page.goto('/');
  await page.getByLabel('Adresse e-mail').fill(String(process.env.E2E_EMAIL));
  await page.getByLabel('Mot de passe').fill(String(process.env.E2E_PASSWORD));
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('heading', { name: /Albums/ })).toBeVisible();
}

// La file est enregistrée sur le serveur et reprise à la connexion suivante : on la vide en partant,
// pour ne rien laisser aux essais suivants (même compte).
test.afterEach(async ({ page }) => {
  await page.evaluate(async () => {
    /** @type {any} */ (window).musique.reset();
    const me = await (await fetch('/api/v2/me')).json();
    const q = await fetch('/api/v2/me/queue');
    await fetch('/api/v2/me/queue', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': me.csrfToken, 'If-Match': String(q.headers.get('ETag')) },
      body: JSON.stringify({ trackIds: [], currentIndex: null, positionMs: 0, mode: 'normal' }),
    });
  });
});

/** Lance la lecture de pistes prises dans la bibliothèque. @param {import('@playwright/test').Page} page @param {number} count */
async function playSome(page, count) {
  await page.evaluate(async (n) => {
    await /** @type {any} */ (window).musique.restore();
    const items = (await (await fetch('/api/v2/tracks?limit=50')).json()).items;
    /** @type {any} */ (window).musique.playTracks(items.slice(0, n), 0);
  }, count);
}

test('la forme d’onde se dessine derrière la barre et suit la lecture @téléphone', async ({ page, isMobile }) => {
  await login(page);
  await playSome(page, 3);
  if (isMobile) await page.getByRole('button', { name: 'Ouvrir le lecteur' }).click();
  const wave = page.locator('.progress-bar .wave');
  await expect(wave).toBeVisible();
  // Des barres (une forme, pas un trait plat), la partie écoutée découpée à la position
  const d = await wave.locator('svg').first().locator('path').getAttribute('d');
  expect((d ?? '').split('Z').length).toBeGreaterThan(60);
  await page.evaluate(() => /** @type {any} */ (window).musique.seek(60000));
  await expect.poll(async () => wave.locator('.wave-played').evaluate((el) => /** @type {SVGElement} */ (el).style.clipPath)).toMatch(/inset\(0(px)? [1-9]\d?\.\d+% 0(px)? 0(px)?\)/);
  // Le curseur reste utilisable : au clavier, il avance de 10 s
  const bar = page.getByRole('slider', { name: 'Position' });
  const before = Number(await bar.getAttribute('aria-valuenow'));
  await bar.focus();
  await page.keyboard.press('ArrowRight');
  await expect.poll(async () => Number(await bar.getAttribute('aria-valuenow'))).toBeGreaterThanOrEqual(before + 9);
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth), 'rien ne déborde').toBeLessThanOrEqual(0);
});

test('fondu calé sur le tempo : la suivante suit le tempo et se cale sur les temps', async ({ page }) => {
  test.setTimeout(90_000);
  await login(page);

  // Réglage : fondu de 6 s (au clavier), calé sur le tempo
  await page.goto('/reglages');
  const fade = page.getByRole('slider', { name: /Fondu enchaîné/ });
  await fade.focus();
  await fade.press('Home');
  for (let i = 0; i < 6; i++) await fade.press('ArrowRight');
  await expect(page.getByText('Fondu enchaîné : 6 s')).toBeVisible();
  await expect(page.getByRole('status')).toHaveText('Enregistré');
  const sync = page.getByRole('checkbox', { name: /Caler le fondu sur le tempo/ });
  await expect(sync).toBeEnabled();
  await expect(sync).toBeChecked();
  await page.reload();
  await expect(page.getByText('Fondu enchaîné : 6 s')).toBeVisible(); // enregistré sur le serveur

  // Deux pistes d'albums différents : grille à la fin de l'une, au début de l'autre, tempos à moins de 3 %
  const pair = await page.evaluate(async () => {
    const items = (await (await fetch('/api/v2/tracks?limit=500')).json()).items;
    for (const a of items) {
      const out = a.playback.beatGrid?.outro;
      if (!out || a.durationMs < 90000) continue;
      for (const b of items) {
        const intro = b.playback.beatGrid?.intro;
        if (!intro || b.album.id === a.album.id || b.durationMs < 90000) continue;
        if (Math.abs(out.bpm / intro.bpm - 1) <= 0.03) return [a, b];
      }
    }
    return null;
  });
  expect(pair, 'deux pistes qui se calent dans la bibliothèque du dev').not.toBeNull();
  const [a, b] = /** @type {any[]} */ (pair);

  // Lecture de la première, 4 s avant le fondu prévu
  await page.evaluate(async ([x, y]) => {
    await /** @type {any} */ (window).musique.restore();
    /** @type {any} */ (window).musique.playTracks([x, y], 0);
  }, [a, b]);
  const end = a.playback.audioEndMs ?? a.durationMs;
  await page.evaluate((ms) => /** @type {any} */ (window).musique.seek(ms), end - 6000 - 4000);

  // Le fondu commence : la suivante joue à la vitesse qui suit le tempo
  await expect.poll(() => page.evaluate(() => /** @type {any} */ (window).musique.player.deck.trackId), { timeout: 15_000 }).toBe(b.id);
  const ratio = a.playback.beatGrid.outro.bpm / b.playback.beatGrid.intro.bpm;

  // Pendant le fondu : écart de phase entre les deux platines, en temps (de -0,5 à 0,5)
  const errors = [];
  for (let i = 0; i < 16; i++) {
    await page.waitForTimeout(250);
    const s = await page.evaluate(() => {
      const p = /** @type {any} */ (window).musique.player;
      const old = p.decks[1 - p.active];
      const cur = p.deck;
      return { fading: p.fading, rate: cur.audio.playbackRate, oldPos: old.offsetMs + old.audio.currentTime * 1000, newPos: cur.offsetMs + cur.audio.currentTime * 1000 };
    });
    if (!s.fading) break;
    expect(Math.abs(s.rate / ratio - 1), `vitesse ${s.rate} pour un rapport de tempo ${ratio}`).toBeLessThanOrEqual(0.031);
    const phase = (/** @type {number} */ pos, /** @type {{ bpm: number, offsetMs: number }} */ g) => {
      const x = ((pos - g.offsetMs) * g.bpm) / 60000;
      return x - Math.floor(x);
    };
    const e = phase(s.newPos, b.playback.beatGrid.intro) - phase(s.oldPos, a.playback.beatGrid.outro);
    errors.push(Math.abs(e - Math.round(e)));
  }
  expect(errors.length, 'mesures pendant le fondu').toBeGreaterThanOrEqual(8);
  // Une fois recalées (après 1,5 s), les deux pistes restent à moins d'un dixième de temps l'une de l'autre
  const settled = errors.slice(6);
  expect(Math.max(...settled), `écarts : ${errors.map((x) => x.toFixed(3)).join(' ')}`).toBeLessThan(0.1);

  // Après le fondu, retour progressif à la vitesse normale
  await expect.poll(() => page.evaluate(() => /** @type {any} */ (window).musique.player.deck.audio.playbackRate), { timeout: 20_000 }).toBe(1);
});
