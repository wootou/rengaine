// @ts-check
// Client de l'API v2. Le site s'authentifie par son cookie de session (posé par /auth/login) ;
// toute requête qui modifie porte le jeton anti-CSRF. Les erreurs suivent le format `Error` du
// contrat : on les relève en ApiError, avec le code stable que l'interface peut tester.

import { tr } from '../i18n/index.js';

export const API = '/api/v2';

export class ApiError extends Error {
  /**
   * @param {number} status
   * @param {string} code
   * @param {string} message
   * @param {Record<string, any>} [details]
   * @param {string} [requestId]
   */
  constructor(status, code, message, details = {}, requestId = '') {
    super(message);
    this.status = status;
    this.code = code;
    this.details = details;
    this.requestId = requestId;
  }
}

/** @type {string | null} */
let csrfToken = null;
/** @type {Set<() => void>} */
const onUnauthorized = new Set();

/** @param {string | null | undefined} token */
export function setCsrfToken(token) {
  csrfToken = token ?? null;
}

/** Prévenu quand la session a expiré (401 sur une route protégée). @param {() => void} fn */
export function onSessionLost(fn) {
  onUnauthorized.add(fn);
  return () => onUnauthorized.delete(fn);
}

/**
 * @param {Record<string, string | number | boolean | null | undefined | (string | number)[]>} [query]
 */
function queryString(query) {
  if (!query) return '';
  const p = new URLSearchParams();
  for (const [k, v] of Object.entries(query)) {
    if (v === undefined || v === null || v === '' || (Array.isArray(v) && v.length === 0)) continue;
    p.set(k, Array.isArray(v) ? v.join(',') : String(v));
  }
  const s = p.toString();
  return s ? `?${s}` : '';
}

/**
 * @template T
 * @param {string} method
 * @param {string} path chemin sous /api/v2 (« /albums »)
 * @param {{ query?: Parameters<typeof queryString>[0], body?: unknown, blob?: Blob, ifMatch?: string | number, signal?: AbortSignal, quiet401?: boolean, csrf?: string }} [options]
 *   `csrf` : jeton anti-CSRF à la place de celui de la session (invité d'une télécommande).
 *   `blob` : corps envoyé tel quel, avec son type (image de fond) ; sinon `body` part en JSON.
 * @returns {Promise<{ data: T, etag: string | null, status: number }>}
 */
export async function request(method, path, options = {}) {
  /** @type {Record<string, string>} */
  const headers = { Accept: 'application/json' };
  if (options.blob !== undefined) headers['Content-Type'] = options.blob.type || 'application/octet-stream';
  else if (options.body !== undefined) headers['Content-Type'] = 'application/json';
  const csrf = options.csrf ?? csrfToken;
  if (method !== 'GET' && method !== 'HEAD' && csrf) headers['X-CSRF-Token'] = csrf;
  if (options.ifMatch !== undefined) headers['If-Match'] = `"${options.ifMatch}"`;

  let res;
  try {
    res = await fetch(API + path + queryString(options.query), {
      method,
      headers,
      credentials: 'same-origin',
      body: options.blob ?? (options.body === undefined ? undefined : JSON.stringify(options.body)),
      signal: options.signal,
    });
  } catch (e) {
    if (e instanceof DOMException && e.name === 'AbortError') throw e;
    throw new ApiError(0, 'network', tr('Serveur injoignable : vérifiez la connexion'));
  }

  const etag = res.headers.get('ETag');
  if (res.status === 204 || res.status === 304) {
    return { data: /** @type {T} */ (/** @type {unknown} */ (null)), etag, status: res.status };
  }
  /** @type {any} */
  let json = null;
  try {
    json = await res.json();
  } catch {
    json = null;
  }
  if (!res.ok) {
    const e = json?.error;
    const err = new ApiError(res.status, e?.code ?? 'http_' + res.status, e?.message ?? tr('Erreur {n}', { n: res.status }), e?.details ?? {}, e?.requestId);
    if (res.status === 401 && !options.quiet401) onUnauthorized.forEach((fn) => fn());
    throw err;
  }
  return { data: json, etag, status: res.status };
}

/**
 * Raccourci : seulement le corps.
 * @template T
 * @param {string} path
 * @param {Parameters<typeof request>[2]} [options]
 * @returns {Promise<T>}
 */
export async function get(path, options) {
  return (await request('GET', path, options)).data;
}

/** Version contenue dans un ETag (« "7" » → 7). @param {string | null} etag */
export function etagVersion(etag) {
  const m = etag?.match(/(\d+)/);
  return m ? Number(m[1]) : 0;
}

/** Image à la taille voulue : « s » 96 px, « m » 300 px, « l » 600 px. @param {string | null | undefined} url @param {'s'|'m'|'l'|'original'} size */
export function imageUrl(url, size) {
  return url ? `${url}?size=${size}` : null;
}
