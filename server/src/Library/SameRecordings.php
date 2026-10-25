<?php
// Même enregistrement dans plusieurs pistes (BAS-03), d'après l'empreinte acoustique (voir
// Audio\Fingerprint) : repérage par les valeurs indexées, puis vérification complète.
// Sert à la migration (prod, Nextcloud, le reste) : rien n'est retiré ici, on signale seulement.

declare(strict_types=1);

namespace Musique\Library;

use Musique\Audio\Fingerprint;
use Musique\Db\Database;

final class SameRecordings
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Enregistre l'empreinte d'une piste, l'indexe et cherche ses pareilles.
     * @param list<int> $fp
     * @return int nombre de pistes reconnues comme le même enregistrement
     */
    public function save(int $trackId, array $fp): int
    {
        $this->db->run('INSERT IGNORE INTO track_analysis (track_id) VALUES (?)', [$trackId]);
        $this->db->run('UPDATE track_analysis SET chromaprint = ? WHERE track_id = ?', [Fingerprint::encode($fp), $trackId]);
        return $this->match($trackId, $fp, $this->index($trackId, $fp));
    }

    /** Valeurs indexées d'une piste, remplacées. @param list<int> $fp @return list<int> */
    private function index(int $trackId, array $fp): array
    {
        $this->db->run('DELETE FROM track_fingerprint_keys WHERE track_id = ?', [$trackId]);
        $keys = Fingerprint::keys($fp);
        foreach (array_chunk($keys, 200) as $chunk) {
            $this->db->run(
                'INSERT IGNORE INTO track_fingerprint_keys (fp_key, track_id) VALUES ' . implode(',', array_fill(0, count($chunk), '(?, ?)')),
                array_merge(...array_map(static fn (int $k): array => [$k, $trackId], $chunk))
            );
        }
        return $keys;
    }

    /**
     * Compare une piste à ses candidates et met à jour ses paires.
     * @param list<int> $fp @param list<int> $keys
     */
    public function match(int $trackId, array $fp, array $keys): int
    {
        $this->db->run('DELETE FROM same_recordings WHERE track_a = ? OR track_b = ?', [$trackId, $trackId]);
        if ($keys === [] || $fp === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($keys), '?'));
        // Pistes qui partagent assez de valeurs, en ignorant celles trop répandues (silence, bruit)
        $candidates = $this->db->all(
            "SELECT k.track_id, COUNT(*) AS shared
               FROM track_fingerprint_keys k
               JOIN tracks t ON t.id = k.track_id AND t.missing_since IS NULL
              WHERE k.fp_key IN ($in) AND k.track_id <> ?
                AND k.fp_key NOT IN (SELECT fp_key FROM track_fingerprint_keys WHERE fp_key IN ($in)
                                      GROUP BY fp_key HAVING COUNT(*) > ?)
              GROUP BY k.track_id HAVING shared >= ? ORDER BY shared DESC LIMIT 50",
            [...$keys, $trackId, ...$keys, Fingerprint::COMMON_KEY_TRACKS, Fingerprint::MIN_SHARED_KEYS]
        );
        $found = 0;
        foreach ($candidates as $c) {
            $other = (int) $c['track_id'];
            $theirs = Fingerprint::decode($this->db->value('SELECT chromaprint FROM track_analysis WHERE track_id = ?', [$other]));
            if ($theirs === []) {
                continue;
            }
            // Toujours dans le même sens (a < b) : le décalage est celui de b par rapport à a
            [$a, $b, $fa, $fb] = $trackId < $other ? [$trackId, $other, $fp, $theirs] : [$other, $trackId, $theirs, $fp];
            $r = Fingerprint::compare($fa, $fb);
            if ($r['score'] < Fingerprint::CLOSE) {
                continue;
            }
            $this->db->run(
                'INSERT INTO same_recordings (track_a, track_b, score, offset_ms) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE score = VALUES(score), offset_ms = VALUES(offset_ms), found_at = CURRENT_TIMESTAMP(3)',
                [$a, $b, $r['score'], -Fingerprint::offsetMs($r['offset'])]
            );
            $found++;
        }
        return $found;
    }

    /** Refait l'index et toutes les comparaisons (méthode ou seuils changés). @return array{tracks: int, pairs: int} */
    public function rebuild(?callable $progress = null): array
    {
        $this->db->run('DELETE FROM same_recordings');
        $rows = $this->db->all('SELECT track_id, chromaprint FROM track_analysis WHERE chromaprint IS NOT NULL ORDER BY track_id');
        // Tout l'index d'abord, pour que chaque piste trouve toutes ses pareilles
        foreach ($rows as $row) {
            $this->index((int) $row['track_id'], Fingerprint::decode($row['chromaprint']));
        }
        $n = 0;
        foreach ($rows as $row) {
            $fp = Fingerprint::decode($row['chromaprint']);
            $this->match((int) $row['track_id'], $fp, Fingerprint::keys($fp));
            $n++;
            if ($progress !== null && $n % 500 === 0) {
                $progress("… $n pistes");
            }
        }
        return ['tracks' => $n, 'pairs' => (int) $this->db->value('SELECT COUNT(*) FROM same_recordings')];
    }

    /**
     * Groupes de pistes du même enregistrement (composantes connexes des paires), les plus sûrs d'abord.
     * @return list<array{trackIds: list<int>, score: float}>
     */
    public function groups(float $minScore = Fingerprint::CLOSE): array
    {
        $parent = [];
        $find = static function (int $x) use (&$parent, &$find): int {
            if (!isset($parent[$x]) || $parent[$x] === $x) {
                return $parent[$x] = $x;
            }
            return $parent[$x] = $find($parent[$x]);
        };
        $low = [];
        $rows = $this->db->all(
            'SELECT s.track_a, s.track_b, s.score FROM same_recordings s
               JOIN tracks ta ON ta.id = s.track_a AND ta.missing_since IS NULL
               JOIN tracks tb ON tb.id = s.track_b AND tb.missing_since IS NULL
              WHERE s.score >= ?', [$minScore]
        );
        foreach ($rows as $r) {
            $a = $find((int) $r['track_a']);
            $b = $find((int) $r['track_b']);
            if ($a !== $b) {
                $parent[max($a, $b)] = min($a, $b);
            }
        }
        $groups = [];
        foreach ($rows as $r) {
            $root = $find((int) $r['track_a']);
            $groups[$root]['ids'][(int) $r['track_a']] = true;
            $groups[$root]['ids'][(int) $r['track_b']] = true;
            $low[$root] = min($low[$root] ?? 1.0, (float) $r['score']);
        }
        $out = [];
        foreach ($groups as $root => $g) {
            $ids = array_keys($g['ids']);
            sort($ids);
            $out[] = ['trackIds' => $ids, 'score' => $low[$root]];
        }
        usort($out, static fn ($x, $y) => [$y['score'], $x['trackIds'][0]] <=> [$x['score'], $y['trackIds'][0]]);
        return $out;
    }
}
