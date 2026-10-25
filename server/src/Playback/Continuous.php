<?php
// Lecture continue intelligente (LEC-03) : choisit les pistes qui suivent la piste en cours selon les
// règles `continuous` des réglages de l'utilisateur.
//
// 1. Des candidates tirées au hasard dans la bibliothèque, filtrées : genres voulus et exclus, pas
//    déjà dans la file, pas écoutées depuis `noRepeatHours`. Plus des candidates déjà compatibles
//    avec la piste en cours (clé, BPM) et des favoris, pour ne pas dépendre du hasard du tirage.
// 2. On enchaîne : chaque piste choisie devient la référence de la suivante. Une candidate doit
//    avoir une clé compatible (Camelot : même code, ±1, ou A↔B), un BPM dans la tolérance (le
//    double ou la moitié comptent aussi), suivre la tendance d'énergie, et ne pas reprendre un
//    artiste des `artistSpacing` dernières pistes.
// 3. Sans candidate qui respecte tout, on relâche une règle après l'autre (énergie, BPM, clé,
//    artistes) plutôt que de laisser la musique s'arrêter.
// `favoritesWeight` : chance qu'une place revienne à un favori (piste, album ou artiste), s'il en reste.

declare(strict_types=1);

namespace Musique\Playback;

use Musique\Catalog\Moods;
use Musique\Db\Database;

final class Continuous
{
    private const POOL = 500;
    private const TARGETED = 300;
    /** Règles relâchées dans cet ordre quand plus aucune candidate ne les respecte toutes. */
    private const RELAX = ['energy', 'bpm', 'key', 'artist'];

    /** @var \Closure(int, int): int */
    private \Closure $rand;

    /** @param ?callable(int, int): int $rand pour les tests (par défaut random_int) */
    public function __construct(private readonly Database $db, ?callable $rand = null)
    {
        $this->rand = \Closure::fromCallable($rand ?? 'random_int');
    }

    /**
     * @param array{genres: list<int>, excludedGenres: list<int>, favoritesWeight: float|int, harmonicMixing: bool,
     *              bpmTolerancePercent: ?int, energyTrend: string, noRepeatHours: int, artistSpacing: int} $rules
     * @param list<int> $recent pistes déjà dans la file (dans l'ordre), à ne pas proposer
     * @return list<int> identifiants des pistes, dans l'ordre de lecture
     */
    public function next(int $userId, ?int $currentId, array $recent, int $count, array $rules): array
    {
        $exclude = array_values(array_unique(array_map('intval', array_merge($recent, $currentId !== null ? [$currentId] : []))));
        $ref = $currentId !== null ? ($this->describe([$currentId])[$currentId] ?? null) : null;

        [$where, $args] = $this->filters($userId, $exclude, $rules);
        $pool = $this->candidates($where, $args, self::POOL, $userId);
        if ($ref !== null && ($rules['harmonicMixing'] || $rules['bpmTolerancePercent'] !== null)) {
            [$w, $a] = $this->targeted($ref, $rules);
            if ($w !== []) {
                $pool += $this->candidates(array_merge($where, $w), array_merge($args, $a), self::TARGETED, $userId);
            }
        }
        if ((float) $rules['favoritesWeight'] > 0) {
            $pool += $this->candidates(array_merge($where, [self::favoriteSql()]), array_merge($args, [$userId, $userId, $userId, $userId]), self::TARGETED, $userId);
        }

        // Artistes des dernières pistes de la file, pour l'espacement
        $spacing = max(0, (int) $rules['artistSpacing']);
        $tail = array_slice($exclude, -$spacing);
        $known = $spacing > 0 ? $this->describe($tail) : [];
        $lastArtists = array_map(static fn ($id) => $known[$id]['artists'] ?? [], $tail);

        $chosen = [];
        $prev = $ref;
        for ($i = 0; $i < $count && $pool !== []; $i++) {
            $wantFavorite = (float) $rules['favoritesWeight'] > 0 && ($this->rand)(0, 999) < (float) $rules['favoritesWeight'] * 1000;
            $pick = $this->pick($pool, $prev, $rules, $wantFavorite, $spacing > 0 ? array_slice($lastArtists, -$spacing) : []);
            if ($pick === null) {
                break;
            }
            $chosen[] = $pick['id'];
            unset($pool[$pick['id']]);
            $lastArtists[] = $pick['artists'];
            $prev = $pick;
        }
        return $chosen;
    }

    // ---------------------------------------------------------------- choix

