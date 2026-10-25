<?php
// Découpe un fichier SQL en instructions, comme le client `mariadb` : PDO n'exécute qu'une
// instruction à la fois et ne connaît pas la directive DELIMITER (utilisée pour les déclencheurs).
// Tient compte des chaînes ('…', "…", `…`) et des commentaires, qui peuvent contenir des « ; ».

declare(strict_types=1);

namespace Musique\Db;

final class SqlSplitter
{
    /** @return list<string> */
    public static function split(string $sql): array
    {
        $statements = [];
        $delimiter = ';';
        $current = '';
        $len = strlen($sql);
        $i = 0;
        $atLineStart = true;

        while ($i < $len) {
            // Directive DELIMITER, seule sur sa ligne
            if ($atLineStart && trim($current) === '' && preg_match('/\G[ \t]*DELIMITER[ \t]+(\S+)[ \t]*(\r?\n|$)/Ai', $sql, $m, 0, $i)) {
                $delimiter = $m[1];
                $i += strlen($m[0]);
                $current = '';
                continue;
            }
            $c = $sql[$i];
            $atLineStart = false;

            // Commentaires
            if ($c === '-' && substr($sql, $i, 2) === '--' && ($i + 2 >= $len || ctype_space($sql[$i + 2]))) {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end;
                continue;
            }
            if ($c === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end;
                continue;
            }
            if ($c === '/' && substr($sql, $i, 2) === '/*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 2;
                $current .= ' ';
                continue;
            }

            // Chaînes et identifiants entre guillemets
            if ($c === "'" || $c === '"' || $c === '`') {
                $j = $i + 1;
                while ($j < $len) {
                    if ($sql[$j] === '\\' && $c !== '`') {
                        $j += 2;
                        continue;
                    }
                    if ($sql[$j] === $c) {
                        if ($j + 1 < $len && $sql[$j + 1] === $c) { // '' doublé
                            $j += 2;
                            continue;
                        }
                        break;
                    }
                    $j++;
                }
                $current .= substr($sql, $i, $j - $i + 1);
                $i = $j + 1;
                continue;
            }

            // Fin d'instruction
            if (substr($sql, $i, strlen($delimiter)) === $delimiter) {
                if (trim($current) !== '') {
                    $statements[] = trim($current);
                }
                $current = '';
                $i += strlen($delimiter);
                continue;
            }

            if ($c === "\n") {
                $atLineStart = true;
            }
            $current .= $c;
            $i++;
        }
        if (trim($current) !== '') {
            $statements[] = trim($current);
        }
        return $statements;
    }
}
