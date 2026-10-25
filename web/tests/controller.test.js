// @ts-check
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Controller } from '../src/engine/controller.js';
import { Player } from '../src/engine/player.js';
import { currentItem } from '../src/engine/queue.js';
import { session } from '../src/engine/session.js';
import { track } from './fixtures.js';

/** Élément <audio> simulé : on fait avancer le temps à la main. */
class FakeAudio extends EventTarget {
  constructor() {
    super();
    this.src = '';
    this.currentTime = 0;
    this.duration = 200;
    this.paused = true;
    this.readyState = 4;
    this.volume = 1;
    this.preload = '';
    this.playbackRate = 1;
    this.preservesPitch = true;
  }
  play() {
    this.paused = false;
    this.dispatchEvent(new Event('playing'));
    return Promise.resolve();
  }
  pause() {
    this.paused = true;
    this.dispatchEvent(new Event('pause'));
  }
  load() {}
  removeAttribute() {
    this.src = '';
  }
  /** @param {number} s */
  tick(s) {
    this.currentTime = s;
    this.dispatchEvent(new Event('timeupdate'));
  }
}

const SETTINGS = {
  playback: { normalization: /** @type {const} */ ('track'), targetLufs: -16, preventClipping: true, crossfadeMs: 0, crossfadeWithinAlbum: false, gapless: true, beatSync: true, streamQuality: { wifi: /** @type {const} */ ('original'), mobile: /** @type {const} */ ('original') } },
  continuous: { enabled: false, genres: [], excludedGenres: [], favoritesWeight: 0.3, harmonicMixing: false, bpmTolerancePercent: null, energyTrend: /** @type {const} */ ('any'), noRepeatHours: 24, artistSpacing: 3 },
  interface: { theme: /** @type {const} */ ('auto'), background: true },
  infinite: { enabled: false },
  random: { genreIds: [], excludedAlbumIds: [], count: 100 },
};

/** @type {{ method: string, url: string, headers: any, body: any }[]} */
let calls;
/** @type {(method: string, url: string, body: any) => { status: number, body: any, headers?: Record<string, string> }} */
let route;

beforeEach(() => {
  calls = [];
  route = () => ({ status: 204, body: null });
  vi.stubGlobal('fetch', vi.fn(async (/** @type {string} */ url, /** @type {RequestInit} */ init) => {
    const body = init.body ? JSON.parse(String(init.body)) : null;
    calls.push({ method: init.method ?? 'GET', url, headers: init.headers, body });
    const r = route(init.method ?? 'GET', url, body);
    return new Response(r.body === null ? null : JSON.stringify(r.body), { status: r.status, headers: { 'Content-Type': 'application/json', ...(r.headers ?? {}) } });
  }));
  session.set({ settings: structuredClone(SETTINGS), status: 'ready' });
});

/** @type {Controller[]} */
const controllers = [];

