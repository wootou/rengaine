// @ts-check
/** @typedef {import('../src/api/types.js').Track} Track */

/**
 * Piste de test au format du contrat.
 * @param {number} id
 * @param {Omit<Partial<Track>, 'album' | 'playback'> & { album?: Partial<Track['album']>, playback?: Partial<Track['playback']> }} [o]
 * @returns {Track}
 */
export function track(id, o = {}) {
  const { album, playback, ...rest } = o;
  return {
    id,
    title: `Piste ${id}`,
    artistCredit: 'Artiste',
    artists: [],
    album: { id: 1, title: 'Album', artistName: 'Artiste', year: 2020, ...album },
    genres: [],
    discNumber: 1,
    trackNumber: id,
    durationMs: 200000,
    coverUrl: null,
    streamUrl: `/api/v2/tracks/${id}/stream`,
    formats: [],
    playback: {
      track: { lufs: -10, truePeakDbtp: -1 },
      album: { lufs: -11, truePeakDbtp: 0.5 },
      audioStartMs: 500,
      audioEndMs: 195000,
      bpm: null, key: null, camelot: null, energy: null,
      ...playback,
    },
    favorite: false,
    available: true,
    ...rest,
  };
}
