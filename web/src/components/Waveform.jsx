// @ts-check
// Forme d'onde de la piste en cours (AUD-07), dessinée derrière la barre de progression : la partie
// écoutée prend la couleur d'accent. Purement décorative (aria-hidden) : la barre reste le curseur
// « Position », au clavier comme à la souris.
import { useEffect, useMemo, useState } from 'react';
import { ApiError, get } from '../api/client.js';

/** @typedef {import('../api/types.js').Schemas['Waveform']} WaveformData */

/** Nombre de barres dessinées (étirées sur la largeur). */
export const WAVE_BARS = 120;
/** Écart de niveau représenté : les tranches 30 dB sous la plus forte ont la hauteur minimale. */
const RANGE_DB = 30;
const MIN_HEIGHT = 0.06;

/** Niveaux des dernières formes d'onde chargées. @type {Map<number, Array<number | null>>} */
const cache = new Map();
const CACHE_MAX = 30;

/**
 * Hauteurs des barres, de 0 à 1 : le plus fort de chaque groupe de tranches, en dB rapportés à la
 * tranche la plus forte de la piste.
 * @param {Array<number | null>} levels @param {number} [bars]
 * @returns {number[]}
 */
export function waveBars(levels, bars = WAVE_BARS) {
  if (levels.length === 0) return [];
  const max = Math.max(...levels.map((l) => (l === null ? -Infinity : l)));
  if (!Number.isFinite(max)) return Array(bars).fill(0);
  const out = [];
  for (let i = 0; i < bars; i++) {
    const from = Math.floor((i * levels.length) / bars);
    const to = Math.max(from + 1, Math.floor(((i + 1) * levels.length) / bars));
    let peak = -Infinity;
    for (let j = from; j < to && j < levels.length; j++) {
      const l = levels[j];
      if (l !== null && l > peak) peak = l;
    }
    out.push(peak === -Infinity ? 0 : Math.max(MIN_HEIGHT, Math.min(1, 1 - (max - peak) / RANGE_DB)));
  }
  return out;
}

/** Chemin SVG des barres (repère : largeur = nombre de barres, hauteur 100). @param {number[]} heights */
export function wavePath(heights) {
  return heights
    .map((h, i) => {
      if (h <= 0) return '';
      const H = Math.max(2, h * 100);
      return `M${(i + 0.15).toFixed(2)} ${((100 - H) / 2).toFixed(1)}h0.7v${H.toFixed(1)}h-0.7Z`;
    })
    .join('');
}

/** @param {number | null} trackId */
function useWaveform(trackId) {
  const [levels, setLevels] = useState(/** @type {Array<number | null> | null} */ (trackId !== null ? cache.get(trackId) ?? null : null));
  useEffect(() => {
    if (trackId === null) {
      setLevels(null);
      return;
    }
    if (cache.has(trackId)) {
      setLevels(cache.get(trackId) ?? null);
      return;
    }
    setLevels(null);
    let alive = true;
    get(`/tracks/${trackId}/waveform`)
      .then((w) => /** @type {WaveformData} */ (w).levels)
      .catch((e) => {
        if (!(e instanceof ApiError && e.status === 404)) console.warn('Forme d’onde :', e);
        return null;
      })
      .then((l) => {
        // Une absence (404) n'est pas gardée : la piste peut être mesurée entre-temps
        if (l) {
          cache.set(trackId, l);
          if (cache.size > CACHE_MAX) cache.delete(/** @type {number} */ (cache.keys().next().value));
        }
        if (alive) setLevels(l);
      });
    return () => {
      alive = false;
    };
  }, [trackId]);
  return levels;
}

/** @param {{ trackId: number | null, progress: number }} props progress de 0 à 1 */
export function Waveform({ trackId, progress }) {
  const levels = useWaveform(trackId);
  const path = useMemo(() => (levels ? wavePath(waveBars(levels)) : ''), [levels]);
  if (!path) return null;
  const clip = `inset(0 ${(100 - Math.max(0, Math.min(1, progress)) * 100).toFixed(2)}% 0 0)`;
  return (
    <span className="wave" aria-hidden="true">
      <svg viewBox={`0 0 ${WAVE_BARS} 100`} preserveAspectRatio="none" focusable="false"><path d={path} /></svg>
      <svg className="wave-played" viewBox={`0 0 ${WAVE_BARS} 100`} preserveAspectRatio="none" focusable="false" style={{ clipPath: clip }}><path d={path} /></svg>
    </span>
  );
}
