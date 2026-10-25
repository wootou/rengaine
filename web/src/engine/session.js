// @ts-check
// Session (compte connecté) et réglages de l'utilisateur, partagés avec l'appli (CPT-04).
// Les réglages sont versionnés : si un autre appareil a enregistré entre-temps (412), on recharge
// sa version et on réapplique la modification demandée.

import { ApiError, etagVersion, get, request, setCsrfToken, onSessionLost } from '../api/client.js';
import { Store } from './store.js';
import { tr } from '../i18n/index.js';

/** @typedef {import('../api/types.js').User} User */
/** @typedef {import('../api/types.js').UserSettings} UserSettings */
/** @typedef {import('../api/types.js').ServerInfo} ServerInfo */
/** @typedef {{ status: 'loading' | 'anonymous' | 'ready', user: User | null, settings: UserSettings | null, settingsVersion: number, server: ServerInfo | null, notice?: string | null }} SessionState */

/** @type {Store<SessionState>} */
export const session = new Store(/** @type {SessionState} */ ({ status: 'loading', user: null, settings: null, settingsVersion: 0, server: null }));

onSessionLost(() => {
  if (session.getState().status === 'ready') session.set({ status: 'anonymous', user: null });
});

async function loadSettings() {
  const r = await request('GET', '/me/settings');
  const doc = /** @type {import('../api/types.js').UserSettingsDocument} */ (r.data);
  session.set({ settings: doc.settings, settingsVersion: doc.version });
}

/** Au démarrage : session encore valide ? */
export async function restore() {
  try {
    const server = /** @type {ServerInfo} */ (await get('/server'));
    session.set({ server });
    // /server ne donne la taille de la bibliothèque qu'à un visiteur connecté : pas de session,
    // pas besoin de demander /me (et pas de 401 rouge dans la console du navigateur)
    if (!server.library) {
      session.set({ status: 'anonymous', user: null });
      return;
    }
  } catch {
    // le serveur répondra peut-être plus tard ; /me le dira
  }
  try {
    const me = /** @type {import('../api/types.js').MeResponse} */ (await get('/me', { quiet401: true }));
    setCsrfToken(me.csrfToken);
    await loadSettings();
    session.set({ status: 'ready', user: me.user });
  } catch (e) {
    if (e instanceof ApiError && e.status === 401) {
      session.set({ status: 'anonymous', user: null });
      return;
    }
    throw e;
  }
}

/** @param {string} email @param {string} password */
export async function login(email, password) {
  const r = await request('POST', '/auth/login', { body: { email, password, client: 'web' }, quiet401: true });
  const data = /** @type {import('../api/types.js').Schemas['LoginResponse']} */ (r.data);
  setCsrfToken(data.csrfToken);
  await loadSettings();
  session.set({ status: 'ready', user: data.user, server: await get('/server'), notice: null });
}

/** Compte supprimé (DELETE /me fait) : retour à la connexion, avec un mot pour le dire. */
export function accountDeleted() {
  setCsrfToken(null);
  session.set({ status: 'anonymous', user: null, settings: null, notice: tr('Votre compte a été supprimé, avec toutes ses données.') });
}

export async function logout() {
  try {
    await request('POST', '/auth/logout');
  } finally {
    setCsrfToken(null);
    session.set({ status: 'anonymous', user: null, settings: null });
  }
}

/** Enregistrements en cours, l'un après l'autre. @type {Promise<unknown>} */
let saving = Promise.resolve();
let pending = 0;
/** Derniers réglages confirmés par le serveur (pendant une série d'enregistrements). @type {UserSettings | null} */
let confirmed = null;

/**
 * Modifie les réglages : `change` reçoit la version actuelle et renvoie la nouvelle. Affiché tout de
 * suite (une case cochée change aussitôt) ; remis comme avant si le serveur refuse.
 * Les envois passent l'un après l'autre, chacun sur la version laissée par le précédent : lancés
 * ensemble (curseur réglé au clavier, un par touche), ils se refusaient l'un l'autre (412) et le
 * dernier arrivé, pas forcément le dernier voulu, restait.
 * @param {(s: UserSettings) => UserSettings} change
 * @returns {Promise<void>}
 */
export function updateSettings(change) {
  const shown = session.getState().settings;
  if (!shown) return Promise.resolve();
  if (pending === 0) confirmed = shown;
  pending += 1;
  session.set({ settings: change(shown) });
  const run = saving.then(() => saveSettings(change)).finally(() => {
    pending -= 1;
  });
  saving = run.catch(() => {});
  return run;
}

/** @param {(s: UserSettings) => UserSettings} change */
async function saveSettings(change) {
  for (let attempt = 0; attempt < 2; attempt++) {
    const base = /** @type {UserSettings} */ (confirmed);
    const { settingsVersion } = session.getState();
    try {
      const r = await request('PUT', '/me/settings', { body: change(base), ifMatch: settingsVersion });
      const doc = /** @type {import('../api/types.js').UserSettingsDocument} */ (r.data);
      confirmed = doc.settings;
      // D'autres modifications attendent : l'affichage les montre déjà, on ne le fait pas reculer
      session.set(pending > 1 ? { settingsVersion: etagVersion(r.etag) || doc.version } : { settings: doc.settings, settingsVersion: etagVersion(r.etag) || doc.version });
      return;
    } catch (e) {
      if (e instanceof ApiError && e.status === 412 && e.details.current) {
        // Enregistré entre-temps sur un autre appareil : on repart de sa version
        confirmed = e.details.current.settings;
        session.set({ settingsVersion: e.details.current.version });
        if (pending === 1) session.set({ settings: change(e.details.current.settings) });
        continue;
      }
      if (pending === 1) session.set({ settings: base });
      throw e;
    }
  }
}
