// @ts-check
// Forme d'onde (AUD-07) : hauteur des barres et chemin SVG.
import { describe, expect, it } from 'vitest';
import { waveBars, wavePath } from '../src/components/Waveform.jsx';

describe('forme d’onde', () => {
  it('hauteur rapportée à la tranche la plus forte, sur 30 dB ; silence = pas de barre', () => {
    const h = waveBars([-10, null, -40, -20], 4);
    expect(h[0]).toBe(1);
    expect(h[1]).toBe(0);
    expect(h[2]).toBe(0.06); // 30 dB sous la plus forte : hauteur minimale
    expect(h[3]).toBeCloseTo(2 / 3, 6);
  });

  it('chaque barre garde le plus fort de ses tranches', () => {
    expect(waveBars([-30, -10, -20, -20], 2)).toEqual([1, 1 - 10 / 30]);
    expect(waveBars([-10], 3)).toEqual([1, 1, 1]); // moins de tranches que de barres
    expect(waveBars([null, null], 2)).toEqual([0, 0]);
    expect(waveBars([], 2)).toEqual([]);
  });

  it('une barre par rectangle, centrée ; aucune pour un silence', () => {
    expect(wavePath([1, 0, 0.5])).toBe('M0.15 0.0h0.7v100.0h-0.7ZM2.15 25.0h0.7v50.0h-0.7Z');
  });
});
