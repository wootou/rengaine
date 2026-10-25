// @ts-check
// Outils partagés par les écrans d'admin : libellés, dates, pages de résultats, actions.
import { useCallback, useEffect, useRef, useState } from 'react';
import { ApiError } from '../api/client.js';
import { Store } from '../engine/store.js';
import { useApi } from '../hooks.js';
import { labels, locale, tr } from '../i18n/index.js';

/** @typedef {import('../api/types.js').Schemas} Schemas */

export const STATUS = /** @type {Record<Schemas['UserStatus'], string>} */ (labels(() => ({ pending: tr('En attente'), active: tr('Actif'), suspended: tr('Suspendu'), refused: tr('Refusé') })));
export const ROLE = /** @type {Record<Schemas['Role'], string>} */ (labels(() => ({ admin: tr('Admin'), user: tr('Utilisateur'), agent: tr('Agent (Claude)') })));
export const JOB_TYPE = /** @type {Record<Schemas['JobType'], string>} */ (labels(() => ({ index: tr('Scan'), analyze: tr('Analyse audio'), inbox: tr('Dépôt'), thumbnails: tr('Vignettes'), import: tr('Import'), mail: tr('Mail'), dedup: tr('MP3 en double'), purge: tr('Purge des journaux'), organize: tr('Réorganisation par IA') })));
export const JOB_STATUS = /** @type {Record<Schemas['JobStatus'], string>} */ (labels(() => ({ queued: tr('En file'), running: tr('En cours'), done: tr('Terminée'), failed: tr('Échec'), cancelled: tr('Annulée') })));
export const CORRECTION_STATUS = /** @type {Record<Schemas['CorrectionStatus'], string>} */ (labels(() => ({ proposed: tr('Proposée'), active: tr('Active'), rejected: tr('Refusée'), reverted: tr('Annulée') })));
export const CHANGE_SET_STATUS = /** @type {Record<Schemas['ChangeSetStatus'], string>} */ (labels(() => ({ draft: tr('Brouillon'), pending: tr('À décider'), applied: tr('Appliqué'), rejected: tr('Refusé'), reverted: tr('Annulé') })));

/** Champs corrigeables (noms du contrat). */
export const FIELD = /** @type {Record<string, string>} */ (labels(() => ({
  title: tr('Titre'), year: tr('Année'), albumArtist: tr('Artiste de l’album'), isCompilation: tr('Compilation'), genreIds: tr('Genres'),
  coverImageHash: tr('Jaquette'), artistCredit: tr('Artiste'), discNumber: tr('Disque'), trackNumber: tr('Numéro'), bpm: tr('BPM'), key: tr('Clé'), albumId: tr('Album'),
  name: tr('Nom'), sortName: tr('Nom de tri'), mergedInto: tr('Fusionné dans'), genreId: tr('Genre'),
})));

/** Actions du journal d'audit. */
export const ACTION = /** @type {Record<string, string>} */ (labels(() => ({
  'auth.login': tr('Connexion'), 'auth.logout': tr('Déconnexion'), 'user.register': tr('Demande de compte'), 'user.create': tr('Compte créé'),
  'user.approve': tr('Compte approuvé'), 'user.refuse': tr('Demande refusée'), 'user.suspend': tr('Compte suspendu'), 'user.reactivate': tr('Compte réactivé'),
  'user.update': tr('Compte modifié'), 'user.delete': tr('Compte supprimé'), 'user.password': tr('Mot de passe changé'), 'token.create': tr('Jeton créé'), 'token.revoke': tr('Appareil déconnecté'),
  'token.pair': tr('Appli appairée'), 'pairing.create': tr('Code d’appairage'), 'server.settings': tr('Réglages du serveur'),
  'album.update': tr('Album corrigé'), 'track.update': tr('Piste corrigée'), 'correction.revert': tr('Correction annulée'),
  'genre.create': tr('Genre créé'), 'genre.update': tr('Genre modifié'), 'genre.delete': tr('Genre supprimé'), 'genre_alias.map': tr('Tag de genre rattaché'),
  'job.create': tr('Tâche demandée'), 'album.create': tr('Album séparé'), 'album.merge': tr('Albums fusionnés'),
  'artist.update': tr('Artiste corrigé'), 'artist.merge': tr('Artistes fusionnés'), 'change_set.create': tr('Lot proposé'), 'history.clear': tr('Historique d’écoute effacé'),
  'change_set.apply': tr('Lot appliqué'), 'change_set.reject': tr('Lot refusé'), 'change_set.revert': tr('Lot annulé'),
  'library.dedup': tr('MP3 en double retirés'),
})));

