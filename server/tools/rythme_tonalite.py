#!/opt/rengaine-analyse/bin/python
"""BPM, tonalité (AUD-03, AUD-04), énergie et dansabilité (AUD-06) d'un fichier audio, avec Essentia.

Usage : rythme_tonalite.py <fichier>
        rythme_tonalite.py --version     (version d'Essentia et de la méthode)
Écrit une ligne JSON sur la sortie standard :
  {"bpm": 124.1, "bpmConfidence": 3.2, "key": "A", "scale": "minor", "keyStrength": 0.71,
   "energy": 0.69, "danceability": 0.47, "beatGrid": {"intro": {"bpm": 124.06, "offsetMs": 312.5}, "outro": null}}
beatGrid (LEC-06) : grilles régulières des temps sur les 30 premières et les 30 dernières secondes (un temps
tombe à offsetMs + n × 60000 / bpm) ; null quand les temps détectés s'en écartent (tempo libre, fondu, pas
de rythme). Sert à caler les fondus enchaînés sur le tempo.
bpmConfidence va de 0 à 5,3 (au-dessous de 1,5 : peu fiable) ; keyStrength, energy et danceability de 0 à 1.
Énergie (pas de mesure toute faite dans Essentia) : 45 % volume moyen (RMS, de -20 à -8 dBFS), 35 % densité
d'attaques (de 1,5 à 6 par seconde), 20 % brillance (centre de gravité du spectre, de 1000 à 4000 Hz).
Dansabilité : algorithme Danceability d'Essentia (DFA), de 0,8 à 1,4 ramené entre 0 et 1.
Pistes longues (plus de LONG_S, mixes et sets) : Essentia charge tout le morceau en mémoire (plus de 2 Go
pour une heure) et refuse au-delà d'une certaine durée. On n'analyse alors que des extraits : BPM, tonalité,
énergie et dansabilité sur BODY_S au milieu, grilles des temps sur EDGE_LOAD_S au début et à la fin.
Code de sortie 1 et message sur la sortie d'erreur si le fichier est illisible.
"""
import json
import os
import sys

os.environ.setdefault("ESSENTIA_LOG_LEVEL", "error")

import math  # noqa: E402

import essentia  # noqa: E402
import essentia.standard as es  # noqa: E402
import numpy as np  # noqa: E402

essentia.log.infoActive = False
essentia.log.warningActive = False

# À changer quand la méthode change : l'analyseur remet alors BPM et tonalité en file.
METHOD = "rx2013-multifeature+key-bgate+energy1+dfa+grid1"


def scale(value: float, low: float, high: float) -> float:
    """Ramène value entre 0 (low) et 1 (high)."""
    return min(1.0, max(0.0, (value - low) / (high - low)))


def energy(audio) -> float:
    rms_db = 20 * math.log10(max(float(np.sqrt(np.mean(audio ** 2))), 1e-9))
    _onsets, rate = es.OnsetRate()(audio)
    window, spectrum, centroid = es.Windowing(type="hann"), es.Spectrum(), es.Centroid(range=22050)
    cents = [centroid(spectrum(window(f))) for f in es.FrameGenerator(audio, frameSize=2048, hopSize=4096)
             if float(np.max(np.abs(f))) > 1e-4]
    bright = float(np.mean(cents)) if cents else 0.0
    return 0.45 * scale(rms_db, -20, -8) + 0.35 * scale(float(rate), 1.5, 6) + 0.20 * scale(bright, 1000, 4000)


# Écart toléré entre les temps détectés et la grille (médiane, en ms), et durée prise à chaque bout
GRID_TOLERANCE_MS = 25
GRID_EDGE_S = 30


def local_grid(t, bpm: float):
    """Grille régulière ajustée aux temps t (moindres carrés), ou None si elle ne tient pas."""
    if bpm <= 0 or len(t) < 16:
        return None
    period = 60.0 / bpm
    # Numéro de chaque temps : on compte les temps manqués entre deux détections
    steps = np.maximum(1, np.round(np.diff(t) / period))
    n = np.concatenate([[0.0], np.cumsum(steps)])
    slope, intercept = np.polyfit(n, t, 1)
    if slope <= 0:
        return None
    residual = np.abs(t - (intercept + slope * n)) * 1000
    if float(np.median(residual)) > GRID_TOLERANCE_MS:
        return None
    # Le temps de la grille le plus proche de 0 (la grille ne vaut que pour ce bout du morceau)
    return {"bpm": round(60.0 / float(slope), 3), "offsetMs": round(float(intercept % slope) * 1000, 1)}


