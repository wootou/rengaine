// @ts-check
// Playlists (FUT-01) côté lecteur : mes playlists (pour la fenêtre « Ajouter à une playlist »),
// un compteur `changes` que les pages surveillent pour se recharger, et la fenêtre elle-même
// (ouverte depuis une piste, un album ou la file d'attente).

import { get, request } from '../api/client.js';
import { Store } from './store.js';

/** @typedef {import('../api/types.js').Schemas['PlaylistSummary']} PlaylistSummary */
/** @typedef {import('../api/types.js').Schemas['PlaylistDetail']} PlaylistDetail */
/** @typedef {import('../api/types.js').Track} Track */

/**
 * @type {Store<{ mine: PlaylistSummary[] | null, changes: number, picker: { tracks: Track[], title: string } | null }>}
 */
export const playlists = new Store({ mine: /** @type {PlaylistSummary[] | null} */ (null), changes: 0, picker: /** @type {{ tracks: Track[], title: string } | null} */ (null) });

function changed() {
  playlists.set({ changes: playlists.getState().changes + 1 });
}

/** Recharge mes playlists (les plus récemment modifiées d'abord). */
export async function loadMine() {
  const r = /** @type {{ items: PlaylistSummary[] }} */ (await get('/playlists', { query: { scope: 'mine' } }));
  playlists.set({ mine: r.items });
  return r.items;
}

/** @param {string} name @param {number[]} [trackIds] @param {{ description?: string | null, shared?: boolean }} [o] */
export async function createPlaylist(name, trackIds = [], o = {}) {
  const r = /** @type {{ data: PlaylistDetail }} */ (await request('POST', '/playlists', { body: { name, trackIds, ...o } }));
  changed();
  void loadMine().catch(() => {});
  return r.data;
}

/** @param {number} id @param {number[]} trackIds */
export async function addToPlaylist(id, trackIds) {
  const r = /** @type {{ data: PlaylistDetail }} */ (await request('POST', `/playlists/${id}/items`, { body: { trackIds } }));
  changed();
  void loadMine().catch(() => {});
  return r.data;
}

/**
 * Modification d'une playlist (renommer, partager, retirer, réordonner) : renvoie la playlist à jour.
 * @param {'PATCH' | 'PUT' | 'DELETE'} method @param {string} path @param {any} [body]
 */
export async function editPlaylist(method, path, body) {
  const r = await request(method, path, body === undefined ? {} : { body });
  changed();
  void loadMine().catch(() => {});
  return /** @type {PlaylistDetail | null} */ (r.data ?? null);
}

/** Ouvre la fenêtre « Ajouter à une playlist ». @param {Track[]} tracks @param {string} title ce qu'on ajoute (« Ulysses », « l'album Tonight »…) */
export function openPicker(tracks, title) {
  const playable = tracks.filter((t) => t.available !== false);
  if (playable.length) playlists.set({ picker: { tracks: playable, title } });
}

export function closePicker() {
  playlists.set({ picker: null });
}
