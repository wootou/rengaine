<?php
// Rangement du dépôt (CAT-05). On dépose un album dans `inbox/` (rclone, Nextcloud, copie à la
// main) ; une fois le dossier immobile, il est rangé sous un nom lisible tiré des tags :
//   Artiste/Album (année)/01 - Titre.flac        album ordinaire
//   Artiste/Album (année)/CD2/01 - Titre.flac    plusieurs disques
//   Compilations/Album (année)/01 - Artiste - Titre.mp3
// Les pochettes et autres fichiers suivent l'album. Rien n'est écrasé ni supprimé : un cas douteux
// (plusieurs albums mélangés, artiste introuvable, fichier déjà présent) reste dans le dépôt, avec
// sa raison dans `inbox_items` et un seul signalement dans les erreurs de tâche.

declare(strict_types=1);

namespace Musique\Library;

use Musique\Db\Database;
use Musique\Jobs\JobLog;
use Musique\Log\Logger;
use Musique\Storage\Storage;
use Musique\Storage\StorageObject;

final class Inbox
{
    public const PREFIX = 'inbox/';
    public const COMPILATIONS = 'Compilations';
    /** Fichier en cours de copie (rclone, Nextcloud, navigateurs, qBittorrent…) : on attend. */
    private const BUSY = '/\.(part|partial|filepart|tmp|crdownload|download|!qb|ocTransferId\d+\.part)$/i';
    private const KEEP_FILED_DAYS = 30;

    public function __construct(
        private readonly Database $db,
        private readonly Storage $storage,
        private readonly Logger $logger,
        private readonly Probe $probe,
        /** Un dossier n'est rangé qu'après ce délai sans changement. */
        private readonly int $waitMinutes = 10,
        /** Album incomplet d'après ses tags (« 3/12 ») : rangé quand même après ce délai. */
        private readonly int $incompleteHours = 24,
    ) {
    }

    /** Réglages de la section [depot] (facultative) de rengaine.ini. */
    public static function fromConfig(\Musique\Config $config, Database $db, Storage $storage, Logger $logger): self
    {
        return new self($db, $storage, $logger, new Probe($config->int('indexeur', 'processus', 8)),
            $config->int('depot', 'attente_minutes', 10), $config->int('depot', 'attente_incomplet_heures', 24));
    }

    /** Vrai pour une clé du dépôt (l'indexeur l'ignore). */
    public static function contains(string $key): bool
    {
        return str_starts_with($key, self::PREFIX);
    }

    /**
     * Un passage sur le dépôt.
     * @param bool $dryRun n'affiche que le plan : rien n'est déplacé ni enregistré
     * @param ?callable(string): void $progress
     * @return array{stats: array<string, int>, filed: list<string>, plans: list<array<string, mixed>>}
     */
    public function run(?int $requestedBy = null, ?callable $progress = null, ?int $queuedJobId = null, bool $dryRun = false): array
    {
        $progress ??= static function (string $msg): void {};
        if (!$dryRun && (int) $this->db->value("SELECT GET_LOCK('musique:inbox', 0)") !== 1) {
            throw new \RuntimeException('Un rangement du dépôt est déjà en cours.');
        }
        $job = null;
        try {
            $groups = $this->groups();
            $known = $this->db->value("SELECT COUNT(*) FROM inbox_items WHERE status <> 'filed'");
            // Dépôt vide et rien en attente : pas de passage enregistré (le minuteur tourne toutes les 15 min)
            if ($groups === [] && (int) $known === 0 && $queuedJobId === null) {
                return ['stats' => [], 'filed' => [], 'plans' => []];
            }
            // Passage enregistré seulement s'il se passe quelque chose (rangement, nouveau blocage…)
            $ensureJob = function () use (&$job, $dryRun, $requestedBy, $queuedJobId): ?JobLog {
                return $dryRun ? null : ($job ??= new JobLog($this->db, $this->logger, 'inbox', [], $requestedBy, $queuedJobId));
            };
            if ($queuedJobId !== null) {
                $ensureJob();
            }
            $filed = [];
            $plans = [];
            foreach ($groups as $dir => $objects) {
                try {
                    $res = $this->handle($dir, $objects, $dryRun);
                } catch (\Throwable $e) {
                    $res = ['status' => 'blocked', 'changed' => true, 'message' => 'Rangement interrompu : ' . $e->getMessage()];
                    $dryRun || $this->save($dir, $objects, $res);
                }
                if ($res['changed']) {
                    $j = $ensureJob();
                    $j?->count($res['status']);
                    $j?->count('files_moved', (int) ($res['moved'] ?? 0));
                    if ($res['status'] === 'blocked') {
                        $j?->error('inbox_blocked', (string) $res['message'], $dir);
                    }
                }
                unset($res['changed']);
                $plans[] = ['dir' => $dir] + $res;
                if ($res['status'] === 'filed') {
                    $filed[] = (string) $res['targetDir'];
                }
                $progress(sprintf('%s : %s%s', $dir, $res['status'], isset($res['message']) ? " ({$res['message']})" : ''));
            }
            if (!$dryRun) {
                // Dossiers retirés du dépôt par quelqu'un : plus rien à suivre. Rangements anciens oubliés.
                $present = array_map(static fn ($d) => hash('sha256', (string) $d, true), array_keys($groups));
                foreach ($this->db->all("SELECT id, source_hash FROM inbox_items WHERE status <> 'filed'") as $r) {
                    if (!in_array($r['source_hash'], $present, true)) {
                        $this->db->run('DELETE FROM inbox_items WHERE id = ?', [$r['id']]);
                    }
                }
                $this->db->run("DELETE FROM inbox_items WHERE status = 'filed' AND filed_at < CURRENT_TIMESTAMP(3) - INTERVAL ? DAY", [self::KEEP_FILED_DAYS]);
            }
            $job?->finish();
            return ['stats' => $job?->stats() ?? [], 'filed' => $filed, 'plans' => $plans];
        } catch (\Throwable $e) {
            $job?->fail($e);
            throw $e;
        } finally {
            $dryRun || $this->db->value("SELECT RELEASE_LOCK('musique:inbox')");
        }
    }

