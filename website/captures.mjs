// Captures d'écran du site de présentation (rengaine.org), sur l'instance de démonstration : bibliothèque
// fictive (server/bin/rengaine demo:generer), base à part, servie par le serveur intégré de PHP (tools/demo-server.php).
//
//   DEMO_URL=http://127.0.0.1:8090 DEMO_EMAIL=… DEMO_PASSWORD=… DEMO_ADMIN_TOKEN=… DEMO_AGENT_TOKEN=… \
//     node website/captures.mjs
//
// Les jetons viennent de `rengaine user:create` (admin : connexion en app ; agent : jeton affiché à la
// création) et ne sont jamais écrits ici. Sortie : website/images/<langue>/<nom>-<thème>.webp.
import { execFileSync } from 'node:child_process';
import { mkdirSync, rmSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from '../web/node_modules/@playwright/test/index.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const BASE = process.env.DEMO_URL ?? 'http://127.0.0.1:8090';
const ADMIN = process.env.DEMO_ADMIN_TOKEN ?? '';
const AGENT = process.env.DEMO_AGENT_TOKEN ?? '';
const EMAIL = process.env.DEMO_EMAIL ?? '';
const PASSWORD = process.env.DEMO_PASSWORD ?? '';
if (!ADMIN || !AGENT || !EMAIL || !PASSWORD) throw new Error('DEMO_ADMIN_TOKEN, DEMO_AGENT_TOKEN, DEMO_EMAIL et DEMO_PASSWORD sont nécessaires');

/** Appel de l'API avec un jeton. */
async function api(/** @type {string} */ token, /** @type {string} */ method, /** @type {string} */ path, /** @type {any} */ body, /** @type {Record<string, string>} */ headers = {}) {
  const r = await fetch(`${BASE}/api/v2${path}`, {
    method, headers: { Authorization: `Bearer ${token}`, ...(body ? { 'Content-Type': 'application/json' } : {}), ...headers },
    body: body ? JSON.stringify(body) : undefined,
  });
  const text = await r.text();
  if (!r.ok) throw new Error(`${method} ${path} : ${r.status} ${text.slice(0, 200)}`);
  return text ? JSON.parse(text) : null;
}

// ------------------------------------------------------------------ données de la démo

const albums = (await api(ADMIN, 'GET', '/albums?limit=200&sort=name')).items;
if (albums.length < 5) throw new Error('Bibliothèque de démo vide : lancer l’indexation');
const byTitle = (/** @type {RegExp} */ re) => albums.find((/** @type {any} */ a) => re.test(a.title)) ?? albums[0];
const featured = byTitle(/heures claires/i);
const second = byTitle(/Low Tide/i);
const tracksOf = async (/** @type {number} */ id) => (await api(ADMIN, 'GET', `/albums/${id}`)).tracks;
const featuredTracks = await tracksOf(featured.id);
const secondTracks = await tracksOf(second.id);

// Favoris (cœurs visibles), une playlist, des écoutes aujourd'hui et hier
for (const a of albums.slice(0, 4)) await api(ADMIN, 'PUT', `/me/favorites/album/${a.id}`);
for (const t of featuredTracks.slice(0, 3)) await api(ADMIN, 'PUT', `/me/favorites/track/${t.id}`);
const lists = (await api(ADMIN, 'GET', '/playlists')).items;
if (!lists.some((/** @type {any} */ p) => p.name === 'Pour la route')) {
  const pl = await api(ADMIN, 'POST', '/playlists', { name: 'Pour la route' });
  await api(ADMIN, 'POST', `/playlists/${pl.id}/items`, { trackIds: [...featuredTracks.slice(0, 5), ...secondTracks.slice(0, 5)].map((t) => t.id) });
}
const day = 86_400_000;
const now = Date.now();
const plays = [...featuredTracks.slice(0, 6), ...secondTracks.slice(0, 5)].map((t, i) => ({
  trackId: t.id, startedAt: new Date(now - (i < 6 ? i * 4 * 60_000 + 3_600_000 : day + i * 5 * 60_000)).toISOString(),
  playedMs: i === 2 ? 42_000 : t.durationMs, completed: i !== 2, skipped: i === 2, context: 'album',
}));
await api(ADMIN, 'POST', '/me/history', { plays });

// File d'attente : l'album mis en avant, troisième piste en cours
const queue = await api(ADMIN, 'GET', '/me/queue');
await api(ADMIN, 'PUT', '/me/queue', { trackIds: [...featuredTracks, ...secondTracks.slice(0, 4)].map((t) => t.id), currentIndex: 2, positionMs: 61_000, mode: 'normal' },
  { 'If-Match': `"${queue.version}"` });

// Réglages : fondu enchaîné de 6 s (l'écran des réglages montre un réglage parlant)
const prefs = await api(ADMIN, 'GET', '/me/settings');
await api(ADMIN, 'PUT', '/me/settings', { ...prefs.settings, playback: { ...prefs.settings.playback, crossfadeMs: 6000 } }, { 'If-Match': `"${prefs.version}"` });

// Une proposition de l'agent, en attente
const sets = (await api(AGENT, 'GET', '/admin/change-sets?status=pending')).items;
let proposal = sets[0];
if (!proposal) {
  const target = albums.filter((/** @type {any} */ a) => a.id !== featured.id).slice(0, 3);
  proposal = await api(AGENT, 'POST', '/admin/change-sets', {
    title: 'Années de réédition corrigées', description: 'Les tags donnent l’année de réédition ; l’année de première sortie est plus utile pour les décennies.',
    changes: target.map((/** @type {any} */ a, i) => ({ entityType: 'album', entityId: a.id, field: 'year', value: a.year ? a.year - [1, 2, 3][i] : 2001, reason: 'Année de première sortie' })),
  });
}

// Lien de télécommande, et un lecteur « qui joue » pour la page de l'invité
for (const old of (await api(ADMIN, 'GET', '/me/shares')).items) await api(ADMIN, 'DELETE', `/me/shares/${old.id}`);
const share = await api(ADMIN, 'POST', '/me/shares', { label: 'Soirée', hours: 24, rights: { add: true, edit: true, control: true } });
const player = () => api(ADMIN, 'PUT', '/me/player', { deviceKey: 'demo-capture', trackId: featuredTracks[2].id, playing: true, positionMs: 61_000, queueVersion: queue.version + 1, volume: 80 });

// ------------------------------------------------------------------ captures

const out = join(here, 'images');
const tmp = join(here, '.captures-tmp');
rmSync(tmp, { recursive: true, force: true });
mkdirSync(tmp, { recursive: true });
const browser = await chromium.launch({ args: ['--autoplay-policy=no-user-gesture-required'] });

/** Enregistre une capture PNG puis la convertit en WebP. */
async function shot(/** @type {import('@playwright/test').Page} */ page, /** @type {string} */ lang, /** @type {string} */ name, /** @type {string} */ theme) {
  await page.waitForTimeout(700); // images chargées, transitions finies
  const png = join(tmp, `${lang}-${name}-${theme}.png`);
  await page.screenshot({ path: png });
  mkdirSync(join(out, lang), { recursive: true });
  execFileSync('ffmpeg', ['-v', 'error', '-y', '-i', png, '-c:v', 'libwebp', '-quality', '82', join(out, lang, `${name}-${theme}.webp`)]);
  console.log(`${lang} ${name} ${theme}`);
}

/**
 * Ouvre une page et attend un élément ; recharge jusqu'à trois fois si le lecteur reste sur « Chargement… »
 * (le serveur intégré de PHP sert les flux audio en cours avec les mêmes processus).
 */
async function open(/** @type {import('@playwright/test').Page} */ page, /** @type {string} */ path, /** @type {string} */ selector) {
  const errors = /** @type {string[]} */ ([]);
  const onResponse = (/** @type {any} */ r) => { if (r.status() >= 400 && r.url().includes('/api/')) errors.push(`${r.status()} ${r.url().replace(BASE, '')}`); };
  page.on('response', onResponse);
  for (let i = 0; i < 3; i++) {
    await page.goto(`${BASE}${path}`);
    try {
      await page.locator(selector).first().waitFor({ timeout: 15_000 });
      page.off('response', onResponse);
      return;
    } catch {
      console.log(`  nouvel essai : ${path}`);
    }
  }
  await page.screenshot({ path: join(here, 'echec.png') });
  throw new Error(`Page restée vide : ${path} ; erreurs de l'API : ${errors.slice(-6).join(', ') || 'aucune'}`);
}

// Flux audio : récupérés en entier par Playwright puis rendus au navigateur. Sans cela, le navigateur lirait le
// flux au rythme de l'écoute, et chaque flux en cours occuperait un processus du serveur intégré de PHP (la
// page finirait par rester sur « Chargement… »).
/** Nouveau contexte de navigateur. */
async function context(/** @type {any} */ options) {
  const ctx = await browser.newContext(options);
  await ctx.route('**/api/v2/tracks/*/stream**', async (r) => r.fulfill({ response: await r.fetch() }));
  return ctx;
}

/** Session web du compte de démo dans un nouveau contexte. */
async function signedIn(/** @type {any} */ options) {
  const ctx = await context(options);
  const page = await ctx.newPage();
  await page.goto(`${BASE}/`);
  await page.locator('input[type=email]').fill(EMAIL);
  await page.locator('input[type=password]').fill(PASSWORD);
  await page.locator('button[type=submit]').click();
  await page.locator('.album-grid, .albums').first().waitFor();
  return { ctx, page };
}

for (const lang of ['fr', 'en']) {
  await api(ADMIN, 'PATCH', '/admin/server-settings', { language: lang });
  for (const theme of ['dark', 'light']) {
    const locale = lang === 'fr' ? 'fr-FR' : 'en-GB';
    // Ordinateur
    {
      const { ctx, page } = await signedIn({ viewport: { width: 1440, height: 900 }, colorScheme: theme, locale });
      await open(page, '/', '.album-grid, .albums');
      await shot(page, lang, 'albums', theme);
      await open(page, `/albums/${featured.id}`, '.tracklist');
      await shot(page, lang, 'album', theme);
      await open(page, '/genres', 'main h1');
      await shot(page, lang, 'explore', theme);
      await open(page, `/albums/${second.id}`, '.tracklist');
      await page.locator('.queue-btn').click();
      await page.locator('.queue-panel, aside').first().waitFor();
      await shot(page, lang, 'queue', theme);
      await open(page, '/reglages', 'main h2');
      await page.locator('main h2').nth(1).scrollIntoViewIfNeeded();
      await page.evaluate(() => window.scrollBy(0, -80));
      await shot(page, lang, 'settings', theme);
      await open(page, `/admin/lots/${proposal.id}`, '.admin p.meta');
      await shot(page, lang, 'proposals', theme);
      await open(page, '/historique', '.history-list');
      await shot(page, lang, 'history', theme);
      await ctx.close();
    }
    // Téléphone
    {
      const phone = { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, colorScheme: theme, locale };
      const { ctx, page } = await signedIn(phone);
      await open(page, '/genres', 'main h1');
      await shot(page, lang, 'explore-phone', theme);
      await open(page, '/', '.np-expand');
      await page.locator('.np-expand').click();
      await page.locator('.player-bar.expanded').waitFor();
      await shot(page, lang, 'player-phone', theme);
      await ctx.close();
      // Invité de la télécommande : le flux temps réel est simulé (la démo n'a pas de hub)
      const guestCtx = await context(phone);
      await guestCtx.route('**/.well-known/mercure**', (r) => r.fulfill({ status: 200, headers: { 'Content-Type': 'text/event-stream' }, body: ': ok\n\n' }));
      const guest = await guestCtx.newPage();
      await player();
      await guest.goto(share.url);
      await guest.locator('.remote-now strong').waitFor();
      await shot(guest, lang, 'remote-phone', theme);
      await guestCtx.close();
    }
  }
}
await browser.close();
await api(ADMIN, 'PATCH', '/admin/server-settings', { language: 'fr' });

// Image d'aperçu pour les partages (accueil, sombre, anglais)
execFileSync('ffmpeg', ['-v', 'error', '-y', '-i', join(tmp, 'en-albums-dark.png'), '-vf', 'scale=1200:-1,crop=1200:630:0:0', join(out, 'apercu.png')]);
rmSync(tmp, { recursive: true, force: true });

console.log('Captures prêtes');