def beat_grid(beats, bpm: float):
    """Grilles du début et de la fin : un humain ne garde pas un tempo exact sur tout un morceau."""
    t = np.asarray(beats, dtype=float)
    if len(t) < 16:
        return None
    intro = local_grid(t[t <= t[0] + GRID_EDGE_S], bpm)
    outro = local_grid(t[t >= t[-1] - GRID_EDGE_S], bpm)
    return {"intro": intro, "outro": outro} if intro or outro else None


# Pistes longues : seuil, extrait du milieu, extraits du début et de la fin (en secondes)
LONG_S = 20 * 60
BODY_S = 10 * 60
EDGE_LOAD_S = 60


def load(filename: str, start: float, end: float):
    """Extrait [start, end] en mono 44,1 kHz, sans changer le volume (EasyLoader baisse de 6 dB par défaut)."""
    return es.EasyLoader(filename=filename, sampleRate=44100, startTime=start, endTime=end, replayGain=0)()


def edge_grid(filename: str, start: float, end: float, head: bool):
    """Grille des temps sur un extrait du début ou de la fin, en temps absolus du morceau."""
    audio = load(filename, start, end)
    if len(audio) < 44100 * 10:
        return None
    bpm, beats, _c, _e, _i = es.RhythmExtractor2013(method="multifeature")(audio)
    t = np.asarray(beats, dtype=float) + start
    if len(t) < 16:
        return None
    return local_grid(t[t <= t[0] + GRID_EDGE_S] if head else t[t >= t[-1] - GRID_EDGE_S], float(bpm))


def long_track(filename: str, duration: int) -> int:
    middle = duration / 2
    audio = load(filename, middle - BODY_S / 2, middle + BODY_S / 2)
    bpm, _beats, confidence, _est, _intervals = es.RhythmExtractor2013(method="multifeature")(audio)
    key, mode, strength = es.KeyExtractor(profileType="bgate")(audio)
    dance, _dfa = es.Danceability()(audio)
    nrg = energy(audio)
    del audio
    intro = edge_grid(filename, 0, EDGE_LOAD_S, True)
    outro = edge_grid(filename, max(0, duration - EDGE_LOAD_S), duration + 1, False)
    print(json.dumps({
        "bpm": round(float(bpm), 1),
        "bpmConfidence": round(float(confidence), 2),
        "key": key,
        "scale": mode,
        "keyStrength": round(float(strength), 3),
        "energy": round(nrg, 3),
        "danceability": round(scale(float(dance), 0.8, 1.4), 3),
        "beatGrid": {"intro": intro, "outro": outro} if intro or outro else None,
    }))
    return 0


def main() -> int:
    if sys.argv[1:] == ["--version"]:
        print(f"essentia {essentia.__version__};{METHOD}")
        return 0
    if len(sys.argv) != 2:
        print("usage : rythme_tonalite.py <fichier>", file=sys.stderr)
        return 2
    try:
        duration = int(es.MetadataReader(filename=sys.argv[1])()[-4])
        if duration > LONG_S:
            return long_track(sys.argv[1], duration)
        audio = es.MonoLoader(filename=sys.argv[1], sampleRate=44100)()
    except Exception as e:  # fichier illisible
        print(f"lecture impossible : {e}", file=sys.stderr)
        return 1
    if len(audio) < 44100 * 5:
        print(json.dumps({"bpm": None, "bpmConfidence": 0, "key": None, "scale": None, "keyStrength": 0, "energy": None, "danceability": None, "beatGrid": None}))
        return 0
    bpm, beats, confidence, _est, _intervals = es.RhythmExtractor2013(method="multifeature")(audio)
    key, mode, strength = es.KeyExtractor(profileType="bgate")(audio)
    dance, _dfa = es.Danceability()(audio)
    print(json.dumps({
        "bpm": round(float(bpm), 1),
        "bpmConfidence": round(float(confidence), 2),
        "key": key,
        "scale": mode,
        "keyStrength": round(float(strength), 3),
        "energy": round(energy(audio), 3),
        "danceability": round(scale(float(dance), 0.8, 1.4), 3),
        "beatGrid": beat_grid(beats, float(bpm)),
    }))
    return 0


if __name__ == "__main__":
    sys.exit(main())
