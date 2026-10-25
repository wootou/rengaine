// @ts-check
import { describe, expect, it } from 'vitest';
import { beatTransition, consecutiveInAlbum, formatTime, gainDb, glideRate, glideToNormal, phaseError, syncedRate, tempoMatch, transition, useAlbumGain } from '../src/engine/playback.js';
import { track } from './fixtures.js';

const S = { normalization: /** @type {const} */ ('track'), targetLufs: -16, preventClipping: true };

describe('normalisation du volume', () => {
  it('gain = cible - mesure', () => {
    expect(gainDb(track(1, { playback: { track: { lufs: -10, truePeakDbtp: -8 } } }), S, false)).toBe(-6);
    expect(gainDb(track(1, { playback: { track: { lufs: -20, truePeakDbtp: -8 } } }), S, false)).toBe(4);
  });

  it('jamais au-delà de 0 dBTP si la saturation est évitée', () => {
    const t = track(1, { playback: { track: { lufs: -22, truePeakDbtp: -2 } } });
    expect(gainDb(t, S, false)).toBe(2);
    expect(gainDb(t, { ...S, preventClipping: false }, false)).toBe(6);
  });

  it('gain d’album, et repli sur la piste quand l’album n’est pas mesuré', () => {
    const t = track(1, { playback: { track: { lufs: -10, truePeakDbtp: -9 }, album: { lufs: -12, truePeakDbtp: -9 } } });
    expect(gainDb(t, S, true)).toBe(-4);
    expect(gainDb(track(1, { playback: { album: { lufs: null, truePeakDbtp: null } } }), S, true)).toBe(-6);
  });

  it('piste pas encore mesurée, ou normalisation désactivée : volume d’origine', () => {
    expect(gainDb(track(1, { playback: { track: { lufs: null, truePeakDbtp: null } } }), S, false)).toBe(0);
    expect(gainDb(track(1), { ...S, normalization: 'off' }, false)).toBe(0);
  });

  it('mode auto : volume d’album seulement quand on écoute l’album dans l’ordre', () => {
    const a1 = track(1, { trackNumber: 1 });
    const a2 = track(2, { trackNumber: 2 });
    const other = track(3, { album: { id: 9 } });
    expect(useAlbumGain('auto', a2, a1, null)).toBe(true);
    expect(useAlbumGain('auto', a1, other, other)).toBe(false);
    expect(useAlbumGain('album', a1, null, null)).toBe(true);
    expect(useAlbumGain('track', a2, a1, null)).toBe(false);
  });
});

describe('fondu enchaîné', () => {
  const F = { crossfadeMs: 6000, crossfadeWithinAlbum: false };

  it('se termine quand le son s’arrête, et la suivante démarre après son silence', () => {
    const t = transition(track(1), track(2, { album: { id: 2 } }), F);
    expect(t).toEqual({ crossfadeMs: 6000, fadeStartMs: 189000, nextStartMs: 500 });
  });

  it('pas de fondu entre deux pistes qui se suivent dans un album (albums live), sauf demande', () => {
    expect(transition(track(1), track(2), F).crossfadeMs).toBe(0);
    expect(transition(track(1), track(2), { ...F, crossfadeWithinAlbum: true }).crossfadeMs).toBe(6000);
    // Même album mais pas à la suite (file mélangée) : fondu
    expect(transition(track(1), track(5), F).crossfadeMs).toBe(6000);
  });

  it('jamais plus du tiers d’une piste courte', () => {
    const short = track(2, { album: { id: 2 }, durationMs: 9000 });
    expect(transition(track(1), short, F).crossfadeMs).toBe(3000);
  });

  it('fondu désactivé, ou pas de suivante : fin du fichier', () => {
    expect(transition(track(1), track(2, { album: { id: 2 } }), { ...F, crossfadeMs: 0 })).toEqual({ crossfadeMs: 0, fadeStartMs: 200000, nextStartMs: 0 });
    expect(transition(track(1), null, F).crossfadeMs).toBe(0);
  });

  it('pistes consécutives, changement de disque compris', () => {
    expect(consecutiveInAlbum(track(1, { discNumber: 1, trackNumber: 12 }), track(2, { discNumber: 2, trackNumber: 1 }))).toBe(true);
    expect(consecutiveInAlbum(track(1, { trackNumber: 3 }), track(2, { trackNumber: 5 }))).toBe(false);
  });
});

