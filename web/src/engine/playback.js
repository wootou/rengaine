// @ts-check
// Décisions de lecture, sans effet de bord (donc testables) :
//   - gain de normalisation (LEC-01) : le serveur donne la mesure, le client calcule le gain ;
//   - fondu enchaîné (LEC-02) : quand commencer, combien de temps, où démarrer la suivante ;
//   - fondu calé sur le tempo (LEC-06) : mesures entières, départ sur un temps, vitesse de la suivante
//     ajustée pour suivre la première, puis recalage de phase pendant le fondu.

/** @typedef {import('../api/types.js').Track} Track */
/** @typedef {import('../api/types.js').UserSettings['playback']} PlaybackSettings */

/** Deux pistes qui se suivent dans un même album, dans l'ordre (disque puis numéro). @param {Track | null | undefined} a @param {Track | null | undefined} b */
export function consecutiveInAlbum(a, b) {
  if (!a || !b || a.album.id !== b.album.id) return false;
  if (a.trackNumber === null || b.trackNumber === null) return true;
  return (b.discNumber === a.discNumber && b.trackNumber === a.trackNumber + 1)
    || (b.discNumber === a.discNumber + 1 && b.trackNumber <= 1);
}

/**
 * Faut-il le gain d'album ? En mode `auto` : oui quand la piste est entourée de pistes du même
 * album (on écoute l'album), non en aléatoire, en recherche ou en lecture continue.
 * @param {PlaybackSettings['normalization']} mode
 * @param {Track} track @param {Track | null | undefined} prev @param {Track | null | undefined} next
 */
export function useAlbumGain(mode, track, prev, next) {
  if (mode === 'album') return true;
  if (mode !== 'auto') return false;
  return consecutiveInAlbum(prev, track) || consecutiveInAlbum(track, next);
}

/**
 * Gain à appliquer, en dB.
 * @param {Track} track
 * @param {Pick<PlaybackSettings, 'normalization' | 'targetLufs' | 'preventClipping'>} s
 * @param {boolean} albumGain
 */
export function gainDb(track, s, albumGain) {
  if (s.normalization === 'off') return 0;
  const m = albumGain && track.playback.album.lufs !== null ? track.playback.album : track.playback.track;
  if (m.lufs === null) return 0; // pas encore mesurée : volume d'origine
  let gain = s.targetLufs - m.lufs;
  // Le gain est réduit si la crête dépasserait 0 dBTP
  if (s.preventClipping && m.truePeakDbtp !== null) gain = Math.min(gain, -m.truePeakDbtp);
  return Math.round(gain * 100) / 100;
}

/** @param {number} db */
export function dbToLinear(db) {
  return Math.pow(10, db / 20);
}

/**
 * Comment passer de `current` à `next`.
 * @param {Track} current @param {Track | null | undefined} next
 * @param {Pick<PlaybackSettings, 'crossfadeMs' | 'crossfadeWithinAlbum'>} s
 * @returns {{ crossfadeMs: number, fadeStartMs: number, nextStartMs: number }}
 *   crossfadeMs = 0 : enchaînement à la fin du fichier, sans fondu.
 */
export function transition(current, next, s) {
  const end = current.playback.audioEndMs ?? current.durationMs;
  const none = { crossfadeMs: 0, fadeStartMs: current.durationMs, nextStartMs: 0 };
  if (!next || s.crossfadeMs <= 0) return none;
  // Albums live, albums sans blanc : on ne fond pas deux pistes qui se suivent, sauf demande
  if (!s.crossfadeWithinAlbum && consecutiveInAlbum(current, next)) return none;
  // Jamais plus que le tiers de l'une ou l'autre piste
  const shortest = Math.min(end, next.durationMs);
  const fade = Math.max(0, Math.min(s.crossfadeMs, Math.floor(shortest / 3)));
  if (fade < 500) return none;
  return {
    crossfadeMs: fade,
    // Le fondu se termine quand le son de la piste s'arrête (silence de fin ignoré)
    fadeStartMs: Math.max(0, end - fade),
    // La suivante démarre là où son son commence (silence de début sauté)
    nextStartMs: next.playback.audioStartMs ?? 0,
  };
}

/** @typedef {{ bpm: number, offsetMs: number }} BeatGrid */
/** @typedef {{ rate: number, beatMs: number, current: BeatGrid, next: BeatGrid }} BeatSync */

/** Écart de tempo au plus pour caler un fondu : 6 % (au-delà, la suivante sonnerait trop vite ou trop lente). */
export const BEAT_SYNC_MAX_DELTA = 0.06;
/** Correction de vitesse au plus pendant le recalage de phase. */
export const PHASE_MAX_CORRECTION = 0.03;

/**
 * Vitesse à donner à la suivante pour qu'elle suive le tempo de la piste en cours. Un tempo double ou
 * moitié compte (85 et 170 BPM se calent temps sur temps). null si les tempos sont trop éloignés.
 * @param {number} currentBpm @param {number} nextBpm
 * @returns {{ rate: number, factor: number } | null} factor : tempo de la suivante multiplié par 0,5, 1 ou 2
 */