    /**
     * @param array<int, array<string, mixed>> $pool
     * @param ?array<string, mixed> $prev
     * @param list<list<int>> $recentArtists
     * @return ?array<string, mixed>
     */
    private function pick(array $pool, ?array $prev, array $rules, bool $wantFavorite, array $recentArtists): ?array
    {
        $banned = $recentArtists === [] ? [] : array_fill_keys(array_merge(...$recentArtists), true);
        for ($relaxed = 0; $relaxed <= count(self::RELAX); $relaxed++) {
            $off = array_fill_keys(array_slice(self::RELAX, 0, $relaxed), true);
            $ok = array_filter($pool, fn ($c) => $this->fits($c, $prev, $rules, $off, $banned));
            if ($ok === []) {
                continue;
            }
            if ($wantFavorite) {
                $fav = array_filter($ok, static fn ($c) => $c['favorite']);
                $ok = $fav !== [] ? $fav : $ok;
            }
            $keys = array_keys($ok);
            return $ok[$keys[($this->rand)(0, count($keys) - 1)]];
        }
        return null;
    }

    /** @param array<string, bool> $off règles relâchées @param array<int, bool> $banned artistes trop récents */
    private function fits(array $c, ?array $prev, array $rules, array $off, array $banned): bool
    {
        if (!isset($off['artist']) && array_intersect_key(array_fill_keys($c['artists'], true), $banned) !== []) {
            return false;
        }
        if ($prev === null) {
            return true;
        }
        if (!isset($off['key']) && $rules['harmonicMixing'] && $prev['camelot'] !== null
            && ($c['camelot'] === null || !in_array($c['camelot'], self::compatible($prev['camelot']), true))) {
            return false;
        }
        if (!isset($off['bpm']) && $rules['bpmTolerancePercent'] !== null && $prev['bpm'] !== null
            && ($c['bpm'] === null || !self::bpmClose($prev['bpm'], $c['bpm'], (int) $rules['bpmTolerancePercent']))) {
            return false;
        }
        if (!isset($off['energy']) && $rules['energyTrend'] !== 'any' && $prev['energy'] !== null && $c['energy'] !== null) {
            $d = $c['energy'] - $prev['energy'];
            $okEnergy = match ($rules['energyTrend']) {
                'steady' => abs($d) <= 0.1,
                'rising' => $d >= -0.02,
                'falling' => $d <= 0.02,
                default => true,
            };
            if (!$okEnergy) {
                return false;
            }
        }
        return true;
    }

    /**
     * Codes Camelot qui s'enchaînent avec $code : lui-même, ±1 sur la roue, et l'autre lettre.
     * @return list<string>
     */
    public static function compatible(string $code): array
    {
        if (!preg_match('/^(1[0-2]|[1-9])([AB])$/', $code, $m)) {
            return [];
        }
        $n = (int) $m[1];
        $other = $m[2] === 'A' ? 'B' : 'A';
        return [$code, (($n + 10) % 12 + 1) . $m[2], ($n % 12 + 1) . $m[2], $n . $other];
    }

    /** BPM dans la tolérance (en %), en acceptant le double ou la moitié du tempo. */
    public static function bpmClose(float $ref, float $bpm, int $percent): bool
    {
        foreach ([$bpm, $bpm * 2, $bpm / 2] as $b) {
            if (abs($b - $ref) <= $ref * $percent / 100 + 1e-9) {
                return true;
            }
        }
        return false;
    }

    // ---------------------------------------------------------------- requêtes

