// @ts-check
// File d'attente : ce que le lecteur lit vraiment. Elle est distincte de la liste consultée (une
// page d'album, des résultats de recherche) : on peut ajouter un album à la file sans couper la
// lecture, comme dans l'ancien lecteur.
//
// La piste en cours est repérée par la clé de son élément, jamais par sa position : réordonner
// ou retirer des pistes déplace les positions, la clé ne bouge pas (leçon de l'ancien lecteur,
// où l'index était recalculé depuis la source plutôt que lu dans un attribut).
// Fonctions pures : chaque opération renvoie une nouvelle file.

/** @typedef {import('../api/types.js').Track} Track */
/** @typedef {import('../api/types.js').QueueMode} QueueMode */
/** @typedef {{ key: string, track: Track }} QueueItem */
/** @typedef {{ items: QueueItem[], currentKey: string | null, mode: QueueMode }} Queue */

let counter = 0;
/** @returns {string} */
function newKey() {
  counter += 1;
  return `q${Date.now().toString(36)}${counter.toString(36)}`;
}

/** @param {Track[]} tracks @returns {QueueItem[]} */
function wrap(tracks) {
  return tracks.map((track) => ({ key: newKey(), track }));
}

/** @returns {Queue} */
export function emptyQueue() {
  return { items: [], currentKey: null, mode: 'normal' };
}

/** Position de la piste en cours, ou -1. @param {Queue} q */
export function currentIndex(q) {
  return q.currentKey === null ? -1 : q.items.findIndex((i) => i.key === q.currentKey);
}

/** @param {Queue} q */
export function currentItem(q) {
  const i = currentIndex(q);
  return i < 0 ? null : q.items[i];
}

/** Remplace la file (« écouter cet album à partir de cette piste »). @param {Queue} q @param {Track[]} tracks @param {number} start @returns {Queue} */
export function replace(q, tracks, start = 0) {
  const items = wrap(tracks);
  return { ...q, items, currentKey: items[Math.min(Math.max(start, 0), items.length - 1)]?.key ?? null };
}

/** Ajoute à la fin, sans toucher à la lecture. @param {Queue} q @param {Track[]} tracks @returns {Queue} */
export function append(q, tracks) {
  const items = [...q.items, ...wrap(tracks)];
  return { ...q, items, currentKey: q.currentKey ?? items[0]?.key ?? null };
}

/** Insère juste après la piste en cours (« lire ensuite »). @param {Queue} q @param {Track[]} tracks @returns {Queue} */
export function playNext(q, tracks) {
  const i = currentIndex(q);
  const items = [...q.items];
  items.splice(i + 1, 0, ...wrap(tracks));
  return { ...q, items, currentKey: q.currentKey ?? items[0]?.key ?? null };
}

/**
 * Retire un élément. Si c'était la piste en cours, la suivante devient la piste en cours.
 * @param {Queue} q @param {string} key
 * @returns {Queue}
 */
export function remove(q, key) {
  const i = q.items.findIndex((it) => it.key === key);
  if (i < 0) return q;
  const items = q.items.filter((it) => it.key !== key);
  let currentKey = q.currentKey;
  if (key === q.currentKey) {
    currentKey = items[Math.min(i, items.length - 1)]?.key ?? null;
  }
  return { ...q, items, currentKey };
}

/** Déplace un élément ; la piste en cours reste la même. @param {Queue} q @param {number} from @param {number} to @returns {Queue} */
export function move(q, from, to) {
  if (from === to || from < 0 || from >= q.items.length) return q;
  const items = [...q.items];
  const [it] = items.splice(from, 1);
  items.splice(Math.max(0, Math.min(to, items.length)), 0, it);
  return { ...q, items };
}

/** Vide la file, sauf la piste en cours. @param {Queue} q @returns {Queue} */
export function clearUpcoming(q) {
  const cur = currentItem(q);
  return { ...q, items: cur ? [cur] : [], currentKey: cur?.key ?? null };
}

/** @param {Queue} q @param {string} key @returns {Queue} */
export function jumpTo(q, key) {
  return q.items.some((i) => i.key === key) ? { ...q, currentKey: key } : q;
}

/** @param {Queue} q @param {QueueMode} mode @returns {Queue} */
export function setMode(q, mode) {
  return { ...q, mode };
}

/**
 * Élément qui suit la piste en cours, selon le mode (null = fin de file).
 * @param {Queue} q
 * @param {{ manual?: boolean }} [opts] manual : appui sur « suivant » (repeat_one passe quand même à la suivante)
 */
export function nextItem(q, opts = {}) {
  const i = currentIndex(q);
  if (i < 0) return q.items[0] ?? null;
  if (q.mode === 'repeat_one' && !opts.manual) return q.items[i];
  if (i + 1 < q.items.length) return q.items[i + 1];
  // En fin de file : on reboucle en « répéter la file », et sur « suivant » en « répéter la piste »
  return q.mode === 'repeat_all' || q.mode === 'repeat_one' ? q.items[0] ?? null : null;
}

/** @param {Queue} q */
export function previousItem(q) {
  const i = currentIndex(q);
  if (i > 0) return q.items[i - 1];
  return q.mode === 'repeat_all' ? q.items[q.items.length - 1] ?? null : null;
}

/** Nombre de pistes restant après la piste en cours. @param {Queue} q */
export function remaining(q) {
  const i = currentIndex(q);
  return i < 0 ? q.items.length : q.items.length - i - 1;
}

/** Forme envoyée au serveur (PlayQueueInput). @param {Queue} q @param {number} positionMs */
export function toServer(q, positionMs) {
  const i = currentIndex(q);
  return {
    trackIds: q.items.map((it) => it.track.id),
    currentIndex: i < 0 ? null : i,
    positionMs: Math.max(0, Math.round(positionMs)),
    mode: q.mode,
  };
}

/** File reçue du serveur (PlayQueue : trackIds + tracks dans le même ordre). @param {import('../api/types.js').PlayQueue} p @returns {Queue} */
export function fromServer(p) {
  const items = wrap(p.tracks);
  const idx = p.currentIndex ?? -1;
  return { items, currentKey: items[idx]?.key ?? null, mode: p.mode };
}
