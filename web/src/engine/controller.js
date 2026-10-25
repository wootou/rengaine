// @ts-check
// Chef d'orchestre du lecteur, hors de React :
//   file d'attente (queue.js) + lecteur (player.js) + décisions (playback.js),
//   synchronisation de la file avec le serveur (on la retrouve sur un autre appareil, WEB-04),
//   mode infini par genre (WEB-05), historique d'écoute, Media Session (écran verrouillé).

import { ApiError, etagVersion, get, imageUrl, request } from '../api/client.js';
import { Player } from './player.js';
import { RemoteLink } from './remote.js';
import * as Q from './queue.js';
import { beatTransition, gainDb, useAlbumGain } from './playback.js';
import { canPlayOpus, connectionKind, streamSource } from './stream.js';
import { Store } from './store.js';
import { session, updateSettings } from './session.js';
import { tr } from '../i18n/index.js';

/** @typedef {{ mood?: import('../api/types.js').Schemas['MoodId'], yearFrom?: number, yearTo?: number, collection?: number }} InfiniteFilter */
/** @typedef {import('../api/types.js').Track} Track */
/** @typedef {import('./queue.js').Queue} Queue */
/**
 * @typedef {{
 *   queue: Queue, playing: boolean, loading: boolean, positionMs: number, durationMs: number,
 *   infinite: { enabled: boolean, genreIds: number[], filter: InfiniteFilter, loading: boolean }, error: string | null,
 *   volume: number, muted: boolean
 * }} PlayerState
 */

const PREPARE_AHEAD_MS = 15000; // préchargement de la suivante
// Fondu calé sur le tempo : à l'approche du départ, un minuteur le lance au bon moment (timeupdate ne
// passe que toutes les 250 ms environ, un demi-temps à 120 BPM).
const FADE_TIMER_MS = 600;
const INFINITE_THRESHOLD = 3; // pistes restantes sous lesquelles on complète la file
const INFINITE_BATCH = 20;
const INFINITE_NO_REPEAT = 10; // genre épuisé : pistes récentes qu'on ne rejoue pas tout de suite
const SYNC_DELAY_MS = 1500; // regroupe les changements de file avant envoi
const POSITION_SYNC_MS = 30000;
// Volume du curseur : propre à chaque appareil (téléphone et ordinateur n'ont pas le même besoin),
// donc gardé dans le navigateur et pas dans les réglages du compte.
const VOLUME_KEY = 'musique.volume';

/** @returns {{ volume: number, muted: boolean }} */
function loadVolume() {
  try {
    const v = JSON.parse(localStorage.getItem(VOLUME_KEY) ?? 'null');
    if (v && typeof v.volume === 'number' && v.volume >= 0 && v.volume <= 1) return { volume: v.volume, muted: v.muted === true };
  } catch {
    // stockage indisponible ou illisible : volume plein
  }
  return { volume: 1, muted: false };
}

export class Controller {
  /** @param {Player} [player] */
  constructor(player = new Player()) {
    this.player = player;
    /** @type {Store<PlayerState>} */
    this.store = new Store(/** @type {PlayerState} */ ({
      queue: Q.emptyQueue(), playing: false, loading: false, positionMs: 0, durationMs: 0,
      infinite: { enabled: false, genreIds: [], filter: {}, loading: false }, error: null,
      ...loadVolume(),
    }));
    player.setVolume(this.state.muted ? 0 : this.state.volume);
    player.sourceFor = (track, startMs) => streamSource(track, startMs, this.streamOptions());
    this.serverVersion = 0;
    /** @type {ReturnType<typeof setTimeout> | null} */
    this.syncTimer = null;
    this.lastPositionSync = 0;
    this.restored = false;
    /** @type {Promise<void> | null} */
    this.restoring = null;
    /** @type {{ trackId: number, startedAt: string, playedMs: number, lastPos: number } | null} */
    this.listening = null;
    /** @type {import('../api/types.js').PlayInput[]} */
    this.pendingPlays = [];
    this.transitionStarted = false;
    /** Départ du fondu calé sur le tempo. @type {ReturnType<typeof setTimeout> | null} */
    this.fadeTimer = null;

    player.addEventListener('time', () => this.onTime());
    player.addEventListener('ended', () => this.onEnded());
    player.addEventListener('state', () => this.store.set({ playing: !player.paused, loading: player.deck.audio.readyState < 3 && !player.paused }));
    player.addEventListener('error', () => {
      this.store.set({ error: tr('Lecture impossible de cette piste : passage à la suivante') });
      this.next(false);
    });
    this.setupMediaSession();
    // Le mode infini est un réglage du compte (le même dans l'appli) : il suit les réglages chargés
    this.unsubscribe = session.subscribe(() => this.followInfiniteSetting());
  }