afterEach(() => {
  controllers.splice(0).forEach((c) => c.dispose());
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

function setup() {
  /** @type {FakeAudio[]} */
  const audios = [];
  const player = new Player(() => {
    const a = new FakeAudio();
    audios.push(a);
    return /** @type {any} */ (a);
  });
  const c = new Controller(player);
  controllers.push(c);
  const active = () => /** @type {FakeAudio} */ (/** @type {unknown} */ (player.deck.audio));
  return { c, player, audios, active };
}

const flush = () => new Promise((r) => setTimeout(r, 0));

describe('contrôleur du lecteur', () => {
  it('écoute une liste : la piste choisie démarre, avec son gain', () => {
    const { c, active } = setup();
    c.playTracks([track(1), track(2), track(3)], 1);
    expect(active().src).toBe('/api/v2/tracks/2/stream');
    expect(active().paused).toBe(false);
    expect(active().volume).toBeCloseTo(Math.pow(10, -6 / 20), 5); // -10 LUFS → -16 : -6 dB (sans Web Audio)
    expect(c.state.playing).toBe(true);
  });

  it('flux converti : avancer redemande la piste à la position voulue', () => {
    session.set({ server: /** @type {any} */ ({ features: { transcoding: true } }) });
    session.set({ settings: { ...structuredClone(SETTINGS), playback: { ...SETTINGS.playback, streamQuality: { wifi: 'medium', mobile: 'low' } } } });
    const { c, player, active } = setup();
    const t = { ...track(1), formats: [{ format: /** @type {const} */ ('flac'), lossless: true, bitrateKbps: 900, sizeBytes: 1 }] };
    c.playTracks([t, track(2)], 0);
    expect(active().src).toMatch(/^\/api\/v2\/tracks\/1\/stream\?format=(opus|mp3)&bitrate=(160|192)$/);
    expect(player.durationMs).toBe(0); // inconnue du flux : celle de la piste
    expect(c.state.durationMs).toBe(200000);

    c.seek(100000); // pas encore reçu : on redemande à partir de là
    expect(active().src).toMatch(/startMs=100000$/);
    active().tick(5);
    expect(c.state.positionMs).toBe(105000);
    expect(c.state.durationMs).toBe(200000);
    expect(active().paused).toBe(false);

    // Déjà reçu : on avance dans le flux, sans le redemander
    const src = active().src;
    /** @type {any} */ (active()).duration = Infinity; // flux en cours de conversion : seul le reçu compte
    /** @type {any} */ (active()).seekable = { length: 1, start: () => 0, end: () => Infinity };
    /** @type {any} */ (active()).buffered = { length: 1, start: () => 0, end: () => 30 };
    c.seek(120000);
    expect(active().src).toBe(src);
    expect(active().currentTime).toBe(20);

    // Une piste sans conversion utile (MP3) garde son adresse
    c.next();
    expect(active().src).toBe('/api/v2/tracks/2/stream');
    session.set({ server: null });
  });

  it('volume du curseur : multiplie le gain de normalisation, gardé dans le navigateur', () => {
    localStorage.removeItem('musique.volume');
    const { c, active, audios } = setup();
    expect(c.state.volume).toBe(1);
    c.playTracks([track(1), track(2)], 0);
    const norm = Math.pow(10, -6 / 20);
    c.setVolume(0.5);
    expect(active().volume).toBeCloseTo(norm * 0.5, 5);
    c.toggleMute();
    expect(c.state.muted).toBe(true);
    expect(audios.every((a) => a.volume === 0)).toBe(true);
    c.toggleMute();
    expect(active().volume).toBeCloseTo(norm * 0.5, 5);
    // la piste suivante garde le volume
    c.next(true);
    expect(active().volume).toBeCloseTo(norm * 0.5, 5);
    // un nouveau lecteur (rechargement de la page) le retrouve
    const again = setup();
    expect(again.c.state.volume).toBe(0.5);
    expect(again.c.state.muted).toBe(false);
    localStorage.removeItem('musique.volume');
  });

  it('les pistes indisponibles ne vont pas dans la file', () => {
    const { c } = setup();
    c.playTracks([track(1), track(2, { available: false }), track(3)], 2);
    expect(c.state.queue.items.map((i) => i.track.id)).toEqual([1, 3]);
    expect(currentItem(c.state.queue)?.track.id).toBe(3);
  });

  it('fin de piste : la suivante, préchargée, enchaîne sans attente', () => {
    const { c, player, active } = setup();
    c.playTracks([track(1), track(2)], 0);
    const first = active();
    first.tick(190); // 10 s avant la fin : préchargement
    expect(player.isPrepared(2)).toBe(true);
    first.dispatchEvent(new Event('ended'));
    expect(active()).not.toBe(first);
    expect(active().src).toBe('/api/v2/tracks/2/stream');
    expect(currentItem(c.state.queue)?.track.id).toBe(2);
  });

  it('fondu enchaîné : la suivante démarre après son silence, la file avance', () => {
    const { c, active } = setup();
    session.set({ settings: { ...SETTINGS, playback: { ...SETTINGS.playback, crossfadeMs: 6000 } } });
    c.playTracks([track(1), track(2, { album: { id: 2 } })], 0);
    const first = active();
    first.tick(180); // préchargement (fondu prévu à 189 s : fin du son 195 s - 6 s)
    first.tick(189.5);
    const second = active();
    expect(second).not.toBe(first);
    expect(second.paused).toBe(false);
    expect(second.currentTime).toBe(0.5); // audioStartMs de la suivante
    expect(currentItem(c.state.queue)?.track.id).toBe(2);
  });

  it('fondu calé sur le tempo : départ sur un temps, la suivante suit le tempo, se recale, puis revient à sa vitesse', () => {
    vi.useFakeTimers();
    try {
      const { c, active } = setup();
      session.set({ settings: { ...SETTINGS, playback: { ...SETTINGS.playback, crossfadeMs: 6000 } } });
      const a = track(1, { playback: { beatGrid: { intro: null, outro: { bpm: 120, offsetMs: 250 } } } });
      const b = track(2, { album: { id: 2 }, playback: { beatGrid: { intro: { bpm: 115, offsetMs: 100 }, outro: null } } });
      c.playTracks([a, b], 0);
      const first = active();
      first.tick(180); // préchargement
      first.tick(188.4); // 350 ms avant le départ (temps à 188,75 s) : un minuteur le lancera à l'heure
      expect(active()).toBe(first);
      vi.advanceTimersByTime(349);
      expect(active()).toBe(first);
      vi.advanceTimersByTime(1);
      const second = active();
      expect(second).not.toBe(first);
      expect(second.currentTime).toBeCloseTo(0.622, 3); // premier temps de la suivante après son silence
      expect(second.playbackRate).toBeCloseTo(120 / 115, 4);
      expect(currentItem(c.state.queue)?.track.id).toBe(2);
      // Les positions simulées n'avancent pas : la suivante est en retard d'un tiers de temps, elle accélère (3 % au plus)
      vi.advanceTimersByTime(100);
      expect(second.playbackRate).toBeCloseTo((120 / 115) * 1.03, 4);
      // Fondu fini : retour progressif à la vitesse normale
      vi.advanceTimersByTime(6000);
      const after = second.playbackRate;
      vi.advanceTimersByTime(1000);
      expect(second.playbackRate).toBeLessThan(after);
      expect(second.playbackRate).toBeGreaterThan(1);
      vi.advanceTimersByTime(20000);
      expect(second.playbackRate).toBe(1);
    } finally {
      vi.useRealTimers();
    }
  });

  it('fondu sans Web Audio (son simplifié) : les volumes passent en rampe, pas d’un coup', () => {
    vi.useFakeTimers();
    try {
      const { c, active } = setup();
      session.set({ settings: { ...SETTINGS, playback: { ...SETTINGS.playback, crossfadeMs: 6000 } } });
      c.playTracks([track(1), track(2, { album: { id: 2 } })], 0);
      const first = active();
      const start = first.volume;
      first.tick(180);
      first.tick(189.5);
      const second = active();
      expect(second.volume).toBeLessThan(0.01); // part du silence
      vi.advanceTimersByTime(3000);
      expect(second.volume).toBeGreaterThan(0.2);
      expect(first.volume).toBeLessThan(start * 0.7);
      expect(first.volume).toBeGreaterThan(0.05);
      vi.advanceTimersByTime(3100);
      expect(second.volume).toBeCloseTo(start, 5);
    } finally {
      vi.useRealTimers();
    }
  });

  it('écran verrouillé (minuteurs arrêtés) : le fondu sans Web Audio avance avec la lecture', () => {
    vi.useFakeTimers();
    try {
      const { c, active } = setup();
      session.set({ settings: { ...SETTINGS, playback: { ...SETTINGS.playback, crossfadeMs: 6000 } } });
      c.playTracks([track(1), track(2, { album: { id: 2 } })], 0);
      const first = active();
      const start = first.volume;
      first.tick(180);
      first.tick(189.5);
      const second = active();
      // Les minuteurs ne passent pas ; seul le temps avance, et la lecture donne des nouvelles (timeupdate)
      vi.setSystemTime(Date.now() + 3000);
      second.tick(3.5);
      expect(second.volume).toBeGreaterThan(0.2);
      expect(first.volume).toBeLessThan(start * 0.7);
      vi.setSystemTime(Date.now() + 3500);
      second.tick(7);
      expect(second.volume).toBeCloseTo(start, 5);
    } finally {
      vi.useRealTimers();
    }
  });

  it('fondu calé : un geste de l’utilisateur rend sa vitesse normale à la piste', () => {
    vi.useFakeTimers();
    try {
      const { c, active } = setup();
      session.set({ settings: { ...SETTINGS, playback: { ...SETTINGS.playback, crossfadeMs: 6000 } } });
      const a = track(1, { playback: { beatGrid: { intro: null, outro: { bpm: 120, offsetMs: 250 } } } });
      const b = track(2, { album: { id: 2 }, playback: { beatGrid: { intro: { bpm: 115, offsetMs: 100 }, outro: null } } });
      c.playTracks([a, b], 0);
      active().tick(180);
      active().tick(188.8);
      expect(active().playbackRate).not.toBe(1);
      c.seek(30000);
      expect(active().playbackRate).toBe(1);
    } finally {
      vi.useRealTimers();
    }
  });

  it('mode infini : la file se complète avec le genre de la piste en cours, sans répéter', async () => {
    const { c } = setup();
    route = (m, url) => (url.startsWith('/api/v2/random/tracks') ? { status: 200, body: { items: [track(10), track(11)] } } : { status: 204, body: null });
    c.playTracks([track(1, { genres: [{ id: 7, name: 'Rock', inherited: true }] }), track(2)], 0);
    c.setInfinite(true);
    await flush();
    const call = calls.find((x) => x.url.startsWith('/api/v2/random/tracks'));
    expect(call?.url).toContain('genre=7');
    expect(call?.url).toContain('exclude=1%2C2');
    expect(c.state.queue.items.map((i) => i.track.id)).toEqual([1, 2, 10, 11]);
  });

  it('mode infini : genre épuisé, la file se complète quand même (toute la bibliothèque, puis reprises)', async () => {
    const { c } = setup();
    route = (m, url) => {
      if (!url.startsWith('/api/v2/random/tracks')) return { status: 204, body: null };
      // Le genre 7 n'a que les pistes 1 et 2 (déjà dans la file) ; la bibliothèque, la piste 30 en plus
      const u = new URL(url, 'http://x');
      const genre = u.searchParams.get('genre');
      const exclude = (u.searchParams.get('exclude') ?? '').split(',').filter(Boolean).map(Number);
      const pool = genre === '7' ? [1, 2] : [1, 2, 30];
      return { status: 200, body: { items: pool.filter((id) => !exclude.includes(id)).map((id) => track(id)) } };
    };
    const rock = [{ id: 7, name: 'Rock', inherited: true }];
    c.playTracks([track(1, { genres: rock }), track(2, { genres: rock })], 0);
    c.setInfinite(true);
    await flush();
    expect(c.state.queue.items.map((i) => i.track.id)).toEqual([1, 2, 30]);

    // Genres choisis (page Genres) et tout est déjà passé : reprises, sauf les 10 dernières… ici toutes récentes
    const { c: c2 } = setup();
    c2.playTracks([track(1, { genres: rock }), track(2, { genres: rock })], 0);
    c2.setInfinite(true, [7]);
    await flush();
    expect(c2.state.queue.items.map((i) => i.track.id).slice(2).sort()).toEqual([1, 2]);
    expect(c2.state.infinite.loading).toBe(false);
  });

  it('lecture continue intelligente : le serveur choisit la suite de la dernière piste ; sinon, au hasard', async () => {
    const smart = { ...structuredClone(SETTINGS), continuous: { ...SETTINGS.continuous, enabled: true } };
    session.set({ settings: smart, server: /** @type {any} */ ({ features: { continuousPlay: true } }) });
    const { c } = setup();
    route = (m, url) => (m === 'POST' && url === '/api/v2/playback/next' ? { status: 200, body: { items: [track(20), track(21)] } } : { status: 204, body: null });
    c.playTracks([track(1), track(2)], 0);
    c.setInfinite(true, [7]);
    await flush();
    const post = calls.find((x) => x.url === '/api/v2/playback/next');
    expect(post?.body).toEqual({ count: 20, currentTrackId: 2, recentTrackIds: [1, 2], overrides: { genres: [7] } });
    expect(calls.some((x) => x.url.startsWith('/api/v2/random/tracks'))).toBe(false);
    expect(c.state.queue.items.map((i) => i.track.id)).toEqual([1, 2, 20, 21]);

    // Le serveur échoue : la file se complète quand même, au hasard
    const { c: c2 } = setup();
    route = (m, url) => {
      if (url === '/api/v2/playback/next') return { status: 500, body: { error: { code: 'internal', message: 'panne' } } };
      return url.startsWith('/api/v2/random/tracks') ? { status: 200, body: { items: [track(30)] } } : { status: 204, body: null };
    };
    c2.playTracks([track(1), track(2)], 0);
    c2.setInfinite(true);
    await flush();
    expect(c2.state.queue.items.map((i) => i.track.id)).toEqual([1, 2, 30]);

    // Serveur sans lecture continue : pas de demande
    session.set({ server: /** @type {any} */ ({ features: { continuousPlay: false } }) });
    calls.length = 0;
    const { c: c3 } = setup();
    route = (m, url) => (url.startsWith('/api/v2/random/tracks') ? { status: 200, body: { items: [track(40)] } } : { status: 204, body: null });
    c3.playTracks([track(1), track(2)], 0);
    c3.setInfinite(true);
    await flush();
    expect(calls.some((x) => x.url === '/api/v2/playback/next')).toBe(false);
    session.set({ server: null });
  });

  it('mode infini dans une ambiance (CAT-08) : le tirage et la lecture continue gardent le filtre', async () => {
    const { c } = setup();
    route = (m, url) => (url.startsWith('/api/v2/random/tracks') ? { status: 200, body: { items: [track(50), track(51)] } } : { status: 204, body: null });
    await c.startInfinite([], { mood: 'calme' });
    expect(calls.find((x) => x.url.startsWith('/api/v2/random/tracks'))?.url).toContain('mood=calme');
    expect(c.state.infinite.filter).toEqual({ mood: 'calme' });

    // Lecture intelligente : le filtre part dans les remplacements
    session.set({ settings: { ...structuredClone(SETTINGS), continuous: { ...SETTINGS.continuous, enabled: true } }, server: /** @type {any} */ ({ features: { continuousPlay: true } }) });
    calls.length = 0;
    route = (m, url) => (url === '/api/v2/playback/next' ? { status: 200, body: { items: [track(52)] } } : { status: 204, body: null });
    c.setInfinite(true, [], { mood: 'calme' }); // file de 2 pistes : elle se complète
    await flush();
    expect(calls.find((x) => x.url === '/api/v2/playback/next')?.body.overrides).toEqual({ mood: 'calme' });
    session.set({ server: null });
  });

  it('mode infini : réglage du compte, suivi au chargement et enregistré quand on le change', async () => {
    const { c } = setup();
    route = (m, url, body) => (m === 'PUT' && url === '/api/v2/me/settings'
      ? { status: 200, body: { settings: body, version: 4, updatedAt: '2026-10-19T08:00:00.000Z' }, headers: { ETag: '"4"' } }
      : { status: 200, body: { items: [] } });
    expect(c.state.infinite.enabled).toBe(false);
    // Réglages enregistrés depuis l'appli : le site suit
    session.set({ settings: { ...structuredClone(SETTINGS), infinite: { enabled: true } } });
    expect(c.state.infinite.enabled).toBe(true);
    // Bouton du lecteur : enregistré sur le serveur
    c.setInfinite(false);
    await flush();
    const put = calls.find((x) => x.method === 'PUT' && x.url === '/api/v2/me/settings');
    expect(put?.body.infinite).toEqual({ enabled: false });
    expect(session.getState().settings?.infinite.enabled).toBe(false);
    expect(c.state.infinite.enabled).toBe(false);
  });

  it('réglages : affichés aussitôt, remis comme avant si le serveur refuse', async () => {
    const { updateSettings } = await import('../src/engine/session.js');
    route = () => ({ status: 400, body: { error: { status: 400, code: 'bad_request', message: 'non', requestId: 'R' } } });
    const p = updateSettings((s) => ({ ...s, infinite: { enabled: true } }));
    expect(session.getState().settings?.infinite.enabled).toBe(true);
    await expect(p).rejects.toThrow('non');
    expect(session.getState().settings?.infinite.enabled).toBe(false);
  });

  it('réglages modifiés coup sur coup (curseur au clavier) : enregistrés dans l’ordre, la dernière valeur reste', async () => {
    const { updateSettings } = await import('../src/engine/session.js');
    // Serveur : refuse une version périmée (412), comme le vrai
    let version = 0;
    /** @type {any} */
    let stored = structuredClone(SETTINGS);
    route = (m, url, body) => {
      if (m !== 'PUT' || url !== '/api/v2/me/settings') return { status: 204, body: null };
      const ifMatch = calls[calls.length - 1].headers['If-Match'];
      if (ifMatch !== `"${version}"`) {
        return { status: 412, body: { error: { status: 412, code: 'precondition_failed', message: 'périmée', requestId: 'R', details: { current: { settings: stored, version } } } } };
      }
      version += 1;
      stored = body;
      return { status: 200, body: { settings: body, version, updatedAt: '2026-10-22T00:00:00.000Z' }, headers: { ETag: `"${version}"` } };
    };
    session.set({ settingsVersion: 0 });
    const saves = [1000, 2000, 3000, 4000, 5000, 6000].map((ms) => updateSettings((x) => ({ ...x, playback: { ...x.playback, crossfadeMs: ms } })));
    await Promise.all(saves);
    expect(calls.filter((x) => x.method === 'PUT').map((x) => x.body.playback.crossfadeMs)).toEqual([1000, 2000, 3000, 4000, 5000, 6000]);
    expect(stored.playback.crossfadeMs).toBe(6000);
    expect(session.getState().settings?.playback.crossfadeMs).toBe(6000);
    expect(session.getState().settingsVersion).toBe(6);
  });

  it('historique : une écoute de plus de 5 s est envoyée', async () => {
    const { c, active } = setup();
    c.playTracks([track(1), track(2)], 0);
    for (let s = 1; s <= 8; s++) active().tick(s);
    c.next(true);
    await flush();
    const h = calls.find((x) => x.url === '/api/v2/me/history');
    expect(h?.body.plays).toHaveLength(1);
    expect(h?.body.plays[0]).toMatchObject({ trackId: 1, skipped: true, completed: false });
    expect(h?.body.plays[0].playedMs).toBeGreaterThanOrEqual(7000);
  });

  it('historique : lancer une autre liste en pleine écoute compte la piste quittée, une seule fois', async () => {
    const { c, active } = setup();
    c.playTracks([track(1), track(2)], 0);
    for (let s = 1; s <= 8; s++) active().tick(s);
    c.playTracks([track(3)], 0);
    await flush();
    const plays = calls.filter((x) => x.url === '/api/v2/me/history').flatMap((x) => x.body.plays);
    expect(plays).toHaveLength(1);
    expect(plays[0]).toMatchObject({ trackId: 1, skipped: true, completed: false, context: 'queue' });
  });

  it('file enregistrée sur le serveur ; un autre appareil a écrit entre-temps : notre file l’emporte', async () => {
    vi.useFakeTimers();
    const { c } = setup();
    route = (m, url) => {
      if (m === 'GET' && url === '/api/v2/me/queue') return { status: 200, body: { trackIds: [], currentIndex: null, positionMs: 0, mode: 'normal', version: 2, updatedAt: '2026-10-19T08:00:00.000Z', tracks: [] }, headers: { ETag: '"2"' } };
      if (m === 'PUT' && calls.filter((x) => x.method === 'PUT').length === 1) {
        return { status: 412, body: { error: { status: 412, code: 'version_conflict', message: 'x', requestId: 'R', details: { current: { version: 5 } } } } };
      }
      return { status: 200, body: {}, headers: { ETag: '"6"' } };
    };
    await c.restore();
    c.playTracks([track(1), track(2)], 1);
    await vi.advanceTimersByTimeAsync(2000);
    const puts = calls.filter((x) => x.method === 'PUT');
    expect(puts.map((p) => p.headers['If-Match'])).toEqual(['"2"', '"5"']);
    expect(puts[1].body).toMatchObject({ trackIds: [1, 2], currentIndex: 1, mode: 'normal' });
    expect(c.serverVersion).toBe(6);
  });

  it('écoute lancée pendant la reprise de la file : le choix de l’utilisateur l’emporte, et part au serveur', async () => {
    vi.useFakeTimers();
    const { c, active } = setup();
    /** @type {(v: Response) => void} */
    let answer = () => {};
    const saved = { trackIds: [7], currentIndex: 0, positionMs: 196000, mode: 'normal', version: 3, updatedAt: '2026-10-22T08:00:00.000Z', tracks: [track(7)] };
    vi.stubGlobal('fetch', vi.fn(async (/** @type {string} */ url, /** @type {RequestInit} */ init) => {
      const body = init.body ? JSON.parse(String(init.body)) : null;
      calls.push({ method: init.method ?? 'GET', url, headers: init.headers, body });
      if (url === '/api/v2/me/queue' && (init.method ?? 'GET') === 'GET') {
        return new Promise((resolve) => { answer = resolve; }); // réponse lente
      }
      return new Response('{}', { status: 200, headers: { 'Content-Type': 'application/json', ETag: '"4"' } });
    }));
    const restoring = c.restore();
    c.playTracks([track(1), track(2)], 0);
    answer(new Response(JSON.stringify(saved), { status: 200, headers: { 'Content-Type': 'application/json', ETag: '"3"' } }));
    await restoring;
    expect(c.state.queue.items.map((i) => i.track.id)).toEqual([1, 2]);
    expect(active().src).toBe('/api/v2/tracks/1/stream');
    expect(active().paused).toBe(false);
    await vi.advanceTimersByTimeAsync(2000);
    const put = calls.find((x) => x.method === 'PUT' && x.url === '/api/v2/me/queue');
    expect(put?.headers['If-Match']).toBe('"3"');
    expect(put?.body).toMatchObject({ trackIds: [1, 2], currentIndex: 0 });
  });

  it('déconnexion : plus rien du compte précédent', () => {
    const { c, active } = setup();
    c.playTracks([track(1)], 0);
    c.reset();
    expect(c.state.queue.items).toEqual([]);
    expect(active().paused).toBe(true);
    expect(c.restoring).toBeNull();
  });
});
