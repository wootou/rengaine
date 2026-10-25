// @ts-check
// Télécommande partagée, côté lecteur (FUT-02). Tant que la session est ouverte et que le serveur a
// un hub temps réel, le lecteur écoute son canal :
//   - « command » : une commande d'invité, appliquée seulement par le lecteur visé (target), celui
//     qui joue. La file est enregistrée aussitôt, pour que les invités voient le résultat ;
//   - « shares » : un lien a été créé ou révoqué (ici ou sur un autre appareil).
// Tant qu'un lien est valable, le lecteur donne des nouvelles au serveur (piste, lecture ou pause, volume)
// à chaque changement et toutes les 30 s : c'est ce que voient les invités.

import { ApiError, get, request } from '../api/client.js';
import { note } from './diag.js';
import * as Q from './queue.js';
import { Store } from './store.js';
import { Subscription } from './sse.js';
import { tr, trn } from '../i18n/index.js';

/** @typedef {import('../api/types.js').RealtimeEvent} RealtimeEvent */
/** @typedef {import('../api/types.js').RemoteCommand} RemoteCommand */
/** @typedef {import('../api/types.js').Track} Track */

const DEVICE_KEY = 'rengaine.deviceKey';
const REPORT_EVERY_MS = 30000;

/** Identifiant de ce lecteur (onglet) : tiré au hasard, gardé le temps de l'onglet. */
export function deviceKey() {
  try {
    const k = sessionStorage.getItem(DEVICE_KEY);
    if (k && /^[A-Za-z0-9_-]{8,64}$/.test(k)) return k;
  } catch {
    // stockage indisponible : un identifiant pour la durée de la page
  }
  const bytes = crypto.getRandomValues(new Uint8Array(12));
  const k = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
  try {
    sessionStorage.setItem(DEVICE_KEY, k);
  } catch {
    // tant pis
  }
  return k;
}

/**
 * Applique une commande d'invité à la file du lecteur. Une commande dont la piste ne correspond
 * plus (file changée entre-temps) est ignorée. Fonction séparée pour les tests.
 * @param {import('./controller.js').Controller} c
 * @param {RemoteCommand} cmd
 * @param {Track[]} tracks pistes jointes à « add »
 * @returns {string | null} ce qui a été fait (pour le journal), null si ignorée
 */
export function applyCommand(c, cmd, tracks = []) {
  const q = c.state.queue;
  switch (cmd.type) {
    case 'add': {
      const ok = tracks.filter((t) => t.available);
      if (ok.length === 0) return null;
      if (cmd.where === 'now') {
        const was = q.items.length;
        c.playNext(ok);
        // File vide : la piste ajoutée est déjà la piste en cours (et se lance)
        const at = was === 0 ? null : c.state.queue.items[Q.currentIndex(c.state.queue) + 1];
        if (at) c.jumpTo(at.key);
        return tr('« {titre} » lancée', { titre: ok[0].title });
      }
      if (cmd.where === 'next') c.playNext(ok);
      else c.enqueue(ok);
      return cmd.where === 'next'
        ? trn(ok.length, '{n} piste ajoutée après la piste en cours', '{n} pistes ajoutées après la piste en cours')
        : trn(ok.length, '{n} piste ajoutée en fin de file', '{n} pistes ajoutées en fin de file');
    }
    case 'move': {
      const it = q.items[cmd.from ?? -1];
      if (!it || it.track.id !== cmd.trackId || cmd.to === undefined) return null;
      c.move(/** @type {number} */ (cmd.from), Math.min(cmd.to, q.items.length - 1));
      return tr('« {titre} » déplacée', { titre: it.track.title });
    }
    case 'remove': {
      const it = q.items[cmd.index ?? -1];
      if (!it || it.track.id !== cmd.trackId) return null;
      c.remove(it.key);
      return tr('« {titre} » retirée', { titre: it.track.title });
    }
    case 'jump': {
      const it = q.items[cmd.index ?? -1];
      if (!it || it.track.id !== cmd.trackId) return null;
      c.jumpTo(it.key);
      return tr('« {titre} » lancée', { titre: it.track.title });
    }
    case 'play':
      if (!Q.currentItem(q) || c.state.playing) return null;
      c.togglePlay();
      return tr('lecture');
    case 'pause':
      if (!c.state.playing) return null;
      c.togglePlay();
      return tr('pause');
    case 'next':
      if (!Q.currentItem(q)) return null;
      c.next(true);
      return tr('piste suivante');
    case 'volume': {
      if (typeof cmd.volume !== 'number') return null;
      const v = Math.max(0, Math.min(100, Math.round(cmd.volume)));
      c.setVolume(v / 100); // remet le son s'il était coupé
      return tr('volume {v} %', { v });
    }
    default:
      return null;
  }
}

