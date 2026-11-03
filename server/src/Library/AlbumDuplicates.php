<?php
// Albums en double complets (deux dossiers pour le même album, souvent une copie sans tags aux pistes
// « Piste N ») : des paires à vérifier avant de proposer d'en retirer une (album.removed) ou de fusionner.
// Rien n'est modifié ici. Différent des « doublons » MP3 + FLAC d'un même album (Duplicates).
//   - sameTitle : même titre une fois la casse, les accents, la ponctuation et les mentions entre
//     parenthèses ou crochets retirées (« Costello Music (Deluxe) » = « Costello Music ») ;
//   - closeTitle : une ou deux lettres d'écart.
// L'artiste doit être le même, sauf si l'un des deux n'en a pas (copie sans tags).

declare(strict_types=1);

namespace Musique\Library;

use Musique\Db\Database;

final class AlbumDuplicates
{
    private const MIN_LENGTH = 4;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Toutes les paires, les plus sûres d'abord ; dans chaque paire, la copie la plus complète d'abord.
     * @return list<array{albums: list<array<string, mixed>>, reason: string, distance: int}>
     */
    public function pairs(): array
    {
        $albums = $this->albums();
        $byTitle = [];
        foreach ($albums as $a) {
            $byTitle['t' . $a['titleKey']][] = $a; // préfixe : une clé « 1984 » resterait sinon un entier
        }
        $pairs = [];
        $seen = [];
        $add = static function (array $a, array $b, string $reason, int $distance) use (&$pairs, &$seen): void {
            if ($a['artistKey'] !== '' && $b['artistKey'] !== '' && $a['artistKey'] !== $b['artistKey']) {
                return;
            }
            $k = min($a['id'], $b['id']) . '-' . max($a['id'], $b['id']);
            if (isset($seen[$k])) {
                return;
            }
            $seen[$k] = true;
            $rank = static fn ($x) => [$x['trackCount'], (int) $x['hasLossless'], -$x['id']];
            $pairs[] = ['albums' => array_map(self::out(...), $rank($a) >= $rank($b) ? [$a, $b] : [$b, $a]), 'reason' => $reason, 'distance' => $distance];
        };
        foreach ($byTitle as $group) {
            for ($i = 0; $i < count($group); $i++) {
                for ($j = $i + 1; $j < count($group); $j++) {
                    $add($group[$i], $group[$j], 'sameTitle', 0);
                }
            }
        }
        // Titres proches, comparés seulement entre albums du même artiste
        $byArtist = [];
        foreach ($albums as $a) {
            if ($a['artistKey'] !== '' && strlen($a['titleKey']) >= self::MIN_LENGTH) {
                $byArtist['a' . $a['artistKey']][] = $a;
            }
        }
        foreach ($byArtist as $group) {
            for ($i = 0; $i < count($group); $i++) {
                for ($j = $i + 1; $j < count($group); $j++) {
                    [$x, $y] = [$group[$i]['titleKey'], $group[$j]['titleKey']];
                    $max = min(strlen($x), strlen($y)) <= 6 ? 1 : 2;
                    if ($x !== $y && abs(strlen($x) - strlen($y)) <= $max && ($d = levenshtein($x, $y)) <= $max) {
                        $add($group[$i], $group[$j], 'closeTitle', $d);
                    }
                }
            }
        }
        usort($pairs, static fn ($a, $b) => [$a['distance'], $a['albums'][0]['title']] <=> [$b['distance'], $b['albums'][0]['title']]);
        return $pairs;
    }

    /** @return list<array<string, mixed>> */
    private function albums(): array
    {
        $out = [];
        foreach ($this->db->all(
            'SELECT a.id, a.title, a.year, a.kind, a.track_count, a.duration_ms, a.has_lossless, a.source_dir, ar.name AS artist
               FROM albums a LEFT JOIN artists ar ON ar.id = a.album_artist_id
              WHERE a.missing_since IS NULL AND a.track_count > 0'
        ) as $r) {
            // Les dossiers en vrac ne sont pas des albums : leurs titres (noms de dossiers) se ressemblent souvent
            if ($r['kind'] === 'loose') {
                continue;
            }
            $title = preg_replace('/\s*[(\[][^)\]]*[)\]]/u', '', (string) $r['title']) ?: (string) $r['title'];
            $titleKey = self::ascii($title);
            if ($titleKey === '') {
                continue;
            }
            $out[] = [
                'id' => (int) $r['id'], 'title' => (string) $r['title'], 'artistName' => $r['artist'], 'year' => $r['year'] === null ? null : (int) $r['year'],
                'trackCount' => (int) $r['track_count'], 'durationMs' => (int) $r['duration_ms'], 'hasLossless' => (bool) $r['has_lossless'],
                'sourceDir' => $r['source_dir'], 'titleKey' => $titleKey, 'artistKey' => self::ascii((string) ($r['artist'] ?? '')),
            ];
        }
        return $out;
    }

    /** Clé ASCII sans espaces (levenshtein compte les octets). */
    private static function ascii(string $s): string
    {
        $key = str_replace(' ', '', Text::key($s));
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $key);
        return $ascii === false ? $key : (preg_replace('/[^a-z0-9]/', '', strtolower($ascii)) ?? $key);
    }

    /** @param array<string, mixed> $a */
    private static function out(array $a): array
    {
        return array_intersect_key($a, array_flip(['id', 'title', 'artistName', 'year', 'trackCount', 'durationMs', 'hasLossless', 'sourceDir']));
    }
}
