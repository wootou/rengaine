<?php
// Genres des albums et des pistes (voir docs/CONCEPTION.md § 5 « Genres »).
//   - Album : les genres choisis (import de meta.json, admin, agent) priment ; sinon les genres
//     majoritaires des tags de ses pistes.
//   - Piste : ses propres genres (correction, ou tag dans une compilation), sinon ceux de l'album.
//   - Un tag ne crée jamais de genre : il passe par genre_aliases.

declare(strict_types=1);

namespace Musique\Library;

use Musique\Db\Database;
use Musique\Storage\Storage;

final class Genres
{
    /** @var array<string, ?int> clé de comparaison → genre */
    private array $aliasCache = [];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Les genres donnés et tous leurs sous-genres (familles de genres, CAT-08).
     * @param list<int> $ids
     * @return list<int>
     */
    public function withDescendants(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $children = [];
        foreach ($this->db->all('SELECT id, parent_id FROM genres WHERE parent_id IS NOT NULL') as $g) {
            $children[(int) $g['parent_id']][] = (int) $g['id'];
        }
        $out = [];
        $todo = array_map('intval', $ids);
        while ($todo !== []) {
            $id = array_pop($todo);
            if (isset($out[$id])) {
                continue; // garde-fou contre une boucle
            }
            $out[$id] = true;
            array_push($todo, ...($children[$id] ?? []));
        }
        return array_keys($out);
    }

    /**
     * Valeurs de genre d'un tag (« Pop; Rock » → 2 valeurs). La barre oblique n'est pas un
     * séparateur : « Hip-Hop/Rap » est un seul genre.
     * @return list<string>
     */
    public static function splitTag(?string $value): array
    {
        if ($value === null) {
            return [];
        }
        $out = [];
        foreach (preg_split('/\s*[;\x00]\s*/u', $value) ?: [] as $v) {
            $v = Text::clean($v);
            if ($v !== null && mb_strlen($v) <= 100) {
                $out[] = $v;
            }
        }
        return array_values(array_unique($out));
    }

    /** Genre rattaché à une valeur de tag (en notant la valeur si elle est nouvelle). */
    public function genreForTag(string $value): ?int
    {
        $k = mb_strtolower($value);
        if (array_key_exists($k, $this->aliasCache)) {
            return $this->aliasCache[$k];
        }
        // Nouvelle valeur : rattachée d'office si un genre porte ce nom (sans casse ni accents)
        $this->db->run(
            'INSERT IGNORE INTO genre_aliases (alias, genre_id) VALUES (?, (SELECT id FROM genres WHERE name = ? LIMIT 1))',
            [$value, $value]
        );
        $id = $this->db->value('SELECT genre_id FROM genre_aliases WHERE alias = ?', [$value]);
        return $this->aliasCache[$k] = $id === null ? null : (int) $id;
    }