    /**
     * Fichiers du dépôt regroupés par dossier d'album (sous-dossiers CD2/, flac/ rattachés au parent).
     * Un dossier sans audio qui contient d'autres albums (« Artiste/ » avec une photo) est ignoré.
     * @return array<string, list<StorageObject>>
     */
    private function groups(): array
    {
        $groups = [];
        foreach ($this->storage->list(self::PREFIX) as $obj) {
            $groups[Layout::albumDir($obj->key)][] = $obj;
        }
        ksort($groups, SORT_STRING);
        foreach ($groups as $dir => $objects) {
            $hasAudio = array_filter($objects, static fn ($o) => Layout::kind($o->key) === 'audio') !== [];
            if ($hasAudio || self::isBusy($objects)) {
                continue;
            }
            // Sous-dossier sans musique (Scans/, Artwork/) : il suit l'album qui le contient
            $parent = (string) $dir;
            while (($parent = dirname($parent)) !== '.' && $parent !== '' && str_contains($parent, '/')) {
                if (isset($groups[$parent]) && array_filter($groups[$parent], static fn ($o) => Layout::kind($o->key) === 'audio') !== []) {
                    array_push($groups[$parent], ...$objects);
                    unset($groups[$dir]);
                    continue 2;
                }
            }
            // Dossier d'artiste avec une photo, au-dessus de ses albums : ignoré
            foreach (array_keys($groups) as $other) {
                if (str_starts_with((string) $other, $dir . '/')) {
                    unset($groups[$dir]);
                    break;
                }
            }
        }
        return $groups;
    }

    /**
     * @param list<StorageObject> $objects
     * @return array<string, mixed> status, changed (nouvel état à signaler), message, et pour un plan :
     *   targetDir, albumTitle, albumArtist, moves, moved
     */
    private function handle(string $dir, array $objects, bool $dryRun): array
    {
        $signature = self::signature($objects);
        $row = $this->db->one('SELECT status, signature FROM inbox_items WHERE source_hash = ?', [hash('sha256', $dir, true)]);
        $ageMinutes = self::ageMinutes($objects);

        if (self::isBusy($objects)) {
            $res = ['status' => 'waiting', 'message' => 'Copie en cours'];
        } elseif ($ageMinutes < $this->waitMinutes && !$dryRun) {
            $res = ['status' => 'waiting', 'message' => sprintf('Rangé quand rien n’aura bougé pendant %d min', $this->waitMinutes)];
        } elseif (!$dryRun && $row !== null && $row['signature'] === $signature && $row['status'] === 'blocked') {
            // Déjà signalé, rien n'a changé depuis : on n'y touche pas
            return ['status' => 'blocked', 'changed' => false,
                'message' => (string) $this->db->value('SELECT message FROM inbox_items WHERE source_hash = ?', [hash('sha256', $dir, true)])];
        } else {
            $res = $this->plan($dir, $objects);
            if ($res['status'] === 'incomplete' && $ageMinutes >= $this->incompleteHours * 60) {
                $res['status'] = 'ready';
                $res['message'] = $res['message'] . ' : rangé quand même après ' . $this->incompleteHours . ' h';
            }
            if ($res['status'] === 'ready') {
                $conflict = $this->conflict($res['moves']);
                if ($conflict !== null) {
                    $res = ['status' => 'blocked', 'message' => $conflict] + $res;
                }
            }
        }
        $res['changed'] = $row === null || $row['status'] !== $res['status'] || $row['signature'] !== $signature;

        if ($res['status'] === 'ready') {
            if ($dryRun) {
                return ['status' => 'ready'] + $res;
            }
            $res['moved'] = 0;
            foreach ($res['moves'] as $from => $to) {
                $this->storage->move($from, $to);
                $res['moved']++;
            }
            $res['status'] = 'filed';
            $res['changed'] = true;
            $this->logger->info('Album rangé depuis le dépôt', ['from' => $dir, 'to' => $res['targetDir'], 'files' => count($res['moves'])]);
        }
        if (!$dryRun) {
            $this->save($dir, $objects, $res, $signature);
        }
        return $res;
    }

