// @ts-check
// Routeur minimal (adresses réelles, pas de #) : le site est servi sous BASE_URL, et Apache
// renvoie index.html pour toute adresse inconnue.
import { useSyncExternalStore } from 'react';
import { note } from './engine/diag.js';

const BASE = import.meta.env.BASE_URL.replace(/\/$/, '');

/** Chemin de l'application, sans la base (« /albums/3 »). */
function currentPath() {
  const p = window.location.pathname;
  return (p.startsWith(BASE) ? p.slice(BASE.length) : p) || '/';
}

/** @type {Set<() => void>} */
const listeners = new Set();
window.addEventListener('popstate', () => {
  note(`page ${currentPath()} (retour)`);
  listeners.forEach((fn) => fn());
});

/** @param {string} to chemin de l'application, requête comprise (« /albums?genre=3 ») */
export function navigate(to, replace = false) {
  const url = BASE + to;
  note(`page ${to}`);
  if (replace) window.history.replaceState(null, '', url);
  else window.history.pushState(null, '', url);
  window.scrollTo(0, 0);
  listeners.forEach((fn) => fn());
}

function snapshot() {
  return window.location.pathname + window.location.search;
}

/** @returns {{ path: string, query: URLSearchParams }} */
export function useRoute() {
  useSyncExternalStore((fn) => {
    listeners.add(fn);
    return () => listeners.delete(fn);
  }, snapshot);
  return { path: currentPath(), query: new URLSearchParams(window.location.search) };
}

/**
 * « /albums/:id » contre « /albums/12 » → { id: '12' }, ou null.
 * @param {string} pattern @param {string} path
 * @returns {Record<string, string> | null}
 */
export function match(pattern, path) {
  /** @type {string[]} */
  const names = [];
  const re = new RegExp('^' + pattern.replace(/:(\w+)/g, (_, n) => {
    names.push(n);
    return '([^/]+)';
  }) + '/?$');
  const m = path.match(re);
  if (!m) return null;
  return Object.fromEntries(names.map((n, i) => [n, decodeURIComponent(m[i + 1])]));
}

/** @param {{ to: string, children: import('react').ReactNode, className?: string, title?: string }} props */
export function Link({ to, children, className, title }) {
  return (
    <a
      href={BASE + to}
      className={className}
      title={title}
      onClick={(e) => {
        if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        e.preventDefault();
        navigate(to);
      }}
    >
      {children}
    </a>
  );
}