export class RemoteLink {
  /** @param {import('./controller.js').Controller} controller */
  constructor(controller) {
    this.controller = controller;
    this.key = deviceKey();
    /** Ce que l'interface affiche : liens valables, dernière action d'un invité. */
    this.store = new Store({ sharesActive: false, lastAction: /** @type {string | null} */ (null) });
    /** @type {Subscription | null} */
    this.sub = null;
    /** @type {ReturnType<typeof setInterval> | null} */
    this.timer = null;
    /** @type {(() => void) | null} */
    this.unwatch = null;
    this.lastReport = '';
    this.reporting = false;
    this.again = false;
  }

  get sharesActive() {
    return this.store.getState().sharesActive;
  }

  start() {
    if (this.sub) return;
    this.sub = new Subscription(
      async () => {
        const ch = /** @type {import('../api/types.js').RealtimeChannel} */ (await get('/me/realtime', { quiet401: true }));
        this.setActive(ch.sharesActive === true);
        return ch;
      },
      (e) => this.onEvent(e),
      {
        onOpen: () => void this.report(true),
        // Plus de hub (503) ou plus de session (401) : on arrête d'essayer
        onFatal: (e) => e instanceof ApiError && (e.status === 401 || e.status === 503 || e.status === 501),
      },
    );
    this.sub.start();
    // Piste ou lecture changée : on prévient (au plus une fois par changement)
    this.unwatch = this.controller.store.subscribe(() => void this.report(false));
    this.timer = setInterval(() => void this.report(true), REPORT_EVERY_MS);
  }

  stop() {
    this.sub?.stop();
    this.sub = null;
    this.unwatch?.();
    this.unwatch = null;
    if (this.timer) clearInterval(this.timer);
    this.timer = null;
    this.store.set({ sharesActive: false, lastAction: null });
  }

  /** Un lien vient d'être créé ou révoqué depuis cet appareil. @param {boolean} active */
  setActive(active) {
    const was = this.sharesActive;
    this.store.set({ sharesActive: active });
    if (active && !was) void this.report(true);
  }

  /** @param {RealtimeEvent} e */
  onEvent(e) {
    if (e.type === 'disabled') {
      // L'admin a coupé la télécommande : plus d'abonnement jusqu'au prochain chargement
      this.stop();
      return;
    }
    if (e.type === 'shares') {
      void get('/me/realtime', { quiet401: true })
        .then((ch) => this.setActive(/** @type {any} */ (ch).sharesActive === true))
        .catch(() => {});
      return;
    }
    if (e.type !== 'command' || e.target !== this.key || !e.command) return;
    const done = applyCommand(this.controller, e.command, e.tracks ?? []);
    note(`télécommande (${e.by ?? 'invité'}) : ${e.command.type} → ${done ?? 'ignorée'}`);
    if (!done) {
      void this.report(true); // l'invité relit l'état et voit que rien n'a changé
      return;
    }
    this.store.set({ lastAction: tr('{qui} : {quoi}', { qui: e.by ?? tr('Un invité'), quoi: done }) });
    // La file part tout de suite (pas après le délai habituel) : les invités la voient changer
    if (this.controller.syncTimer) {
      clearTimeout(this.controller.syncTimer);
      void this.controller.pushQueue();
    }
    void this.report(true);
  }

  /** @param {boolean} force même si rien n'a changé (battement de cœur) */
  async report(force) {
    if (!this.sharesActive || !this.controller.restored) return;
    const s = this.controller.state;
    const cur = Q.currentItem(s.queue);
    const body = {
      deviceKey: this.key,
      trackId: cur?.track.id ?? null,
      playing: s.playing,
      positionMs: Math.max(0, Math.round(this.controller.player.positionMs || 0)),
      queueVersion: this.controller.serverVersion,
      volume: s.muted ? 0 : Math.round(s.volume * 100),
    };
    const sig = `${body.trackId}|${body.playing}|${body.queueVersion}|${body.volume}`;
    if (!force && sig === this.lastReport) return;
    if (this.reporting) {
      this.again = true; // renvoyé à la fin de l'envoi en cours, avec l'état d'alors
      return;
    }
    this.lastReport = sig;
    this.reporting = true;
    try {
      await request('PUT', '/me/player', { body, quiet401: true });
    } catch (e) {
      console.warn('Télécommande : état non envoyé', e);
    } finally {
      this.reporting = false;
    }
    if (this.again) {
      this.again = false;
      await this.report(true);
    }
  }
}