  /** Plus d'abonnement aux réglages (tests : un contrôleur par test). */
  dispose() {
    this.unsubscribe();
  }

  /** Réglages chargés ou changés ailleurs : le mode infini prend leur valeur. */
  followInfiniteSetting() {
    const enabled = session.getState().settings?.infinite?.enabled;
    if (typeof enabled !== 'boolean' || enabled === this.state.infinite.enabled) return;
    this.store.set({ infinite: { ...this.state.infinite, enabled } });
    if (enabled) void this.extendIfNeeded();
  }

  /** Enregistre le mode infini dans les réglages du compte. @param {boolean} enabled */
  saveInfiniteSetting(enabled) {
    const settings = session.getState().settings;
    if (!settings?.infinite || settings.infinite.enabled === enabled) return;
    updateSettings((s) => ({ ...s, infinite: { ...s.infinite, enabled } })).catch((e) => console.warn('Réglage du mode infini :', e));
  }

  get state() {
    return this.store.getState();
  }

  get settings() {
    return session.getState().settings?.playback ?? null;
  }

  /** Qualité du flux : réglage wifi ou données mobiles, si le serveur sait convertir. */
  streamOptions() {
    const { server } = session.getState();
    const quality = this.settings?.streamQuality?.[connectionKind()] ?? 'original';
    return { quality, transcoding: !!server?.features?.transcoding, opus: canPlayOpus() };
  }

  // ---------------------------------------------------------------- actions

  /**
   * Volume du curseur (0 à 1), multiplié au gain de normalisation. Le monter démute.
   * @param {number} volume @param {boolean} [muted]
   */
  setVolume(volume, muted = false) {
    const v = Math.max(0, Math.min(1, Math.round(volume * 100) / 100));
    this.store.set({ volume: v, muted });
    this.player.setVolume(muted ? 0 : v);
    try {
      localStorage.setItem(VOLUME_KEY, JSON.stringify({ volume: v, muted }));
    } catch {
      // navigation privée : le volume vaut pour la session seulement
    }
  }

  toggleMute() {
    this.setVolume(this.state.volume, !this.state.muted);
  }

  /** Écouter ces pistes à partir de `start` (la file est remplacée). @param {Track[]} tracks @param {number} [start] */
  playTracks(tracks, start = 0) {
    const playable = tracks.filter((t) => t.available);
    const first = tracks[start];
    const idx = Math.max(0, first ? playable.indexOf(first) : 0);
    this.setQueue(Q.replace(this.state.queue, playable, idx), { reload: true });
  }

  /** @param {Track[]} tracks */
  enqueue(tracks) {
    const wasEmpty = this.state.queue.items.length === 0;
    this.setQueue(Q.append(this.state.queue, tracks.filter((t) => t.available)), { reload: wasEmpty });
  }

  /** @param {Track[]} tracks */
  playNext(tracks) {
    const wasEmpty = this.state.queue.items.length === 0;
    this.setQueue(Q.playNext(this.state.queue, tracks.filter((t) => t.available)), { reload: wasEmpty });
  }

  togglePlay() {
    const cur = Q.currentItem(this.state.queue);
    if (!cur) return;
    if (this.player.currentTrackId !== cur.track.id) {
      this.loadCurrent(true);
    } else if (this.player.paused) {
      this.player.play();
    } else {
      this.player.pause();
      this.syncPosition(true);
    }
  }

  /** @param {boolean} [manual] */
  next(manual = true) {
    const it = Q.nextItem(this.state.queue, { manual });
    this.finishListening(!manual);
    if (!it) {
      this.player.stop();
      this.store.set({ playing: false });
      return;
    }
    this.setQueue(Q.jumpTo(this.state.queue, it.key), { reload: true });
  }