/** @param {string | null | undefined} iso */
export function formatDate(iso) {
  if (!iso) return '—';
  return new Date(iso).toLocaleString(locale(), { dateStyle: 'short', timeStyle: 'short' });
}

/** Durée entre deux dates (« 2 min 20 s »). @param {string | null | undefined} from @param {string | null | undefined} to */
export function formatDuration(from, to) {
  if (!from || !to) return '';
  const s = Math.max(0, Math.round((Date.parse(to) - Date.parse(from)) / 1000));
  if (s < 60) return `${s} s`;
  const m = Math.floor(s / 60);
  return m < 60 ? `${m} min ${s % 60} s` : `${Math.floor(m / 60)} h ${m % 60} min`;
}

/** @param {number} bytes */
export function formatSize(bytes) {
  if (bytes < 1024 * 1024) return tr('{n} Ko', { n: Math.round(bytes / 1024) });
  return tr('{n} Mo', { n: (bytes / 1024 / 1024).toLocaleString(locale(), { minimumFractionDigits: 1, maximumFractionDigits: 1 }) });
}

/**
 * Valeur d'une correction, lisible.
 * @param {string} field @param {unknown} value @param {Map<number, string>} [genres]
 */
export function formatValue(field, value, genres) {
  if (value === null || value === undefined || value === '') return '—';
  if (field === 'genreIds' && Array.isArray(value)) {
    return value.length ? value.map((id) => genres?.get(Number(id)) ?? `#${id}`).join(', ') : tr('aucun');
  }
  if (field === 'genreId' && typeof value === 'number') return genres?.get(value) ?? `#${value}`;
  if (field === 'albumId' && typeof value === 'number') return tr('album #{id}', { id: value });
  if (field === 'mergedInto' && typeof value === 'number') return tr('artiste #{id}', { id: value });
  if (typeof value === 'boolean') return value ? tr('oui') : tr('non');
  if (field === 'coverImageHash') return String(value).slice(0, 10) + '…';
  if (typeof value === 'object') return JSON.stringify(value);
  return String(value);
}

/** Prévient les autres écrans d'un changement (badge des demandes en attente). */
export const adminEvents = new Store({ users: 0, sets: 0 });

/**
 * Page de résultats avec décalage ; revient à la première page quand les filtres changent.
 * @template T
 * @param {string} path @param {Record<string, any>} query @param {number} [limit]
 */
export function useOffsetPage(path, query, limit = 50) {
  const key = JSON.stringify(query);
  const [state, setState] = useState({ key, offset: 0 });
  const offset = state.key === key ? state.offset : 0;
  const api = useApi(path, { ...query, limit, offset });
  const page = /** @type {{ items: T[], total: number } | null} */ (api.data);
  return {
    items: page?.items ?? [], total: page?.total ?? null, offset, limit, error: api.error, loading: api.loading, reload: api.reload,
    /** @param {number} o */
    setOffset: (o) => setState({ key, offset: o }),
  };
}

