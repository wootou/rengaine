// @ts-check
// Journal de diagnostic du son (réglage propre à l'appareil) : pour comprendre des coupures qu'on ne
// reproduit pas ailleurs (Firefox Android). Note ce qui peut faire sauter le son :
//   - les événements des deux platines (attente de données, reprise, suspension du téléchargement…) ;
//   - un timeupdate en retard ou une position figée pendant la lecture ;
//   - le fil principal bloqué (minuteur de 100 ms en retard) ;
//   - les changements de page et d'onglet, pour situer les coupures.
// Rien ne part au serveur : le journal se copie depuis les Réglages.
import { Store } from './store.js';

const KEY = 'rengaine.diagSon';
const MAX_LINES = 1500;
const DECK_EVENTS = ['loadstart', 'canplay', 'playing', 'pause', 'waiting', 'stalled', 'suspend', 'seeking', 'seeked', 'ended', 'error', 'emptied'];

/** @type {Store<{ enabled: boolean, lines: string[] }>} */
export const diag = new Store({ enabled: readEnabled(), lines: /** @type {string[]} */ ([]) });

function readEnabled() {
  try {
    return localStorage.getItem(KEY) === '1';
  } catch {
    return false;
  }
}

const t0 = typeof performance !== 'undefined' ? performance.now() : 0;
/** @type {string[]} */
let buffer = [];
let flushQueued = false;

/** Ajoute une ligne (si le journal est actif). @param {string} msg */
export function note(msg) {
  if (!diag.getState().enabled) return;
  const s = ((performance.now() - t0) / 1000).toFixed(2).padStart(8);
  buffer.push(`${new Date().toLocaleTimeString('fr-FR')} ${s}  ${msg}`);
  if (buffer.length > MAX_LINES) buffer = buffer.slice(-MAX_LINES);
  // L'affichage suit au plus une fois par seconde : le journal ne doit pas charger la page lui-même
  if (!flushQueued) {
    flushQueued = true;
    setTimeout(() => {
      flushQueued = false;
      diag.set({ lines: buffer.slice() });
    }, 1000);
  }
}

export function clearDiag() {
  buffer = [];
  diag.set({ lines: [] });
}

/** @type {(() => void) | null} */
let stopProbes = null;

/**
 * Branche les sondes sur les platines du lecteur.
 * @param {{ decks: Array<{ audio: HTMLAudioElement, trackId: number | null }>, active: number }} player
 */
function startProbes(player) {
  if (stopProbes) return;
  /** @type {Array<() => void>} */
  const undo = [];
  /** @param {EventTarget} target @param {string} type @param {EventListener} fn */
  const on = (target, type, fn) => {
    target.addEventListener(type, fn);
    undo.push(() => target.removeEventListener(type, fn));
  };
  for (const [i, d] of player.decks.entries()) {
    const a = d.audio;
    const name = () => `platine ${i}${i === player.active ? '*' : ' '} piste ${d.trackId ?? '-'}`;
    const ahead = () => {
      const b = a.buffered;
      for (let k = 0; k < b.length; k++) {
        if (a.currentTime >= b.start(k) && a.currentTime <= b.end(k)) return `${(b.end(k) - a.currentTime).toFixed(1)} s d'avance`;
      }
      return 'rien d’avance';
    };
    for (const ev of DECK_EVENTS) {
      on(a, ev, () => note(`${name()} ${ev.padEnd(9)} pos ${a.currentTime.toFixed(2)} s, ${ahead()}${ev === 'error' && a.error ? ` (code ${a.error.code})` : ''}`));
    }
    let last = 0;
    let lastPos = 0;
    on(a, 'timeupdate', () => {
      const now = performance.now();
      if (!a.paused && last) {
        if (now - last > 600) note(`${name()} timeupdate en retard : ${Math.round(now - last)} ms (${ahead()})`);
        else if (a.currentTime - lastPos < 0.05 && now - last > 200) note(`${name()} position figée à ${a.currentTime.toFixed(2)} s (${ahead()})`);
      }
      last = a.paused ? 0 : now;
      lastPos = a.currentTime;
    });
  }
  let tick = performance.now();
  const timer = setInterval(() => {
    const now = performance.now();
    if (now - tick > 250) note(`fil principal bloqué ${Math.round(now - tick - 100)} ms`);
    tick = now;
  }, 100);
  undo.push(() => clearInterval(timer));
  on(document, 'visibilitychange', () => note(`onglet ${document.visibilityState === 'visible' ? 'visible' : 'caché'}`));
  note(`journal démarré ; ${navigator.userAgent}`);
  stopProbes = () => {
    undo.forEach((fn) => fn());
    stopProbes = null;
  };
}

/** @param {Parameters<typeof startProbes>[0]} player */
export function initDiag(player) {
  if (diag.getState().enabled) startProbes(player);
}

/** @param {boolean} on @param {Parameters<typeof startProbes>[0]} player */
export function setDiag(on, player) {
  if (!on) note('journal arrêté');
  try {
    if (on) localStorage.setItem(KEY, '1');
    else localStorage.removeItem(KEY);
  } catch {
    // stockage indisponible : actif jusqu'au rechargement
  }
  diag.set({ enabled: on });
  if (on) startProbes(player);
  else stopProbes?.();
}
