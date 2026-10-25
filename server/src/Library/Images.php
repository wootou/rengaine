<?php
// Jaquettes (CAT-04). Une image identique n'est enregistrée qu'une fois (empreinte SHA-256).
//   - Image fichier : la ligne pointe vers l'objet du stockage ; les octets restent dans le stockage.
//   - Pochette intégrée : extraite par ffmpeg et gardée dans le cache (<cache>/images/ab/abcd….jpg) ;
//     si la copie disparaît du cache, elle est extraite à nouveau du fichier audio (restore).
//   - Image envoyée depuis l'admin (photo d'artiste) : octets gardés en base (image_blobs).
// Image propre à une piste : seulement si sa pochette intégrée diffère de celle de la majorité de
// l'album (trackImages, appelé par l'analyseur qui relève l'empreinte de chaque pochette).

declare(strict_types=1);

namespace Musique\Library;

use Musique\Db\Database;
use Musique\Storage\Storage;

final class Images
{
    /** Noms conventionnels, du plus au moins probable. */
    private const PREFERRED = ['cover', 'folder', 'front', 'album', 'albumart', 'albumartlarge', 'thumb', 'albumartsmall'];

    public function __construct(
        private readonly Database $db,
        private readonly Storage $storage,
        private readonly string $cacheDir,
    ) {
    }

    /**
     * Choisit la jaquette d'un album parmi ses images (règles de l'ancien site) :
     * noms conventionnels, puis nom évocateur (« Cover.jpg.jpg », « front-hd »), puis image unique.
     * @param list<array{id: int, storage_key: string}> $images
     * @return array{id: int, storage_key: string}|null
     */
    public static function pickCoverFile(array $images): ?array
    {
        if ($images === []) {
            return null;
        }
        // Les images à la racine de l'album passent avant celles des sous-dossiers (CD1/)
        usort($images, static fn ($a, $b) => [substr_count($a['storage_key'], '/'), $a['storage_key']] <=> [substr_count($b['storage_key'], '/'), $b['storage_key']]);
        foreach (self::PREFERRED as $name) {
            foreach ($images as $img) {
                if (Layout::imageStem($img['storage_key']) === $name) {
                    return $img;
                }
            }
        }
        foreach (['cover', 'front', 'folder', 'pochette', 'jaquette'] as $word) {
            foreach ($images as $img) {
                if (str_contains(Layout::imageStem($img['storage_key']), $word)) {
                    return $img;
                }
            }
        }
        return count($images) === 1 ? $images[0] : null;
    }

    /** Image d'un fichier du stockage → identifiant dans `images` (lue seulement si inconnue). */
    public function fromStorageObject(int $objectId, string $key): ?int
    {
        $known = $this->db->value("SELECT id FROM images WHERE object_id = ? AND source = 'file' LIMIT 1", [$objectId]);
        if ($known !== null) {
            return (int) $known;
        }
        $bytes = $this->storage->get($key);
        return $this->store($bytes, 'file', $objectId, false);
    }

    /** Pochette intégrée à un fichier audio → identifiant dans `images`, ou null si l'extraction échoue. */
    public function fromEmbedded(int $audioObjectId, string $key): ?int
    {
        $known = $this->db->value("SELECT id FROM images WHERE object_id = ? AND source = 'embedded' LIMIT 1", [$audioObjectId]);
        if ($known !== null) {
            return (int) $known;
        }
        $bytes = self::extractEmbedded($this->storage->mediaSource($key, 300));
        return $bytes === null ? null : $this->store($bytes, 'embedded', $audioObjectId, true);
    }

