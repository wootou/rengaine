// @ts-check
// Lecteur audio : deux « platines » (éléments <audio>), pour précharger la piste suivante et faire
// le fondu enchaîné. Le gain de normalisation passe par Web Audio (un GainNode par platine), qui
// sait aussi monter le volume ; sans Web Audio (tests, vieux navigateur), repli sur audio.volume.
// Le volume de l'utilisateur (curseur du lecteur, 0 à 1) multiplie ce gain : un dernier GainNode
// commun aux deux platines, que le fondu enchaîné ne touche pas.
// Fondu calé sur le tempo (LEC-06) : la suivante part à la vitesse qui suit le tempo de la première
// (hauteur conservée), sa phase est recalée tous les SYNC_TICK_MS pendant le fondu, puis elle revient
// doucement à sa vitesse normale.
// Ce module ne sait rien de la file : il joue ce qu'on lui donne et signale la fin d'une piste.
// Flux converti (stream.js) : tant que la conversion n'est pas en cache, on ne peut pas avancer dans
// le fichier ; on redemande la piste à partir de la position voulue, et `offsetMs` garde ce décalage.

import { note } from './diag.js';
import { dbToLinear, glideRate, phaseError, syncedRate } from './playback.js';

/** @typedef {import('./playback.js').BeatSync} BeatSync */
/** Rampe de audio.volume (sans Web Audio). @typedef {{ timer: ReturnType<typeof setInterval>, from: number, target: number, start: number, ms: number }} Ramp */

export const SYNC_TICK_MS = 100;

/**
 * « Son simplifié » (réglage propre à l'appareil) : pas de Web Audio, seulement l'élément <audio>.
 * Pour un navigateur dont le son saute avec Web Audio. Le gain de normalisation ne peut alors que
 * baisser le volume, et les fondus se font par une rampe de audio.volume.
 * Actif d'office sur Firefox Android : avec Web Audio, le son y coupe, surtout écran verrouillé
 * (constaté le 2026-10-23 sur un OnePlus Nord 4, Firefox 158). Un choix fait dans les Réglages l'emporte.
 */
const SIMPLE_AUDIO_KEY = 'rengaine.sonSimple';

/** Firefox sur Android (pas un navigateur qui se dit « Gecko » pour se faire passer pour lui). @param {string} [ua] */
export function isFirefoxAndroid(ua = typeof navigator === 'undefined' ? '' : navigator.userAgent) {
  return /Android/.test(ua) && /Firefox\//.test(ua) && !/Chrome\//.test(ua);
}

export function simpleAudio() {
  try {
    const v = localStorage.getItem(SIMPLE_AUDIO_KEY);
    if (v === '1') return true;
    if (v === '0') return false;
  } catch {
    // stockage indisponible : choix par défaut
  }
  return isFirefoxAndroid();
}

/** Choix explicite de l'utilisateur, retenu par cet appareil. @param {boolean} on */
export function setSimpleAudio(on) {
  try {
    localStorage.setItem(SIMPLE_AUDIO_KEY, on ? '1' : '0');
  } catch {
    // stockage du navigateur indisponible : réglage non retenu
  }
}
const RAMP_STEP_MS = 50;

/** @typedef {{ id: number, streamUrl: string, formats?: Array<{ format: string, lossless: boolean, bitrateKbps?: number | null }> }} Playable */
/** @typedef {(track: Playable, startMs: number) => { url: string, transcoded: boolean }} SourceFor */
/**
 * @typedef {{ audio: HTMLAudioElement, gain: GainNode | null, trackId: number | null, gainDb: number,
 *   track: Playable | null, transcoded: boolean, offsetMs: number, ramp: Ramp | null }} Deck
 */

