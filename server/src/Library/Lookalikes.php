<?php
// Artistes écrits de plusieurs façons (« Ska P » / « Ska-P », « Frances Cabrel » / « Francis Cabrel ») :
// des paires à vérifier avant de proposer une fusion (mergedInto). Rien n'est modifié ici.
//   - sameKey : même nom une fois la casse, les accents, la ponctuation et les espaces retirés ;
//   - close : une ou deux lettres de différence (une seule pour les noms courts).
// Pour ne pas comparer chaque nom à tous les autres, on ne compare que les noms qui commencent ou
// finissent par les mêmes lettres : une faute au milieu du nom est trouvée, une faute à la fois au
// début et à la fin ne l'est pas.

declare(strict_types=1);

namespace Musique\Library;

use Musique\Db\Database;

final class Lookalikes
{
    /** Noms plus courts : trop de faux amis (« Abba » / « Alba »). */
    private const MIN_LENGTH = 4;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Toutes les paires, les plus sûres d'abord.
     * @return list<array{artists: list<array{id: int, name: string, albumCount: int, trackCount: int}>, reason: string, distance: int}>
     */
    public function pairs(): array
    {
        $artists = $this->artists();
        $pairs = [];
        $seen = [];
        $add = static function (array $a, array $b, string $reason, int $distance) use (&$pairs, &$seen): void {
            $k = min($a['id'], $b['id']) . '-' . max($a['id'], $b['id']);
            if (isset($seen[$k])) {
                return;
            }
            $seen[$k] = true;
            // Le plus présent d'abord : candidat naturel de la fusion
            $weight = static fn ($x) => [$x['albumCount'] + $x['trackCount'], -$x['id']];
            $pairs[] = ['artists' => $weight($a) >= $weight($b) ? [$a, $b] : [$b, $a], 'reason' => $reason, 'distance' => $distance];
        };

        // Même clé
        $byKey = [];
        foreach ($artists as $a) {
            $byKey[$a['key']][] = $a;
        }
        foreach ($byKey as $group) {
            for ($i = 0; $i < count($group); $i++) {
                for ($j = $i + 1; $j < count($group); $j++) {
                    $add(self::out($group[$i]), self::out($group[$j]), 'sameKey', 0);
                }
            }
        }

        // Noms proches : comparés par blocs (mêmes 2 premières lettres, ou mêmes 3 dernières)
        $blocks = [];
        foreach (array_keys($byKey) as $key) {
            if (strlen($key) < self::MIN_LENGTH) {
                continue;
            }
            $blocks['p' . substr($key, 0, 2)][] = $key;
            $blocks['s' . substr($key, -3)][] = $key;
        }
        foreach ($blocks as $keys) {
            $n = count($keys);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    [$x, $y] = [$keys[$i], $keys[$j]];
                    $max = min(strlen($x), strlen($y)) <= 6 ? 1 : 2;
                    if (abs(strlen($x) - strlen($y)) > $max) {
                        continue;
                    }
                    $d = levenshtein($x, $y);
                    if ($d > 0 && $d <= $max) {
                        $add(self::out($byKey[$x][0]), self::out($byKey[$y][0]), 'close', $d);
                    }
                }
            }
        }

        usort($pairs, static fn ($a, $b) => [$a['distance'], -self::weight($a)] <=> [$b['distance'], -self::weight($b)]);
        return $pairs;
    }

    /** @return list<array{id: int, name: string, albumCount: int, trackCount: int, key: string}> */
    private function artists(): array
    {
        $rows = $this->db->all(
            "SELECT ar.id, ar.name,
                    (SELECT COUNT(*) FROM albums a WHERE a.album_artist_id = ar.id AND a.missing_since IS NULL) AS album_count,
                    (SELECT COUNT(DISTINCT ta.track_id) FROM track_artists ta JOIN tracks t ON t.id = ta.track_id
                      WHERE ta.artist_id = ar.id AND t.missing_since IS NULL) AS track_count
               FROM artists ar
              WHERE NOT EXISTS (SELECT 1 FROM corrections c WHERE c.entity_type = 'artist' AND c.entity_id = ar.id
                                  AND c.field = 'merged_into' AND c.status = 'active')"
        );
        $out = [];
        foreach ($rows as $r) {
            if ((int) $r['album_count'] + (int) $r['track_count'] === 0) {
                continue;
            }
            // Clé ASCII (levenshtein compte les octets) : sans accents, casse, ponctuation ni espaces
            $key = str_replace(' ', '', Text::key((string) $r['name']));
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $key);
            $key = $ascii === false ? $key : (preg_replace('/[^a-z0-9]/', '', strtolower($ascii)) ?: $key);
            if ($key === '') {
                continue;
            }
            $out[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'albumCount' => (int) $r['album_count'], 'trackCount' => (int) $r['track_count'], 'key' => $key];
        }
        return $out;
    }

    /** @param array{id: int, name: string, albumCount: int, trackCount: int, key: string} $a */
    private static function out(array $a): array
    {
        return ['id' => $a['id'], 'name' => $a['name'], 'albumCount' => $a['albumCount'], 'trackCount' => $a['trackCount']];
    }

    private static function weight(array $pair): int
    {
        return array_sum(array_map(static fn ($a) => $a['albumCount'] + $a['trackCount'], $pair['artists']));
    }
}
