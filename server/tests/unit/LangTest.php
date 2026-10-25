<?php
// Langue du serveur (WEB-13) : traduction des messages composés, et catalogue complet — chaque
// message qu'un client peut recevoir a sa traduction anglaise, avec les mêmes variables.

declare(strict_types=1);

namespace Musique\Tests;

use Musique\Lang;

final class LangTest extends TestCase
{
    public function tearDown(): void
    {
        Lang::set('fr');
    }

    public function testMessagesComposes(): void
    {
        Lang::set('fr');
        $this->assertSame('Champ « email » obligatoire', Lang::message('Champ « email » obligatoire'));
        Lang::set('en');
        $this->assertSame('Field “email” is required', Lang::message('Champ « email » obligatoire'));
        $this->assertSame('Field “name”: 1 to 100 characters', Lang::message('Champ « name » : de 1 à 100 caractères'));
        $this->assertSame('Album not found', Lang::message('Album introuvable'));
        // {msg} : message imbriqué, traduit à son tour
        $this->assertSame('Change no. 3 no longer applies: Unknown genre. Reject the batch or ask for a new one.',
            Lang::message('Changement n° 3 plus applicable : Genre inconnu. Refusez le lot ou demandez-en un nouveau.'));
        // Le modèle le plus précis gagne
        $this->assertSame('at 0:35 and 2:08: flac : invalid residual', Lang::message('à 0:35 et 2:08 : flac : invalid residual'));
        $this->assertSame('at 1:41: flac : invalid residual', Lang::message('à 1:41 : flac : invalid residual'));
        // Les parties variables sont recopiées telles quelles, même si elles ressemblent à un texte connu
        $this->assertSame('Another artist is already called “Calme”: merge the two instead',
            Lang::message('Un autre artiste s’appelle déjà « Calme » : fusionnez plutôt les deux'));
        // Inconnu (message d'un outil externe) : inchangé
        $this->assertSame('ffmpeg: invalid data', Lang::message('ffmpeg: invalid data'));
        $this->assertSame('Hello Rémy', strtok(Lang::tr("Bonjour {nom},\n\nVotre demande de compte sur {serveur} n’a pas été acceptée.", ['nom' => 'Rémy', 'serveur' => 'X']), ','));
    }

    /** Variables d'un texte, triées. @return list<string> */
    private static function vars(string $s): array
    {
        preg_match_all('/\{(\w+)\}/', $s, $m);
        $v = $m[1];
        sort($v);
        return $v;
    }

    public function testMemesVariables(): void
    {
        $bad = [];
        foreach (Lang::catalog() as $fr => $en) {
            if (self::vars($fr) !== self::vars($en)) {
                $bad[] = "$fr → $en";
            }
        }
        $this->assertSame([], $bad);
    }

    /** Chaînes littérales d'une ligne de PHP. @return list<string> */
    private static function literals(string $code): array
    {
        preg_match_all('/\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"/u', $code, $m, PREG_SET_ORDER);
        return array_map(static fn ($x) => isset($x[2]) && $x[2] !== '' ? stripcslashes($x[2]) : str_replace("\\'", "'", $x[1]), $m);
    }

    public function testCatalogueComplet(): void
    {
        $catalog = Lang::catalog();
        // Clés normalisées : chaque variable devient {}
        $norm = static fn (string $s): string => (string) preg_replace('/\{\w+\}/', '{}', $s);
        $keys = array_map($norm, array_keys($catalog));
        $missing = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../src'));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || str_ends_with($file->getPathname(), 'ApiError.php')) {
                continue;
            }
            $path = substr($file->getPathname(), strlen(__DIR__ . '/../../src/'));
            $lines = file($file->getPathname());
            foreach ($lines as $i => $line) {
                if (preg_match('#^\s*(//|\*|/\*)#', $line)) {
                    continue;
                }
                // Textes écrits pour l'occasion : la clé exacte
                if (preg_match_all('/Lang::tr\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/u', $line, $m)) {
                    foreach ($m[1] as $lit) {
                        $fr = self::literals($lit)[0];
                        if (!isset($catalog[$fr])) {
                            $missing[] = "$path:" . ($i + 1) . " $fr";
                        }
                    }
                }
                // Messages envoyés aux clients (erreurs, motifs gardés en base) ; pas les journaux
                $sent = (str_contains($line, 'ApiError') && !str_starts_with(trim($line), 'use '))
                    || str_contains($line, "'message' =>") || str_contains($line, '$reason = ') || preg_match('/\bhit\(/', $line)
                    || str_contains($line, 'return "Un fichier porte');
                if (!$sent || preg_match('/logger|->(warning|info|error|debug)\(/', $line) || str_contains($line, 'Lang::')) {
                    continue;
                }
                $code = $line;
                for ($j = $i + 1; substr_count($code, '(') > substr_count($code, ')') && $j < $i + 5 && isset($lines[$j]); $j++) {
                    $code .= ' ' . trim($lines[$j]);
                }
                foreach (self::literals($code) as $s) {
                    if (!preg_match('/[A-Za-zÀ-ÿ]{3,} |[À-ÿ’«]/u', $s) || preg_match('/^(SELECT|UPDATE|INSERT|DELETE|bytes)\b|\bWHERE\b/', $s)) {
                        continue;
                    }
                    // Interpolations PHP et sprintf → {}
                    $n = (string) preg_replace(['/\{\$[^}]*\}/', '/\$\w+(\[[^\]]*\])?/', '/%[ds]/'], '{}', $s);
                    $n = trim($n, " .:(");
                    $found = false;
                    foreach ($keys as $k) {
                        if (str_contains($k, $n)) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        // Message complet : un modèle du catalogue doit le reconnaître
                        Lang::set('en');
                        $sample = str_replace('{}', 'x', $n);
                        $found = Lang::message($sample) !== $sample;
                        Lang::set('fr');
                    }
                    if (!$found) {
                        $missing[] = "$path:" . ($i + 1) . " $s";
                    }
                }
            }
        }
        $this->assertSame([], $missing);
    }
}
