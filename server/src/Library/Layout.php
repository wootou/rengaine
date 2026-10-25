<?php
// Règles de rangement : quel dossier forme un album, quel disque, quel titre à défaut de tags.
// Voir docs/CONCEPTION.md § 5 « Regroupement en albums ».

declare(strict_types=1);

namespace Musique\Library;

final class Layout
{
    public const AUDIO_EXT = ['mp3', 'flac', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'wav', 'aif', 'aiff', 'wma', 'ape', 'wv'];
    public const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    /** Sous-dossier de disque : CD1, CD 2, Disc 3, Disque 1, Disk2. */
    private const DISC_DIR = '/^(?:cd|dis[ckq]u?e?)\s*[-_]?\s*(\d{1,2})$/i';
    /** Sous-dossier de format : flac/, mp3/… */
    private const FORMAT_DIR = '/^(?:flac|mp3|ogg|opus|m4a|aac|wav|alac|hi-?res|24bit)$/i';

    public static function kind(string $key): string
    {
        $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
        return match (true) {
            in_array($ext, self::AUDIO_EXT, true) => 'audio',
            in_array($ext, self::IMAGE_EXT, true) => 'image',
            default => 'other',
        };
    }

    /**
     * Dossier de l'album d'un fichier : son dossier, sans les sous-dossiers de disque ou de format.
     * « A/B/CD2/flac/01.flac » → « A/B ». Un fichier à la racine → « » (dossier racine).
     */
    public static function albumDir(string $key): string
    {
        $parts = explode('/', $key);
        array_pop($parts);
        while ($parts !== [] && (preg_match(self::DISC_DIR, end($parts)) || preg_match(self::FORMAT_DIR, end($parts)))) {
            array_pop($parts);
        }
        return implode('/', $parts);
    }

    /** Numéro de disque donné par un sous-dossier CD2/, sinon null. */
    public static function discFromPath(string $key): ?int
    {
        foreach (array_reverse(array_slice(explode('/', $key), 0, -1)) as $segment) {
            if (preg_match(self::DISC_DIR, $segment, $m)) {
                return (int) $m[1];
            }
        }
        return null;
    }

    /**
     * Titre et numéro tirés du nom de fichier, quand les tags manquent.
     * « 05 - Twilight Omens.mp3 » → [5, « Twilight Omens »] ;
     * « 31 - Francis Cabrel - Quand j'aime.mp3 » avec l'artiste « Francis Cabrel » → [31, « Quand j'aime »].
     * @return array{0: ?int, 1: string}
     */
    public static function fromFilename(string $key, ?string $artist = null): array
    {
        $name = pathinfo($key, PATHINFO_FILENAME);
        $number = null;
        if (preg_match('/^\s*(\d{1,3})\s*(?:[-._)]\s*|\s+)(.+)$/u', $name, $m)) {
            $number = (int) $m[1];
            $name = $m[2];
        }
        if ($artist !== null && $artist !== '') {
            $prefix = Text::key($artist);
            $parts = explode(' - ', $name, 2);
            if (count($parts) === 2 && Text::key($parts[0]) === $prefix) {
                $name = $parts[1];
            }
        }
        $name = str_replace('_', ' ', $name);
        return [$number, Text::clean($name) ?? pathinfo($key, PATHINFO_FILENAME)];
    }

    /**
     * Artiste et titre d'album tirés du nom du dossier « Artiste - Album », à défaut de tags.
     * @return array{0: ?string, 1: string}
     */
    public static function fromDirName(string $albumDir): array
    {
        $base = $albumDir === '' ? 'Racine' : basename($albumDir);
        $parts = explode(' - ', $base, 2);
        if (count($parts) === 2 && trim($parts[0]) !== '' && trim($parts[1]) !== '') {
            return [Text::clean($parts[0]), Text::clean($parts[1]) ?? $base];
        }
        return [null, Text::clean($base) ?? $base];
    }

    /** Nom d'image sans ses extensions (« Cover.jpg.jpg » → « cover »), en minuscules. */
    public static function imageStem(string $key): string
    {
        $name = strtolower(basename($key));
        return preg_replace('/(\.(jpe?g|png|webp|gif))+$/i', '', $name) ?? $name;
    }
}
