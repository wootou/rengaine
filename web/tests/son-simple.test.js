// @ts-check
// « Son simplifié » : actif d'office sur Firefox Android, un choix explicite l'emporte.
import { afterEach, describe, expect, it, vi } from 'vitest';
import { isFirefoxAndroid, setSimpleAudio, simpleAudio } from '../src/engine/player.js';

const FIREFOX_ANDROID = 'Mozilla/5.0 (Android 16; Mobile; rv:158.0) Gecko/158.0 Firefox/158.0';
const CHROME_ANDROID = 'Mozilla/5.0 (Linux; Android 16; CPH2663) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Mobile Safari/537.36';
const FIREFOX_PC = 'Mozilla/5.0 (X11; Linux x86_64; rv:158.0) Gecko/20100101 Firefox/158.0';

afterEach(() => {
  localStorage.clear();
  vi.unstubAllGlobals();
});

describe('son simplifié', () => {
  it('Firefox Android seulement', () => {
    expect(isFirefoxAndroid(FIREFOX_ANDROID)).toBe(true);
    expect(isFirefoxAndroid(CHROME_ANDROID)).toBe(false);
    expect(isFirefoxAndroid(FIREFOX_PC)).toBe(false);
  });

  it('actif d’office sur Firefox Android, sauf choix contraire ; ailleurs, seulement si on le demande', () => {
    vi.stubGlobal('navigator', { userAgent: FIREFOX_ANDROID });
    expect(simpleAudio()).toBe(true);
    setSimpleAudio(false);
    expect(simpleAudio()).toBe(false);
    vi.stubGlobal('navigator', { userAgent: CHROME_ANDROID });
    localStorage.clear();
    expect(simpleAudio()).toBe(false);
    setSimpleAudio(true);
    expect(simpleAudio()).toBe(true);
  });
});

describe('vitesse de lecture', () => {
  it('le retour à 1 se fait même depuis un écart minuscule ; les autres petits écarts sont ignorés', async () => {
    const { Player } = await import('../src/engine/player.js');
    const audio = /** @type {HTMLAudioElement} */ ({ playbackRate: 1.00005, preservesPitch: true });
    Player.setRate(audio, 1.00009);
    expect(audio.playbackRate).toBe(1.00005);
    Player.setRate(audio, 1);
    expect(audio.playbackRate).toBe(1);
  });
});