    /** Recalcule les genres de l'album (s'ils viennent des tags) et les genres effectifs de ses pistes. */
    public function refreshAlbum(int $albumId): void
    {
        $album = $this->db->one('SELECT is_compilation FROM albums WHERE id = ?', [$albumId]);
        if ($album === null) {
            return;
        }
        $files = $this->db->all(
            "SELECT t.id AS track_id, JSON_VALUE(tf.tags_raw, '$.genre') AS genre
               FROM tracks t JOIN track_files tf ON tf.track_id = t.id
              WHERE t.album_id = ? AND t.missing_since IS NULL",
            [$albumId]
        );
        /** @var array<int, array<int, true>> $tagGenresByTrack */
        $tagGenresByTrack = [];
        foreach ($files as $f) {
            $tagGenresByTrack[(int) $f['track_id']] ??= [];
            foreach (self::splitTag($f['genre']) as $value) {
                $gid = $this->genreForTag($value);
                if ($gid !== null) {
                    $tagGenresByTrack[(int) $f['track_id']][$gid] = true;
                }
            }
        }

        // 1. Genres de l'album issus des tags, seulement s'il n'a pas de genre choisi
        $curated = (int) $this->db->value(
            "SELECT COUNT(*) FROM album_genres WHERE album_id = ? AND source <> 'tag'", [$albumId]
        );
        $this->db->run("DELETE FROM album_genres WHERE album_id = ? AND source = 'tag'", [$albumId]);
        if ($curated === 0 && $tagGenresByTrack !== []) {
            $counts = [];
            foreach ($tagGenresByTrack as $genres) {
                foreach (array_keys($genres) as $gid) {
                    $counts[$gid] = ($counts[$gid] ?? 0) + 1;
                }
            }
            foreach ($counts as $gid => $n) {
                if ($n / count($tagGenresByTrack) >= 0.5) {
                    $this->db->run("INSERT IGNORE INTO album_genres (album_id, genre_id, source) VALUES (?, ?, 'tag')", [$albumId, $gid]);
                }
            }
        }

        // 2. Genres effectifs des pistes
        $albumGenres = array_map('intval', array_column(
            $this->db->all('SELECT genre_id FROM album_genres WHERE album_id = ?', [$albumId]), 'genre_id'
        ));
        $corrected = [];
        foreach ($this->db->all(
            "SELECT c.entity_id, c.value, c.created_by FROM corrections c JOIN tracks t ON t.id = c.entity_id
              WHERE c.entity_type = 'track' AND c.field = 'genre_ids' AND c.status = 'active' AND t.album_id = ?
              ORDER BY c.id",
            [$albumId]
        ) as $c) {
            $corrected[(int) $c['entity_id']] = json_decode((string) $c['value'], true);
        }

        $trackIds = array_map('intval', array_column(
            $this->db->all('SELECT id FROM tracks WHERE album_id = ?', [$albumId]), 'id'
        ));
        if ($trackIds !== []) {
            $in = implode(',', array_fill(0, count($trackIds), '?'));
            $this->db->run("DELETE FROM track_genres WHERE track_id IN ($in)", $trackIds);
        }
        foreach ($trackIds as $tid) {
            $own = [];
            $source = 'album';
            if (array_key_exists($tid, $corrected) && is_array($corrected[$tid])) {
                // Un genre supprimé depuis la correction est simplement ignoré
                $own = array_values(array_intersect(array_map('intval', $corrected[$tid]), $this->existingGenres()));
                $source = 'manual';
            } elseif ((bool) $album['is_compilation']) {
                $own = array_keys($tagGenresByTrack[$tid] ?? []);
                $source = 'tag';
            }
            $effective = $own !== [] ? $own : $albumGenres;
            $src = $own !== [] ? $source : 'album';
            foreach ($effective as $gid) {
                $this->db->run('INSERT IGNORE INTO track_genres (track_id, genre_id, source) VALUES (?, ?, ?)', [$tid, $gid, $src]);
            }
        }
    }

    /** @return list<int> */
    private function existingGenres(): array
    {
        return array_map('intval', array_column($this->db->all('SELECT id FROM genres'), 'id'));
    }

    /** Nombre de pistes par valeur de tag (affiché dans l'admin, pour rattacher d'abord les plus fréquentes). */
    public function recountAliases(): void
    {
        $counts = [];
        foreach ($this->db->all(
            "SELECT JSON_VALUE(tf.tags_raw, '$.genre') AS genre, COUNT(DISTINCT tf.track_id) AS n
               FROM track_files tf JOIN tracks t ON t.id = tf.track_id
              WHERE t.missing_since IS NULL GROUP BY genre"
        ) as $row) {
            foreach (self::splitTag($row['genre']) as $value) {
                $k = mb_strtolower($value);
                $counts[$k] = ['value' => $value, 'n' => ($counts[$k]['n'] ?? 0) + (int) $row['n']];
            }
        }
        $this->db->run('UPDATE genre_aliases SET track_count = 0');
        foreach ($counts as $c) {
            $this->db->run('UPDATE genre_aliases SET track_count = ? WHERE alias = ?', [$c['n'], $c['value']]);
        }
    }

