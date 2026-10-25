<?php
// Langue du serveur (WEB-13) : une seule, choisie par l'admin (réglage `language`, `fr` par défaut).
// Le code écrit en français ; lang/en.php donne l'anglais, clé = texte français avec ses variables
// nommées entre accolades (« Champ « {champ} » obligatoire »).
//
// Deux usages :
//   - Lang::tr('Bonjour {nom}', ['nom' => …]) : texte écrit pour l'occasion (mails, ambiances) ;
//   - Lang::message($texte) : texte déjà composé (messages d'erreur, motifs gardés en base). Il est
//     reconnu d'après les modèles du catalogue et ses parties variables recopiées telles quelles ;
//     une variable nommée {msg} contient elle-même un message, traduit à son tour.
// Les journaux et la commande d'admin restent en français.

declare(strict_types=1);

namespace Musique;

use Musique\Account\ServerSettings;
use Musique\Db\Database;

final class Lang
{
    private static string $lang = 'fr';
    private static bool $loaded = false;
    private static ?Database $db = null;
    /** @var array<string, string>|null */
    private static ?array $catalog = null;
    /** @var list<array{0: string, 1: list<string>, 2: string}>|null modèle, variables, traduction */
    private static ?array $patterns = null;

    /** Lit la langue du serveur (une fois par processus). Base injoignable : français. */
    public static function load(Database $db): void
    {
        if (self::$loaded) {
            return;
        }
        try {
            self::set((string) (new ServerSettings($db))->get('language'));
        } catch (\Throwable) {
            self::set('fr');
        }
    }

    /** Base où lire la langue au premier texte à traduire (aucune requête avant). */
    public static function useDatabase(Database $db): void
    {
        if (self::$db !== $db) {
            self::$db = $db;
            self::$loaded = false;
        }
    }

    /** Nouvelle requête : la langue sera relue (l'admin a pu la changer). */
    public static function forget(): void
    {
        self::$loaded = false;
    }

    private static function ensure(): void
    {
        if (!self::$loaded && self::$db !== null) {
            self::load(self::$db);
        }
    }

    /** Fixe la langue (tests, ou juste après un changement par l'admin). */
    public static function set(string $lang): void
    {
        self::$lang = $lang === 'en' ? 'en' : 'fr';
        self::$loaded = true;
    }

    public static function current(): string
    {
        self::ensure();
        return self::$lang;
    }

    /** @return array<string, string> */
    public static function catalog(): array
    {
        return self::$catalog ??= require __DIR__ . '/../lang/en.php';
    }

    /** @param array<string, string|int|float> $vars */
    public static function tr(string $fr, array $vars = []): string
    {
        self::ensure();
        $text = self::$lang === 'en' ? (self::catalog()[$fr] ?? $fr) : $fr;
        return $vars === [] ? $text : strtr($text, array_combine(
            array_map(static fn ($k) => '{' . $k . '}', array_keys($vars)),
            array_map('strval', array_values($vars))
        ));
    }

    /** Traduit un texte déjà composé ; inconnu du catalogue, il reste tel quel. */
    public static function message(string $text): string
    {
        self::ensure();
        if (self::$lang !== 'en' || $text === '') {
            return $text;
        }
        $catalog = self::catalog();
        if (isset($catalog[$text])) {
            return $catalog[$text];
        }
        foreach (self::patterns() as [$regex, $names, $en]) {
            if (preg_match($regex, $text, $m) === 1) {
                $repl = [];
                foreach ($names as $i => $name) {
                    $repl['{' . $name . '}'] = $name === 'msg' ? self::message($m[$i + 1]) : $m[$i + 1];
                }
                return strtr($en, $repl);
            }
        }
        return $text;
    }

    /** @return list<array{0: string, 1: list<string>, 2: string}> */
    private static function patterns(): array
    {
        if (self::$patterns !== null) {
            return self::$patterns;
        }
        $keys = array_filter(array_keys(self::catalog()), static fn ($k) => str_contains($k, '{'));
        // Les modèles les plus longs d'abord : le plus précis gagne
        usort($keys, static fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $out = [];
        foreach ($keys as $key) {
            preg_match_all('/\{(\w+)\}/', $key, $names);
            $parts = preg_split('/\{\w+\}/', $key);
            $regex = '/^' . implode('(.+?)', array_map(static fn ($p) => preg_quote($p, '/'), $parts)) . '$/su';
            $out[] = [$regex, $names[1], self::catalog()[$key]];
        }
        return self::$patterns = $out;
    }
}
