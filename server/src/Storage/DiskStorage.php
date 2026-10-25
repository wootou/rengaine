<?php
// Pilote disque local, NFS compris (STO-03).

declare(strict_types=1);

namespace Musique\Storage;

final class DiskStorage implements Storage
{
    private const CHUNK = 262144; // 256 Kio

    private readonly string $root;

    public function __construct(string $root)
    {
        $real = realpath($root);
        if ($real === false || !is_dir($real)) {
            throw new StorageException(StorageException::UNAVAILABLE, "Dossier de stockage introuvable : $root");
        }
        $this->root = $real;
    }

    public function mode(): string
    {
        return 'disque';
    }

    public function list(string $prefix = ''): \Generator
    {
        // On part du dossier le plus profond entièrement contenu dans le préfixe.
        $slash = strrpos($prefix, '/');
        $baseRel = $slash === false ? '' : substr($prefix, 0, $slash);
        $base = $baseRel === '' ? $this->root : $this->root . '/' . $baseRel;
        if (!is_dir($base)) {
            return;
        }

        $found = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO),
                static fn (\SplFileInfo $f) => !str_starts_with($f->getFilename(), '.'),
            ),
        );
        $rootLen = strlen($this->root) + 1;
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $key = substr($file->getPathname(), $rootLen);
            if ($prefix !== '' && !str_starts_with($key, $prefix)) {
                continue;
            }
            $found[$key] = $file;
        }
        // Même ordre que S3 : octets UTF-8
        ksort($found, SORT_STRING);
        foreach ($found as $key => $file) {
            yield new StorageObject((string) $key, $file->getSize(), self::date($file->getMTime()));
        }
    }

    public function stat(string $key): ?StorageObject
    {
        $path = $this->path($key);
        clearstatcache(true, $path);
        if (!is_file($path)) {
            return null;
        }
        return new StorageObject($key, (int) filesize($path), self::date((int) filemtime($path)));
    }

    public function get(string $key): string
    {
        $path = $this->existing($key);
        $data = @file_get_contents($path);
        if ($data === false) {
            throw new StorageException(StorageException::FAILED, "Lecture impossible : $key");
        }
        return $data;
    }

    public function stream(string $key, int $offset, ?int $length, callable $sink): void
    {
        $path = $this->existing($key);
        $size = (int) filesize($path);
        [$offset, $remaining] = Range::resolve($offset, $length, $size);
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new StorageException(StorageException::FAILED, "Ouverture impossible : $key");
        }
        try {
            if ($offset > 0 && fseek($fh, $offset) !== 0) {
                throw new StorageException(StorageException::FAILED, "Positionnement impossible : $key");
            }
            while ($remaining > 0 && !feof($fh)) {
                $chunk = fread($fh, min(self::CHUNK, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $remaining -= strlen($chunk);
                $sink($chunk);
            }
        } finally {
            fclose($fh);
        }
    }

    public function put(string $key, string $localFile, ?string $contentType = null): StorageObject
    {
        $path = $this->path($key);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new StorageException(StorageException::FAILED, "Création du dossier impossible pour : $key");
        }
        // Écriture dans un fichier caché puis renommage : jamais de fichier à moitié écrit visible.
        $tmp = $dir . '/.tmp-' . bin2hex(random_bytes(6));
        if (!@copy($localFile, $tmp)) {
            throw new StorageException(StorageException::FAILED, "Écriture impossible : $key");
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new StorageException(StorageException::FAILED, "Écriture impossible : $key");
        }
        return $this->stat($key) ?? throw new StorageException(StorageException::FAILED, "Fichier absent après écriture : $key");
    }

    public function move(string $from, string $to): StorageObject
    {
        $src = $this->existing($from);
        $dst = $this->path($to);
        if (file_exists($dst)) {
            throw new StorageException(StorageException::FAILED, "Déplacement refusé, le fichier existe déjà : $to");
        }
        $dir = dirname($dst);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new StorageException(StorageException::FAILED, "Création du dossier impossible pour : $to");
        }
        if (!@rename($src, $dst)) {
            throw new StorageException(StorageException::FAILED, "Déplacement impossible : $from");
        }
        // Dossiers d'origine devenus vides retirés, sauf le premier niveau (le dossier de dépôt reste)
        $top = $this->root . '/' . explode('/', $from)[0];
        $d = dirname($this->root . '/' . $from);
        while ($d !== $top && str_starts_with($d, $top . '/') && @rmdir($d)) {
            $d = dirname($d);
        }
        return $this->stat($to) ?? throw new StorageException(StorageException::FAILED, "Fichier absent après déplacement : $to");
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path) && !@unlink($path)) {
            throw new StorageException(StorageException::FAILED, "Suppression impossible : $key");
        }
        // Retire les dossiers devenus vides (comme S3, qui n'a pas de dossiers), sans remonter au-delà de la racine.
        $dir = dirname($path);
        while ($dir !== $this->root && str_starts_with($dir, $this->root . '/') && @rmdir($dir)) {
            $dir = dirname($dir);
        }
    }

    public function mediaSource(string $key, int $ttlSeconds = 3600): string
    {
        return $this->existing($key);
    }

    private function path(string $key): string
    {
        Keys::assertValid($key);
        return $this->root . '/' . $key;
    }

    /** Chemin d'un fichier existant, sans échappée hors de la racine par un lien symbolique. */
    private function existing(string $key): string
    {
        $path = $this->path($key);
        $real = realpath($path);
        if ($real === false || !is_file($real)) {
            throw new StorageException(StorageException::NOT_FOUND, "Fichier introuvable : $key");
        }
        if (!str_starts_with($real, $this->root . '/')) {
            throw new StorageException(StorageException::INVALID_KEY, "Clé refusée (hors du stockage) : $key");
        }
        return $real;
    }

    private static function date(int $timestamp): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('UTC'));
    }
}
