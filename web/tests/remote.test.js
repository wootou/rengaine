// @ts-check
// Télécommande partagée (FUT-02) : lecture du flux du hub, commandes des invités appliquées par le lecteur.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Controller } from '../src/engine/controller.js';
import { Player } from '../src/engine/player.js';
import { currentItem } from '../src/engine/queue.js';
import { applyCommand, deviceKey, RemoteLink } from '../src/engine/remote.js';
import { session } from '../src/engine/session.js';
import { parseSse } from '../src/engine/sse.js';
import { track } from './fixtures.js';

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
}

/** @type {{ method: string, url: string, body: any }[]} */
let calls;
/** @type {Controller[]} */
const controllers = [];

beforeEach(() => {
  calls = [];
  vi.stubGlobal('fetch', vi.fn(async (/** @type {string} */ url, /** @type {RequestInit} */ init) => {
    calls.push({ method: init.method ?? 'GET', url: String(url), body: init.body ? JSON.parse(String(init.body)) : null });
    return new Response(null, { status: 204 });
  }));
  session.set({ status: 'ready', settings: null });
});

afterEach(() => {
  controllers.splice(0).forEach((c) => c.dispose());
  vi.unstubAllGlobals();
});

function setup() {
  const c = new Controller(new Player(() => /** @type {any} */ (new FakeAudio())));
  controllers.push(c);
  c.restored = true;
  return c;
}

/** @param {Controller} c */
const titles = (c) => c.state.queue.items.map((it) => it.track.title);

describe('flux du hub (SSE)', () => {
  it('découpe les messages, garde la fin incomplète, ignore les commentaires', () => {
    const r = parseSse(':\n\nid: 1\ndata: {"type":"changed"}\n\nid: 2\r\ndata: {"type":\r\ndata: "revoked"}\r\n\r\ndata: {"ty');
    expect(r.messages).toEqual(['{"type":"changed"}', '{"type":\n"revoked"}']);
    expect(r.rest).toBe('data: {"ty');
    expect(parseSse(r.rest + 'pe":"shares"}\n\n').messages).toEqual(['{"type":"shares"}']);
  });
});

