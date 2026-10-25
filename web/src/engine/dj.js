// @ts-check
// BPM et tonalité d'une piste, écrits pour un humain : « 124 BPM · La mineur (8A) ».
import { language, tr } from '../i18n/index.js';

/** Noms des notes en français (en anglais, la lettre suffit). @type {Record<string, string>} */
const NOTES = { C: 'Do', D: 'Ré', E: 'Mi', F: 'Fa', G: 'Sol', A: 'La', B: 'Si' };

/**
 * Tonalité en notation anglaise courte (« Am », « F# », « Ebm ») → « La mineur », « Fa♯ majeur ».
 * @param {string | null | undefined} key
 */
export function keyName(key) {
  const m = /^([A-G])([#b]?)(m?)$/.exec(key ?? '');
  if (!m) return null;
  const accidental = m[2] === '#' ? '♯' : m[2] === 'b' ? '♭' : '';
  const note = `${language() === 'fr' ? NOTES[m[1]] : m[1]}${accidental}`;
  return m[3] ? tr('{note} mineur', { note }) : tr('{note} majeur', { note });
}

/**
 * Ligne « 124 BPM · La mineur (8A) » ; null si la piste n'a ni BPM ni tonalité.
 * @param {{ bpm: number | null, key: string | null, camelot: string | null } | null | undefined} p
 */
export function djLabel(p) {
  if (!p) return null;
  const parts = [];
  if (p.bpm !== null && p.bpm !== undefined) parts.push(`${Math.round(p.bpm)} BPM`);
  const name = keyName(p.key);
  if (name) parts.push(p.camelot ? `${name} (${p.camelot})` : name);
  return parts.length ? parts.join(' · ') : null;
}