    /** @return array{0: list<string>, 1: list<mixed>} */
    private function filters(int $userId, array $exclude, array $rules): array
    {
        $where = ['t.missing_since IS NULL'];
        $args = [];
        if ($rules['genres'] !== []) {
            $where[] = 'EXISTS (SELECT 1 FROM track_genres tg WHERE tg.track_id = t.id AND tg.genre_id IN (' . self::in($rules['genres']) . '))';
            array_push($args, ...$rules['genres']);
        }
        if ($rules['excludedGenres'] !== []) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM track_genres tg WHERE tg.track_id = t.id AND tg.genre_id IN (' . self::in($rules['excludedGenres']) . '))';
            array_push($args, ...$rules['excludedGenres']);
        }
        if ($exclude !== []) {
            $where[] = 't.id NOT IN (' . self::in($exclude) . ')';
            array_push($args, ...$exclude);
        }
        // Ambiance, années, collection (CAT-08) : remplacements d'une demande (page Genres)
        if (($rules['mood'] ?? null) !== null) {
            $where[] = Moods::where((string) $rules['mood']);
        }
        if (($rules['yearFrom'] ?? null) !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM albums ay WHERE ay.id = t.album_id AND ay.year >= ?)';
            $args[] = (int) $rules['yearFrom'];
        }
        if (($rules['yearTo'] ?? null) !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM albums ay WHERE ay.id = t.album_id AND ay.year <= ?)';
            $args[] = (int) $rules['yearTo'];
        }
        if (($rules['collection'] ?? null) !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM collection_albums ca WHERE ca.album_id = t.album_id AND ca.collection_id = ?)';
            $args[] = (int) $rules['collection'];
        }
        if ((int) $rules['noRepeatHours'] > 0) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM play_history h WHERE h.user_id = ? AND h.track_id = t.id
                                     AND h.started_at > CURRENT_TIMESTAMP(3) - INTERVAL ' . (int) $rules['noRepeatHours'] . ' HOUR)';
            $args[] = $userId;
        }
        return [$where, $args];
    }

    /** Conditions qui ne gardent que des pistes compatibles avec la référence. @return array{0: list<string>, 1: list<mixed>} */
    private function targeted(array $ref, array $rules): array
    {
        $where = [];
        $args = [];
        if ($rules['harmonicMixing'] && $ref['camelot'] !== null && ($codes = self::compatible($ref['camelot'])) !== []) {
            $where[] = 'an.camelot IN (' . self::in($codes) . ')';
            array_push($args, ...$codes);
        }
        if ($rules['bpmTolerancePercent'] !== null && $ref['bpm'] !== null) {
            $t = (int) $rules['bpmTolerancePercent'] / 100;
            $ranges = [];
            foreach ([1, 2, 0.5] as $f) {
                $ranges[] = '(an.bpm BETWEEN ? AND ?)';
                array_push($args, $ref['bpm'] * (1 - $t) / $f, $ref['bpm'] * (1 + $t) / $f);
            }
            $where[] = '(' . implode(' OR ', $ranges) . ')';
        }
        return [$where, $args];
    }

    private static function favoriteSql(): string
    {
        return "(EXISTS (SELECT 1 FROM favorites f WHERE f.user_id = ? AND f.item_type = 'track' AND f.item_id = t.id)
              OR EXISTS (SELECT 1 FROM favorites f WHERE f.user_id = ? AND f.item_type = 'album' AND f.item_id = t.album_id)
              OR EXISTS (SELECT 1 FROM track_artists ta JOIN favorites f ON f.user_id = ? AND f.item_type = 'artist' AND f.item_id = ta.artist_id WHERE ta.track_id = t.id)
              OR EXISTS (SELECT 1 FROM albums a JOIN favorites f ON f.user_id = ? AND f.item_type = 'artist' AND f.item_id = a.album_artist_id WHERE a.id = t.album_id))";
    }

    /** @return array<int, array<string, mixed>> */
    private function candidates(array $where, array $args, int $limit, int $userId): array
    {
        $ids = array_map('intval', array_column($this->db->all(
            'SELECT t.id FROM tracks t LEFT JOIN track_analysis an ON an.track_id = t.id WHERE ' . implode(' AND ', $where) . " ORDER BY RAND() LIMIT $limit",
            $args
        ), 'id'));
        return $this->describe($ids, $userId);
    }

    /**
     * Clé, BPM, énergie, artistes (et favori) de chaque piste.
     * @param list<int> $ids
     * @return array<int, array{id: int, camelot: ?string, bpm: ?float, energy: ?float, artists: list<int>, favorite: bool}>
     */
    private function describe(array $ids, ?int $userId = null): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->all(
            'SELECT t.id, an.camelot, an.bpm, an.energy, a.album_artist_id,
                    (SELECT GROUP_CONCAT(ta.artist_id) FROM track_artists ta WHERE ta.track_id = t.id) AS artist_ids'
                . ($userId !== null ? ', ' . self::favoriteSql() . ' AS fav' : '') . '
               FROM tracks t JOIN albums a ON a.id = t.album_id LEFT JOIN track_analysis an ON an.track_id = t.id
              WHERE t.id IN (' . self::in($ids) . ')',
            array_merge($userId !== null ? [$userId, $userId, $userId, $userId] : [], $ids)
        );
        $out = [];
        foreach ($rows as $r) {
            $artists = $r['artist_ids'] !== null ? array_map('intval', explode(',', (string) $r['artist_ids'])) : [];
            if ($artists === [] && $r['album_artist_id'] !== null) {
                $artists = [(int) $r['album_artist_id']];
            }
            $out[(int) $r['id']] = [
                'id' => (int) $r['id'],
                'camelot' => $r['camelot'],
                'bpm' => $r['bpm'] !== null ? (float) $r['bpm'] : null,
                'energy' => $r['energy'] !== null ? (float) $r['energy'] : null,
                'artists' => array_values(array_unique($artists)),
                'favorite' => (bool) ($r['fav'] ?? false),
            ];
        }
        return $out;
    }

    private static function in(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
