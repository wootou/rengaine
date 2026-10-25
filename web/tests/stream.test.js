// @ts-check
import { describe, expect, it } from 'vitest';
import { connectionKind, streamSource, willConvert } from '../src/engine/stream.js';

const flac = { streamUrl: '/api/v2/tracks/7/stream', formats: [{ format: 'flac', lossless: true, bitrateKbps: 900 }] };
const mp3 = { streamUrl: '/api/v2/tracks/8/stream', formats: [{ format: 'mp3', lossless: false, bitrateKbps: 192 }] };

describe('adresse du flux', () => {
  it('origine : le fichier tel quel', () => {
    expect(streamSource(flac, 0, { quality: 'original', transcoding: true, opus: true })).toEqual({ url: flac.streamUrl, transcoded: false });
    expect(streamSource(flac, 0, { quality: 'low', transcoding: false, opus: true }).transcoded).toBe(false);
  });

  it('FLAC → Opus au débit du niveau choisi, MP3 si le navigateur ne lit pas l’Opus', () => {
    expect(streamSource(flac, 0, { quality: 'medium', transcoding: true, opus: true }).url).toBe('/api/v2/tracks/7/stream?format=opus&bitrate=160');
    expect(streamSource(flac, 0, { quality: 'high', transcoding: true, opus: false }).url).toBe('/api/v2/tracks/7/stream?format=mp3&bitrate=320');
    expect(streamSource(flac, 61234.4, { quality: 'low', transcoding: true, opus: true }).url).toBe('/api/v2/tracks/7/stream?format=opus&bitrate=96&startMs=61234');
  });

  it('même règle que le serveur : pas de MP3 en MP3, pas vers un débit supérieur', () => {
    expect(streamSource(mp3, 0, { quality: 'low', transcoding: true, opus: false }).transcoded).toBe(false);
    expect(streamSource(mp3, 0, { quality: 'high', transcoding: true, opus: true }).transcoded).toBe(false);
    expect(streamSource(mp3, 0, { quality: 'low', transcoding: true, opus: true }).url).toContain('format=opus&bitrate=96');
    expect(willConvert(undefined, 'opus', 96)).toBe(false);
  });

  it('données mobiles seulement si le navigateur le dit', () => {
    expect(connectionKind({ connection: { type: 'cellular' } })).toBe('mobile');
    expect(connectionKind({ connection: { type: 'wifi' } })).toBe('wifi');
    expect(connectionKind({})).toBe('wifi');
  });
});