    /**
     * Reprise des genres de l'ancien site (CAT-07) : <album>/meta.json, puis genres_manual.json
     * (prioritaire, clé = nom du dossier). Crée les genres manquants. Renvoie [albums importés, erreurs].
     * @param ?callable(string, string): void $onError (clé, message)
     * @return array{0: int, 1: int}
     */
    public function importLegacy(Storage $storage, ?string $manualFile, ?callable $onError = null): array
    {
        $manual = [];
        if ($manualFile !== null) {
            $data = self::decodeJson((string) @file_get_contents($manualFile));
            foreach (is_array($data) ? $data : [] as $dir => $genre) {
                if (!str_starts_with((string) $dir, '_') && is_string($genre) && Text::clean($genre) !== null) {
                    $manual[Text::key((string) $dir)] = Text::clean($genre);
                }
            }
        }

        $imported = $errors = 0;
        foreach ($this->db->all('SELECT id, source_dir FROM albums WHERE source_dir IS NOT NULL') as $album) {
            $dir = (string) $album['source_dir'];
            $genre = $manual[Text::key(basename($dir))] ?? null;
            if ($genre === null) {
                $metaKey = ($dir === '' ? '' : "$dir/") . 'meta.json';
                if ($storage->stat($metaKey) === null) {
                    continue;
                }
                $raw = $storage->get($metaKey);
                $corrupted = !mb_check_encoding($raw, 'UTF-8');
                // Octets invalides remplacés par « ? » : on sait où la valeur est abîmée
                $meta = self::decodeJson($corrupted ? mb_scrub($raw, 'UTF-8') : $raw);
                if (!is_array($meta)) {
                    $errors++;
                    $onError && $onError($metaKey, 'meta.json illisible');
                    continue;
                }
                $genre = Text::clean(is_string($meta['genre'] ?? null) ? $meta['genre'] : null);
                if ($genre !== null && ($corrupted || str_contains($genre, "\u{FFFD}"))) {
                    // 1. Un tag des pistes qui commence pareil ; 2. sinon, lecture en Windows-1252
                    //    (ancien encodage), sauf si elle donne des « ÿ » : octets détruits, irréparable.
                    $repaired = $this->repairFromTags((int) $album['id'], $genre);
                    if ($repaired === null) {
                        $legacy = self::decodeJson(Text::utf8($raw));
                        $repaired = Text::clean(is_array($legacy) && is_string($legacy['genre'] ?? null) ? $legacy['genre'] : null);
                        if ($repaired !== null && preg_match('/[\x{00FF}\x{FFFD}]/u', $repaired)) {
                            $repaired = null;
                        }
                    }
                    if ($repaired === null) {
                        $errors++;
                        $onError && $onError($metaKey, 'genre abîmé (octets illisibles), aucun tag correspondant : ignoré');
                        continue;
                    }
                    $genre = $repaired;
                }
            }
            if ($genre === null) {
                continue;
            }
            $this->db->run('INSERT IGNORE INTO genres (name) VALUES (?)', [$genre]);
            $gid = (int) $this->db->value('SELECT id FROM genres WHERE name = ?', [$genre]);
            $this->db->run("DELETE FROM album_genres WHERE album_id = ? AND source IN ('import', 'tag')", [$album['id']]);
            $this->db->run("INSERT IGNORE INTO album_genres (album_id, genre_id, source) VALUES (?, ?, 'import')", [$album['id'], $gid]);
            $imported++;
        }
        $this->remapAliases();
        foreach ($this->db->all('SELECT id FROM albums') as $a) {
            $this->refreshAlbum((int) $a['id']);
        }
        $this->recountAliases();
        return [$imported, $errors];
    }

    /**
     * Genre abîmé (« Alternatif Et Ind?? ») : on cherche parmi les tags de genre des pistes de
     * l'album une valeur qui commence pareil (« Alternatif et Indé »).
     */
    private function repairFromTags(int $albumId, string $damaged): ?string
    {
        $readable = preg_split('/[?\x{FFFD}]/u', $damaged)[0] ?? '';
        $prefix = Text::key($readable);
        if (mb_strlen($prefix) < 3) {
            return null;
        }
        $rows = $this->db->all(
            "SELECT DISTINCT JSON_VALUE(tf.tags_raw, '$.genre') AS genre FROM track_files tf JOIN tracks t ON t.id = tf.track_id WHERE t.album_id = ?",
            [$albumId]
        );
        foreach ($rows as $r) {
            foreach (self::splitTag($r['genre']) as $value) {
                if (str_starts_with(Text::key($value), $prefix)) {
                    return $value;
                }
            }
        }
        return null;
    }

    /** Oublie les rattachements lus (après un changement de genre_aliases). */
    public function forgetAliases(): void
    {
        $this->aliasCache = [];
    }

    /**
     * Albums dont une piste porte cette valeur de genre dans ses tags (pour les recalculer).
     * @return list<int>
     */
    public function albumsWithAlias(string $alias): array
    {
        $out = [];
        foreach ($this->db->all(
            "SELECT DISTINCT t.album_id, JSON_VALUE(tf.tags_raw, '$.genre') AS genre FROM track_files tf JOIN tracks t ON t.id = tf.track_id
              WHERE JSON_VALUE(tf.tags_raw, '$.genre') LIKE ?", ['%' . addcslashes($alias, '%_\\') . '%']
        ) as $r) {
            foreach (self::splitTag($r['genre']) as $v) {
                if (mb_strtolower($v) === mb_strtolower($alias)) {
                    $out[(int) $r['album_id']] = true;
                }
            }
        }
        return array_keys($out);
    }

    /** Rattache les valeurs de tag orphelines aux genres qui portent maintenant le même nom. */
    public function remapAliases(): void
    {
        $this->db->run('UPDATE genre_aliases ga JOIN genres g ON g.name = ga.alias SET ga.genre_id = g.id WHERE ga.genre_id IS NULL');
        $this->aliasCache = [];
    }

    /** JSON tolérant : UTF-8 invalide converti plutôt que rejeté (leçon de l'ancien site). */
    public static function decodeJson(string $raw): mixed
    {
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw; // BOM
        $data = json_decode($raw, true);
        if ($data === null && json_last_error() === JSON_ERROR_UTF8) {
            $data = json_decode(Text::utf8($raw), true);
        }
        return $data;
    }
}