export class Player extends EventTarget {
  /** @param {() => HTMLAudioElement} [createAudio] */
  constructor(createAudio = () => new Audio()) {
    super();
    /** @type {Deck[]} */
    this.decks = [0, 1].map(() => {
      const audio = createAudio();
      audio.preload = 'auto';
      return { audio, gain: null, trackId: null, gainDb: 0, track: null, transcoded: false, offsetMs: 0, ramp: null };
    });
    /** Adresse du flux : le fichier tel quel, sauf si le contrôleur choisit une conversion. @type {SourceFor} */
    this.sourceFor = (track) => ({ url: track.streamUrl, transcoded: false });
    this.active = 0;
    /** @type {AudioContext | null} */
    this.ctx = null;
    /** @type {GainNode | null} */
    this.master = null;
    this.volume = 1;
    this.fading = false;
    /** @type {ReturnType<typeof setTimeout> | null} */
    this.fadeTimer = null;
    /** Recalage de phase puis retour à la vitesse normale. @type {ReturnType<typeof setInterval> | null} */
    this.syncTimer = null;
    for (const [i, d] of this.decks.entries()) {
      d.audio.addEventListener('timeupdate', () => {
        // Écran verrouillé, les minuteurs ne passent plus qu'une fois par seconde ; la lecture, elle, donne
        // encore des nouvelles quatre fois par seconde : les rampes de volume avancent aussi d'ici
        for (const x of this.decks) if (x.ramp) Player.stepRamp(x);
        if (i === this.active) this.emit('time');
      });
      d.audio.addEventListener('ended', () => i === this.active && !this.fading && this.emit('ended'));
      d.audio.addEventListener('playing', () => i === this.active && this.emit('state'));
      d.audio.addEventListener('pause', () => i === this.active && this.emit('state'));
      d.audio.addEventListener('waiting', () => i === this.active && this.emit('state'));
      d.audio.addEventListener('durationchange', () => i === this.active && this.emit('time'));
      d.audio.addEventListener('error', () => {
        if (i === this.active && d.audio.src) this.emit('error', { trackId: d.trackId });
      });
    }
  }

  /** @param {string} type @param {any} [detail] */
  emit(type, detail) {
    this.dispatchEvent(new CustomEvent(type, { detail }));
  }

  /** Web Audio n'existe qu'après un geste de l'utilisateur : on le crée au premier « lecture ». */
  ensureAudioGraph() {
    if (this.ctx || typeof window === 'undefined' || !('AudioContext' in window) || simpleAudio()) return;
    try {
      // Tampons « lecture » : plus grands que ceux du défaut (« interactive », faible latence) ; avec ceux-là,
      // Firefox Android coupait le son par moments quand le téléphone était occupé (défilement, pages)
      this.ctx = new AudioContext({ latencyHint: 'playback' });
      this.master = this.ctx.createGain();
      this.master.gain.value = this.volume;
      this.master.connect(this.ctx.destination);
      for (const d of this.decks) {
        const src = this.ctx.createMediaElementSource(d.audio);
        d.gain = this.ctx.createGain();
        src.connect(d.gain).connect(this.master);
        d.audio.volume = 1;
        this.applyGain(d, d.gainDb);
      }
    } catch (e) {
      console.warn('Web Audio indisponible, volume simple', e);
      this.ctx = null;
      this.master = null;
    }
    if (this.ctx?.state === 'suspended') void this.ctx.resume();
  }

  /** @param {Deck} d @param {number} db @param {number} [rampMs] */
  applyGain(d, db, rampMs = 0) {
    d.gainDb = db;
    const lin = dbToLinear(db);
    if (d.gain && this.ctx) {
      const now = this.ctx.currentTime;
      d.gain.gain.cancelScheduledValues(now);
      if (rampMs > 0) {
        d.gain.gain.setValueAtTime(d.gain.gain.value, now);
        d.gain.gain.linearRampToValueAtTime(lin, now + rampMs / 1000);
      } else {
        d.gain.gain.setValueAtTime(lin, now);
      }
    } else {
      // Sans Web Audio : audio.volume, en rampe par petits pas pour un fondu (le volume suit le temps écoulé)
      if (d.ramp) clearInterval(d.ramp.timer);
      d.ramp = null;
      const target = Math.min(1, lin * this.volume);
      if (rampMs <= 0) {
        d.audio.volume = target;
        return;
      }
      const ramp = { timer: setInterval(() => Player.stepRamp(d), RAMP_STEP_MS), from: d.audio.volume, target, start: Date.now(), ms: rampMs };
      d.ramp = ramp;
    }
  }

  /** Avance la rampe de volume d'une platine selon le temps écoulé. @param {Deck} d */
  static stepRamp(d) {
    const r = d.ramp;
    if (!r) return;
    const t = Math.min(1, (Date.now() - r.start) / r.ms);
    d.audio.volume = Math.max(0, Math.min(1, r.from + (r.target - r.from) * t));
    if (t >= 1) {
      clearInterval(r.timer);
      d.ramp = null;
    }
  }