  previous() {
    // Comme partout : au-delà de 3 s, « précédent » revient au début de la piste
    if (this.player.positionMs > 3000) {
      this.player.seek(0);
      return;
    }
    const it = Q.previousItem(this.state.queue);
    if (it) {
      this.finishListening(false);
      this.setQueue(Q.jumpTo(this.state.queue, it.key), { reload: true });
    } else {
      this.player.seek(0);
    }
  }

  /** @param {number} ms */
  seek(ms) {
    this.clearFadeTimer();
    this.player.seek(ms);
    this.transitionStarted = false;
  }

  clearFadeTimer() {
    if (this.fadeTimer) clearTimeout(this.fadeTimer);
    this.fadeTimer = null;
  }

  /** Lance le fondu vers `it`, déjà préchargée. @param {Q.QueueItem} it @param {ReturnType<typeof beatTransition>} plan */
  startFade(it, plan) {
    this.clearFadeTimer();
    this.transitionStarted = true;
    this.player.crossfade(plan.crossfadeMs, plan.sync);
    this.advanceTo(it, plan.nextStartMs);
  }

  /** @param {string} key */
  jumpTo(key) {
    this.finishListening(false);
    this.setQueue(Q.jumpTo(this.state.queue, key), { reload: true });
  }

  /** @param {string} key */
  remove(key) {
    const wasCurrent = key === this.state.queue.currentKey;
    this.setQueue(Q.remove(this.state.queue, key), { reload: wasCurrent && !this.player.paused });
  }

  /** @param {number} from @param {number} to */
  move(from, to) {
    this.setQueue(Q.move(this.state.queue, from, to));
  }

  clearUpcoming() {
    this.setQueue(Q.clearUpcoming(this.state.queue));
  }

  /** @param {import('../api/types.js').QueueMode} mode */
  setMode(mode) {
    this.setQueue(Q.setMode(this.state.queue, mode));
  }

  /**
   * Mode infini : la file se complète toute seule, par genre (vide = tous) et, au besoin, dans une
   * ambiance, une décennie ou une collection (CAT-08).
   * @param {boolean} enabled @param {number[]} [genreIds] @param {InfiniteFilter} [filter]
   */
  setInfinite(enabled, genreIds = [], filter = {}) {
    this.store.set({ infinite: { ...this.state.infinite, enabled, genreIds, filter } });
    this.saveInfiniteSetting(enabled);
    if (enabled) void this.extendIfNeeded();
  }

  /** Démarre une écoute aléatoire infinie (page Genres). @param {number[]} genreIds @param {InfiniteFilter} [filter] */
  async startInfinite(genreIds, filter = {}) {
    this.store.set({ infinite: { enabled: true, genreIds, filter, loading: true } });
    this.saveInfiniteSetting(true);
    try {
      const r = await get('/random/tracks', { query: { genre: genreIds, count: INFINITE_BATCH, ...filter } });
      this.playTracks(/** @type {{ items: Track[] }} */ (r).items, 0);
    } finally {
      this.store.set({ infinite: { ...this.state.infinite, loading: false } });
    }
  }

  /** Déconnexion : plus rien de ce compte ne reste dans le lecteur. */
  reset() {
    if (this.syncTimer) clearTimeout(this.syncTimer);
    this.syncTimer = null;
    this.player.stop();
    this.restoring = null;
    this.restored = false;
    this.serverVersion = 0;
    this.listening = null;
    this.pendingPlays = [];
    this.store.set({ queue: Q.emptyQueue(), playing: false, positionMs: 0, durationMs: 0, infinite: { enabled: false, genreIds: [], filter: {}, loading: false }, error: null });
  }

  /** Réglages changés : le gain de la piste en cours suit. */
  refreshGain() {
    const cur = Q.currentItem(this.state.queue);
    if (cur && this.player.currentTrackId === cur.track.id) this.player.setGain(this.gainFor(cur.track));
  }

  // ---------------------------------------------------------------- file

  /** @param {Queue} queue @param {{ reload?: boolean }} [o] */
  setQueue(queue, o = {}) {
    this.store.set({ queue, error: null });
    this.transitionStarted = false;
    if (o.reload) this.loadCurrent(true);
    this.scheduleSync();
    void this.extendIfNeeded();
    this.updateMediaMetadata();
  }