describe('fondu calé sur le tempo (LEC-06)', () => {
  const F = { crossfadeMs: 6000, crossfadeWithinAlbum: false, beatSync: true };
  /** @param {any} outro @param {any} intro @returns {[import('../src/api/types.js').Track, import('../src/api/types.js').Track]} */
  const pair = (outro, intro) => [
    track(1, { playback: { beatGrid: outro ? { intro: null, outro } : null } }),
    track(2, { album: { id: 2 }, playback: { beatGrid: intro ? { intro, outro: null } : null } }),
  ];

  it('tempos proches : vitesse de la suivante, le double et la moitié comptent', () => {
    expect(tempoMatch(120, 115)?.rate).toBeCloseTo(120 / 115, 6);
    expect(tempoMatch(120, 61)).toEqual({ rate: 120 / 122, factor: 2 });
    expect(tempoMatch(85, 172)).toEqual({ rate: 85 / 86, factor: 0.5 });
    expect(tempoMatch(120, 100)).toBeNull();
    expect(tempoMatch(120, 0)).toBeNull();
  });

  it('mesures entières, départ sur un temps, la suivante sur son premier temps', () => {
    const [a, b] = pair({ bpm: 120, offsetMs: 250 }, { bpm: 115, offsetMs: 100 });
    const t = beatTransition(a, b, F);
    expect(t.crossfadeMs).toBe(6000); // 12 temps à 120 BPM
    expect(t.fadeStartMs).toBe(188750); // un temps de la grille, et le fondu finit avant 195 s
    expect(t.nextStartMs).toBe(622); // 100 + 521,7 : le premier temps après le silence de 500 ms
    expect(t.sync?.rate).toBeCloseTo(120 / 115, 6);
    // 7 s demandées à 120 BPM : 16 temps (4 mesures), la durée entière la plus proche
    expect(beatTransition(a, b, { ...F, crossfadeMs: 7000 }).crossfadeMs).toBe(8000);
  });

  it('sinon, le fondu habituel', () => {
    const plain = transition(track(1), track(2, { album: { id: 2 } }), F);
    const [a, b] = pair({ bpm: 120, offsetMs: 250 }, { bpm: 115, offsetMs: 100 });
    expect(beatTransition(a, b, { ...F, beatSync: false })).toEqual({ ...plain, sync: null });
    expect(beatTransition(...pair({ bpm: 120, offsetMs: 250 }, null), F)).toEqual({ ...plain, sync: null });
    expect(beatTransition(...pair(null, { bpm: 120, offsetMs: 0 }), F)).toEqual({ ...plain, sync: null });
    expect(beatTransition(...pair({ bpm: 120, offsetMs: 250 }, { bpm: 100, offsetMs: 0 }), F)).toEqual({ ...plain, sync: null });
    expect(beatTransition(a, b, { ...F, crossfadeMs: 0 }).sync).toBeNull();
  });

  it('écart de phase, correction, retour à la vitesse normale', () => {
    const sync = { rate: 1.04, beatMs: 500, current: { bpm: 120, offsetMs: 0 }, next: { bpm: 120, offsetMs: 0 } };
    expect(phaseError(1000, 1100, sync)).toBeCloseTo(0.2, 6);
    expect(phaseError(1000, 900, sync)).toBeCloseTo(-0.2, 6);
    expect(phaseError(1000, 1450, sync)).toBeCloseTo(-0.1, 6); // au plus court : un dixième de temps en retard
    expect(syncedRate(sync, 0)).toBe(1.04);
    expect(syncedRate(sync, 0.04)).toBeCloseTo(1.04 * (1 - 0.01), 6);
    expect(syncedRate(sync, 0.4)).toBeCloseTo(1.04 * 0.97, 6); // 3 % au plus
    expect(glideToNormal(1.04)).toBeCloseTo(1.0395, 6);
    expect(glideToNormal(0.98)).toBeCloseTo(0.9805, 6);
    expect(glideToNormal(1.0003)).toBe(1);
    // Retour selon le temps écoulé : même durée écran verrouillé (minuteurs ralentis)
    expect(glideRate(1.06, 0)).toBe(1.06);
    expect(glideRate(1.06, 6000)).toBeCloseTo(1.03, 6);
    expect(glideRate(0.96, 9000)).toBeCloseTo(0.99, 6);
    expect(glideRate(1.06, 12000)).toBe(1);
  });
});

it('affichage des durées', () => {
  expect(formatTime(187541)).toBe('3:07');
  expect(formatTime(3723000)).toBe('1:02:03');
  expect(formatTime(-5)).toBe('0:00');
});
