// @ts-check
import { useCallback, useEffect, useRef, useState, useSyncExternalStore } from 'react';
import { ApiError, get } from './api/client.js';
import { controller } from './engine/controller.js';
import { device } from './engine/device.js';
import { session } from './engine/session.js';

export function usePlayer() {
  return useSyncExternalStore(controller.store.subscribe, controller.store.getState);
}

/**
 * Une partie seulement de l'état du lecteur : le composant ne se redessine que si elle change.
 * La position avance quatre fois par seconde ; une liste qui ne l'affiche pas n'a pas à suivre.
 * `select` doit rendre une valeur stable (primitive, ou objet de l'état tel quel).
 * @template T @param {(s: ReturnType<typeof controller.store.getState>) => T} select
 * @returns {T}
 */
export function usePlayerSelect(select) {
  return useSyncExternalStore(controller.store.subscribe, () => select(controller.store.getState()));
}

export function useDevice() {
  return useSyncExternalStore(device.subscribe, device.getState);
}

export function useSession() {
  return useSyncExternalStore(session.subscribe, session.getState);
}

/**
 * Une ressource de l'API ; rechargée quand `path` ou `query` changent.
 * @template T
 * @param {string | null} path null = rien à charger
 * @param {Record<string, any>} [query]
 * @returns {{ data: T | null, error: ApiError | null, loading: boolean, reload: () => void }}
 */
export function useApi(path, query) {
  const key = path === null ? null : path + JSON.stringify(query ?? {});
  const [state, setState] = useState(/** @type {{ data: T | null, error: ApiError | null, loading: boolean, key: string | null }} */ ({ data: null, error: null, loading: path !== null, key: null }));
  const [nonce, setNonce] = useState(0);
  useEffect(() => {
    if (path === null) return;
    const ctl = new AbortController();
    setState((s) => ({ ...s, loading: true, error: null }));
    get(path, { query, signal: ctl.signal })
      .then((data) => setState({ data: /** @type {T} */ (data), error: null, loading: false, key }))
      .catch((e) => {
        if (e?.name !== 'AbortError') setState({ data: null, error: e instanceof ApiError ? e : new ApiError(0, 'internal', String(e)), loading: false, key });
      });
    return () => ctl.abort();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, nonce]);
  return { data: state.key === key ? state.data : null, error: state.error, loading: state.loading, reload: () => setNonce((n) => n + 1) };
}

/**
 * Liste paginée chargée au fil du défilement (WEB-02) : la page suivante arrive quand le
 * repère du bas devient visible.
 * @template T
 * @param {string} path @param {Record<string, any>} query @param {number} [pageSize]
 */
export function usePaged(path, query, pageSize = 60) {
  const key = path + JSON.stringify(query);
  const [items, setItems] = useState(/** @type {T[]} */ ([]));
  const [total, setTotal] = useState(/** @type {number | null} */ (null));
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(/** @type {ApiError | null} */ (null));
  const inflight = useRef(/** @type {string | null} */ (null));
  const keyRef = useRef(key);

  useEffect(() => {
    keyRef.current = key;
    setItems([]);
    setTotal(null);
    setError(null);
    inflight.current = null;
  }, [key]);

  const loadMore = useCallback(() => {
    const offset = items.length;
    if (inflight.current === key + offset || (total !== null && offset >= total)) return;
    inflight.current = key + offset;
    setLoading(true);
    const forKey = key;
    get(path, { query: { ...query, limit: pageSize, offset } })
      .then((r) => {
        if (keyRef.current !== forKey) return;
        const page = /** @type {{ items: T[], total: number }} */ (r);
        setItems((prev) => (prev.length === offset ? [...prev, ...page.items] : prev));
        setTotal(page.total);
      })
      .catch((e) => keyRef.current === forKey && setError(e instanceof ApiError ? e : null))
      .finally(() => keyRef.current === forKey && setLoading(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, items.length, total]);

  useEffect(() => {
    if (items.length === 0 && total === null) loadMore();
  }, [items.length, total, loadMore]);

  const done = total !== null && items.length >= total;
  return { items, total, loading, error, loadMore, done };
}

/** Appelle `onVisible` quand l'élément entre à l'écran (avec un peu d'avance). @param {() => void} onVisible @param {boolean} active */
export function useSentinel(onVisible, active) {
  const ref = useRef(/** @type {HTMLDivElement | null} */ (null));
  useEffect(() => {
    const el = ref.current;
    if (!el || !active || typeof IntersectionObserver === 'undefined') return;
    const io = new IntersectionObserver((entries) => entries.some((e) => e.isIntersecting) && onVisible(), { rootMargin: '600px' });
    io.observe(el);
    return () => io.disconnect();
  }, [onVisible, active]);
  return ref;
}

/** Valeur retardée (recherche pendant la frappe). @template T @param {T} value @param {number} ms */
export function useDebounced(value, ms) {
  const [v, setV] = useState(value);
  useEffect(() => {
    const t = setTimeout(() => setV(value), ms);
    return () => clearTimeout(t);
  }, [value, ms]);
  return v;
}
