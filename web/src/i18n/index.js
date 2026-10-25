// @ts-check
// Langue du site (WEB-13) : une seule par serveur, choisie par l'admin. Le texte français sert de clé :
// tr('Appairer un appareil') rend le français tel quel, ou la traduction du catalogue chargé (en.js).
// Les clés doivent rester des chaînes littérales : tests/i18n.test.js les relève dans le code pour
// vérifier que chaque texte a sa traduction.

/** @typedef {Record<string, string | [string, string]>} Catalog */

/** @type {Catalog} */
let catalog = {};
let lang = 'fr';

/** @param {string} l @param {Catalog} c */
export function setCatalog(l, c) {
  lang = l;
  catalog = c;
  if (typeof document !== 'undefined') document.documentElement.lang = l;
}

export function language() {
  return lang;
}

/** Locale pour Intl (dates, nombres, tri). */
export function locale() {
  return lang === 'en' ? 'en-GB' : 'fr-FR';
}

/** @param {string} s @param {Record<string, string | number> | undefined} vars */
function fill(s, vars) {
  if (!vars) return s;
  return s.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m));
}

/**
 * Texte traduit, avec variables : tr('« {nom} » est appairé.', { nom }).
 * @param {string} fr @param {Record<string, string | number>} [vars]
 */
export function tr(fr, vars) {
  const s = catalog[fr];
  return fill(typeof s === 'string' ? s : fr, vars);
}

/**
 * Texte qui dépend d'un nombre : trn(n, '{n} piste', '{n} pistes'). La variable {n} est fournie.
 * Le singulier suit la règle de la langue (0 et 1 en français, 1 seul en anglais).
 * @param {number} n @param {string} one @param {string} other @param {Record<string, string | number>} [vars]
 */
export function trn(n, one, other, vars) {
  const forms = catalog[`${one}|${other}`];
  const [o, m] = Array.isArray(forms) ? forms : [one, other];
  const singular = new Intl.PluralRules(locale()).select(n) === 'one';
  return fill(singular ? o : m, { n: n.toLocaleString(locale()), ...vars });
}

/**
 * Table de libellés traduite à chaque lecture (les modules se chargent avant le choix de la langue) :
 * export const STATUS = labels(() => ({ pending: tr('En attente') })) ; STATUS.pending, Object.entries(STATUS)…
 * @template {Record<string, any>} T @param {() => T} make @returns {T}
 */
export function labels(make) {
  return /** @type {T} */ (new Proxy({}, {
    get: (_, k) => /** @type {any} */ (make())[k],
    has: (_, k) => k in make(),
    ownKeys: () => Reflect.ownKeys(make()),
    getOwnPropertyDescriptor: (_, k) => (k in make() ? { value: /** @type {any} */ (make())[k], enumerable: true, configurable: true, writable: false } : undefined),
  }));
}

/**
 * Charge la langue annoncée par le serveur (GET /server). Le catalogue anglais est un fichier à part,
 * téléchargé seulement s'il sert.
 * @param {string | null | undefined} lang
 */
export async function loadLanguage(lang) {
  if (lang === 'en') {
    const m = await import('./en.js');
    setCatalog('en', m.default);
  } else {
    setCatalog('fr', {});
  }
}