describe('commandes des invités', () => {
  it('ajoute ensuite ou à la fin, sans couper la lecture', () => {
    const c = setup();
    c.playTracks([track(1), track(2)], 0);
    const playing = c.player.currentTrackId;
    expect(applyCommand(c, { type: 'add', where: 'next' }, [track(5), track(6)])).toMatch(/2 piste/);
    expect(applyCommand(c, { type: 'add', where: 'end' }, [track(7), track(8, { available: false })])).toMatch(/1 piste/);
    expect(titles(c)).toEqual(['Piste 1', 'Piste 5', 'Piste 6', 'Piste 2', 'Piste 7']);
    expect(c.player.currentTrackId).toBe(playing);
    expect(applyCommand(c, { type: 'add', where: 'end' }, [])).toBeNull();
  });

  it('déplace et retire seulement si la piste est toujours à cette place', () => {
    const c = setup();
    c.playTracks([track(1), track(2), track(3), track(4)], 0);
    expect(applyCommand(c, { type: 'move', from: 3, to: 1, trackId: 4, where: 'end' })).toMatch(/déplacée/);
    expect(titles(c)).toEqual(['Piste 1', 'Piste 4', 'Piste 2', 'Piste 3']);
    // File changée entre-temps : la piste n'est plus là, rien ne bouge
    expect(applyCommand(c, { type: 'move', from: 3, to: 1, trackId: 4, where: 'end' })).toBeNull();
    expect(applyCommand(c, { type: 'remove', index: 2, trackId: 3, where: 'end' })).toBeNull();
    expect(applyCommand(c, { type: 'remove', index: 2, trackId: 2, where: 'end' })).toMatch(/retirée/);
    expect(titles(c)).toEqual(['Piste 1', 'Piste 4', 'Piste 3']);
    // Déplacement au-delà de la fin : en dernier
    applyCommand(c, { type: 'move', from: 1, to: 99, trackId: 4, where: 'end' });
    expect(titles(c)).toEqual(['Piste 1', 'Piste 3', 'Piste 4']);
  });

  it('lire maintenant : une piste de la file sans la déplacer, ou une piste ajoutée', () => {
    const c = setup();
    c.playTracks([track(1), track(2), track(3), track(4)], 0);
    expect(applyCommand(c, { type: 'jump', index: 2, trackId: 2, where: 'end' })).toBeNull(); // piste plus à cette place
    expect(applyCommand(c, { type: 'jump', index: 2, trackId: 3, where: 'end' })).toMatch(/lancée/);
    expect(titles(c)).toEqual(['Piste 1', 'Piste 2', 'Piste 3', 'Piste 4']);
    expect(currentItem(c.state.queue)?.track.id).toBe(3);
    c.togglePlay();
    expect(c.state.playing).toBe(false);
    expect(applyCommand(c, { type: 'add', where: 'now' }, [track(9)])).toMatch(/lancée/);
    expect(titles(c)).toEqual(['Piste 1', 'Piste 2', 'Piste 3', 'Piste 9', 'Piste 4']);
    expect(currentItem(c.state.queue)?.track.id).toBe(9);
    expect(c.state.playing).toBe(true);
    // File vide : la piste devient la piste en cours
    const d = setup();
    applyCommand(d, { type: 'add', where: 'now' }, [track(7)]);
    expect(currentItem(d.state.queue)?.track.id).toBe(7);
  });

  it('pause, lecture et suivante', () => {
    const c = setup();
    expect(applyCommand(c, { type: 'play', where: 'end' })).toBeNull(); // file vide
    c.playTracks([track(1), track(2)], 0);
    expect(c.state.playing).toBe(true);
    expect(applyCommand(c, { type: 'play', where: 'end' })).toBeNull(); // joue déjà
    expect(applyCommand(c, { type: 'pause', where: 'end' })).toBe('pause');
    expect(c.state.playing).toBe(false);
    expect(applyCommand(c, { type: 'pause', where: 'end' })).toBeNull();
    expect(applyCommand(c, { type: 'play', where: 'end' })).toBe('lecture');
    expect(c.state.playing).toBe(true);
    expect(applyCommand(c, { type: 'next', where: 'end' })).toBe('piste suivante');
    expect(currentItem(c.state.queue)?.track.id).toBe(2);
  });

  it('volume : réglé à distance, le son revient s’il était coupé, et l’état le donne', async () => {
    const c = setup();
    c.playTracks([track(1)], 0);
    c.setVolume(0.8, true); // son coupé
    const link = new RemoteLink(c);
    link.setActive(true);
    await new Promise((r) => setTimeout(r, 0));
    const reports = () => calls.filter((x) => x.method === 'PUT' && x.url.endsWith('/me/player')).map((x) => x.body);
    expect(reports().at(-1)).toMatchObject({ volume: 0 });
    expect(applyCommand(c, { type: 'volume', volume: 140, where: 'end' })).toBe('volume 100 %');
    expect(applyCommand(c, { type: 'volume', where: 'end' })).toBeNull();
    link.onEvent({ type: 'command', target: link.key, by: 'Paul', command: { type: 'volume', volume: 35, where: 'end' } });
    expect(c.state.volume).toBe(0.35);
    expect(c.state.muted).toBe(false);
    expect(link.store.getState().lastAction).toBe('Paul : volume 35 %');
    await vi.waitFor(() => expect(reports().at(-1)).toMatchObject({ volume: 35 }));
    // Volume changé sur le lecteur lui-même : un changement de volume seul suffit à prévenir les invités
    const before = reports().length;
    await link.report(false);
    expect(reports().length).toBe(before); // rien n'a changé
    c.setVolume(0.5);
    await link.report(false);
    expect(reports().at(-1)).toMatchObject({ volume: 50 });
  });

  it('seul le lecteur visé applique, puis envoie la file et son état', async () => {
    const c = setup();
    c.playTracks([track(1), track(2)], 0);
    const link = new RemoteLink(c);
    link.setActive(true);
    await new Promise((r) => setTimeout(r, 0));
    calls = [];
    link.onEvent({ type: 'command', target: 'un-autre-lecteur', by: 'Paul', command: { type: 'pause', where: 'end' } });
    expect(c.state.playing).toBe(true);
    link.onEvent({ type: 'command', target: link.key, by: 'Paul', command: { type: 'pause', where: 'end' } });
    expect(c.state.playing).toBe(false);
    expect(link.store.getState().lastAction).toBe('Paul : pause');
    await new Promise((r) => setTimeout(r, 0));
    const report = calls.find((x) => x.method === 'PUT' && x.url.endsWith('/me/player'));
    expect(report?.body).toMatchObject({ deviceKey: link.key, trackId: 1, playing: false });
  });

  it('télécommande désactivée par l’admin : le lecteur se désabonne', () => {
    const c = setup();
    const link = new RemoteLink(c);
    link.setActive(true);
    link.sub = /** @type {any} */ ({ stop: vi.fn() });
    const sub = link.sub;
    link.onEvent({ type: 'disabled' });
    expect(sub?.stop).toHaveBeenCalled();
    expect(link.sub).toBeNull();
    expect(link.sharesActive).toBe(false);
  });

  it('sans lien valable, le lecteur ne donne pas de nouvelles', async () => {
    const c = setup();
    c.playTracks([track(1)], 0);
    const link = new RemoteLink(c);
    await link.report(true);
    expect(calls.some((x) => x.url.endsWith('/me/player'))).toBe(false);
  });

  it('identifiant de lecteur stable pour l’onglet', () => {
    const k = deviceKey();
    expect(k).toMatch(/^[0-9a-f]{24}$/);
    expect(deviceKey()).toBe(k);
  });
});
