import { describe, expect, it } from 'vitest';
import { djLabel, keyName } from '../src/engine/dj.js';

describe('BPM et tonalité affichés', () => {
  it('nomme les tonalités en français', () => {
    expect(keyName('Am')).toBe('La mineur');
    expect(keyName('C')).toBe('Do majeur');
    expect(keyName('F#m')).toBe('Fa♯ mineur');
    expect(keyName('Eb')).toBe('Mi♭ majeur');
    expect(keyName('H')).toBeNull();
    expect(keyName(null)).toBeNull();
  });

  it('assemble la ligne, sans ce qui manque', () => {
    expect(djLabel({ bpm: 123.6, key: 'Am', camelot: '8A' })).toBe('124 BPM · La mineur (8A)');
    expect(djLabel({ bpm: 90, key: null, camelot: null })).toBe('90 BPM');
    expect(djLabel({ bpm: null, key: 'Db', camelot: '3B' })).toBe('Ré♭ majeur (3B)');
    expect(djLabel({ bpm: null, key: null, camelot: null })).toBeNull();
    expect(djLabel(null)).toBeNull();
  });
});