    /**
     * Décide du rangement d'un dossier à partir des tags de ses pistes.
     * @param list<StorageObject> $objects
     * @return array<string, mixed>
     */
    private function plan(string $dir, array $objects): array
    {
        $audio = array_values(array_filter($objects, static fn ($o) => Layout::kind($o->key) === 'audio'));
        if ($audio === []) {
            return ['status' => 'blocked', 'message' => 'Aucun fichier audio dans ce dossier'];
        }
        $sources = [];
        foreach ($audio as $i => $o) {
            $sources[$i] = $this->storage->mediaSource($o->key, 600);
        }
        $tags = [];
        foreach ($this->probe->probeMany($sources) as $i => $r) {
            if (!$r['ok']) {
                return ['status' => 'blocked', 'message' => 'Fichier illisible : ' . basename($audio[$i]->key) . ' (' . ($r['error'] ?? '?') . ')'];
            }
            $tags[$i] = $r['info']['tags'];
        }
        ksort($tags);

        // ------------------------------------------------------------- album (vote majoritaire)
        $base = $dir === rtrim(self::PREFIX, '/') ? '' : basename($dir);
        [$dirArtist, $dirTitle] = $base === '' ? [null, null] : Layout::fromDirName($base);
        $albumTags = array_map(static fn ($t) => $t['album'] ?? null, $tags);
        [$title, $titleShare] = Text::majority($albumTags);
        // Sélection de titres pris sur plusieurs albums, déposée sous « VA - Nom » : rangée telle
        // quelle en compilation, sous le nom du dossier (l'indexeur la verra « en vrac »).
        $mixedVarious = $title !== null && $titleShare < 0.5 && $dirTitle !== null
            && $dirArtist !== null && preg_match(AlbumBuilder::VARIOUS, $dirArtist) === 1;
        if ($mixedVarious) {
            $title = $dirTitle;
        } elseif ($title !== null && $titleShare < 0.5) {
            $names = array_slice(array_unique(array_filter($albumTags)), 0, 3);
            return ['status' => 'blocked', 'message' => 'Les pistes portent plusieurs albums (' . implode(', ', $names) . '…) : déposer un dossier par album'];
        }
        $title ??= $dirTitle;
        if ($title === null) {
            return ['status' => 'blocked', 'message' => 'Titre de l’album introuvable (pas de tag « album ») : le mettre dans un dossier « Artiste - Album »'];
        }

        [$aa, $aaShare] = Text::majority(array_map(static fn ($t) => $t['album_artist'] ?? null, $tags));
        [$main, $mainShare] = Text::majority(array_map(static fn ($t) => AlbumBuilder::splitArtist($t['artist'] ?? null)[0], $tags));
        [$compTag] = Text::majority(array_map(static fn ($t) => $t['compilation'] ?? null, $tags));
        $various = $mixedVarious || ($aa !== null && preg_match(AlbumBuilder::VARIOUS, $aa) === 1);
        $artist = match (true) {
            $various => null,
            $aa !== null && $aaShare >= 0.5 => $aa,
            $main !== null && $mainShare >= 0.5 => $main,
            // Le nom du dossier ne sert que sans aucun tag d'artiste : des tags qui divergent
            // (un artiste par piste) font une compilation, comme dans AlbumBuilder.
            $aa === null && $main === null => $dirArtist,
            default => null,
        };
        $compilation = $various || $compTag === '1' || ($artist === null && $main !== null);
        if (!$compilation && $artist === null) {
            return ['status' => 'blocked', 'message' => 'Artiste introuvable (pas de tag « artist ») : le mettre dans un dossier « Artiste - Album »'];
        }
        [$yearStr] = $mixedVarious ? [null] : Text::majority(array_map(static fn ($t) => ($y = Text::year($t['date'] ?? null)) === null ? null : (string) $y, $tags));

        $folderTitle = self::segment($title);
        if ($yearStr !== null && !str_contains($folderTitle, "($yearStr)")) {
            $folderTitle .= " ($yearStr)";
        }
        $targetDir = ($compilation ? self::COMPILATIONS : self::segment((string) $artist)) . '/' . $folderTitle;

        // ------------------------------------------------------------- pistes
        $tracks = [];
        $discTotal = 1;
        $perDiscTotal = [];
        foreach ($audio as $i => $o) {
            $t = $tags[$i];
            $trackArtist = $t['artist'] ?? null;
            [$fileNum, $fileTitle] = Layout::fromFilename($o->key, $trackArtist ?? $artist);
            if ($mixedVarious) {
                // Sélection : l'ordre est celui des fichiers, pas les numéros des albums d'origine
                $tracks[] = ['key' => $o->key, 'disc' => 1, 'num' => $fileNum, 'title' => $t['title'] ?? $fileTitle, 'artist' => $trackArtist];
                continue;
            }
            $disc = Text::firstInt($t['disc'] ?? null) ?? Layout::discFromPath($o->key) ?? 1;
            $num = Text::firstInt($t['track'] ?? null) ?? $fileNum;
            $discTotal = max($discTotal, self::total($t['disc'] ?? null, $t['disctotal'] ?? $t['totaldiscs'] ?? null) ?? 1);
            $total = self::total($t['track'] ?? null, $t['tracktotal'] ?? $t['totaltracks'] ?? null);
            if ($total !== null) {
                $perDiscTotal[$disc] = max($perDiscTotal[$disc] ?? 0, $total);
            }
            $tracks[] = ['key' => $o->key, 'disc' => max(1, $disc), 'num' => $num, 'title' => $t['title'] ?? $fileTitle, 'artist' => $trackArtist];
        }
        $discs = array_unique(array_column($tracks, 'disc'));
        $multiDisc = count($discs) > 1 || $discTotal > 1;
        $width = max(2, strlen((string) max(array_map(static fn ($t) => (int) $t['num'], $tracks))));

        $moves = [];
        foreach ($tracks as $t) {
            $name = ($t['num'] !== null ? str_pad((string) $t['num'], $width, '0', STR_PAD_LEFT) . ' - ' : '')
                . ($compilation && $t['artist'] !== null ? $t['artist'] . ' - ' : '')
                . $t['title'];
            $ext = strtolower(pathinfo($t['key'], PATHINFO_EXTENSION));
            $moves[$t['key']] = $targetDir . ($multiDisc ? '/CD' . $t['disc'] : '') . '/' . self::segment($name, $ext);
        }
        $seen = array_count_values($moves);
        foreach ($seen as $to => $n) {
            if ($n > 1) {
                return ['status' => 'blocked', 'message' => 'Deux fichiers donneraient le même nom : ' . basename((string) $to) . ' (doublon dans le dossier déposé ?)'];
            }
        }
        // Pochettes, livrets, .cue… : même place relative, sans les sous-dossiers de format
        foreach ($objects as $o) {
            if (Layout::kind($o->key) === 'audio') {
                continue;
            }
            $rel = substr($o->key, strlen($dir) + 1);
            $parts = array_values(array_filter(explode('/', $rel), static fn ($p) => preg_match('/^(?:flac|mp3|ogg|opus|m4a|aac|wav|alac|hi-?res|24bit)$/i', $p) !== 1));
            $to = $targetDir . '/' . implode('/', array_map(static fn ($p) => self::segment($p), $parts));
            if (in_array($to, $moves, true)) {
                $to = $targetDir . '/' . implode('/', array_map(static fn ($p) => self::segment($p), explode('/', $rel)));
            }
            $moves[$o->key] = $to;
        }

        $res = ['status' => 'ready', 'targetDir' => $targetDir, 'albumTitle' => $title, 'albumArtist' => $compilation ? null : $artist, 'moves' => $moves];
        // Album incomplet d'après ses tags : on laisse le temps au reste d'arriver
        $expected = array_sum($perDiscTotal);
        $present = count(array_unique(array_map(static fn ($t) => $t['disc'] . '|' . $t['num'], array_filter($tracks, static fn ($t) => $t['num'] !== null))));
        if ($discTotal > count($discs)) {
            $res = ['status' => 'incomplete', 'message' => sprintf('Disques présents : %d sur %d', count($discs), $discTotal)] + $res;
        } elseif ($perDiscTotal !== [] && count($perDiscTotal) === count($discs) && $present < $expected) {
            $res = ['status' => 'incomplete', 'message' => sprintf('Pistes présentes : %d sur %d', $present, $expected)] + $res;
        }
        return $res;
    }

