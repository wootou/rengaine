<?php
// Ambiances calculées (CAT-08) : des filtres sur l'énergie, la dansabilité et le tempo mesurés
// (AUD-03, AUD-06), sans rien saisir ni stocker. Seuils réglés sur la bibliothèque de dev le 2026-10-22.

declare(strict_types=1);

namespace Musique\Catalog;

use Musique\Db\Database;
use Musique\Lang;

final class Moods
{
    /** id => [nom, description, condition SQL sur track_analysis « an »] */
    public const ALL = [
        'calme' => ['Calme', 'Doux et posé : ballades, acoustique', 'an.energy < 0.35'],
        'concentration' => ['Pour se concentrer', 'Présent sans prendre toute la place', 'an.energy BETWEEN 0.3 AND 0.6 AND an.danceability < 0.55'],
        'energique' => ['Énergique', 'Fort, rapide, plein d’attaques', 'an.energy >= 0.7'],
        'danser' => ['Pour danser', 'Rythme régulier entre 95 et 135 BPM', 'an.danceability >= 0.7 AND an.bpm BETWEEN 95 AND 135'],
    ];

    /** Condition SQL d'une ambiance (sur la jointure « an » = track_analysis). */
    public static function where(string $id): string
    {
        return '(' . (self::ALL[$id][2] ?? throw new \InvalidArgumentException("Ambiance inconnue : $id")) . ')';
    }

    /** @return list<array{id: string, name: string, description: string, trackCount: int}> */
    public static function list(Database $db): array
    {
        $out = [];
        foreach (self::ALL as $id => [$name, $description]) {
            $n = (int) $db->value('SELECT COUNT(*) FROM tracks t JOIN track_analysis an ON an.track_id = t.id WHERE t.missing_since IS NULL AND ' . self::where($id));
            $out[] = ['id' => $id, 'name' => Lang::tr($name), 'description' => Lang::tr($description), 'trackCount' => $n];
        }
        return $out;
    }
}