/** @param {{ total: number | null, offset: number, limit: number, onChange: (o: number) => void }} props */
export function Pager({ total, offset, limit, onChange }) {
  if (total === null || total <= limit) return null;
  const last = Math.min(offset + limit, total);
  return (
    <div className="pager">
      <button type="button" disabled={offset === 0} onClick={() => onChange(Math.max(0, offset - limit))}>{tr('Précédents')}</button>
      <span>{tr('{a}–{b} sur {total}', { a: offset + 1, b: last, total })}</span>
      <button type="button" disabled={last >= total} onClick={() => onChange(offset + limit)}>{tr('Suivants')}</button>
    </div>
  );
}

/**
 * Lance une modification et garde son résultat à afficher (message ou erreur).
 * @returns {{ run: (fn: () => Promise<unknown>, ok?: string) => Promise<boolean>, busy: boolean, message: string, error: string, clear: () => void }}
 */
export function useAction() {
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  return {
    busy, message, error,
    clear: () => {
      setMessage('');
      setError('');
    },
    run: async (fn, ok = '') => {
      setBusy(true);
      setMessage('');
      setError('');
      try {
        await fn();
        setMessage(ok);
        return true;
      } catch (e) {
        setError(errorText(e));
        return false;
      } finally {
        setBusy(false);
      }
    },
  };
}

/** @param {unknown} e */
export function errorText(e) {
  if (!(e instanceof ApiError)) return tr('Erreur inattendue');
  const field = e.details?.field ? ` (${e.details.field})` : '';
  return e.message + field;
}

/** Message de résultat d'une action. @param {{ message: string, error: string }} props */
export function Feedback({ message, error }) {
  if (error) return <p className="form-error" role="alert">{error}</p>;
  if (message) return <p className="form-ok" role="status">{message}</p>;
  return null;
}

/** Recharge toutes les `ms` tant que `active` (tâche en cours). @param {() => void} reload @param {boolean} active @param {number} ms */
export function usePolling(reload, active, ms) {
  useEffect(() => {
    if (!active) return;
    const t = setInterval(reload, ms);
    return () => clearInterval(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [active, ms]);
}

/** Genres (liste du catalogue) et leurs noms par identifiant. */
export function useGenres() {
  const api = useApi('/genres');
  const list = /** @type {{ items: Schemas['Genre'][] } | null} */ (api.data)?.items ?? [];
  const names = new Map(list.map((g) => [g.id, g.name]));
  return { list, names, reload: api.reload, loading: api.loading };
}

/**
 * Téléphone : les tableaux d'admin s'affichent en cartes (styles.css), chaque valeur précédée du
 * nom de sa colonne. Ce crochet recopie l'en-tête de chaque colonne dans `data-label` sur les
 * cellules, y compris les lignes arrivées plus tard (chargement, page suivante).
 * À poser avec `ref={…}` sur l'élément qui contient les tableaux.
 * @returns {(el: HTMLElement | null) => void}
 */
export function useTableLabels() {
  const observer = useRef(/** @type {MutationObserver | null} */ (null));
  return useCallback((/** @type {HTMLElement | null} */ root) => {
    observer.current?.disconnect();
    observer.current = null;
    if (!root) return;
    const label = () => {
      for (const table of root.querySelectorAll('table.admin-table')) {
        const heads = [...table.querySelectorAll('thead th')].map((th) => th.textContent?.trim() ?? '');
        for (const row of table.querySelectorAll('tbody tr')) {
          let col = 0;
          for (const td of row.children) {
            const span = Number(td.getAttribute('colspan') || 1);
            const name = span > 1 ? '' : heads[col] ?? ''; // cellule sur plusieurs colonnes : pas de nom
            if (name && td.getAttribute('data-label') !== name) td.setAttribute('data-label', name);
            else if (!name && td.hasAttribute('data-label')) td.removeAttribute('data-label');
            col += span;
          }
        }
      }
    };
    label();
    // Les attributs ne sont pas observés : poser data-label ne relance pas l'observateur
    observer.current = new MutationObserver(label);
    observer.current.observe(root, { childList: true, subtree: true, characterData: true });
  }, []);
}