  /** Volume de l'utilisateur, multiplié au gain de normalisation. @param {number} v entre 0 et 1 */
  setVolume(v) {
    this.volume = Math.max(0, Math.min(1, v));
    if (this.master && this.ctx) {
      const now = this.ctx.currentTime;
      this.master.gain.cancelScheduledValues(now);
      this.master.gain.setValueAtTime(this.master.gain.value, now);
      this.master.gain.linearRampToValueAtTime(this.volume, now + 0.05);
    } else {
      for (const d of this.decks) d.audio.volume = Math.min(1, dbToLinear(d.gainDb) * this.volume);
    }
  }

  get deck() {
    return this.decks[this.active];
  }

  get idle() {
    return this.decks[1 - this.active];
  }

  get positionMs() {
    return this.deck.offsetMs + this.deck.audio.currentTime * 1000;
  }

  /** 0 quand le fichier ne la donne pas (flux converti) : le contrôleur prend alors celle de la piste. */
  get durationMs() {
    if (this.deck.transcoded) return 0;
    const d = this.deck.audio.duration;
    return Number.isFinite(d) ? d * 1000 : 0;
  }

  get paused() {
    return this.deck.audio.paused;
  }

  get currentTrackId() {
    return this.deck.trackId;
  }

  /** @param {Deck} d @param {Playable} track @param {number} startMs @param {number} gainDb */
  setSource(d, track, startMs, gainDb) {
    const src = this.sourceFor(track, startMs);
    d.trackId = track.id;
    d.track = track;
    d.transcoded = src.transcoded;
    d.offsetMs = src.transcoded ? startMs : 0; // le flux converti commence déjà à startMs
    d.audio.src = src.url;
    Player.setRate(d.audio, 1);
    this.applyGain(d, gainDb);
    if (startMs > 0 && !src.transcoded) {
      const seek = () => {
        d.audio.currentTime = startMs / 1000;
      };
      if (d.audio.readyState >= 1) seek();
      else d.audio.addEventListener('loadedmetadata', seek, { once: true });
    }
  }

  /**
   * Joue une piste tout de suite sur la platine active (l'autre est arrêtée).
   * @param {Playable} track @param {{ startMs?: number, gainDb?: number, autoplay?: boolean }} [o]
   */
  load(track, o = {}) {
    this.cancelFade();
    this.idle.audio.pause();
    this.idle.trackId = null;
    this.setSource(this.deck, track, o.startMs ?? 0, o.gainDb ?? 0);
    if (o.autoplay !== false) this.play();
    this.emit('state');
  }

  /** Précharge la suivante sur la platine libre. @param {Playable} track @param {number} startMs @param {number} gainDb */
  prepare(track, startMs, gainDb) {
    if (this.idle.trackId === track.id) return;
    this.setSource(this.idle, track, startMs, gainDb);
    this.idle.audio.load();
  }

  /** La platine libre a-t-elle cette piste prête ? @param {number} trackId */
  isPrepared(trackId) {
    return this.idle.trackId === trackId;
  }

  /** Bascule sans fondu sur la piste préchargée (fin de piste). */
  switchToPrepared() {
    const old = this.deck;
    this.active = 1 - this.active;
    old.audio.pause();
    old.trackId = null;
    this.play();
    this.emit('state');
  }

  /** Position d'une platine dans son fichier, en ms. @param {Deck} d */
  static position(d) {
    return d.offsetMs + d.audio.currentTime * 1000;
  }

  /** @param {HTMLAudioElement} audio @param {number} rate */
  static setRate(audio, rate) {
    if (Math.abs(audio.playbackRate - rate) < 0.0001) return; // plus fin que le pas de glideToNormal
    audio.preservesPitch = true; // la hauteur ne change pas (vrai par défaut, mais on s'en assure)
    audio.playbackRate = rate;
    note(`vitesse ${rate.toFixed(4)}`);
  }