  /** @param {Track} track */
  gainFor(track) {
    const s = this.settings;
    if (!s) return 0;
    const q = this.state.queue;
    const i = q.items.findIndex((it) => it.track.id === track.id);
    return gainDb(track, s, useAlbumGain(s.normalization, track, q.items[i - 1]?.track, q.items[i + 1]?.track));
  }

  /** @param {boolean} autoplay @param {number} [startMs] */
  loadCurrent(autoplay, startMs = 0) {
    // Une autre liste lancée en pleine écoute : la piste quittée compte (non terminée, passée)
    this.finishListening(false);
    const cur = Q.currentItem(this.state.queue);
    if (!cur) {
      this.player.stop();
      return;
    }
    this.player.load(cur.track, { startMs, gainDb: this.gainFor(cur.track), autoplay });
    this.startListening(cur.track, startMs);
    this.store.set({ positionMs: startMs, durationMs: cur.track.durationMs });
  }

  onTime() {
    const pos = this.player.positionMs;
    this.store.set({ positionMs: pos, durationMs: this.player.durationMs || (Q.currentItem(this.state.queue)?.track.durationMs ?? 0) });
    if (this.listening) {
      const delta = pos - this.listening.lastPos;
      if (delta > 0 && delta < 2000) this.listening.playedMs += delta;
      this.listening.lastPos = pos;
    }
    if (!this.player.paused && Date.now() - this.lastPositionSync > POSITION_SYNC_MS) this.syncPosition(false);

    const s = this.settings;
    const cur = Q.currentItem(this.state.queue);
    const nextIt = Q.nextItem(this.state.queue);
    if (!s || !cur || !nextIt || this.transitionStarted) return;
    const plan = beatTransition(cur.track, nextIt.track, s);
    // Préchargement de la suivante, un peu avant la fin (ou le fondu)
    if (pos >= plan.fadeStartMs - PREPARE_AHEAD_MS && (s.gapless || plan.crossfadeMs > 0) && nextIt.track.id !== cur.track.id) {
      this.player.prepare(nextIt.track, plan.nextStartMs, this.gainFor(nextIt.track));
    }
    if (plan.crossfadeMs > 0 && this.player.isPrepared(nextIt.track.id)) {
      if (pos >= plan.fadeStartMs) {
        this.startFade(nextIt, plan);
      } else if (plan.sync && !this.fadeTimer && plan.fadeStartMs - pos <= FADE_TIMER_MS) {
        // Départ précis : sur un temps de la piste en cours
        const keys = [cur.key, nextIt.key];
        const rate = this.player.deck.audio.playbackRate || 1;
        this.fadeTimer = setTimeout(() => {
          this.fadeTimer = null;
          const c = Q.currentItem(this.state.queue);
          const n = Q.nextItem(this.state.queue);
          if (this.transitionStarted || this.player.paused || c?.key !== keys[0] || n?.key !== keys[1] || !this.player.isPrepared(n.track.id)) return;
          this.startFade(n, plan);
        }, Math.max(0, (plan.fadeStartMs - pos) / rate));
      }
    }
  }

  onEnded() {
    const nextIt = Q.nextItem(this.state.queue);
    if (nextIt && nextIt.track.id !== Q.currentItem(this.state.queue)?.track.id && this.player.isPrepared(nextIt.track.id)) {
      // Piste suivante déjà chargée : enchaînement sans attente
      this.player.switchToPrepared();
      this.advanceTo(nextIt, 0);
      return;
    }
    this.next(false);
  }

  /** La platine joue déjà `it` : on avance la file sans recharger. @param {Q.QueueItem} it @param {number} startMs */
  advanceTo(it, startMs) {
    this.finishListening(true);
    this.store.set({ queue: Q.jumpTo(this.state.queue, it.key), positionMs: startMs, durationMs: it.track.durationMs });
    this.startListening(it.track, startMs);
    this.transitionStarted = false;
    this.scheduleSync();
    void this.extendIfNeeded();
    this.updateMediaMetadata();
  }

