// @ts-nocheck (lit les sources avec node:fs, dont les types ne sont pas installés)
// Langue du site (WEB-13) : chaque texte passé à tr() / trn() a sa traduction anglaise, sans clé
// en trop, avec les mêmes variables ; aucun texte français n'est resté écrit en dur.
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { describe, expect, it } from 'vitest';
import en from '../src/i18n/en.js';
import { setCatalog, tr, trn } from '../src/i18n/index.js';

const SRC = join(import.meta.dirname, '../src');
/** @returns {string[]} */
function files(dir = SRC) {
  return readdirSync(dir).flatMap((f) => {
    const p = join(dir, f);
    if (statSync(p).isDirectory()) return files(p);
    return /\.(js|jsx)$/.test(f) && !f.endsWith('.d.ts') && !p.includes('/i18n/') ? [p] : [];
  });
}
const sources = files().map((p) => ({ path: relative(SRC, p), text: readFileSync(p, 'utf8') }));

const LIT = String.raw`'(?:[^'\\\n]|\\.)*'|"(?:[^"\\\n]|\\.)*"`;
/** @param {string} lit */
const unquote = (lit) => (lit.startsWith('"') ? JSON.parse(lit) : lit.slice(1, -1).replace(/\\'/g, "'"));
/** @param {string} s */
const vars = (s) => [...s.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort();

/** Textes trouvés dans le code : clé → fichiers. */
const keys = new Map();
const plurals = new Map();
/** Appels à tr()/trn() dont le texte n'est pas une chaîne littérale. */
const dynamic = [];
for (const { path, text } of sources) {
  for (const m of text.matchAll(new RegExp(String.raw`(?<![\w.])tr\(\s*(${LIT})?`, 'g'))) {
    if (!m[1]) dynamic.push(`${path}: ${text.slice(m.index, m.index + 40)}`);
    else keys.set(unquote(m[1]), path);
  }
  for (const m of text.matchAll(new RegExp(String.raw`(?<![\w.])trn\(([^'"\`]*?),\s*(${LIT})?\s*,\s*(${LIT})?`, 'g'))) {
    if (!m[2] || !m[3]) dynamic.push(`${path}: ${text.slice(m.index, m.index + 60)}`);
    else plurals.set(`${unquote(m[2])}|${unquote(m[3])}`, path);
  }
}

describe('traductions', () => {
  it('les textes passés à tr() et trn() sont des chaînes littérales', () => {
    expect(dynamic).toEqual([]);
  });

  it('chaque texte a sa traduction anglaise', () => {
    const missing = [...keys.keys()].filter((k) => typeof en[k] !== 'string')
      .concat([...plurals.keys()].filter((k) => !Array.isArray(en[k])));
    expect(missing).toEqual([]);
  });

  it('aucune traduction en trop', () => {
    expect(Object.keys(en).filter((k) => !keys.has(k) && !plurals.has(k))).toEqual([]);
  });

  it('mêmes variables en français et en anglais', () => {
    const bad = [];
    for (const [k, v] of Object.entries(en)) {
      const fr = k.split('|');
      const tr2 = Array.isArray(v) ? v : [v];
      fr.forEach((f, i) => {
        const want = vars(f).filter((x) => x !== 'n');
        const got = vars(tr2[i] ?? '').filter((x) => x !== 'n');
        if (want.join() !== got.join()) bad.push(`${k} → ${tr2[i]}`);
      });
    }
    expect(bad).toEqual([]);
  });

  it('aucun texte français écrit en dur', () => {
    const found = [];
    for (const { path, text } of sources) {
      if (path.endsWith('diag.js')) continue; // journal de diagnostic : pour le développeur
      const code = text
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/^\s*\/\/.*$/gm, '')
        .replace(/\s\/\/ .*$/gm, '')
        .replace(new RegExp(String.raw`(?<![\w.])trn?\(([^'"\`]*?,\s*)?(${LIT})(\s*,\s*(${LIT}))?`, 'g'), 'tr(')
        .replace(/^\s*import .*$/gm, '')
        .replace(/.*\bnote\(.*$/gm, '')
        .replace(/console\.\w+\([^)]*\)/g, '');
      for (const m of code.matchAll(new RegExp(String.raw`${LIT}|\`[^\`]*\``, 'g'))) {
        if (/[À-ÿ’«»]/.test(m[0]) && !/^['"`](?:[A-Za-z0-9_./-]|Ré)*['"`]$/.test(m[0])) found.push(`${path}: ${m[0]}`);
      }
      if (path.endsWith('.jsx')) {
        for (const m of code.matchAll(/>([^<>{}\n=&|?;()]*[A-Za-zÀ-ÿ]{3,}[^<>{}\n=&|?;()]*)</g)) {
          if (!/^\s*(FLAC|OK|Français|English|inbox\/|corbeille\/|rengaine corbeille:restaurer)\s*$/.test(m[1])) found.push(`${path}: >${m[1]}<`);
        }
      }
    }
    expect(found).toEqual([]);
  });

  it('tr et trn : français par défaut, catalogue anglais une fois chargé', () => {
    setCatalog('fr', {});
    expect(tr('« {nom} » est appairé.', { nom: 'Pixel' })).toBe('« Pixel » est appairé.');
    expect(trn(0, '{n} piste', '{n} pistes')).toBe('0 piste');
    expect(trn(2, '{n} piste', '{n} pistes')).toBe('2 pistes');
    setCatalog('en', en);
    expect(trn(0, '{n} piste', '{n} pistes')).toBe('0 tracks');
    expect(trn(1, '{n} piste', '{n} pistes')).toBe('1 track');
    expect(tr('Appairer un appareil')).toBe('Pair a device');
    setCatalog('fr', {});
  });
});