export function tempoMatch(currentBpm, nextBpm) {
  /** @type {{ rate: number, factor: number } | null} */
  let best = null;
  if (!(currentBpm > 0) || !(nextBpm > 0)) return null;
  for (const factor of [1, 2, 0.5]) {
    const rate = currentBpm / (nextBpm * factor);
    if (Math.abs(rate - 1) <= BEAT_SYNC_MAX_DELTA && (!best || Math.abs(rate - 1) < Math.abs(best.rate - 1))) best = { rate, factor };
  }
  return best;
}

/**
 * Fondu enchaîné, calé sur le tempo quand c'est possible (réglage `beatSync`, grilles des deux bouts,
 * tempos proches) ; sinon le fondu habituel (`transition`).
 * @param {Track} current @param {Track | null | undefined} next
 * @param {Pick<PlaybackSettings, 'crossfadeMs' | 'crossfadeWithinAlbum'> & { beatSync?: boolean }} s
 * @returns {{ crossfadeMs: number, fadeStartMs: number, nextStartMs: number, sync: BeatSync | null }}
 */
export function beatTransition(current, next, s) {
  const base = { ...transition(current, next, s), sync: null };
  if (s.beatSync === false || base.crossfadeMs === 0 || !next) return base;
  const out = current.playback.beatGrid?.outro;
  const intro = next.playback.beatGrid?.intro;
  if (!out || !intro) return base;
  const match = tempoMatch(out.bpm, intro.bpm);
  if (!match) return base;
  const beatMs = 60000 / out.bpm;
  const end = current.playback.audioEndMs ?? current.durationMs;
  // Un nombre entier de mesures (4 temps), le plus proche de la durée choisie, jamais plus du tiers d'une piste
  const limit = Math.min(end, next.durationMs) / 3;
  let beats = Math.max(4, Math.round(base.crossfadeMs / beatMs / 4) * 4);
  while (beats > 4 && beats * beatMs > limit) beats -= 4;
  const fadeMs = beats * beatMs;
  if (fadeMs > limit) return base;
  // Départ sur le dernier temps qui laisse finir le fondu avant la fin du son
  const n = Math.floor((end - fadeMs - out.offsetMs) / beatMs);
  if (n < 0) return base;
  const fadeStartMs = out.offsetMs + n * beatMs;
  // La suivante part sur son premier temps après son silence de début
  const nextBeat = 60000 / intro.bpm;
  const startMs = next.playback.audioStartMs ?? 0;
  const k = Math.max(0, Math.ceil((startMs - intro.offsetMs) / nextBeat - 1e-6));
  return {
    crossfadeMs: Math.round(fadeMs),
    fadeStartMs: Math.round(fadeStartMs),
    nextStartMs: Math.round(intro.offsetMs + k * nextBeat),
    sync: { rate: match.rate, beatMs, current: out, next: { bpm: intro.bpm * match.factor, offsetMs: intro.offsetMs } },
  };
}

/**
 * Position dans le temps en cours, de 0 à 1. @param {number} posMs @param {BeatGrid} grid
 */
export function beatPhase(posMs, grid) {
  const p = ((posMs - grid.offsetMs) * grid.bpm) / 60000;
  return p - Math.floor(p);
}

/**
 * Avance de la suivante sur la piste en cours, en temps (de -0,5 à 0,5 ; positif : la suivante est en avance).
 * @param {number} currentPosMs @param {number} nextPosMs @param {BeatSync} sync
 */
export function phaseError(currentPosMs, nextPosMs, sync) {
  const e = beatPhase(nextPosMs, sync.next) - beatPhase(currentPosMs, sync.current);
  return e - Math.round(e);
}

/**
 * Vitesse de la suivante pendant le fondu : celle qui suit le tempo, corrigée pour rattraper l'écart de
 * phase en deux secondes environ (3 % au plus).
 * @param {BeatSync} sync @param {number} error écart en temps (voir phaseError)
 */
export function syncedRate(sync, error) {
  const correction = Math.max(-PHASE_MAX_CORRECTION, Math.min(PHASE_MAX_CORRECTION, (error * sync.beatMs) / 2000));
  return sync.rate * (1 - correction);
}

/** Durée du retour à la vitesse normale après un fondu calé. */
export const GLIDE_MS = 12000;

/**
 * Vitesse pendant le retour à la normale, selon le temps écoulé (pas le nombre de pas : écran verrouillé,
 * les minuteurs ne passent plus qu'une fois par seconde). @param {number} from @param {number} elapsedMs
 */
export function glideRate(from, elapsedMs) {
  const t = Math.min(1, Math.max(0, elapsedMs / GLIDE_MS));
  return t >= 1 ? 1 : from + (1 - from) * t;
}

/**
 * Retour progressif à la vitesse normale après un fondu calé : 0,05 % par pas (100 ms), soit 6 % en 12 s.
 * @param {number} rate
 */
export function glideToNormal(rate) {
  const step = 0.0005;
  return Math.abs(rate - 1) <= step ? 1 : rate + (rate < 1 ? step : -step);
}

/** « 3:07 », « 1:02:03 ». @param {number} ms */
export function formatTime(ms) {
  const total = Math.max(0, Math.floor(ms / 1000));
  const h = Math.floor(total / 3600);
  const m = Math.floor((total % 3600) / 60);
  const sec = String(total % 60).padStart(2, '0');
  return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${sec}` : `${m}:${sec}`;
}