  /** La file se complète par la lecture continue intelligente (réglage du compte, et le serveur sait la faire). */
  smartContinuation() {
    const { settings, server } = session.getState();
    return Boolean(settings?.continuous?.enabled && server?.features.continuousPlay);
  }

  async extendIfNeeded() {
    const inf = this.state.infinite;
    const q = this.state.queue;
    if (!inf.enabled || inf.loading || q.items.length === 0 || Q.remaining(q) >= INFINITE_THRESHOLD) return;
    this.store.set({ infinite: { ...inf, loading: true } });
    try {
      // Genres choisis, sinon ceux de la piste en cours (sauf écoute d'une ambiance, d'une décennie
      // ou d'une collection : là, tous les genres)
      const chosen = inf.genreIds.length > 0;
      const filtered = Object.keys(inf.filter).length > 0;
      const genres = chosen ? inf.genreIds : filtered ? [] : (Q.currentItem(q)?.track.genres.map((g) => g.id) ?? []);
      const ids = q.items.map((it) => it.track.id);
      // Lecture continue intelligente (LEC-03) : le serveur choisit des pistes qui s'enchaînent
      // avec la dernière de la file (clé, tempo, favoris, pas de répétition, artistes espacés).
      // En cas d'échec, ou s'il ne propose rien, le tirage au hasard prend le relais.
      if (this.smartContinuation()) {
        try {
          const r = await request('POST', '/playback/next', {
            body: {
              count: INFINITE_BATCH,
              currentTrackId: ids[ids.length - 1] ?? null,
              recentTrackIds: ids.slice(-200),
              ...(chosen || filtered ? { overrides: { ...(chosen ? { genres: inf.genreIds } : {}), ...inf.filter } } : {}),
            },
          });
          const smart = /** @type {{ items: Track[] }} */ (r.data).items;
          if (smart.length > 0) {
            this.setQueue(Q.append(this.state.queue, smart));
            return;
          }
        } catch (e) {
          console.warn('Lecture continue intelligente :', e);
        }
      }
      /** @param {number[]} genre @param {number[]} exclude */
      const draw = async (genre, exclude) => /** @type {{ items: Track[] }} */ (
        await get('/random/tracks', { query: { genre, count: INFINITE_BATCH, exclude, ...inf.filter } })).items;
      // Un genre peut s'épuiser (toutes ses pistes sont déjà dans la file) : on élargit plutôt que
      // de s'arrêter en silence. D'abord toute la bibliothèque (si le genre venait de la piste en
      // cours), puis on accepte de rejouer des pistes, sauf les plus récentes, puis n'importe lesquelles.
      let items = await draw(genres, ids.slice(-300));
      if (items.length === 0 && !chosen && genres.length > 0) items = await draw([], ids.slice(-300));
      const pool = chosen ? genres : [];
      if (items.length === 0) items = await draw(pool, ids.slice(-INFINITE_NO_REPEAT));
      if (items.length === 0) items = await draw(pool, []);
      if (items.length > 0) this.setQueue(Q.append(this.state.queue, items));
    } catch (e) {
      console.warn('Mode infini :', e);
    } finally {
      this.store.set({ infinite: { ...this.state.infinite, loading: false } });
    }
  }

  // ---------------------------------------------------------------- serveur

  /** Reprend la file enregistrée (autre appareil, ou rechargement de la page), sans lancer la lecture. */
  async restore() {
    // Un seul rechargement, même si l'interface le demande deux fois (mode strict de React)
    this.restoring ??= this.doRestore();
    return this.restoring;
  }

  async doRestore() {
    const before = this.state.queue;
    try {
      const r = await request('GET', '/me/queue');
      const p = /** @type {import('../api/types.js').PlayQueue} */ (r.data);
      this.serverVersion = etagVersion(r.etag) || p.version;
      if (this.state.queue !== before) {
        // L'utilisateur a lancé une écoute pendant la demande : son choix l'emporte, et part au serveur
        this.restored = true;
        this.scheduleSync();
        return;
      }
      const queue = Q.fromServer(p);
      this.store.set({ queue, positionMs: p.positionMs });
      const cur = Q.currentItem(queue);
      if (cur) {
        this.player.load(cur.track, { startMs: p.positionMs, gainDb: this.gainFor(cur.track), autoplay: false });
        this.store.set({ durationMs: cur.track.durationMs });
      }
      this.updateMediaMetadata();
    } catch (e) {
      console.warn('File non reprise :', e);
    }
    this.restored = true;
  }