    /** Octets de la pochette intégrée d'un fichier audio (chemin ou adresse), ou null. */
    public static function extractEmbedded(string $source): ?string
    {
        $cmd = ['ffmpeg', '-v', 'error', '-nostdin', '-i', $source, '-an', '-map', '0:v:0', '-frames:v', '1', '-c', 'copy', '-f', 'image2pipe', '-'];
        $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return null;
        }
        $bytes = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        return $exit !== 0 || strlen($bytes) < 100 || @getimagesizefromstring($bytes) === false ? null : $bytes;
    }

    /** Image envoyée depuis l'admin : gardée en base. Renvoie son identifiant (le même si déjà connue). */
    public function fromUpload(string $bytes): ?int
    {
        $id = $this->store($bytes, 'upload', null, false);
        if ($id !== null) {
            $this->db->run('INSERT IGNORE INTO image_blobs (image_id, data) VALUES (?, ?)', [$id, $bytes]);
        }
        return $id;
    }

    /**
     * Octets d'origine d'une image : copie en cache, base (envoi), stockage (fichier), ou nouvelle
     * extraction de la pochette intégrée quand la copie du cache a disparu (gardée de nouveau).
     * @param array{source: string, storage_key: ?string, missing_since: ?string} $img
     */
    public function original(array $img, string $hex): ?string
    {
        $copies = glob("{$this->cacheDir}/images/" . substr($hex, 0, 2) . "/$hex.*") ?: [];
        if ($copies !== []) {
            return (string) file_get_contents($copies[0]);
        }
        if ($img['source'] === 'upload') {
            $blob = $this->db->value('SELECT b.data FROM image_blobs b JOIN images i ON i.id = b.image_id WHERE i.sha256 = UNHEX(?)', [$hex]);
            return $blob === null ? null : (string) $blob;
        }
        if ($img['storage_key'] === null || $img['missing_since'] !== null) {
            return null;
        }
        if ($img['source'] === 'embedded') {
            $bytes = self::extractEmbedded($this->storage->mediaSource((string) $img['storage_key'], 300));
            if ($bytes === null || hash('sha256', $bytes) !== $hex) {
                return null; // fichier audio changé depuis : sa pochette n'est plus celle-ci
            }
            $this->keepCopy($bytes, $hex);
            return $bytes;
        }
        return $this->storage->get((string) $img['storage_key']);
    }

    /**
     * Images propres aux pistes d'un album, d'après l'empreinte de leur pochette intégrée : une piste
     * a sa propre image si sa pochette n'est pas celle de la majorité. Si toutes diffèrent (compilation),
     * chacune a la sienne. Rien tant qu'une pochette de l'album n'est pas encore relevée.
     * @return int nombre de pistes avec leur propre image
     */
    public function trackImages(int $albumId): int
    {
        $rows = $this->db->all(
            'SELECT t.id AS track_id, tf.object_id, so.storage_key, tf.has_picture, LOWER(HEX(tf.picture_sha256)) AS pic
               FROM tracks t JOIN track_files tf ON tf.track_id = t.id JOIN storage_objects so ON so.id = tf.object_id
              WHERE t.album_id = ? AND t.missing_since IS NULL AND so.missing_since IS NULL
              ORDER BY tf.lossless DESC, tf.bitrate_kbps DESC, tf.id',
            [$albumId]
        );
        /** @var array<int, array<string, array{object_id: int, key: string}>> $byTrack piste → empreinte → fichier */
        $byTrack = [];
        foreach ($rows as $r) {
            if ((bool) $r['has_picture'] && $r['pic'] === null) {
                return 0; // pas encore relevée
            }
            $byTrack[(int) $r['track_id']] ??= [];
            if ($r['pic'] !== null && $r['pic'] !== str_repeat('0', 64)) {
                $byTrack[(int) $r['track_id']][$r['pic']] ??= ['object_id' => (int) $r['object_id'], 'key' => (string) $r['storage_key']];
            }
        }
        $count = [];
        foreach ($byTrack as $pics) {
            foreach (array_keys($pics) as $sha) {
                $count[$sha] = ($count[$sha] ?? 0) + 1;
            }
        }
        arsort($count);
        $major = count($count) > 1 && reset($count) > 1 ? (string) array_key_first($count) : null;
        $own = 0;
        foreach ($byTrack as $trackId => $pics) {
            $image = null;
            if (count($count) > 1 && $pics !== [] && ($major === null || !isset($pics[$major]))) {
                $f = reset($pics);
                $image = $this->fromEmbedded($f['object_id'], $f['key']);
            }
            $this->db->run('UPDATE tracks SET image_id = ? WHERE id = ?', [$image, $trackId]);
            $own += $image !== null ? 1 : 0;
        }
        return $own;
    }

    private function keepCopy(string $bytes, string $hex): void
    {
        $info = @getimagesizefromstring($bytes);
        $ext = $info === false ? 'img' : (image_type_to_extension($info[2], false) ?: 'img');
        $dir = $this->cacheDir . '/images/' . substr($hex, 0, 2);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cache des images inaccessible : $dir");
        }
        if (@file_put_contents("$dir/$hex.$ext", $bytes) === false) {
            throw new \RuntimeException("Écriture impossible dans le cache des images : $dir");
        }
    }

    private function store(string $bytes, string $source, ?int $objectId, bool $keepCopy): ?int
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return null;
        }
        $sha = hash('sha256', $bytes, true);
        $existing = $this->db->value('SELECT id FROM images WHERE sha256 = ?', [$sha]);
        if ($existing !== null) {
            return (int) $existing;
        }
        if ($keepCopy) {
            $this->keepCopy($bytes, bin2hex($sha));
        }
        $this->db->run(
            'INSERT INTO images (sha256, source, object_id, mime_type, width, height, size_bytes) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$sha, $source, $objectId, $info['mime'], min(65535, $info[0]) ?: null, min(65535, $info[1]) ?: null, strlen($bytes)]
        );
        return (int) $this->db->pdo()->lastInsertId();
    }
}