  /**
   * Fondu enchaîné vers la piste préchargée ; calé sur le tempo si `sync` est donné.
   * @param {number} ms @param {BeatSync | null} [sync]
   */
  crossfade(ms, sync = null) {
    if (this.fading) return;
    this.fading = true;
    const old = this.deck;
    const next = this.idle;
    const target = next.gainDb;
    this.applyGain(next, -60);
    this.active = 1 - this.active;
    if (sync) Player.setRate(next.audio, sync.rate);
    this.play();
    if (sync) this.startSync(old, next, sync, ms);
    this.applyGain(next, target, ms);
    this.applyGain(old, -60, ms);
    this.fadeTimer = setTimeout(() => {
      old.audio.pause();
      old.trackId = null;
      this.applyGain(old, 0);
      this.fading = false;
      this.fadeTimer = null;
    }, ms);
    this.emit('state');
  }

  /**
   * Pendant le fondu : vitesse corrigée pour rattraper l'écart de phase. Ensuite : retour progressif à 1.
   * @param {Deck} old @param {Deck} next @param {BeatSync} sync @param {number} ms
   */
  startSync(old, next, sync, ms) {
    this.stopSync(false);
    const end = Date.now() + ms;
    /** @type {{ from: number, start: number } | null} */
    let glide = null;
    this.syncTimer = setInterval(() => {
      const a = next.audio;
      if (a.paused) return;
      if (Date.now() < end && this.fading && !old.audio.paused) {
        // Page cachée (écran verrouillé) : un pas par seconde ferait osciller la correction ; on garde le tempo seul
        const hidden = typeof document !== 'undefined' && document.hidden;
        Player.setRate(a, hidden ? sync.rate : syncedRate(sync, phaseError(Player.position(old), Player.position(next), sync)));
        return;
      }
      glide ??= { from: a.playbackRate, start: Date.now() };
      const rate = glideRate(glide.from, Date.now() - glide.start);
      Player.setRate(a, rate);
      if (rate === 1) this.stopSync(false);
    }, SYNC_TICK_MS);
  }

  /** @param {boolean} reset vitesse normale tout de suite (geste de l'utilisateur) */
  stopSync(reset) {
    if (this.syncTimer) {
      clearInterval(this.syncTimer);
      this.syncTimer = null;
    }
    if (reset) for (const d of this.decks) Player.setRate(d.audio, 1);
  }

  cancelFade() {
    this.stopSync(true);
    if (this.fadeTimer) {
      clearTimeout(this.fadeTimer);
      this.fadeTimer = null;
    }
    if (this.fading) {
      this.idle.audio.pause();
      this.idle.trackId = null;
      this.fading = false;
    }
  }

  play() {
    this.ensureAudioGraph();
    const p = this.deck.audio.play();
    if (p) p.catch((e) => e?.name !== 'AbortError' && console.warn('Lecture refusée :', e?.message));
  }

  pause() {
    this.cancelFade();
    this.deck.audio.pause();
  }

  /** @param {number} ms */
  seek(ms) {
    this.cancelFade();
    const d = this.deck;
    const target = Math.max(0, ms);
    if (d.transcoded && d.track && !Player.canSeekTo(d.audio, (target - d.offsetMs) / 1000)) {
      // Hors de ce que le flux converti permet : on le redemande à cette position
      const playing = !d.audio.paused;
      const src = this.sourceFor(d.track, target);
      d.transcoded = src.transcoded;
      d.offsetMs = src.transcoded ? target : 0;
      d.audio.src = src.url;
      if (!src.transcoded) d.audio.currentTime = target / 1000;
      if (playing) this.play();
    } else {
      d.audio.currentTime = (target - d.offsetMs) / 1000;
    }
    this.emit('time');
  }

  /**
   * La position (en s, dans le flux chargé) est-elle atteignable sans recharger ? Pendant une conversion
   * (durée inconnue), Chromium se dit capable d'aller partout, mais seul ce qui est reçu est sûr.
   * @param {HTMLAudioElement} audio @param {number} s
   */
  static canSeekTo(audio, s) {
    if (s < 0) return false;
    const r = Number.isFinite(audio.duration) ? audio.seekable : audio.buffered;
    if (!r) return false;
    for (let i = 0; i < r.length; i++) if (s >= r.start(i) && s <= r.end(i)) return true;
    return false;
  }

  /** Change le gain de la piste en cours (réglages modifiés). @param {number} db */
  setGain(db) {
    this.applyGain(this.deck, db, 200);
  }

  stop() {
    this.cancelFade();
    for (const d of this.decks) {
      d.audio.pause();
      d.audio.removeAttribute('src');
      d.trackId = null;
      d.track = null;
      d.transcoded = false;
      d.offsetMs = 0;
    }
    this.emit('state');
  }
}