  scheduleSync() {
    if (!this.restored) return;
    if (this.syncTimer) clearTimeout(this.syncTimer);
    this.syncTimer = setTimeout(() => void this.pushQueue(), SYNC_DELAY_MS);
  }

  /** @param {boolean} now */
  syncPosition(now) {
    this.lastPositionSync = Date.now();
    if (now) void this.pushQueue();
    else this.scheduleSync();
  }

  async pushQueue() {
    this.syncTimer = null;
    const body = Q.toServer(this.state.queue, this.player.positionMs);
    for (let attempt = 0; attempt < 2; attempt++) {
      try {
        const r = await request('PUT', '/me/queue', { body, ifMatch: this.serverVersion });
        this.serverVersion = etagVersion(r.etag) || this.serverVersion + 1;
        return;
      } catch (e) {
        if (e instanceof ApiError && e.status === 412 && e.details.current) {
          // Un autre appareil a écrit : c'est ici qu'on écoute, notre file l'emporte
          this.serverVersion = e.details.current.version;
          continue;
        }
        console.warn('File non enregistrée :', e);
        return;
      }
    }
  }

  // ---------------------------------------------------------------- historique

  /** @param {Track} track @param {number} startMs */
  startListening(track, startMs) {
    this.listening = { trackId: track.id, startedAt: new Date().toISOString(), playedMs: 0, lastPos: startMs };
  }

  /** @param {boolean} completed */
  finishListening(completed) {
    const l = this.listening;
    this.listening = null;
    if (!l || l.playedMs < 5000) return; // un passage éclair ne compte pas
    this.pendingPlays.push({ trackId: l.trackId, startedAt: l.startedAt, playedMs: Math.round(l.playedMs), completed, skipped: !completed, context: 'queue' });
    void this.flushPlays();
  }

  async flushPlays() {
    const plays = this.pendingPlays.splice(0, 500);
    if (plays.length === 0) return;
    try {
      await request('POST', '/me/history', { body: { plays } });
    } catch {
      this.pendingPlays.unshift(...plays); // renvoyées au prochain coup
    }
  }

  // ---------------------------------------------------------------- écran verrouillé

  setupMediaSession() {
    if (typeof navigator === 'undefined' || !('mediaSession' in navigator)) return;
    const ms = navigator.mediaSession;
    /** @type {[MediaSessionAction, MediaSessionActionHandler][]} */
    const handlers = [
      ['play', () => this.togglePlay()],
      ['pause', () => this.togglePlay()],
      ['nexttrack', () => this.next(true)],
      ['previoustrack', () => this.previous()],
      ['seekto', (d) => d.seekTime !== undefined && this.seek(d.seekTime * 1000)],
    ];
    for (const [action, fn] of handlers) {
      try {
        ms.setActionHandler(action, fn);
      } catch {
        // action non gérée par ce navigateur
      }
    }
  }

  updateMediaMetadata() {
    if (typeof navigator === 'undefined' || !('mediaSession' in navigator) || typeof MediaMetadata === 'undefined') return;
    const cur = Q.currentItem(this.state.queue);
    document.title = cur ? `${cur.track.title} — ${cur.track.artistCredit}` : (session.getState().server?.name ?? 'Rengaine');
    if (!cur) return;
    const art = (/** @type {'s'|'m'|'l'} */ size, /** @type {string} */ px) => {
      const src = imageUrl(cur.track.coverUrl, size);
      return src ? [{ src, sizes: `${px}x${px}`, type: 'image/jpeg' }] : [];
    };
    navigator.mediaSession.metadata = new MediaMetadata({
      title: cur.track.title, artist: cur.track.artistCredit, album: cur.track.album.title,
      artwork: [...art('s', '96'), ...art('m', '300'), ...art('l', '600')],
    });
  }
}

/** Le lecteur de l'application (un seul). */
export const controller = new Controller();

/** Son lien avec la télécommande partagée (FUT-02), lancé une fois connecté. */
export const remote = new RemoteLink(controller);
