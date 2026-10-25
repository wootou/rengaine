<?php

declare(strict_types=1);

namespace Musique\Library;

/** Petits outils de texte communs à l'indexeur. */
final class Text
{
    /**
     * Rend une chaîne valide en UTF-8. Les vieux tags et certains meta.json sont en Windows-1252 :
     * on les convertit au lieu de les rejeter (leçon de l'ancien site, où json_decode échouait).
     */
    public static function utf8(string $s): string
    {
        if (mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        // Seuls les octets invalides sont convertis : un texte en partie correct le reste.
        $valid = '/([\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}'
            . '|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})|(.)/s';
        return (string) preg_replace_callback($valid, static fn (array $m): string => ($m[2] ?? '') === ''
            ? $m[1]
            : mb_convert_encoding($m[2], 'UTF-8', 'Windows-1252'), $s);
    }

    /** Nettoie une valeur affichée : UTF-8 valide, espaces simples, sans caractères de contrôle. */
    public static function clean(?string $s): ?string
    {
        if ($s === null) {
            return null;
        }
        $s = self::utf8($s);
        $s = preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $s) ?? $s;
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
        return $s === '' ? null : $s;
    }

    /**
     * Clé de comparaison : sans casse, sans accents, ponctuation réduite à des espaces.
     * « L'Été (Remastered) » et « l ete remastered » donnent la même clé.
     */
    public static function key(?string $s): string
    {
        if ($s === null) {
            return '';
        }
        $s = self::utf8($s);
        if (class_exists(\Normalizer::class)) {
            $s = \Normalizer::normalize($s, \Normalizer::FORM_KD) ?: $s;
            $s = preg_replace('/\p{Mn}+/u', '', $s) ?? $s;
        }
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? $s;
        return trim($s);
    }

    /** Premier nombre d'une valeur « 3/12 », « 03 », « CD2 ». */
    public static function firstInt(?string $s): ?int
    {
        if ($s === null || !preg_match('/\d+/', $s, $m)) {
            return null;
        }
        $n = (int) $m[0];
        return $n > 0 && $n < 10000 ? $n : null;
    }

    /** Année d'une date (« 2009-01-26 », « 13-11-2020 », « 2017 »). */
    public static function year(?string $s): ?int
    {
        if ($s === null || !preg_match('/(?<!\d)(1[89]\d\d|20\d\d)(?!\d)/', $s, $m)) {
            return null;
        }
        return (int) $m[1];
    }

    /**
     * Valeur majoritaire d'une liste (comparaison par clé), avec sa part.
     * @param list<string|null> $values
     * @return array{0: ?string, 1: float} [valeur la plus fréquente sous sa forme la plus courante, part entre 0 et 1]
     */
    public static function majority(array $values): array
    {
        $total = count($values);
        if ($total === 0) {
            return [null, 0.0];
        }
        $groups = [];
        foreach ($values as $v) {
            $k = self::key($v);
            if ($k === '') {
                continue;
            }
            $groups[$k][] = $v;
        }
        if ($groups === []) {
            return [null, 0.0];
        }
        uasort($groups, static fn ($a, $b) => count($b) <=> count($a));
        $best = reset($groups);
        // Forme la plus fréquente parmi les variantes de casse
        $forms = array_count_values($best);
        arsort($forms);
        return [(string) array_key_first($forms), count($best) / $total];
    }
}