    /**
     * Un fichier existe déjà à l'arrivée ? (Compléter un album déjà rangé reste possible.)
     * @param array<string, string> $moves
     */
    private function conflict(array $moves): ?string
    {
        foreach ($moves as $to) {
            if ($this->storage->stat($to) !== null) {
                return "Un fichier porte déjà ce nom dans la bibliothèque : $to";
            }
        }
        return null;
    }

    /**
     * @param list<StorageObject> $objects
     * @param array<string, mixed> $res
     */
    private function save(string $dir, array $objects, array $res, ?string $signature = null): void
    {
        $this->db->run(
            'INSERT INTO inbox_items (source_dir, source_hash, status, file_count, size_bytes, signature, message, album_title, album_artist, target_dir, filed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, IF(? = 1, CURRENT_TIMESTAMP(3), NULL))
             ON DUPLICATE KEY UPDATE status = VALUES(status), file_count = VALUES(file_count), size_bytes = VALUES(size_bytes),
                signature = VALUES(signature), message = VALUES(message), album_title = VALUES(album_title),
                album_artist = VALUES(album_artist), target_dir = VALUES(target_dir), filed_at = VALUES(filed_at)',
            [
                $dir, hash('sha256', $dir, true), $res['status'], count($objects), array_sum(array_map(static fn ($o) => $o->size, $objects)),
                $signature ?? self::signature($objects), isset($res['message']) ? mb_substr((string) $res['message'], 0, 1000) : null,
                isset($res['albumTitle']) ? mb_substr((string) $res['albumTitle'], 0, 255) : null,
                isset($res['albumArtist']) ? mb_substr((string) $res['albumArtist'], 0, 255) : null,
                $res['targetDir'] ?? null,
                (int) ($res['status'] === 'filed'),
            ]
        );
    }

