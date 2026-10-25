// @ts-check
// Adresse du flux d'une piste selon la qualité choisie (LEC-04).
// « original » sert le fichier tel quel ; sinon le serveur convertit en Opus (ou en MP3 pour un
// navigateur qui ne lit pas l'Opus). Le choix suit les réglages : un niveau pour le wifi, un pour
// les données mobiles (connu seulement des navigateurs qui exposent navigator.connection).
// Une conversion pas encore en cache ne permet pas d'avancer dans le fichier : on redemande alors
// la piste à la bonne position (startMs), d'où le drapeau `transcoded` rendu au lecteur.

/** @typedef {'original' | 'high' | 'medium' | 'low'} Quality */

/** Débits en kbit/s, alignés sur StreamQuality du contrat. */
export const BITRATES = {
  opus: { high: 256, medium: 160, low: 96 },
  mp3: { high: 320, medium: 192, low: 128 },
};

/** Données mobiles ? Sans l'information (Firefox, Safari), on suppose le wifi. @param {any} [nav] */
export function connectionKind(nav = typeof navigator === 'undefined' ? undefined : navigator) {
  const c = nav?.connection;
  return c?.type === 'cellular' ? 'mobile' : 'wifi';
}

/** @type {boolean | undefined} */
let opus;
export function canPlayOpus() {
  if (opus === undefined) {
    try {
      opus = typeof document !== 'undefined' && document.createElement('audio').canPlayType('audio/ogg; codecs=opus') !== '';
    } catch {
      opus = false;
    }
  }
  return opus;
}

/**
 * Le serveur convertira-t-il ? Même règle que Transcoder::plan() : jamais de MP3 en MP3, ni d'une
 * source avec perte vers un débit égal ou supérieur. @param {{ format: string, lossless: boolean, bitrateKbps?: number | null } | undefined} best
 * @param {'opus' | 'mp3'} format @param {number} bitrate
 */
export function willConvert(best, format, bitrate) {
  if (!best) return false;
  if (best.lossless) return true;
  if (best.format === format) return false;
  return !(best.bitrateKbps && bitrate >= best.bitrateKbps);
}

/**
 * @param {{ streamUrl: string, formats?: Array<{ format: string, lossless: boolean, bitrateKbps?: number | null }> }} track
 * @param {number} startMs
 * @param {{ quality?: Quality | null, transcoding?: boolean, opus?: boolean }} o
 * @returns {{ url: string, transcoded: boolean }}
 */
export function streamSource(track, startMs, o) {
  const quality = o.quality ?? 'original';
  if (!o.transcoding || quality === 'original') return { url: track.streamUrl, transcoded: false };
  const format = o.opus ? 'opus' : 'mp3';
  const bitrate = BITRATES[format][quality];
  if (!bitrate || !willConvert(track.formats?.[0], format, bitrate)) return { url: track.streamUrl, transcoded: false };
  const params = new URLSearchParams({ format, bitrate: String(bitrate) });
  if (startMs > 0) params.set('startMs', String(Math.round(startMs)));
  return { url: `${track.streamUrl}?${params}`, transcoded: true };
}