    /** @param list<StorageObject> $objects */
    private static function signature(array $objects): string
    {
        $lines = array_map(static fn ($o) => $o->key . '|' . $o->size, $objects);
        sort($lines, SORT_STRING);
        return hash('sha256', implode("\n", $lines), true);
    }

    /** @param list<StorageObject> $objects */
    private static function isBusy(array $objects): bool
    {
        foreach ($objects as $o) {
            if (preg_match(self::BUSY, $o->key) === 1) {
                return true;
            }
        }
        return false;
    }

    /** Minutes écoulées depuis la dernière modification d'un fichier du dossier. @param list<StorageObject> $objects */
    private static function ageMinutes(array $objects): float
    {
        $latest = 0;
        foreach ($objects as $o) {
            $latest = max($latest, $o->modifiedAt?->getTimestamp() ?? time());
        }
        return (time() - $latest) / 60;
    }

    /** Total d'un tag « 3/12 », ou d'un tag de total séparé. */
    private static function total(?string $numberTag, ?string $totalTag): ?int
    {
        if ($numberTag !== null && preg_match('#/\s*(\d+)#', $numberTag, $m)) {
            return (int) $m[1] ?: null;
        }
        return $totalTag === null ? null : (Text::firstInt($totalTag) ?: null);
    }

    /**
     * Nom de dossier ou de fichier sûr partout (disque, S3, Nextcloud, Windows) : sans / \ : * ? " < > |,
     * sans point au début, 150 octets au plus (l'extension est gardée).
     */
    public static function segment(string $name, ?string $ext = null): string
    {
        $s = Text::clean($name) ?? '';
        $s = preg_replace('#[/\\\\:*?"<>|]+#u', '-', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        $s = trim(ltrim(trim($s), '.'), " .");
        if ($s === '') {
            $s = 'Sans titre';
        }
        $max = 150 - ($ext === null ? 0 : strlen($ext) + 1);
        if (strlen($s) > $max) {
            $s = rtrim(mb_strcut($s, 0, $max, 'UTF-8'), ' .');
        }
        return $ext === null || $ext === '' ? $s : "$s.$ext";
    }
}
